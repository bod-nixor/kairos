let currentUser = null;
let currentRoomId = null;
let currentCourseId = null;
let pollTimer = null;

async function apiGet(url) {
  const response = await fetch(url, {
    credentials: 'same-origin',
    headers: { 'Cache-Control': 'no-cache' }
  });
  if (!response.ok) {
    throw new Error(`${url} -> ${response.status}`);
  }
  return response.json();
}

function escapeHtml(value) {
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

function skeletonQueues(n = 2, h = 140) {
  return Array.from({ length: n })
    .map(() => `<div class="sk" style="height:${h}px"></div>`)
    .join('');
}

function setMessage(text = '', type = 'info') {
  const box = document.getElementById('roomMessage');
  if (!box) return;
  if (!text) {
    box.classList.add('hidden');
    box.classList.remove('error', 'success');
    box.textContent = '';
    box.dataset.type = '';
    return;
  }
  box.textContent = text;
  box.dataset.type = type;
  box.classList.remove('hidden');
  box.classList.toggle('error', type === 'error');
  box.classList.toggle('success', type === 'success');
}

function formatEta(minutes) {
  if (!Number.isFinite(minutes) || minutes <= 0) {
    return 'under a minute';
  }
  const rounded = Math.max(1, Math.round(minutes));
  const hours = Math.floor(rounded / 60);
  const mins = rounded % 60;
  const parts = [];
  if (hours > 0) parts.push(`${hours}h`);
  if (mins > 0) parts.push(`${mins}m`);
  if (!parts.length) parts.push('under a minute');
  return parts.join(' ');
}

function renderQueueRow(queue) {
  const row = document.createElement('div');
  row.className = 'queue-row';
  row.dataset.queueId = String(queue.queue_id ?? '');

  const occupants = Array.isArray(queue.occupants) ? queue.occupants : [];
  const occupantCount = Number.isFinite(Number(queue.occupant_count))
    ? Number(queue.occupant_count)
    : occupants.length;

  const myIndex = occupants.findIndex((o) => Number(o?.user_id) === Number(currentUser?.user_id));
  const isInQueue = myIndex >= 0;

  const avgMinutesRaw = Number(queue.avg_handle_minutes);
  const avgMinutes = Number.isFinite(avgMinutesRaw) && avgMinutesRaw > 0 ? avgMinutesRaw : 5;

  let etaText = 'No wait time — queue is empty right now.';
  if (isInQueue) {
    const eta = Math.max(0, myIndex * avgMinutes);
    etaText = `Your position: ${myIndex + 1} · ETA ${formatEta(eta)}.`;
  } else if (occupantCount > 0) {
    const eta = occupantCount * avgMinutes;
    const label = occupantCount === 1 ? 'person ahead' : 'people ahead';
    etaText = `Estimated wait: ${formatEta(eta)} (${occupantCount} ${label}).`;
  }

  const occupantLabel = occupantCount === 1 ? '1 person waiting' : `${occupantCount} people waiting`;
  const occupantPills = occupants
    .map((person) => {
      const label = person?.name ? person.name : (person?.user_id ? `User #${person.user_id}` : 'Unknown user');
      const me = Number(person?.user_id) === Number(currentUser?.user_id);
      return `<span class="pill${me ? ' me' : ''}">${escapeHtml(label)}</span>`;
    })
    .join('');

  const occupantSection = occupantCount > 0
    ? `<div class="queue-occupants"><div class="occupant-label">${escapeHtml(occupantLabel)}</div><div class="occupant-pills">${occupantPills}</div></div>`
    : '<div class="queue-occupants empty"><span>No one in this queue yet.</span></div>';

  const joinDisabled = isInQueue ? ' disabled' : '';
  const leaveDisabled = isInQueue ? '' : ' disabled';
  const joinLabel = isInQueue ? 'In queue' : 'Join queue';

  row.innerHTML = `
    <div class="queue-header">
      <div class="queue-header-text">
        <div class="q-name">${escapeHtml(queue.name)}</div>
        <div class="q-desc">${escapeHtml(queue.description || '')}</div>
      </div>
      <div class="queue-actions">
        <button class="btn btn-ghost" data-action="join" data-queue="${queue.queue_id}"${joinDisabled}>${joinLabel}</button>
        <button class="btn" data-action="leave" data-queue="${queue.queue_id}"${leaveDisabled}>Leave</button>
      </div>
    </div>
    <div class="queue-meta small">${escapeHtml(etaText)}</div>
    ${occupantSection}
  `;

  return row;
}

async function loadQueues({ silent = false } = {}) {
  const container = document.getElementById('queuesContainer');
  if (!container || !currentRoomId) return;

  if (!silent) {
    container.innerHTML = skeletonQueues();
  }

  try {
    const queues = await apiGet('./api/queues.php?room_id=' + encodeURIComponent(currentRoomId));
    container.innerHTML = '';
    if (!Array.isArray(queues) || queues.length === 0) {
      container.innerHTML = '<div class="card">No queues are available for this room.</div>';
    } else {
      for (const queue of queues) {
        container.appendChild(renderQueueRow(queue));
      }
    }
    if (!silent) {
      setMessage('');
    }
  } catch (err) {
    console.error('Failed to load queues', err);
    container.innerHTML = '<div class="card">Unable to load queues right now.</div>';
    if (!silent) {
      setMessage('Unable to load queues right now. Please try again.', 'error');
    }
  }
}

async function handleQueueAction(action, queueId) {
  if (!queueId || (action !== 'join' && action !== 'leave')) return;
  try {
    const response = await fetch('./api/queues.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ action, queue_id: queueId })
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || payload?.error) {
      throw new Error(payload?.error || 'Request failed');
    }
    await loadQueues({ silent: true });
    setMessage(action === 'join' ? 'Joined queue.' : 'Left queue.', 'success');
  } catch (err) {
    console.error('Queue action failed', err);
    setMessage(err.message || 'Queue action failed. Please try again.', 'error');
  }
}

function startQueuePolling() {
  stopQueuePolling();
  pollTimer = setInterval(() => {
    loadQueues({ silent: true });
  }, 8000);
}

function stopQueuePolling() {
  if (pollTimer) {
    clearInterval(pollTimer);
    pollTimer = null;
  }
}

async function loadRoomMetadata() {
  const titleEl = document.getElementById('roomTitle');
  const courseEl = document.getElementById('roomCourse');

  let rooms = [];
  const query = currentCourseId
    ? `./api/rooms.php?course_id=${encodeURIComponent(currentCourseId)}`
    : './api/rooms.php';

  try {
    rooms = await apiGet(query);
  } catch (err) {
    console.error('Failed to fetch room metadata', err);
  }

  const room = Array.isArray(rooms)
    ? rooms.find((r) => String(r.room_id) === String(currentRoomId))
    : null;

  if (!room) {
    setMessage('Room not found or you do not have access to it.', 'error');
    if (titleEl) titleEl.textContent = 'Unknown room';
    return false;
  }

  if (titleEl) {
    titleEl.textContent = room.name ? escapeHtml(room.name) : `Room #${room.room_id}`;
  }

  currentCourseId = currentCourseId || room.course_id || null;
  if (courseEl && currentCourseId) {
    courseEl.textContent = `Course #${currentCourseId}`;
  } else if (courseEl) {
    courseEl.textContent = '';
  }

  document.getElementById('breadcrumbs').textContent = `Room #${room.room_id}`;

  try {
    sessionStorage.setItem('signoff:lastCourseId', String(currentCourseId || ''));
  } catch (err) {
    console.debug('Unable to persist course id', err);
  }

  return true;
}

async function bootstrapRoom() {
  const params = new URLSearchParams(window.location.search);
  currentRoomId = params.get('room_id');
  currentCourseId = params.get('course_id');

  if (!currentRoomId) {
    setMessage('Missing room_id parameter.', 'error');
    return;
  }

  if (!/^\d+$/.test(String(currentRoomId))) {
    setMessage('Invalid room_id parameter.', 'error');
    return;
  }

  currentRoomId = String(Number(currentRoomId));
  if (!currentRoomId) {
    setMessage('Invalid room_id parameter.', 'error');
    return;
  }

  if (currentCourseId && /^\d+$/.test(String(currentCourseId))) {
    currentCourseId = String(Number(currentCourseId));
  } else {
    currentCourseId = null;
  }

  try {
    const me = await apiGet('./api/me.php');
    if (!me?.user_id) throw new Error('Not signed in');
    currentUser = me;
    const avatar = document.getElementById('avatar');
    const nameEl = document.getElementById('name');
    const emailEl = document.getElementById('email');
    if (avatar) avatar.src = me.picture_url || '';
    if (nameEl) nameEl.textContent = me.name || '';
    if (emailEl) emailEl.textContent = me.email || '';
    document.getElementById('userbar')?.classList.remove('hidden');
  } catch (err) {
    console.error('Auth failed on room page', err);
    window.location.href = './index.html';
    return;
  }

  const loaded = await loadRoomMetadata();
  if (!loaded) return;

  await loadQueues({ silent: true });
  startQueuePolling();
}

document.addEventListener('DOMContentLoaded', () => {
  bootstrapRoom();

  document.getElementById('backToCourse')?.addEventListener('click', () => {
    stopQueuePolling();
    const target = currentCourseId ? `./index.html#course=${encodeURIComponent(currentCourseId)}` : './index.html';
    window.location.href = target;
  });

  document.getElementById('logoutBtn')?.addEventListener('click', async () => {
    stopQueuePolling();
    await fetch('./api/logout.php', { method: 'POST', credentials: 'same-origin' });
    window.location.href = './index.html';
  });

  const container = document.getElementById('queuesContainer');
  container?.addEventListener('click', (event) => {
    const btn = event.target.closest('[data-action][data-queue]');
    if (!btn) return;
    const action = btn.getAttribute('data-action');
    const queueId = btn.getAttribute('data-queue');
    if (btn.disabled) return;
    btn.disabled = true;
    handleQueueAction(action, queueId).finally(() => {
      btn.disabled = false;
    });
  });
});

window.addEventListener('beforeunload', () => {
  stopQueuePolling();
});

document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'hidden') {
    stopQueuePolling();
  } else if (document.visibilityState === 'visible' && currentRoomId) {
    startQueuePolling();
  }
});
