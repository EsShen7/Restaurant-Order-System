/* ── STATE ─────────────────────────────────────────── */
const state = {
  step: 1,
  tableType: null,
  availability: null,
  reservationId: null,
  menuItems: [],
  lookupData: null,
};

/* ── INIT ──────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('startBtn')?.addEventListener('click', scrollToReservation);
  document.getElementById('navBookBtn')?.addEventListener('click', scrollToReservation);
  document.getElementById('navManageBtn')?.addEventListener('click', scrollToManage);
  loadAvailability();
  loadMenu();
});

function scrollToReservation() {
  document.getElementById('reservation').scrollIntoView({ behavior: 'smooth' });
}
function scrollToManage() {
  document.getElementById('manage').scrollIntoView({ behavior: 'smooth' });
}

/* ── AVAILABILITY ──────────────────────────────────── */
async function loadAvailability() {
  try {
    const res = await api('get_availability');
    state.availability = res;
    renderTableCards(res.types);
  } catch (e) {
    console.warn('Availability load failed', e);
  }
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
    small:   'Standard Table (1–2 guests)',
    medium:  'Medium Table (3–4 guests)',
    large:   'Large Table (5–8 guests)',
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
  if (!/^1[3-9]\d{9}$/.test(phone)) { showErr('step2-err', 'Please enter a valid 11-digit mobile number.'); return; }

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
    showErr('step2-err', e.message || 'Submission failed. Please try again.');
  } finally {
    btn.disabled = false; btn.textContent = 'Confirm Booking';
  }
}

function showResult(type, name, msg) {
  document.getElementById('result-icon').textContent  = type === 'success' ? '✅' : '📋';
  document.getElementById('result-title').textContent = type === 'success'
    ? `Reservation confirmed, ${name}!` : `Request received, ${name}!`;
  document.getElementById('result-msg').textContent   = msg;
  const noteEl = document.getElementById('result-note');
  noteEl.textContent  = type === 'success'
    ? 'To cancel or modify your reservation, please call us in advance. Thank you for your understanding.'
    : 'Private room bookings require staff confirmation. We will contact you shortly — please keep your phone available.';
  noteEl.style.display = '';

  const preorderSection = document.getElementById('preorder-section');
  const doneBtnWrap     = document.getElementById('booking-done-btn');
  const preorderDone    = document.getElementById('preorder-done');
  preorderDone.style.display = 'none';

  if (type === 'success' && state.reservationId) {
    preorderSection.style.display = '';
    doneBtnWrap.style.display     = 'none';
    renderPreorderMenu();
  } else {
    preorderSection.style.display = 'none';
    doneBtnWrap.style.display     = '';
  }
}

function resetReservation() {
  state.tableType = null;
  state.reservationId = null;
  document.querySelectorAll('.table-type-card').forEach(c => c.classList.remove('selected'));
  document.getElementById('res-name').value  = '';
  document.getElementById('res-phone').value = '';
  document.getElementById('btnNext1').disabled = true;
  document.getElementById('preorder-section').style.display = 'none';
  document.getElementById('booking-done-btn').style.display = 'none';
  document.getElementById('preorder-done').style.display    = 'none';
  // Reset qty counters
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
    grid.innerHTML = '<p style="color:rgba(255,255,255,.4);font-size:13px">Menu unavailable.</p>';
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
  if (!state.reservationId) { showErr('preorder-err', 'Reservation ID missing. Please try again.'); return; }
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
    document.getElementById('preorder-section').style.display = 'none';
    document.getElementById('preorder-done').style.display    = '';
    document.getElementById('booking-done-btn').style.display = '';
  } catch(e) {
    showErr('preorder-err', e.message);
    btn.disabled = false; btn.textContent = 'Submit Pre-Order →';
  }
}

function skipPreorder() {
  document.getElementById('preorder-section').style.display = 'none';
  document.getElementById('booking-done-btn').style.display = '';
}

