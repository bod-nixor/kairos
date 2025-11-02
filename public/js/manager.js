const APP_CONFIG = window.SIGNOFF_CONFIG || {};
const CLIENT_ID = typeof APP_CONFIG.googleClientId === 'string' ? APP_CONFIG.googleClientId : '';
const ALLOWED_DOMAIN = typeof APP_CONFIG.allowedDomain === 'string' ? APP_CONFIG.allowedDomain : '';
let currentUser = null;
let activeCourseId = null;
let activeCourseName = '';
let lastSearchTerm = '';

function updateAllowedDomainCopy() {
  const domain = (typeof ALLOWED_DOMAIN === 'string' && ALLOWED_DOMAIN)
    ? ALLOWED_DOMAIN.replace(/^@+/, '')
    : '';
  const display = domain ? `@${domain}` : 'your organization';
  document.querySelectorAll('[data-allowed-domain-text]').forEach((el) => {
    el.textContent = display;
  });
}

function showSignin() {
  document.getElementById('signin').classList.remove('hidden');
  document.getElementById('userbar').classList.add('hidden');
  document.querySelectorAll('.view').forEach(v => v.classList.add('hidden'));
  const btn = document.getElementById('googleBtn');
  if (btn) btn.innerHTML = '';
  renderGoogleButton();
  const forbidden = document.getElementById('managerForbidden');
  if (forbidden) forbidden.classList.add('hidden');
}

function showApp() {
  document.getElementById('signin').classList.add('hidden');
  document.getElementById('userbar').classList.remove('hidden');
  const forbidden = document.getElementById('managerForbidden');
  if (forbidden) forbidden.classList.add('hidden');
}

function showForbidden() {
  document.getElementById('signin').classList.add('hidden');
  document.querySelectorAll('.view').forEach(v => v.classList.add('hidden'));
  const forbidden = document.getElementById('managerForbidden');
  if (forbidden) forbidden.classList.remove('hidden');
  document.getElementById('userbar').classList.remove('hidden');
}

async function handleCredentialResponse(resp) {
  try {
    const r = await fetch('./api/auth.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      credentials: 'same-origin',
      body: JSON.stringify({ credential: resp.credential })
    });
    const data = await r.json();
    if (!data.success) throw new Error(data.error || 'Auth failed');
    await bootstrap();
  } catch (err) {
    alert('Login failed: ' + err.message);
    showSignin();
  }
}

function renderGoogleButton() {
  if (!window.google || !google.accounts?.id) {
    setTimeout(renderGoogleButton, 120);
    return;
  }
  if (!CLIENT_ID) {
    console.error('Google client ID is not configured.');
    const btn = document.getElementById('googleBtn');
    if (btn && !btn.textContent.trim()) {
      btn.innerHTML = '<p class="muted small">Sign-in is temporarily unavailable.</p>';
    }
    return;
  }
  if (!renderGoogleButton._init) {
    google.accounts.id.initialize({
      client_id: CLIENT_ID,
      callback: handleCredentialResponse,
      ux_mode: 'popup',
      auto_select: false,
      itp_support: true
    });
    renderGoogleButton._init = true;
  }
  const btn = document.getElementById('googleBtn');
  if (btn && !btn.hasChildNodes()) {
    google.accounts.id.renderButton(btn, {
      theme: 'outline', size: 'large', shape: 'rectangular', text: 'signin_with', logo_alignment: 'left'
    });
  }
}

async function bootstrap() {
  showSignin();
  try {
    const r = await fetch('./api/me.php', { credentials: 'same-origin' });
    if (!r.ok) throw new Error('me.php ' + r.status);
    const me = await r.json();
    if (!me || !me.email) {
      showSignin();
      return;
    }
    currentUser = me;
    document.getElementById('avatar').src = me.picture_url || '';
    document.getElementById('name').textContent = me.name || '';
    document.getElementById('email').textContent = me.email || '';
    showApp();
    if (window.SignoffWS) {
      if (me.user_id != null) {
        window.SignoffWS.setSelfUserId(Number(me.user_id));
      }
      window.SignoffWS.init({
        getFilters: () => ({ courseId: activeCourseId ? Number(activeCourseId) : null }),
        onRooms: () => { if (activeCourseId) loadRooms().catch(() => {}); },
        onQueue: () => { if (activeCourseId) loadRooms().catch(() => {}); },
      });
    }
    await loadCourses();
  } catch (err) {
    console.warn('bootstrap failed', err);
    showSignin();
  }
}

