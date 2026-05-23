/* ═══════════════════════════════════════════════════════
   CityEats · City-wide Restaurant Discovery
   ═══════════════════════════════════════════════════════ */

/* ── STATE ─────────────────────────────────────────── */
const state = {
  step: 1,
  tableType: null,
  availability: null,
  reservationId: null,
  menuItems: [],
  lookupData: null,
  allRestaurants: [],
  selectedRestaurant: null, // for booking context
  customer: null,   // logged-in customer info
  partySize: 2,     // party size filter
};

/* ── INIT ──────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('startBtn')?.addEventListener('click', scrollToReservation);
  document.getElementById('navBookBtn')?.addEventListener('click', scrollToReservation);
  document.getElementById('navManageBtn')?.addEventListener('click', scrollToManage);

  // Populate nav user info from auth check done before page load
  initNavUser();
  loadAvailability();
  loadMenu();
  loadMyBookings();
  initVisualEffects();
  loadCuisines();
  loadRecommendations();
  loadAllRestaurants();
});

/* ── API HELPER ────────────────────────────────────── */
async function api(action, body = {}) {
  const params = new URLSearchParams({ action });
  const resp = await fetch(`api.php?${params}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  const json = await resp.json();
  if (!json.success) throw new Error(json.error || 'Request failed.');
  return json;
}

/* ── NAV USER ──────────────────────────────────────── */
function initNavUser() {
  const c = window.__customer;
  if (!c) return;
  state.customer = c;
  const avatarEl = document.getElementById('navAvatar');
  const nameEl   = document.getElementById('navUName');
  if (avatarEl) avatarEl.textContent = (c.name || '?')[0].toUpperCase();
  if (nameEl)   nameEl.textContent   = c.name || c.email || '';

  // Prefill reservation form with customer data
  const nameInput  = document.getElementById('res-name');
  const phoneInput = document.getElementById('res-phone');
  if (nameInput  && !nameInput.value  && c.name)  nameInput.value  = c.name;
  if (phoneInput && !phoneInput.value && c.phone) phoneInput.value = c.phone;
}

/* ── LOGOUT ────────────────────────────────────────── */
async function logout() {
  try { await api('customer_logout'); } catch(e) {}
  location.href = 'index.html';
}

/* ── PARTY SIZE FILTER ─────────────────────────────── */
function changePartySize(delta) {
  state.partySize = Math.max(1, Math.min(20, state.partySize + delta));
  const el = document.getElementById('partySizeDisplay');
  if (el) el.textContent = state.partySize;
  updatePartyHint();
  highlightRecommendedTable();
}

function updatePartyHint() {
  const n = state.partySize;
  const hintEl = document.getElementById('partyHint');
  if (!hintEl) return;
  if (n <= 2)       hintEl.textContent = 'Standard table recommended';
  else if (n <= 4)  hintEl.textContent = 'Medium table recommended';
  else if (n <= 8)  hintEl.textContent = 'Large table recommended';
  else              hintEl.textContent = 'Private room recommended for large parties';
}

function highlightRecommendedTable() {
  const n = state.partySize;
  let rec = 'small';
  if (n >= 3 && n <= 4)  rec = 'medium';
  else if (n >= 5 && n <= 8) rec = 'large';
  else if (n > 8)        rec = 'private';

  ['small','medium','large','private'].forEach(t => {
    const card = document.getElementById('card-' + t);
    if (!card) return;
    card.classList.toggle('recommended', t === rec);
  });
}

/* ── MY BOOKINGS ───────────────────────────────────── */
async function loadMyBookings() {
  const el = document.getElementById('myBookingsContent');
  if (!el) return;
  try {
    const res = await api('my_reservations');
    renderMyBookings(res.reservations || []);
  } catch(e) {
    el.innerHTML = `<div class="my-bookings-empty">
      <div class="my-bookings-empty-icon">📋</div>
      <p>Unable to load reservations. ${esc(e.message)}</p>
    </div>`;
  }
}

function renderMyBookings(list) {
  const el = document.getElementById('myBookingsContent');
  if (!el) return;
  if (!list.length) {
    el.innerHTML = `<div class="my-bookings-empty">
      <div class="my-bookings-empty-icon">🍽️</div>
      <p>No active reservations yet.</p>
      <p style="margin-top:8px;font-size:13px;color:rgba(255,255,255,.25)">
        Use the <a href="#reservation" style="color:var(--gold)">Reserve a Table</a> section above to make your first booking.
      </p>
    </div>`;
    return;
  }
  const typeNames = { small: 'Standard', medium: 'Medium', large: 'Large', private: 'Private Room' };
  el.innerHTML = `<div class="my-bookings-grid">
    ${list.map(r => `
      <div class="booking-card" id="bk-${r.id}">
        <div class="booking-card-top">
          <span class="bk-type-badge ${r.table_type}">${typeNames[r.table_type] || r.table_type}</span>
          <span class="bk-table-num">Table ${esc(r.table_number)}</span>
        </div>
        <div class="bk-row">Guest: <strong>${esc(r.customer_name)}</strong></div>
        <div class="bk-row">Phone: <strong>${esc(r.phone)}</strong></div>
        ${r.party_size ? `<div class="bk-row">Party: <strong>${r.party_size} guests</strong></div>` : ''}
        ${r.pre_orders_summary ? `<div class="bk-preorders">${
          r.pre_orders_summary.split(', ').map(s => `<span class="bk-po-tag">${esc(s)}</span>`).join('')
        }</div>` : ''}
        <div class="bk-date">Booked ${fmtDate(r.created_at)}</div>
        <div class="bk-actions">
          <button class="bk-cancel-btn" onclick="cancelMyBooking(${r.id},'${esc(r.customer_name)}','${esc(r.phone)}',this)">
            Cancel Reservation
          </button>
        </div>
      </div>
    `).join('')}
  </div>`;
}

async function cancelMyBooking(id, name, phone, btn) {
  if (!confirm('Are you sure you want to cancel this reservation? This cannot be undone.')) return;
  btn.disabled = true; btn.textContent = 'Cancelling…';
  try {
    await api('customer_cancel_reservation', { id, customer_name: name, phone });
    const card = document.getElementById('bk-' + id);
    if (card) {
      card.innerHTML = `<div class="bk-cancelled-overlay">✅ Reservation cancelled successfully.</div>`;
      setTimeout(() => { card.style.opacity='0'; card.style.transition='opacity .5s'; setTimeout(()=>loadMyBookings(),520); }, 1800);
    }
  } catch(e) {
    btn.disabled = false; btn.textContent = 'Cancel Reservation';
    alert(e.message);
  }
}

/* ── AFTER BOOKING: scroll to my-bookings ─────────── */
function afterBookingDone() {
  resetReservation();
  loadMyBookings();
  setTimeout(() => document.getElementById('my-bookings')?.scrollIntoView({ behavior: 'smooth' }), 100);
}

function scrollToReservation() {
  document.getElementById('reservation').scrollIntoView({ behavior: 'smooth' });
}
function scrollToManage() {
  document.getElementById('manage').scrollIntoView({ behavior: 'smooth' });
}

/* ═══════════════════════════════════════════════════════
   RESTAURANT DISCOVERY
   ═══════════════════════════════════════════════════════ */

/* ── CUISINES & FILTERS ───────────────────────────── */
async function loadCuisines() {
  try {
    const res = await api('get_cuisines');
    const select = document.getElementById('filter-cuisine');
    if (!select) return;
    res.cuisines.forEach(c => {
      const opt = document.createElement('option');
      opt.value = c.name;
      opt.textContent = c.name;
      select.appendChild(opt);
    });
    // Load districts from restaurant data
    const districts = [...new Set(state.allRestaurants.map(r => r.district).filter(Boolean))];
    const distSelect = document.getElementById('filter-district');
    if (districts.length > 0 && distSelect) {
      districts.forEach(d => {
        const opt = document.createElement('option');
        opt.value = d;
        opt.textContent = d;
        distSelect.appendChild(opt);
      });
    }
  } catch(e) { console.warn('Failed to load cuisines', e); }
}

function applyFilters() {
  loadAllRestaurants(true);
}

/* ── AI RECOMMENDATIONS ───────────────────────────── */
async function loadRecommendations() {
  const grid = document.getElementById('discover-grid');
  const loading = document.getElementById('discover-loading');
  if (!grid) return;

  try {
    const res = await api('get_recommendations', { limit: 6 });
    if (loading) loading.style.display = 'none';
    if (!res.recommendations || res.recommendations.length === 0) {
      grid.innerHTML = '<p style="text-align:center;color:rgba(255,255,255,.3);grid-column:1/-1;padding:40px">No recommendations right now</p>';
      return;
    }
    grid.innerHTML = res.recommendations.map(r => renderRestaurantCard(r, 'discover')).join('');
  } catch(e) {
    if (loading) loading.style.display = 'none';
    grid.innerHTML = '<p style="text-align:center;color:rgba(255,255,255,.3);grid-column:1/-1;padding:40px">Failed to load recommendations</p>';
  }
}

/* ── ALL RESTAURANTS ──────────────────────────────── */
async function loadAllRestaurants(useFilter = false) {
  const grid = document.getElementById('restaurants-grid');
  const loading = document.getElementById('restaurants-loading');
  if (!grid) return;
  if (!useFilter) loading.style.display = '';

  const params = { limit: 50 };
  if (useFilter) {
    const cuisine = document.getElementById('filter-cuisine')?.value || '';
    const price = document.getElementById('filter-price')?.value || '';
    const district = document.getElementById('filter-district')?.value || '';
    const search = document.getElementById('filter-search')?.value || '';
    if (cuisine) params.cuisine = cuisine;
    if (price) params.price = price;
    if (district) params.district = district;
    if (search) params.search = search;
  }

  try {
    const res = await api('get_restaurants', params);
    state.allRestaurants = res.restaurants;
    if (loading) loading.style.display = 'none';
    if (!res.restaurants || res.restaurants.length === 0) {
      grid.innerHTML = '<p style="text-align:center;color:var(--muted);grid-column:1/-1;padding:60px">No restaurants match your criteria</p>';
      return;
    }
    grid.innerHTML = res.restaurants.map(r => renderRestaurantCard(r, 'all')).join('');
  } catch(e) {
    if (loading) loading.style.display = 'none';
    grid.innerHTML = '<p style="text-align:center;color:var(--muted);grid-column:1/-1;padding:60px">Failed to load restaurants</p>';
  }
}

/* ── RENDER RESTAURANT CARD ───────────────────────── */
function renderRestaurantCard(r, context) {
  const emojiMap = {
    'Sichuan':'🌶️','Cantonese':'🥟','Japanese':'🍣','Western':'🍝','Hotpot':'🍲','BBQ':'🍖',
    'SE Asian':'🍛','Coffee':'☕','Tea':'🫖','Snacks':'🥟','Dessert':'🍰','Seafood':'🦐',
  };
  const img = r.cuisines?.length ? (emojiMap[r.cuisines[0]] || '🍽️') : '🍽️';
  const cnName = r.name_cn ? `<span class="restaurant-card-name-cn">${esc(r.name_cn)}</span>` : '';
  const cuisines = r.cuisines?.map(c => `<span class="cuisine-tag">${esc(c)}</span>`).join('') || '';
  const stars = '★'.repeat(Math.round(r.avg_rating)).padEnd(5, '☆');

  return `<div class="restaurant-card" onclick="openDetail(${r.id})">
    <div class="restaurant-card-img">${img}</div>
    <div class="restaurant-card-body">
      <div class="restaurant-card-header">
        <div class="restaurant-card-name">${esc(r.name)}${cnName}</div>
        <div class="restaurant-card-rating">${stars} ${r.avg_rating}</div>
      </div>
      <div class="restaurant-card-cuisines">${cuisines}</div>
      <div class="restaurant-card-meta">
        <span>📍 ${esc(r.district || '')}</span>
        <span>💰 ${esc(r.price_range || '')}</span>
        <span>💬 ${r.review_count || 0} reviews</span>
      </div>
      <div class="restaurant-card-desc">${esc(r.description || '')}</div>
    </div>
  </div>`;
}

/* ── HERO SEARCH ───────────────────────────────────── */
function doHeroSearch() {
  const input = document.getElementById('heroSearchInput');
  const query = input.value.trim();
  if (!query) return;
  // Set filter search and switch to restaurants tab
  const searchInput = document.getElementById('filter-search');
  if (searchInput) searchInput.value = query;
  applyFilters();
  document.getElementById('restaurants').scrollIntoView({ behavior: 'smooth' });
}

function searchCuisine(cuisine) {
  const select = document.getElementById('filter-cuisine');
  if (select) {
    // Find and select the matching cuisine option
    for (const opt of select.options) {
      if (opt.value === cuisine) { opt.selected = true; break; }
    }
  }
  applyFilters();
  document.getElementById('restaurants').scrollIntoView({ behavior: 'smooth' });
}

/* ── RESTAURANT DETAIL MODAL ───────────────────────── */
async function openDetail(restaurantId) {
  const modal = document.getElementById('detailModal');
  const content = document.getElementById('detail-content');
  if (!modal || !content) return;

  modal.classList.add('show');
  content.innerHTML = '<div style="text-align:center;padding:80px 0;color:var(--muted)">Loading…</div>';

  try {
    const [restRes, reviewRes] = await Promise.all([
      api('get_restaurant', { id: restaurantId }),
      api('get_restaurant_reviews', { id: restaurantId, limit: 10 }),
    ]);
    const r = restRes.restaurant;
    const reviews = reviewRes.reviews || [];

    const cnName = r.name_cn ? `<span class="modal-header-name-cn">${esc(r.name_cn)}</span>` : '';
    const cuisines = r.cuisines?.map(c => `<span class="cuisine-tag" style="font-size:12px">${esc(c)}</span>`).join('') || '';
    const stars = '★'.repeat(Math.round(r.avg_rating)).padEnd(5, '☆');
    const opens = r.opening_hours ? `<span>🕐 ${esc(r.opening_hours)}</span>` : '';
    const phone = r.phone ? `<span>📞 ${esc(r.phone)}</span>` : '';
    const address = r.address ? `<span>📍 ${esc(r.address)}</span>` : '';

    const menuHtml = r.menu?.length
      ? r.menu.map(m => `
        <div class="modal-menu-item">
          <div>
            <div class="modal-menu-item-name">${esc(m.name)}</div>
            ${m.description ? `<div class="modal-menu-item-desc">${esc(m.description)}</div>` : ''}
          </div>
          <div class="modal-menu-item-price">¥${parseFloat(m.price).toFixed(2)}</div>
        </div>
      `).join('')
      : '<p style="color:var(--muted);font-size:13px">No menu information</p>';

    const reviewsHtml = reviews.length
      ? reviews.map(rv => {
          const rvStars = '★'.repeat(rv.rating).padEnd(5, '☆');
          return `<div class="review-item">
            <div class="review-header">
              <span class="review-name">${esc(rv.customer_name || 'Anonymous')}</span>
              <span class="review-rating">${rvStars}</span>
            </div>
            <div class="review-comment">${esc(rv.comment)}</div>
            <div class="review-date">${fmtDate(rv.created_at)}</div>
          </div>`;
        }).join('')
      : '<p style="color:var(--muted);font-size:13px">No reviews yet</p>';

    content.innerHTML = `<div class="modal-inner">
      <div class="modal-header">
        <div class="modal-header-name">${esc(r.name)}${cnName}</div>
        <div class="modal-header-meta">
          <span class="modal-header-rating">${stars} ${r.avg_rating}</span>
          <span>💰 ${esc(r.price_range || '')}</span>
          <span>💬 ${r.review_count || 0} reviews</span>
          ${opens}
        </div>
        <div class="modal-header-cuisines" style="margin-top:8px">${cuisines}</div>
        ${phone}
        ${address}
        ${r.description ? `<div class="modal-header-desc">${esc(r.description)}</div>` : ''}
      </div>

      <div class="modal-section-title">🍽️ Recommended Dishes</div>
      <div>${menuHtml}</div>

      <div class="modal-section-title">💬 Customer Reviews</div>
      <div>${reviewsHtml}</div>

      <button class="modal-btn-book" onclick="bookFromDetail(${r.id}, '${esc(r.name)}')">
        Book at ${esc(r.name)}
      </button>
    </div>`;
  } catch(e) {
    content.innerHTML = `<div style="text-align:center;padding:80px;color:#e74c3c">Failed to load: ${esc(e.message)}</div>`;
  }
}

function closeDetail() {
  const modal = document.getElementById('detailModal');
  if (modal) modal.classList.remove('show');
}

function bookFromDetail(id, name) {
  closeDetail();
  state.selectedRestaurant = { id, name };
  const infoEl = document.getElementById('res-restaurant-info');
  const nameEl = document.getElementById('res-restaurant-name');
  if (infoEl) infoEl.style.display = '';
  if (nameEl) nameEl.textContent = name;
  scrollToReservation();
}

function clearResRestaurant() {
  state.selectedRestaurant = null;
  const infoEl = document.getElementById('res-restaurant-info');
  if (infoEl) infoEl.style.display = 'none';
}

/* ═══════════════════════════════════════════════════════
   AVAILABILITY (kept from original)
   ═══════════════════════════════════════════════════════ */
async function loadAvailability() {
  try {
    const res = await api('get_availability');
    state.availability = res;
    renderTableCards(res.types);
  } catch (e) { console.warn('Availability load failed', e); }
}

function renderTableCards(types) {
  const typeConfig = {
    small:   { id: 'card-small' },
    medium:  { id: 'card-medium' },
    large:   { id: 'card-large' },
    private: { id: 'card-private' },
  };
  for (const [type, cfg] of Object.entries(typeConfig)) {
    const card = document.getElementById(cfg.id);
    if (!card) continue;
    const info = types[type];
    if (!info) continue;
    const avail = parseInt(info.available);
    const total = parseInt(info.total);
    let badgeClass = 'avail-badge';
    let badgeText, availText;
    if (type === 'private') {
      badgeText = avail > 0 ? 'Available' : 'Fully Booked';
      availText = `${avail}/${total} rooms`;
    } else {
      badgeText = avail > 0 ? `${avail} remaining` : 'Fully Booked';
      availText = `${avail}/${total}`;
    }
    if (avail === 0) { badgeClass += ' none'; badgeText = 'Fully Booked'; }
    else if (avail / total < 0.2) badgeClass += ' low';
    const badgeEl = card.querySelector('.avail-badge');
    const availEl = card.querySelector('.avail-num');
    if (badgeEl) { badgeEl.className = badgeClass; badgeEl.innerHTML = `<span class="avail-dot"></span>${badgeText}`; }
    if (availEl) availEl.textContent = availText;
    if (avail === 0) card.classList.add('disabled');
    else card.classList.remove('disabled');
  }
}

/* ── TABLE SELECTION ───────────────────────────────── */
function selectTable(type) {
  state.tableType = type;
  document.querySelectorAll('.table-type-card').forEach(c => c.classList.remove('selected'));
  document.getElementById('card-' + type)?.classList.add('selected');
  document.getElementById('btnNext1').disabled = false;
}

function nextStep1() {
  if (!state.tableType) { showErr('step1-err', 'Please select a table type.'); return; }
  const names = {
    small:   'Standard Table (1-2 guests)',
    medium:  'Medium Table (3-4 guests)',
    large:   'Large Table (5-8 guests)',
    private: 'Private Room',
  };
  document.getElementById('selected-type-display').textContent = names[state.tableType];
  goStep(2);
}

/* ── BOOKING FORM ──────────────────────────────────── */
async function submitReservation() {
  const name  = document.getElementById('res-name').value.trim();
  const phone = document.getElementById('res-phone').value.trim();
  hideErr('step2-err');
  if (!name)  { showErr('step2-err', 'Please enter your name.'); return; }
  if (!/^1[3-9]\d{9}$/.test(phone)) { showErr('step2-err', 'Please enter a valid 11-digit phone number.'); return; }

  const btn = document.getElementById('btnSubmit');
  btn.disabled = true; btn.textContent = 'Submitting…';
  try {
    if (state.tableType === 'private') {
      const res = await api('request_private_room', { customer_name: name, phone });
      showResult('pending', name, res.message);
      state.reservationId = null;
    } else {
      const res = await api('make_reservation', { table_type: state.tableType, customer_name: name, phone });
      showResult('success', name, res.message);
      state.reservationId = res.reservation_id || null;
    }
    goStep(3);
  } catch (e) {
    showErr('step2-err', e.message || 'Submission failed, please try again.');
  } finally {
    btn.disabled = false; btn.textContent = 'Confirm Booking';
  }
}

function showResult(type, name, msg) {
  const titleEl = document.getElementById('result-title');
  const iconEl = document.getElementById('result-icon');
  const msgEl = document.getElementById('result-msg');
  const noteEl = document.getElementById('result-note');
  if (iconEl) iconEl.textContent = type === 'success' ? '✅' : '📋';
  if (titleEl) titleEl.textContent = type === 'success' ? `${name}, booking confirmed!` : `${name}, request submitted!`;
  if (msgEl) msgEl.textContent = msg;
  if (noteEl) {
    noteEl.textContent = type === 'success'
      ? 'To cancel or modify, please call us in advance.'
      : 'Private rooms require staff confirmation. We will contact you shortly. Please keep your phone available.';
    noteEl.style.display = '';
  }

  const preorderSection = document.getElementById('preorder-section');
  const doneBtnWrap = document.getElementById('booking-done-btn');
  const preorderDone = document.getElementById('preorder-done');
  if (preorderDone) preorderDone.style.display = 'none';

  if (preorderSection) preorderSection.style.display = 'none';
  if (doneBtnWrap) doneBtnWrap.style.display = 'none';
  if (type === 'success' && state.reservationId) {
    renderPreorderMenu();
    setTimeout(() => fadeIn(preorderSection, 400), 80);
  } else {
    setTimeout(() => fadeIn(doneBtnWrap, 380), 80);
  }
}

function resetReservation() {
  state.tableType = null;
  state.reservationId = null;
  document.querySelectorAll('.table-type-card').forEach(c => c.classList.remove('selected'));
  const nameEl = document.getElementById('res-name');
  const phoneEl = document.getElementById('res-phone');
  if (nameEl) nameEl.value = '';
  if (phoneEl) phoneEl.value = '';
  document.getElementById('btnNext1').disabled = true;
  const ps = document.getElementById('preorder-section');
  const db = document.getElementById('booking-done-btn');
  const pd = document.getElementById('preorder-done');
  if (ps) ps.style.display = 'none';
  if (db) db.style.display = 'none';
  if (pd) pd.style.display = 'none';
  document.querySelectorAll('[id^="qty-"]').forEach(el => { el.textContent = '0'; });
  document.querySelectorAll('.preorder-item').forEach(el => el.classList.remove('selected'));
  loadAvailability();
  goStep(1);
}

/* ── PRE-ORDER ─────────────────────────────────────── */
async function loadMenu() {
  try {
    const res = await api('get_menu');
    state.menuItems = res.menu;
  } catch(e) {}
}

function renderPreorderMenu() {
  const grid = document.getElementById('preorder-grid');
  if (!grid) return;
  if (!state.menuItems.length) {
    grid.innerHTML = '<p style="color:rgba(255,255,255,.4);font-size:13px">Menu not available.</p>';
    return;
  }
  grid.innerHTML = state.menuItems.map(item => `
    <div class="preorder-item" id="poi-${item.id}">
      <div class="poi-emoji">${item.emoji}</div>
      <div class="poi-name">${esc(item.name)}</div>
      <div class="poi-qty">
        <button onclick="changeQty(${item.id},-1)">−</button>
        <span id="qty-${item.id}">0</span>
        <button onclick="changeQty(${item.id},1)">+</button>
      </div>
    </div>
  `).join('');
}

function changeQty(itemId, delta) {
  const el = document.getElementById('qty-' + itemId);
  if (!el) return;
  let val = parseInt(el.textContent) + delta;
  if (val < 0) val = 0;
  if (val > 10) val = 10;
  el.textContent = val;
  document.getElementById('poi-' + itemId)?.classList.toggle('selected', val > 0);
}

async function submitPreorder() {
  if (!state.reservationId) { showErr('preorder-err', 'Reservation ID missing.'); return; }
  const items = [];
  document.querySelectorAll('[id^="qty-"]').forEach(el => {
    const qty = parseInt(el.textContent);
    if (qty > 0) {
      const itemId = el.id.replace('qty-', '');
      const nameEl = document.querySelector(`#poi-${itemId} .poi-name`);
      if (nameEl) items.push({ name: nameEl.textContent, quantity: qty });
    }
  });
  if (!items.length) { showErr('preorder-err', 'Please select at least one dish.'); return; }
  hideErr('preorder-err');
  const btn = document.getElementById('btnPreorder');
  btn.disabled = true; btn.textContent = 'Submitting…';
  try {
    await api('add_pre_order', { reservation_id: state.reservationId, items });
    const pd = document.getElementById('preorder-done');
    const db = document.getElementById('booking-done-btn');
    const ps = document.getElementById('preorder-section');
    fadeOut(ps, 260, () => {
      fadeIn(pd, 380);
      setTimeout(() => fadeIn(db, 380), 120);
    });
  } catch(e) {
    showErr('preorder-err', e.message);
    btn.disabled = false; btn.textContent = 'Submit →';
  }
}

