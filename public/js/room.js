const queuesContainer = document.getElementById('queuesContainer');
const roomTitleEl = document.getElementById('roomTitle');
const roomBadgeEl = document.getElementById('roomBadge');
const roomMetaEl = document.getElementById('roomMeta');
const roomMain = document.getElementById('roomMain');
const roomCard = document.getElementById('roomCard');
const toastStack = document.getElementById('toastStack');
let serveAudio;

const AVERAGE_SERVICE_MINUTES = 5;
const SERVED_SOUND_SRC = 'https://kairos.nixorcorporate.com/signoff/sounds/served-notification.mp3';

const state = {
  roomId: null,
  userId: null,
  userName: '',
  loading: false,
  queues: new Map(),
  queueRefs: new Map(),
  me: null,
  initialized: false,
  currentQueueIdInRoom: null,
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
      onTaAccept: handleTaAcceptPayload,
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
  state.currentQueueIdInRoom = null;
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

  refreshCurrentQueueMembership({ forceUpdateButtons: true });
}

function detectCurrentQueueIdInRoom() {
  if (state.userId == null) return null;
  for (const queue of state.queues.values()) {
    const hasMembership = queue.students.some(
      (student) => student.id === state.userId && (student.status === 'waiting' || student.status === 'serving'),
    );
    if (hasMembership) {
      return queue.queueId;
    }
  }
  return null;
}

function refreshCurrentQueueMembership({ forceUpdateButtons = false } = {}) {
  const detected = detectCurrentQueueIdInRoom();
  const changed = state.currentQueueIdInRoom !== detected;
  state.currentQueueIdInRoom = detected;

  if (changed || forceUpdateButtons) {
    state.queueRefs.forEach((refs, queueId) => {
      const queue = state.queues.get(queueId);
      if (queue) {
        updateQueueButtons(queue, refs);
        updateQueueNote(queue, refs);
      }
    });
  }
}

