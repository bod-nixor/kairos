const queuesContainer = document.getElementById('queuesContainer');
const roomTitleEl = document.getElementById('roomTitle');
const roomBadgeEl = document.getElementById('roomBadge');
const roomMetaEl = document.getElementById('roomMeta');
const roomMain = document.getElementById('roomMain');
const roomCard = document.getElementById('roomCard');
const toastStack = document.getElementById('toastStack');

const state = {
  roomId: null,
  userId: null,
  userName: '',
  loading: false,
  queues: new Map(),
  queueRefs: new Map(),
  me: null,
  initialized: false,
};

document.addEventListener('DOMContentLoaded', async () => {
  if (state.initialized) return;
  state.initialized = true;

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
    const me = await ensureLoggedIn();
    state.me = me;
    state.userId = me?.user_id != null ? Number(me.user_id) : null;
    state.userName = me?.name || '';
  } catch (err) {
    redirectToIndex();
    return;
  }

  if (window.SignoffWS) {
    if (state.userId != null) {
      window.SignoffWS.setSelfUserId(state.userId);
    }
    window.SignoffWS.init({
      getFilters: () => ({ roomId: state.roomId ? Number(state.roomId) : null }),
      onQueue: handleQueueBroadcast,
    });
    window.SignoffWS.updateFilters({ roomId: Number(state.roomId) });
  }

  await loadInitialQueues();
});

async function loadInitialQueues() {
  if (!state.roomId) return;
  setLoading(true, { showSkeleton: true });
  try {
    const queues = await fetchQueues(state.roomId);
    applyInitialQueues(Array.isArray(queues) ? queues : []);
  } catch (err) {
    console.error('queues fetch failed', err);
    showErrorCard('We could not load queues for this room.');
  } finally {
    setLoading(false);
  }
}

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
  return data;
}

function redirectToIndex() {
  window.location.replace('/signoff/');
}

async function fetchQueues(roomId) {
  const resp = await fetch(`./api/queues.php?room_id=${encodeURIComponent(roomId)}`, {
    credentials: 'same-origin',
    headers: { 'Cache-Control': 'no-cache' },
  });
  if (!resp.ok) {
    throw new Error(`queues ${resp.status}`);
  }
  return resp.json();
}

function applyInitialQueues(list) {
  state.queues.clear();
  state.queueRefs.clear();
  if (queuesContainer) {
    queuesContainer.innerHTML = '';
  }

  if (!Array.isArray(list) || list.length === 0) {
    showEmptyMessage();
    return;
  }

  list.forEach((raw) => {
    const queue = normalizeQueue(raw);
    if (!queue.queueId) return;
    state.queues.set(queue.queueId, queue);
    createQueueCard(queue);
  });
}

function normalizeQueue(raw) {
  const queueId = Number(raw?.queue_id ?? raw?.queueId ?? 0);
  const roomId = Number(raw?.room_id ?? raw?.roomId ?? state.roomId ?? 0);
  const name = raw?.name || `Queue #${queueId}`;
  const description = raw?.description || '';
  const studentsRaw = Array.isArray(raw?.students) ? raw.students : [];
  const students = studentsRaw.map(normalizeQueueStudent);
  let serving = null;
  const servingRaw = raw?.serving;
  if (servingRaw && typeof servingRaw === 'object') {
    const taId = servingRaw.ta_user_id != null ? Number(servingRaw.ta_user_id) : null;
    const studentId = servingRaw.student_user_id != null ? Number(servingRaw.student_user_id) : null;
    if (taId || studentId) {
      serving = {
        ta_user_id: taId,
        ta_name: servingRaw.ta_name || '',
        student_user_id: studentId,
        student_name: servingRaw.student_name || '',
        started_at: servingRaw.started_at || null,
      };
    }
  }

  const occupants = students
    .filter((student) => student.status === 'waiting')
    .map((student) => ({
      user_id: student.id,
      name: student.name,
      joined_at: student.joinedAt || null,
    }));

  const occupantCount = raw?.occupant_count != null
    ? Number(raw.occupant_count)
    : occupants.length;

  const updatedAt = raw?.updated_at != null ? Number(raw.updated_at) : Math.floor(Date.now() / 1000);

  return {
    queueId,
    roomId,
    name,
    description,
    students,
    occupants,
    occupantCount,
    serving,
    updatedAt,
  };
}

function normalizeQueueStudent(student) {
  const id = Number(student?.id ?? student?.user_id ?? 0);
  let status = student?.status || 'waiting';
  if (!['waiting', 'serving', 'done'].includes(status)) {
    status = 'waiting';
  }
  const joinedAt = student?.joinedAt ?? student?.joined_at ?? null;
  return {
    id,
    name: student?.name || '',
    status,
    joinedAt,
  };
}

