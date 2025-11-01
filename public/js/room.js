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

async function refreshQueues() {
  if (!state.roomId) return;
  setLoading(true);
  try {
    const queues = await fetchQueues(state.roomId);
    state.lastQueues = Array.isArray(queues) ? queues : [];
    renderQueues(state.lastQueues);
  } catch (err) {
    console.error(err);
    showErrorCard('We could not load queues for this room.');
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
    await refreshQueues();
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

function setLoading(isLoading) {
  state.loading = isLoading;
  if (!roomCard || !queuesContainer) return;
  if (isLoading) {
    roomCard.classList.add('loading');
    queuesContainer.innerHTML = skeletonCards(3);
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
