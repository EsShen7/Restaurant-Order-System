/* ═══════════════════════════════════════════════════
   STATE
   ═══════════════════════════════════════════════════ */
const S = {
  user: null,
  currentPanel: 'reservations',
  reservationFilter: 'all',
  reservations: [],
  pendingRooms: [],
  employees: [],
  selectedRoomReq: null,
  selectedRoomTable: null,
  statsDrawerOpen: false,
  shownNotifIds: new Set(),
  notifAlertQueue: [],
  notifAlertOpen: false,
};

/* ═══════════════════════════════════════════════════
   BOOT
   ═══════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', () => {
  const page = document.body.dataset.page;
  if (page === 'login') initLogin();
  else initApp();
});

/* ── LOGIN ─────────────────────────────────────────── */
function initLogin() {
  document.getElementById('loginForm').addEventListener('submit', async e => {
    e.preventDefault();
    const un = document.getElementById('loginUser').value.trim();
    const pw = document.getElementById('loginPass').value;
    const errEl = document.getElementById('loginErr');
    errEl.textContent = '';
    try {
      const res = await api('login', { username: un, password: pw });
      if (res.notices?.length) sessionStorage.setItem('login_notices', JSON.stringify(res.notices));
      window.location.href = 'employee.html';
    } catch (e) {
      errEl.textContent = e.message;
    }
  });
}

/* ── MAIN APP ──────────────────────────────────────── */
async function initApp() {
  try {
    const res = await api('check_auth');
    if (!res.logged_in) { window.location.href = 'login.html'; return; }
    S.user = res;
  } catch (e) {
    window.location.href = 'login.html'; return;
  }

  renderUser();
  bindNav();
  bindTopbar();
  bindStats();
  loadPanel('reservations');
  updatePendingBadge();
  initNotifications();

  const notices = JSON.parse(sessionStorage.getItem('login_notices') || '[]');
  sessionStorage.removeItem('login_notices');
  if (notices.length) renderNoticesBar(notices);

  setInterval(updatePendingBadge, 30000);
  setInterval(pollNotifications, 20000);
}

function renderUser() {
  const name = S.user.nickname || S.user.username;
  document.getElementById('userDisplayName').textContent = name;
  document.getElementById('userAvatar').textContent = name[0].toUpperCase();
  if (S.user.is_admin) {
    document.getElementById('adminNavItem')?.classList.remove('hidden');
  }
  updateUnreadBadge(S.user.unread);
}

function bindNav() {
  document.querySelectorAll('.nav-item[data-panel]').forEach(el => {
    el.addEventListener('click', () => loadPanel(el.dataset.panel));
  });
}

function bindTopbar() {
  document.getElementById('notifBtn').addEventListener('click', toggleNotifications);
  document.getElementById('avatarBtn').addEventListener('click', () => loadPanel('profile'));
  document.getElementById('logoutBtn').addEventListener('click', logout);
  document.addEventListener('click', e => {
    if (!e.target.closest('#notifBtn') && !e.target.closest('#notifDropdown')) {
      document.getElementById('notifDropdown').classList.remove('show');
    }
  });
}

function bindStats() {
  document.getElementById('statsToggle').addEventListener('click', toggleStatsDrawer);
  document.getElementById('statsClose').addEventListener('click', () => {
    S.statsDrawerOpen = false;
    document.getElementById('statsDrawer').classList.remove('open');
    document.getElementById('statsToggle').textContent = '◀';
  });
}

/* ── PANEL ROUTER ──────────────────────────────────── */
function loadPanel(name) {
  S.currentPanel = name;
  document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
  document.getElementById('panel-' + name)?.classList.add('active');
  document.querySelectorAll('.nav-item').forEach(el => {
    el.classList.toggle('active', el.dataset.panel === name);
  });
  const titles = {
    reservations: 'Reservations',
    admin:        'Staff Management',
    profile:      'My Profile',
  };
  document.getElementById('panelTitle').textContent = titles[name] || 'Cloud Pavilion Staff System';

  if (name === 'reservations') loadReservations();
  if (name === 'admin')        loadEmployees();
  if (name === 'profile')      loadProfile();
}

/* ─────────────────────────────────────────────────────
   RESERVATIONS PANEL
   ───────────────────────────────────────────────────── */