function skipPreorder() {
  fadeOut(document.getElementById('preorder-section'), 260, () => {
    fadeIn(document.getElementById('booking-done-btn'), 360);
  });
}

/* ── PANEL ANIMATIONS ──────────────────────────────── */
function fadeIn(el, duration = 420, translateY = 18) {
  if (!el) return;
  el.style.display = '';
  el.style.opacity = '0';
  el.style.transform = `translateY(${translateY}px)`;
  el.style.transition = `opacity ${duration}ms cubic-bezier(.4,0,.2,1), transform ${duration}ms cubic-bezier(.4,0,.2,1)`;
  requestAnimationFrame(() => requestAnimationFrame(() => {
    el.style.opacity = '1';
    el.style.transform = 'none';
    const cleanup = () => { el.style.transition = ''; el.style.opacity = ''; el.style.transform = ''; el.removeEventListener('transitionend', cleanup); };
    el.addEventListener('transitionend', cleanup, { once: true });
  }));
}

function fadeOut(el, duration = 280, cb) {
  if (!el) { if (cb) cb(); return; }
  el.style.transition = `opacity ${duration}ms cubic-bezier(.4,0,.2,1), transform ${duration}ms cubic-bezier(.4,0,.2,1)`;
  el.style.opacity = '0';
  el.style.transform = 'translateY(-12px)';
  const finish = () => { el.style.display = 'none'; el.style.opacity = ''; el.style.transform = ''; el.style.transition = ''; if (cb) cb(); };
  el.addEventListener('transitionend', finish, { once: true });
  setTimeout(finish, duration + 30);
}

