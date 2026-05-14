<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/config.php';

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$data   = array_merge($_GET, $_POST, $body);
$action = $data['action'] ?? '';

function ok($payload = []) {
    echo json_encode(array_merge(['success' => true], $payload), JSON_UNESCAPED_UNICODE);
    exit;
}
function err($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
function uid()   { return $_SESSION['uid']      ?? null; }
function isAdmin(){ return !empty($_SESSION['is_admin']); }
function auth()  { if (!uid()) err('Not logged in.', 401); }
function admin() { auth(); if (!isAdmin()) err('Administrator access required.', 403); }

// lightweight maintenance on each request
doMaintenance();

switch ($action) {
    // Public
    case 'get_availability':      getAvailability();    break;
    case 'make_reservation':      makeReservation();    break;
    case 'request_private_room':  requestPrivateRoom(); break;

    // Public — menu & customer self-service
    case 'get_menu':                     getMenu();                    break;
    case 'add_pre_order':               addPreOrder();                break;
    case 'find_customer_reservation':   findCustomerReservation();    break;
    case 'customer_update_reservation': customerUpdateReservation();  break;

    // Auth
    case 'login':       handleLogin();   break;
    case 'logout':      handleLogout();  break;
    case 'check_auth':  checkAuth();     break;

    // Employee
    case 'get_available_tables': auth(); getAvailableTables(); break;
    case 'get_pre_orders':       auth(); getPreOrders();       break;
    case 'get_reservations':   auth(); getReservations();   break;
    case 'add_reservation':    auth(); addReservation();    break;
    case 'update_reservation': auth(); updateReservation(); break;
    case 'delete_reservation': auth(); deleteReservation(); break;
    case 'get_pending_rooms':  auth(); getPendingRooms();   break;
    case 'find_rooms':         auth(); findRooms();         break;
    case 'confirm_room':       auth(); confirmRoom();       break;
    case 'reject_room':        auth(); rejectRoom();        break;
    case 'get_notifications':  auth(); getNotifications();  break;
    case 'read_notification':  auth(); readNotification();  break;
    case 'get_stats':          auth(); getStats();          break;

    // Profile
    case 'get_profile':      auth(); getProfile();     break;
    case 'set_nickname':     auth(); setNickname();    break;
    case 'change_password':  auth(); changePassword(); break;

    // Admin
    case 'list_employees':        admin(); listEmployees();       break;
    case 'create_employee':       admin(); createEmployee();      break;
    case 'update_employee_name':  admin(); updateEmployeeName();  break;
    case 'toggle_employee':       admin(); toggleEmployee();      break;

    default: err('Unknown action.');
}

/* ============================================================
   PUBLIC ENDPOINTS
   ============================================================ */

function getAvailability() {
    $db = getDB();
    $sql = "SELECT dt.type,
                   COUNT(*) AS total,
                   COUNT(*) - COUNT(r.id) AS available
            FROM dining_tables dt
            LEFT JOIN reservations r
              ON r.table_id = dt.id AND r.status = 'confirmed'
            GROUP BY dt.type";
    $rows = $db->query($sql)->fetchAll();
    $result = [];
    foreach ($rows as $row) $result[$row['type']] = $row;
    // Also return private room breakdown
    $priv = $db->query("SELECT dt.table_number, dt.min_capacity, dt.max_capacity,
                                (SELECT COUNT(*) FROM reservations r WHERE r.table_id=dt.id AND r.status='confirmed') AS reserved
                         FROM dining_tables dt WHERE dt.type='private' ORDER BY dt.min_capacity, dt.table_number")->fetchAll();
    ok(['types' => $result, 'private_rooms' => $priv]);
}

function makeReservation() {
    global $data;
    $type  = $data['table_type'] ?? '';
    $name  = trim($data['customer_name'] ?? '');
    $phone = trim($data['phone'] ?? '');

    if (!in_array($type, ['small','medium','large'])) err('Invalid table type.');
    if (!$name)  err('Please enter your name.');
    if (!preg_match('/^1[3-9]\d{9}$/', $phone)) err('Please enter a valid phone number.');

    $db = getDB();
    // Find an available table of this type
    $stmt = $db->prepare(
        "SELECT dt.id FROM dining_tables dt
         WHERE dt.type = ?
           AND dt.id NOT IN (SELECT table_id FROM reservations WHERE status='confirmed')
         LIMIT 1"
    );
    $stmt->execute([$type]);
    $table = $stmt->fetch();
    if (!$table) err('No tables of this type are currently available. Please select another type.');

    $ins = $db->prepare("INSERT INTO reservations (table_id,customer_name,phone) VALUES (?,?,?)");
    $ins->execute([$table['id'], $name, $phone]);
    $resId = (int)$db->lastInsertId();
    // Get table number for notification
    $tblStmt = $db->prepare("SELECT table_number FROM dining_tables WHERE id=?");
    $tblStmt->execute([$table['id']]);
    $tbl = $tblStmt->fetch();
    ok(['message' => 'Reservation confirmed! Thank you for choosing Cloud Pavilion. We look forward to welcoming you.', 'reservation_id' => $resId]);
}

function requestPrivateRoom() {
    global $data;
    $name  = trim($data['customer_name'] ?? '');
    $phone = trim($data['phone'] ?? '');

    if (!$name)  err('Please enter your name.');
    if (!preg_match('/^1[3-9]\d{9}$/', $phone)) err('Please enter a valid phone number.');

    $db = getDB();
    $db->prepare("INSERT INTO private_room_requests (customer_name,phone) VALUES (?,?)")->execute([$name, $phone]);
    $reqId = (int)$db->lastInsertId();
    broadcastNotification('new_private_request',
        "New private room request from {$name} (phone: {$phone}). Please process via the Pending Requests button.", $reqId);
    ok(['message' => 'Your private room request has been submitted. Our staff will contact you shortly to confirm the arrangement. Please keep your phone available.']);
}

/* ============================================================
   AUTH
   ============================================================ */

function handleLogin() {
    global $data;
    $loginName = trim($data['username'] ?? '');
    $password  = $data['password'] ?? '';
    if (!$loginName || !$password) err('Please enter your username and password.');

    $db = getDB();

    // Find employee by username / nickname / alias
    $emp = findEmployeeByLogin($db, $loginName);
    if (!$emp) err('Invalid username or password.');
    if (!$emp['is_active']) err('This account has been disabled.');

    $isOldAlias   = !empty($emp['_alias']); // logged in with old alias
    $isOldPassword = false;

    // Verify password (current first)
    if (!password_verify($password, $emp['password_hash'])) {
        // Try old password within 72h
        if ($emp['old_password_hash'] && $emp['old_password_expires'] && strtotime($emp['old_password_expires']) > time()) {
            if (!password_verify($password, $emp['old_password_hash'])) {
                err('Invalid username or password.');
            }
            $isOldPassword = true;
        } else {
            err('Invalid username or password.');
        }
    }

    // Handle old-alias login tracking
    if ($isOldAlias) {
        $trans = $db->prepare("SELECT * FROM account_transitions WHERE old_username = ? AND employee_id = ?")->execute([$loginName, $emp['id']]);
        $trans = $db->prepare("SELECT * FROM account_transitions WHERE old_username = ? AND employee_id = ?")->execute([$loginName, $emp['id']]) ? null : null;
        $stmt = $db->prepare("SELECT * FROM account_transitions WHERE old_username = ? AND employee_id = ?");
        $stmt->execute([$loginName, $emp['id']]);
        $tr = $stmt->fetch();
        if ($tr && $tr['new_account_used']) {
            // re-activate reminders
            $db->prepare("UPDATE account_transitions SET reminders_cancelled=0 WHERE id=?")->execute([$tr['id']]);
        }
    } else {
        // Logged in with NEW account — check if there's a transition
        $stmt = $db->prepare("SELECT * FROM account_transitions WHERE employee_id = ? AND new_username = ? AND expiry_date > NOW()");
        $stmt->execute([$emp['id'], $emp['username']]);
        $tr = $stmt->fetch();
        if ($tr) {
            if (!$tr['new_account_used']) {
                $db->prepare("UPDATE account_transitions SET new_account_used=1, reminders_cancelled=1 WHERE id=?")->execute([$tr['id']]);
            } elseif (!$tr['reminders_cancelled']) {
                $db->prepare("UPDATE account_transitions SET reminders_cancelled=1 WHERE id=?")->execute([$tr['id']]);
            }
        }
    }

    // Handle old-password login tracking
    if ($isOldPassword) {
        $db->prepare("UPDATE employees SET old_password_reminder_cancelled=0 WHERE id=?")->execute([$emp['id']]);
    } else {
        // Using new password — cancel old-password reminders
        $db->prepare("UPDATE employees SET old_password_reminder_cancelled=1 WHERE id=?")->execute([$emp['id']]);
    }

    // Set session
    $_SESSION['uid']      = $emp['id'];
    $_SESSION['username'] = $emp['username'];
    $_SESSION['is_admin'] = (bool)$emp['is_admin'];

    // Collect notices
    $notices = [];
    if ($isOldAlias) {
        $stmt2 = $db->prepare("SELECT new_username, expiry_date FROM account_transitions WHERE old_username=? AND employee_id=?");
        $stmt2->execute([$loginName, $emp['id']]);
        $tr2 = $stmt2->fetch();
        if ($tr2) {
            $daysLeft = ceil((strtotime($tr2['expiry_date']) - time()) / 86400);
            $notices[] = "You are logged in with your old username. Your new username is: {$tr2['new_username']}. The old username will expire in {$daysLeft} day(s). Please switch to your new username soon.";
        }
    }
    if ($isOldPassword) {
        $hoursLeft = ceil((strtotime($emp['old_password_expires']) - time()) / 3600);
        $notices[] = "You are logged in with your old password. It will expire in approximately {$hoursLeft} hour(s). Please switch to your new password.";
    }

    // Unread notification count
    $unread = $db->prepare("SELECT COUNT(*) FROM notifications WHERE employee_id=? AND is_read=0");
    $unread->execute([$emp['id']]);

    ok([
        'uid'       => $emp['id'],
        'username'  => $emp['username'],
        'nickname'  => $emp['nickname'],
        'full_name' => $emp['full_name'],
        'is_admin'  => (bool)$emp['is_admin'],
        'notices'   => $notices,
        'unread'    => (int)$unread->fetchColumn(),
    ]);
}

function findEmployeeByLogin(PDO $db, string $login): ?array {
    // Try username
    $stmt = $db->prepare("SELECT * FROM employees WHERE username = ?");
    $stmt->execute([$login]);
    $emp = $stmt->fetch();
    if ($emp) return $emp;

    // Try nickname
    $stmt = $db->prepare("SELECT * FROM employees WHERE nickname = ? AND nickname IS NOT NULL");
    $stmt->execute([$login]);
    $emp = $stmt->fetch();
    if ($emp) return $emp;

    // Try alias (old account)
    $stmt = $db->prepare("SELECT e.*, ua.alias_username AS _alias
                           FROM username_aliases ua
                           JOIN employees e ON e.id = ua.employee_id
                           WHERE ua.alias_username = ? AND ua.expiry_date > NOW()");
    $stmt->execute([$login]);
    $emp = $stmt->fetch();
    if ($emp) { $emp['_alias'] = $login; return $emp; }

    return null;
}

function handleLogout() {
    session_destroy();
    ok();
}

function checkAuth() {
    if (!uid()) { ok(['logged_in' => false]); }
    $db = getDB();
    $stmt = $db->prepare("SELECT id,username,nickname,full_name,is_admin FROM employees WHERE id=?");
    $stmt->execute([uid()]);
    $emp = $stmt->fetch();
    if (!$emp) { session_destroy(); ok(['logged_in' => false]); }

    $unread = $db->prepare("SELECT COUNT(*) FROM notifications WHERE employee_id=? AND is_read=0");
    $unread->execute([uid()]);
    $pending = $db->query("SELECT COUNT(*) FROM private_room_requests WHERE status='pending'")->fetchColumn();

    ok(['logged_in' => true, 'uid' => $emp['id'], 'username' => $emp['username'],
        'nickname' => $emp['nickname'], 'full_name' => $emp['full_name'],
        'is_admin' => (bool)$emp['is_admin'], 'unread' => (int)$unread->fetchColumn(),
        'pending_rooms' => (int)$pending]);
}

/* ============================================================
   RESERVATIONS (Employee)
   ============================================================ */

function getAvailableTables() {
    global $data;
    $type = $data['type'] ?? '';
    $db   = getDB();
    $sql  = "SELECT dt.id, dt.type, dt.table_number, dt.min_capacity, dt.max_capacity
             FROM dining_tables dt
             WHERE dt.id NOT IN (SELECT table_id FROM reservations WHERE status='confirmed')";
    $params = [];
    if ($type && in_array($type, ['small','medium','large','private'])) {
        $sql .= " AND dt.type = ?"; $params[] = $type;
    }
    $sql .= " ORDER BY dt.type, dt.table_number";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    ok(['tables' => $stmt->fetchAll()]);
}

function getReservations() {
    global $data;
    $db     = getDB();
    $type   = $data['type'] ?? '';
    $search = trim($data['search'] ?? '');

    $sql = "SELECT r.id, r.customer_name, r.phone, r.party_size, r.notes, r.status,
                   r.created_at, dt.table_number, dt.type AS table_type,
                   dt.min_capacity, dt.max_capacity,
                   e.username AS created_by_name,
                   (SELECT COUNT(*) FROM pre_orders po WHERE po.reservation_id = r.id) AS pre_order_count
            FROM reservations r
            JOIN dining_tables dt ON dt.id = r.table_id
            LEFT JOIN employees e ON e.id = r.created_by
            WHERE r.status = 'confirmed'";
    $params = [];

    if ($type && in_array($type, ['small','medium','large','private'])) {
        $sql .= " AND dt.type = ?"; $params[] = $type;
    }
    if ($search) {
        $sql .= " AND (r.phone LIKE ? OR r.customer_name LIKE ?)";
        $params[] = "%$search%"; $params[] = "%$search%";
    }
    $sql .= " ORDER BY r.created_at DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    ok(['reservations' => $stmt->fetchAll()]);
}

function addReservation() {
    global $data;
    $tableId = (int)($data['table_id'] ?? 0);
    $name    = trim($data['customer_name'] ?? '');
    $phone   = trim($data['phone'] ?? '');
    $party   = (int)($data['party_size'] ?? 0) ?: null;
    $notes   = trim($data['notes'] ?? '');

    if (!$tableId) err('Please select a table.');
    if (!$name)    err('Please enter the guest name.');
    if (!preg_match('/^1[3-9]\d{9}$/', $phone)) err('Invalid phone number format.');

    $db = getDB();
    // Check not already reserved
    $chk = $db->prepare("SELECT id FROM reservations WHERE table_id=? AND status='confirmed'");
    $chk->execute([$tableId]);
    if ($chk->fetch()) err('This table already has a reservation.');

    $db->prepare("INSERT INTO reservations (table_id,customer_name,phone,party_size,notes,created_by) VALUES (?,?,?,?,?,?)")
       ->execute([$tableId, $name, $phone, $party, $notes, uid()]);
    ok(['id' => $db->lastInsertId()]);
}

function updateReservation() {
    global $data;
    $id    = (int)($data['id'] ?? 0);
    $name  = trim($data['customer_name'] ?? '');
    $phone = trim($data['phone'] ?? '');
    $notes = trim($data['notes'] ?? '');

    if (!$id)   err('Missing reservation ID.');
    if (!$name) err('Guest name cannot be empty.');
    if ($phone && !preg_match('/^1[3-9]\d{9}$/', $phone)) err('Invalid phone number format.');

    $db = getDB();
    $db->prepare("UPDATE reservations SET customer_name=?, phone=?, notes=? WHERE id=? AND status='confirmed'")
       ->execute([$name, $phone, $notes, $id]);
    ok();
}

function deleteReservation() {
    global $data;
    $id = (int)($data['id'] ?? 0);
    if (!$id) err('Missing reservation ID.');
    $db = getDB();
    $db->prepare("UPDATE reservations SET status='cancelled' WHERE id=?")->execute([$id]);
    ok();
}

/* ============================================================
   PRIVATE ROOMS (Employee)
   ============================================================ */

function getPendingRooms() {
    $db = getDB();
    $rows = $db->query("SELECT * FROM private_room_requests WHERE status='pending' ORDER BY created_at DESC")->fetchAll();
    ok(['requests' => $rows]);
}

function findRooms() {
    global $data;
    $n = (int)($data['party_size'] ?? 0);
    if ($n < 1) err('Please enter a valid party size.');

    $db = getDB();

    // Subquery: reserved private room IDs
    $reservedSQL = "SELECT table_id FROM reservations WHERE status='confirmed'
                    UNION SELECT assigned_table_id FROM private_room_requests WHERE status='confirmed' AND assigned_table_id IS NOT NULL";

    // Step 1: exact match
    $rooms = searchPrivateRooms($db, "min_capacity <= $n AND max_capacity >= $n", $reservedSQL);
    if ($rooms) { ok(['rooms' => $rooms, 'match_type' => 'exact']); }

    // Step 2: upper +1 (party_size = max+1)
    $rooms = searchPrivateRooms($db, "max_capacity = " . ($n-1), $reservedSQL);
    if ($rooms) { ok(['rooms' => $rooms, 'match_type' => 'upper+1', 'note' => 'The following rooms have a max capacity of ' . ($n-1) . ' — slightly over by 1.']); }

    // Step 3: lower -1 (party_size = min-1)
    $rooms = searchPrivateRooms($db, "min_capacity = " . ($n+1), $reservedSQL);
    if ($rooms) { ok(['rooms' => $rooms, 'match_type' => 'lower-1', 'note' => 'The following rooms require a minimum of ' . ($n+1) . ' guests — slightly under by 1.']); }

    // Step 4: upper +2
    $rooms = searchPrivateRooms($db, "max_capacity = " . ($n-2), $reservedSQL);
    if ($rooms) { ok(['rooms' => $rooms, 'match_type' => 'upper+2', 'note' => 'The following rooms have a max capacity of ' . ($n-2) . ' — over by 2.']); }

    // Step 5: lower -2
    $rooms = searchPrivateRooms($db, "min_capacity = " . ($n+2), $reservedSQL);
    if ($rooms) { ok(['rooms' => $rooms, 'match_type' => 'lower-2', 'note' => 'The following rooms require a minimum of ' . ($n+2) . ' guests — under by 2.']); }

    ok(['rooms' => [], 'match_type' => 'none', 'note' => 'No suitable private room is available for this party size.']);
}

function searchPrivateRooms(PDO $db, string $capacityCond, string $reservedSQL): array {
    $sql = "SELECT id, table_number, min_capacity, max_capacity
            FROM dining_tables
            WHERE type='private'
              AND $capacityCond
              AND id NOT IN ($reservedSQL)
            ORDER BY min_capacity, table_number";
    return $db->query($sql)->fetchAll();
}

function confirmRoom() {
    global $data;
    $reqId   = (int)($data['request_id'] ?? 0);
    $tableId = (int)($data['table_id'] ?? 0);
    $party   = (int)($data['party_size'] ?? 0);
    $name    = trim($data['customer_name'] ?? '');
    $phone   = trim($data['phone'] ?? '');

    if (!$reqId || !$tableId) err('Invalid request parameters.');

    $db = getDB();
    // Check table still free
    $chk = $db->prepare("SELECT id FROM reservations WHERE table_id=? AND status='confirmed'");
    $chk->execute([$tableId]);
    if ($chk->fetch()) err('This private room has just been reserved. Please select another.');

    $db->beginTransaction();
    try {
        $db->prepare("UPDATE private_room_requests SET status='confirmed', assigned_table_id=?, party_size=?, processed_by=?, processed_at=NOW() WHERE id=?")
           ->execute([$tableId, $party, uid(), $reqId]);
        $db->prepare("INSERT INTO reservations (table_id,customer_name,phone,party_size,created_by) VALUES (?,?,?,?,?)")
           ->execute([$tableId, $name, $phone, $party, uid()]);
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        err('Operation failed: ' . $e->getMessage());
    }
    ok();
}

function rejectRoom() {
    global $data;
    $reqId = (int)($data['request_id'] ?? 0);
    if (!$reqId) err('Invalid request parameters.');
    $db = getDB();
    $db->prepare("UPDATE private_room_requests SET status='rejected', processed_by=?, processed_at=NOW() WHERE id=?")
       ->execute([uid(), $reqId]);
    ok();
}

/* ============================================================
   NOTIFICATIONS
   ============================================================ */

function getNotifications() {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM notifications WHERE employee_id=? ORDER BY created_at DESC LIMIT 50");
    $stmt->execute([uid()]);
    $rows = $stmt->fetchAll();
    $unread = array_reduce($rows, fn($c, $r) => $c + (int)!$r['is_read'], 0);
    ok(['notifications' => $rows, 'unread' => $unread]);
}

function readNotification() {
    global $data;
    $id = (int)($data['id'] ?? 0);
    $db = getDB();
    if ($id) {
        $db->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND employee_id=?")->execute([$id, uid()]);
    } else {
        $db->prepare("UPDATE notifications SET is_read=1 WHERE employee_id=?")->execute([uid()]);
    }
    ok();
}

/* ============================================================
   STATS
   ============================================================ */

function getStats() {
    $db = getDB();
    $sql = "SELECT dt.type,
                   COUNT(*) AS total,
                   SUM(CASE WHEN r.id IS NOT NULL THEN 1 ELSE 0 END) AS reserved
            FROM dining_tables dt
            LEFT JOIN reservations r ON r.table_id = dt.id AND r.status='confirmed'
            GROUP BY dt.type";
    $rows = $db->query($sql)->fetchAll();
    $result = [];
    foreach ($rows as $row) {
        $result[$row['type']] = [
            'total'    => (int)$row['total'],
            'reserved' => (int)$row['reserved'],
            'rate'     => $row['total'] > 0 ? round($row['reserved'] / $row['total'] * 100) : 0,
        ];
    }
    // Pending private rooms
    $pending = $db->query("SELECT COUNT(*) FROM private_room_requests WHERE status='pending'")->fetchColumn();
    ok(['stats' => $result, 'pending_rooms' => (int)$pending]);
}

/* ============================================================
   PROFILE
   ============================================================ */

function getProfile() {
    $db = getDB();
    $stmt = $db->prepare("SELECT id,username,nickname,full_name,is_admin,nickname_last_changed FROM employees WHERE id=?");
    $stmt->execute([uid()]);
    $emp = $stmt->fetch();
    // Check active transitions
    $trans = $db->prepare("SELECT * FROM account_transitions WHERE employee_id=? AND expiry_date > NOW() ORDER BY created_at DESC LIMIT 1");
    $trans->execute([uid()]);
    $tr = $trans->fetch();
    ok(['profile' => $emp, 'active_transition' => $tr ?: null]);
}

function setNickname() {
    global $data;
    $nick = trim($data['nickname'] ?? '');
    if (!$nick) err('Nickname cannot be empty.');
    if (mb_strlen($nick) > 20) err('Nickname cannot exceed 20 characters.');

    $db = getDB();
    // Check 7-day cooldown
    $stmt = $db->prepare("SELECT nickname_last_changed FROM employees WHERE id=?");
    $stmt->execute([uid()]);
    $emp = $stmt->fetch();
    if ($emp['nickname_last_changed']) {
        $diff = time() - strtotime($emp['nickname_last_changed']);
        if ($diff < 7 * 86400) {
            $remaining = 7 - floor($diff / 86400);
            err("Nicknames can only be changed once every 7 days. Please wait {$remaining} more day(s).");
        }
    }
    // Check uniqueness (cannot clash with username or another nickname)
    $chk = $db->prepare("SELECT id FROM employees WHERE (username=? OR nickname=?) AND id != ?");
    $chk->execute([$nick, $nick, uid()]);
    if ($chk->fetch()) err('This nickname is already in use.');

    $db->prepare("UPDATE employees SET nickname=?, nickname_last_changed=NOW() WHERE id=?")->execute([$nick, uid()]);
    ok(['nickname' => $nick]);
}

function changePassword() {
    global $data;
    $old = $data['old_password'] ?? '';
    $new = $data['new_password'] ?? '';
    if (!$old || !$new) err('Please fill in all fields.');
    if (strlen($new) < 6) err('New password must be at least 6 characters.');

    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM employees WHERE id=?");
    $stmt->execute([uid()]);
    $emp = $stmt->fetch();

    if (!password_verify($old, $emp['password_hash'])) {
        // Try old password
        if ($emp['old_password_hash'] && $emp['old_password_expires'] && strtotime($emp['old_password_expires']) > time()
            && password_verify($old, $emp['old_password_hash'])) {
            // Allow using old password to change
        } else {
            err('Current password is incorrect.');
        }
    }

    $newHash = password_hash($new, PASSWORD_BCRYPT);
    $expires = date('Y-m-d H:i:s', strtotime('+72 hours'));
    // Copy current hash to old_password_hash BEFORE overwriting
    $db->prepare("UPDATE employees SET old_password_hash=password_hash, old_password_expires=?, old_password_reminder_cancelled=0 WHERE id=?")
       ->execute([$expires, uid()]);
    $db->prepare("UPDATE employees SET password_hash=? WHERE id=?")
       ->execute([$newHash, uid()]);

    addNotification(uid(), 'password_changed', 'Your password has been changed. The old password will remain usable for 72 hours in case you forget the new one.', uid());
    ok(['message' => 'Password changed successfully. Your old password is still valid for 72 hours.']);
}

/* ============================================================
   ADMIN: EMPLOYEE MANAGEMENT
   ============================================================ */

function listEmployees() {
    global $data;
    $search = trim($data['search'] ?? '');
    $db = getDB();
    $sql = "SELECT id,username,nickname,full_name,is_admin,is_active,created_at FROM employees WHERE is_admin=0";
    $params = [];
    if ($search) {
        $sql .= " AND (username LIKE ? OR full_name LIKE ? OR nickname LIKE ?)";
        $params = ["%$search%", "%$search%", "%$search%"];
    }
    $sql .= " ORDER BY created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    ok(['employees' => $stmt->fetchAll()]);
}

function createEmployee() {
    global $data;
    $fullName = trim($data['full_name'] ?? '');
    if (!$fullName) err('Please enter the employee name.');
    if (!preg_match('/^\p{Han}+$/u', $fullName)) err('Employee name must be in Chinese characters.');
    if (mb_strlen($fullName) < 2) err('Name must be at least 2 characters.');

    $db = getDB();
    $username = generateUsername($db, $fullName);
    $hash = password_hash('123456', PASSWORD_BCRYPT);

    $db->prepare("INSERT INTO employees (username,password_hash,full_name) VALUES (?,?,?)")
       ->execute([$username, $hash, $fullName]);
    $newId = $db->lastInsertId();

    addNotification($newId, 'account_created', "Welcome to Cloud Pavilion! Your username is: {$username}. Default password: 123456. Please change your password after your first login.", null);
    ok(['username' => $username, 'id' => $newId]);
}

function updateEmployeeName() {
    global $data;
    $empId   = (int)($data['id'] ?? 0);
    $newName = trim($data['full_name'] ?? '');
    if (!$empId || !$newName) err('Invalid request parameters.');
    if (!preg_match('/^\p{Han}+$/u', $newName)) err('Name must be in Chinese characters.');
    if (mb_strlen($newName) < 2) err('Name must be at least 2 characters.');

    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM employees WHERE id=? AND is_admin=0");
    $stmt->execute([$empId]);
    $emp = $stmt->fetch();
    if (!$emp) err('Employee not found.');

    $oldUsername = $emp['username'];
    $newUsername = generateUsername($db, $newName, $empId);

    if ($oldUsername === $newUsername) {
        // Just update name, no account change needed
        $db->prepare("UPDATE employees SET full_name=? WHERE id=?")->execute([$newName, $empId]);
        ok(['message' => 'Name updated.']);
    }

    $db->beginTransaction();
    try {
        // Update employee record
        $db->prepare("UPDATE employees SET full_name=?, username=? WHERE id=?")->execute([$newName, $newUsername, $empId]);
        // Add alias for old username (30 days)
        $expiry = date('Y-m-d H:i:s', strtotime('+30 days'));
        $db->prepare("INSERT INTO username_aliases (employee_id, alias_username, expiry_date) VALUES (?,?,?) ON DUPLICATE KEY UPDATE expiry_date=?")
           ->execute([$empId, $oldUsername, $expiry, $expiry]);
        // Record transition
        $db->prepare("INSERT INTO account_transitions (employee_id,old_username,new_username,expiry_date) VALUES (?,?,?,?)")
           ->execute([$empId, $oldUsername, $newUsername, $expiry]);
        $transId = $db->lastInsertId();
        // Update alias with transition id
        $db->prepare("UPDATE username_aliases SET transition_id=? WHERE alias_username=?")->execute([$transId, $oldUsername]);
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        err('Operation failed: ' . $e->getMessage());
    }

    addNotification($empId, 'account_changed',
        "Your account has been updated. Old username: {$oldUsername}, new username: {$newUsername}. " .
        "The old username will expire in 30 days — please switch to your new username as soon as possible.", $empId);

    ok(['old_username' => $oldUsername, 'new_username' => $newUsername]);
}

function toggleEmployee() {
    global $data;
    $empId = (int)($data['id'] ?? 0);
    if (!$empId) err('Invalid request parameters.');
    $db = getDB();
    $db->prepare("UPDATE employees SET is_active = 1 - is_active WHERE id=? AND is_admin=0")->execute([$empId]);
    $stmt = $db->prepare("SELECT is_active FROM employees WHERE id=?");
    $stmt->execute([$empId]);
    ok(['is_active' => (bool)$stmt->fetchColumn()]);
}

/* ============================================================
   HELPERS
   ============================================================ */

/* ============================================================
   MENU & PRE-ORDERS
   ============================================================ */

function getMenu() {
    ok(['menu' => [
        ['id' => 1,  'name' => 'Crispy Boston Lobster',          'emoji' => '🦞'],
        ['id' => 2,  'name' => 'Buddha Jumps Over the Wall',     'emoji' => '🫕'],
        ['id' => 3,  'name' => 'Osmanthus Honey Roast Duck',     'emoji' => '🦆'],
        ['id' => 4,  'name' => 'Crystal Prawn Dumplings',        'emoji' => '🥟'],
        ['id' => 5,  'name' => 'Steamed Songjiang Perch',        'emoji' => '🐟'],
        ['id' => 6,  'name' => 'Osmanthus Glutinous Rice Lotus', 'emoji' => '🍮'],
        ['id' => 7,  'name' => 'Braised Wagyu Short Rib',        'emoji' => '🥩'],
        ['id' => 8,  'name' => 'Wok-Fried King Prawn',          'emoji' => '🍤'],
        ['id' => 9,  'name' => 'Handmade Dimsum Platter',        'emoji' => '🧆'],
        ['id' => 10, 'name' => 'Seasonal Vegetable Medley',      'emoji' => '🥦'],
    ]]);
}

function addPreOrder() {
    global $data;
    $reservationId = (int)($data['reservation_id'] ?? 0);
    $items         = $data['items'] ?? [];
    if (!$reservationId) err('Invalid reservation ID.');
    if (!is_array($items) || !count($items)) err('Please select at least one dish.');

    $db = getDB();
    $stmt = $db->prepare(
        "SELECT r.id, r.customer_name, dt.table_number
         FROM reservations r JOIN dining_tables dt ON dt.id=r.table_id
         WHERE r.id=? AND r.status='confirmed'"
    );
    $stmt->execute([$reservationId]);
    $res = $stmt->fetch();
    if (!$res) err('Reservation not found.');

    $ins = $db->prepare("INSERT INTO pre_orders (reservation_id, item_name, quantity) VALUES (?,?,?)");
    $itemSummary = [];
    foreach ($items as $item) {
        $name = trim($item['name'] ?? '');
        $qty  = max(1, min(20, (int)($item['quantity'] ?? 1)));
        if (!$name) continue;
        $ins->execute([$reservationId, $name, $qty]);
        $itemSummary[] = "{$name} ×{$qty}";
    }
    if (!$itemSummary) err('No valid items submitted.');

    broadcastNotification('customer_prebooked',
        "Table {$res['table_number']} · {$res['customer_name']} submitted a pre-order: " . implode(', ', $itemSummary) . ".",
        $reservationId);

    ok(['message' => 'Pre-order submitted successfully! Our kitchen will have your selections ready.']);
}

function getPreOrders() {
    global $data;
    $resId = (int)($data['reservation_id'] ?? 0);
    if (!$resId) err('Invalid reservation ID.');
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM pre_orders WHERE reservation_id=? ORDER BY created_at");
    $stmt->execute([$resId]);
    ok(['pre_orders' => $stmt->fetchAll()]);
}

/* ============================================================
   CUSTOMER SELF-SERVICE
   ============================================================ */

function findCustomerReservation() {
    global $data;
    $name  = trim($data['customer_name'] ?? '');
    $phone = trim($data['phone'] ?? '');
    if (!$name || !$phone) err('Please enter both your name and phone number.');
    if (!preg_match('/^1[3-9]\d{9}$/', $phone)) err('Please enter a valid phone number.');

    $db = getDB();
    // Check confirmed reservations first
    $stmt = $db->prepare(
        "SELECT r.id, r.customer_name, r.phone, r.party_size, r.created_at,
                dt.table_number, dt.type AS table_type, dt.min_capacity, dt.max_capacity
         FROM reservations r JOIN dining_tables dt ON dt.id=r.table_id
         WHERE r.customer_name=? AND r.phone=? AND r.status='confirmed'
         ORDER BY r.created_at DESC LIMIT 1"
    );
    $stmt->execute([$name, $phone]);
    $reservation = $stmt->fetch();
    if ($reservation) {
        $s2 = $db->prepare("SELECT item_name, quantity FROM pre_orders WHERE reservation_id=? ORDER BY created_at");
        $s2->execute([$reservation['id']]);
        ok(['found' => true, 'type' => 'reservation', 'reservation' => $reservation, 'pre_orders' => $s2->fetchAll()]);
    }

    // Check pending private room request
    $stmt = $db->prepare(
        "SELECT * FROM private_room_requests WHERE customer_name=? AND phone=? AND status='pending'
         ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->execute([$name, $phone]);
    $request = $stmt->fetch();
    if ($request) {
        ok(['found' => true, 'type' => 'private_request', 'request' => $request]);
    }

    ok(['found' => false]);
}

function customerUpdateReservation() {
    global $data;
    $id       = (int)($data['id'] ?? 0);
    $newName  = trim($data['new_name'] ?? '');
    $newPhone = trim($data['new_phone'] ?? '');
    $oldName  = trim($data['old_name'] ?? '');
    $oldPhone = trim($data['old_phone'] ?? '');
    if (!$id || !$newName || !$newPhone) err('Please fill in all fields.');
    if (!preg_match('/^1[3-9]\d{9}$/', $newPhone)) err('Please enter a valid phone number.');

    $db = getDB();
    $stmt = $db->prepare(
        "SELECT r.*, dt.table_number FROM reservations r JOIN dining_tables dt ON dt.id=r.table_id
         WHERE r.id=? AND r.status='confirmed'"
    );
    $stmt->execute([$id]);
    $res = $stmt->fetch();
    if (!$res) err('Reservation not found.');
    if ($res['customer_name'] !== $oldName || $res['phone'] !== $oldPhone) {
        err('Your name and phone do not match our records. Please try again.');
    }

    $db->prepare("UPDATE reservations SET customer_name=?, phone=? WHERE id=?")->execute([$newName, $newPhone, $id]);

    broadcastNotification('customer_modified',
        "Table {$res['table_number']} · Guest updated booking details. New name: {$newName}, phone: {$newPhone}.",
        $id);

    ok(['message' => 'Your reservation has been updated successfully.']);
}

/* ============================================================
   BROADCAST HELPER
   ============================================================ */

function broadcastNotification(string $type, string $msg, ?int $relatedId = null) {
    $db = getDB();
    $employees = $db->query("SELECT id FROM employees WHERE is_active=1")->fetchAll();
    $stmt = $db->prepare("INSERT INTO notifications (employee_id,type,message,related_id) VALUES (?,?,?,?)");
    foreach ($employees as $emp) {
        $stmt->execute([$emp['id'], $type, $msg, $relatedId]);
    }
}

function addNotification(int $empId, string $type, string $msg, $relatedId) {
    $db = getDB();
    $db->prepare("INSERT INTO notifications (employee_id,type,message,related_id) VALUES (?,?,?,?)")
       ->execute([$empId, $type, $msg, $relatedId]);
}

function generateUsername(PDO $db, string $fullName, int $excludeId = 0): string {
    $chars = preg_split('//u', $fullName, -1, PREG_SPLIT_NO_EMPTY);
    $surname = array_shift($chars);
    $surnamePy = getCharPinyin($surname);
    $givenInitials = '';
    foreach ($chars as $c) {
        $py = getCharPinyin($c);
        $givenInitials .= $py[0] ?? 'x';
    }
    $base = strtolower($surnamePy . $givenInitials);

    // Generate unique 4-digit suffix
    $attempts = 0;
    do {
        $suffix   = str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
        $username = $base . $suffix;
        $stmt = $db->prepare("SELECT id FROM employees WHERE username=?" . ($excludeId ? " AND id!=?" : ""));
        $params = [$username];
        if ($excludeId) $params[] = $excludeId;
        $stmt->execute($params);
        $exists = $stmt->fetch();
        // Also check aliases
        if (!$exists) {
            $stmt2 = $db->prepare("SELECT id FROM username_aliases WHERE alias_username=?");
            $stmt2->execute([$username]);
            $exists = $stmt2->fetch();
        }
        $attempts++;
    } while ($exists && $attempts < 100);

    return $username;
}

function getCharPinyin(string $char): string {
    $map = [
        // Top 100 surnames
        '赵'=>'zhao','钱'=>'qian','孙'=>'sun','李'=>'li','周'=>'zhou','吴'=>'wu','郑'=>'zheng','王'=>'wang',
        '冯'=>'feng','陈'=>'chen','褚'=>'chu','卫'=>'wei','蒋'=>'jiang','沈'=>'shen','韩'=>'han','杨'=>'yang',
        '朱'=>'zhu','秦'=>'qin','尤'=>'you','许'=>'xu','何'=>'he','吕'=>'lv','施'=>'shi','张'=>'zhang',
        '孔'=>'kong','曹'=>'cao','严'=>'yan','华'=>'hua','金'=>'jin','魏'=>'wei','陶'=>'tao','姜'=>'jiang',
        '戚'=>'qi','谢'=>'xie','邹'=>'zou','喻'=>'yu','柏'=>'bai','水'=>'shui','窦'=>'dou','章'=>'zhang',
        '云'=>'yun','苏'=>'su','潘'=>'pan','葛'=>'ge','奚'=>'xi','范'=>'fan','彭'=>'peng','郎'=>'lang',
        '鲁'=>'lu','韦'=>'wei','昌'=>'chang','马'=>'ma','苗'=>'miao','凤'=>'feng','花'=>'hua','方'=>'fang',
        '俞'=>'yu','任'=>'ren','袁'=>'yuan','柳'=>'liu','酆'=>'feng','鲍'=>'bao','史'=>'shi','唐'=>'tang',
        '费'=>'fei','廉'=>'lian','岑'=>'cen','薛'=>'xue','雷'=>'lei','贺'=>'he','倪'=>'ni','汤'=>'tang',
        '滕'=>'teng','殷'=>'yin','罗'=>'luo','毕'=>'bi','郝'=>'hao','邬'=>'wu','安'=>'an','常'=>'chang',
        '乐'=>'le','于'=>'yu','时'=>'shi','傅'=>'fu','皮'=>'pi','卞'=>'bian','齐'=>'qi','康'=>'kang',
        '伍'=>'wu','余'=>'yu','元'=>'yuan','卜'=>'bu','顾'=>'gu','孟'=>'meng','平'=>'ping','黄'=>'huang',
        '和'=>'he','穆'=>'mu','萧'=>'xiao','尹'=>'yin','姚'=>'yao','邵'=>'shao','湛'=>'zhan','汪'=>'wang',
        '祁'=>'qi','毛'=>'mao','禹'=>'yu','狄'=>'di','米'=>'mi','贝'=>'bei','明'=>'ming','臧'=>'zang',
        '计'=>'ji','伏'=>'fu','成'=>'cheng','戴'=>'dai','谈'=>'tan','宋'=>'song','茅'=>'mao','庞'=>'pang',
        '熊'=>'xiong','纪'=>'ji','舒'=>'shu','屈'=>'qu','项'=>'xiang','祝'=>'zhu','董'=>'dong','梁'=>'liang',
        '杜'=>'du','阮'=>'ruan','蓝'=>'lan','闵'=>'min','席'=>'xi','季'=>'ji','麻'=>'ma','强'=>'qiang',
        '贾'=>'jia','路'=>'lu','娄'=>'lou','危'=>'wei','江'=>'jiang','童'=>'tong','颜'=>'yan','郭'=>'guo',
        '梅'=>'mei','盛'=>'sheng','林'=>'lin','刁'=>'diao','钟'=>'zhong','徐'=>'xu','邱'=>'qiu','骆'=>'luo',
        '高'=>'gao','夏'=>'xia','蔡'=>'cai','田'=>'tian','樊'=>'fan','胡'=>'hu','凌'=>'ling','霍'=>'huo',
        '虞'=>'yu','万'=>'wan','支'=>'zhi','柯'=>'ke','昝'=>'zan','管'=>'guan','卢'=>'lu','莫'=>'mo',
        '经'=>'jing','房'=>'fang','裘'=>'qiu','缪'=>'miao','干'=>'gan','解'=>'xie','应'=>'ying','宗'=>'zong',
        '丁'=>'ding','宣'=>'xuan','贲'=>'ben','邓'=>'deng','郁'=>'yu','单'=>'dan','杭'=>'hang','洪'=>'hong',
        '包'=>'bao','诸'=>'zhu','左'=>'zuo','石'=>'shi','崔'=>'cui','吉'=>'ji','钮'=>'niu','龚'=>'gong',
        '程'=>'cheng','嵇'=>'ji','邢'=>'xing','滑'=>'hua','裴'=>'pei','陆'=>'lu','荣'=>'rong','翁'=>'weng',
        '荀'=>'xun','羊'=>'yang','於'=>'yu','惠'=>'hui','甄'=>'zhen','曲'=>'qu','家'=>'jia','封'=>'feng',
        '芮'=>'rui','羿'=>'yi','储'=>'chu','靳'=>'jin','汲'=>'ji','邴'=>'bing','糜'=>'mi','松'=>'song',
        '井'=>'jing','段'=>'duan','富'=>'fu','巫'=>'wu','乌'=>'wu','焦'=>'jiao','巴'=>'ba','弓'=>'gong',
        '牧'=>'mu','隗'=>'kui','山'=>'shan','谷'=>'gu','车'=>'che','侯'=>'hou','宓'=>'mi','蓬'=>'peng',
        // Common given name chars
        '伟'=>'wei','芳'=>'fang','娜'=>'na','敏'=>'min','静'=>'jing','丽'=>'li','磊'=>'lei','军'=>'jun',
        '洋'=>'yang','勇'=>'yong','艳'=>'yan','杰'=>'jie','娟'=>'juan','涛'=>'tao','超'=>'chao','霞'=>'xia',
        '刚'=>'gang','英'=>'ying','建'=>'jian','国'=>'guo','志'=>'zhi','宇'=>'yu','辉'=>'hui','浩'=>'hao',
        '博'=>'bo','峰'=>'feng','飞'=>'fei','鑫'=>'xin','鹏'=>'peng','宁'=>'ning','东'=>'dong','全'=>'quan',
        '德'=>'de','天'=>'tian','海'=>'hai','子'=>'zi','航'=>'hang','斌'=>'bin','彬'=>'bin','健'=>'jian',
        '城'=>'cheng','帅'=>'shuai','翔'=>'xiang','旭'=>'xu','豪'=>'hao','嘉'=>'jia','晓'=>'xiao','俊'=>'jun',
        '悦'=>'yue','菲'=>'fei','燕'=>'yan','婷'=>'ting','雪'=>'xue','萍'=>'ping','红'=>'hong','月'=>'yue',
        '琴'=>'qin','玲'=>'ling','玉'=>'yu','珍'=>'zhen','兰'=>'lan','桂'=>'gui','爱'=>'ai','翠'=>'cui',
        '莲'=>'lian','竹'=>'zhu','宝'=>'bao','福'=>'fu','贵'=>'gui','进'=>'jin','礼'=>'li','民'=>'min',
        '新'=>'xin','兴'=>'xing','秀'=>'xiu','学'=>'xue','业'=>'ye','有'=>'you','勤'=>'qin','远'=>'yuan',
        '运'=>'yun','正'=>'zheng','中'=>'zhong','乐'=>'le','亮'=>'liang','文'=>'wen','武'=>'wu','春'=>'chun',
        '龙'=>'long','泽'=>'ze','轩'=>'xuan','凯'=>'kai','哲'=>'zhe','诚'=>'cheng','睿'=>'rui','锋'=>'feng',
        '刘'=>'liu','力'=>'li','丰'=>'feng','号'=>'hao','杰'=>'jie','坤'=>'kun','庆'=>'qing','庄'=>'zhuang',
    ];
    return $map[$char] ?? 'x';
}

/* ============================================================
   MAINTENANCE (runs on every request)
   ============================================================ */

function doMaintenance() {
    $db = getDB();

    // 1. Delete expired username aliases
    $db->exec("DELETE FROM username_aliases WHERE expiry_date < NOW()");

    // 2. Delete expired old passwords
    $db->exec("UPDATE employees SET old_password_hash=NULL, old_password_expires=NULL, old_password_reminder_cancelled=0 WHERE old_password_expires IS NOT NULL AND old_password_expires < NOW()");

    // 3. Send account transition reminders (7, 3, 1 days before)
    $transitions = $db->query(
        "SELECT at.*, e.is_active FROM account_transitions at
         JOIN employees e ON e.id = at.employee_id
         WHERE at.expiry_date > NOW() AND at.reminders_cancelled = 0"
    )->fetchAll();

    foreach ($transitions as $tr) {
        $daysLeft = (strtotime($tr['expiry_date']) - time()) / 86400;
        $sent     = explode(',', $tr['reminders_sent'] ?? '');

        foreach ([7, 3, 1] as $d) {
            if ($daysLeft <= $d && !in_array((string)$d, $sent)) {
                addNotification($tr['employee_id'], 'account_reminder',
                    "Reminder: your old username \"{$tr['old_username']}\" will be deactivated in {$d} day(s). Please switch to your new username \"{$tr['new_username']}\" to log in.",
                    $tr['id']);
                $sent[] = $d;
                $db->prepare("UPDATE account_transitions SET reminders_sent=? WHERE id=?")
                   ->execute([implode(',', array_filter($sent)), $tr['id']]);
            }
        }
    }

    // 4. Send old-password reminders (24, 12, 6 hours)
    $employees = $db->query(
        "SELECT id, old_password_expires, old_password_reminder_cancelled
         FROM employees
         WHERE old_password_hash IS NOT NULL
           AND old_password_expires > NOW()
           AND old_password_reminder_cancelled = 0"
    )->fetchAll();

    foreach ($employees as $emp) {
        $hoursLeft = (strtotime($emp['old_password_expires']) - time()) / 3600;
        // Use notification table to deduplicate (check recent sent ones)
        foreach ([24, 12, 6] as $h) {
            if ($hoursLeft <= $h) {
                $exists = $db->prepare(
                    "SELECT id FROM notifications WHERE employee_id=? AND type='password_reminder'
                     AND message LIKE ? AND created_at > DATE_SUB(NOW(), INTERVAL 2 HOUR)"
                );
                $exists->execute([$emp['id'], "%{$h} hour%"]);
                if (!$exists->fetch()) {
                    addNotification($emp['id'], 'password_reminder',
                        "Reminder: your old password will expire in approximately {$h} hour(s). After that, only your new password will work.", null);
                }
                break; // Only send the most urgent one per cycle
            }
        }
    }
}