async function loadReservations() {
  const params = { type: S.reservationFilter === 'all' ? '' : S.reservationFilter };
  const search = document.getElementById('resSearch')?.value.trim();
  if (search) params.search = search;
  try {
    const res = await api('get_reservations', params);
    S.reservations = res.reservations;
    renderReservationsTable(S.reservations);
  } catch (e) { showToast(e.message, 'error'); }
}

function renderReservationsTable(rows) {
  const tbody = document.getElementById('resTbody');
  if (!rows.length) {
    tbody.innerHTML = `<tr><td colspan="8"><div class="empty-state"><div class="empty-icon">📋</div><p>No reservations found.</p></div></td></tr>`;
    return;
  }
  const typeNames = { small: 'Standard', medium: 'Medium', large: 'Large', private: 'Private' };
  tbody.innerHTML = rows.map(r => `
    <tr>
      <td><span class="tag tag-${r.table_type}">${typeNames[r.table_type] || r.table_type}</span></td>
      <td><strong>${r.table_number}</strong></td>
      <td>${esc(r.customer_name)}</td>
      <td>${esc(r.phone)}</td>
      <td>${r.party_size ? r.party_size + ' guests' : '—'}</td>
      <td>${r.pre_order_count > 0
        ? `<button class="act-btn act-btn-view" onclick="viewPreOrders(${r.id})">🍽️ ${r.pre_order_count}</button>`
        : '<span style="color:var(--muted);font-size:12px">—</span>'}</td>
      <td style="font-size:12px;color:var(--muted)">${fmtDate(r.created_at)}</td>
      <td>
        <button class="act-btn act-btn-edit"   onclick="openEditRes(${r.id})">Edit</button>
        <button class="act-btn act-btn-delete" onclick="deleteRes(${r.id})">Delete</button>
      </td>
    </tr>
  `).join('');
}

function setResFilter(type) {
  S.reservationFilter = type;
  document.querySelectorAll('.filter-btn').forEach(b => b.classList.toggle('active', b.dataset.type === type));
  loadReservations();
}

function openAddRes() {
  populateTableSelect('addResTable', S.reservationFilter === 'all' ? null : S.reservationFilter);
  openModal('modalAddRes');
}

async function populateTableSelect(selectId, typeFilter) {
  const sel = document.getElementById(selectId);
  sel.innerHTML = '<option value="">-- Loading --</option>';
  try {
    const ares = await api('get_available_tables', { type: typeFilter || '' });
    sel.innerHTML = '<option value="">-- Select a table --</option>';
    const typeLabel = { small: 'Standard', medium: 'Medium', large: 'Large', private: 'Private Room' };
    const groups = {};
    for (const t of ares.tables) {
      if (!groups[t.type]) groups[t.type] = [];
      groups[t.type].push(t);
    }
    for (const [type, tables] of Object.entries(groups)) {
      const grp = document.createElement('optgroup');
      grp.label = typeLabel[type] || type;
      for (const t of tables) {
        const o = document.createElement('option');
        o.value = t.id;
        o.textContent = `${t.table_number} (${t.min_capacity}–${t.max_capacity} guests)`;
        grp.appendChild(o);
      }
      sel.appendChild(grp);
    }
    if (!ares.tables.length) sel.innerHTML = '<option value="">No tables available</option>';
  } catch(e) { sel.innerHTML = '<option value="">Failed to load</option>'; }
}

async function submitAddRes() {
  const tableId = document.getElementById('addResTable').value;
  const name    = document.getElementById('addResName').value.trim();
  const phone   = document.getElementById('addResPhone').value.trim();
  const party   = document.getElementById('addResParty').value;
  const notes   = document.getElementById('addResNotes').value.trim();

  clearFormErrors('modalAddRes');
  if (!tableId) { setFieldErr('addResTable', 'Please select a table.'); return; }
  if (!name)    { setFieldErr('addResName',  'Please enter the guest name.'); return; }
  if (!/^1[3-9]\d{9}$/.test(phone)) { setFieldErr('addResPhone', 'Invalid phone number format.'); return; }

  try {
    await api('add_reservation', { table_id: tableId, customer_name: name, phone, party_size: party, notes });
    closeModal('modalAddRes');
    loadReservations();
    showToast('Reservation added successfully.');
  } catch(e) { showToast(e.message, 'error'); }
}