/* ── STEP NAVIGATION ───────────────────────────────── */
let _stepping = false;
function goStep(n) {
  if (_stepping) return;
  const prev = document.querySelector('.step-panel.active');
  const doSwitch = () => {
    _stepping = false;
    state.step = n;
    document.querySelectorAll('.step-panel').forEach(p => p.classList.remove('active', 'exiting'));
    document.getElementById('step-panel-' + n)?.classList.add('active');
    document.querySelectorAll('.step-item').forEach((el, i) => {
      el.classList.remove('active', 'done');
      if (i + 1 < n) el.classList.add('done');
      if (i + 1 === n) el.classList.add('active');
    });
    document.querySelectorAll('.step-line').forEach((el, i) => {
      el.classList.toggle('done', i + 1 < n);
    });
  };
  if (prev) {
    _stepping = true;
    prev.classList.add('exiting');
    setTimeout(doSwitch, 270);
  } else { doSwitch(); }
}

/* ═══════════════════════════════════════════════════════
   MANAGE RESERVATION (kept from original)
   ═══════════════════════════════════════════════════════ */
async function lookupReservation() {
  const name  = document.getElementById('lu-name').value.trim();
  const phone = document.getElementById('lu-phone').value.trim();
  hideErr('lu-err');
  if (!name)  { showErr('lu-err', 'Please enter your name.'); return; }
  if (!/^1[3-9]\d{9}$/.test(phone)) { showErr('lu-err', 'Please enter a valid 11-digit phone number.'); return; }
  const btn = document.getElementById('btnLookup');
  btn.disabled = true; btn.textContent = 'Searching…';
  try {
    const res = await api('find_customer_reservation', { customer_name: name, phone });
    if (!res.found) {
      showErr('lu-err', 'No booking found. Please check your name and phone number match what was entered when booking.');
      return;
    }
    renderLookupResult(res);
    fadeOut(document.getElementById('lu-form-panel'), 280, () => {
      fadeIn(document.getElementById('lu-result-panel'), 440);
    });
  } catch(e) {
    showErr('lu-err', e.message);
  } finally {
    btn.disabled = false; btn.textContent = 'Find My Booking';
  }
}

