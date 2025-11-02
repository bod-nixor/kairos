// ---------- Server-Sent Events helpers ----------
const queueStreamState = {
  source: null,
  queueIds: new Set(),
  currentUrl: '',
  lastEventId: null,
  onMessage: null,
  onOpen: null,
};

function buildQueueStreamUrl() {
  if (!queueStreamState.queueIds.size) {
    return null;
  }
  const ids = Array.from(queueStreamState.queueIds).sort((a, b) => Number(a) - Number(b));
  const url = new URL('./api/changes.php', window.location.origin);
  url.searchParams.set('channels', 'queue');
  url.searchParams.set('queue_id', ids.join(','));
  if (queueStreamState.lastEventId != null) {
    url.searchParams.set('since', String(queueStreamState.lastEventId));
  }
  return url.toString();
}

function closeQueueStream() {
  if (queueStreamState.source) {
    try { queueStreamState.source.close(); } catch (err) { /* ignore */ }
  }
  queueStreamState.source = null;
  queueStreamState.currentUrl = '';
}

function ensureQueueStream(force = false) {
  if (typeof queueStreamState.onMessage !== 'function' || !queueStreamState.queueIds.size) {
    closeQueueStream();
    return;
  }
  const nextUrl = buildQueueStreamUrl();
  if (!nextUrl) {
    closeQueueStream();
    return;
  }
  if (!force && queueStreamState.source && queueStreamState.currentUrl === nextUrl) {
    return;
  }

  closeQueueStream();

  const source = new EventSource(nextUrl, { withCredentials: true });
  queueStreamState.source = source;
  queueStreamState.currentUrl = nextUrl;

  const updateLastId = (event) => {
    if (!event || event.lastEventId == null) return;
    const parsed = Number(event.lastEventId);
    if (Number.isFinite(parsed) && parsed > 0) {
      queueStreamState.lastEventId = parsed;
    }
  };

  source.addEventListener('queue', (event) => {
    updateLastId(event);
    if (typeof queueStreamState.onMessage !== 'function') {
      return;
    }
    let data = null;
    if (typeof event?.data === 'string' && event.data !== '') {
      try {
        data = JSON.parse(event.data);
      } catch (err) {
        console.warn('Invalid SSE payload', err, event.data);
        return;
      }
    }
    queueStreamState.onMessage(data, event);
  });
  source.onmessage = updateLastId;
  source.onerror = () => {
    // rely on browser auto-reconnect
  };
  source.onopen = () => {
    if (typeof queueStreamState.onOpen === 'function') {
      try {
        queueStreamState.onOpen();
      } catch (err) {
        console.error('SSE open handler error', err);
      }
    }
  };
}

function setQueueStreamHandlers(onMessage, onOpen) {
  queueStreamState.onMessage = typeof onMessage === 'function' ? onMessage : null;
  queueStreamState.onOpen = typeof onOpen === 'function' ? onOpen : null;
  ensureQueueStream(true);
}

function updateQueueStreamIds(ids, force = false) {
  const normalized = new Set();
  if (Array.isArray(ids)) {
    ids.forEach((id) => {
      if (id == null) return;
      const str = String(id).trim();
      if (/^\d+$/.test(str)) {
        normalized.add(str);
      }
    });
  }
  let changed = force;
  if (!changed) {
    if (normalized.size !== queueStreamState.queueIds.size) {
      changed = true;
    } else {
      for (const id of normalized) {
        if (!queueStreamState.queueIds.has(id)) {
          changed = true;
          break;
        }
      }
    }
  }
  queueStreamState.queueIds = normalized;
  if (!normalized.size) {
    ensureQueueStream(true);
    return;
  }
  ensureQueueStream(changed);
}

window.addEventListener('beforeunload', () => {
  closeQueueStream();
});

// ---------- Room view logic ----------
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
  streamHandlersSet: false,
  refreshPending: false,
  refreshTimer: null,
  userId: null,
};

document.addEventListener('DOMContentLoaded', async () => {
  const roomId = parseRoomId();
  if (!roomId) {
    showErrorCard('Missing or invalid room ID.');
    return;
  }

  state.roomId = roomId;
  if (window.SignoffWS) {
    window.SignoffWS.updateFilters({ roomId: Number(roomId) });
  }
  document.title = `Room #${roomId}`;
  if (roomBadgeEl) roomBadgeEl.textContent = `Room #${roomId}`;
  if (roomTitleEl) roomTitleEl.textContent = `Room #${roomId}`;
  if (roomMetaEl) roomMetaEl.textContent = 'Queues currently open for this room.';

  let me = null;
  try {
    me = await ensureLoggedIn();
  } catch (err) {
    redirectToIndex();
    return;
  }

  if (window.SignoffWS) {
    if (me && me.user_id != null) {
      window.SignoffWS.setSelfUserId(Number(me.user_id));
    }
    window.SignoffWS.init({
      getFilters: () => ({ roomId: state.roomId ? Number(state.roomId) : null }),
      onQueue: () => {
        refreshQueues({ silent: true, skipIfLoading: true }).catch(() => {});
      },
    });
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
  state.userId = data.user_id != null ? Number(data.user_id) : null;
  return data;
}

function redirectToIndex() {
  window.location.replace('/signoff/');
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
      <p><a class="btn btn-primary" href="/signoff/">Return to dashboard</a></p>
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

  if (queueIds.length && (force || !state.refreshPending)) {
    scheduleQueueRefresh();
  }
}

function stopQueueUpdates() {
  if (state.refreshTimer) {
    clearTimeout(state.refreshTimer);
    state.refreshTimer = null;
  }
  state.refreshPending = false;
  state.streamHandlersSet = false;
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