function openEditRes(id) {
  const r = S.reservations.find(x => x.id === id);
  if (!r) return;
  document.getElementById('editResId').value    = id;
  document.getElementById('editResName').value  = r.customer_name;
  document.getElementById('editResPhone').value = r.phone;
  document.getElementById('editResNotes').value = r.notes || '';
  openModal('modalEditRes');
}

async function submitEditRes() {
  const id    = document.getElementById('editResId').value;
  const name  = document.getElementById('editResName').value.trim();
  const phone = document.getElementById('editResPhone').value.trim();
  const notes = document.getElementById('editResNotes').value.trim();

  clearFormErrors('modalEditRes');
  if (!name)  { setFieldErr('editResName',  'Guest name cannot be empty.'); return; }
  if (phone && !/^1[3-9]\d{9}$/.test(phone)) { setFieldErr('editResPhone', 'Invalid phone number format.'); return; }

  try {
    await api('update_reservation', { id, customer_name: name, phone, notes });
    closeModal('modalEditRes');
    loadReservations();
    showToast('Changes saved.');
  } catch(e) { showToast(e.message, 'error'); }
}

async function deleteRes(id) {
  if (!confirm('Delete this reservation? This cannot be undone.')) return;
  try {
    await api('delete_reservation', { id });
    loadReservations();
    showToast('Reservation deleted.');
  } catch(e) { showToast(e.message, 'error'); }
}

/* ─────────────────────────────────────────────────────
   PENDING ROOMS (FAB button)
   ───────────────────────────────────────────────────── */
async function updatePendingBadge() {
  try {
    const res = await api('check_auth');
    const cnt = res.pending_rooms || 0;
    const badge = document.getElementById('pendingBadge');
    if (badge) { badge.textContent = cnt; badge.style.display = cnt ? '' : 'none'; }
    updateUnreadBadge(res.unread);
  } catch(e) {}
}

function openPendingRooms() {
  loadPendingRooms();
  openModal('modalPendingRooms');
}

async function loadPendingRooms() {
  try {
    const res = await api('get_pending_rooms');
    S.pendingRooms = res.requests;
    renderPendingRooms(res.requests);
  } catch(e) { showToast(e.message, 'error'); }
}

function renderPendingRooms(requests) {
  const el = document.getElementById('pendingRoomsList');
  if (!requests.length) {
    el.innerHTML = `<div class="empty-state"><div class="empty-icon">✅</div><p>No pending private room requests.</p></div>`;
    return;
  }
  const statusLabel = { pending: 'Pending', confirmed: 'Confirmed', rejected: 'Rejected' };
  el.innerHTML = requests.map(r => `
    <div class="room-request-card">
      <div class="room-card-head">
        <div>
          <div class="room-card-name">${esc(r.customer_name)}</div>
          <div class="room-card-phone">📞 ${esc(r.phone)}</div>
        </div>
        <div style="text-align:right">
          <span class="status-badge status-${r.status}">${statusLabel[r.status] || r.status}</span>
          <div class="room-card-time">${fmtDate(r.created_at)}</div>
        </div>
      </div>
      ${r.status === 'pending' ? `
        <div style="display:flex;gap:8px;margin-top:12px">
          <button class="btn-primary" style="font-size:13px;padding:8px 18px" onclick="openProcessRoom(${r.id})">Enter party size after contact</button>
          <button class="btn-secondary" style="font-size:13px;padding:8px 18px" onclick="rejectRoom(${r.id})">Cannot arrange</button>
        </div>
      ` : ''}
    </div>
  `).join('');
}

function openProcessRoom(reqId) {
  S.selectedRoomReq = S.pendingRooms.find(r => r.id === reqId);
  S.selectedRoomTable = null;
  document.getElementById('processReqId').value = reqId;
  document.getElementById('processPartySize').value = '';
  document.getElementById('roomCandidates').innerHTML = '';
  document.getElementById('processCustomerName').value = S.selectedRoomReq.customer_name;
  document.getElementById('processCustomerPhone').value = S.selectedRoomReq.phone;
  document.getElementById('confirmRoomBtn').disabled = true;
  openModal('modalProcessRoom');
}