function renderLookupResult(res) {
  const el = document.getElementById('lu-result-content');
  if (res.type === 'private_request') {
    const r = res.request;
    state.lookupData = { type: 'private', id: r.id, name: r.customer_name, phone: r.phone };
    el.innerHTML = `
      <div class="lookup-card">
        <span class="lookup-type-badge private">Private Room Request</span>
        <div class="lookup-info">
          <div class="lookup-row"><span class="lookup-label">Customer</span><span class="lookup-value">${esc(r.customer_name)}</span></div>
          <div class="lookup-row"><span class="lookup-label">Phone</span><span class="lookup-value">${esc(r.phone)}</span></div>
          <div class="lookup-row"><span class="lookup-label">Submitted at</span><span class="lookup-value">${fmtDate(r.created_at)}</span></div>
        </div>
        <div class="lookup-pending-note">⏳ Your private room request is being reviewed. We will contact you soon.</div>
        <div id="lu-cancel-confirm" class="cancel-confirm-panel" style="display:none">
          <p style="color:rgba(255,255,255,.65);font-size:14px;line-height:1.75;margin-bottom:18px">
            ⚠️ Are you sure you want to cancel the private room request? This cannot be undone.
          </p>
          <div id="lu-cancel-err" class="error-msg"></div>
          <div class="btn-row">
            <button class="btn-back" onclick="hideCancelConfirm()">← Keep Request</button>
            <button class="btn-danger" id="btnConfirmCancel" onclick="confirmCancelPrivateRequest()">Cancel Request</button>
          </div>
        </div>
        <div id="lu-action-btns" class="btn-row" style="margin-top:24px;flex-wrap:wrap;gap:10px">
          <button class="btn-back" onclick="resetLookup()">← Back to Search</button>
          <button class="btn-danger" onclick="showCancelConfirm()" style="margin-left:auto">Cancel Request</button>
        </div>
      </div>`;
    return;
  }
  const r = res.reservation;
  const typeNames = { small: 'Standard', medium: 'Medium', large: 'Large', private: 'Private Room' };
  state.lookupData = { id: r.id, oldName: r.customer_name, oldPhone: r.phone };
  const preOrderHtml = res.pre_orders?.length
    ? `<div class="lookup-preorders">
         <div style="font-size:12px;color:rgba(255,255,255,.4);margin-bottom:8px">Pre-orders</div>
         ${res.pre_orders.map(p => `<span class="preorder-tag">${esc(p.item_name)} ×${p.quantity}</span>`).join('')}
       </div>` : '';
  el.innerHTML = `
    <div class="lookup-card">
      <span class="lookup-type-badge ${r.table_type}">${typeNames[r.table_type] || r.table_type}</span>
      <div class="lookup-info">
        <div class="lookup-row"><span class="lookup-label">Table No.</span><span class="lookup-value"><strong style="color:var(--gold)">${esc(r.table_number)}</strong></span></div>
        <div class="lookup-row"><span class="lookup-label">Name</span><span class="lookup-value" id="lu-disp-name">${esc(r.customer_name)}</span></div>
        <div class="lookup-row"><span class="lookup-label">Phone</span><span class="lookup-value" id="lu-disp-phone">${esc(r.phone)}</span></div>
        ${r.party_size ? `<div class="lookup-row"><span class="lookup-label">Guests</span><span class="lookup-value">${r.party_size} guests</span></div>` : ''}
        <div class="lookup-row"><span class="lookup-label">Booked at</span><span class="lookup-value" style="font-size:13px">${fmtDate(r.created_at)}</span></div>
      </div>
      ${preOrderHtml}
      <div id="lu-edit-form" style="display:none;margin-top:24px">
        <div style="font-size:14px;font-weight:700;color:var(--gold);margin-bottom:16px">Modify Booking</div>
        <div class="res-form">
          <div class="form-row">
            <label class="form-label">New Name</label>
            <input class="form-input" id="lu-edit-name" type="text" maxlength="30" placeholder="New Name">
          </div>
          <div class="form-row">
            <label class="form-label">New Phone</label>
            <input class="form-input" id="lu-edit-phone" type="tel" maxlength="11" placeholder="11-digit phone">
          </div>
        </div>
        <div id="lu-edit-err" class="error-msg"></div>
        <div class="btn-row">
          <button class="btn-back" onclick="cancelEditLookup()">← Cancel</button>
          <button class="btn-submit" onclick="submitEditLookup()">Save</button>
        </div>
      </div>
      <div id="lu-edit-success" style="display:none" class="lookup-pending-note">✅ Booking updated.</div>
      <div id="lu-cancel-confirm" class="cancel-confirm-panel" style="display:none">
        <p style="color:rgba(255,255,255,.65);font-size:14px;line-height:1.75;margin-bottom:18px">
          ⚠️ Are you sure you want to cancel table <strong style="color:var(--gold)">${esc(r.table_number)}</strong>? This cannot be undone.
        </p>
        <div id="lu-cancel-err" class="error-msg"></div>
        <div class="btn-row">
          <button class="btn-back" onclick="hideCancelConfirm()">← Keep Booking</button>
          <button class="btn-danger" id="btnConfirmCancel" onclick="confirmCancelReservation()">Confirm Cancel</button>
        </div>
      </div>
      <div id="lu-action-btns" class="btn-row" style="margin-top:24px;flex-wrap:wrap;gap:10px">
        <button class="btn-back" onclick="resetLookup()">← Back to Search</button>
        <button class="btn-submit" id="btnEditRes" onclick="showEditLookup()">Modify Booking</button>
        <button class="btn-danger" onclick="showCancelConfirm()" style="margin-left:auto">Cancel Booking</button>
      </div>
    </div>`;
}