function getCurrentQueueIdInRoom() {
  if (state.currentQueueIdInRoom == null) {
    state.currentQueueIdInRoom = detectCurrentQueueIdInRoom();
  }
  return state.currentQueueIdInRoom;
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

  const waitEstimate = document.createElement('div');
  waitEstimate.className = 'muted small';
  waitEstimate.dataset.role = 'wait-estimate';
  titleWrap.appendChild(waitEstimate);

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

  const queueNote = document.createElement('div');
  queueNote.className = 'muted small';
  queueNote.dataset.role = 'queue-note';
  queueNote.hidden = true;

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
  card.appendChild(queueNote);
  card.appendChild(occupantsWrap);

  queuesContainer.appendChild(card);

  state.queueRefs.set(queue.queueId, {
    root: card,
    joinButton: joinBtn,
    leaveButton: leaveBtn,
    label,
    pills,
    occupantsWrap,
    waitEstimate,
    queueNote,
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

  refreshCurrentQueueMembership({ forceUpdateButtons: true });

  if (state.queues.size === 0) {
    showEmptyMessage();
  }
}

function updateQueueCard(queue) {
  const refs = state.queueRefs.get(queue.queueId);
  if (!refs) return;

  updateQueueStudents(queue, refs);
  updateQueueButtons(queue, refs);
  updateQueueWaitEstimate(queue, refs);
  updateQueueNote(queue, refs);
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
  const activeQueueId = getCurrentQueueIdInRoom();
  const inAnotherQueue = activeQueueId != null && activeQueueId !== queue.queueId;

  if (joinBtn.dataset.loading === '1') {
    joinBtn.disabled = true;
  } else {
    joinBtn.disabled = isWaiting || isServing || inAnotherQueue;
  }

  const leaveDisabled = !isWaiting;
  if (leaveBtn.dataset.loading === '1') {
    leaveBtn.disabled = true;
  } else {
    leaveBtn.disabled = leaveDisabled;
  }

  if (inAnotherQueue) {
    joinBtn.title = 'You are already in a queue in this room.';
  } else {
    joinBtn.removeAttribute('title');
  }
}

function updateQueueWaitEstimate(queue, refs) {
  if (!refs.waitEstimate) return;
  const label = formatEstimatedWait(queue, state.userId);
  refs.waitEstimate.textContent = label;
}

function updateQueueNote(queue, refs) {
  if (!refs.queueNote) return;
  const activeQueueId = getCurrentQueueIdInRoom();
  const isServingMe = queue.serving?.student_user_id != null && state.userId != null
    ? Number(queue.serving.student_user_id) === Number(state.userId)
    : false;

  let note = '';
  if (isServingMe) {
    note = 'You are currently being served in this queue.';
  } else if (activeQueueId && activeQueueId !== queue.queueId) {
    note = 'You are already in a queue in this room. Please leave that queue before joining another.';
  }

  refs.queueNote.textContent = note;
  refs.queueNote.hidden = !note;
}

function handleQueueBroadcast(message) {
  const payload = message?.payload !== undefined ? message.payload : message;
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

  refreshCurrentQueueMembership({ forceUpdateButtons: true });
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
  refreshCurrentQueueMembership();
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

function getWaitingCount(queue) {
  if (!queue) return 0;
  const waiting = queue.students.filter((student) => student.status === 'waiting');
  const waitingCount = waiting.length;
  if (Number.isFinite(queue.occupantCount)) {
    return Math.max(waitingCount, Number(queue.occupantCount));
  }
  return waitingCount;
}

function getStudentQueuePosition(queue, studentId) {
  if (!queue || studentId == null) return null;
  const waiting = queue.students.filter((student) => student.status === 'waiting');
  const index = waiting.findIndex((student) => student.id === studentId);
  return index >= 0 ? index + 1 : null;
}

function getEstimatedWaitMinutes(queue, studentId) {
  if (!queue) return null;
  const waitingCount = getWaitingCount(queue);
  const position = getStudentQueuePosition(queue, studentId);
  if (position != null) {
    return position * AVERAGE_SERVICE_MINUTES;
  }
  if (waitingCount <= 0) {
    return 0;
  }
  return (waitingCount + 1) * AVERAGE_SERVICE_MINUTES;
}

function formatEstimatedWait(queue, studentId) {
  const minutes = getEstimatedWaitMinutes(queue, studentId);
  if (minutes == null) return '';
  if (minutes <= 0) return 'Estimated wait: ~0–5 minutes';
  const rounded = Math.max(1, Math.round(minutes));
  return `Estimated wait: ~${rounded} minute${rounded === 1 ? '' : 's'}`;
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

  refreshCurrentQueueMembership();
  const activeQueueId = state.currentQueueIdInRoom;
  if (action === 'join' && activeQueueId && activeQueueId !== queueId) {
    showToast('You are already in a queue in this room. Please leave that queue before joining another.');
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

function handleTaAcceptPayload(payload) {
  const info = normalizeTaAcceptPayload(payload);
  if (!info) return;

  const matchesStudent = state.userId != null && info.studentId != null
    && Number(info.studentId) === Number(state.userId);
  if (!matchesStudent) return;

  if (state.roomId && info.roomId && Number(state.roomId) !== Number(info.roomId)) {
    return;
  }

  const taName = info.taName || 'A TA';
  playServeNotificationSound();
  showServeNotification(taName);

  if (info.queueId) {
    state.currentQueueIdInRoom = info.queueId;
  }
  refreshCurrentQueueMembership({ forceUpdateButtons: true });
}

function normalizeTaAcceptPayload(raw) {
  const source = raw && typeof raw === 'object' && raw.payload && typeof raw.payload === 'object'
    ? raw.payload
    : raw || {};

  const studentIdRaw = source.student_user_id ?? source.user_id ?? source.studentId ?? source.student_id;
  const roomIdRaw = source.room_id ?? source.roomId ?? null;
  const queueIdRaw = source.queue_id ?? source.queueId ?? source.ref_id ?? source.refId ?? raw?.ref_id ?? null;
  const taName = source.ta_name ?? source.taName ?? '';

  const studentId = Number(studentIdRaw);
  const roomId = roomIdRaw != null ? Number(roomIdRaw) : null;
  const queueId = queueIdRaw != null ? Number(queueIdRaw) : null;

  return {
    studentId: Number.isFinite(studentId) ? studentId : null,
    roomId: Number.isFinite(roomId) ? roomId : null,
    queueId: Number.isFinite(queueId) ? queueId : null,
    taName: taName || '',
  };
}

function playServeNotificationSound() {
  if (typeof Audio === 'undefined') return;
  try {
    if (!serveAudio) {
      serveAudio = new Audio(SERVED_SOUND_SRC);
      serveAudio.preload = 'auto';
    }
    serveAudio.currentTime = 0;
    const playPromise = serveAudio.play();
    if (playPromise?.catch) {
      playPromise.catch((err) => { console.debug('serve notification sound blocked', err); });
    }
  } catch (err) {
    console.debug('serve notification sound failed', err);
  }
}

function showServeNotification(taName) {
  const container = toastStack || document.body;
  const toast = document.createElement('div');
  toast.className = 'toast';

  const message = document.createElement('div');
  message.innerHTML = `<strong>${escapeHtml(taName)}</strong> is serving you now, please raise your hand.`;

  const closeBtn = document.createElement('button');
  closeBtn.type = 'button';
  closeBtn.setAttribute('aria-label', 'Dismiss notification');
  closeBtn.textContent = '×';
  closeBtn.style.marginLeft = '12px';
  closeBtn.style.background = 'transparent';
  closeBtn.style.border = 'none';
  closeBtn.style.color = 'inherit';
  closeBtn.style.fontSize = '16px';
  closeBtn.style.cursor = 'pointer';
  closeBtn.addEventListener('click', () => toast.remove());

  toast.appendChild(message);
  toast.appendChild(closeBtn);
  container.appendChild(toast);

  requestAnimationFrame(() => { toast.classList.add('show'); });
  setTimeout(() => {
    toast.classList.remove('show');
    setTimeout(() => toast.remove(), 250);
  }, 10000);
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