async function findRooms() {
  const n = parseInt(document.getElementById('processPartySize').value);
  if (!n || n < 1) { showToast('Please enter a valid party size.', 'error'); return; }

  const el = document.getElementById('roomCandidates');
  el.innerHTML = '<div class="loading"><div class="spinner"></div>Searching…</div>';

  try {
    const res = await api('find_rooms', { party_size: n });
    S.selectedRoomTable = null;
    document.getElementById('confirmRoomBtn').disabled = true;
    renderRoomCandidates(res.rooms, res.match_type, res.note);
  } catch(e) { showToast(e.message, 'error'); }
}

function renderRoomCandidates(rooms, matchType, note) {
  const el = document.getElementById('roomCandidates');
  if (!rooms.length) {
    el.innerHTML = `<div class="alert alert-danger">No suitable private room is available for this party size.</div>`;
    return;
  }

  let noteHtml = '';
  if (matchType !== 'exact' && note) noteHtml = `<div class="alert alert-warning" style="margin-bottom:10px">${note}</div>`;

  const matchClass = matchType === 'exact' ? 'match-exact' : 'match-warn';
  const matchLabel = matchType === 'exact' ? 'Exact match' : 'Approximate match';

  el.innerHTML = noteHtml + rooms.map(r => `
    <div class="room-candidate" id="rc-${r.id}" onclick="selectRoomCandidate(${r.id})">
      <div>
        <div class="room-num">${r.table_number}</div>
        <div class="room-cap">${r.min_capacity}–${r.max_capacity} guests</div>
      </div>
      <span class="room-match ${matchClass}">${matchLabel}</span>
    </div>
  `).join('');
}

function selectRoomCandidate(tableId) {
  document.querySelectorAll('.room-candidate').forEach(el => el.classList.remove('selected'));
  document.getElementById('rc-' + tableId)?.classList.add('selected');
  S.selectedRoomTable = tableId;
  document.getElementById('confirmRoomBtn').disabled = false;
}

async function confirmRoom() {
  if (!S.selectedRoomTable || !S.selectedRoomReq) return;
  const party = parseInt(document.getElementById('processPartySize').value);
  const name  = document.getElementById('processCustomerName').value.trim();
  const phone = document.getElementById('processCustomerPhone').value.trim();

  try {
    await api('confirm_room', {
      request_id: S.selectedRoomReq.id,
      table_id:   S.selectedRoomTable,
      party_size: party,
      customer_name: name,
      phone,
    });
    closeModal('modalProcessRoom');
    closeModal('modalPendingRooms');
    updatePendingBadge();
    showToast('Private room assigned successfully.');
    if (S.currentPanel === 'reservations') loadReservations();
  } catch(e) { showToast(e.message, 'error'); }
}

async function rejectRoom(reqId) {
  if (!confirm('Mark this request as "Cannot arrange"?')) return;
  try {
    await api('reject_room', { request_id: reqId });
    loadPendingRooms();
    updatePendingBadge();
    showToast('Request marked as unavailable.');
  } catch(e) { showToast(e.message, 'error'); }
}

/* ─────────────────────────────────────────────────────
   NOTIFICATIONS
   ───────────────────────────────────────────────────── */
function toggleNotifications() {
  const dd = document.getElementById('notifDropdown');
  const showing = dd.classList.toggle('show');
  if (showing) loadNotifications();
}

async function loadNotifications() {
  const listEl = document.getElementById('notifList');
  listEl.innerHTML = '<div class="loading">Loading…</div>';
  try {
    const res = await api('get_notifications');
    updateUnreadBadge(res.unread);
    if (!res.notifications.length) {
      listEl.innerHTML = '<div style="padding:24px;text-align:center;color:var(--muted);font-size:13px">No notifications.</div>';
      return;
    }
    listEl.innerHTML = res.notifications.map(n => `
      <div class="notif-item ${n.is_read ? '' : 'unread'}" onclick="readNotif(${n.id}, this)">
        ${!n.is_read ? '<span class="notif-unread-dot"></span>' : ''}
        <div class="notif-msg">${esc(n.message)}</div>
        <div class="notif-time">${fmtDate(n.created_at)}</div>
      </div>
    `).join('');
  } catch(e) {}
}

async function readNotif(id, el) {
  await api('read_notification', { id });
  el.classList.remove('unread');
  el.querySelector('.notif-unread-dot')?.remove();
}

