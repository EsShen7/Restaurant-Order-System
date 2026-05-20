<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Database Setup · CityEats</title>
<style>
body { font-family: sans-serif; max-width: 700px; margin: 40px auto; padding: 20px; }
.ok { color: #27ae60; } .err { color: #e74c3c; }
pre { background: #f8f8f8; padding: 10px; border-radius: 4px; }
a { color: #2980b9; }
</style>
</head>
<body>
<h1>CityEats · Database Setup</h1>
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

    $pdo->exec("CREATE TABLE IF NOT EXISTS dining_tables (
        id INT PRIMARY KEY AUTO_INCREMENT,
        type ENUM('small','medium','large','private') NOT NULL,
        table_number VARCHAR(20) NOT NULL UNIQUE,
        min_capacity INT NOT NULL,
        max_capacity INT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    step('Table: dining_tables');

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

    // users table (required by V2 foreign keys)
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        user_id INT PRIMARY KEY AUTO_INCREMENT,
        username VARCHAR(100) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        email VARCHAR(255),
        phone VARCHAR(20),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    step('Table: users');

    // V1 tables that may not exist (for menu/restaurant assignment)
    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
        category_id INT PRIMARY KEY AUTO_INCREMENT,
        name VARCHAR(100) NOT NULL,
        display_order INT DEFAULT 0,
        restaurant_id INT DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS menu_items (
        item_id INT PRIMARY KEY AUTO_INCREMENT,
        category_id INT DEFAULT NULL,
        name VARCHAR(200) NOT NULL,
        description TEXT,
        price DECIMAL(10,2) NOT NULL,
        image_url VARCHAR(500),
        is_available TINYINT(1) DEFAULT 1,
        restaurant_id INT DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    step('Tables: categories + menu_items');

    // ── V2: Multi-restaurant tables ──
    $pdo->exec("CREATE TABLE IF NOT EXISTS restaurants (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(200) NOT NULL,
        name_cn VARCHAR(200),
        description TEXT,
        address VARCHAR(500),
        district VARCHAR(100),
        latitude DECIMAL(10,8),
        longitude DECIMAL(11,8),
        phone VARCHAR(20),
        opening_hours VARCHAR(200),
        price_range VARCHAR(20) DEFAULT '$$',
        avg_rating DECIMAL(2,1) DEFAULT 0.0,
        review_count INT DEFAULT 0,
        image_url VARCHAR(500),
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    step('Table: restaurants');

    $pdo->exec("CREATE TABLE IF NOT EXISTS restaurant_cuisines (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(50) NOT NULL UNIQUE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS restaurant_cuisine_map (
        restaurant_id INT NOT NULL,
        cuisine_id INT NOT NULL,
        PRIMARY KEY (restaurant_id, cuisine_id),
        FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE,
        FOREIGN KEY (cuisine_id) REFERENCES restaurant_cuisines(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    step('Tables: restaurant_cuisines + map');

    $pdo->exec("CREATE TABLE IF NOT EXISTS reviews (
        id INT AUTO_INCREMENT PRIMARY KEY,
        restaurant_id INT NOT NULL,
        user_id INT DEFAULT NULL,
        customer_name VARCHAR(100),
        rating TINYINT NOT NULL,
        comment TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    step('Table: reviews');

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_preferences (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL UNIQUE,
        preferred_cuisines TEXT,
        price_min DECIMAL(8,2),
        price_max DECIMAL(8,2),
        preferred_districts TEXT,
        dietary_restrictions TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    step('Table: user_preferences');

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_favorites (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        restaurant_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY (user_id, restaurant_id),
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
        FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_recommendation_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT DEFAULT NULL,
        session_id VARCHAR(64),
        query_text TEXT,
        recommendations JSON,
        user_feedback VARCHAR(50),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    step('Tables: user_favorites + ai_recommendation_logs');

    // Add restaurant_id to existing tables
    try {
        $pdo->exec("ALTER TABLE categories ADD COLUMN restaurant_id INT DEFAULT NULL AFTER category_id,
            ADD FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE");
    } catch (PDOException $e) {
        $msg = $e->getMessage();
	    if (strpos($msg, 'Duplicate column') === false && strpos($msg, "doesn't exist") === false && strpos($msg, 'Base table') === false) throw $e;
    }
    try {
        $pdo->exec("ALTER TABLE menu_items ADD COLUMN restaurant_id INT DEFAULT NULL AFTER item_id,
            ADD FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE");
    } catch (PDOException $e) {
        $msg = $e->getMessage();
	    if (strpos($msg, 'Duplicate column') === false && strpos($msg, "doesn't exist") === false && strpos($msg, 'Base table') === false) throw $e;
    }
    step('Altered existing tables for multi-restaurant');

    // Seed cuisine tags
    $cnt = $pdo->query("SELECT COUNT(*) FROM restaurant_cuisines")->fetchColumn();
    if ($cnt == 0) {
        $cuisines = ['Sichuan','Cantonese','Hunan','Shandong','Jiangsu','Japanese','Korean','Western','SE Asian','Hotpot','BBQ','Snacks','Dessert','Coffee','Tea','Fusion','Seafood','Vegetarian'];
        $stmt = $pdo->prepare("INSERT IGNORE INTO restaurant_cuisines (name) VALUES (?)");
        foreach ($cuisines as $c) $stmt->execute([$c]);
        step('Cuisine tags seeded.');
    }

    // Seed restaurants
    $cnt = $pdo->query("SELECT COUNT(*) FROM restaurants")->fetchColumn();
    if ($cnt == 0) {
        $pdo->exec("INSERT INTO restaurants (id,name,name_cn,description,address,district,phone,opening_hours,price_range,avg_rating,review_count) VALUES
(1,'Chuan Flavor House',NULL,'Three generations of authentic Sichuan cuisine. Award-winning chef, hand-made spices, fresh ingredients daily. Signature boiled fish and mapo tofu.','88 East Street, Jinjiang','Jinjiang','028-86521111','11:00-22:00','$$',4.6,328),
(2,'Yue Gang Xuan',NULL,'Authentic Cantonese tea house with 40+ handmade dim sum varieties. Weekend morning tea requires one-week advance booking.','66 Kehua North Road, Wuhou','Wuhou','028-85432222','09:00-21:30','$$$',4.7,512),
(3,'Sakura Japanese',NULL,'Premium Japanese cuisine with daily air-freighted ingredients from Tokyo Toyosu Market. 15-year experienced Japanese head chef. Omakase requires 3-day advance booking.','999 Tianfu Avenue, High-tech','High-tech','028-85993333','11:30-22:00','$$$$',4.8,256),
(4,'Romance Italia',NULL,'Romantic vintage Italian restaurant with handmade pasta and wood-fired pizza. Live piano on weekends. Perfect for dates and anniversaries.','12 Kuanzhai Alley, Qingyang','Qingyang','028-86264444','11:00-23:00','$$$',4.5,189),
(5,'Haidilao Hotpot',NULL,'Nationally renowned hotpot chain famous for legendary service. Free manicures, shoe shines, face-changing performances. Open 24 hours. Tomato broth is the signature.','1 Hongxing Road, Jinjiang','Jinjiang','028-86705555','24 hours','$$',4.4,2018),
(6,'Northwest Home',NULL,'Hearty flavors from China''s northwest. Authentic Lanzhou beef noodles, hand-grabbed lamb, big plate chicken. Noodles pulled fresh daily.','88 1st Ring North Road, Jinniu','Jinniu','028-83186666','10:00-21:00','\$',4.3,156),
(7,'Thai Dragon',NULL,'One of the city''s first Thai restaurants, operating for 15 years. Tom Yum soup, curry crab, and mango sticky rice are signatures. Spices directly sourced from Thailand.','36 Sizhu Road, Wuhou','Wuhou','028-85437777','11:00-21:30','$$',4.5,234),
(8,'Morning Tea Night Wine',NULL,'A refined tea house by day, a chic lounge by night. 50+ premium Chinese teas and creative cocktails. Elegant ambiance for gatherings with friends.','56 Tiexiangsi Water Street, High-tech','High-tech','028-85108888','10:00-02:00','$$',4.6,167),
(9,'Chaoshan Beef Hotpot',NULL,'Authentic Chaoshan beef hotpot with fresh beef delivered twice daily. Premium cuts sliced to order. Hand-pounded beef balls are a specialty.','65 Liangui South Road, Jinjiang','Jinjiang','028-84559999','11:00-23:00','$$',4.7,423),
(10,'Taipei Bites',NULL,'Taiwanese snack collection: oyster omelet, braised pork rice, beef noodles, bubble tea and more. The owner is from Taipei — all recipes and spices are authentic.','15 Jianshe Lane, Chenghua','Chenghua','028-83260000','11:00-21:00','\$',4.2,98)");
        // Map cuisines
        $maps = [
            [1,'Sichuan'],[1,'Hotpot'],[2,'Cantonese'],[2,'Snacks'],[3,'Japanese'],[3,'Seafood'],
            [4,'Western'],[5,'Hotpot'],[6,'BBQ'],[6,'Snacks'],[7,'SE Asian'],[8,'Coffee'],[8,'Tea'],
            [9,'Hotpot'],[9,'Seafood'],[10,'Snacks'],[10,'Dessert'],
        ];
        $insC = $pdo->prepare("INSERT INTO restaurant_cuisine_map (restaurant_id, cuisine_id) SELECT ?, id FROM restaurant_cuisines WHERE name=?");
        foreach ($maps as $m) $insC->execute([$m[0], $m[1]]);
        // Sample reviews
        $reviews = [
            [1,'Mr. Zhang',5,'The boiled fish is incredibly authentic! Numbing and spicy, I order it every time.'],
            [1,'Ms. Li',4,'Great flavor, but the wait can be long. Recommend booking ahead.'],
            [2,'Mr. Wang',5,'Thin-skinned shrimp dumplings with generous filling — the most authentic Cantonese dim sum in Chengdu.'],
            [2,'Ms. Chen',5,'Our go-to for family gatherings. The elders absolutely love it.'],
            [3,'Mr. Zhao',5,'Every Omakase course is a work of art. The sea urchin is so fresh it is sweet.'],
            [3,'Ms. Liu',5,'The best Japanese restaurant in Chengdu, bar none. Chose it for my birthday dinner — thoroughly satisfied.'],
            [4,'Ms. Zhou',5,'The ambiance is stunning, like being on vacation in Rome. Perfect for dates!'],
            [4,'Mr. Wu',4,'The steak was cooked to perfection. Tiramisu made the authentic Italian way.'],
            [5,'Mr. Zheng',5,'Hotpot still piping hot at 2am! The service is always on point.'],
            [5,'Ms. Huang',4,'The tomato broth is incredible! But the queue is insane — go on weekdays.'],
            [6,'Mr. Ma',5,'The Lanzhou beef noodle soup is spot-on, noodles are perfectly chewy. Lamb skewers are a must.'],
            [7,'Ms. Lin',5,'Tom Yum soup tastes exactly like it does in Thailand! The curry crab sauce over rice is incredible.'],
            [8,'Mr. Yang',4,'Very quiet atmosphere, great for meetings. The Tieguanyin tea is excellent quality.'],
            [8,'Ms. Xu',5,'The evening cocktails are very creative. The tea-liquor fusion concept is brilliant.'],
            [9,'Mr. Sun',5,'The beef is so fresh! The fat-marbled brisket melts in your mouth — the best beef hotpot I have ever had.'],
            [9,'Ms. Qian',5,'The beef balls are wonderfully bouncy and chewy. The satay sauce is authentic too.'],
            [10,'Ms. Gao',4,'The braised pork rice is rich without being greasy. Bubble tea pearls are perfectly chewy. Great value.'],
        ];
        $insR = $pdo->prepare("INSERT INTO reviews (restaurant_id,customer_name,rating,comment) VALUES (?,?,?,?)");
        foreach ($reviews as $r) $insR->execute([$r[0], $r[1], $r[2], $r[3]]);
        // Update ratings
        $pdo->exec("UPDATE restaurants r SET avg_rating=ROUND((SELECT AVG(rating) FROM reviews WHERE restaurant_id=r.id),1), review_count=(SELECT COUNT(*) FROM reviews WHERE restaurant_id=r.id)");
        // Assign existing categories + menu to restaurant 1
        $pdo->exec("UPDATE categories SET restaurant_id=1 WHERE restaurant_id IS NULL");
        $pdo->exec("UPDATE menu_items SET restaurant_id=1 WHERE restaurant_id IS NULL");
        step('Restaurant seed data: 10 restaurants, 17 reviews.');
    } else {
        step("Restaurant data already exists ({$cnt} rows) — skipped.");
    }

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
        for ($i = 1; $i <= 25; $i++)
            $pdo->prepare("INSERT INTO dining_tables (type,table_number,min_capacity,max_capacity) VALUES ('small',?,1,2)")
                ->execute([sprintf('S%02d', $i)]);
        for ($i = 1; $i <= 40; $i++)
            $pdo->prepare("INSERT INTO dining_tables (type,table_number,min_capacity,max_capacity) VALUES ('medium',?,3,4)")
                ->execute([sprintf('M%02d', $i)]);
        for ($i = 1; $i <= 15; $i++)
            $pdo->prepare("INSERT INTO dining_tables (type,table_number,min_capacity,max_capacity) VALUES ('large',?,5,8)")
                ->execute([sprintf('L%02d', $i)]);
        $rooms = [
            ['P01',6,8],['P02',6,8],['P03',6,8],['P04',6,8],['P05',6,8],['P06',6,8],
            ['P07',8,10],['P08',8,10],['P09',8,10],['P10',8,10],
            ['P11',10,12],['P12',10,12],['P13',10,12],['P14',10,12],
            ['P15',12,15],['P16',12,15],['P17',15,18],['P18',18,20],
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
  <a href="index.html">→ CityEats Discovery</a> &nbsp;&nbsp;
  <a href="employee.html">→ Staff Portal</a>
</p>
<p style="color:#888;font-size:12px;">Once setup is complete, it is recommended to delete or rename this file to prevent accidental re-execution.</p>
</body>
</html>