function createQueueCard(queue) {
  if (!queuesContainer) return null;
  hideEmptyMessage();

  const card = document.createElement('div');
  card.className = 'queue-row';
  card.dataset.queueId = String(queue.queueId);

  const header = document.createElement('div');
  header.className = 'queue-header';

  const titleWrap = document.createElement('div');
  titleWrap.className = 'queue-header-text';

  const title = document.createElement('div');
  title.className = 'q-name';
  title.textContent = queue.name || `Queue #${queue.queueId}`;
  titleWrap.appendChild(title);

  if (queue.description) {
    const desc = document.createElement('div');
    desc.className = 'q-desc';
    desc.textContent = queue.description;
    titleWrap.appendChild(desc);
  }

  const actions = document.createElement('div');
  actions.className = 'queue-actions';

  const joinBtn = document.createElement('button');
  joinBtn.className = 'btn btn-ghost';
  joinBtn.type = 'button';
  joinBtn.dataset.action = 'join';
  joinBtn.dataset.queueId = String(queue.queueId);
  joinBtn.dataset.loadingText = 'Joining…';
  joinBtn.textContent = 'Join';

  const leaveBtn = document.createElement('button');
  leaveBtn.className = 'btn';
  leaveBtn.type = 'button';
  leaveBtn.dataset.action = 'leave';
  leaveBtn.dataset.queueId = String(queue.queueId);
  leaveBtn.dataset.loadingText = 'Leaving…';
  leaveBtn.textContent = 'Leave';

  actions.appendChild(joinBtn);
  actions.appendChild(leaveBtn);

  header.appendChild(titleWrap);
  header.appendChild(actions);

  const occupantsWrap = document.createElement('div');
  occupantsWrap.className = 'queue-occupants';
  occupantsWrap.dataset.role = 'occupants';

  const label = document.createElement('div');
  label.className = 'occupant-label';
  label.dataset.role = 'occupant-label';
  occupantsWrap.appendChild(label);

  const pills = document.createElement('div');
  pills.className = 'occupant-pills';
  pills.dataset.role = 'occupant-pills';
  occupantsWrap.appendChild(pills);

  card.appendChild(header);
  card.appendChild(occupantsWrap);

  queuesContainer.appendChild(card);

  state.queueRefs.set(queue.queueId, {
    root: card,
    joinButton: joinBtn,
    leaveButton: leaveBtn,
    label,
    pills,
    occupantsWrap,
  });

  updateQueueCard(queue);
  return card;
}

function removeQueue(queueId) {
  const refs = state.queueRefs.get(queueId);
  if (refs?.root) {
    refs.root.remove();
  }
  state.queueRefs.delete(queueId);
  state.queues.delete(queueId);

  if (state.queues.size === 0) {
    showEmptyMessage();
  }
}

function updateQueueCard(queue) {
  const refs = state.queueRefs.get(queue.queueId);
  if (!refs) return;

  updateQueueStudents(queue, refs);
  updateQueueButtons(queue, refs);
}

function updateQueueStudents(queue, refs) {
  if (!refs.label || !refs.pills || !refs.occupantsWrap) return;

  const count = Number.isFinite(queue.occupantCount) ? queue.occupantCount : queue.occupants.length;
  if (count <= 0) {
    refs.label.textContent = 'No one in this queue yet.';
    refs.occupantsWrap.classList.add('empty');
    refs.pills.innerHTML = '<span>No one in this queue yet.</span>';
    return;
  }

  const label = count === 1 ? '1 person in queue' : `${count} people in queue`;
  refs.label.textContent = label;
  refs.occupantsWrap.classList.remove('empty');

  const fragment = document.createDocumentFragment();
  queue.occupants.forEach((occupant) => {
    const pill = document.createElement('span');
    pill.className = 'pill';
    pill.textContent = occupant?.name ? occupant.name : `User #${occupant?.user_id ?? ''}`;
    fragment.appendChild(pill);
  });
  refs.pills.innerHTML = '';
  refs.pills.appendChild(fragment);
}

function updateQueueButtons(queue, refs) {
  const joinBtn = refs.joinButton;
  const leaveBtn = refs.leaveButton;
  if (!joinBtn || !leaveBtn) return;

  const selfId = state.userId;
  const waitingIds = new Set(queue.occupants.map((occ) => occ.user_id));
  const isWaiting = selfId != null && waitingIds.has(selfId);
  const isServing = selfId != null && queue.students.some((student) => student.id === selfId && student.status === 'serving');

  if (joinBtn.dataset.loading === '1') {
    joinBtn.disabled = true;
  } else {
    joinBtn.disabled = isWaiting || isServing;
  }

  const leaveDisabled = !isWaiting;
  if (leaveBtn.dataset.loading === '1') {
    leaveBtn.disabled = true;
  } else {
    leaveBtn.disabled = leaveDisabled;
  }
}