async function readAllNotifs() {
  await api('read_notification', { id: 0 });
  document.querySelectorAll('.notif-item.unread').forEach(el => {
    el.classList.remove('unread');
    el.querySelector('.notif-unread-dot')?.remove();
  });
  updateUnreadBadge(0);
}

function updateUnreadBadge(n) {
  const badge = document.getElementById('notifBadge');
  if (badge) { badge.textContent = n; badge.style.display = n > 0 ? '' : 'none'; }
}

/* ─────────────────────────────────────────────────────
   STATS DRAWER
   ───────────────────────────────────────────────────── */
function toggleStatsDrawer() {
  S.statsDrawerOpen = !S.statsDrawerOpen;
  document.getElementById('statsDrawer').classList.toggle('open', S.statsDrawerOpen);
  document.getElementById('statsToggle').textContent = S.statsDrawerOpen ? '▶' : '◀';
  if (S.statsDrawerOpen) loadStats();
}

async function loadStats() {
  try {
    const res = await api('get_stats');
    renderStats(res.stats);
  } catch(e) {}
}

function renderStats(stats) {
  const typeNames = {
    small:   'Standard (1–2)',
    medium:  'Medium (3–4)',
    large:   'Large (5–8)',
    private: 'Private Rooms',
  };
  let total = 0, reserved = 0;
  for (const s of Object.values(stats)) { total += s.total; reserved += s.reserved; }
  const overallRate = total > 0 ? Math.round(reserved / total * 100) : 0;

  document.getElementById('statsContent').innerHTML = `
    <div class="drawer-stat-row">
      <div class="drawer-stat-title">Overall Booking Rate</div>
      <div class="drawer-stat-nums">${reserved} / ${total} tables</div>
      <div class="progress"><div class="progress-fill" style="width:${overallRate}%;background:var(--primary-l)"></div></div>
      <div style="font-size:12px;color:var(--muted);margin-top:4px">${overallRate}%</div>
    </div>
    ${Object.entries(stats).map(([type, s]) => `
      <div class="drawer-stat-row">
        <div class="drawer-stat-title">${typeNames[type] || type}</div>
        <div class="drawer-stat-nums">${s.reserved} / ${s.total} &nbsp;·&nbsp; ${s.total - s.reserved} available</div>
        <div class="progress"><div class="progress-fill" style="width:${s.rate}%"></div></div>
        <div style="font-size:12px;color:var(--muted);margin-top:4px">${s.rate}%</div>
      </div>
    `).join('')}
  `;
}

/* ─────────────────────────────────────────────────────
   ADMIN: EMPLOYEE MANAGEMENT
   ───────────────────────────────────────────────────── */
async function loadEmployees(search = '') {
  try {
    const res = await api('list_employees', { search });
    S.employees = res.employees;
    renderEmployeeTable(res.employees);
  } catch(e) { showToast(e.message, 'error'); }
}

function renderEmployeeTable(emps) {
  const tbody = document.getElementById('empTbody');
  if (!emps.length) {
    tbody.innerHTML = `<tr><td colspan="6"><div class="empty-state"><div class="empty-icon">👥</div><p>No staff accounts found.</p></div></td></tr>`;
    return;
  }
  tbody.innerHTML = emps.map(e => `
    <tr class="${e.is_active ? '' : 'emp-disabled'}">
      <td>
        <div style="display:flex;align-items:center">
          <span class="emp-avatar">${(e.nickname || e.username)[0].toUpperCase()}</span>
          <div>
            <div style="font-weight:600">${esc(e.username)}</div>
            ${e.nickname ? `<div style="font-size:12px;color:var(--muted)">Nickname: ${esc(e.nickname)}</div>` : ''}
          </div>
        </div>
      </td>
      <td>${esc(e.full_name)}</td>
      <td><span style="font-size:12px;color:var(--muted)">${fmtDate(e.created_at)}</span></td>
      <td>
        <span style="display:inline-flex;align-items:center;gap:6px;font-size:13px;color:${e.is_active ? 'var(--success)' : 'var(--muted)'}">
          <span style="width:8px;height:8px;border-radius:50%;background:currentColor;display:inline-block"></span>
          ${e.is_active ? 'Active' : 'Disabled'}
        </span>
      </td>
      <td><span style="font-size:12px;color:var(--muted)">••••••</span></td>
      <td>
        <button class="act-btn act-btn-edit"   onclick="openEditEmp(${e.id})">Rename</button>
        <button class="act-btn act-btn-${e.is_active ? 'delete' : 'view'}" onclick="toggleEmp(${e.id})">
          ${e.is_active ? 'Disable' : 'Enable'}
        </button>
      </td>
    </tr>
  `).join('');
}

