const WS_MAX_RECONNECT_DELAY = 15000;

function resolveWebSocketUrl(params = {}) {
  const query = new URLSearchParams(params);
  const explicitUrl = typeof window.SIGNOFF_WS_URL === 'string' && window.SIGNOFF_WS_URL.trim();
  if (explicitUrl) {
    const base = explicitUrl.trim();
    const joiner = base.includes('?') ? (query.toString() ? '&' : '') : (query.toString() ? '?' : '');
    return `${base}${joiner}${query.toString()}`;
  }

  const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
  const overrideHost = typeof window.SIGNOFF_WS_HOST === 'string' ? window.SIGNOFF_WS_HOST.trim() : '';
  const overridePort = typeof window.SIGNOFF_WS_PORT === 'string' ? window.SIGNOFF_WS_PORT.trim() :
    (typeof window.SIGNOFF_WS_PORT === 'number' ? String(window.SIGNOFF_WS_PORT) : '');
  const hostname = overrideHost || window.location.hostname;
  let port = overridePort || window.location.port;
  if (!port && !overrideHost && !explicitUrl) {
    port = '8090';
  }
  const path = typeof window.SIGNOFF_WS_PATH === 'string' && window.SIGNOFF_WS_PATH.trim()
    ? window.SIGNOFF_WS_PATH.trim()
    : '/ws/changes';
  const host = port ? `${hostname}:${port}` : hostname;
  const querySuffix = query.toString() ? `?${query.toString()}` : '';
  return `${protocol}//${host}${path}${querySuffix}`;
}

function createManagedSocketState() {
  return {
    socket: null,
    reconnectTimer: null,
    reconnectDelay: 1000,
    params: null,
    handlers: null,
  };
}

function scheduleSocketReconnect(state) {
  if (!state || !state.params) return;
  if (state.reconnectTimer) return;
  const delay = state.reconnectDelay || 1000;
  state.reconnectTimer = window.setTimeout(() => {
    state.reconnectTimer = null;
    state.reconnectDelay = Math.min((state.reconnectDelay || delay) * 2, WS_MAX_RECONNECT_DELAY);
    connectManagedSocket(state, state.params, state.handlers || {});
  }, delay);
}

function connectManagedSocket(state, params, handlers = {}) {
  if (!state) return;
  state.params = params;
  state.handlers = handlers;
  if (state.reconnectTimer) {
    clearTimeout(state.reconnectTimer);
    state.reconnectTimer = null;
  }
  if (state.socket) {
    try { state.socket.close(); } catch (err) { /* noop */ }
    state.socket = null;
  }

  if (typeof WebSocket === 'undefined') {
    console.warn('WebSocket is not supported in this environment.');
    return;
  }

  let socket;
  try {
    socket = new WebSocket(resolveWebSocketUrl(params));
  } catch (err) {
    scheduleSocketReconnect(state);
    return;
  }

  state.socket = socket;
  state.reconnectDelay = 1000;

  socket.addEventListener('open', (event) => {
    if (handlers.onOpen) handlers.onOpen(event, socket);
  });
  socket.addEventListener('message', (event) => {
    if (handlers.onMessage) handlers.onMessage(event, socket);
  });
  socket.addEventListener('close', (event) => {
    if (handlers.onClose) handlers.onClose(event, socket);
    state.socket = null;
    scheduleSocketReconnect(state);
  });
  socket.addEventListener('error', (event) => {
    if (handlers.onError) handlers.onError(event, socket);
  });
}

function disconnectManagedSocket(state) {
  if (!state) return;
  if (state.reconnectTimer) {
    clearTimeout(state.reconnectTimer);
    state.reconnectTimer = null;
  }
  if (state.socket) {
    try { state.socket.close(); } catch (err) { /* noop */ }
    state.socket = null;
  }
  state.params = null;
  state.handlers = null;
  state.reconnectDelay = 1000;
}

function parseSocketEventPayload(raw) {
  if (!raw) return null;
  if (typeof raw === 'object') return raw;
  try {
    const parsed = JSON.parse(raw);
    if (parsed && typeof parsed === 'object') {
      return parsed;
    }
  } catch (err) {
    /* ignore */
  }
  return null;
}

const queuesContainer = document.getElementById('queuesContainer');
const roomTitleEl = document.getElementById('roomTitle');
const roomBadgeEl = document.getElementById('roomBadge');
const roomMetaEl = document.getElementById('roomMeta');
const roomMain = document.getElementById('roomMain');
const roomCard = document.getElementById('roomCard');
const toastStack = document.getElementById('toastStack');

const state = {
  roomId: null,
  loading: false,
  lastQueues: [],
  updatesInitialized: false,
  socketState: createManagedSocketState(),
  refreshPending: false,
  refreshTimer: null,
  socketKey: null,
};