function showEditLookup() {
  if (!state.lookupData) return;
  document.getElementById('lu-edit-name').value  = state.lookupData.oldName;
  document.getElementById('lu-edit-phone').value = state.lookupData.oldPhone;
  document.getElementById('lu-edit-success').style.display = 'none';
  hideErr('lu-edit-err');
  fadeOut(document.getElementById('lu-action-btns'), 220, () => {
    fadeIn(document.getElementById('lu-edit-form'), 360);
  });
}

function cancelEditLookup() {
  fadeOut(document.getElementById('lu-edit-form'), 220, () => {
    fadeIn(document.getElementById('lu-action-btns'), 360);
  });
}

async function submitEditLookup() {
  if (!state.lookupData) return;
  const newName  = document.getElementById('lu-edit-name').value.trim();
  const newPhone = document.getElementById('lu-edit-phone').value.trim();
  hideErr('lu-edit-err');
  if (!newName)  { showErr('lu-edit-err', 'Please enter your name.'); return; }
  if (!/^1[3-9]\d{9}$/.test(newPhone)) { showErr('lu-edit-err', 'Please enter a valid 11-digit phone number.'); return; }
  const { id, oldName, oldPhone } = state.lookupData;
  try {
    await api('customer_update_reservation', { id, new_name: newName, new_phone: newPhone, old_name: oldName, old_phone: oldPhone });
    const nameEl  = document.getElementById('lu-disp-name');
    const phoneEl = document.getElementById('lu-disp-phone');
    if (nameEl) nameEl.textContent = newName;
    if (phoneEl) phoneEl.textContent = newPhone;
    state.lookupData.oldName = newName;
    state.lookupData.oldPhone = newPhone;
    const es = document.getElementById('lu-edit-success');
    fadeOut(document.getElementById('lu-edit-form'), 220, () => {
      fadeIn(es, 380);
      fadeIn(document.getElementById('lu-action-btns'), 380);
    });
    document.getElementById('btnEditRes').textContent = 'Continue Editing';
  } catch(e) { showErr('lu-edit-err', e.message); }
}