function openCreateEmp() {
  document.getElementById('newEmpName').value = '';
  document.getElementById('newEmpResult').innerHTML = '';
  openModal('modalCreateEmp');
}

async function submitCreateEmp() {
  const name = document.getElementById('newEmpName').value.trim();
  if (!name) { showToast('Please enter the employee name.', 'error'); return; }
  try {
    const res = await api('create_employee', { full_name: name });
    document.getElementById('newEmpResult').innerHTML = `
      <div class="alert alert-success">
        <strong>Account created!</strong><br>
        Username: <code style="font-size:15px;font-weight:700">${res.username}</code><br>
        Default password: <code>123456</code>
      </div>`;
    loadEmployees();
  } catch(e) { showToast(e.message, 'error'); }
}

function openEditEmp(id) {
  const emp = S.employees.find(e => e.id === id);
  if (!emp) return;
  document.getElementById('editEmpId').value            = id;
  document.getElementById('editEmpOldName').textContent = emp.full_name;
  document.getElementById('editEmpName').value          = '';
  document.getElementById('editEmpResult').innerHTML    = '';
  openModal('modalEditEmp');
}

async function submitEditEmp() {
  const id   = document.getElementById('editEmpId').value;
  const name = document.getElementById('editEmpName').value.trim();
  if (!name) { showToast('Please enter the new name.', 'error'); return; }
  try {
    const res = await api('update_employee_name', { id, full_name: name });
    if (res.old_username) {
      document.getElementById('editEmpResult').innerHTML = `
        <div class="alert alert-info">
          <strong>Username updated.</strong><br>
          Old username: <code>${res.old_username}</code> → New username: <code style="font-weight:700">${res.new_username}</code><br>
          The old username will expire in 30 days.
        </div>`;
    } else {
      document.getElementById('editEmpResult').innerHTML = `<div class="alert alert-success">Name updated successfully.</div>`;
    }
    loadEmployees();
  } catch(e) { showToast(e.message, 'error'); }
}

async function toggleEmp(id) {
  const emp    = S.employees.find(e => e.id === id);
  const action = emp?.is_active ? 'disable' : 'enable';
  if (!confirm(`Confirm ${action} this account?`)) return;
  try {
    await api('toggle_employee', { id });
    loadEmployees();
    showToast(`Account ${action}d.`);
  } catch(e) { showToast(e.message, 'error'); }
}

function searchEmployees() {
  const q = document.getElementById('empSearch').value.trim();
  loadEmployees(q);
}

/* ─────────────────────────────────────────────────────
   PROFILE
   ───────────────────────────────────────────────────── */
async function loadProfile() {
  try {
    const res = await api('get_profile');
    const p   = res.profile;
    const tr  = res.active_transition;
    let html = `
      <div class="profile-info-row">
        <div class="prow-label">Username</div>
        <div class="prow-value"><code style="font-size:15px">${esc(p.username)}</code></div>
      </div>
      <div class="profile-info-row">
        <div class="prow-label">Full Name</div>
        <div class="prow-value">${esc(p.full_name)}</div>
      </div>
      <div class="profile-info-row">
        <div class="prow-label">Nickname</div>
        <div class="prow-value">${p.nickname ? esc(p.nickname) : '<span style="color:var(--muted)">Not set</span>'}</div>
        <div class="prow-action" onclick="openSetNickname()">Edit</div>
      </div>
      <div class="profile-info-row">
        <div class="prow-label">Password</div>
        <div class="prow-value">••••••</div>
        <div class="prow-action" onclick="openChangePassword()">Change</div>
      </div>
    `;
    if (tr) {
      const days = Math.ceil((new Date(tr.expiry_date) - new Date()) / 86400000);
      html += `
        <div class="alert alert-warning" style="margin-top:16px">
          <strong>Account Change Notice:</strong> Old username <code>${tr.old_username}</code> expires in
          <strong>${days}</strong> day(s). Please switch to your new username <code>${p.username}</code>.
        </div>`;
    }
    document.getElementById('profileContent').innerHTML = html;
  } catch(e) {}
}

