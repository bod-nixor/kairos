// ---------- Server-Sent Events helpers ----------
const SSE_SUPPORTED_CHANNELS = ['rooms', 'queue', 'progress'];

const COURSE_FILTER_ALLOW_ALL = Symbol('course:all');

const eventStreamState = {
  source: null,
  handlers: {
    rooms: new Set(),
    queue: new Set(),
    progress: new Set(),
  },
  openHandlers: new Set(),
  courseFilters: new Map(),
  queueFilters: new Map(),
  lastEventId: null,
  currentUrl: '',
};

function getActiveChannels() {
  return Object.entries(eventStreamState.handlers)
    .filter(([, listeners]) => listeners.size > 0)
    .map(([channel]) => channel)
    .filter((channel) => SSE_SUPPORTED_CHANNELS.includes(channel))
    .sort();
}

function computeCourseFilter() {
  if (!eventStreamState.courseFilters.size) {
    return null;
  }
  let selected = null;
  for (const value of eventStreamState.courseFilters.values()) {
    if (value == null) {
      continue;
    }
    if (value === COURSE_FILTER_ALLOW_ALL) {
      return null;
    }
    if (selected === null) {
      selected = value;
      continue;
    }
    if (selected !== value) {
      return null;
    }
  }
  return selected;
}

function computeQueueFilterIds() {
  const ids = new Set();
  for (const value of eventStreamState.queueFilters.values()) {
    if (!value) continue;
    for (const id of value) {
      if (id != null && id !== '') {
        ids.add(String(id));
      }
    }
  }
  if (!ids.size) {
    return [];
  }
  return Array.from(ids).sort((a, b) => Number(a) - Number(b));
}

function buildEventStreamUrl(channels) {
  if (!channels.length) {
    return null;
  }
  const url = new URL('./api/changes.php', window.location.origin);
  url.searchParams.set('channels', channels.join(','));

  const courseId = computeCourseFilter();
  if (courseId != null) {
    url.searchParams.set('course_id', String(courseId));
  }

  if (channels.includes('queue')) {
    const queueIds = computeQueueFilterIds();
    if (queueIds.length) {
      url.searchParams.set('queue_id', queueIds.join(','));
    }
  }

  if (eventStreamState.lastEventId != null) {
    url.searchParams.set('since', String(eventStreamState.lastEventId));
  }

  return url.toString();
}

function closeEventStream() {
  if (eventStreamState.source) {
    try { eventStreamState.source.close(); } catch (err) { /* ignore */ }
  }
  eventStreamState.source = null;
  eventStreamState.currentUrl = '';
}

function ensureEventStream(force = false) {
  const channels = getActiveChannels();
  const nextUrl = buildEventStreamUrl(channels);

  if (!nextUrl) {
    closeEventStream();
    return;
  }

  if (!force && eventStreamState.source && eventStreamState.currentUrl === nextUrl) {
    return;
  }

  closeEventStream();

  const source = new EventSource(nextUrl, { withCredentials: true });
  eventStreamState.source = source;
  eventStreamState.currentUrl = nextUrl;

  const updateLastEventId = (event) => {
    if (!event || event.lastEventId == null) {
      return;
    }
    const parsed = Number(event.lastEventId);
    if (Number.isFinite(parsed) && parsed > 0) {
      eventStreamState.lastEventId = parsed;
    }
  };

  const dispatch = (channel, event) => {
    updateLastEventId(event);
    const listeners = eventStreamState.handlers[channel];
    if (!listeners || listeners.size === 0) {
      return;
    }
    let payload = null;
    if (typeof event?.data === 'string' && event.data !== '') {
      try {
        payload = JSON.parse(event.data);
      } catch (err) {
        console.warn('Failed to parse SSE payload', err, event.data);
        return;
      }
    }
    listeners.forEach((listener) => {
      try {
        listener(payload, event);
      } catch (err) {
        console.error('SSE listener error', err);
      }
    });
  };

  source.addEventListener('rooms', (event) => dispatch('rooms', event));
  source.addEventListener('queue', (event) => dispatch('queue', event));
  source.addEventListener('progress', (event) => dispatch('progress', event));
  source.onmessage = updateLastEventId;
  source.onerror = () => {
    // Allow the browser to manage reconnection automatically.
  };
  source.onopen = () => {
    eventStreamState.openHandlers.forEach((handler) => {
      try {
        handler();
      } catch (err) {
        console.error('SSE open handler error', err);
      }
    });
  };
}