function setBreadcrumbs(text) {
  document.getElementById('breadcrumbs').textContent = text;
}

function showView(id) {
  document.querySelectorAll('.view').forEach(v => v.classList.toggle('hidden', v.id !== id));
  document.getElementById('navCourses').classList.toggle('active', id === 'viewCourses');
}

async function apiGet(url) {
  const r = await fetch(url, { credentials: 'same-origin', headers: { 'Cache-Control': 'no-cache' } });
  if (r.status === 401) {
    showSignin();
    throw new Error('unauthenticated');
  }
  if (r.status === 403) {
    showForbidden();
    const msg = await safeErrorMessage(r);
    throw Object.assign(new Error(msg || 'forbidden'), { status: 403 });
  }
  if (!r.ok) {
    const msg = await safeErrorMessage(r);
    const error = new Error(msg || (url + ' -> ' + r.status));
    error.status = r.status;
    throw error;
  }
  return r.json();
}

async function apiPost(url, payload) {
  const r = await fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload || {})
  });
  if (r.status === 401) {
    showSignin();
    throw new Error('unauthenticated');
  }
  if (r.status === 403) {
    showForbidden();
  }
  const data = await r.json().catch(() => ({}));
  if (!r.ok || data?.error) {
    const msg = data?.message || data?.error || (url + ' -> ' + r.status);
    const error = new Error(msg);
    error.status = r.status;
    error.body = data;
    throw error;
  }
  return data;
}

async function safeErrorMessage(response) {
  try {
    const data = await response.json();
    return data?.message || data?.error;
  } catch (_) {
    return null;
  }
}

async function loadCourses() {
  activeCourseId = null;
  activeCourseName = '';
  if (window.SignoffWS) {
    window.SignoffWS.updateFilters({ courseId: null });
  }
  setBreadcrumbs('Courses');
  showView('viewCourses');
  const grid = document.getElementById('coursesGrid');
  grid.innerHTML = '<div class="card">Loading…</div>';
  try {
    const courses = await apiGet('./api/manager/courses.php');
    if (!Array.isArray(courses) || !courses.length) {
      grid.innerHTML = '<div class="card"><strong>No managed courses.</strong><div class="muted small">You are not enrolled as a manager yet.</div></div>';
      return;
    }
    grid.innerHTML = '';
    courses.forEach(course => {
      const card = document.createElement('div');
      card.className = 'course-card';
      card.innerHTML = `
        <span class="badge">Course #${course.course_id}</span>
        <h3 class="course-title">${escapeHtml(course.name || '')}</h3>
        <div class="mt-8">
          <button class="btn btn-primary" data-open-course="${course.course_id}" data-course-name="${escapeHtmlAttr(course.name || '')}">Open</button>
        </div>
      `;
      grid.appendChild(card);
    });
    grid.onclick = onCourseGridClick;
  } catch (err) {
    if (err?.status === 403) {
      return;
    }
    grid.innerHTML = `<div class="card">Failed to load courses.<br/><span class="muted small">${escapeHtml(err.message)}</span></div>`;
  }
}

function onCourseGridClick(event) {
  const btn = event.target.closest('button[data-open-course]');
  if (!btn) return;
  const id = btn.getAttribute('data-open-course');
  const name = btn.getAttribute('data-course-name') || '';
  openCourse(id, name);
}