/* ── STEP NAVIGATION ───────────────────────────────── */
function goStep(n) {
  state.step = n;
  document.querySelectorAll('.step-panel').forEach(p => p.classList.remove('active'));
  document.getElementById('step-panel-' + n)?.classList.add('active');
  document.querySelectorAll('.step-item').forEach((el, i) => {
    el.classList.remove('active', 'done');
    if (i + 1 < n) el.classList.add('done');
    if (i + 1 === n) el.classList.add('active');
  });
  document.querySelectorAll('.step-line').forEach((el, i) => {
    el.classList.toggle('done', i + 1 < n);
  });
}

/* ── MANAGE RESERVATION ────────────────────────────── */
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
      showErr('lu-err', 'No reservation found. Please check that your name and phone number exactly match what you entered when booking.');
      return;
    }
    renderLookupResult(res);
    document.getElementById('lu-form-panel').style.display   = 'none';
    document.getElementById('lu-result-panel').style.display = '';
  } catch(e) {
    showErr('lu-err', e.message);
  } finally {
    btn.disabled = false; btn.textContent = 'Find My Reservation';
  }
}

function renderLookupResult(res) {
  const el = document.getElementById('lu-result-content');

  if (res.type === 'private_request') {
    const r = res.request;
    el.innerHTML = `
      <div class="lookup-card">
        <span class="lookup-type-badge private">Private Room Request</span>
        <div class="lookup-info">
          <div class="lookup-row"><span class="lookup-label">Guest</span><span class="lookup-value">${esc(r.customer_name)}</span></div>
          <div class="lookup-row"><span class="lookup-label">Phone</span><span class="lookup-value">${esc(r.phone)}</span></div>
          <div class="lookup-row"><span class="lookup-label">Submitted</span><span class="lookup-value">${fmtDate(r.created_at)}</span></div>
        </div>
        <div class="lookup-pending-note">
          ⏳ Your private room request is currently being reviewed by our staff.
          We will contact you shortly to confirm the details. Please keep your phone available.
        </div>
        <div class="btn-row" style="margin-top:24px">
          <button class="btn-back" onclick="resetLookup()">← Back to Search</button>
        </div>
      </div>`;
    state.lookupData = null;
    return;
  }

  const r = res.reservation;
  const typeNames = { small: 'Standard Table', medium: 'Medium Table', large: 'Large Table', private: 'Private Room' };
  state.lookupData = { id: r.id, oldName: r.customer_name, oldPhone: r.phone };

  const preOrderHtml = res.pre_orders?.length
    ? `<div class="lookup-preorders">
         <div style="font-size:12px;color:rgba(255,255,255,.4);margin-bottom:8px;letter-spacing:.05em">PRE-ORDERS</div>
         ${res.pre_orders.map(p => `<span class="preorder-tag">${esc(p.item_name)} ×${p.quantity}</span>`).join('')}
       </div>` : '';

  el.innerHTML = `
    <div class="lookup-card">
      <span class="lookup-type-badge ${r.table_type}">${typeNames[r.table_type] || r.table_type}</span>
      <div class="lookup-info">
        <div class="lookup-row"><span class="lookup-label">Table</span><span class="lookup-value"><strong style="color:var(--gold)">${esc(r.table_number)}</strong></span></div>
        <div class="lookup-row"><span class="lookup-label">Guest Name</span><span class="lookup-value" id="lu-disp-name">${esc(r.customer_name)}</span></div>
        <div class="lookup-row"><span class="lookup-label">Phone</span><span class="lookup-value" id="lu-disp-phone">${esc(r.phone)}</span></div>
        ${r.party_size ? `<div class="lookup-row"><span class="lookup-label">Party Size</span><span class="lookup-value">${r.party_size} guests</span></div>` : ''}
        <div class="lookup-row"><span class="lookup-label">Booked At</span><span class="lookup-value" style="font-size:13px">${fmtDate(r.created_at)}</span></div>
      </div>
      ${preOrderHtml}

      <div id="lu-edit-form" style="display:none;margin-top:24px">
        <div style="font-size:14px;font-weight:600;color:var(--gold);margin-bottom:16px">Edit Your Booking</div>
        <div class="res-form">
          <div class="form-row">
            <label class="form-label">New Name</label>
            <input class="form-input" id="lu-edit-name" type="text" maxlength="30" placeholder="New guest name">
          </div>
          <div class="form-row">
            <label class="form-label">New Phone Number</label>
            <input class="form-input" id="lu-edit-phone" type="tel" maxlength="11" placeholder="11-digit mobile number">
          </div>
        </div>
        <div id="lu-edit-err" class="error-msg"></div>
        <div class="btn-row">
          <button class="btn-back" onclick="cancelEditLookup()">Cancel</button>
          <button class="btn-submit" onclick="submitEditLookup()">Save Changes</button>
        </div>
      </div>

      <div id="lu-edit-success" style="display:none" class="lookup-pending-note">
        ✅ Your reservation has been updated successfully.
      </div>

      <div id="lu-action-btns" class="btn-row" style="margin-top:24px">
        <button class="btn-back" onclick="resetLookup()">← Back to Search</button>
        <button class="btn-submit" id="btnEditRes" onclick="showEditLookup()">Edit My Booking</button>
      </div>
    </div>`;
}

