// ============================================================
// db.js  —  Database Connection Module
// Course: CPS 3500  |  Database Designer: Guo Junyan
// Usage:  const db = require('./db');
// ============================================================

const mysql = require('mysql2/promise');

// ── Connection Pool ──────────────────────────────────────────
const pool = mysql.createPool({
  host:               process.env.DB_HOST     || 'localhost',
  port:               process.env.DB_PORT     || 3306,
  user:               process.env.DB_USER     || 'root',
  password:           process.env.DB_PASSWORD || '123456',
  database:           process.env.DB_NAME     || 'restaurant_db',
  waitForConnections: true,
  connectionLimit:    10,
  charset:            'utf8mb4',
});

// Quick connectivity test (call once on server start)
async function testConnection() {
  try {
    const conn = await pool.getConnection();
    console.log('✅  MySQL connected successfully');
    conn.release();
  } catch (err) {
    console.error('❌  MySQL connection failed:', err.message);
    process.exit(1);
  }
}

// ── Generic query helper ─────────────────────────────────────
async function query(sql, params = []) {
  const [rows] = await pool.execute(sql, params);
  return rows;
}

// ============================================================
// USER  queries
// ============================================================
const User = {
  /** Find user by email (used for login) */
  findByEmail: (email) =>
    query('SELECT * FROM users WHERE email = ? LIMIT 1', [email])
      .then(rows => rows[0] || null),

  /** Find user by ID */
  findById: (id) =>
    query('SELECT user_id, full_name, email, phone, role, created_at FROM users WHERE user_id = ?', [id])
      .then(rows => rows[0] || null),

  /** Register new customer */
  create: ({ full_name, email, password, phone }) =>
    query(
      'INSERT INTO users (full_name, email, password, phone) VALUES (?, ?, ?, ?)',
      [full_name, email, password, phone]
    ),
};

// ============================================================
// MENU  queries
// ============================================================
const Menu = {
  /** All available menu items with their category name */
  getAll: () =>
    query(`
      SELECT mi.item_id, c.name AS category, mi.name, mi.description,
             mi.price, mi.image_url
      FROM menu_items mi
      JOIN categories c ON mi.category_id = c.category_id
      WHERE mi.is_available = 1
      ORDER BY c.display_order, mi.name
    `),

  /** Menu items filtered by category */
  getByCategory: (categoryId) =>
    query(
      `SELECT * FROM menu_items WHERE category_id = ? AND is_available = 1`,
      [categoryId]
    ),

  /** Single item by ID */
  findById: (id) =>
    query('SELECT * FROM menu_items WHERE item_id = ?', [id])
      .then(rows => rows[0] || null),

  // ── Admin operations ───────────────────────────────────────
  create: ({ category_id, name, description, price, image_url }) =>
    query(
      'INSERT INTO menu_items (category_id, name, description, price, image_url) VALUES (?, ?, ?, ?, ?)',
      [category_id, name, description, price, image_url]
    ),

  update: (id, { name, description, price, image_url, is_available }) =>
    query(
      `UPDATE menu_items
       SET name=?, description=?, price=?, image_url=?, is_available=?
       WHERE item_id=?`,
      [name, description, price, image_url, is_available, id]
    ),

  delete: (id) =>
    query('DELETE FROM menu_items WHERE item_id = ?', [id]),
};

// ============================================================
// CART  queries
// ============================================================
const Cart = {
  getByUser: (userId) =>
    query(`
      SELECT c.cart_id, mi.item_id, mi.name, mi.price, mi.image_url, c.quantity,
             (mi.price * c.quantity) AS subtotal
      FROM cart c
      JOIN menu_items mi ON c.item_id = mi.item_id
      WHERE c.user_id = ?
    `, [userId]),

  /** Add item or increase quantity if already exists */
  addOrUpdate: (userId, itemId, quantity) =>
    query(`
      INSERT INTO cart (user_id, item_id, quantity)
      VALUES (?, ?, ?)
      ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
    `, [userId, itemId, quantity]),

  updateQuantity: (userId, itemId, quantity) =>
    query(
      'UPDATE cart SET quantity = ? WHERE user_id = ? AND item_id = ?',
      [quantity, userId, itemId]
    ),

  removeItem: (userId, itemId) =>
    query('DELETE FROM cart WHERE user_id = ? AND item_id = ?', [userId, itemId]),

  clearCart: (userId) =>
    query('DELETE FROM cart WHERE user_id = ?', [userId]),
};

// ============================================================
// ORDER  queries
// ============================================================
const Order = {
  /** Create order + order_items + payment in a transaction */
  createFromCart: async (userId, note = '') => {
    const conn = await pool.getConnection();
    try {
      await conn.beginTransaction();

      // 1. Fetch cart
      const [cartItems] = await conn.execute(`
        SELECT c.item_id, c.quantity, mi.price
        FROM cart c JOIN menu_items mi ON c.item_id = mi.item_id
        WHERE c.user_id = ?
      `, [userId]);

      if (cartItems.length === 0) throw new Error('Cart is empty');

      // 2. Calculate total
      const total = cartItems.reduce((sum, i) => sum + i.price * i.quantity, 0);

      // 3. Insert order
      const [orderResult] = await conn.execute(
        'INSERT INTO orders (user_id, total_price, note) VALUES (?, ?, ?)',
        [userId, total.toFixed(2), note]
      );
      const orderId = orderResult.insertId;

      // 4. Insert order_items
      for (const item of cartItems) {
        await conn.execute(
          'INSERT INTO order_items (order_id, item_id, quantity, unit_price) VALUES (?, ?, ?, ?)',
          [orderId, item.item_id, item.quantity, item.price]
        );
      }

      // 5. Create pending payment record
      await conn.execute(
        'INSERT INTO payments (order_id, method, status, amount) VALUES (?, ?, ?, ?)',
        [orderId, 'online', 'pending', total.toFixed(2)]
      );

      // 6. Clear cart
      await conn.execute('DELETE FROM cart WHERE user_id = ?', [userId]);

      await conn.commit();
      return orderId;
    } catch (err) {
      await conn.rollback();
      throw err;
    } finally {
      conn.release();
    }
  },

  /** Orders for a specific customer */
  getByUser: (userId) =>
    query(
      'SELECT * FROM v_order_summary WHERE user_id = ? ORDER BY created_at DESC',
      // note: v_order_summary doesn't expose user_id — use direct table for security
      'SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC',
      [userId]
    ),

  getById: (orderId) =>
    query('SELECT * FROM v_order_summary WHERE order_id = ?', [orderId])
      .then(rows => rows[0] || null),

  getItems: (orderId) =>
    query('SELECT * FROM v_order_detail WHERE order_id = ?', [orderId]),

  /** Admin: list all orders */
  getAll: () =>
    query('SELECT * FROM v_order_summary ORDER BY created_at DESC'),

  /** Admin: update order status */
  updateStatus: (orderId, status) =>
    query('UPDATE orders SET status = ? WHERE order_id = ?', [status, orderId]),
};

// ============================================================
// PAYMENT  queries
// ============================================================
const Payment = {
  /** Simulate payment — mark as paid and record transaction number */
  pay: (orderId, method) => {
    const txnNo = 'TXN-' + Date.now();
    return query(`
      UPDATE payments
      SET status = 'paid', method = ?, paid_at = NOW(), transaction_no = ?
      WHERE order_id = ?
    `, [method, txnNo, orderId]);
  },
};

// ============================================================
module.exports = {
  pool,
  testConnection,
  query,
  User,
  Menu,
  Cart,
  Order,
  Payment,
};