async function openCourse(courseId, courseName) {
  activeCourseId = Number(courseId);
  activeCourseName = courseName;
  if (window.SignoffWS) {
    window.SignoffWS.updateFilters({ courseId: activeCourseId });
  }
  setBreadcrumbs(`Course #${activeCourseId}`);
  document.getElementById('courseTitle').textContent = `${courseName || 'Course'} (#${activeCourseId})`;
  showView('viewCourseDetail');
  await Promise.all([loadRooms(), loadRoster()]);
}

async function loadRooms() {
  if (!activeCourseId) return;
  const container = document.getElementById('roomsList');
  container.innerHTML = '<div class="muted">Loading rooms…</div>';
  try {
    const rooms = await apiGet(`./api/rooms.php?course_id=${encodeURIComponent(activeCourseId)}`);
    if (!Array.isArray(rooms) || !rooms.length) {
      container.innerHTML = '<div class="muted">No rooms yet. Create one to start managing queues.</div>';
      return;
    }
    const queueData = await Promise.all(rooms.map(r => apiGet(`./api/queues.php?room_id=${encodeURIComponent(r.room_id)}`)
      .catch(() => [])));
    container.innerHTML = '';
    rooms.forEach((room, idx) => {
      const queues = Array.isArray(queueData[idx]) ? queueData[idx] : [];
      container.appendChild(renderRoom(room, queues));
    });
  } catch (err) {
    if (err?.status === 403) {
      return;
    }
    container.innerHTML = `<div class="muted">Failed to load rooms: ${escapeHtml(err.message)}</div>`;
  }
}

function renderRoom(room, queues) {
  const wrapper = document.createElement('div');
  wrapper.className = 'room-entry';
  wrapper.dataset.roomId = room.room_id;
  wrapper.innerHTML = `
    <div class="room-header">
      <div>
        <span class="badge">Room #${room.room_id}</span>
        <h3 class="title">${escapeHtml(room.name || '')}</h3>
      </div>
      <div class="room-actions">
        <button class="btn btn-secondary" data-room-action="add-queue" data-room-id="${room.room_id}">+ Queue</button>
        <button class="btn btn-secondary" data-room-action="rename" data-room-id="${room.room_id}" data-room-name="${escapeHtmlAttr(room.name || '')}">Rename</button>
        <button class="btn btn-danger" data-room-action="delete" data-room-id="${room.room_id}">Delete</button>
      </div>
    </div>
    <div class="queue-list">${queues.map(renderQueueHtml).join('')}</div>
  `;
  wrapper.addEventListener('click', onRoomActionClick);
  return wrapper;
}

function renderQueueHtml(queue) {
  const occupants = Array.isArray(queue.occupants) ? queue.occupants : [];
  const pills = occupants.length
    ? `<div class="occupant-pills">${occupants.map(o => `<span class="pill">${escapeHtml(o.name || 'User #' + o.user_id)}</span>`).join('')}</div>`
    : '<div class="muted">No one in queue.</div>';
  return `
    <div class="queue-item" data-queue-id="${queue.queue_id}">
      <div class="queue-head">
        <span class="badge">Queue #${queue.queue_id}</span>
        <div class="queue-details">
          <div class="queue-name">${escapeHtml(queue.name || '')}</div>
          <div class="queue-meta">${escapeHtml(queue.description || '')}</div>
        </div>
        <div class="room-actions">
          <button class="btn btn-secondary" data-queue-action="rename" data-queue-id="${queue.queue_id}" data-queue-name="${escapeHtmlAttr(queue.name || '')}" data-queue-desc="${escapeHtmlAttr(queue.description || '')}">Rename</button>
          <button class="btn btn-danger" data-queue-action="delete" data-queue-id="${queue.queue_id}">Delete</button>
        </div>
      </div>
      <div>
        <div class="muted text-small">${occupants.length} in queue</div>
        ${pills}
      </div>
    </div>
  `;
}