function handleQueueBroadcast(message) {
  const payload = message?.payload;
  if (!payload || payload.queueId == null) {
    return;
  }
  const queueId = Number(payload.queueId);
  if (!Number.isFinite(queueId) || queueId <= 0) {
    return;
  }
  const roomId = payload.roomId != null ? Number(payload.roomId) : null;
  if (state.roomId && roomId && Number(state.roomId) !== roomId) {
    return;
  }

  if (!state.queues.has(queueId)) {
    reloadQueuesFromServer();
    return;
  }

  if (payload.change === 'bulk_refresh') {
    reloadQueuesFromServer();
    return;
  }

  applyQueuePayload(queueId, payload);
}

async function reloadQueuesFromServer() {
  if (!state.roomId) return;
  try {
    const queues = await fetchQueues(state.roomId);
    syncQueuesWithServer(Array.isArray(queues) ? queues : []);
  } catch (err) {
    console.error('Failed to refresh queues', err);
  }
}

function syncQueuesWithServer(list) {
  if (!queuesContainer) return;
  const seen = new Set();

  list.forEach((raw) => {
    const queue = normalizeQueue(raw);
    if (!queue.queueId) return;
    seen.add(queue.queueId);
    if (state.queues.has(queue.queueId)) {
      const existing = state.queues.get(queue.queueId);
      Object.assign(existing, queue);
      state.queues.set(queue.queueId, existing);
      updateQueueCard(existing);
    } else {
      state.queues.set(queue.queueId, queue);
      createQueueCard(queue);
    }
  });

  Array.from(state.queues.keys()).forEach((queueId) => {
    if (!seen.has(queueId)) {
      removeQueue(queueId);
    }
  });
}

function applyQueuePayload(queueId, payload) {
  const queue = state.queues.get(queueId);
  if (!queue) return;

  if (payload.snapshot && Array.isArray(payload.snapshot.students)) {
    queue.students = payload.snapshot.students.map(normalizeQueueStudent);
    queue.updatedAt = payload.snapshot.updatedAt != null
      ? Number(payload.snapshot.updatedAt)
      : Math.floor(Date.now() / 1000);
  } else if (payload.student && payload.change) {
    applyIncrementalQueueChange(queue, payload);
    queue.updatedAt = Math.floor(Date.now() / 1000);
  }

  if (payload.waitingCount != null) {
    queue.occupantCount = Number(payload.waitingCount);
  } else {
    queue.occupantCount = queue.students.filter((student) => student.status === 'waiting').length;
  }

  queue.occupants = queue.students
    .filter((student) => student.status === 'waiting')
    .map((student) => ({
      user_id: student.id,
      name: student.name,
      joined_at: student.joinedAt || null,
    }));

  const hasServingInfo = Object.prototype.hasOwnProperty.call(payload, 'servingTaId')
    || Object.prototype.hasOwnProperty.call(payload, 'servingStudentId')
    || Object.prototype.hasOwnProperty.call(payload, 'servingTaName')
    || Object.prototype.hasOwnProperty.call(payload, 'servingStudentName');

  if (hasServingInfo) {
    const servingTaId = payload.servingTaId != null ? Number(payload.servingTaId) : null;
    const servingStudentId = payload.servingStudentId != null ? Number(payload.servingStudentId) : null;
    const servingTaName = payload.servingTaName != null ? payload.servingTaName : (queue.serving?.ta_name ?? '');
    const servingStudentName = payload.servingStudentName != null ? payload.servingStudentName : (queue.serving?.student_name ?? '');

    if (servingTaId || servingStudentId) {
      queue.serving = {
        ta_user_id: servingTaId,
        ta_name: servingTaName,
        student_user_id: servingStudentId,
        student_name: servingStudentName,
        started_at: queue.serving?.started_at ?? null,
      };
    } else {
      queue.serving = null;
    }
  }

  state.queues.set(queueId, queue);
  updateQueueCard(queue);
}

function applyIncrementalQueueChange(queue, payload) {
  const studentPayload = payload.student;
  if (!studentPayload) return;
  const studentId = Number(studentPayload.id ?? studentPayload.user_id ?? 0);
  if (!studentId) return;

  if (payload.change === 'join') {
    const exists = queue.students.some((student) => student.id === studentId && student.status === 'waiting');
    if (!exists) {
      queue.students.push({
        id: studentId,
        name: studentPayload.name || '',
        status: 'waiting',
        joinedAt: null,
      });
    }
  } else if (payload.change === 'leave') {
    queue.students = queue.students.filter((student) => !(student.id === studentId && student.status === 'waiting'));
  } else if (payload.change === 'stop_serve') {
    queue.students = queue.students.filter((student) => student.id !== studentId || student.status !== 'serving');
  }
}