function openSetNickname() {
  document.getElementById('nicknameInput').value = S.user.nickname || '';
  openModal('modalNickname');
}

async function submitNickname() {
  const nick = document.getElementById('nicknameInput').value.trim();
  try {
    const res = await api('set_nickname', { nickname: nick });
    S.user.nickname = res.nickname;
    renderUser();
    closeModal('modalNickname');
    loadProfile();
    showToast('Nickname updated.');
  } catch(e) { showToast(e.message, 'error'); }
}

function openChangePassword() { openModal('modalChangePass'); }

async function submitChangePassword() {
  const oldP = document.getElementById('oldPassword').value;
  const newP = document.getElementById('newPassword').value;
  const conP = document.getElementById('confirmPassword').value;

  if (newP !== conP) { showToast('New passwords do not match.', 'error'); return; }
  try {
    const res = await api('change_password', { old_password: oldP, new_password: newP });
    closeModal('modalChangePass');
    showToast(res.message || 'Password changed successfully.');
    document.getElementById('oldPassword').value = '';
    document.getElementById('newPassword').value = '';
    document.getElementById('confirmPassword').value = '';
  } catch(e) { showToast(e.message, 'error'); }
}

/* ─────────────────────────────────────────────────────
   NOTICES BAR
   ───────────────────────────────────────────────────── */
function renderNoticesBar(notices) {
  const bar = document.getElementById('noticesBar');
  bar.innerHTML = notices.map(n =>
    `<div class="alert alert-warning">${esc(n)}</div>`
  ).join('');
}

/* ─────────────────────────────────────────────────────
   LOGOUT
   ───────────────────────────────────────────────────── */
async function logout() {
  if (!confirm('Confirm log out?')) return;
  await api('logout');
  window.location.href = 'login.html';
}

/* ─────────────────────────────────────────────────────
   PRE-ORDERS VIEW
   ───────────────────────────────────────────────────── */
async function viewPreOrders(reservationId) {
  openModal('modalPreOrders');
  const el = document.getElementById('preOrdersContent');
  el.innerHTML = '<div class="loading"><div class="spinner"></div>Loading…</div>';
  try {
    const res = await api('get_pre_orders', { reservation_id: reservationId });
    if (!res.pre_orders.length) {
      el.innerHTML = '<p style="color:var(--muted);font-size:14px">No pre-orders for this reservation.</p>';
      return;
    }
    el.innerHTML = `
      <table class="data-table">
        <thead><tr><th>Dish</th><th>Qty</th><th>Ordered At</th></tr></thead>
        <tbody>
          ${res.pre_orders.map(p => `
            <tr>
              <td>${esc(p.item_name)}</td>
              <td>${p.quantity}</td>
              <td style="font-size:12px;color:var(--muted)">${fmtDate(p.created_at)}</td>
            </tr>
          `).join('')}
        </tbody>
      </table>`;
  } catch(e) {
    el.innerHTML = `<p style="color:var(--danger);font-size:13px">${esc(e.message)}</p>`;
  }
}

/* ─────────────────────────────────────────────────────
   NOTIFICATION INIT & POLLING
   ───────────────────────────────────────────────────── */
async function initNotifications() {
  try {
    const res = await api('get_notifications');
    updateUnreadBadge(res.unread);
    let urgentNotif = null;
    for (const n of res.notifications) {
      S.shownNotifIds.add(n.id);
      if (!n.is_read && !urgentNotif &&
          (n.type === 'account_reminder' || n.type === 'password_reminder')) {
        urgentNotif = n;
      }
    }
    if (urgentNotif) setTimeout(() => showNotifModal(urgentNotif), 800);
  } catch(e) {}
}

async function pollNotifications() {
  try {
    const res = await api('get_notifications');
    updateUnreadBadge(res.unread);
    for (const n of res.notifications) {
      if (!S.shownNotifIds.has(n.id)) {
        S.shownNotifIds.add(n.id);
        if (!n.is_read) showMiniNotif(n);
      }
    }
  } catch(e) {}
}

/* ─────────────────────────────────────────────────────
   MINI NOTIFICATION POPUPS
   ───────────────────────────────────────────────────── */