async function onRoomActionClick(event) {
  const btn = event.target.closest('button[data-room-action], button[data-queue-action]');
  if (!btn) return;
  event.preventDefault();
  const roomAction = btn.getAttribute('data-room-action');
  const queueAction = btn.getAttribute('data-queue-action');
  try {
    if (roomAction) {
      const roomId = Number(btn.getAttribute('data-room-id'));
      if (roomAction === 'add-queue') {
        await createQueue(roomId);
      } else if (roomAction === 'rename') {
        const currentName = btn.getAttribute('data-room-name') || '';
        await renameRoom(roomId, currentName);
      } else if (roomAction === 'delete') {
        await deleteRoom(roomId);
      }
    } else if (queueAction) {
      const queueId = Number(btn.getAttribute('data-queue-id'));
      if (queueAction === 'rename') {
        const name = btn.getAttribute('data-queue-name') || '';
        const desc = btn.getAttribute('data-queue-desc') || '';
        await renameQueue(queueId, name, desc);
      } else if (queueAction === 'delete') {
        await deleteQueue(queueId);
      }
    }
    await loadRooms();
  } catch (err) {
    if (err?.status !== 403) {
      alert(err.message);
    }
  }
}

async function createQueue(roomId) {
  const name = prompt('Queue name');
  if (!name) return;
  const description = prompt('Queue description (optional)') || '';
  await apiPost('./api/manager/queues.php', {
    action: 'create',
    room_id: roomId,
    name,
    description
  });
}

async function renameQueue(queueId, currentName, currentDesc) {
  const name = prompt('New queue name', currentName || '');
  if (!name) return;
  const description = prompt('New description (optional)', currentDesc || '') ?? '';
  await apiPost('./api/manager/queues.php', {
    action: 'rename',
    queue_id: queueId,
    name,
    description
  });
}

async function deleteQueue(queueId) {
  if (!confirm('Delete this queue? This cannot be undone.')) return;
  await apiPost('./api/manager/queues.php', {
    action: 'delete',
    queue_id: queueId
  });
}

async function renameRoom(roomId, currentName) {
  const name = prompt('New room name', currentName || '');
  if (!name) return;
  await apiPost('./api/manager/rooms.php', {
    action: 'rename',
    room_id: roomId,
    name
  });
}

async function deleteRoom(roomId) {
  if (!confirm('Delete this room and all its queues?')) return;
  await apiPost('./api/manager/rooms.php', {
    action: 'delete',
    room_id: roomId
  });
}

async function createRoom() {
  if (!activeCourseId) return;
  const name = prompt('Room name');
  if (!name) return;
  await apiPost('./api/manager/rooms.php', {
    action: 'create',
    course_id: activeCourseId,
    name
  });
  await loadRooms();
}

async function loadRoster() {
  if (!activeCourseId) return;
  const rosterEl = document.getElementById('courseRoster');
  rosterEl.innerHTML = '<div class="muted">Loading roster…</div>';
  rosterEl.onclick = null;
  try {
    const roster = await apiGet(`./api/manager/users_search.php?course_id=${encodeURIComponent(activeCourseId)}&roster=1`);
    if (!Array.isArray(roster) || !roster.length) {
      rosterEl.innerHTML = '<div class="muted">No one enrolled yet.</div>';
      return;
    }
    rosterEl.innerHTML = '';
    roster.forEach(user => {
      const row = document.createElement('div');
      row.className = 'list-row';
      row.innerHTML = `
        <div class="meta">
          <span>${escapeHtml(user.name || 'User #' + user.user_id)}</span>
          <span>${escapeHtml(user.email || '')}</span>
        </div>
        <button class="btn btn-danger" data-roster-remove="${user.user_id}">Remove</button>
      `;
      rosterEl.appendChild(row);
    });
    rosterEl.onclick = async (event) => {
      const btn = event.target.closest('button[data-roster-remove]');
      if (!btn) return;
      const uid = Number(btn.getAttribute('data-roster-remove'));
      if (!confirm('Unenroll this user?')) return;
      try {
        await apiPost('./api/manager/unenroll.php', { user_id: uid, course_id: activeCourseId });
        await Promise.all([loadRoster(), rerunLastSearch()]);
      } catch (err) {
        if (err?.status !== 403) {
          alert(err.message);
        }
      }
    };
  } catch (err) {
    if (err?.status === 403) {
      return;
    }
    rosterEl.innerHTML = `<div class="muted">Failed to load roster: ${escapeHtml(err.message)}</div>`;
  }
}