queuesContainer?.addEventListener('click', async (event) => {
  const button = event.target.closest('button[data-action]');
  if (!button) return;

  const action = button.getAttribute('data-action');
  const queueId = Number(button.getAttribute('data-queue-id'));
  if (!queueId) {
    showToast('Invalid queue.');
    return;
  }

  const queue = state.queues.get(queueId);
  if (!queue) {
    showToast('Queue unavailable.');
    return;
  }

  try {
    const loadingText = button.getAttribute('data-loading-text') || (action === 'join' ? 'Joining…' : 'Leaving…');
    startButtonLoading(button, loadingText);
    const response = await mutateQueue(action, queueId);

    if (response?.success) {
      if (action === 'join' && !response.already && state.userId != null) {
        const nextStudents = queue.students
          .filter((student) => !(student.id === state.userId && student.status === 'waiting'))
          .concat([{
            id: state.userId,
            name: state.userName || 'You',
            status: 'waiting',
            joinedAt: null,
          }]);
        applyQueuePayload(queueId, {
          queueId,
          roomId: queue.roomId,
          change: 'join',
          student: { id: state.userId, name: state.userName || '' },
          waitingCount: nextStudents.filter((student) => student.status === 'waiting').length,
          snapshot: {
            students: nextStudents.map((student) => ({
              id: student.id,
              name: student.name,
              status: student.status,
              joinedAt: student.joinedAt || null,
            })),
            updatedAt: Math.floor(Date.now() / 1000),
          },
          servingTaId: queue.serving?.ta_user_id ?? null,
          servingTaName: queue.serving?.ta_name ?? null,
          servingStudentId: queue.serving?.student_user_id ?? null,
          servingStudentName: queue.serving?.student_name ?? null,
        });
        stopButtonLoading(button, { keepDisabled: true });
      } else if (action === 'leave' && !response.already && state.userId != null) {
        const nextStudents = queue.students.filter((student) => !(student.id === state.userId && student.status === 'waiting'));
        applyQueuePayload(queueId, {
          queueId,
          roomId: queue.roomId,
          change: 'leave',
          student: { id: state.userId, name: state.userName || '' },
          waitingCount: nextStudents.filter((student) => student.status === 'waiting').length,
          snapshot: {
            students: nextStudents.map((student) => ({
              id: student.id,
              name: student.name,
              status: student.status,
              joinedAt: student.joinedAt || null,
            })),
            updatedAt: Math.floor(Date.now() / 1000),
          },
          servingTaId: queue.serving?.ta_user_id ?? null,
          servingTaName: queue.serving?.ta_name ?? null,
          servingStudentId: queue.serving?.student_user_id ?? null,
          servingStudentName: queue.serving?.student_name ?? null,
        });
        stopButtonLoading(button);
      } else {
        stopButtonLoading(button);
        updateQueueCard(queue);
      }
    } else {
      const message = response?.message || response?.error;
      throw new Error(message || 'Request failed');
    }
  } catch (err) {
    console.error('queue mutate failed', err);
    stopButtonLoading(button);
    const message = err?.message || 'Something went wrong.';
    showToast(message);
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
  const contentType = resp.headers.get('content-type') || '';
  const data = contentType.includes('application/json') ? await resp.json().catch(() => ({})) : {};
  if (!resp.ok || data?.success !== true) {
    const message = data?.message || data?.error;
    const error = new Error(message || 'Request failed');
    error.status = resp.status;
    throw error;
  }
  return data;
}

function startButtonLoading(button, label) {
  if (!button) return;
  if (!button.dataset.originalContent) {
    button.dataset.originalContent = button.innerHTML;
  }
  button.dataset.loading = '1';
  const text = label || 'Working…';
  button.innerHTML = `<span class="btn-spinner" aria-hidden="true"></span><span class="btn-label">${escapeHtml(text)}</span>`;
  button.disabled = true;
}

function stopButtonLoading(button, { keepDisabled = false } = {}) {
  if (!button) return;
  if (button.dataset.originalContent) {
    button.innerHTML = button.dataset.originalContent;
    delete button.dataset.originalContent;
  }
  if (!keepDisabled) {
    button.disabled = false;
  }
  delete button.dataset.loading;
}

function setLoading(isLoading, { showSkeleton = false } = {}) {
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

function showEmptyMessage() {
  if (!queuesContainer) return;
  queuesContainer.innerHTML = `<div class="card"><strong>No open queues.</strong><div class="muted small">There are no queues for this room right now.</div></div>`;
}

function hideEmptyMessage() {
  if (!queuesContainer) return;
  const empty = queuesContainer.querySelector('.card');
  if (empty && !empty.dataset.queueId) {
    empty.remove();
  }
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

function escapeHtml(value) {
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}
