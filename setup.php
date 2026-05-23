<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Database Setup · 智膳</title>
<style>
body { font-family: sans-serif; max-width: 800px; margin: 40px auto; padding: 20px; }
.ok  { color: #27ae60; } .err { color: #e74c3c; }
h2 { margin-top: 24px; }
</style>
</head>
<body>
<h1>智膳 · 数据库初始化</h1>
<?php
$host = 'localhost'; $dbuser = 'root'; $dbpass = ''; $dbname = 'restaurant_db';

function step($msg, $ok = true) {
    echo '<p class="' . ($ok ? 'ok' : 'err') . '">• ' . htmlspecialchars($msg) . '</p>';
    flush();
}

try {
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $dbuser, $dbpass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("SET sql_mode = ''");
    $pdo->exec("SET NAMES utf8mb4");
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$dbname`");
    step('Database ready.');

    /* ── employees ── */
    $pdo->exec("CREATE TABLE IF NOT EXISTS employees (
        id INT PRIMARY KEY AUTO_INCREMENT,
        username VARCHAR(100) NOT NULL UNIQUE,
        nickname VARCHAR(100) DEFAULT NULL,
        nickname_last_changed DATETIME NULL DEFAULT NULL,
        password_hash VARCHAR(255) NOT NULL,
        old_password_hash VARCHAR(255) DEFAULT NULL,
        old_password_expires DATETIME NULL DEFAULT NULL,
        old_password_reminder_cancelled TINYINT(1) DEFAULT 0,
        full_name VARCHAR(100) NOT NULL,
        is_admin TINYINT(1) DEFAULT 0,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    step('Table: employees');

    /* ── username_aliases ── */
    $pdo->exec("CREATE TABLE IF NOT EXISTS username_aliases (
        id INT PRIMARY KEY AUTO_INCREMENT,
        employee_id INT NOT NULL,
        alias_username VARCHAR(100) NOT NULL UNIQUE,
        transition_id INT DEFAULT NULL,
        expiry_date DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    step('Table: username_aliases');

    /* ── account_transitions ── */
    $pdo->exec("CREATE TABLE IF NOT EXISTS account_transitions (
        id INT PRIMARY KEY AUTO_INCREMENT,
        employee_id INT NOT NULL,
        old_username VARCHAR(100) NOT NULL,
        new_username VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        expiry_date DATETIME NOT NULL,
        new_account_used TINYINT(1) DEFAULT 0,
        reminders_cancelled TINYINT(1) DEFAULT 0,
        reminders_sent VARCHAR(20) DEFAULT '',
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    step('Table: account_transitions');

    /* ── restaurants ── */
    $pdo->exec("CREATE TABLE IF NOT EXISTS restaurants (
        id VARCHAR(60) PRIMARY KEY,
        name_zh VARCHAR(100) NOT NULL,
        name_en VARCHAR(100) NOT NULL,
        open_time VARCHAR(5) NOT NULL DEFAULT '11:00',
        close_time VARCHAR(5) NOT NULL DEFAULT '22:00'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    step('Table: restaurants');

    /* ── dining_tables ── */
    $pdo->exec("CREATE TABLE IF NOT EXISTS dining_tables (
        id INT PRIMARY KEY AUTO_INCREMENT,
        restaurant_id VARCHAR(60) NOT NULL DEFAULT 'cloud-pavilion',
        type ENUM('small','medium','large','private') NOT NULL,
        table_number VARCHAR(30) NOT NULL,
        min_capacity INT NOT NULL,
        max_capacity INT NOT NULL,
        UNIQUE KEY uk_rt (restaurant_id, table_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    step('Table: dining_tables');

    /* ── reservations ── */
    $pdo->exec("CREATE TABLE IF NOT EXISTS reservations (
        id INT PRIMARY KEY AUTO_INCREMENT,
        restaurant_id VARCHAR(60) NOT NULL DEFAULT 'cloud-pavilion',
        table_id INT NOT NULL,
        customer_name VARCHAR(100) NOT NULL,
        phone VARCHAR(20) NOT NULL,
        party_size INT DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        visit_date DATE DEFAULT NULL,
        visit_time VARCHAR(5) DEFAULT NULL,
        status ENUM('confirmed','cancelled') DEFAULT 'confirmed',
        customer_id INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_by INT DEFAULT NULL,
        FOREIGN KEY (table_id) REFERENCES dining_tables(id),
        FOREIGN KEY (created_by) REFERENCES employees(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    step('Table: reservations');

    /* ── private_room_requests ── */
    $pdo->exec("CREATE TABLE IF NOT EXISTS private_room_requests (
        id INT PRIMARY KEY AUTO_INCREMENT,
        customer_name VARCHAR(100) NOT NULL,
        phone VARCHAR(20) NOT NULL,
        party_size INT DEFAULT NULL,
        assigned_table_id INT DEFAULT NULL,
        status ENUM('pending','confirmed','rejected','cancelled') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        processed_by INT DEFAULT NULL,
        processed_at DATETIME NULL DEFAULT NULL,
        FOREIGN KEY (assigned_table_id) REFERENCES dining_tables(id) ON DELETE SET NULL,
        FOREIGN KEY (processed_by) REFERENCES employees(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    step('Table: private_room_requests');

    /* ── pre_orders ── */
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

    /* ── notifications ── */
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

    /* ── customers ── */
    $pdo->exec("CREATE TABLE IF NOT EXISTS customers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(100) NOT NULL,
        email VARCHAR(150) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        phone VARCHAR(20) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    step('Table: customers');

    /* ── Admin account ── */
    $stmt = $pdo->prepare("SELECT id FROM employees WHERE username = 'Admin'");
    $stmt->execute();
    if (!$stmt->fetch()) {
        $hash = password_hash('123456', PASSWORD_BCRYPT);
        $pdo->prepare("INSERT INTO employees (username,password_hash,full_name,is_admin) VALUES ('Admin',?,?,1)")
            ->execute([$hash, 'Administrator']);
        step('Admin account: username=Admin, password=123456');
    } else {
        step('Admin account already exists — skipped.');
    }

    /* ── Restaurants seed ── */
    $restaurants = [
        ['cloud-pavilion', '云轩',       'Cloud Pavilion',   '11:00','22:00'],
        ['tokyo-shokunin', '东京匠心',    'Tokyo Shokunin',   '11:30','22:30'],
        ['bella-roma',     '贝拉罗马',    'Bella Roma',       '12:00','23:00'],
        ['sichuan-dao',    '川辣道',      'Chuan La Dao',     '10:30','23:30'],
        ['prime-house',    '极品牛排馆',  'The Prime House',  '17:00','23:00'],
        ['hong-tu-seafood','鸿图海鲜楼',  'Hong Tu Seafood',  '10:00','22:00'],
        ['lao-zhang-hotpot','老张涮肉',   'Lao Zhang Hot Pot','11:00','23:00'],
        ['seoul-garden',   '首尔花园',    'Seoul Garden',     '11:00','23:00'],
        ['chongqing-hotpot','重庆火锅王', 'Chongqing Hot Pot','10:00','23:00'],
        ['din-tai-fung',   '鼎泰丰',      'Din Tai Fung',     '11:00','21:30'],
        ['le-bistro',      'Le Bistro',   'Le Petit Bistro',  '11:30','22:00'],
        ['spice-india',    '香料印度',    'Spice India',      '11:00','22:00'],
        ['vietnam-pho',    '河内越饭',    'Hanoi Kitchen',    '10:00','21:00'],
        ['barbeque-brazil','巴西火焰烤肉','Fogo Brasil',      '11:30','22:30'],
        ['dim-sum-palace', '点心宫',      'Dim Sum Palace',   '07:00','15:00'],
    ];

    $restCnt = $pdo->query("SELECT COUNT(*) FROM restaurants")->fetchColumn();
    if ($restCnt == 0) {
        $rs = $pdo->prepare("INSERT INTO restaurants (id,name_zh,name_en,open_time,close_time) VALUES (?,?,?,?,?)");
        foreach ($restaurants as $r) $rs->execute($r);
        step('Seeded ' . count($restaurants) . ' restaurants.');
    } else {
        step("Restaurants already seeded ($restCnt rows) — skipped.");
    }

    /* ── Dining tables seed ── */
    // [restaurant_id, small, medium, large, private]
    $tableCfg = [
        ['cloud-pavilion',  25, 40, 15, 18],
        ['tokyo-shokunin',  20, 12,  6,  4],
        ['bella-roma',      18, 10,  6,  4],
        ['sichuan-dao',     30, 20, 10,  4],
        ['prime-house',     16,  8,  6,  6],
        ['hong-tu-seafood', 20, 14,  8,  4],
        ['lao-zhang-hotpot',28, 18, 10,  4],
        ['seoul-garden',    24, 16,  8,  4],
        ['chongqing-hotpot',32, 20, 10,  4],
        ['din-tai-fung',    22, 14,  6,  4],
        ['le-bistro',       14,  8,  4,  4],
        ['spice-india',     16, 10,  4,  2],
        ['vietnam-pho',     18, 10,  4,  2],
        ['barbeque-brazil', 20, 12,  6,  4],
        ['dim-sum-palace',  20, 14,  6,  2],
    ];

    $tblCnt = $pdo->query("SELECT COUNT(*) FROM dining_tables")->fetchColumn();
    if ($tblCnt == 0) {
        $ins = $pdo->prepare("INSERT INTO dining_tables (restaurant_id,type,table_number,min_capacity,max_capacity) VALUES (?,?,?,?,?)");
        foreach ($tableCfg as [$rid, $s, $m, $l, $p]) {
            // prefix: first letters of restaurant id parts
            $parts = explode('-', $rid);
            $pfx   = strtoupper(implode('', array_map(fn($w) => $w[0], $parts)));
            for ($i = 1; $i <= $s; $i++) $ins->execute([$rid,'small',  "{$pfx}-S".sprintf('%02d',$i),1,2]);
            for ($i = 1; $i <= $m; $i++) $ins->execute([$rid,'medium', "{$pfx}-M".sprintf('%02d',$i),3,4]);
            for ($i = 1; $i <= $l; $i++) $ins->execute([$rid,'large',  "{$pfx}-L".sprintf('%02d',$i),5,8]);
            for ($i = 1; $i <= $p; $i++) $ins->execute([$rid,'private',"{$pfx}-P".sprintf('%02d',$i),6,20]);
        }
        $total = $pdo->query("SELECT COUNT(*) FROM dining_tables")->fetchColumn();
        step("Seeded $total dining tables across all restaurants.");
    } else {
        step("Dining tables already exist ($tblCnt rows) — skipped.");
    }

    /* ── reviews ── */
    $pdo->exec("CREATE TABLE IF NOT EXISTS reviews (
        id INT AUTO_INCREMENT PRIMARY KEY,
        restaurant_id VARCHAR(60) NOT NULL,
        customer_id INT DEFAULT NULL,
        reviewer_name VARCHAR(100) NOT NULL DEFAULT 'Guest',
        rating TINYINT NOT NULL,
        comment TEXT NOT NULL,
        visit_date DATE DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_rest (restaurant_id),
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    step('Table: reviews');

    /* ── seed reviews ── */
    $rvCnt = $pdo->query("SELECT COUNT(*) FROM reviews")->fetchColumn();
    if ($rvCnt == 0) {
        $rv = $pdo->prepare("INSERT INTO reviews (restaurant_id,reviewer_name,rating,comment,visit_date) VALUES (?,?,?,?,?)");
        $reviews = [
            // Cloud Pavilion
            ['cloud-pavilion','James W.',5,'The Buddha Jumps Over the Wall left me speechless. Eighteen premium ingredients, 48 hours simmering — the result is transcendent. A bucket-list dining experience.','2026-05-01'],
            ['cloud-pavilion','Sophia T.',5,'Flawless Cantonese cuisine. The osmanthus duck skin shattered on the first bite, and the flesh was impossibly juicy. Service was white-glove throughout.','2026-04-22'],
            ['cloud-pavilion','Daniel K.',4,'Booked the private room for a milestone anniversary. The crystal prawn dumplings were the highlight — genuinely the best I\'ve had. Expensive but worth every yuan.','2026-04-14'],
            ['cloud-pavilion','Rachel M.',5,'Exquisite attention to detail in every dish. The steamed perch arrived glowing with fresh oil. A masterclass in Cantonese technique.','2026-03-30'],
            ['cloud-pavilion','Kevin L.',4,'Excellent food and ambiance. The wagyu short rib melted in my mouth. My only gripe is that reservations are hard to get — plan weeks ahead.','2026-03-18'],
            ['cloud-pavilion','Claire H.',5,'The lobster was marinated to perfection — golden and crispy outside, meltingly sweet inside. Staff remembered our dietary preferences without being asked.','2026-03-05'],
            // Tokyo Shokunin
            ['tokyo-shokunin','Emma R.',5,'I lived in Fukuoka for two years and this tonkotsu rivals the best I had there. The broth is deeply soulful, the chashu melts beautifully. Must try.','2026-05-10'],
            ['tokyo-shokunin','Alex B.',5,'Otoro sashimi so fresh it dissolved on my tongue. The chef\'s omakase was a revelation — every course extraordinary. Best Japanese in Shanghai.','2026-04-28'],
            ['tokyo-shokunin','Megan W.',4,'Superb wagyu sushi platter, beautifully presented. The sake pairing suggestions from staff were excellent. Would love slightly larger portions.','2026-04-16'],
            ['tokyo-shokunin','Chris N.',5,'Went for a birthday dinner — the staff prepared a lovely surprise. The matcha kakigori was the perfect dessert to close an exceptional meal.','2026-04-02'],
            ['tokyo-shokunin','Natalie F.',3,'Quality is undeniably high but waits can be 40 minutes on weekends. Book ahead! The gyoza alone are worth the trip though.','2026-03-20'],
            // Bella Roma
            ['bella-roma','Laura C.',5,'Truffle margherita from a 450°C wood-fired oven — the crust had the perfect char and chew. This is what pizza should be. Bellissimo!','2026-05-08'],
            ['bella-roma','Thomas E.',5,'Genuine Roman carbonara — guanciale, egg, Pecorino. Not a drop of cream in sight. Finally found the real deal in Shanghai.','2026-04-25'],
            ['bella-roma','Isabella K.',4,'Charming setting, excellent wine list. The burrata was creamy and fresh. Romantic atmosphere perfect for date nights.','2026-04-12'],
            ['bella-roma','Marcus H.',5,'The tableside Crêpe Suzette was pure theatre — the whole room turned to watch. Delicious and utterly memorable.','2026-03-28'],
            ['bella-roma','Grace P.',4,'Solid Italian cooking with quality ingredients. The seafood linguine was fresh and flavoursome. Service can feel rushed during peak hours.','2026-03-15'],
            // Sichuan Dao
            ['sichuan-dao','Ryan Z.',5,'The mapo tofu here is on another level — numbing Sichuan pepper perfectly balanced with chilli heat. Absolutely addictive.','2026-05-12'],
            ['sichuan-dao','Amy S.',5,'Authentic Chengdu flavors. The dan dan noodles were spot-on — nutty, spicy, with a subtle sourness. I ordered a second bowl.','2026-04-30'],
            ['sichuan-dao','Jason L.',5,'Mao Xuewang blew my mind. The tripe and duck blood cooked impeccably in that fiery red broth. Bring tissues — and come hungry.','2026-04-18'],
            ['sichuan-dao','Priya K.',4,'Brilliant Sichuan cuisine at a very reasonable price. The kung pao chicken was perfectly balanced. Downside: it does get smoky inside.','2026-04-05'],
            ['sichuan-dao','Ben T.',5,'Went four Saturdays in a row — that should say it all. The twice-cooked pork with garlic shoots is my new comfort food.','2026-03-22'],
            // Prime House
            ['prime-house','Victoria L.',5,'The A5 Wagyu ribeye was the most extraordinary beef I\'ve eaten in my life. Marble score 12 texture is something else entirely. Perfection.','2026-05-05'],
            ['prime-house','Andrew M.',5,'The tomahawk was carved tableside with theatre — smoky salt, chimichurri, Bund views at night. A completely unforgettable evening.','2026-04-20'],
            ['prime-house','Caroline B.',4,'World-class beef, exceptional wine list (400+ labels). The foie gras terrine to start was brilliant. Worth every yuan for a special occasion.','2026-04-08'],
            ['prime-house','George H.',5,'Took a client here and secured the deal. Private dining room is exceptional, staff knew when to be present and when to step back.','2026-03-25'],
            ['prime-house','Nadia W.',4,'The lobster thermidor was perfectly executed — classic French technique, generous portion. Dress code is enforced; worth dressing up for.','2026-03-12'],
            // Hong Tu Seafood
            ['hong-tu-seafood','Helen C.',5,'The snow crab arrived impossibly fresh, steam rising. Sweet, bouncy flesh dipped in aged vinegar and ginger — pure pleasure.','2026-05-14'],
            ['hong-tu-seafood','Patrick W.',5,'Salt and pepper mantis shrimp — crispy shell, juicy interior, that wok hei fragrance. Ordered three portions between us. Spectacular.','2026-05-03'],
            ['hong-tu-seafood','Lily T.',5,'XO clams arrived sizzling at the table. The garlic scallops were the best we\'ve had. Loud and lively atmosphere, which we loved.','2026-04-22'],
            ['hong-tu-seafood','Nathan G.',4,'Quality of the seafood is really high — live tanks, clearly very fresh. The braised sea cucumber was a luxurious treat.','2026-04-10'],
            ['hong-tu-seafood','Sophia A.',5,'The fish head casserole was incredibly comforting — rich, silky broth with perfectly cooked tofu. A Cantonese seafood institution.','2026-03-30'],
            // Lao Zhang Hot Pot
            ['lao-zhang-hotpot','Michael J.',5,'Hand-cut Inner Mongolia lamb — I\'ve had countless hot pots but this was different. The quality of the meat and old broth make it exceptional.','2026-05-11'],
            ['lao-zhang-hotpot','Jessica R.',5,'The sesame sauce is the best I\'ve tried anywhere. DIY mixing with fermented tofu and garlic chives — get the proportions right, it\'s a revelation.','2026-04-28'],
            ['lao-zhang-hotpot','Tom H.',4,'Classic Beijing copper-pot hot pot, unpretentious and authentic. The hand-rolled noodles at the end soaked up the broth beautifully.','2026-04-15'],
            ['lao-zhang-hotpot','Angela K.',5,'Came on a cold evening — the charcoal copper pot warming the table felt like the most comforting thing in the world. Zero to five stars in one visit.','2026-04-01'],
            ['lao-zhang-hotpot','Sam P.',4,'Solid traditional hot pot. Queue at peak times so arrive early. The mushroom platter was a great addition alongside the lamb.','2026-03-19'],
            // Seoul Garden
            ['seoul-garden','Jennifer K.',5,'The marinated galbi is incredible — tender, caramelised edges, that smoky sweetness from the pear marinade. I dreamt about it the next day.','2026-05-09'],
            ['seoul-garden','David L.',5,'The ventilation at every table is a game changer — no smoky clothes when you leave. Iberian pork belly grilled to golden perfection. Brilliant.','2026-04-26'],
            ['seoul-garden','Emma S.',4,'Unlimited banchan was a highlight — the kimchi and japchae were both outstanding. Dolsot bibimbap with crispy rice was deeply satisfying.','2026-04-14'],
            ['seoul-garden','Marcus T.',5,'Best Korean BBQ in Pudong by a wide margin. The Budae Jjigae is a guilty pleasure I come back for every month.','2026-04-02'],
            ['seoul-garden','Alice B.',4,'Lovely atmosphere with K-pop at the right volume. The haemul pajeon was crispy and generous with seafood. A happy, energetic restaurant.','2026-03-21'],
            // Chongqing Hot Pot
            ['chongqing-hotpot','Oliver Z.',5,'This is the real deal — tallow broth with complex heat that stays with you. Fresh tripe in 7 seconds. Perfection.','2026-05-15'],
            ['chongqing-hotpot','Sarah L.',5,'I grew up in Chongqing and this is as authentic as you\'ll find outside the city. The Pixian doubanjiang base is fiery and fragrant.','2026-05-03'],
            ['chongqing-hotpot','Peter M.',4,'Brilliant spicy hot pot. The yin-yang pot lets less daring friends enjoy it too. Wagyu beef disappeared in seconds in that broth.','2026-04-20'],
            ['chongqing-hotpot','Chloe W.',5,'Open until 11pm which saved our evening after the theatre. The broth colour tells you everything — dark red and glorious.','2026-04-08'],
            ['chongqing-hotpot','Nick T.',4,'Outstanding quality, great price. The rice cake was a brilliant way to finish. My spicy tolerance grew three levels after this meal.','2026-03-25'],
            // Din Tai Fung
            ['din-tai-fung','Fiona H.',5,'The 18-fold xiaolongbao are a work of art — each one holding a small ocean of broth inside. The truffle version elevated everything further.','2026-05-13'],
            ['din-tai-fung','Edward K.',5,'Michelin-recommended for good reason. The shrimp fried rice was flawlessly executed — each grain separate, the wok hei unmistakable.','2026-05-01'],
            ['din-tai-fung','Mia C.',5,'Red oil wontons were dangerously addictive. The mango rice cake dessert was a revelation. Queue is expected but moves quickly.','2026-04-19'],
            ['din-tai-fung','Joshua N.',4,'Consistently excellent. The XLB never disappoint. The staff are informative about the optimal eating technique — appreciated.','2026-04-07'],
            ['din-tai-fung','Lucy B.',5,'The staff brought complimentary longevity noodles for a celebration without being asked. That personal touch made it truly special.','2026-03-26'],
            // Le Bistro
            ['le-bistro','Charles D.',5,'Duck confit that rivals anything I\'ve eaten in Lyon. Eight hours in its own fat produces something simultaneously crispy and outrageously tender.','2026-05-07'],
            ['le-bistro','Isabelle M.',5,'The tableside Crêpe Suzette was theatre and deliciousness in equal measure. The bouillabaisse transported me to Marseille.','2026-04-24'],
            ['le-bistro','Henry W.',4,'Beautifully curated wine list with knowledgeable sommelier. The foie gras terrine was exceptional. Quiet enough for proper conversation.','2026-04-11'],
            ['le-bistro','Amelie P.',5,'Plane trees along Wukang Road, gentle music, perfectly chilled Sancerre — and then duck confit arrived. Life has rarely felt this good.','2026-03-29'],
            ['le-bistro','Robert C.',4,'Classical French technique done with real care. The bouillabaisse was complex and properly made. Dress nicely — this place deserves it.','2026-03-16'],
            // Spice India
            ['spice-india','Priya S.',5,'As an Indian expat, finding butter chicken this good in Shanghai is extraordinary. The freshly ground spices make all the difference.','2026-05-10'],
            ['spice-india','Will T.',5,'The garlic naan from the tandoor arrived golden, puffy and fragrant. Tore it apart and dipped into the rogan josh. That\'s happiness.','2026-04-27'],
            ['spice-india','Charlotte B.',4,'Excellent palak paneer — the spinach sauce was bright and herby, the paneer soft. Great vegetarian options throughout the menu.','2026-04-14'],
            ['spice-india','Arjun M.',5,'The lamb rogan josh had authentic Kashmiri flavors — bold with dried chilies and warming spices. The mango lassi was the perfect counterpoint.','2026-04-01'],
            ['spice-india','Sophie L.',4,'Very solid Indian cooking at a fair price. The aroma walking through the door already tells you something good is coming.','2026-03-20'],
            // Vietnam Pho
            ['vietnam-pho','Lucas W.',5,'Pho broth simmered for 12 hours — you taste every hour of it. The star anise, the cinnamon, the clean beefy depth. Fresh herbs on the side. Bliss.','2026-05-06'],
            ['vietnam-pho','Nina K.',5,'The fresh spring rolls with prawns were impossibly light — rice paper, herbs, the peanut dipping sauce. My go-to lunch spot near Fuxing Park.','2026-04-23'],
            ['vietnam-pho','Felix B.',4,'The iced Vietnamese coffee with condensed milk was the highlight of my afternoon. Bun Bo Hue is properly spicy and full of character.','2026-04-10'],
            ['vietnam-pho','Zara S.',5,'Affordable, healthy, and genuinely delicious. The Hue spicy noodle soup was a revelation — much bolder than regular pho. Will become a regular.','2026-03-28'],
            ['vietnam-pho','Marco R.',4,'Closes at 9pm, plan ahead. The spring rolls are some of the freshest I\'ve had anywhere in the city.','2026-03-15'],
            // Fogo Brasil
            ['barbeque-brazil','Jake H.',5,'The Picanha alone is worth the price. Fat cap, perfect crust, pink inside — the gauchos know exactly when to carve. An absolute carnivore\'s dream.','2026-05-12'],
            ['barbeque-brazil','Anna K.',5,'Twenty minutes in and I\'d tried six different cuts. The Costela slow-cooked for four hours was the standout. Unlimited salad bar was excellent too.','2026-04-30'],
            ['barbeque-brazil','Patrick D.',4,'Great experience. The flank was wonderfully tender. The pork linguiça sausage had beautiful spice. Good value for the quality.','2026-04-17'],
            ['barbeque-brazil','Kate B.',5,'Brought eight friends for a birthday — everyone left euphoric. The tableside carving theatre never gets old. Best group dining in Pudong.','2026-04-04'],
            ['barbeque-brazil','Carlos M.',5,'As a Brazilian, I can confirm this is authentic. The quality of the Picanha matches the best churrascarias back home. Impressive for Shanghai.','2026-03-22'],
            // Dim Sum Palace
            ['dim-sum-palace','Lily C.',5,'The har gow here are a benchmark — 18 pleats, translucent skin that just holds, prawn filling sweet and crunchy. My gold standard for dim sum.','2026-05-14'],
            ['dim-sum-palace','Owen T.',5,'Push-cart service at 7am — piping hot siu mai arriving with my tea was the best way to start a Sunday. Authentic dim sum culture at its finest.','2026-05-02'],
            ['dim-sum-palace','Rachel W.',5,'The shrimp rice noodle roll arrived silky and glistening. Four egg tarts straight from the oven and I ate them all before my friend arrived. No regrets.','2026-04-20'],
            ['dim-sum-palace','Steven L.',4,'Closes at 3pm so plan ahead. Congee with preserved egg was deeply comforting. The atmosphere is wonderfully old-school Hong Kong.','2026-04-08'],
            ['dim-sum-palace','Grace M.',5,'Three generations of our family — grandparents, parents, kids — all found something to love here. The roasted pork and char siu bao were outstanding.','2026-03-26'],
        ];
        foreach ($reviews as $r) $rv->execute($r);
        step('Seeded ' . count($reviews) . ' reviews across all restaurants.');
    } else {
        step("Reviews already seeded ($rvCnt rows) — skipped.");
    }

} catch (PDOException $e) {
    step('Database error: ' . $e->getMessage(), false);
}
?>
<hr>
<h2>Setup Complete</h2>
<p><strong>Admin:</strong> username=Admin &nbsp; password=123456</p>
<p><a href="index.html">→ 顾客入口 (index.html)</a></p>
<p style="color:#888;font-size:12px">建议初始化完成后删除或重命名此文件。</p>
</body>
</html>