function subscribeToChannel(channel, handler) {
  if (!SSE_SUPPORTED_CHANNELS.includes(channel)) {
    throw new Error(`Unsupported SSE channel: ${channel}`);
  }
  if (typeof handler !== 'function') {
    return () => {};
  }
  const listeners = eventStreamState.handlers[channel];
  listeners.add(handler);
  ensureEventStream();
  return () => {
    listeners.delete(handler);
    ensureEventStream();
  };
}

function onEventStreamOpen(handler) {
  if (typeof handler !== 'function') {
    return () => {};
  }
  eventStreamState.openHandlers.add(handler);
  return () => {
    eventStreamState.openHandlers.delete(handler);
  };
}

function setCourseFilter(key, courseId) {
  if (!key) return;
  if (courseId == null) {
    eventStreamState.courseFilters.delete(key);
  } else {
    eventStreamState.courseFilters.set(key, Number(courseId));
  }
  ensureEventStream();
}

function clearCourseFilter(key) {
  if (!key) return;
  if (eventStreamState.courseFilters.has(key)) {
    eventStreamState.courseFilters.delete(key);
    ensureEventStream();
  }
}

function setCourseFilterAllowAll(key) {
  if (!key) return;
  eventStreamState.courseFilters.set(key, COURSE_FILTER_ALLOW_ALL);
  ensureEventStream();
}

function setQueueFilter(key, ids) {
  if (!key) return;
  if (!ids || !ids.length) {
    eventStreamState.queueFilters.delete(key);
  } else {
    const normalized = new Set();
    ids.forEach((id) => {
      if (id == null) return;
      const str = String(id).trim();
      if (/^\d+$/.test(str)) {
        normalized.add(str);
      }
    });
    if (normalized.size) {
      eventStreamState.queueFilters.set(key, normalized);
    } else {
      eventStreamState.queueFilters.delete(key);
    }
  }
  ensureEventStream();
}

window.addEventListener('beforeunload', () => {
  closeEventStream();
});

// ---------- Google Sign-In + App flow ----------
const COURSE_FILTER_KEY = 'course-view';
const QUEUE_LIVE_FILTER_KEY = 'queue-live';
const QUEUE_NOTIFY_FILTER_KEY = 'queue-notify';
const COURSE_NOTIFY_FILTER_KEY = 'course-notify';

const changeStreamSubscriptions = {
  rooms: null,
  progress: null,
};
let notifyQueueSubscription = null;
const APP_CONFIG = window.SIGNOFF_CONFIG || {};
const CLIENT_ID = typeof APP_CONFIG.googleClientId === 'string' ? APP_CONFIG.googleClientId : '';
const ALLOWED_DOMAIN = typeof APP_CONFIG.allowedDomain === 'string' ? APP_CONFIG.allowedDomain : '';
let selectedCourse = null;
let selectedRoomId = null;
let selfUserId = null;
let taAudioCtx = null;
let currentUserId = null;
let sessionCapabilities = { is_logged_in: false, roles: { student: false, ta: false, manager: false, admin: false } };

function updateAllowedDomainCopy() {
  const domain = (typeof ALLOWED_DOMAIN === 'string' && ALLOWED_DOMAIN)
    ? ALLOWED_DOMAIN.replace(/^@+/, '')
    : '';
  const display = domain ? `@${domain}` : 'your organization';
  document.querySelectorAll('[data-allowed-domain-text]').forEach((el) => {
    el.textContent = display;
  });
}

function setTopNavActive(key) {
  const nav = document.getElementById('mainNav');
  if (!nav) return;
  const ids = {
    home: 'navHomeLink',
    courses: 'navCoursesLink',
    ta: 'navTaLink',
    manager: 'navManagerLink',
    admin: 'navAdminLink',
  };
  nav.querySelectorAll('.top-nav-link').forEach((link) => {
    link.removeAttribute('aria-current');
  });
  if (!key || !ids[key]) {
    return;
  }
  const target = document.getElementById(ids[key]);
  if (target) {
    target.setAttribute('aria-current', 'page');
  }
}