function showEditLookup() {
  if (!state.lookupData) return;
  document.getElementById('lu-edit-name').value  = state.lookupData.oldName;
  document.getElementById('lu-edit-phone').value = state.lookupData.oldPhone;
  document.getElementById('lu-edit-form').style.display    = '';
  document.getElementById('lu-action-btns').style.display  = 'none';
  document.getElementById('lu-edit-success').style.display = 'none';
  hideErr('lu-edit-err');
}

function cancelEditLookup() {
  document.getElementById('lu-edit-form').style.display   = 'none';
  document.getElementById('lu-action-btns').style.display = '';
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
    if (nameEl)  nameEl.textContent  = newName;
    if (phoneEl) phoneEl.textContent = newPhone;
    state.lookupData.oldName  = newName;
    state.lookupData.oldPhone = newPhone;
    document.getElementById('lu-edit-form').style.display    = 'none';
    document.getElementById('lu-edit-success').style.display = '';
    document.getElementById('lu-action-btns').style.display  = '';
    document.getElementById('btnEditRes').textContent = 'Edit Again';
  } catch(e) {
    showErr('lu-edit-err', e.message);
  }
}

function resetLookup() {
  document.getElementById('lu-form-panel').style.display   = '';
  document.getElementById('lu-result-panel').style.display = 'none';
  document.getElementById('lu-name').value  = '';
  document.getElementById('lu-phone').value = '';
  document.getElementById('lu-result-content').innerHTML = '';
  state.lookupData = null;
  hideErr('lu-err');
}

/* ── HELPERS ───────────────────────────────────────── */
async function api(action, body = {}) {
  const params = new URLSearchParams({ action });
  const resp   = await fetch(`api.php?${params}`, {
    method:  'POST',
    headers: { 'Content-Type': 'application/json' },
    body:    JSON.stringify(body),
  });
  const json = await resp.json();
  if (!json.success) throw new Error(json.error || 'Request failed.');
  return json;
}

function esc(str) {
  return String(str ?? '').replace(/[&<>"']/g, c =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])
  );
}

function fmtDate(str) {
  if (!str) return '—';
  const d = new Date(str);
  return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')} `
       + `${String(d.getHours()).padStart(2,'0')}:${String(d.getMinutes()).padStart(2,'0')}`;
}

function showErr(id, msg) {
  const el = document.getElementById(id);
  if (el) { el.textContent = msg; el.classList.add('show'); }
}
function hideErr(id) {
  const el = document.getElementById(id);
  if (el) el.classList.remove('show');
}