function showMiniNotif(notif) {
  const isModal      = notif.type === 'account_reminder' || notif.type === 'password_reminder';
  const isPersistent = notif.type === 'new_private_request';

  if (isModal) { showNotifModal(notif); return; }

  const container = document.getElementById('miniNotifContainer');
  const el = document.createElement('div');
  el.className = 'mini-notif' + (isPersistent ? ' persistent' : '');
  el.dataset.notifId = notif.id;

  const title = isPersistent ? '🏮 Private Room Request' : '🔔 Notification';

  el.innerHTML = `
    <div class="mini-notif-header">
      <div class="mini-notif-title">${title}</div>
      <div class="mini-notif-close" onclick="dismissMiniNotif(this)">×</div>
    </div>
    <div class="mini-notif-body">${esc(notif.message)}</div>
    <div class="mini-notif-time">${fmtDate(notif.created_at)}</div>
  `;

  container.appendChild(el);

  if (!isPersistent) {
    setTimeout(() => dismissMiniNotif(null, el), 7000);
  }
}

function dismissMiniNotif(closeBtn, elDirect) {
  const el = elDirect || closeBtn?.closest('.mini-notif');
  if (!el || el._dismissing) return;
  el._dismissing = true;
  const notifId = parseInt(el.dataset.notifId);
  const isPersistent = el.classList.contains('persistent');

  el.classList.add('dismissing');
  setTimeout(() => { el.remove(); }, 320);

  if (notifId) api('read_notification', { id: notifId }).catch(() => {});
  if (isPersistent) updatePendingBadge();
}

/* ─────────────────────────────────────────────────────
   NOTIFICATION ALERT MODAL (account/password reminders)
   ───────────────────────────────────────────────────── */
function showNotifModal(notif) {
  if (S.notifAlertOpen) { S.notifAlertQueue.push(notif); return; }
  S.notifAlertOpen = true;
  const icon = notif.type === 'password_reminder' ? '🔐' : '👤';
  document.getElementById('notifAlertContent').innerHTML = `
    <div style="display:flex;gap:14px;align-items:flex-start">
      <div style="font-size:32px">${icon}</div>
      <div style="font-size:14px;line-height:1.7;color:var(--text)">${esc(notif.message)}</div>
    </div>`;
  openModal('modalNotifAlert');
  api('read_notification', { id: notif.id }).catch(() => {});
}

function closeNotifAlertModal() {
  closeModal('modalNotifAlert');
  S.notifAlertOpen = false;
  if (S.notifAlertQueue.length) {
    const next = S.notifAlertQueue.shift();
    setTimeout(() => showNotifModal(next), 200);
  }
  updateUnreadBadge(Math.max(0, (parseInt(document.getElementById('notifBadge').textContent) || 0) - 1));
}

/* ─────────────────────────────────────────────────────
   MODAL HELPERS
   ───────────────────────────────────────────────────── */
function openModal(id) {
  document.getElementById(id).classList.add('show');
}
function closeModal(id) {
  document.getElementById(id).classList.remove('show');
}
document.addEventListener('click', e => {
  if (e.target.classList.contains('modal-overlay')) closeModal(e.target.id);
});

function clearFormErrors(modalId) {
  document.getElementById(modalId).querySelectorAll('.form-error').forEach(el => el.classList.remove('show'));
}
function setFieldErr(fieldId, msg) {
  const field = document.getElementById(fieldId);
  if (!field) return;
  const errEl = field.parentElement.querySelector('.form-error');
  if (errEl) { errEl.textContent = msg; errEl.classList.add('show'); }
}

/* ─────────────────────────────────────────────────────
   TOAST
   ───────────────────────────────────────────────────── */
function showToast(msg, type = 'success') {
  const toast = document.getElementById('toast');
  toast.textContent = msg;
  toast.className   = 'toast toast-' + type + ' show';
  setTimeout(() => toast.classList.remove('show'), 3000);
}

/* ─────────────────────────────────────────────────────
   API + UTILS
   ───────────────────────────────────────────────────── */
async function api(action, body = {}) {
  const resp = await fetch(`api.php?action=${action}`, {
    method:  'POST',
    headers: { 'Content-Type': 'application/json' },
    body:    JSON.stringify(body),
    credentials: 'same-origin',
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