function applySessionCapabilities(caps) {
  const defaults = { is_logged_in: false, roles: { student: false, ta: false, manager: false, admin: false } };
  const next = {
    ...defaults,
    ...(caps || {}),
    roles: { ...defaults.roles, ...((caps && caps.roles) || {}) },
  };
  sessionCapabilities = next;

  const nav = document.getElementById('mainNav');
  if (!nav) {
    return;
  }

  const isLogged = !!next.is_logged_in;
  nav.classList.toggle('hidden', !isLogged);

  ['navHomeLink', 'navCoursesLink'].forEach((id) => {
    const link = document.getElementById(id);
    if (link) {
      link.classList.toggle('hidden', !isLogged);
    }
  });

  const roleLinks = [
    ['navTaLink', 'ta'],
    ['navManagerLink', 'manager'],
    ['navAdminLink', 'admin'],
  ];
  roleLinks.forEach(([id, role]) => {
    const link = document.getElementById(id);
    if (!link) return;
    const allowed = isLogged && !!next.roles[role];
    link.classList.toggle('hidden', !allowed);
  });

  if (!isLogged) {
    setTopNavActive(null);
  }
}

async function refreshSessionCapabilities() {
  try {
    const res = await fetch('./api/session_capabilities.php', {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    });
    if (!res.ok) throw new Error('session_capabilities.php ' + res.status);
    const ctype = res.headers.get('content-type') || '';
    if (!ctype.includes('application/json')) throw new Error('session_capabilities not JSON');
    const json = await res.json();
    applySessionCapabilities(json);
    return json;
  } catch (err) {
    console.warn('Failed to load session capabilities', err);
    applySessionCapabilities({ is_logged_in: false, roles: { student: false, ta: false, manager: false, admin: false } });
    return null;
  }
}

const queueLiveState = {
  roomId: null,
  queueIds: new Set(),
  subscription: null,
  openSubscription: null,
};
const queuePendingFetches = new Map();

function showSignin() {
  applySessionCapabilities(null);
  document.getElementById('signin').classList.remove('hidden');  // show login card
  document.getElementById('userbar').classList.add('hidden');    // hide user info
  // hide app views while logged out
  document.querySelectorAll('.view').forEach(v => v.classList.add('hidden'));
  // ensure the button renders (fresh container)
  const target = document.getElementById('googleBtn');
  if (target) target.innerHTML = '';
  renderGoogleButton();
}