function resetLookup() {
  const resultPanel = document.getElementById('lu-result-panel');
  const formPanel = document.getElementById('lu-form-panel');
  const nameEl = document.getElementById('lu-name');
  const phoneEl = document.getElementById('lu-phone');
  if (nameEl) nameEl.value = '';
  if (phoneEl) phoneEl.value = '';
  state.lookupData = null;
  hideErr('lu-err');
  const doReset = () => {
    const rc = document.getElementById('lu-result-content');
    if (rc) rc.innerHTML = '';
    if (resultPanel) resultPanel.style.display = 'none';
    fadeIn(formPanel, 420);
  };
  if (resultPanel && resultPanel.style.display !== 'none') {
    fadeOut(resultPanel, 280, doReset);
  } else { doReset(); }
}

/* ── CANCEL ────────────────────────────────────────── */
function showCancelConfirm() {
  fadeOut(document.getElementById('lu-action-btns'), 200, () => {
    fadeIn(document.getElementById('lu-cancel-confirm'), 360);
  });
}
function hideCancelConfirm() {
  fadeOut(document.getElementById('lu-cancel-confirm'), 200, () => {
    const ab = document.getElementById('lu-action-btns');
    document.getElementById('lu-cancel-err')?.classList.remove('show');
    fadeIn(ab, 360);
  });
}

