-- ============================================================
-- Restaurant Ordering System - Database Schema
-- Course: CPS 3500  |  Role: Database Designer (Guo Junyan)
-- ============================================================

CREATE DATABASE IF NOT EXISTS restaurant_db
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE restaurant_db;

-- ─────────────────────────────────────────
-- 1. USER
-- ─────────────────────────────────────────
CREATE TABLE users (
    user_id      INT AUTO_INCREMENT PRIMARY KEY,
    full_name    VARCHAR(100)        NOT NULL,
    email        VARCHAR(150)        NOT NULL UNIQUE,
    password     VARCHAR(255)        NOT NULL,       -- store hashed password (bcrypt)
    phone        VARCHAR(20),
    role         ENUM('customer','admin') NOT NULL DEFAULT 'customer',
    created_at   TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ─────────────────────────────────────────
-- 2. CATEGORIES  (菜品分类)
-- ─────────────────────────────────────────
CREATE TABLE categories (
    category_id   INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(80)  NOT NULL,
    description   TEXT,
    display_order INT          NOT NULL DEFAULT 0
);

-- ─────────────────────────────────────────
-- 3. MENU ITEMS  (菜单菜品)
-- ─────────────────────────────────────────
CREATE TABLE menu_items (
    item_id       INT AUTO_INCREMENT PRIMARY KEY,
    category_id   INT             NOT NULL,
    name          VARCHAR(150)    NOT NULL,
    description   TEXT,
    price         DECIMAL(8,2)    NOT NULL,
    image_url     VARCHAR(255),
    is_available  TINYINT(1)      NOT NULL DEFAULT 1,   -- 1=on sale, 0=off shelf
    created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(category_id) ON DELETE CASCADE
);

-- ─────────────────────────────────────────
-- 4. ORDERS  (订单主表)
-- ─────────────────────────────────────────
CREATE TABLE orders (
    order_id      INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT             NOT NULL,
    status        ENUM('pending','confirmed','preparing','ready','delivered','cancelled')
                                  NOT NULL DEFAULT 'pending',
    total_price   DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    note          TEXT,                                 -- 备注
    created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- ─────────────────────────────────────────
-- 5. ORDER ITEMS  (订单明细)
-- ─────────────────────────────────────────
CREATE TABLE order_items (
    order_item_id INT AUTO_INCREMENT PRIMARY KEY,
    order_id      INT             NOT NULL,
    item_id       INT             NOT NULL,
    quantity      INT             NOT NULL DEFAULT 1,
    unit_price    DECIMAL(8,2)    NOT NULL,             -- snapshot price at order time
    subtotal      DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
    FOREIGN KEY (item_id)  REFERENCES menu_items(item_id) ON DELETE RESTRICT
);

-- ─────────────────────────────────────────
-- 6. PAYMENTS  (支付模拟)
-- ─────────────────────────────────────────
CREATE TABLE payments (
    payment_id     INT AUTO_INCREMENT PRIMARY KEY,
    order_id       INT             NOT NULL UNIQUE,
    method         ENUM('cash','credit_card','online') NOT NULL DEFAULT 'online',
    status         ENUM('pending','paid','refunded')   NOT NULL DEFAULT 'pending',
    amount         DECIMAL(10,2)   NOT NULL,
    paid_at        TIMESTAMP       NULL,
    transaction_no VARCHAR(64),                        -- simulated transaction ID
    FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE
);

-- ─────────────────────────────────────────
-- 7. CART  (购物车，临时状态)
-- ─────────────────────────────────────────
CREATE TABLE cart (
    cart_id     INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT  NOT NULL,
    item_id     INT  NOT NULL,
    quantity    INT  NOT NULL DEFAULT 1,
    added_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_item (user_id, item_id),
    FOREIGN KEY (user_id) REFERENCES users(user_id)    ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES menu_items(item_id) ON DELETE CASCADE
);

-- ============================================================
-- SAMPLE DATA
-- ============================================================

-- Categories
INSERT INTO categories (name, description, display_order) VALUES
('Appetizers',  'Start your meal right',              1),
('Main Course', 'Hearty dishes for every appetite',   2),
('Drinks',      'Fresh beverages and soft drinks',    3),
('Desserts',    'Sweet endings',                      4);

-- Menu Items
INSERT INTO menu_items (category_id, name, description, price, image_url) VALUES
-- Appetizers
(1, 'Spring Rolls',       'Crispy vegetable spring rolls (×4)',             5.99,  'images/spring_rolls.jpg'),
(1, 'Garlic Bread',       'Toasted baguette with herb butter',              4.50,  'images/garlic_bread.jpg'),
(1, 'Soup of the Day',    'Ask your server for today\'s soup',              4.00,  'images/soup.jpg'),
-- Main Course
(2, 'Grilled Salmon',     'Atlantic salmon, lemon butter, seasonal veg',   18.99, 'images/salmon.jpg'),
(2, 'Beef Burger',        '200g beef patty, cheddar, lettuce, tomato',     13.50, 'images/burger.jpg'),
(2, 'Margherita Pizza',   '12-inch, tomato, mozzarella, fresh basil',      14.00, 'images/pizza.jpg'),
(2, 'Pasta Carbonara',    'Spaghetti, bacon, egg, parmesan',               12.00, 'images/pasta.jpg'),
-- Drinks
(3, 'Lemonade',           'Fresh-squeezed, served with ice',                3.50,  'images/lemonade.jpg'),
(3, 'Iced Coffee',        'Cold brew over ice, milk optional',              4.00,  'images/iced_coffee.jpg'),
(3, 'Sparkling Water',    '500 ml',                                         2.00,  'images/water.jpg'),
-- Desserts
(4, 'Chocolate Lava Cake','Warm centre, vanilla ice cream',                 6.50,  'images/lava_cake.jpg'),
(4, 'Cheesecake Slice',   'New York style, berry compote',                  5.50,  'images/cheesecake.jpg');

-- Users (passwords are bcrypt hashes of "password123" and "admin123")
INSERT INTO users (full_name, email, password, phone, role) VALUES
('Alice Wong',   'alice@example.com', '$2b$10$exampleHashForAlice000000000000000000000000000', '555-1001', 'customer'),
('Bob Chen',     'bob@example.com',   '$2b$10$exampleHashForBob0000000000000000000000000000', '555-1002', 'customer'),
('Admin User',   'admin@restaurant.com','$2b$10$exampleHashForAdmin00000000000000000000000000','555-0000', 'admin');

-- Orders
INSERT INTO orders (user_id, status, total_price, note) VALUES
(1, 'delivered', 36.49, 'No onions please'),
(2, 'preparing', 17.50, NULL);

-- Order Items
INSERT INTO order_items (order_id, item_id, quantity, unit_price) VALUES
(1, 4, 1, 18.99),   -- Grilled Salmon
(1, 1, 2,  5.99),   -- Spring Rolls ×2
(1, 8, 1,  3.50),   -- Lemonade
(2, 5, 1, 13.50),   -- Beef Burger
(2, 9, 1,  4.00);   -- Iced Coffee

-- Payments
INSERT INTO payments (order_id, method, status, amount, paid_at, transaction_no) VALUES
(1, 'credit_card', 'paid',    36.49, '2026-05-10 12:34:00', 'TXN-20260510-001'),
(2, 'online',      'pending', 17.50,  NULL,                  NULL);

-- Cart (Bob has items in cart)
INSERT INTO cart (user_id, item_id, quantity) VALUES
(2, 6, 1),   -- Margherita Pizza
(2, 11, 1);  -- Chocolate Lava Cake

-- ============================================================
-- USEFUL VIEWS (optional, helps the backend team)
-- ============================================================

-- Full order summary
CREATE OR REPLACE VIEW v_order_summary AS
SELECT
    o.order_id,
    u.full_name,
    u.email,
    o.status,
    o.total_price,
    o.created_at,
    p.method        AS payment_method,
    p.status        AS payment_status
FROM orders o
JOIN users    u ON o.user_id   = u.user_id
LEFT JOIN payments p ON o.order_id = p.order_id;

-- Order detail (items breakdown)
CREATE OR REPLACE VIEW v_order_detail AS
SELECT
    oi.order_id,
    mi.name         AS item_name,
    oi.quantity,
    oi.unit_price,
    oi.subtotal
FROM order_items oi
JOIN menu_items mi ON oi.item_id = mi.item_id;

-- ════════════════════════════════════════════════════════
-- V2: Multi-Restaurant & Recommendation Tables
-- ════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS restaurants (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(200) NOT NULL,
    name_cn        VARCHAR(200),
    description    TEXT,
    address        VARCHAR(500),
    district       VARCHAR(100),
    latitude       DECIMAL(10,8),
    longitude      DECIMAL(11,8),
    phone          VARCHAR(20),
    opening_hours  VARCHAR(200),
    price_range    VARCHAR(20) DEFAULT '$$',
    avg_rating     DECIMAL(2,1) DEFAULT 0.0,
    review_count   INT DEFAULT 0,
    image_url      VARCHAR(500),
    is_active      TINYINT(1) DEFAULT 1,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS restaurant_cuisines (
    id   INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS restaurant_cuisine_map (
    restaurant_id INT NOT NULL,
    cuisine_id    INT NOT NULL,
    PRIMARY KEY (restaurant_id, cuisine_id),
    FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE,
    FOREIGN KEY (cuisine_id)    REFERENCES restaurant_cuisines(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS reviews (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id   INT NOT NULL,
    user_id         INT DEFAULT NULL,
    customer_name   VARCHAR(100),
    rating          TINYINT NOT NULL,
    comment         TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)       REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_preferences (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    user_id              INT NOT NULL UNIQUE,
    preferred_cuisines   TEXT,
    price_min            DECIMAL(8,2),
    price_max            DECIMAL(8,2),
    preferred_districts  TEXT,
    dietary_restrictions TEXT,
    created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_favorites (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    restaurant_id   INT NOT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY (user_id, restaurant_id),
    FOREIGN KEY (user_id)       REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_recommendation_logs (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    user_id          INT DEFAULT NULL,
    session_id       VARCHAR(64),
    query_text       TEXT,
    recommendations  JSON,
    user_feedback    VARCHAR(50),
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
