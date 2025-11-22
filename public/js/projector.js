const projectorState = {
  roomId: null,
  courseId: null,
  queues: new Map(),
  overlayTimer: null,
  me: null,
};

const gridEl = document.getElementById('projectorGrid');
const emptyEl = document.getElementById('projectorEmpty');
const roomEl = document.getElementById('projectorRoom');
const courseEl = document.getElementById('projectorCourse');
const overlayEl = document.getElementById('projectorOverlay');
const overlayNames = document.getElementById('overlayNames');
const overlaySubtitle = document.getElementById('overlaySubtitle');
const overlayTitle = document.getElementById('overlayTitle');

function parseIds() {
  const params = new URLSearchParams(window.location.search);
  const roomRaw = params.get('room_id');
  const courseRaw = params.get('course_id');
  const roomId = roomRaw && /^\d+$/.test(roomRaw) ? Number(roomRaw) : null;
  const courseId = courseRaw && /^\d+$/.test(courseRaw) ? Number(courseRaw) : null;
  projectorState.roomId = roomId;
  projectorState.courseId = courseId;
}

async function ensureTaAccess() {
  const res = await fetch('./api/session_capabilities.php', {
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  });
  if (!res.ok) throw new Error('auth');
  const json = await res.json();
  if (!json?.roles?.ta) {
    throw new Error('forbidden');
  }
}

async function loadMe() {
  const res = await fetch('./api/me.php', {
    credentials: 'same-origin',
    headers: { 'Cache-Control': 'no-cache' },
  });
  if (!res.ok) throw new Error('auth');
  projectorState.me = await res.json();
}

async function fetchQueues(roomId) {
  const endpoint = `./api/ta/queues.php?room_id=${encodeURIComponent(roomId)}`;
  const res = await fetch(endpoint, {
    credentials: 'same-origin',
    headers: { 'Cache-Control': 'no-cache' },
  });
  if (!res.ok) throw new Error(`queues ${res.status}`);
  return res.json();
}

function normalizeQueue(raw) {
  const queueId = Number(raw?.queue_id ?? 0);
  const name = raw?.name || `Queue #${queueId}`;
  const description = raw?.description || '';
  const studentsRaw = Array.isArray(raw?.students) ? raw.students : [];
  const students = studentsRaw.map((student) => ({
    id: Number(student?.id ?? student?.user_id ?? 0),
    name: student?.name || '',
    status: student?.status || 'waiting',
  }));
  let serving = null;
  const servingRaw = raw?.serving;
  if (servingRaw && typeof servingRaw === 'object') {
    serving = {
      student_user_id: servingRaw.student_user_id != null ? Number(servingRaw.student_user_id) : null,
      student_name: servingRaw.student_name || '',
      ta_user_id: servingRaw.ta_user_id != null ? Number(servingRaw.ta_user_id) : null,
      ta_name: servingRaw.ta_name || '',
    };
  }
  return {
    queueId,
    name,
    description,
    students,
    serving,
  };
}

function renderQueues() {
  if (!gridEl || !emptyEl) return;
  const queues = Array.from(projectorState.queues.values());
  gridEl.innerHTML = '';
  if (!queues.length) {
    emptyEl.style.display = 'block';
    return;
  }
  emptyEl.style.display = 'none';
  const fragment = document.createDocumentFragment();
  queues.forEach((queue) => {
    const card = document.createElement('div');
    card.className = 'projector-card';
    const title = document.createElement('h2');
    title.textContent = queue.name;
    card.appendChild(title);
    if (queue.description) {
      const desc = document.createElement('div');
      desc.className = 'queue-description';
      desc.textContent = queue.description;
      card.appendChild(desc);
    }
    const list = document.createElement('ul');
    list.className = 'projector-students';
    const orderedStudents = [...queue.students];
    orderedStudents.sort((a, b) => {
      if (a.status === 'serving' && b.status !== 'serving') return -1;
      if (a.status !== 'serving' && b.status === 'serving') return 1;
      return 0;
    });
    if (queue.serving?.student_user_id && !orderedStudents.some((s) => s.id === queue.serving.student_user_id)) {
      orderedStudents.unshift({
        id: queue.serving.student_user_id,
        name: queue.serving.student_name || 'Student',
        status: 'serving',
      });
    }
    if (!orderedStudents.length) {
      const empty = document.createElement('div');
      empty.className = 'projector-empty';
      empty.textContent = 'No students waiting.';
      card.appendChild(empty);
    } else {
      orderedStudents.forEach((student) => {
        const item = document.createElement('li');
        item.className = 'projector-student';
        if (student.status === 'serving') {
          item.classList.add('serving');
        }
        const name = document.createElement('span');
        name.textContent = student.name || 'Student';
        const status = document.createElement('span');
        status.className = 'status';
        status.textContent = student.status === 'serving' ? 'Being served' : 'Waiting';
        item.appendChild(name);
        item.appendChild(status);
        list.appendChild(item);
      });
      card.appendChild(list);
    }
    fragment.appendChild(card);
  });
  gridEl.appendChild(fragment);
}