function showApp() {
  document.getElementById('signin').classList.add('hidden');     // hide login card
  document.getElementById('userbar').classList.remove('hidden'); // show user info
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
  } catch (e) {
    alert('Login failed: ' + e.message);
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
    const target = document.getElementById('googleBtn');
    if (target && !target.textContent.trim()) {
      target.innerHTML = '<p class="muted small">Sign-in is temporarily unavailable.</p>';
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
  const target = document.getElementById('googleBtn');
  if (target && !target.hasChildNodes()) {
    google.accounts.id.renderButton(target, {
      theme: 'outline', size: 'large', shape: 'rectangular', text: 'signin_with', logo_alignment: 'left'
    });
  }
}

async function bootstrap() {
  showSignin();
  try {
    const r = await fetch('./api/me.php', { credentials: 'same-origin' });
    if (!r.ok) throw new Error('me.php ' + r.status);
    const ctype = r.headers.get('content-type') || '';
    if (!ctype.includes('application/json')) throw new Error('me.php not JSON');

    const me = await r.json();
    if (!me?.email) { selfUserId = null; stopNotifySSE(); showSignin(); return; }

    // Fill userbar
    document.getElementById('avatar').src = me.picture_url || '';
    document.getElementById('name').textContent = me.name || '';
    document.getElementById('email').textContent = me.email || '';

    selfUserId = me.user_id || null;
    currentUserId = (typeof me.user_id === 'number' && Number.isFinite(me.user_id))
      ? me.user_id
      : (me?.user_id != null ? Number(me.user_id) : null);
    if (!Number.isFinite(currentUserId)) {
      currentUserId = null;
    }

    await refreshSessionCapabilities();
    showApp();

    // Step 1: show only the user's enrolled courses as cards
    await renderCourseCards();

    // Start SSE (optional; comment out if you haven't added change_log)
    // startSSE();
    startNotifySSE();
  } catch (e) {
    console.warn('bootstrap -> logged-out', e);
    selfUserId = null;
    stopNotifySSE();
    showSignin();
  }
}

document.addEventListener('DOMContentLoaded', () => {
  updateAllowedDomainCopy();
  renderGoogleButton();
  bootstrap();
  const mainNav = document.getElementById('mainNav');
  if (mainNav) {
    mainNav.addEventListener('click', (event) => {
      const link = event.target.closest('a.top-nav-link');
      if (!link) return;
      const view = link.dataset.view;
      if (view === 'home' || view === 'courses') {
        event.preventDefault();
        renderCourseCards();
      }
    });
  }
  const dismiss = document.getElementById('taAcceptDismiss');
  if (dismiss) dismiss.addEventListener('click', hideTaAcceptModal);
  const modal = document.getElementById('taAcceptModal');
  if (modal) {
    modal.addEventListener('click', (e) => {
      if (e.target === modal || e.target.classList.contains('modal-backdrop')) {
        hideTaAcceptModal();
      }
    });
  }
});

document.getElementById('logoutBtn').addEventListener('click', async () => {
  await fetch('./api/logout.php', { method: 'POST', credentials: 'same-origin' });
  stopSSE();
  stopNotifySSE();
  selfUserId = null;
  showSignin();
  renderGoogleButton();
});

// ---------- API helpers ----------
async function apiGet(url) {
  const r = await fetch(url, { credentials: 'same-origin', headers: { 'Cache-Control': 'no-cache' } });
  if (!r.ok) throw new Error(`${url} -> ${r.status}`);
  return r.json();
}

// nav state
function setCrumbs(text){ document.getElementById('breadcrumbs').textContent = text; }
function showView(id){
  for (const v of document.querySelectorAll('.view')) v.classList.add('hidden');
  document.getElementById(id).classList.remove('hidden');
  document.getElementById('navCourses').classList.toggle('active', id==='viewCourses');
  document.getElementById('navRooms').classList.toggle('active', id==='viewRooms');
  if (id === 'viewCourses') {
    setTopNavActive('courses');
  } else {
    setTopNavActive(null);
  }
}

// COURSES (cards: enrolled only)
async function renderCourseCards(){
  selectedRoomId = null;                                 // reset room selection when leaving rooms view
  stopQueueLiveUpdates();
  setCrumbs('Courses');
  showView('viewCourses');
  const progressSection = document.getElementById('progressSection');
  if (progressSection) progressSection.classList.add('hidden');
  const grid = document.getElementById('coursesGrid');
  grid.innerHTML = skeletonCards(3);

  let courses = [];
  try { courses = await apiGet('./api/my_courses.php'); } catch {}
  if (!Array.isArray(courses)) courses = [];

  if (!courses.length){
    grid.innerHTML = `<div class="card"><strong>No courses yet.</strong><div class="muted small">You’re not enrolled in any courses.</div></div>`;
    return;
  }

  grid.innerHTML = '';
  courses.forEach(c=>{
    const card = document.createElement('div');
    card.className = 'course-card';
    card.innerHTML = `
      <span class="badge">Course #${c.course_id}</span>
      <h3 class="course-title">${escapeHtml(c.name)}</h3>
      <div class="mt-8">
        <button class="btn btn-primary" data-course="${c.course_id}">Open</button>
      </div>
    `;
    grid.appendChild(card);
  });

  grid.onclick = async (e)=>{
    const btn = e.target.closest('button[data-course]');
    if(!btn) return;
    const id = btn.getAttribute('data-course');
    await showCourse(id);
  };
}

// ROOMS (cards) + PROGRESS (bottom)
async function showCourse(courseId){
  selectedCourse = String(courseId);
  try {
    sessionStorage.setItem('signoff:lastCourseId', selectedCourse);
  } catch (err) {
    console.debug('Unable to persist course id', err);
  }
  setCrumbs(`Course #${selectedCourse}`);
  showView('viewRooms');
  document.getElementById('roomsTitle').textContent = `Rooms for Course #${selectedCourse}`;

  const grid = document.getElementById('roomsGrid');
  grid.innerHTML = skeletonCards(3);

  let rooms = [];
  try {
    rooms = await apiGet('./api/rooms.php?course_id=' + encodeURIComponent(selectedCourse));
  } catch (err) {
    console.error('Failed to load rooms', err);
  }

  grid.innerHTML = '';

  if (!Array.isArray(rooms) || rooms.length === 0) {
    grid.innerHTML = `<div class="card">No open rooms for this course.</div>`;
  } else {
    for (const room of rooms) {
      const card = document.createElement('div');
      card.className = 'room-card';
      const url = `/signoff/room?course_id=${encodeURIComponent(selectedCourse)}&room_id=${encodeURIComponent(room.room_id)}`;
      card.innerHTML = `
        <div class="flex align-center gap-10">
          <span class="badge">Room #${room.room_id}</span>
          <h3 class="room-title title-reset">${escapeHtml(room.name)}</h3>
        </div>
        <div class="room-actions mt-8 flex gap-8 flex-wrap">
          <a class="btn btn-primary" href="${url}">Open room</a>
        </div>
      `;
      grid.appendChild(card);
    }
  }

  grid.onclick = async (e) => {
    const card = e.target.closest('.room-card');
    const insideQueues = e.target.closest('.queues');
    if (card && !e.target.closest('button') && !insideQueues) {
      const roomId = card.dataset.roomId;
      if (roomId) {
        window.location.href = `/signoff/room?room_id=${encodeURIComponent(roomId)}`;
        return;
      }
    }

    const joinBtn = e.target.closest('button[data-join-room]');
    if (joinBtn) {
      const roomId = joinBtn.getAttribute('data-join-room');
      if (roomId && selectedRoomId !== roomId) {
        selectedRoomId = roomId;
        updateRoomSelectionUI();
        const wrap = document.getElementById(`queues-for-${roomId}`);
        if (wrap) {
          wrap.innerHTML = '<div class="sk"></div>';
        }
        await loadQueuesForRoom(roomId);
      }
      return;
    }

    const leaveBtn = e.target.closest('button[data-leave-room]');
    if (leaveBtn) {
      const roomId = leaveBtn.getAttribute('data-leave-room');
      if (roomId && selectedRoomId === roomId) {
        selectedRoomId = null;
        updateRoomSelectionUI();
      }
    }
  };

  const progressSection = document.getElementById('progressSection');
  if (progressSection) progressSection.classList.remove('hidden');
  await renderProgress(selectedCourse);

  document.getElementById('backToCourses').onclick = () => {
    const progressSection = document.getElementById('progressSection');
    if (progressSection) progressSection.classList.add('hidden');
    renderCourseCards();
  };
  document.getElementById('navRooms').classList.add('active');
  document.getElementById('navCourses').classList.remove('active');
}

// queues per room (unchanged logic, prettier buttons)
async function loadQueuesForRoom(roomId){
  const wrap = document.getElementById(`queues-for-${roomId}`);
  if (!wrap) return;
  if (String(selectedRoomId || '') !== String(roomId)) return;
  wrap.innerHTML = '<div class="sk"></div>';
  stopQueueLiveUpdates();
  try{
    const queues = await apiGet('./api/queues.php?room_id='+encodeURIComponent(roomId));
    if (String(selectedRoomId || '') !== String(roomId)) return;
    if(!queues.length){ wrap.innerHTML = `<div class="muted">No open queues for this room.</div>`; return; }
    wrap.innerHTML = '';
    const queueIds = [];
    queues.forEach(q=>{
      const row = document.createElement('div');
      row.className='queue-row';
      row.dataset.queueId = String(q.queue_id ?? '');
      row.innerHTML = `
        <div class="queue-header">
          <div class="queue-header-text">
            <div class="q-name">${escapeHtml(q.name)}</div>
            <div class="q-desc">${escapeHtml(q.description||'')}</div>
          </div>
          <div class="queue-meta">
            <div class="queue-count" data-role="queue-count">Loading…</div>
            <div class="queue-eta" data-role="queue-eta"></div>
          </div>
          <div class="queue-actions">
            <button class="btn btn-ghost" data-join="${q.queue_id}">Join</button>
            <button class="btn" data-leave="${q.queue_id}">Leave</button>
          </div>
        </div>
        <div class="queue-occupants empty" data-role="queue-occupants">
          <span class="muted small">Loading participants…</span>
        </div>
      `;
      wrap.appendChild(row);
      queueIds.push(String(q.queue_id ?? ''));
    });
    initQueueLiveUpdates(roomId, queueIds);
    wrap.onclick = async (e)=>{
      const joinId = e.target.getAttribute('data-join');
      const leaveId = e.target.getAttribute('data-leave');
      if(joinId){
        await fetch('./api/queues.php',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({action:'join',queue_id:joinId})});
        await loadQueuesForRoom(roomId);
      }
      if(leaveId){
        await fetch('./api/queues.php',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({action:'leave',queue_id:leaveId})});
        await loadQueuesForRoom(roomId);
      }
    };
  }catch{
    if (String(selectedRoomId || '') === String(roomId)) {
      wrap.innerHTML = `<div class="muted">Failed to load queues.</div>`;
    }
  }
}