async function confirmCancelReservation() {
  if (!state.lookupData) return;
  const btn = document.getElementById('btnConfirmCancel');
  if (btn) { btn.disabled = true; btn.textContent = 'Cancelling…'; }
  hideErr('lu-cancel-err');
  try {
    await api('customer_cancel_reservation', { id: state.lookupData.id, customer_name: state.lookupData.oldName, phone: state.lookupData.oldPhone });
    state.lookupData = null;
    const el = document.getElementById('lu-result-content');
    if (el) {
      fadeOut(el.querySelector('.lookup-card'), 260, () => {
        el.innerHTML = `<div class="lookup-card" style="text-align:center;padding:52px 32px">
          <div style="font-size:60px;margin-bottom:20px;filter:drop-shadow(0 0 20px rgba(201,168,76,.3))">✅</div>
          <h3 style="font-family:'Cormorant Garamond',Georgia,serif;font-size:26px;color:var(--white);font-weight:300;margin-bottom:12px">Booking Cancelled</h3>
          <p style="color:rgba(255,255,255,.55);font-size:14px;line-height:1.85;max-width:360px;margin:0 auto">Your booking has been successfully cancelled. We look forward to welcoming you again.</p>
          <div class="btn-row" style="justify-content:center;margin-top:32px">
            <button class="btn-submit" onclick="resetLookup()">Back to Search</button>
          </div>
        </div>`;
        fadeIn(el.querySelector('.lookup-card'), 420);
      });
    }
  } catch(e) {
    if (btn) { btn.disabled = false; btn.textContent = 'Confirm Cancel'; }
    showErr('lu-cancel-err', e.message);
  }
}