function syncQueues(list) {
  projectorState.queues.clear();
  list.forEach((raw) => {
    const queue = normalizeQueue(raw);
    if (queue.queueId) {
      projectorState.queues.set(queue.queueId, queue);
    }
  });
  renderQueues();
}

function applyQueuePayload(payload) {
  const queueId = Number(payload.queueId ?? payload.queue_id ?? 0);
  if (!queueId || !projectorState.queues.has(queueId)) {
    reloadQueues();
    return;
  }
  const queue = projectorState.queues.get(queueId);
  if (!queue) return;
  if (payload.snapshot && Array.isArray(payload.snapshot.students)) {
    queue.students = payload.snapshot.students.map((student) => ({
      id: Number(student?.id ?? student?.user_id ?? 0),
      name: student?.name || '',
      status: student?.status || 'waiting',
    }));
  }
  if (payload.servingStudentId) {
    queue.serving = {
      student_user_id: Number(payload.servingStudentId),
      student_name: payload.servingStudentName || '',
      ta_user_id: payload.servingTaId != null ? Number(payload.servingTaId) : null,
      ta_name: payload.servingTaName || '',
    };
  } else if (payload.servingStudentId === null || payload.servingTaId === null) {
    queue.serving = null;
  }
  projectorState.queues.set(queueId, queue);
  renderQueues();
}

function handleQueueBroadcast(message) {
  const payload = message?.payload;
  if (!payload || payload.queueId == null) return;
  const roomId = payload.roomId != null ? Number(payload.roomId) : null;
  if (projectorState.roomId && roomId && Number(projectorState.roomId) !== roomId) {
    return;
  }
  if (payload.change === 'bulk_refresh') {
    reloadQueues();
    return;
  }
  applyQueuePayload(payload);
}

function showOverlay({ taName, studentName, type }) {
  if (!overlayEl || !overlayNames || !overlaySubtitle || !overlayTitle) return;
  const ta = taName || 'A TA';
  const student = studentName || 'a student';
  overlayTitle.textContent = type === 'call_again' ? 'Calling Again' : 'Now Serving';
  overlayNames.textContent = `${ta} is now serving ${student}.`;
  overlaySubtitle.textContent = 'Please raise your hand.';
  overlayEl.classList.add('active');
  if (projectorState.overlayTimer) {
    clearTimeout(projectorState.overlayTimer);
  }
  projectorState.overlayTimer = setTimeout(() => {
    overlayEl.classList.remove('active');
  }, 4500);
}

function handleProjectorEvent(payload) {
  if (!payload || typeof payload !== 'object') return;
  const roomId = payload.room_id ?? payload.roomId ?? null;
  if (projectorState.roomId && roomId && Number(roomId) !== Number(projectorState.roomId)) {
    return;
  }
  const type = payload.type || 'serve';
  const taName = payload.ta_name || '';
  const studentName = payload.student_name || '';
  showOverlay({ taName, studentName, type });
}

async function reloadQueues() {
  if (!projectorState.roomId) return;
  try {
    const data = await fetchQueues(projectorState.roomId);
    const list = Array.isArray(data?.queues) ? data.queues : [];
    syncQueues(list);
  } catch (err) {
    console.error('Failed to reload queues', err);
  }
}

async function bootstrapProjector() {
  parseIds();
  if (!projectorState.roomId) {
    if (roomEl) roomEl.textContent = 'Room unavailable';
    if (gridEl) gridEl.textContent = 'Missing room_id parameter.';
    return;
  }
  if (roomEl) roomEl.textContent = `Room #${projectorState.roomId}`;
  if (courseEl && projectorState.courseId) {
    courseEl.textContent = `Course #${projectorState.courseId}`;
  }
  try {
    await ensureTaAccess();
    await loadMe();
  } catch (err) {
    if (gridEl) {
      gridEl.textContent = 'Projector View is restricted to TAs.';
    }
    return;
  }
  await reloadQueues();
  if (window.SignoffWS) {
    if (projectorState.me?.user_id != null) {
      window.SignoffWS.setSelfUserId(Number(projectorState.me.user_id));
    }
    window.SignoffWS.init({
      channels: ['queue', 'projector'],
      getFilters: () => ({
        courseId: projectorState.courseId ?? null,
        roomId: projectorState.roomId ?? null,
      }),
      onQueue: handleQueueBroadcast,
      onProjector: handleProjectorEvent,
    });
  }
}

document.addEventListener('DOMContentLoaded', () => {
  bootstrapProjector();
});
