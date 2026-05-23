<?php
$cookieParams = session_get_cookie_params();
session_set_cookie_params([
    'lifetime' => $cookieParams['lifetime'],
    'path'     => $cookieParams['path'],
    'domain'   => $cookieParams['domain'],
    'secure'   => false,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

// ── Session timeout ──
$idleTimeout = 1800;     // 30 min idle
$absoluteTimeout = 28800; // 8 hours absolute
$now = time();
if (!empty($_SESSION['last_activity']) && ($now - $_SESSION['last_activity'] > $idleTimeout)) {
    $_SESSION = [];
    session_destroy();
    session_start();
    $_SESSION = [];
}
if (!empty($_SESSION['created_at']) && ($now - $_SESSION['created_at'] > $absoluteTimeout)) {
    $_SESSION = [];
    session_destroy();
    session_start();
    $_SESSION = [];
}
$_SESSION['last_activity'] = $now;
if (empty($_SESSION['created_at'])) {
    $_SESSION['created_at'] = $now;
}

// ── CSRF token ──
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── Security headers ──
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

set_exception_handler(function (Throwable $e) {
    if (!headers_sent()) http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
});

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ai_config.php';

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$data   = array_merge($_GET, $_POST, $body);
$action = $data['action'] ?? '';

// ── Actions exempt from CSRF validation (public reads, auth, AI) ──
$csrfSafe = [
    'get_availability', 'get_all_availability', 'get_menu',
    'get_restaurants', 'get_restaurant', 'get_cuisines',
    'get_recommendations', 'get_restaurant_reviews', 'get_reviews',
    'ai_search', 'ai_restaurant_search', 'get_csrf_token',
    'customer_check_auth', 'customer_login', 'customer_register',
    'login', 'check_auth', 'find_customer_reservation',
    'get_recommendation_feedback',
];
function validateCsrf() {
    global $action, $csrfSafe;
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    if (in_array($action, $csrfSafe, true)) return;
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!$header || !hash_equals($_SESSION['csrf_token'], $header)) {
        err('Invalid or missing CSRF token.', 403);
    }
}

// ── Login rate limiting ──
function clientIp(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
function checkLoginRateLimit(): void {
    $db = getDB();
    $ip = clientIp();
    $window = $db->prepare(
        "SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
    );
    $window->execute([$ip]);
    if ((int)$window->fetchColumn() >= 5) {
        err('Too many login attempts. Please try again in 15 minutes.', 429);
    }
}
function recordLoginAttempt(string $status): void {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO login_attempts (ip, status) VALUES (?, ?)");
    $stmt->execute([clientIp(), $status]);
}
function clearLoginAttempts(): void {
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM login_attempts WHERE ip = ?");
    $stmt->execute([clientIp()]);
}

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

function cid()   { return $_SESSION['cid']   ?? null; }
function cauth() { if (!cid()) err('Please sign in to continue.', 401); }

// CSRF validation (after action known, before any mutation)
validateCsrf();

// lightweight maintenance on each request
doMaintenance();

switch ($action) {
    // Public
    case 'get_availability':      getAvailability();      break;
    case 'get_all_availability':  getAllAvailability();    break;
    case 'make_reservation':      makeReservation();      break;
    case 'request_private_room':  requestPrivateRoom();   break;

    // Public — menu & customer self-service
    case 'get_menu':                     getMenu();                    break;
    case 'get_restaurants':              getRestaurants();             break;
    case 'get_restaurant':               getRestaurant();              break;
    case 'get_recommendations':          getRecommendations();         break;
    case 'get_cuisines':                 getCuisines();                break;
    case 'get_restaurant_reviews':       getRestaurantReviews();       break;
    case 'add_review':                   addReview();                  break;
    case 'get_recommendation_feedback':  logRecommendFeedback();       break;
    case 'ai_search':                    aiSearch();                   break;
    case 'ai_restaurant_search':         aiRestaurantSearch();         break;
    case 'get_reviews':                  getReviews();                 break;
    case 'submit_review':                cauth(); submitReview();      break;
    case 'add_pre_order':               addPreOrder();                break;
    case 'find_customer_reservation':   findCustomerReservation();    break;
    case 'customer_update_reservation':     customerUpdateReservation();      break;
    case 'customer_cancel_reservation':     customerCancelReservation();      break;
    case 'customer_cancel_private_request': customerCancelPrivateRequest();   break;

    // Customer Auth
    case 'customer_register':    customerRegister();    break;
    case 'customer_login':       customerLogin();       break;
    case 'customer_logout':      customerLogout();      break;
    case 'customer_check_auth':  customerCheckAuth();   break;
    case 'my_reservations':      cauth(); myReservations(); break;

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
    global $data;
    $db           = getDB();
    $restaurantId = $data['restaurant_id'] ?? 'cloud-pavilion';
    $date         = $data['date'] ?? date('Y-m-d');

    $stmt = $db->prepare(
        "SELECT dt.type,
                COUNT(*) AS total,
                COUNT(*) - COUNT(r.id) AS available
         FROM dining_tables dt
         LEFT JOIN reservations r
           ON r.table_id = dt.id AND r.status='confirmed' AND r.visit_date = ?
         WHERE dt.restaurant_id = ?
         GROUP BY dt.type"
    );
    $stmt->execute([$date, $restaurantId]);
    $result = [];
    foreach ($stmt->fetchAll() as $row) $result[$row['type']] = $row;
    ok(['types' => $result, 'restaurant_id' => $restaurantId, 'date' => $date]);
}

function getAllAvailability() {
    global $data;
    $db   = getDB();
    $date = $data['date'] ?? date('Y-m-d');

    $stmt = $db->prepare(
        "SELECT dt.restaurant_id, dt.type,
                COUNT(*) AS total,
                COUNT(*) - COUNT(r.id) AS available
         FROM dining_tables dt
         LEFT JOIN reservations r
           ON r.table_id = dt.id AND r.status='confirmed' AND r.visit_date = ?
         GROUP BY dt.restaurant_id, dt.type"
    );
    $stmt->execute([$date]);
    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $result[$row['restaurant_id']][$row['type']] = $row;
    }
    ok(['availability' => $result, 'date' => $date]);
}

function makeReservation() {
    global $data;
    $restaurantId = trim($data['restaurant_id'] ?? 'cloud-pavilion');
    $type      = $data['table_type'] ?? '';
    $name      = trim($data['customer_name'] ?? '');
    $phone     = trim($data['phone'] ?? '');
    $visitDate = $data['visit_date'] ?? date('Y-m-d');
    $visitTime = trim($data['visit_time'] ?? '');
    $partySize = (int)($data['party_size'] ?? 0) ?: null;

    if (!in_array($type, ['small','medium','large','private'])) err('请选择桌型。');
    if (!$name)  err('请填写您的姓名。');
    if (!preg_match('/^1[3-9]\d{9}$/', $phone)) err('请填写正确的11位手机号。');
    if (!$visitDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $visitDate)) err('请选择用餐日期。');
    if (!$visitTime) err('请选择用餐时间。');
    if (strtotime($visitDate) < strtotime(date('Y-m-d'))) err('预订日期不能早于今天。');

    $db = getDB();

    // Validate time within business hours
    $restStmt = $db->prepare("SELECT name_zh, open_time, close_time FROM restaurants WHERE id=?");
    $restStmt->execute([$restaurantId]);
    $rest = $restStmt->fetch();
    if ($rest) {
        $vt    = strtotime("$visitDate $visitTime");
        $open  = strtotime("$visitDate {$rest['open_time']}");
        $close = strtotime("$visitDate {$rest['close_time']}");
        $last  = $close - 3600; // last booking 1 hour before close
        if ($vt < $open || $vt > $last) {
            $lastStr = date('H:i', $last);
            err("{$rest['name_zh']} 营业时间 {$rest['open_time']}–{$rest['close_time']}，最晚可预订 {$lastStr}。");
        }
        $restName = $rest['name_zh'];
    } else {
        $restName = $restaurantId;
    }

    // Find available table for this restaurant + date
    $stmt = $db->prepare(
        "SELECT dt.id, dt.table_number FROM dining_tables dt
         WHERE dt.restaurant_id = ? AND dt.type = ?
           AND dt.id NOT IN (
               SELECT table_id FROM reservations
               WHERE status='confirmed' AND visit_date = ?
           )
         ORDER BY dt.table_number LIMIT 1"
    );
    $stmt->execute([$restaurantId, $type, $visitDate]);
    $table = $stmt->fetch();
    if (!$table) err('该日期此桌型已无空位，请换个日期或桌型。');

    $ins = $db->prepare(
        "INSERT INTO reservations
         (restaurant_id, table_id, customer_name, phone, party_size, visit_date, visit_time, customer_id)
         VALUES (?,?,?,?,?,?,?,?)"
    );
    $ins->execute([$restaurantId, $table['id'], $name, $phone, $partySize, $visitDate, $visitTime, cid()]);
    $resId = (int)$db->lastInsertId();

    ok(['message' => "预订成功！欢迎光临{$restName}，期待您的到来。", 'reservation_id' => $resId]);
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
   CUSTOMER AUTH
   ============================================================ */

function customerRegister() {
    global $data;
    $name  = trim($data['full_name'] ?? '');
    $email = strtolower(trim($data['email'] ?? ''));
    $phone = trim($data['phone'] ?? '') ?: null;
    $pass  = $data['password'] ?? '';

    if (!$name)  err('Please enter your name.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) err('Invalid email address.');
    if (strlen($pass) < 6) err('Password must be at least 6 characters.');
    if ($phone && !preg_match('/^1[3-9]\d{9}$/', $phone)) err('Please enter a valid phone number.');

    $db  = getDB();
    $chk = $db->prepare("SELECT id FROM customers WHERE email=?");
    $chk->execute([$email]);
    if ($chk->fetch()) err('This email is already registered. Please sign in.');

    $hash = password_hash($pass, PASSWORD_BCRYPT);
    $db->prepare("INSERT INTO customers (full_name,email,password_hash,phone) VALUES (?,?,?,?)")
       ->execute([$name, $email, $hash, $phone]);
    $newId = (int)$db->lastInsertId();

    $_SESSION['cid']    = $newId;
    $_SESSION['cname']  = $name;
    $_SESSION['cemail'] = $email;

    ok(['uid' => $newId, 'name' => $name, 'email' => $email, 'phone' => $phone]);
}

function customerLogin() {
    global $data;
    $email = strtolower(trim($data['email'] ?? ''));
    $pass  = $data['password'] ?? '';
    if (!$email || !$pass) err('Please enter your email and password.');

    checkLoginRateLimit();

    $db   = getDB();
    $stmt = $db->prepare("SELECT * FROM customers WHERE email=?");
    $stmt->execute([$email]);
    $cust = $stmt->fetch();

    if (!$cust || !password_verify($pass, $cust['password_hash'])) {
        recordLoginAttempt('fail');
        err('Incorrect email or password.');
    }
    clearLoginAttempts();

    $_SESSION['cid']    = $cust['id'];
    $_SESSION['cname']  = $cust['full_name'];
    $_SESSION['cemail'] = $cust['email'];

    ok(['uid' => $cust['id'], 'name' => $cust['full_name'],
        'email' => $cust['email'], 'phone' => $cust['phone']]);
}

function customerLogout() {
    unset($_SESSION['cid'], $_SESSION['cname'], $_SESSION['cemail']);
    ok();
}

function customerCheckAuth() {
    if (!cid()) { ok(['logged_in' => false, 'csrf_token' => $_SESSION['csrf_token']]); return; }
    $db   = getDB();
    $stmt = $db->prepare("SELECT id,full_name,email,phone FROM customers WHERE id=?");
    $stmt->execute([cid()]);
    $c = $stmt->fetch();
    if (!$c) {
        unset($_SESSION['cid'], $_SESSION['cname'], $_SESSION['cemail']);
        ok(['logged_in' => false, 'csrf_token' => $_SESSION['csrf_token']]);
        return;
    }
    ok(['logged_in' => true, 'uid' => $c['id'], 'name' => $c['full_name'],
        'email' => $c['email'], 'phone' => $c['phone'], 'csrf_token' => $_SESSION['csrf_token']]);
}

function myReservations() {
    $db = getDB();

    $prof = $db->prepare("SELECT full_name, phone FROM customers WHERE id=?");
    $prof->execute([cid()]);
    $cust = $prof->fetch();

    $sql = "SELECT r.id, r.customer_name, r.phone, r.party_size, r.status,
                   r.visit_date, r.visit_time, r.restaurant_id, r.created_at,
                   COALESCE(rs.name_zh, r.restaurant_id) AS restaurant_name,
                   dt.table_number, dt.type AS table_type,
                   dt.min_capacity, dt.max_capacity,
                   (SELECT GROUP_CONCAT(po.item_name,' ×',po.quantity ORDER BY po.id SEPARATOR ', ')
                    FROM pre_orders po WHERE po.reservation_id=r.id) AS pre_orders_summary
            FROM reservations r
            JOIN dining_tables dt ON dt.id = r.table_id
            LEFT JOIN restaurants rs ON rs.id = r.restaurant_id
            WHERE r.status='confirmed'
              AND (r.customer_id = ?";
    $params = [cid()];

    if (!empty($cust['phone'])) {
        $sql .= " OR r.phone = ?";
        $params[] = $cust['phone'];
    }

    $sql .= ") ORDER BY r.visit_date ASC, r.visit_time ASC, r.created_at DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    ok(['reservations' => $stmt->fetchAll()]);
}

/* ============================================================
   AUTH
   ============================================================ */

function handleLogin() {
    global $data;
    $loginName = trim($data['username'] ?? '');
    $password  = $data['password'] ?? '';
    if (!$loginName || !$password) err('Please enter your username and password.');

    checkLoginRateLimit();

    $db = getDB();

    // Find employee by username / nickname / alias
    $emp = findEmployeeByLogin($db, $loginName);
    if (!$emp) { recordLoginAttempt('fail'); err('Invalid username or password.'); }
    if (!$emp['is_active']) err('This account has been disabled.');

    $isOldAlias   = !empty($emp['_alias']); // logged in with old alias
    $isOldPassword = false;

    // Verify password (current first)
    if (!password_verify($password, $emp['password_hash'])) {
        // Try old password within 72h
        if ($emp['old_password_hash'] && $emp['old_password_expires'] && strtotime($emp['old_password_expires']) > time()) {
            if (!password_verify($password, $emp['old_password_hash'])) {
                recordLoginAttempt('fail');
                err('Invalid username or password.');
            }
            $isOldPassword = true;
        } else {
            recordLoginAttempt('fail');
            err('Invalid username or password.');
        }
    }
    clearLoginAttempts();

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
    if (!uid()) { ok(['logged_in' => false, 'csrf_token' => $_SESSION['csrf_token']]); }
    $db = getDB();
    $stmt = $db->prepare("SELECT id,username,nickname,full_name,is_admin FROM employees WHERE id=?");
    $stmt->execute([uid()]);
    $emp = $stmt->fetch();
    if (!$emp) { session_destroy(); ok(['logged_in' => false, 'csrf_token' => $_SESSION['csrf_token']]); }

    $unread = $db->prepare("SELECT COUNT(*) FROM notifications WHERE employee_id=? AND is_read=0");
    $unread->execute([uid()]);
    $pending = $db->query("SELECT COUNT(*) FROM private_room_requests WHERE status='pending'")->fetchColumn();

    ok(['logged_in' => true, 'uid' => $emp['id'], 'username' => $emp['username'],
        'nickname' => $emp['nickname'], 'full_name' => $emp['full_name'],
        'is_admin' => (bool)$emp['is_admin'], 'unread' => (int)$unread->fetchColumn(),
        'pending_rooms' => (int)$pending, 'csrf_token' => $_SESSION['csrf_token']]);
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

/* ============================================================
   RESTAURANT LISTING & RECOMMENDATIONS
   ============================================================ */

function getRestaurants() {
    global $data;
    $db       = getDB();
    $cuisine  = trim($data['cuisine'] ?? '');
    $district = trim($data['district'] ?? '');
    $price    = trim($data['price'] ?? '');
    $search   = trim($data['search'] ?? '');
    $limit    = min(50, max(1, (int)($data['limit'] ?? 20)));

    $sql = "SELECT r.id, r.name, r.name_cn, r.description, r.district, r.price_range,
                   r.avg_rating, r.review_count, r.opening_hours, r.phone, r.address, r.image_url
            FROM restaurants r WHERE r.is_active = 1";
    $params = [];

    if ($cuisine) {
        $sql .= " AND r.id IN (SELECT restaurant_id FROM restaurant_cuisine_map rc JOIN restaurant_cuisines c ON c.id=rc.cuisine_id WHERE c.name=?)";
        $params[] = $cuisine;
    }
    if ($district) {
        $sql .= " AND r.district LIKE ?";
        $params[] = "%$district%";
    }
    if ($price) {
        $sql .= " AND r.price_range = ?";
        $params[] = $price;
    }
    if ($search) {
        $sql .= " AND (r.name LIKE ? OR r.name_cn LIKE ? OR r.description LIKE ? OR r.district LIKE ?)";
        $like = "%$search%";
        $params = array_merge($params, [$like, $like, $like, $like]);
    }
    $sql .= " ORDER BY r.avg_rating DESC, r.review_count DESC LIMIT " . (int)$limit;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $restaurants = $stmt->fetchAll();

    // Attach cuisines to each restaurant
    $cuisineStmt = $db->prepare(
        "SELECT c.name FROM restaurant_cuisine_map rc
         JOIN restaurant_cuisines c ON c.id = rc.cuisine_id
         WHERE rc.restaurant_id = ?"
    );
    foreach ($restaurants as &$r) {
        $cuisineStmt->execute([$r['id']]);
        $r['cuisines'] = array_column($cuisineStmt->fetchAll(), 'name');
    }
    unset($r);

    ok(['restaurants' => $restaurants]);
}

function getRestaurant() {
    global $data;
    $id = (int)($data['id'] ?? 0);
    if (!$id) err('Missing restaurant ID.');

    $db = getDB();
    $stmt = $db->prepare(
        "SELECT r.* FROM restaurants r WHERE r.id = ? AND r.is_active = 1"
    );
    $stmt->execute([$id]);
    $restaurant = $stmt->fetch();
    if (!$restaurant) err('Restaurant not found.');

    // Get cuisines
    $stmt = $db->prepare(
        "SELECT c.name FROM restaurant_cuisine_map rc
         JOIN restaurant_cuisines c ON c.id = rc.cuisine_id
         WHERE rc.restaurant_id = ?"
    );
    $stmt->execute([$id]);
    $restaurant['cuisines'] = array_column($stmt->fetchAll(), 'name');

    // Get menu items
    $menuStmt = $db->prepare(
        "SELECT mi.item_id, mi.name, mi.description, mi.price, mi.image_url, mi.is_available,
                c.name AS category_name
         FROM menu_items mi
         LEFT JOIN categories c ON c.category_id = mi.category_id
         WHERE mi.restaurant_id = ? OR mi.restaurant_id IS NULL
         ORDER BY c.display_order, mi.name"
    );
    $menuStmt->execute([$id]);
    $restaurant['menu'] = $menuStmt->fetchAll();

    ok(['restaurant' => $restaurant]);
}

function getCuisines() {
    $db = getDB();
    $rows = $db->query("SELECT id, name FROM restaurant_cuisines ORDER BY id")->fetchAll();
    ok(['cuisines' => $rows]);
}

function getRecommendations() {
    global $data;
    $db = getDB();

    // Determine user context
    $userId = (int)($data['user_id'] ?? 0);
    $query  = trim($data['query'] ?? '');

    // Build user context string
    $userContext = [];
    $userContext['time'] = date('Y-m-d H:i:s');
    $userContext['day_of_week'] = date('l');

    if ($userId) {
        $stmt = $db->prepare("SELECT * FROM user_preferences WHERE user_id = ?");
        $stmt->execute([$userId]);
        $prefs = $stmt->fetch();
        if ($prefs) {
            $userContext['preferences'] = $prefs;
        }

        $stmt = $db->prepare(
            "SELECT r.id, r.name, r.name_cn, uh.action_type
             FROM user_history uh
             JOIN restaurants r ON r.id = uh.restaurant_id
             WHERE uh.user_id = ? ORDER BY uh.created_at DESC LIMIT 10"
        );
        $stmt->execute([$userId]);
        $history = $stmt->fetchAll();
        if ($history) {
            $userContext['history'] = $history;
        }

        // Get favorites
        $stmt = $db->prepare("SELECT restaurant_id FROM user_favorites WHERE user_id = ?");
        $stmt->execute([$userId]);
        $favs = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if ($favs) {
            $userContext['favorites'] = $favs;
        }
    }

    if ($query) {
        $userContext['user_query'] = $query;
    }

    // Get all active restaurants
    $restaurants = $db->query(
        "SELECT r.id, r.name, r.name_cn, r.district, r.price_range, r.avg_rating, r.review_count,
                r.description
         FROM restaurants r WHERE r.is_active = 1 ORDER BY r.avg_rating DESC"
    )->fetchAll();

    if (empty($restaurants)) {
        ok(['recommendations' => []]);
        return;
    }

    // Attach cuisines
    $cuisineStmt = $db->prepare(
        "SELECT c.name FROM restaurant_cuisine_map rc
         JOIN restaurant_cuisines c ON c.id = rc.cuisine_id
         WHERE rc.restaurant_id = ?"
    );
    foreach ($restaurants as &$r) {
        $cuisineStmt->execute([$r['id']]);
        $r['cuisines'] = array_column($cuisineStmt->fetchAll(), 'name');
    }
    unset($r);

    // Step 1: Try AI-powered recommendation
    $recommendedIds = null;
    try {
        $userContextJson = json_encode($userContext, JSON_UNESCAPED_UNICODE);
        $restaurantsJson = json_encode($restaurants, JSON_UNESCAPED_UNICODE);
        $recommendedIds = callAIRecommendation($userContextJson, $restaurantsJson);
    } catch (Throwable $e) {
        // AI failed, fall through to fallback
    }

    // Step 2: Fallback to rule-based scoring
    if (!$recommendedIds || count($recommendedIds) === 0) {
        $recommendedIds = fallbackRecommendations($restaurants);
    }

    // Filter to only valid IDs and build full result
    $validIds = array_unique(array_map('intval', $recommendedIds));
    $resultRestaurants = [];
    $idOrder = array_flip($validIds);

    foreach ($restaurants as $r) {
        if (isset($idOrder[$r['id']])) {
            $resultRestaurants[] = $r;
        }
    }

    // Sort by the AI's order
    usort($resultRestaurants, function ($a, $b) use ($idOrder) {
        return ($idOrder[$a['id']] ?? 999) <=> ($idOrder[$b['id']] ?? 999);
    });

    // Log recommendation
    try {
        $stmt = $db->prepare(
            "INSERT INTO ai_recommendation_logs (user_id, query_text, recommendations) VALUES (?, ?, ?)"
        );
        $stmt->execute([
            $userId ?: null,
            $query ?: null,
            json_encode(array_column($resultRestaurants, 'id'))
        ]);
    } catch (Throwable $e) {
        // Non-critical, ignore
    }

    ok(['recommendations' => $resultRestaurants]);
}

function getRestaurantReviews() {
    global $data;
    $id    = (int)($data['id'] ?? 0);
    $limit = min(50, max(1, (int)($data['limit'] ?? 20)));

    if (!$id) err('Missing restaurant ID.');

    $db = getDB();
    $stmt = $db->prepare(
        "SELECT r.id, r.customer_name, r.rating, r.comment, r.created_at
         FROM reviews r WHERE r.restaurant_id = ?
         ORDER BY r.created_at DESC LIMIT " . (int)$limit
    );
    $stmt->execute([$id]);
    $reviews = $stmt->fetchAll();

    // Get rating summary
    $summary = $db->prepare(
        "SELECT COUNT(*) AS total, AVG(rating) AS avg_rating,
                SUM(CASE WHEN rating=5 THEN 1 ELSE 0 END) AS five,
                SUM(CASE WHEN rating=4 THEN 1 ELSE 0 END) AS four,
                SUM(CASE WHEN rating=3 THEN 1 ELSE 0 END) AS three,
                SUM(CASE WHEN rating=2 THEN 1 ELSE 0 END) AS two,
                SUM(CASE WHEN rating=1 THEN 1 ELSE 0 END) AS one
         FROM reviews WHERE restaurant_id = ?"
    );
    $summary->execute([$id]);
    $stats = $summary->fetch();

    ok(['reviews' => $reviews, 'stats' => $stats]);
}

function addReview() {
    global $data;
    $restaurantId = (int)($data['restaurant_id'] ?? 0);
    $name         = trim($data['customer_name'] ?? '');
    $rating       = (int)($data['rating'] ?? 0);
    $comment      = trim($data['comment'] ?? '');
    $userId       = (int)($data['user_id'] ?? 0);

    if (!$restaurantId) err('Missing restaurant ID.');
    if (!$name)         err('Please enter your name.');
    if ($rating < 1 || $rating > 5) err('Rating must be between 1 and 5.');
    if (!$comment)      err('Please write a comment.');

    $db = getDB();

    // Verify restaurant exists
    $chk = $db->prepare("SELECT id FROM restaurants WHERE id = ? AND is_active = 1");
    $chk->execute([$restaurantId]);
    if (!$chk->fetch()) err('Restaurant not found.');

    $stmt = $db->prepare(
        "INSERT INTO reviews (restaurant_id, user_id, customer_name, rating, comment) VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->execute([$restaurantId, $userId ?: null, $name, $rating, $comment]);

    // Update aggregate rating
    $db->prepare(
        "UPDATE restaurants r SET
            avg_rating   = ROUND((SELECT AVG(rating) FROM reviews WHERE restaurant_id = r.id), 1),
            review_count = (SELECT COUNT(*) FROM reviews WHERE restaurant_id = r.id)
         WHERE r.id = ?"
    )->execute([$restaurantId]);

    // Log history
    if ($userId) {
        try {
            $db->prepare(
                "INSERT INTO user_history (user_id, restaurant_id, action_type) VALUES (?, ?, 'review')"
            )->execute([$userId, $restaurantId]);
        } catch (Throwable $e) {}
    }

    ok(['message' => 'Review submitted. Thank you for your feedback!', 'id' => (int)$db->lastInsertId()]);
}

function logRecommendFeedback() {
    global $data;
    $logId = (int)($data['log_id'] ?? 0);
    $feedback = trim($data['feedback'] ?? '');

    if (!$logId || !in_array($feedback, ['clicked','ignored','thumbs_up','thumbs_down'])) {
        ok(); // Silent ignore for non-critical feedback
        return;
    }

    $db = getDB();
    $db->prepare("UPDATE ai_recommendation_logs SET user_feedback = ? WHERE id = ?")
       ->execute([$feedback, $logId]);
    ok();
}

/* ============================================================
   AI-POWERED SMART SEARCH (DeepSeek AI + keyword fallback)
   ============================================================ */

/* ============================================================
   AI 餐厅推荐搜索
   ============================================================ */

/* ============================================================
   REVIEWS
   ============================================================ */

function getReviews() {
    global $data;
    $rid  = trim($data['restaurant_id'] ?? '');
    if (!$rid) err('Missing restaurant_id.');
    $db   = getDB();

    $stmt = $db->prepare(
        "SELECT id, reviewer_name, rating, comment, visit_date, created_at
         FROM reviews WHERE restaurant_id=?
         ORDER BY created_at DESC LIMIT 60"
    );
    $stmt->execute([$rid]);
    $reviews = $stmt->fetchAll();

    $s = $db->prepare(
        "SELECT COUNT(*) AS total, ROUND(AVG(rating),1) AS avg_rating,
                SUM(rating=5) AS r5, SUM(rating=4) AS r4,
                SUM(rating=3) AS r3, SUM(rating=2) AS r2, SUM(rating=1) AS r1
         FROM reviews WHERE restaurant_id=?"
    );
    $s->execute([$rid]);
    $stat = $s->fetch();

    ok([
        'reviews' => $reviews,
        'stats'   => [
            'total'        => (int)$stat['total'],
            'avg_rating'   => $stat['avg_rating'] ? (float)$stat['avg_rating'] : null,
            'distribution' => [5=>(int)$stat['r5'],4=>(int)$stat['r4'],3=>(int)$stat['r3'],2=>(int)$stat['r2'],1=>(int)$stat['r1']],
        ],
    ]);
}

function submitReview() {
    global $data;
    $rid     = trim($data['restaurant_id'] ?? '');
    $rating  = (int)($data['rating']  ?? 0);
    $comment = trim($data['comment']  ?? '');
    $vdate   = $data['visit_date']    ?? null;

    if (!$rid)               err('Missing restaurant ID.');
    if ($rating < 1 || $rating > 5) err('Rating must be between 1 and 5 stars.');
    if (mb_strlen($comment) < 10)   err('Please write at least 10 characters.');
    if (mb_strlen($comment) > 1000) err('Review is too long (max 1,000 characters).');

    $db  = getDB();
    $chk = $db->prepare("SELECT id FROM reviews WHERE restaurant_id=? AND customer_id=?");
    $chk->execute([$rid, cid()]);
    if ($chk->fetch()) err('You have already reviewed this restaurant.');

    // Anonymize name: "John Smith" → "John S."
    $prof = $db->prepare("SELECT full_name FROM customers WHERE id=?");
    $prof->execute([cid()]);
    $c = $prof->fetch();
    $name = $c['full_name'] ?? 'Guest';
    $parts = preg_split('/\s+/', trim($name));
    if (count($parts) > 1) {
        $last  = array_pop($parts);
        $name  = implode(' ', $parts) . ' ' . strtoupper($last[0]) . '.';
    }

    $db->prepare(
        "INSERT INTO reviews (restaurant_id,customer_id,reviewer_name,rating,comment,visit_date)
         VALUES (?,?,?,?,?,?)"
    )->execute([$rid, cid(), $name, $rating, $comment, $vdate ?: null]);

    ok(['message' => 'Thank you for your review!']);
}

/* ── Also create reviews table in maintenance if missing ── */

function aiRestaurantSearch() {
    global $data;
    $query       = trim($data['query']       ?? '');
    $restaurants = $data['restaurants']      ?? [];

    if (!$query) err('请输入搜索内容。');
    if (!$restaurants) err('未收到餐厅列表。');

    // Build restaurant context for the prompt
    $ctx = '';
    foreach ($restaurants as $r) {
        $tags = implode('、', (array)($r['tags'] ?? []));
        $ctx .= "ID:{$r['id']} | {$r['nameZh']}（{$r['name']}）| 菜系:{$r['cuisine']} | 标签:{$tags} | 人均:¥{$r['avgPrice']} | 地区:{$r['district']} | 营业:{$r['hours']}\n";
    }

    $prompt = <<<PROMPT
You are the AI search assistant for "SmartDine" restaurant platform.

Restaurant list:
{$ctx}

User search: {$query}

Analyse the user's intent (cuisine type, price, occasion, taste, area, mood, etc.) and select the most relevant restaurants from the list above.

Return ONLY valid JSON, no other text or markdown:
{
  "matched_ids": ["restaurant-id-1", "restaurant-id-2"],
  "summary": "One-sentence description of the results in English",
  "suggestion": "A helpful follow-up tip in English, or empty string"
}

Rules:
- matched_ids must only contain IDs from the list above
- If nothing matches, return []
- Understand natural language: "romantic date" → quiet, elegant places; "cheap" → low avg price; "spicy" → Sichuan/Korean/etc; "group" → places with large tables; "breakfast" → early-opening places
- Respond in English
PROMPT;

    try {
        $response = callAI($prompt);

        // Parse JSON from response
        $json = json_decode($response, true);
        if (!$json) {
            if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/i', $response, $m))
                $json = json_decode($m[1], true);
        }
        if (!$json) {
            preg_match('/\{[\s\S]*\}/', $response, $m);
            if ($m) $json = json_decode($m[0], true);
        }

        if (!$json || !array_key_exists('matched_ids', $json)) {
            err('AI 解析失败，已切换到关键词搜索。');
        }

        ok([
            'matched_ids' => array_values((array)$json['matched_ids']),
            'summary'     => trim($json['summary']    ?? ''),
            'suggestion'  => trim($json['suggestion'] ?? ''),
            'ai'          => true,
        ]);
    } catch (Throwable $e) {
        err('AI 暂时不可用：' . $e->getMessage());
    }
}

function aiSearch() {
    global $data;
    $query = trim($data['query'] ?? '');
    $scope = $data['scope'] ?? 'staff';

    if (!$query) err('Please enter a search query.');
    if ($scope === 'staff') auth();

    $db = getDB();

    // Step 1: Try AI-powered search (DeepSeek understands intent → PHP queries DB)
    try {
        $result = aiPoweredSearch($db, $query, $scope);
        if ($result !== null) {
            ok($result);
            return;
        }
    } catch (Throwable $e) {
        // AI failed — fall through to keyword matching
    }

    // Step 2: Fallback — keyword matching
    $result = smartMatchSearch($db, $query, $scope);
    if ($result !== null) {
        $result['ai'] = false;
        ok($result);
        return;
    }

    // Step 3: General status as last resort
    $general = generalStatus($db, $scope);
    $general['ai'] = false;
    ok($general);
}

/**
 * AI-powered search: send question + DB context to DeepSeek,
 * parse the JSON intent response, then execute the appropriate DB query.
 * Returns null if AI fails or can't understand.
 */
function aiPoweredSearch(PDO $db, string $query, string $scope): ?array {
    $context = buildAIContext();

    $prompt = <<<PROMPT
You are a restaurant assistant for "Cloud Pavilion". Analyze the user's question and return ONLY valid JSON.

{$context}

User question: {$query}

Respond with ONLY this JSON format (no other text, no markdown):
{
  "intent": "check_availability | find_reservation | search_employee | general_status",
  "params": {
    "type": "small|medium|large|private",
    "customer_name": "",
    "phone": "",
    "table_number": "",
    "search_text": ""
  },
  "summary": "A brief English summary of what was found",
  "suggestion": "A helpful follow-up suggestion, or empty string"
}

Rules:
- check_availability: user asks about free/available tables or private rooms
- find_reservation: user asks about a specific booking (by name, phone, table)
- search_employee: staff search only, finding employee info
- general_status: overview of restaurant
- Fill ONLY the params you have info for, leave others empty
- Always respond in English regardless of the user's language
PROMPT;

    $response = callAI($prompt);

    // Parse JSON from response
    $json = json_decode($response, true);
    if (!$json) {
        // Try extracting from code block if wrapped in markdown
        if (preg_match('/```(?:json)?\s*\n?(.*?)\n?```/s', $response, $m)) {
            $json = json_decode($m[1], true);
        }
    }
    if (!$json) {
        // Last resort: find anything that looks like JSON
        preg_match('/\{(?:[^{}]|(?R))*\}/s', $response, $m);
        if (!empty($m[0]) && ($decoded = json_decode($m[0], true))) {
            $json = $decoded;
        }
    }

    if (!$json || empty($json['intent'])) return null;

    $intent     = $json['intent'];
    $params     = $json['params'] ?? [];
    $summary    = trim($json['summary'] ?? '');
    $suggestion = trim($json['suggestion'] ?? '');

    $result = null;

    switch ($intent) {
        case 'check_availability':
            $type = $params['type'] ?? '';
            if (!in_array($type, ['small','medium','large','private',''])) $type = '';
            $result = buildAvailabilityResult($db, $type);
            // Override the default summary with AI-generated one
            if ($summary) $result['ai_summary'] = $summary;
            break;

        case 'find_reservation':
            $filters = [];
            if (!empty($params['customer_name'])) $filters['customer_name'] = $params['customer_name'];
            if (!empty($params['phone']))          $filters['phone']          = $params['phone'];
            if (!empty($params['table_number']))   $filters['table_number']   = $params['table_number'];

            if (!empty($filters)) {
                $result = buildReservationResult($db, $filters);
            }

            // If AI had specific filters but no results, try fuzzy search
            if (!$result || $result['count'] === 0) {
                $searchText = $params['search_text'] ?? $params['customer_name'] ?? $query;
                $like = "%{$searchText}%";
                $stmt = $db->prepare(
                    "SELECT r.id, r.customer_name, r.phone, r.party_size, r.created_at,
                            dt.table_number, dt.type AS table_type
                     FROM reservations r JOIN dining_tables dt ON dt.id=r.table_id
                     WHERE r.status='confirmed'
                       AND (r.customer_name LIKE ? OR r.phone LIKE ? OR dt.table_number LIKE ?)
                     ORDER BY r.created_at DESC LIMIT 20"
                );
                $stmt->execute([$like, $like, $like]);
                $rows = $stmt->fetchAll();
                $result = [
                    'success' => true,
                    'type'    => 'reservations',
                    'results' => $rows,
                    'count'   => count($rows),
                ];
            }

            if ($summary) $result['ai_summary'] = $summary;
            break;

        case 'search_employee':
            if ($scope !== 'staff') return null;
            $searchText = $params['search_text'] ?? $params['customer_name'] ?? $query;
            $result = buildEmployeeResult($db, $searchText);
            if ($summary) $result['ai_summary'] = $summary;
            break;

        case 'general_status':
        case 'general':
            $result = generalStatus($db, $scope);
            if ($summary) $result['ai_summary'] = $summary;
            break;

        default:
            return null; // Unknown intent → keyword fallback
    }

    if ($result) {
        $result['ai'] = true;
        if ($suggestion) $result['ai_suggestion'] = $suggestion;
        return $result;
    }

    return null;
}

/**
 * 纯关键词智能搜索 — 不依赖任何外部 API
 */
function smartMatchSearch(PDO $db, string $query, string $scope): ?array {
    $q = mb_strtolower($query, 'UTF-8');

    // 1. 查询空余桌位
    $availWords = ['空', 'available', '有空', '还有', '剩余', '包间', '包厢', 'private', 'free', '空闲'];
    foreach ($availWords as $kw) {
        if (mb_strpos($q, $kw) !== false) {
            $type = '';
            if (mb_strpos($q, '包间') !== false || mb_strpos($q, '包厢') !== false || mb_strpos($q, 'private') !== false) {
                $type = 'private';
            } elseif (mb_strpos($q, '大') !== false && mb_strpos($q, '桌') === false) {
                $type = 'large';
            } elseif (mb_strpos($q, '中') !== false) {
                $type = 'medium';
            } elseif (mb_strpos($q, '小') !== false || mb_strpos($q, '标准') !== false) {
                $type = 'small';
            }
            return buildAvailabilityResult($db, $type);
        }
    }

    // 2. 手机号查询
    if (preg_match('/1[3-9]\d{9}/', $query, $m)) {
        return buildReservationResult($db, ['phone' => $m[0]]);
    }

    // 3. 桌号查询（Table 5 / 桌3 / 5号桌 / #7）
    if (preg_match('/(?:table|桌|号|#)\s*(\d+)/i', $query, $m)) {
        return buildReservationResult($db, ['table_number' => $m[1]]);
    }

    // 4. 中文姓名（2-4 个字）
    if (preg_match('/^[\x{4e00}-\x{9fa5}]{2,4}$/u', $query)) {
        $r = buildReservationResult($db, ['customer_name' => $query]);
        if ($r['count'] > 0) return $r;
        if ($scope === 'staff') {
            $e = buildEmployeeResult($db, $query);
            if ($e['count'] > 0) return $e;
        }
    }

    // 5. 模糊搜索（姓名、电话、桌号）
    $stmt = $db->prepare(
        "SELECT r.id, r.customer_name, r.phone, r.party_size, r.created_at,
                dt.table_number, dt.type AS table_type
         FROM reservations r JOIN dining_tables dt ON dt.id=r.table_id
         WHERE r.status='confirmed' AND (r.customer_name LIKE ? OR r.phone LIKE ? OR dt.table_number LIKE ?)
         ORDER BY r.created_at DESC LIMIT 20"
    );
    $like = "%{$query}%";
    $stmt->execute([$like, $like, $like]);
    $rows = $stmt->fetchAll();
    if (count($rows) > 0) {
        return ['success' => true, 'type' => 'reservations', 'results' => $rows, 'count' => count($rows)];
    }

    return null;
}

function buildAvailabilityResult(PDO $db, string $typeFilter): array {
    $sql = "SELECT dt.type, COUNT(*) AS total,
                   COUNT(*) - COUNT(r.id) AS available
            FROM dining_tables dt
            LEFT JOIN reservations r ON r.table_id = dt.id AND r.status = 'confirmed'";
    $binds = [];
    if ($typeFilter) {
        $sql .= " WHERE dt.type = ?";
        $binds[] = $typeFilter;
    }
    $sql .= " GROUP BY dt.type ORDER BY FIELD(dt.type,'small','medium','large','private')";
    $stmt = $db->prepare($sql);
    $stmt->execute($binds);
    $stats = $stmt->fetchAll();

    // 获取具体可用的桌位列表
    $availSql = "SELECT dt.id, dt.type, dt.table_number, dt.min_capacity, dt.max_capacity
                 FROM dining_tables dt
                 WHERE dt.id NOT IN (SELECT table_id FROM reservations WHERE status='confirmed')";
    $availBinds = [];
    if ($typeFilter) {
        $availSql .= " AND dt.type = ?";
        $availBinds[] = $typeFilter;
    }
    $availSql .= " ORDER BY dt.type, dt.table_number LIMIT 30";
    $s2 = $db->prepare($availSql);
    $s2->execute($availBinds);

    $summary = 'Tables availability';
    if ($typeFilter) {
        $typeNames = ['small'=>'Standard', 'medium'=>'Medium', 'large'=>'Large', 'private'=>'Private Room'];
        $summary = $typeNames[$typeFilter] . ' availability';
    }

    return [
        'success' => true,
        'type' => 'availability',
        'stats' => $stats,
        'available_tables' => $s2->fetchAll(),
        'count' => count($stats),
        'ai_summary' => $summary,
    ];
}

function buildReservationResult(PDO $db, array $filters): array {
    $sql = "SELECT r.id, r.customer_name, r.phone, r.party_size, r.notes, r.created_at,
                   dt.table_number, dt.type AS table_type
            FROM reservations r JOIN dining_tables dt ON dt.id=r.table_id
            WHERE r.status='confirmed'";
    $binds = [];

    if (!empty($filters['customer_name'])) {
        $sql .= " AND r.customer_name LIKE ?";
        $binds[] = '%' . $filters['customer_name'] . '%';
    }
    if (!empty($filters['phone'])) {
        $sql .= " AND r.phone LIKE ?";
        $binds[] = '%' . $filters['phone'] . '%';
    }
    if (!empty($filters['table_number'])) {
        $sql .= " AND dt.table_number LIKE ?";
        $binds[] = '%' . $filters['table_number'] . '%';
    }
    $sql .= " ORDER BY r.created_at DESC LIMIT 50";

    $stmt = $db->prepare($sql);
    $stmt->execute($binds);
    $rows = $stmt->fetchAll();
    $count = count($rows);
    $summary = $count > 0 ? "Found {$count} reservation(s)" : 'No reservations found';

    return ['success' => true, 'type' => 'reservations', 'results' => $rows, 'count' => $count, 'ai_summary' => $summary];
}

function buildEmployeeResult(PDO $db, string $search): array {
    $sql = "SELECT id, username, nickname, full_name, is_active, created_at
            FROM employees WHERE is_admin=0
            AND (username LIKE ? OR full_name LIKE ? OR nickname LIKE ?)
            ORDER BY created_at DESC LIMIT 20";
    $like = "%{$search}%";
    $stmt = $db->prepare($sql);
    $stmt->execute([$like, $like, $like]);
    $rows = $stmt->fetchAll();
    return ['success' => true, 'type' => 'employees', 'results' => $rows, 'count' => count($rows)];
}

function generalStatus(PDO $db, string $scope): array {
    $stats = $db->query(
        "SELECT dt.type, COUNT(*) AS total,
                SUM(CASE WHEN r.id IS NOT NULL THEN 1 ELSE 0 END) AS reserved
         FROM dining_tables dt
         LEFT JOIN reservations r ON r.table_id = dt.id AND r.status='confirmed'
         GROUP BY dt.type"
    )->fetchAll();
    $pending = $db->query("SELECT COUNT(*) FROM private_room_requests WHERE status='pending'")->fetchColumn();
    $total = $db->query("SELECT COUNT(*) FROM reservations WHERE status='confirmed'")->fetchColumn();
    return [
        'success' => true,
        'type' => 'general',
        'stats' => $stats,
        'pending_rooms' => (int)$pending,
        'total_reservations' => (int)$total,
        'scope' => $scope,
        'ai_summary' => "Found {$total} confirmed reservations",
    ];
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

function customerCancelReservation() {
    global $data;
    $id    = (int)($data['id']            ?? 0);
    $name  = trim($data['customer_name']  ?? '');
    $phone = trim($data['phone']          ?? '');
    if (!$id || !$name || !$phone) err('Missing required fields.');

    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT r.*, dt.table_number FROM reservations r
         JOIN dining_tables dt ON dt.id = r.table_id
         WHERE r.id=? AND r.status='confirmed'"
    );
    $stmt->execute([$id]);
    $res = $stmt->fetch();
    if (!$res) err('Reservation not found or already cancelled.');
    if ($res['customer_name'] !== $name || $res['phone'] !== $phone)
        err('Your credentials do not match this reservation.');

    $db->prepare("UPDATE reservations SET status='cancelled' WHERE id=?")->execute([$id]);

    broadcastNotification('customer_cancelled',
        "Table {$res['table_number']} · Guest {$name} cancelled their reservation.", $id);

    ok(['message' => 'Your reservation has been successfully cancelled.']);
}

function customerCancelPrivateRequest() {
    global $data;
    $id    = (int)($data['id']            ?? 0);
    $name  = trim($data['customer_name']  ?? '');
    $phone = trim($data['phone']          ?? '');
    if (!$id || !$name || !$phone) err('Missing required fields.');

    $db   = getDB();
    $stmt = $db->prepare("SELECT * FROM private_room_requests WHERE id=? AND status='pending'");
    $stmt->execute([$id]);
    $req = $stmt->fetch();
    if (!$req) err('Request not found or already processed.');
    if ($req['customer_name'] !== $name || $req['phone'] !== $phone)
        err('Your credentials do not match this request.');

    $db->prepare("UPDATE private_room_requests SET status='cancelled' WHERE id=?")->execute([$id]);

    broadcastNotification('customer_cancelled',
        "Private room request from {$name} (phone: {$phone}) was cancelled by the guest.", $id);

    ok(['message' => 'Your private room request has been successfully cancelled.']);
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

    // Login rate-limiting table
    $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(45) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'fail',
        attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ip_time (ip, attempted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 0. Ensure all required tables and columns exist
    $db->exec("CREATE TABLE IF NOT EXISTS customers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(100) NOT NULL,
        email VARCHAR(150) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        phone VARCHAR(20) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS reviews (
        id INT AUTO_INCREMENT PRIMARY KEY,
        restaurant_id VARCHAR(60) NOT NULL,
        customer_id INT DEFAULT NULL,
        reviewer_name VARCHAR(100) NOT NULL DEFAULT 'Guest',
        rating TINYINT NOT NULL,
        comment TEXT NOT NULL,
        visit_date DATE DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_rest (restaurant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS restaurants (
        id VARCHAR(60) PRIMARY KEY,
        name_zh VARCHAR(100) NOT NULL,
        name_en VARCHAR(100) NOT NULL,
        open_time VARCHAR(5) NOT NULL DEFAULT '11:00',
        close_time VARCHAR(5) NOT NULL DEFAULT '22:00'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $addCols = [
        "ALTER TABLE reservations ADD COLUMN customer_id INT NULL DEFAULT NULL",
        "ALTER TABLE reservations ADD COLUMN restaurant_id VARCHAR(60) NOT NULL DEFAULT 'cloud-pavilion'",
        "ALTER TABLE reservations ADD COLUMN visit_date DATE DEFAULT NULL",
        "ALTER TABLE reservations ADD COLUMN visit_time VARCHAR(5) DEFAULT NULL",
        "ALTER TABLE dining_tables ADD COLUMN restaurant_id VARCHAR(60) NOT NULL DEFAULT 'cloud-pavilion'",
    ];
    foreach ($addCols as $sql) {
        try { $db->exec($sql); } catch (Throwable $e) { /* already exists */ }
    }

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
