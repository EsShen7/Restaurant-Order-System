-- ============================================================
-- Migration v2: 全城餐厅智能推荐系统
-- Adds multi-restaurant support, reviews, preferences, AI logs
-- ============================================================

USE restaurant_db;

-- ─────────────────────────────────────────
-- 1. RESTAURANTS
-- ─────────────────────────────────────────
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

-- ─────────────────────────────────────────
-- 2. CUISINE TAGS
-- ─────────────────────────────────────────
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

-- ─────────────────────────────────────────
-- 3. REVIEWS
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS reviews (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id   INT NOT NULL,
    user_id         INT DEFAULT NULL,
    customer_name   VARCHAR(100),
    rating          TINYINT NOT NULL CHECK (rating >= 1 AND rating <= 5),
    comment         TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)       REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────
-- 4. USER PREFERENCES
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS user_preferences (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    user_id              INT NOT NULL UNIQUE,
    preferred_cuisines   TEXT,       -- JSON array of cuisine IDs
    price_min            DECIMAL(8,2),
    price_max            DECIMAL(8,2),
    preferred_districts  TEXT,       -- JSON array of district names
    dietary_restrictions TEXT,       -- JSON array
    created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────
-- 5. USER HISTORY
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS user_history (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    restaurant_id   INT NOT NULL,
    action_type     VARCHAR(50) NOT NULL,  -- 'view','order','review','favorite'
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)       REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────
-- 6. FAVORITES
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS user_favorites (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    restaurant_id   INT NOT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY (user_id, restaurant_id),
    FOREIGN KEY (user_id)       REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────
-- 7. AI RECOMMENDATION LOGS
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ai_recommendation_logs (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    user_id          INT DEFAULT NULL,
    session_id       VARCHAR(64),
    query_text       TEXT,
    recommendations  JSON,            -- array of recommended restaurant IDs with scores
    user_feedback    VARCHAR(50),     -- 'clicked','ignored','thumbs_up','thumbs_down'
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────
-- 8. ALTER EXISTING TABLES
-- ─────────────────────────────────────────
ALTER TABLE categories ADD COLUMN restaurant_id INT DEFAULT NULL AFTER category_id,
    ADD FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE;

ALTER TABLE menu_items ADD COLUMN restaurant_id INT DEFAULT NULL AFTER item_id,
    ADD FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE;

ALTER TABLE orders ADD COLUMN restaurant_id INT DEFAULT NULL AFTER order_id,
    ADD FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE SET NULL;

-- ============================================================
-- SEED DATA: CUISINES
-- ============================================================
INSERT IGNORE INTO restaurant_cuisines (name) VALUES
('Sichuan'), ('Cantonese'), ('Hunan'), ('Shandong'), ('Jiangsu'),
('Japanese'), ('Korean'), ('Western'), ('SE Asian'), ('Hotpot'),
('BBQ'), ('Snacks'), ('Dessert'), ('Coffee'), ('Tea'),
('Fusion'), ('Seafood'), ('Vegetarian');

-- ============================================================
-- SEED DATA: RESTAURANTS (10 diverse restaurants)
-- ============================================================
INSERT INTO restaurants (id, name, name_cn, description, address, district, phone, opening_hours, price_range, avg_rating, review_count, image_url, is_active) VALUES
(1, 'Chuan Flavor House', NULL,
 'Three generations of authentic Sichuan cuisine. Award-winning chef, hand-made spices, fresh ingredients daily. Signature boiled fish and mapo tofu.',
 '88 East Street, Jinjiang', 'Jinjiang', '028-86521111', '11:00-22:00', '$$', 4.6, 328, NULL, 1),

(2, 'Yue Gang Xuan', NULL,
 'Authentic Cantonese tea house with 40+ handmade dim sum varieties. Weekend morning tea requires one-week advance booking.',
 '66 Kehua North Road, Wuhou', 'Wuhou', '028-85432222', '09:00-21:30', '$$$', 4.7, 512, NULL, 1),

(3, 'Sakura Japanese', NULL,
 'Premium Japanese cuisine with daily air-freighted ingredients from Tokyo Toyosu Market. 15-year experienced Japanese head chef. Omakase requires 3-day advance booking.',
 '999 Tianfu Avenue, High-tech', 'High-tech', '028-85993333', '11:30-22:00', '$$$$', 4.8, 256, NULL, 1),

(4, 'Romance Italia', NULL,
 'Romantic vintage Italian restaurant with handmade pasta and wood-fired pizza. Live piano on weekends. Perfect for dates and anniversaries.',
 '12 Kuanzhai Alley, Qingyang', 'Qingyang', '028-86264444', '11:00-23:00', '$$$', 4.5, 189, NULL, 1),

(5, 'Haidilao Hotpot', NULL,
 'Nationally renowned hotpot chain famous for legendary service. Free manicures, shoe shines, face-changing performances. Open 24 hours. Tomato broth is the signature.',
 '1 Hongxing Road, Jinjiang', 'Jinjiang', '028-86705555', '24 hours', '$$', 4.4, 2018, NULL, 1),

(6, 'Northwest Home', NULL,
 'Hearty flavors from China''s northwest. Authentic Lanzhou beef noodles, hand-grabbed lamb, big plate chicken. Noodles pulled fresh daily.',
 '88 1st Ring North Road, Jinniu', 'Jinniu', '028-83186666', '10:00-21:00', '$', 4.3, 156, NULL, 1),

(7, 'Thai Dragon', NULL,
 'One of the city''s first Thai restaurants, operating for 15 years. Tom Yum soup, curry crab, and mango sticky rice are signatures. Spices directly sourced from Thailand.',
 '36 Sizhu Road, Wuhou', 'Wuhou', '028-85437777', '11:00-21:30', '$$', 4.5, 234, NULL, 1),

(8, 'Morning Tea Night Wine', NULL,
 'A refined tea house by day, a chic lounge by night. 50+ premium Chinese teas and creative cocktails. Elegant ambiance for gatherings with friends.',
 '56 Tiexiangsi Water Street, High-tech', 'High-tech', '028-85108888', '10:00-02:00', '$$', 4.6, 167, NULL, 1),

(9, 'Chaoshan Beef Hotpot', NULL,
 'Authentic Chaoshan beef hotpot with fresh beef delivered twice daily. Premium cuts sliced to order. Hand-pounded beef balls are a specialty.',
 '65 Liangui South Road, Jinjiang', 'Jinjiang', '028-84559999', '11:00-23:00', '$$', 4.7, 423, NULL, 1),

(10, 'Taipei Bites', NULL,
 'Taiwanese snack collection: oyster omelet, braised pork rice, beef noodles, bubble tea and more. The owner is from Taipei — all recipes and spices are authentic.',
 '15 Jianshe Lane, Chenghua', 'Chenghua', '028-83260000', '11:00-21:00', '$', 4.2, 98, NULL, 1);

-- ─────────────────────────────────────────
-- RESTAURANT-CUISINE MAPPING
-- ─────────────────────────────────────────
INSERT INTO restaurant_cuisine_map (restaurant_id, cuisine_id)
SELECT 1, id FROM restaurant_cuisines WHERE name='Sichuan';
INSERT INTO restaurant_cuisine_map (restaurant_id, cuisine_id)
SELECT 1, id FROM restaurant_cuisines WHERE name='Hotpot';
INSERT INTO restaurant_cuisine_map (restaurant_id, cuisine_id)
SELECT 2, id FROM restaurant_cuisines WHERE name='Cantonese';
INSERT INTO restaurant_cuisine_map (restaurant_id, cuisine_id)
SELECT 2, id FROM restaurant_cuisines WHERE name='Snacks';
INSERT INTO restaurant_cuisine_map (restaurant_id, cuisine_id)
SELECT 3, id FROM restaurant_cuisines WHERE name='Japanese';
INSERT INTO restaurant_cuisine_map (restaurant_id, cuisine_id)
SELECT 3, id FROM restaurant_cuisines WHERE name='Seafood';
INSERT INTO restaurant_cuisine_map (restaurant_id, cuisine_id)
SELECT 4, id FROM restaurant_cuisines WHERE name='Western';
INSERT INTO restaurant_cuisine_map (restaurant_id, cuisine_id)
SELECT 5, id FROM restaurant_cuisines WHERE name='Hotpot';
INSERT INTO restaurant_cuisine_map (restaurant_id, cuisine_id)
SELECT 6, id FROM restaurant_cuisines WHERE name='BBQ';
INSERT INTO restaurant_cuisine_map (restaurant_id, cuisine_id)
SELECT 6, id FROM restaurant_cuisines WHERE name='Snacks';
INSERT INTO restaurant_cuisine_map (restaurant_id, cuisine_id)
SELECT 7, id FROM restaurant_cuisines WHERE name='SE Asian';
INSERT INTO restaurant_cuisine_map (restaurant_id, cuisine_id)
SELECT 8, id FROM restaurant_cuisines WHERE name='Coffee';
INSERT INTO restaurant_cuisine_map (restaurant_id, cuisine_id)
SELECT 8, id FROM restaurant_cuisines WHERE name='Tea';
INSERT INTO restaurant_cuisine_map (restaurant_id, cuisine_id)
SELECT 9, id FROM restaurant_cuisines WHERE name='Hotpot';
INSERT INTO restaurant_cuisine_map (restaurant_id, cuisine_id)
SELECT 9, id FROM restaurant_cuisines WHERE name='Seafood';
INSERT INTO restaurant_cuisine_map (restaurant_id, cuisine_id)
SELECT 10, id FROM restaurant_cuisines WHERE name='Snacks';
INSERT INTO restaurant_cuisine_map (restaurant_id, cuisine_id)
SELECT 10, id FROM restaurant_cuisines WHERE name='Dessert';

-- ─────────────────────────────────────────
-- SAMPLE REVIEWS
-- ─────────────────────────────────────────
INSERT INTO reviews (restaurant_id, customer_name, rating, comment, created_at) VALUES
(1, 'Mr. Zhang', 5, 'The boiled fish is incredibly authentic! Numbing and spicy, I order it every time.', '2026-05-10 12:30:00'),
(1, 'Ms. Li', 4, 'Great flavor, but the wait can be long. Recommend booking ahead.', '2026-05-12 18:20:00'),
(2, 'Mr. Wang', 5, 'Thin-skinned shrimp dumplings with generous filling — the most authentic Cantonese dim sum in Chengdu.', '2026-05-08 09:15:00'),
(2, 'Ms. Chen', 5, 'Our go-to for family gatherings. The elders absolutely love it.', '2026-05-14 12:00:00'),
(3, 'Mr. Zhao', 5, 'Every Omakase course is a work of art. The sea urchin is so fresh it is sweet.', '2026-04-28 19:30:00'),
(3, 'Ms. Liu', 5, 'The best Japanese restaurant in Chengdu, bar none. Chose it for my birthday dinner — thoroughly satisfied.', '2026-05-05 20:00:00'),
(4, 'Ms. Zhou', 5, 'The ambiance is stunning, like being on vacation in Rome. Perfect for dates!', '2026-05-15 18:45:00'),
(4, 'Mr. Wu', 4, 'The steak was cooked to perfection. Tiramisu made the authentic Italian way.', '2026-05-02 19:00:00'),
(5, 'Mr. Zheng', 5, 'Hotpot still piping hot at 2am! The service is always on point.', '2026-05-11 01:30:00'),
(5, 'Ms. Huang', 4, 'The tomato broth is incredible! But the queue is insane — go on weekdays.', '2026-05-06 20:00:00'),
(6, 'Mr. Ma', 5, 'The Lanzhou beef noodle soup is spot-on, noodles are perfectly chewy. Lamb skewers are a must.', '2026-05-09 12:30:00'),
(7, 'Ms. Lin', 5, 'Tom Yum soup tastes exactly like it does in Thailand! The curry crab sauce over rice is incredible.', '2026-05-13 19:00:00'),
(8, 'Mr. Yang', 4, 'Very quiet atmosphere, great for meetings. The Tieguanyin tea is excellent quality.', '2026-05-07 15:00:00'),
(8, 'Ms. Xu', 5, 'The evening cocktails are very creative. The tea-liquor fusion concept is brilliant.', '2026-05-16 21:00:00'),
(9, 'Mr. Sun', 5, 'The beef is so fresh! The fat-marbled brisket melts in your mouth — the best beef hotpot I have ever had.', '2026-05-10 19:30:00'),
(9, 'Ms. Qian', 5, 'The beef balls are wonderfully bouncy and chewy. The satay sauce is authentic too.', '2026-05-04 18:00:00'),
(10, 'Ms. Gao', 4, 'The braised pork rice is rich without being greasy. Bubble tea pearls are perfectly chewy. Great value.', '2026-05-12 13:00:00');

-- ─────────────────────────────────────────
-- UPDATE RATINGS BASED ON REVIEWS
-- ─────────────────────────────────────────
UPDATE restaurants r SET
    avg_rating   = ROUND((SELECT AVG(rating) FROM reviews WHERE restaurant_id = r.id), 1),
    review_count = (SELECT COUNT(*) FROM reviews WHERE restaurant_id = r.id);

-- ─────────────────────────────────────────
-- ASSIGN EXISTING CATEGORIES & MENU ITEMS TO RESTAURANT 1 (川味轩)
-- ─────────────────────────────────────────
UPDATE categories SET restaurant_id = 1 WHERE restaurant_id IS NULL;
UPDATE menu_items SET restaurant_id = 1 WHERE restaurant_id IS NULL;