function updateRoomSelectionUI(){
  document.querySelectorAll('#roomsGrid .room-card').forEach(card => {
    const roomId = card.dataset.roomId;
    const isActive = selectedRoomId && String(selectedRoomId) === String(roomId);
    const joinBtn = card.querySelector('button[data-join-room]');
    const leaveBtn = card.querySelector('button[data-leave-room]');
    const queues = card.querySelector('.queues');
    if (joinBtn) joinBtn.classList.toggle('hidden', !!isActive);
    if (leaveBtn) leaveBtn.classList.toggle('hidden', !isActive);
    if (queues) {
      queues.classList.toggle('hidden', !isActive);
      if (!isActive) {
        queues.innerHTML = '';
      }
    }
  });
  if (!selectedRoomId) {
    stopQueueLiveUpdates();
  }
}

// Map status string to CSS class (from your code)
function statusClass(s) {
    const key = String(s || 'None').toLowerCase();
    if (key === 'pending')   return 'status-pending';
    if (key === 'completed') return 'status-completed';
    if (key === 'review')    return 'status-review';
    return 'status-none';
}


// --- Main Progress Rendering ---

// progress rendered as horizontal “tables”
async function renderProgress(courseId) {
    const container = document.getElementById('progressContainer');
    container.innerHTML = '<p>Loading progress...</p>'; // Simple loading state
    const data = await apiGet('./api/progress.php?course_id=' + encodeURIComponent(courseId || ''));
    const cats   = data.categories || [];
    const byCat  = data.detailsByCategory || {};
    const status = data.userStatuses || {}; // { detail_id: "None" | "Pending" | "Completed" | "Review" }

    container.innerHTML = ''; // Clear the "Loading..." text

    for (const cat of cats) {
        const details = byCat[cat.category_id] || [];
        if (!details.length) continue;

        const section = document.createElement('div');
        section.className = 'progress-section-row'; // Use a different class to avoid nesting .progress-section

        const title = document.createElement('h4');
        title.className = 'progress-title';
        title.textContent = cat.name;
        section.appendChild(title);

        const row = document.createElement('div');
        row.className = 'progress-row';

        for (const d of details) {
            const sName = (status[d.detail_id] || 'None'); // default
            const cls = statusClass(sName);

            const cell = document.createElement('div');
            cell.className = 'progress-cell';
            cell.innerHTML = `
                <div class="detail-name">${escapeHtml(d.name)}</div>
                <div class="status ${cls}">${escapeHtml(sName)}</div>
            `;
            row.appendChild(cell);
        }

        section.appendChild(row);
        container.appendChild(section);
    }
}