document.addEventListener('DOMContentLoaded', async () => {
  const roomId = parseRoomId();
  if (!roomId) {
    showErrorCard('Missing or invalid room ID.');
    return;
  }

  state.roomId = roomId;
  document.title = `Room #${roomId}`;
  if (roomBadgeEl) roomBadgeEl.textContent = `Room #${roomId}`;
  if (roomTitleEl) roomTitleEl.textContent = `Room #${roomId}`;
  if (roomMetaEl) roomMetaEl.textContent = 'Queues currently open for this room.';

  try {
    await ensureLoggedIn();
  } catch (err) {
    redirectToIndex();
    return;
  }

  await refreshQueues();
  initQueueUpdates();
  if (document.visibilityState === 'visible') {
    startQueueUpdates();
  }
});

function parseRoomId() {
  const params = new URLSearchParams(window.location.search);
  const raw = params.get('room_id');
  if (!raw) return null;
  const trimmed = raw.trim();
  if (!/^\d+$/.test(trimmed)) return null;
  const n = Number(trimmed);
  return Number.isFinite(n) && n > 0 ? n : null;
}

async function ensureLoggedIn() {
  const resp = await fetch('./api/me.php', {
    credentials: 'same-origin',
    headers: { 'Cache-Control': 'no-cache' },
  });
  if (!resp.ok) {
    throw new Error('auth');
  }
  const data = await resp.json();
  if (!data || !data.email) {
    throw new Error('auth');
  }
}

function redirectToIndex() {
  window.location.replace('./index.html');
}

async function refreshQueues(options = {}) {
  const { silent = false, skipIfLoading = false } = options;
  if (!state.roomId) return;
  if (skipIfLoading && state.loading) return;
  const shouldShowSkeleton = !silent || state.lastQueues.length === 0;
  setLoading(true, { showSkeleton: shouldShowSkeleton });
  try {
    const queues = await fetchQueues(state.roomId);
    state.lastQueues = Array.isArray(queues) ? queues : [];
    renderQueues(state.lastQueues);
    startQueueUpdates();
  } catch (err) {
    console.error(err);
    if (!silent) {
      showErrorCard('We could not load queues for this room.');
    }
  } finally {
    setLoading(false);
  }
}

async function fetchQueues(roomId) {
  const resp = await fetch(`./api/queues.php?room_id=${encodeURIComponent(roomId)}`, {
    credentials: 'same-origin',
    headers: { 'Cache-Control': 'no-cache' },
  });
  if (!resp.ok) {
    throw new Error(`queues ${resp.status}`);
  }
  const data = await resp.json();
  if (!Array.isArray(data)) {
    throw new Error('Invalid queues payload');
  }
  return data;
}

function renderQueues(list) {
  if (!queuesContainer) return;
  queuesContainer.innerHTML = '';

  if (!Array.isArray(list) || list.length === 0) {
    queuesContainer.innerHTML = `<div class="card"><strong>No open queues.</strong><div class="muted small">There are no queues for this room right now.</div></div>`;
    return;
  }

  const fragment = document.createDocumentFragment();

  list.forEach(queue => {
    const row = document.createElement('div');
    row.className = 'queue-row';

    const occupantCount = Number(queue?.occupant_count ?? 0);
    const occupants = Array.isArray(queue?.occupants) ? queue.occupants : [];
    const occupantLabel = occupantCount === 1 ? '1 person in queue' : `${occupantCount} people in queue`;
    const occupantPills = occupants.map(o => {
      const label = o?.name ? o.name : (o?.user_id ? `User #${o.user_id}` : 'Unknown user');
      return `<span class="pill">${escapeHtml(label)}</span>`;
    }).join('');

    const occupantSection = occupantCount > 0
      ? `<div class="queue-occupants"><div class="occupant-label">${escapeHtml(occupantLabel)}</div><div class="occupant-pills">${occupantPills}</div></div>`
      : `<div class="queue-occupants empty"><span>No one in this queue yet.</span></div>`;

    row.innerHTML = `
      <div class="queue-header">
        <div class="queue-header-text">
          <div class="q-name">${escapeHtml(queue?.name ?? '')}</div>
          <div class="q-desc">${escapeHtml(queue?.description ?? '')}</div>
        </div>
        <div class="queue-actions">
          <button class="btn btn-ghost" data-action="join" data-queue-id="${queue?.queue_id ?? ''}" data-loading-text="Joining...">Join</button>
          <button class="btn" data-action="leave" data-queue-id="${queue?.queue_id ?? ''}" data-loading-text="Leaving...">Leave</button>
        </div>
      </div>
      ${occupantSection}
    `;

    fragment.appendChild(row);
  });

  queuesContainer.appendChild(fragment);
}

queuesContainer?.addEventListener('click', async (event) => {
  const button = event.target.closest('button[data-action]');
  if (!button) return;

  const action = button.getAttribute('data-action');
  const queueId = button.getAttribute('data-queue-id');
  if (!queueId || !/^\d+$/.test(queueId)) {
    showToast('Invalid queue.');
    return;
  }

  try {
    setButtonLoading(button, true);
    await mutateQueue(action, Number(queueId));
    await refreshQueues({ silent: true });
  } catch (err) {
    console.error(err);
    showToast(err.message || 'Something went wrong.');
  } finally {
    setButtonLoading(button, false);
  }
});

