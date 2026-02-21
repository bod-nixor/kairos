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
  const url = new URL('./api/changes.php', document.baseURI);
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
    return () => { };
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
    return () => { };
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
let APP_CONFIG = window.SignoffConfig || window.SIGNOFF_CONFIG || {};
let CLIENT_ID = typeof APP_CONFIG.googleClientId === 'string' ? APP_CONFIG.googleClientId : '';
let ALLOWED_DOMAIN = typeof APP_CONFIG.allowedDomain === 'string' ? APP_CONFIG.allowedDomain : '';
function setAppConfig(config) {
  APP_CONFIG = config || {};
  CLIENT_ID = typeof APP_CONFIG.googleClientId === 'string' ? APP_CONFIG.googleClientId : '';
  ALLOWED_DOMAIN = typeof APP_CONFIG.allowedDomain === 'string' ? APP_CONFIG.allowedDomain : '';
}
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

  // Old top nav (compatibility shim)
  const nav = document.getElementById('mainNav');
  if (nav) {
    const isLogged = !!next.is_logged_in;
    nav.classList.toggle('hidden', !isLogged);

    ['navHomeLink', 'navCoursesLink'].forEach((id) => {
      const link = document.getElementById(id);
      if (link) link.classList.toggle('hidden', !isLogged);
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

    if (!isLogged) setTopNavActive(null);
  }

  // New sidebar role nav
  const isLogged = !!next.is_logged_in;
  const kNavRoles = document.getElementById('kNavRoles');
  if (kNavRoles) {
    const hasAnyRole = isLogged && (next.roles.ta || next.roles.manager || next.roles.admin);
    kNavRoles.classList.toggle('hidden', !hasAnyRole);
  }
  const sidebarRoleMap = [
    ['navTA', 'ta'],
    ['navManager', 'manager'],
    ['navAdmin', 'admin'],
  ];
  sidebarRoleMap.forEach(([id, role]) => {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.toggle('hidden', !(isLogged && next.roles[role]));
  });

  // Update sidebar role text
  const sidebarRole = document.getElementById('kSidebarRole');
  if (sidebarRole && isLogged) {
    const roleDisplay = next.roles.admin ? 'Admin' :
      next.roles.manager ? 'Manager' :
        next.roles.ta ? 'TA' : 'Student';
    sidebarRole.textContent = roleDisplay;
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

    // The LMS API returns: { ok: true, data: { user: { role: 'admin' }, ... } }
    // Transform into the format applySessionCapabilities expects:
    // { is_logged_in: bool, roles: { student: bool, ta: bool, manager: bool, admin: bool } }
    let caps;
    if (json && json.ok === true && json.data && json.data.user) {
      const role = String(json.data.user.role || 'student').toLowerCase();
      // Role hierarchy: admin > manager > ta > student
      caps = {
        is_logged_in: true,
        roles: {
          student: true,
          ta: role === 'ta' || role === 'manager' || role === 'admin',
          manager: role === 'manager' || role === 'admin',
          admin: role === 'admin',
        },
      };
    } else if (json && json.is_logged_in !== undefined) {
      // Already in the old format (backwards compat)
      caps = json;
    } else {
      caps = { is_logged_in: false, roles: { student: false, ta: false, manager: false, admin: false } };
    }

    applySessionCapabilities(caps);
    return caps;
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

async function reloadRooms() {
  if (!selectedCourse) {
    return;
  }
  try {
    await showCourse(selectedCourse);
  } catch (err) {
    console.warn('reloadRooms failed', err);
  }
}

async function reloadQueues() {
  if (!selectedRoomId) {
    return;
  }
  try {
    await loadQueuesForRoom(selectedRoomId);
  } catch (err) {
    console.warn('reloadQueues failed', err);
  }
}

async function reloadProgress() {
  if (!selectedCourse) {
    return;
  }
  try {
    await renderProgress(selectedCourse);
  } catch (err) {
    console.warn('reloadProgress failed', err);
  }
}

function handleTaAcceptEvent(message) {
  if (!message) {
    return;
  }
  const payload = Object.assign({}, message.payload || {});
  if (payload.queue_id == null && message.ref_id != null) {
    payload.queue_id = message.ref_id;
  }
  if (payload.student_user_id == null && payload.user_id != null) {
    payload.student_user_id = payload.user_id;
  }
  handleTaAcceptPayload(payload);
}

function showSignin() {
  applySessionCapabilities(null);
  const signin = document.getElementById('signin');
  if (signin) signin.classList.remove('hidden');
  const userbar = document.getElementById('userbar');
  if (userbar) userbar.classList.add('hidden');
  // Hide the new LMS shell pre-login
  const sidebar = document.getElementById('kSidebar');
  if (sidebar) sidebar.classList.add('hidden');
  const topbar = document.getElementById('kTopbar');
  if (topbar) topbar.classList.add('hidden');
  document.body.classList.add('k-pre-auth');
  // hide app views while logged out
  document.querySelectorAll('.view').forEach(v => v.classList.add('hidden'));
  // ensure the button renders (fresh container)
  const target = document.getElementById('googleBtn');
  if (target) target.innerHTML = '';
  renderGoogleButton();
}

function showApp() {
  const signin = document.getElementById('signin');
  if (signin) signin.classList.add('hidden');
  const userbar = document.getElementById('userbar');
  if (userbar) userbar.classList.remove('hidden');
  // Show the new LMS shell post-login
  const sidebar = document.getElementById('kSidebar');
  if (sidebar) sidebar.classList.remove('hidden');
  const topbar = document.getElementById('kTopbar');
  if (topbar) topbar.classList.remove('hidden');
  document.body.classList.remove('k-pre-auth');
}

async function handleCredentialResponse(resp) {
  try {
    const r = await fetch('./api/auth.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
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

    // Fill userbar (old + new selectors)
    const avatarEl = document.getElementById('avatar');
    if (avatarEl) avatarEl.src = me.picture_url || '';
    const nameEl = document.getElementById('name');
    if (nameEl) nameEl.textContent = me.name || '';
    // Also fill the new sidebar user bar
    const sidebarAvatar = document.getElementById('kSidebarAvatar');
    if (sidebarAvatar) sidebarAvatar.src = me.picture_url || '';
    const sidebarName = document.getElementById('kSidebarName');
    if (sidebarName) sidebarName.textContent = me.name || me.email || '';
    // Note: kSidebarRole is set by applySessionCapabilities() below

    selfUserId = me.user_id || null;
    currentUserId = (typeof me.user_id === 'number' && Number.isFinite(me.user_id))
      ? me.user_id
      : (me?.user_id != null ? Number(me.user_id) : null);
    if (!Number.isFinite(currentUserId)) {
      currentUserId = null;
    }

    await refreshSessionCapabilities();
    showApp();

    // Load courses (renderCourseCards handles showing viewDashboard)
    await renderCourseCards();
    // Clear stats skeletons (no stats API yet)
    const dashStats = document.getElementById('dashStats');
    if (dashStats) dashStats.innerHTML = '';

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
  const startApp = () => {
    updateAllowedDomainCopy();
    renderGoogleButton();
    bootstrap();
  };

  if (typeof window.waitForAppConfig === 'function') {
    window.waitForAppConfig()
      .then((cfg) => { setAppConfig(cfg); startApp(); })
      .catch(() => {
        setAppConfig(typeof window.getAppConfig === 'function' ? window.getAppConfig() : APP_CONFIG);
        startApp();
      });
  } else {
    startApp();
  }

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

async function performLogout() {
  try {
    await fetch('./api/logout.php', { method: 'POST', credentials: 'same-origin' });
  } catch (err) {
    console.warn('Logout request failed', err);
  } finally {
    stopSSE();
    stopNotifySSE();
    selfUserId = null;
    currentUserId = null;
    selectedCourse = null;
    selectedRoomId = null;
    showSignin();
    renderGoogleButton();
  }
}
const _logoutBtn = document.getElementById('logoutBtn');
if (_logoutBtn) _logoutBtn.addEventListener('click', performLogout);
const _kLogoutBtn = document.getElementById('kLogoutBtn');
if (_kLogoutBtn) _kLogoutBtn.addEventListener('click', performLogout);

// ---------- API helpers ----------
async function apiGet(url) {
  const r = await fetch(url, { credentials: 'same-origin', headers: { 'Cache-Control': 'no-cache' } });
  if (!r.ok) throw new Error(`${url} -> ${r.status}`);
  return r.json();
}

// nav state
function setCrumbs(text) {
  const el = document.getElementById('breadcrumbs');
  if (el) el.textContent = text;
  // Also update the new breadcrumb (use textContent to avoid injection)
  const kb = document.getElementById('kBreadcrumb');
  if (kb) {
    kb.textContent = '';
    const span = document.createElement('span');
    span.className = 'k-breadcrumb__item is-current';
    span.textContent = text || 'Dashboard';
    kb.appendChild(span);
  }
}
function showView(id) {
  for (const v of document.querySelectorAll('.view')) v.classList.add('hidden');
  const target = document.getElementById(id);
  if (target) target.classList.remove('hidden');
  const navCourses = document.getElementById('navCourses');
  if (navCourses) navCourses.classList.toggle('active', id === 'viewCourses');
  const navRooms = document.getElementById('navRooms');
  if (navRooms) navRooms.classList.toggle('active', id === 'viewRooms');
  if (id === 'viewCourses') {
    setTopNavActive('courses');
  } else {
    setTopNavActive(null);
  }
}

// COURSES (cards: enrolled only)
async function renderCourseCards() {
  selectedCourse = null;
  selectedRoomId = null;                                 // reset room selection when leaving rooms view
  stopQueueLiveUpdates();
  if (window.SignoffWS) {
    window.SignoffWS.updateFilters({ courseId: null, roomId: null });
  }
  setCrumbs('Courses');
  // Show the dashboard view (coursesGrid lives inside viewDashboard)
  showView('viewDashboard');
  const progressSection = document.getElementById('progressSection');
  if (progressSection) progressSection.classList.add('hidden');
  // Populate both grids (dashboard + standalone courses view)
  const grid = document.getElementById('coursesGrid');
  if (grid) grid.innerHTML = skeletonCards(3);

  let courses = [];
  let coursesError = false;
  try {
    courses = await apiGet('./api/my_courses.php');
  } catch (err) {
    console.error('Failed to load courses', err);
    coursesError = true;
  }
  if (!Array.isArray(courses)) courses = [];

  if (coursesError && !courses.length) {
    if (grid) grid.innerHTML = `<div class="card"><strong>Unable to load courses.</strong><div class="muted small">Please check your connection and try again.</div></div>`;
    return;
  }
  if (!courses.length) {
    if (grid) grid.innerHTML = `<div class="card"><strong>No courses yet.</strong><div class="muted small">You're not enrolled in any courses.</div></div>`;
    return;
  }

  if (!grid) return;
  grid.innerHTML = '';
  courses.forEach(c => {
    const safeId = escapeHtml(String(c.course_id ?? ''));
    const card = document.createElement('div');
    card.className = 'course-card';

    const badge = document.createElement('span');
    badge.className = 'badge';
    badge.textContent = 'Course #' + (c.course_id ?? '');

    const title = document.createElement('h3');
    title.className = 'course-title';
    title.textContent = c.name || '';

    const wrap = document.createElement('div');
    wrap.className = 'mt-8';
    wrap.style.cssText = 'display:flex;gap:8px;flex-wrap:wrap';

    const lmsLink = document.createElement('a');
    lmsLink.className = 'btn btn-primary';
    lmsLink.href = './course.html?course_id=' + encodeURIComponent(String(c.course_id ?? ''));
    lmsLink.textContent = 'Course Page';

    const btn = document.createElement('button');
    btn.className = 'btn btn-ghost';
    btn.setAttribute('data-course', String(c.course_id ?? ''));
    btn.textContent = 'Rooms & Queues';

    wrap.appendChild(lmsLink);
    wrap.appendChild(btn);

    card.appendChild(badge);
    card.appendChild(title);
    card.appendChild(wrap);
    grid.appendChild(card);
  });
  grid.onclick = async (e) => {
    const btn = e.target.closest('button[data-course]');
    if (!btn) return;
    const id = btn.getAttribute('data-course');
    await showCourse(id);
  };
}

// ROOMS (cards) + PROGRESS (bottom)
async function showCourse(courseId) {
  selectedCourse = String(courseId);
  try {
    sessionStorage.setItem('signoff:lastCourseId', selectedCourse);
  } catch (err) {
    console.debug('Unable to persist course id', err);
  }
  if (window.SignoffWS) {
    window.SignoffWS.updateFilters({ courseId: Number(selectedCourse), roomId: selectedRoomId ? Number(selectedRoomId) : null });
  }
  setCrumbs(`Course #${selectedCourse}`);
  showView('viewRooms');
  const roomsTitleEl = document.getElementById('roomsTitle');
  if (roomsTitleEl) roomsTitleEl.textContent = `Rooms for Course #${selectedCourse}`;

  const grid = document.getElementById('roomsGrid');
  if (!grid) return;
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
      const safeRoomId = String(room.room_id ?? '');
      const url = `/signoff/room?course_id=${encodeURIComponent(selectedCourse)}&room_id=${encodeURIComponent(safeRoomId)}`;

      const header = document.createElement('div');
      header.className = 'flex align-center gap-10';
      const badge = document.createElement('span');
      badge.className = 'badge';
      badge.textContent = 'Room #' + safeRoomId;
      const h3 = document.createElement('h3');
      h3.className = 'room-title title-reset';
      h3.textContent = room.name || '';
      header.appendChild(badge);
      header.appendChild(h3);

      const actions = document.createElement('div');
      actions.className = 'room-actions mt-8 flex gap-8 flex-wrap';
      const link = document.createElement('a');
      link.className = 'btn btn-primary';
      link.href = url;
      link.textContent = 'Open room';
      actions.appendChild(link);

      card.appendChild(header);
      card.appendChild(actions);
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
        if (window.SignoffWS) {
          window.SignoffWS.updateFilters({ roomId: Number(selectedRoomId) });
        }
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
        if (window.SignoffWS) {
          window.SignoffWS.updateFilters({ roomId: null });
        }
        updateRoomSelectionUI();
      }
    }
  };

  const progressSection = document.getElementById('progressSection');
  if (progressSection) progressSection.classList.remove('hidden');
  await renderProgress(selectedCourse);

  const backBtn = document.getElementById('backToCourses');
  if (backBtn) {
    backBtn.onclick = () => {
      const progressSection = document.getElementById('progressSection');
      if (progressSection) progressSection.classList.add('hidden');
      renderCourseCards();
    };
  }
  const _nr = document.getElementById('navRooms');
  if (_nr) _nr.classList.add('active');
  const _nc = document.getElementById('navCourses');
  if (_nc) _nc.classList.remove('active');
}

// queues per room (unchanged logic, prettier buttons)
async function loadQueuesForRoom(roomId) {
  const wrap = document.getElementById(`queues-for-${roomId}`);
  if (!wrap) return;
  if (String(selectedRoomId || '') !== String(roomId)) return;
  wrap.innerHTML = '<div class="sk"></div>';
  stopQueueLiveUpdates();
  try {
    const queues = await apiGet('./api/queues.php?room_id=' + encodeURIComponent(roomId));
    if (String(selectedRoomId || '') !== String(roomId)) return;
    if (!Array.isArray(queues) || !queues.length) {
      wrap.innerHTML = `<div class="muted">No open queues for this room.</div>`;
      return;
    }
    wrap.innerHTML = '';
    const queueIds = [];
    queues.forEach(q => {
      const safeQid = String(q.queue_id ?? '');
      const row = document.createElement('div');
      row.className = 'queue-row';
      row.dataset.queueId = safeQid;
      row.innerHTML = `
        <div class="queue-header">
          <div class="queue-header-text">
            <div class="q-name">${escapeHtml(q.name)}</div>
            <div class="q-desc">${escapeHtml(q.description || '')}</div>
          </div>
          <div class="queue-meta">
            <div class="queue-count" data-role="queue-count">Loading…</div>
            <div class="queue-eta" data-role="queue-eta"></div>
          </div>
          <div class="queue-actions"></div>
        </div>
        <div class="queue-occupants empty" data-role="queue-occupants">
          <span class="muted small">Loading participants…</span>
        </div>
        <div class="queue-feedback small" data-role="queue-error" aria-live="polite"></div>
      `;
      // Build buttons programmatically to avoid attribute injection
      const actionsEl = row.querySelector('.queue-actions');
      const joinBtn = document.createElement('button');
      joinBtn.className = 'btn btn-ghost';
      joinBtn.dataset.join = safeQid;
      joinBtn.textContent = 'Join';
      const leaveBtn = document.createElement('button');
      leaveBtn.className = 'btn';
      leaveBtn.dataset.leave = safeQid;
      leaveBtn.textContent = 'Leave';
      actionsEl.appendChild(joinBtn);
      actionsEl.appendChild(leaveBtn);
      wrap.appendChild(row);
      queueIds.push(safeQid);
    });
    initQueueLiveUpdates(roomId, queueIds);
    wrap.onclick = async (e) => {
      const button = e.target.closest('button[data-join], button[data-leave]');
      if (!button) return;
      e.preventDefault();
      e.stopPropagation();

      const queueId = button.getAttribute('data-join') || button.getAttribute('data-leave');
      if (!queueId) return;
      const row = button.closest('.queue-row');
      if (!row) return;
      if (row.dataset.pending === '1') {
        return;
      }

      const joinBtn = row.querySelector('button[data-join]');
      const leaveBtn = row.querySelector('button[data-leave]');
      const errorEl = row.querySelector('[data-role="queue-error"]');
      if (errorEl) {
        errorEl.textContent = '';
      }

      row.dataset.pending = '1';
      [joinBtn, leaveBtn].forEach((btn) => { if (btn) btn.disabled = true; });
      const originalLabels = new Map();
      if (joinBtn) originalLabels.set(joinBtn, joinBtn.textContent);
      if (leaveBtn) originalLabels.set(leaveBtn, leaveBtn.textContent);

      const action = button.hasAttribute('data-join') ? 'join' : 'leave';
      button.textContent = action === 'join' ? 'Joining…' : 'Leaving…';
      const minDelay = new Promise((resolve) => setTimeout(resolve, 250));

      try {
        const response = await fetch('./api/queues.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({ action, queue_id: queueId })
        });
        const data = await response.json().catch(() => ({}));
        await minDelay;
        if (!response.ok || data.success !== true) {
          throw new Error('Request failed');
        }
        await loadQueuesForRoom(roomId);
      } catch (err) {
        await minDelay;
        if (errorEl) {
          errorEl.textContent = action === 'join'
            ? 'Unable to join right now. Please try again.'
            : 'Unable to leave right now. Please try again.';
        }
      } finally {
        delete row.dataset.pending;
        [joinBtn, leaveBtn].forEach((btn) => {
          if (!btn) return;
          btn.disabled = false;
          if (originalLabels.has(btn)) {
            btn.textContent = originalLabels.get(btn);
          }
        });
      }
    };
  } catch {
    if (String(selectedRoomId || '') === String(roomId)) {
      wrap.innerHTML = `<div class="muted">Failed to load queues.</div>`;
    }
  }
}

function updateRoomSelectionUI() {
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
  if (key === 'pending') return 'status-pending';
  if (key === 'completed') return 'status-completed';
  if (key === 'review') return 'status-review';
  return 'status-none';
}


// --- Main Progress Rendering ---

// progress rendered as horizontal “tables”
async function renderProgress(courseId) {
  const container = document.getElementById('progressContainer');
  if (!container) return;
  container.innerHTML = '<p>Loading progress...</p>'; // Simple loading state
  let data;
  try {
    data = await apiGet('./api/progress.php?course_id=' + encodeURIComponent(courseId || ''));
  } catch (err) {
    container.innerHTML = '<p class="muted small">Unable to load progress.</p>';
    console.warn('renderProgress failed', err);
    return;
  }
  const cats = (data && data.categories) || [];
  const byCat = (data && data.detailsByCategory) || {};
  const status = (data && data.userStatuses) || {}; // { detail_id: "None" | "Pending" | "Completed" | "Review" }

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

// sidebar nav (Courses/Rooms) — null-safe
const _navCourses = document.getElementById('navCourses');
if (_navCourses) _navCourses.onclick = () => renderCourseCards();
const _navRooms = document.getElementById('navRooms');
if (_navRooms) _navRooms.onclick = () => showView('viewRooms');

// helpers
function escapeHtml(s) {
  return String(s ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", "&#039;");
}
function skeletonCards(n = 3) {
  return Array.from({ length: n }).map(() => '<div class="sk"></div>').join('');
}

async function refreshQueueMeta(queueId) {
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

function initQueueLiveUpdates(roomId, queueIds) {
  const ids = (Array.isArray(queueIds) ? queueIds : [])
    .map((id) => String(id))
    .filter((id) => /^\d+$/.test(id));
  queueLiveState.roomId = roomId != null ? String(roomId) : null;
  queueLiveState.queueIds = new Set(ids);
  if (window.SignoffWS) {
    const numericRoom = queueLiveState.roomId != null ? Number(queueLiveState.roomId) : null;
    window.SignoffWS.updateFilters({ roomId: numericRoom });
  }
  ids.forEach((id) => { refreshQueueMeta(id); });
}

function stopQueueLiveUpdates() {
  queueLiveState.queueIds = new Set();
  queueLiveState.roomId = null;
  queuePendingFetches.clear();
  if (window.SignoffWS) {
    window.SignoffWS.updateFilters({ roomId: null });
  }
}

// ---------- Live updates (change log) ----------
function startSSE() {
  if (window.SignoffWS) {
    const numericCourse = selectedCourse ? Number(selectedCourse) : null;
    window.SignoffWS.updateFilters({ courseId: numericCourse });
  }
}

function stopSSE() {
  if (window.SignoffWS) {
    window.SignoffWS.updateFilters({ courseId: null });
  }
}

// ---------- TA notifications (student side) ----------
function startNotifySSE() {
  if (!window.SignoffWS) {
    return;
  }
  if (!selfUserId) {
    stopNotifySSE();
    return;
  }
  try {
    window.SignoffWS.setSelfUserId(selfUserId);
    window.SignoffWS.init({
      getFilters: () => ({
        courseId: selectedCourse ? Number(selectedCourse) : null,
        roomId: selectedRoomId ? Number(selectedRoomId) : null,
      }),
      onQueue: () => { reloadQueues(); },
      onProgress: () => { reloadProgress(); },
      onRooms: () => { reloadRooms(); },
      onTaAccept: (event) => { handleTaAcceptEvent(event); },
    });
  } catch (err) {
    console.error('Realtime init failed', err);
  }
}

function stopNotifySSE() {
  if (!window.SignoffWS) {
    return;
  }
  window.SignoffWS.setSelfUserId(null);
  window.SignoffWS.updateFilters({ courseId: null, roomId: null });
}

function handleTaAcceptPayload(payload) {
  if (!payload) return;
  const studentId = payload.student_user_id ?? payload.user_id;
  if (!selfUserId || studentId == null || Number(studentId) !== Number(selfUserId)) return;

  const taName = payload.ta_name && String(payload.ta_name).trim() ? String(payload.ta_name).trim() : 'A TA';
  const queueSource = payload.queue_id ?? payload.ref_id ?? null;
  const queueLabel = queueSource != null ? `#${queueSource}` : 'the queue';
  showTaAcceptModal(taName, queueLabel);
  playTaAcceptSound();

  if ('Notification' in window) {
    const notifyBody = `${taName} is ready for queue ${queueLabel}.`;
    if (Notification.permission === 'granted') {
      try { new Notification('You have been accepted', { body: notifyBody }); } catch (_) { }
    } else if (Notification.permission === 'default') {
      try {
        Notification.requestPermission().then((perm) => {
          if (perm === 'granted') {
            try { new Notification('You have been accepted', { body: notifyBody }); } catch (_) { }
          }
        }).catch(() => { });
      } catch (_) { }
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
      taAudioCtx.resume().catch(() => { });
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