async function searchUsers() {
  if (!activeCourseId) return;
  const input = document.getElementById('userSearchInput');
  const term = (input.value || '').trim();
  if (term.length < 2) {
    alert('Enter at least 2 characters to search.');
    return;
  }
  lastSearchTerm = term;
  const resultsEl = document.getElementById('userSearchResults');
  resultsEl.innerHTML = '<div class="muted">Searching…</div>';
  resultsEl.onclick = null;
  try {
    const results = await apiGet(`./api/manager/users_search.php?course_id=${encodeURIComponent(activeCourseId)}&q=${encodeURIComponent(term)}`);
    if (!Array.isArray(results) || !results.length) {
      resultsEl.innerHTML = '<div class="muted">No results.</div>';
      return;
    }
    resultsEl.innerHTML = '';
    results.forEach(user => {
      const enrolled = Boolean(user.enrolled);
      const row = document.createElement('div');
      row.className = 'list-row';
      row.innerHTML = `
        <div class="meta">
          <span>${escapeHtml(user.name || 'User #' + user.user_id)}</span>
          <span>${escapeHtml(user.email || '')}</span>
        </div>
        <button class="btn ${enrolled ? 'btn-danger' : 'btn-primary'}" data-search-action="${enrolled ? 'unenroll' : 'enroll'}" data-user-id="${user.user_id}">${enrolled ? 'Unenroll' : 'Enroll'}</button>
      `;
      resultsEl.appendChild(row);
    });
    resultsEl.onclick = async (event) => {
      const btn = event.target.closest('button[data-search-action]');
      if (!btn) return;
      const uid = Number(btn.getAttribute('data-user-id'));
      const action = btn.getAttribute('data-search-action');
      try {
        if (action === 'enroll') {
          await apiPost('./api/manager/enroll.php', { user_id: uid, course_id: activeCourseId });
        } else {
          await apiPost('./api/manager/unenroll.php', { user_id: uid, course_id: activeCourseId });
        }
        await Promise.all([loadRoster(), rerunLastSearch()]);
      } catch (err) {
        if (err?.status !== 403) {
          alert(err.message);
        }
      }
    };
  } catch (err) {
    if (err?.status === 403) {
      return;
    }
    resultsEl.innerHTML = `<div class="muted">Search failed: ${escapeHtml(err.message)}</div>`;
  }
}

async function rerunLastSearch() {
  if (lastSearchTerm && activeCourseId) {
    const input = document.getElementById('userSearchInput');
    input.value = lastSearchTerm;
    await searchUsers();
  }
}

function escapeHtml(str) {
  const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
  return String(str ?? '').replace(/[&<>"']/g, ch => map[ch] || ch);
}

function escapeHtmlAttr(str) {
  return escapeHtml(str);
}

function setupEvents() {
  document.getElementById('logoutBtn').addEventListener('click', async () => {
    await fetch('./api/logout.php', { method: 'POST', credentials: 'same-origin' });
    showSignin();
    renderGoogleButton();
  });
  document.getElementById('navCourses').addEventListener('click', () => loadCourses());
  document.getElementById('backToCourses').addEventListener('click', () => loadCourses());
  document.getElementById('addRoomBtn').addEventListener('click', async () => {
    try {
      await createRoom();
    } catch (err) {
      alert(err.message);
    }
  });
  document.getElementById('searchUsersBtn').addEventListener('click', () => searchUsers());
  document.getElementById('userSearchInput').addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      searchUsers();
    }
  });
}

document.addEventListener('DOMContentLoaded', () => {
  updateAllowedDomainCopy();
  renderGoogleButton();
  setupEvents();
  bootstrap();
});
