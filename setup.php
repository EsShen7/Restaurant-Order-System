<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Database Setup · Cloud Pavilion</title>
<style>
body { font-family: sans-serif; max-width: 700px; margin: 40px auto; padding: 20px; }
.ok { color: #27ae60; } .err { color: #e74c3c; }
pre { background: #f8f8f8; padding: 10px; border-radius: 4px; }
a { color: #2980b9; }
</style>
</head>
<body>
<h1>Cloud Pavilion · Database Setup</h1>
<?php
$host   = 'localhost';
$dbuser = 'root';
$dbpass = '';
$dbname = 'restaurant_db';

$steps = [];

function step($msg, $ok = true) {
    global $steps;
    $steps[] = ['msg' => $msg, 'ok' => $ok];
}

try {
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $dbuser, $dbpass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$dbname`");
    step('Database created / verified.');

    // employees
    $pdo->exec("CREATE TABLE IF NOT EXISTS employees (
        id INT PRIMARY KEY AUTO_INCREMENT,
        username VARCHAR(100) NOT NULL UNIQUE,
        nickname VARCHAR(100) DEFAULT NULL,
        nickname_last_changed TIMESTAMP NULL DEFAULT NULL,
        password_hash VARCHAR(255) NOT NULL,
        old_password_hash VARCHAR(255) DEFAULT NULL,
        old_password_expires TIMESTAMP NULL DEFAULT NULL,
        old_password_reminder_cancelled TINYINT(1) DEFAULT 0,
        full_name VARCHAR(100) NOT NULL,
        is_admin TINYINT(1) DEFAULT 0,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    step('Table: employees');

    // username_aliases (old account aliases)
    $pdo->exec("CREATE TABLE IF NOT EXISTS username_aliases (
        id INT PRIMARY KEY AUTO_INCREMENT,
        employee_id INT NOT NULL,
        alias_username VARCHAR(100) NOT NULL UNIQUE,
        transition_id INT DEFAULT NULL,
        expiry_date TIMESTAMP NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    step('Table: username_aliases');

    // account_transitions
    $pdo->exec("CREATE TABLE IF NOT EXISTS account_transitions (
        id INT PRIMARY KEY AUTO_INCREMENT,
        employee_id INT NOT NULL,
        old_username VARCHAR(100) NOT NULL,
        new_username VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        expiry_date TIMESTAMP NULL DEFAULT NULL,
        new_account_used TINYINT(1) DEFAULT 0,
        reminders_cancelled TINYINT(1) DEFAULT 0,
        reminders_sent VARCHAR(20) DEFAULT '',
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    step('Table: account_transitions');

    // dining_tables
    $pdo->exec("CREATE TABLE IF NOT EXISTS dining_tables (
        id INT PRIMARY KEY AUTO_INCREMENT,
        type ENUM('small','medium','large','private') NOT NULL,
        table_number VARCHAR(20) NOT NULL UNIQUE,
        min_capacity INT NOT NULL,
        max_capacity INT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    step('Table: dining_tables');

    // reservations
    $pdo->exec("CREATE TABLE IF NOT EXISTS reservations (
        id INT PRIMARY KEY AUTO_INCREMENT,
        table_id INT NOT NULL,
        customer_name VARCHAR(100) NOT NULL,
        phone VARCHAR(20) NOT NULL,
        party_size INT DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        status ENUM('confirmed','cancelled') DEFAULT 'confirmed',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_by INT DEFAULT NULL,
        FOREIGN KEY (table_id) REFERENCES dining_tables(id),
        FOREIGN KEY (created_by) REFERENCES employees(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    step('Table: reservations');

    // private_room_requests
    $pdo->exec("CREATE TABLE IF NOT EXISTS private_room_requests (
        id INT PRIMARY KEY AUTO_INCREMENT,
        customer_name VARCHAR(100) NOT NULL,
        phone VARCHAR(20) NOT NULL,
        party_size INT DEFAULT NULL,
        assigned_table_id INT DEFAULT NULL,
        status ENUM('pending','confirmed','rejected') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        processed_by INT DEFAULT NULL,
        processed_at TIMESTAMP NULL DEFAULT NULL,
        FOREIGN KEY (assigned_table_id) REFERENCES dining_tables(id) ON DELETE SET NULL,
        FOREIGN KEY (processed_by) REFERENCES employees(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    step('Table: private_room_requests');

    // pre_orders
    $pdo->exec("CREATE TABLE IF NOT EXISTS pre_orders (
        id INT PRIMARY KEY AUTO_INCREMENT,
        reservation_id INT NOT NULL,
        item_name VARCHAR(100) NOT NULL,
        quantity INT NOT NULL DEFAULT 1,
        notes TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    step('Table: pre_orders');

    // notifications
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT PRIMARY KEY AUTO_INCREMENT,
        employee_id INT NOT NULL,
        type VARCHAR(50) NOT NULL,
        message TEXT NOT NULL,
        is_read TINYINT(1) DEFAULT 0,
        related_id INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    step('Table: notifications');

    // Admin account
    $stmt = $pdo->prepare("SELECT id FROM employees WHERE username = 'Admin'");
    $stmt->execute();
    if (!$stmt->fetch()) {
        $hash = password_hash('123456', PASSWORD_BCRYPT);
        $pdo->prepare("INSERT INTO employees (username, password_hash, full_name, is_admin) VALUES ('Admin', ?, 'Administrator', 1)")
            ->execute([$hash]);
        step('Admin account created (username: Admin, password: 123456).');
    } else {
        step('Admin account already exists — skipped.');
    }

    // Dining tables
    $cnt = $pdo->query("SELECT COUNT(*) FROM dining_tables")->fetchColumn();
    if ($cnt == 0) {
        // Small (25)
        for ($i = 1; $i <= 25; $i++)
            $pdo->prepare("INSERT INTO dining_tables (type,table_number,min_capacity,max_capacity) VALUES ('small',?,1,2)")
                ->execute([sprintf('S%02d', $i)]);
        // Medium (40)
        for ($i = 1; $i <= 40; $i++)
            $pdo->prepare("INSERT INTO dining_tables (type,table_number,min_capacity,max_capacity) VALUES ('medium',?,3,4)")
                ->execute([sprintf('M%02d', $i)]);
        // Large (15)
        for ($i = 1; $i <= 15; $i++)
            $pdo->prepare("INSERT INTO dining_tables (type,table_number,min_capacity,max_capacity) VALUES ('large',?,5,8)")
                ->execute([sprintf('L%02d', $i)]);
        // Private rooms
        $rooms = [
            ['P01',6,8],['P02',6,8],['P03',6,8],['P04',6,8],['P05',6,8],['P06',6,8],
            ['P07',8,10],['P08',8,10],['P09',8,10],['P10',8,10],
            ['P11',10,12],['P12',10,12],['P13',10,12],['P14',10,12],
            ['P15',12,15],['P16',12,15],
            ['P17',15,18],
            ['P18',18,20],
        ];
        foreach ($rooms as $r)
            $pdo->prepare("INSERT INTO dining_tables (type,table_number,min_capacity,max_capacity) VALUES ('private',?,?,?)")
                ->execute($r);
        step('Table data seeded: 25 small, 40 medium, 15 large, 18 private rooms.');
    } else {
        step("Table data already exists ($cnt rows) — skipped.");
    }

} catch (PDOException $e) {
    step('Database error: ' . $e->getMessage(), false);
}

foreach ($steps as $s) {
    echo '<p class="' . ($s['ok'] ? 'ok' : 'err') . '">• ' . htmlspecialchars($s['msg']) . '</p>';
}
?>
<hr>
<h2>Setup Complete</h2>
<p><strong>Admin username:</strong> Admin &nbsp;&nbsp; <strong>Password:</strong> 123456</p>
<p>
  <a href="index.html">→ Customer Reservation Page</a> &nbsp;&nbsp;
  <a href="employee.html">→ Staff Portal</a>
</p>
<p style="color:#888;font-size:12px;">Once setup is complete, it is recommended to delete or rename this file to prevent accidental re-execution.</p>
</body>
</html>