async function confirmCancelPrivateRequest() {
  if (!state.lookupData) return;
  const btn = document.getElementById('btnConfirmCancel');
  if (btn) { btn.disabled = true; btn.textContent = 'Cancelling…'; }
  hideErr('lu-cancel-err');
  try {
    await api('customer_cancel_private_request', { id: state.lookupData.id, customer_name: state.lookupData.name, phone: state.lookupData.phone });
    state.lookupData = null;
    const el = document.getElementById('lu-result-content');
    if (el) {
      fadeOut(el.querySelector('.lookup-card'), 260, () => {
        el.innerHTML = `<div class="lookup-card" style="text-align:center;padding:52px 32px">
          <div style="font-size:60px;margin-bottom:20px;filter:drop-shadow(0 0 20px rgba(201,168,76,.3))">✅</div>
          <h3 style="font-family:'Cormorant Garamond',Georgia,serif;font-size:26px;color:var(--white);font-weight:300;margin-bottom:12px">Request Cancelled</h3>
          <p style="color:rgba(255,255,255,.55);font-size:14px;line-height:1.85;max-width:360px;margin:0 auto">The private room request has been withdrawn.</p>
          <div class="btn-row" style="justify-content:center;margin-top:32px">
            <button class="btn-submit" onclick="resetLookup()">Back to Search</button>
          </div>
        </div>`;
        fadeIn(el.querySelector('.lookup-card'), 420);
      });
    }
  } catch(e) {
    if (btn) { btn.disabled = false; btn.textContent = 'Cancel Request'; }
    showErr('lu-cancel-err', e.message);
  }
}

/* ═══════════════════════════════════════════════════════
   HELPERS
   ═══════════════════════════════════════════════════════ */
function esc(str) {
  return String(str ?? '').replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c]);
}

function fmtDate(str) {
  if (!str) return '—';
  const d = new Date(str);
  return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')} ${String(d.getHours()).padStart(2,'0')}:${String(d.getMinutes()).padStart(2,'0')}`;
}

function showErr(id, msg) {
  const el = document.getElementById(id);
  if (el) { el.textContent = msg; el.classList.add('show'); }
}
function hideErr(id) {
  const el = document.getElementById(id);
  if (el) el.classList.remove('show');
}

/* ═══════════════════════════════════════════════════════
   VISUAL EFFECTS (kept from original)
   ═══════════════════════════════════════════════════════ */
function initVisualEffects() {
  initNavScroll();
  initScrollReveal();
  initCardTilt();
  initStatCounters();
  initHeroCursor();
}

function initNavScroll() {
  const nav = document.getElementById('mainNav');
  if (!nav) return;
  window.addEventListener('scroll', () => { nav.classList.toggle('scrolled', window.scrollY > 72); }, { passive: true });
}

function initScrollReveal() {
  const els = document.querySelectorAll('.reveal');
  if (!els.length) return;
  const obs = new IntersectionObserver((entries) => {
    entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('revealed'); obs.unobserve(e.target); } });
  }, { threshold: 0.14 });
  els.forEach(el => obs.observe(el));
  document.querySelectorAll('.menu-card, .restaurant-card').forEach((card, i) => {
    card.classList.add('reveal');
    card.style.transitionDelay = `${i * 0.06}s`;
    obs.observe(card);
  });
}

function initCardTilt() {
  const applyTilt = (selector, maxRY, maxRX) => {
    document.querySelectorAll(selector).forEach(card => {
      card.addEventListener('mouseenter', () => { card.style.transition = 'transform .1s ease, box-shadow .35s cubic-bezier(.4,0,.2,1)'; });
      card.addEventListener('mousemove', e => {
        const r = card.getBoundingClientRect();
        const x = (e.clientX - r.left - r.width / 2) / (r.width / 2);
        const y = (e.clientY - r.top - r.height / 2) / (r.height / 2);
        card.style.setProperty('--card-ry', `${x * maxRY}deg`);
        card.style.setProperty('--card-rx', `${-y * maxRX}deg`);
        card.style.setProperty('--card-tz', '8px');
        const pct = ((e.clientX - r.left) / r.width * 100).toFixed(1);
        const pct2 = ((e.clientY - r.top) / r.height * 100).toFixed(1);
        card.style.setProperty('--mx', `${pct}%`);
        card.style.setProperty('--my', `${pct2}%`);
      });
      card.addEventListener('mouseleave', () => {
        card.style.transition = 'transform .55s cubic-bezier(.4,0,.2,1), box-shadow .35s cubic-bezier(.4,0,.2,1)';
        card.style.setProperty('--card-ry', '0deg');
        card.style.setProperty('--card-rx', '0deg');
        card.style.setProperty('--card-tz', '0px');
      });
    });
  };
  applyTilt('.restaurant-card', 7, 4);
  applyTilt('.table-type-card', 9, 5);
}

function initStatCounters() {
  const items = document.querySelectorAll('.stat-num[data-count]');
  if (!items.length) return;
  const obs = new IntersectionObserver((entries) => {
    entries.forEach(e => {
      if (!e.isIntersecting) return;
      const el = e.target;
      const target = el.dataset.count;
      const suffix = target.replace(/[0-9]/g, '');
      const num = parseInt(target, 10);
      el.textContent = '0' + suffix;
      const dur = 1800;
      const start = performance.now();
      function tick(now) {
        const t = Math.min((now - start) / dur, 1);
        const ease = 1 - Math.pow(1 - t, 3);
        el.textContent = Math.round(ease * num) + suffix;
        if (t < 1) requestAnimationFrame(tick);
      }
      requestAnimationFrame(tick);
      obs.unobserve(el);
    });
  }, { threshold: 0.6 });
  items.forEach(el => obs.observe(el));
}

function initHeroCursor() {
  const hero = document.querySelector('.hero');
  const glow = document.getElementById('heroCursorGlow');
  if (!hero || !glow) return;
  hero.addEventListener('mousemove', e => {
    const r = hero.getBoundingClientRect();
    const x = ((e.clientX - r.left) / r.width * 100).toFixed(1);
    const y = ((e.clientY - r.top) / r.height * 100).toFixed(1);
    glow.style.setProperty('--mx', `${x}%`);
    glow.style.setProperty('--my', `${y}%`);
  }, { passive: true });
}