// sidebar nav (Courses/Rooms)
document.getElementById('navCourses').onclick = ()=> renderCourseCards();
document.getElementById('navRooms').onclick = ()=> showView('viewRooms');

// helpers
function escapeHtml(s){
  return String(s ?? '')
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'","&#039;");
}
function skeletonCards(n=3){
  return Array.from({length:n}).map(()=>'<div class="sk"></div>').join('');
}

async function refreshQueueMeta(queueId){
  const id = String(queueId ?? '');
  if (!id || !queueLiveState.queueIds.has(id)) return;
  const safeId = (typeof CSS !== 'undefined' && typeof CSS.escape === 'function') ? CSS.escape(id) : id.replace(/"/g, '\\"');
  const row = document.querySelector(`.queue-row[data-queue-id="${safeId}"]`);
  if (!row) return;

  if (queuePendingFetches.has(id)) {
    return queuePendingFetches.get(id);
  }

  const promise = (async () => {
    try {
      const data = await apiGet('./api/queue_participants.php?queue_id=' + encodeURIComponent(id));
      const count = Number(data?.count ?? 0);
      const eta = Number(data?.eta_minutes ?? 0);
      const position = data?.position != null ? Number(data.position) : null;
      const participants = Array.isArray(data?.participants) ? data.participants : [];

      const countEl = row.querySelector('[data-role="queue-count"]');
      const etaEl = row.querySelector('[data-role="queue-eta"]');
      const occupantsEl = row.querySelector('[data-role="queue-occupants"]');

      if (countEl) {
        let label;
        if (count <= 0) {
          label = 'No one waiting';
        } else if (count === 1) {
          label = '1 person waiting';
        } else {
          label = `${count} people waiting`;
        }
        if (position && position > 0) {
          label += ` • You are #${position}`;
        }
        countEl.textContent = label;
      }

      if (etaEl) {
        if (eta > 0) {
          etaEl.textContent = `ETA ~ ${eta} min`;
        } else {
          etaEl.textContent = 'ETA ~ —';
        }
      }

      if (occupantsEl) {
        if (count > 0 && participants.length) {
          const pills = participants.map((entry, idx) => {
            const uid = entry?.user_id != null ? Number(entry.user_id) : null;
            const name = entry?.name ? entry.name : (uid ? `User #${uid}` : `User ${idx + 1}`);
            const isSelf = currentUserId != null && uid === Number(currentUserId);
            const suffix = isSelf ? ' (you)' : '';
            return `<span class="pill${isSelf ? ' you' : ''}">${escapeHtml(name)}${suffix}</span>`;
          }).join('');
          occupantsEl.classList.remove('empty');
          occupantsEl.innerHTML = `<div class="occupant-pills">${pills}</div>`;
        } else {
          occupantsEl.classList.add('empty');
          occupantsEl.innerHTML = '<span>No one in this queue yet.</span>';
        }
      }
    } catch (err) {
      const occupantsEl = row.querySelector('[data-role="queue-occupants"]');
      const countEl = row.querySelector('[data-role="queue-count"]');
      const etaEl = row.querySelector('[data-role="queue-eta"]');
      if (countEl) countEl.textContent = 'Queue unavailable';
      if (etaEl) etaEl.textContent = 'ETA unavailable';
      if (occupantsEl) {
        occupantsEl.classList.add('empty');
        occupantsEl.innerHTML = '<span class="muted small">Unable to load queue.</span>';
      }
      console.warn('refreshQueueMeta failed for queue', queueId, err);
    } finally {
      queuePendingFetches.delete(id);
    }
  })();

  queuePendingFetches.set(id, promise);
  return promise;
}

function initQueueLiveUpdates(roomId, queueIds){
  stopQueueLiveUpdates();
  const ids = (Array.isArray(queueIds) ? queueIds : [])
    .map((id) => String(id))
    .filter((id) => /^\d+$/.test(id));
  queueLiveState.roomId = roomId != null ? String(roomId) : null;
  queueLiveState.queueIds = new Set(ids);
  setQueueFilter(QUEUE_LIVE_FILTER_KEY, ids);
  if (!ids.length) {
    return;
  }
  ids.forEach((id) => { refreshQueueMeta(id); });

  queueLiveState.subscription = subscribeToChannel('queue', (data) => {
    if (!data) {
      return;
    }
    const ref = data.ref_id ?? data.queue_id ?? data.id;
    if (ref != null) {
      const refId = String(ref);
      if (queueLiveState.queueIds.has(refId)) {
        refreshQueueMeta(refId);
      }
    }
  });
  queueLiveState.openSubscription = onEventStreamOpen(() => {
    queueLiveState.queueIds.forEach((id) => refreshQueueMeta(id));
  });
}

function stopQueueLiveUpdates(){
  if (queueLiveState.subscription) {
    queueLiveState.subscription();
    queueLiveState.subscription = null;
  }
  if (queueLiveState.openSubscription) {
    queueLiveState.openSubscription();
    queueLiveState.openSubscription = null;
  }
  setQueueFilter(QUEUE_LIVE_FILTER_KEY, null);
  queueLiveState.queueIds = new Set();
  queueLiveState.roomId = null;
  queuePendingFetches.clear();
}

// ---------- Live updates (change log) ----------
function startSSE() {
  if (!selectedCourse) {
    stopSSE();
    return;
  }
  setCourseFilter(COURSE_FILTER_KEY, selectedCourse);
  if (!changeStreamSubscriptions.rooms) {
    changeStreamSubscriptions.rooms = subscribeToChannel('rooms', async () => {
      if (selectedCourse) {
        await showCourse(selectedCourse);
      }
    });
  }
  if (!changeStreamSubscriptions.progress) {
    changeStreamSubscriptions.progress = subscribeToChannel('progress', async () => {
      if (selectedCourse) {
        await renderProgress(selectedCourse);
      }
    });
  }
}

function stopSSE() {
  if (changeStreamSubscriptions.rooms) {
    changeStreamSubscriptions.rooms();
    changeStreamSubscriptions.rooms = null;
  }
  if (changeStreamSubscriptions.progress) {
    changeStreamSubscriptions.progress();
    changeStreamSubscriptions.progress = null;
  }
  clearCourseFilter(COURSE_FILTER_KEY);
}

// ---------- TA notifications (student side) ----------
function startNotifySSE() {
  if (!selfUserId) {
    stopNotifySSE();
    return;
  }
  if (!notifyQueueSubscription) {
    notifyQueueSubscription = subscribeToChannel('queue', (data) => {
      const payload = data?.payload;
      if (!payload || payload.action !== 'accept' || payload.user_id !== selfUserId) {
        return;
      }
      handleTaAcceptPayload({
        user_id: payload.user_id,
        ta_name: payload.ta_name || '',
        queue_id: data?.ref_id ?? payload.queue_id ?? null,
      });
    });
  }
  setCourseFilterAllowAll(COURSE_NOTIFY_FILTER_KEY);
  setQueueFilter(QUEUE_NOTIFY_FILTER_KEY, null);
}

function stopNotifySSE() {
  if (notifyQueueSubscription) {
    notifyQueueSubscription();
    notifyQueueSubscription = null;
  }
  setQueueFilter(QUEUE_NOTIFY_FILTER_KEY, null);
  clearCourseFilter(COURSE_NOTIFY_FILTER_KEY);
}

function handleTaAcceptPayload(payload) {
  if (!payload || payload.user_id !== selfUserId) return;

  const taName = payload.ta_name && payload.ta_name.trim() ? payload.ta_name : 'A TA';
  const queueLabel = payload.queue_id != null ? `#${payload.queue_id}` : 'the queue';
  showTaAcceptModal(taName, queueLabel);
  playTaAcceptSound();

  if ('Notification' in window) {
    const notifyBody = `${taName} is ready for queue ${queueLabel}.`;
    if (Notification.permission === 'granted') {
      try { new Notification('You have been accepted', { body: notifyBody }); } catch (_) {}
    } else if (Notification.permission === 'default') {
      try {
        Notification.requestPermission().then((perm) => {
          if (perm === 'granted') {
            try { new Notification('You have been accepted', { body: notifyBody }); } catch (_) {}
          }
        }).catch(() => {});
      } catch (_) {}
    }
  }
}

function showTaAcceptModal(taName, queueLabel) {
  const modal = document.getElementById('taAcceptModal');
  if (!modal) return;
  const nameEl = document.getElementById('taAcceptTAName');
  const queueEl = document.getElementById('taAcceptQueueId');
  if (nameEl) nameEl.textContent = taName;
  if (queueEl) queueEl.textContent = queueLabel;
  modal.classList.remove('hidden');
}

function hideTaAcceptModal() {
  const modal = document.getElementById('taAcceptModal');
  if (modal) modal.classList.add('hidden');
}

function playTaAcceptSound() {
  try {
    const Ctx = window.AudioContext || window.webkitAudioContext;
    if (!Ctx) return;
    if (!taAudioCtx) taAudioCtx = new Ctx();
    if (taAudioCtx.state === 'suspended') {
      taAudioCtx.resume().catch(() => {});
    }
    const ctx = taAudioCtx;
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.type = 'sine';
    osc.frequency.setValueAtTime(880, ctx.currentTime);
    gain.gain.setValueAtTime(0.0001, ctx.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.25, ctx.currentTime + 0.02);
    gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.4);
    osc.connect(gain);
    gain.connect(ctx.destination);
    osc.start();
    osc.stop(ctx.currentTime + 0.45);
  } catch (e) {
    console.warn('sound failed', e);
  }
}