async function mutateQueue(action, queueId) {
  if (action !== 'join' && action !== 'leave') {
    throw new Error('Unknown action.');
  }
  const resp = await fetch('./api/queues.php', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action, queue_id: queueId }),
  });
  if (!resp.ok) {
    throw new Error(action === 'join' ? 'Failed to join queue.' : 'Failed to leave queue.');
  }
  const data = await resp.json();
  if (data?.error) {
    throw new Error(data.error);
  }
  return data;
}

function setButtonLoading(button, isLoading) {
  if (!button) return;
  if (isLoading) {
    if (!button.dataset.originalText) {
      button.dataset.originalText = button.textContent ?? '';
    }
    const loadingText = button.getAttribute('data-loading-text');
    button.textContent = loadingText || 'Working...';
    button.disabled = true;
  } else {
    if (button.dataset.originalText) {
      button.textContent = button.dataset.originalText;
    }
    button.disabled = false;
  }
}

function setLoading(isLoading, { showSkeleton = true } = {}) {
  state.loading = isLoading;
  if (!roomCard || !queuesContainer) return;
  if (isLoading) {
    roomCard.classList.add('loading');
    if (showSkeleton) {
      queuesContainer.innerHTML = skeletonCards(3);
    }
  } else {
    roomCard.classList.remove('loading');
  }
}

function skeletonCards(n = 2) {
  return Array.from({ length: n }).map(() => '<div class="sk"></div>').join('');
}

function escapeHtml(value) {
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

function showErrorCard(message) {
  if (!roomMain) return;
  roomMain.innerHTML = `
    <section class="card error-card">
      <h2>Room unavailable</h2>
      <p class="muted">${escapeHtml(message)}</p>
      <p><a class="btn btn-primary" href="./index.html">Return to dashboard</a></p>
    </section>
  `;
}

function showToast(message) {
  if (!toastStack) return;
  const toast = document.createElement('div');
  toast.className = 'toast toast-error';
  toast.textContent = message;
  toastStack.appendChild(toast);
  requestAnimationFrame(() => {
    toast.classList.add('show');
  });
  setTimeout(() => {
    toast.classList.remove('show');
    setTimeout(() => toast.remove(), 250);
  }, 3200);
}

function initQueueUpdates() {
  if (state.updatesInitialized) return;
  state.updatesInitialized = true;
  document.addEventListener('visibilitychange', handleVisibilityChange, { passive: true });
  window.addEventListener('focus', handleVisibilityGain, { passive: true });
  window.addEventListener('blur', handleVisibilityLoss, { passive: true });
  window.addEventListener('beforeunload', stopQueueUpdates, { passive: true });
}

function startQueueUpdates(force = false) {
  if (!state.roomId) return;
  const queueIds = Array.isArray(state.lastQueues)
    ? state.lastQueues
        .map((q) => (q && q.queue_id != null ? String(q.queue_id) : null))
        .filter((id) => id && /^\d+$/.test(id))
    : [];
  const params = { channels: 'queue' };
  if (queueIds.length) {
    params.queue_id = queueIds.join(',');
  } else {
    params.room_id = state.roomId;
  }
  const key = JSON.stringify(params);
  if (!force && state.socketKey === key && state.socketState.socket) {
    return;
  }
  state.socketKey = key;
  connectManagedSocket(state.socketState, params, {
    onOpen: () => {
      scheduleQueueRefresh();
    },
    onMessage: (event) => {
      const payload = parseSocketEventPayload(event?.data);
      if (!payload || payload.type !== 'event' || payload.event !== 'queue') {
        return;
      }
      scheduleQueueRefresh();
    },
  });
}

function stopQueueUpdates() {
  if (state.refreshTimer) {
    clearTimeout(state.refreshTimer);
    state.refreshTimer = null;
  }
  state.refreshPending = false;
  disconnectManagedSocket(state.socketState);
  state.socketKey = null;
}

function scheduleQueueRefresh() {
  if (state.refreshPending) return;
  state.refreshPending = true;
  state.refreshTimer = window.setTimeout(() => {
    state.refreshPending = false;
    state.refreshTimer = null;
    refreshQueues({ silent: true, skipIfLoading: true }).catch(() => {});
  }, 150);
}

function handleVisibilityChange() {
  if (document.visibilityState === 'visible') {
    startQueueUpdates(true);
    refreshQueues({ silent: true, skipIfLoading: true });
  } else {
    stopQueueUpdates();
  }
}

function handleVisibilityGain() {
  if (document.visibilityState !== 'visible') return;
  startQueueUpdates(true);
  refreshQueues({ silent: true, skipIfLoading: true });
}

function handleVisibilityLoss() {
  if (document.visibilityState === 'visible') return;
  stopQueueUpdates();
}
