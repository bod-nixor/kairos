const taState = {
  me: null,
  courses: [],
  rooms: [],
  queues: [],
  statusOptions: [],
  selectedCourse: '',
  selectedRoom: '',
  selectedStudent: null,
  studentDirectory: {},
  queueRefs: new Map(),
};

const toastStack = document.getElementById('taToastStack');

function reloadRooms() {
  if (taState.selectedCourse) {
    loadRooms(taState.selectedCourse);
  }
}

function reloadQueues() {
  if (taState.selectedRoom) {
    loadQueues(taState.selectedRoom);
  }
}

function reloadProgress() {
  if (taState.selectedStudent) {
    loadStudentProgress(taState.selectedStudent);
  }
}

const TA_SECTIONS = {
  auth: 'taAuthRequired',
  forbidden: 'taForbidden',
  dashboard: 'taDashboard',
};

document.addEventListener('DOMContentLoaded', () => {
  bootstrapTA();
  const logout = document.getElementById('taLogoutBtn');
  if (logout) {
    logout.addEventListener('click', async () => {
      try { await fetch('./api/logout.php', { method: 'POST', credentials: 'same-origin' }); }
      finally { window.location.href = '/signoff/'; }
    });
  }
  const courseSelect = document.getElementById('taCourseSelect');
  if (courseSelect) {
    courseSelect.addEventListener('change', (e) => {
      const val = e.target.value;
      taState.selectedCourse = val || '';
      taState.selectedRoom = '';
      taState.queues = [];
      taState.selectedStudent = null;
      renderRooms([]);
      renderQueues();
      clearStudentPanel();
      updateProjectorButton();
      if (window.SignoffWS) {
        window.SignoffWS.updateFilters({
          courseId: val ? Number(val) : null,
          roomId: null,
        });
      }
      if (val) {
        loadRooms(val);
      } else {
        const roomSelect = document.getElementById('taRoomSelect');
        if (roomSelect) roomSelect.disabled = true;
      }
    });
  }
  const roomSelect = document.getElementById('taRoomSelect');
  if (roomSelect) {
    roomSelect.addEventListener('change', (e) => {
      const val = e.target.value;
      taState.selectedRoom = val || '';
      taState.queues = [];
      taState.selectedStudent = null;
      renderQueues();
      clearStudentPanel();
      updateProjectorButton();
      if (window.SignoffWS) {
        window.SignoffWS.updateFilters({ roomId: val ? Number(val) : null });
      }
      if (val) {
        loadQueues(val);
      }
    });
  }
  const projectorBtn = document.getElementById('taProjectorBtn');
  if (projectorBtn) {
    projectorBtn.addEventListener('click', () => {
      if (projectorBtn.disabled) return;
      openProjectorView();
    });
  }
  const progressArea = document.getElementById('taProgressArea');
  if (progressArea) {
    progressArea.addEventListener('change', (e) => {
      const select = e.target.closest('select[data-detail]');
      if (!select) return;
      const detailId = parseInt(select.dataset.detail, 10);
      if (!detailId || !taState.selectedStudent) return;
      const status = select.value;
      select.disabled = true;
      updateProgress(detailId, status).finally(() => {
        select.disabled = false;
      });
    });
  }
  const commentForm = document.getElementById('taCommentForm');
  if (commentForm) {
    commentForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (!taState.selectedStudent || !taState.selectedCourse) return;
      const textarea = document.getElementById('taCommentText');
      const text = textarea ? textarea.value.trim() : '';
      if (!text) return;
      try {
        const res = await apiPost('./api/ta/comment.php', {
          user_id: parseInt(taState.selectedStudent, 10),
          course_id: parseInt(taState.selectedCourse, 10),
          text,
        });
        if (res?.comment) {
          prependComment(res.comment);
          if (textarea) textarea.value = '';
        }
      } catch (err) {
        console.error('comment failed', err);
        alert('Failed to add comment.');
      }
    });
  }
  const queueList = document.getElementById('taQueueList');
  if (queueList) {
    queueList.addEventListener('click', (e) => {
      const actionBtn = e.target.closest('[data-action]');
      if (!actionBtn) return;
      const queueId = parseInt(actionBtn.dataset.queueId || '0', 10);
      const userId = parseInt(actionBtn.dataset.userId || '0', 10);
      const action = actionBtn.dataset.action;
      if (!queueId) return;
      if (action === 'accept' && userId) {
        handleAccept(queueId, userId, actionBtn);
      }
      if (action === 'view' && userId) {
        taState.selectedStudent = userId;
        highlightSelectedStudent();
        loadStudentProgress(userId);
      }
      if (action === 'stop-serving') {
        handleStopServing(queueId, actionBtn);
      }
      if (action === 'call-again') {
        handleCallAgain(queueId, actionBtn);
      }
    });
  }
});

async function bootstrapTA() {
  try {
    const me = await apiGet('./api/me.php');
    if (!me?.email) {
      setTaView('auth');
      return;
    }
    taState.me = me;
    updateUserbar(me);
    if (window.SignoffWS) {
      if (me.user_id != null) {
        window.SignoffWS.setSelfUserId(Number(me.user_id));
      }
    window.SignoffWS.init({
      getFilters: () => ({
        courseId: taState.selectedCourse ? Number(taState.selectedCourse) : null,
        roomId: taState.selectedRoom ? Number(taState.selectedRoom) : null,
      }),
      onQueue: handleQueueBroadcast,
      onRooms: () => reloadRooms(),
      onProgress: () => reloadProgress(),
    });
  }
  } catch (err) {
    console.error('me.php failed', err);
    setTaView('auth');
    return;
  }

  let courses = [];
  try {
    courses = await apiGet('./api/ta/courses.php');
  } catch (err) {
    if (err.status === 403) {
      setTaView('forbidden');
      return;
    }
    if (err.status === 401) {
      setTaView('auth');
      return;
    }
    console.error('courses failed', err);
  }
  if (!Array.isArray(courses)) courses = [];
  taState.courses = courses;
  renderCourses(courses);
  setTaView('dashboard');
  updateProjectorButton();
  if (courses.length === 1) {
    taState.selectedCourse = `${courses[0].course_id}`;
    const select = document.getElementById('taCourseSelect');
    if (select) {
      select.value = taState.selectedCourse;
      select.dispatchEvent(new Event('change'));
    }
  }
  if (!courses.length) {
    const queueList = document.getElementById('taQueueList');
    if (queueList) {
      queueList.innerHTML = '<div class="card">No TA courses assigned yet.</div>';
    }
  }
}

function setTaView(view) {
  Object.entries(TA_SECTIONS).forEach(([key, id]) => {
    const el = document.getElementById(id);
    if (el) el.classList.toggle('hidden', key !== view);
  });
  const userbar = document.getElementById('taUserbar');
  if (userbar) {
    userbar.classList.toggle('hidden', view !== 'dashboard');
  }
}

function updateUserbar(me) {
  const avatar = document.getElementById('taAvatar');
  const name = document.getElementById('taName');
  const email = document.getElementById('taEmail');
  if (avatar) avatar.src = me.picture_url || '';
  if (name) name.textContent = me.name || '';
  if (email) email.textContent = me.email || '';
}

function renderCourses(courses) {
  const select = document.getElementById('taCourseSelect');
  if (!select) return;
  select.innerHTML = '<option value="">Select a course…</option>';
  courses.forEach((course) => {
    const opt = document.createElement('option');
    opt.value = course.course_id;
    opt.textContent = `#${course.course_id} · ${course.name}`;
    select.appendChild(opt);
  });
  select.disabled = !courses.length;
}

async function loadRooms(courseId) {
  try {
    const rooms = await apiGet(`./api/ta/rooms.php?course_id=${encodeURIComponent(courseId)}`);
    taState.rooms = Array.isArray(rooms) ? rooms : [];
    renderRooms(taState.rooms);
  } catch (err) {
    console.error('rooms failed', err);
    renderRooms([]);
    alert('Failed to load rooms for this course.');
  }
}

function renderRooms(rooms) {
  const select = document.getElementById('taRoomSelect');
  if (!select) return;
  select.innerHTML = '<option value="">Select a room…</option>';
  rooms.forEach((room) => {
    const opt = document.createElement('option');
    opt.value = room.room_id;
    opt.textContent = room.name ? `${room.name} (#${room.room_id})` : `Room #${room.room_id}`;
    select.appendChild(opt);
  });
  select.disabled = !rooms.length;
}

function updateProjectorButton() {
  const btn = document.getElementById('taProjectorBtn');
  if (!btn) return;
  const enabled = !!taState.selectedRoom;
  btn.disabled = !enabled;
  btn.title = enabled ? 'Open Projector View' : 'Select a room to open Projector View';
}

function openProjectorView() {
  if (!taState.selectedRoom) return;
  const url = new URL('./projector.html', window.location.origin);
  url.searchParams.set('room_id', taState.selectedRoom);
  if (taState.selectedCourse) {
    url.searchParams.set('course_id', taState.selectedCourse);
  }
  window.open(url.toString(), '_blank', 'noopener');
}

async function loadQueues(roomId) {
  try {
    const data = await apiGet(`./api/ta/queues.php?room_id=${encodeURIComponent(roomId)}`);
    taState.queues = Array.isArray(data?.queues) ? data.queues.map(normalizeTaQueue) : [];
    renderQueues();
  } catch (err) {
    console.error('queues failed', err);
    taState.queues = [];
    renderQueues();
    alert('Failed to load queues for this room.');
  }
}

function renderQueues() {
  const list = document.getElementById('taQueueList');
  const notice = document.getElementById('taServingNotice');
  if (!list) return;
  updateProjectorButton();
  list.innerHTML = '';
  taState.queueRefs = new Map();
  taState.studentDirectory = {};
  if (notice) notice.textContent = '';

  if (!taState.selectedRoom) {
    list.innerHTML = '<div class="card">Select a room to view queues.</div>';
    return;
  }
  if (!Array.isArray(taState.queues) || !taState.queues.length) {
    list.innerHTML = '<div class="card">No active queues in this room.</div>';
    return;
  }

  const fragment = document.createDocumentFragment();
  taState.queues.forEach((queue) => {
    const card = createTaQueueCard(queue);
    if (card) fragment.appendChild(card);
  });
  list.appendChild(fragment);
  updateServingNotice();
}

function normalizeTaQueue(raw) {
  const queueId = Number(raw?.queue_id ?? 0);
  const name = raw?.name || `Queue #${queueId}`;
  const description = raw?.description || '';
  const studentsRaw = Array.isArray(raw?.students) ? raw.students : [];
  const students = studentsRaw.map(normalizeQueueStudentEntry);
  let occupants = Array.isArray(raw?.occupants)
    ? raw.occupants.map((occ) => ({
        user_id: occ?.user_id != null ? Number(occ.user_id) : null,
        name: occ?.name || '',
        joined_at: occ?.joined_at || null,
      })).filter((occ) => occ.user_id != null)
    : [];
  if (!occupants.length) {
    occupants = students
      .filter((student) => student.status === 'waiting')
      .map((student) => ({
        user_id: student.id,
        name: student.name,
        joined_at: student.joinedAt || null,
      }));
  }
  const occupantCount = raw?.occupant_count != null
    ? Number(raw.occupant_count)
    : occupants.length;

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

  const updatedAt = raw?.updated_at != null ? Number(raw.updated_at) : Math.floor(Date.now() / 1000);

  return {
    queue_id: queueId,
    room_id: raw?.room_id != null ? Number(raw.room_id) : null,
    name,
    description,
    occupant_count: occupantCount,
    occupants,
    students,
    serving,
    updated_at: updatedAt,
  };
}

function normalizeQueueStudentEntry(student) {
  const id = Number(student?.id ?? student?.user_id ?? 0);
  let status = student?.status || 'waiting';
  if (!['waiting', 'serving', 'done'].includes(status)) {
    status = 'waiting';
  }
  const joinedAt = student?.joined_at ?? student?.joinedAt ?? null;
  return {
    id,
    name: student?.name || '',
    status,
    joinedAt,
  };
}

function createTaQueueCard(queue) {
  const card = document.createElement('div');
  card.className = 'ta-queue-card';
  card.dataset.queueId = queue.queue_id;

  const header = document.createElement('div');
  header.className = 'ta-queue-header';

  const titleWrap = document.createElement('div');
  const title = document.createElement('h3');
  title.className = 'ta-queue-title';
  title.textContent = queue.name || `Queue #${queue.queue_id}`;
  titleWrap.appendChild(title);
  if (queue.description) {
    const desc = document.createElement('div');
    desc.className = 'ta-queue-desc';
    desc.textContent = queue.description;
    titleWrap.appendChild(desc);
  }
  header.appendChild(titleWrap);

  const statusWrap = document.createElement('div');
  statusWrap.className = 'ta-queue-status';
  const servingEl = document.createElement('div');
  servingEl.className = 'ta-queue-serving';
  statusWrap.appendChild(servingEl);
  const stopSlot = document.createElement('div');
  stopSlot.dataset.role = 'stop-slot';
  statusWrap.appendChild(stopSlot);
  header.appendChild(statusWrap);

  card.appendChild(header);

  const list = document.createElement('ul');
  list.className = 'ta-student-list';
  card.appendChild(list);

  const empty = document.createElement('div');
  empty.className = 'ta-queue-empty hidden';
  empty.textContent = 'No students waiting.';
  card.appendChild(empty);

  taState.queueRefs.set(queue.queue_id, {
    root: card,
    list,
    empty,
    serving: servingEl,
    stopSlot,
  });

  updateTaQueueCard(queue);
  return card;
}

function updateTaQueueCard(queue) {
  updateTaQueueStudents(queue);
  updateTaServingStatus(queue);
  highlightSelectedStudent();
}

function updateTaQueueStudents(queue) {
  const refs = taState.queueRefs.get(queue.queue_id);
  if (!refs || !refs.list || !refs.empty) return;

  const list = refs.list;
  const empty = refs.empty;
  const waiting = Array.isArray(queue.occupants) ? queue.occupants.filter((occ) => occ && occ.user_id != null) : [];

  waiting.forEach((occ) => {
    if (occ?.user_id != null) {
      taState.studentDirectory[occ.user_id] = occ.name || '';
    }
  });

  if (!waiting.length) {
    list.innerHTML = '';
    list.classList.add('hidden');
    empty.classList.remove('hidden');
    return;
  }

  empty.classList.add('hidden');
  list.classList.remove('hidden');

  const existing = new Map();
  Array.from(list.children).forEach((node) => {
    if (!(node instanceof HTMLElement)) return;
    const userId = Number(node.dataset.userId);
    if (userId) {
      existing.set(userId, node);
    }
  });

  const newOrder = [];
  waiting.forEach((occ) => {
    const userId = Number(occ.user_id);
    if (!userId) return;
    let item = existing.get(userId);
    if (item) {
      existing.delete(userId);
      updateTaStudentItem(item, queue, occ);
    } else {
      item = createTaStudentItem(queue, occ);
    }
    newOrder.push(item);
  });

  existing.forEach((node) => node.remove());

  newOrder.forEach((node, index) => {
    const current = list.children[index];
    if (current !== node) {
      list.insertBefore(node, current ?? null);
    }
  });
}

function createTaStudentItem(queue, occ) {
  const li = document.createElement('li');
  li.className = 'ta-student-item';
  li.dataset.queueId = queue.queue_id;
  li.dataset.userId = occ.user_id;

  const info = document.createElement('div');
  info.className = 'ta-student-info';
  const name = document.createElement('div');
  name.className = 'ta-student-name';
  info.appendChild(name);
  const meta = document.createElement('div');
  meta.className = 'ta-student-meta';
  info.appendChild(meta);
  li.appendChild(info);

  const actions = document.createElement('div');
  actions.className = 'ta-student-actions';
  const viewBtn = document.createElement('button');
  viewBtn.className = 'btn btn-ghost';
  viewBtn.type = 'button';
  viewBtn.dataset.action = 'view';
  viewBtn.dataset.queueId = queue.queue_id;
  viewBtn.dataset.userId = occ.user_id;
  viewBtn.textContent = 'View';
  actions.appendChild(viewBtn);

  const acceptBtn = document.createElement('button');
  acceptBtn.className = 'btn btn-primary';
  acceptBtn.type = 'button';
  acceptBtn.dataset.action = 'accept';
  acceptBtn.dataset.queueId = queue.queue_id;
  acceptBtn.dataset.userId = occ.user_id;
  acceptBtn.textContent = 'Accept';
  actions.appendChild(acceptBtn);

  li.appendChild(actions);
  updateTaStudentItem(li, queue, occ);
  return li;
}

function updateTaStudentItem(li, queue, occ) {
  const userId = Number(occ?.user_id ?? 0);
  const nameEl = li.querySelector('.ta-student-name');
  const metaEl = li.querySelector('.ta-student-meta');
  const acceptBtn = li.querySelector('button[data-action="accept"]');

  if (nameEl) {
    nameEl.textContent = occ?.name || `Student #${userId || ''}`;
  }
  if (metaEl) {
    const since = occ?.joined_at ? formatTime(occ.joined_at) : 'Waiting';
    metaEl.textContent = since ? `Waiting since ${since}` : 'Waiting';
  }

  if (taState.selectedStudent === userId) {
    li.classList.add('active');
  } else {
    li.classList.remove('active');
  }

  if (acceptBtn) {
    const serving = queue.serving;
    if (acceptBtn.dataset.loading === '1') {
      acceptBtn.disabled = true;
    } else if (serving && serving.student_user_id && serving.student_user_id !== userId) {
      acceptBtn.disabled = true;
      acceptBtn.textContent = 'In use';
    } else if (serving && serving.student_user_id === userId) {
      acceptBtn.disabled = true;
      acceptBtn.textContent = 'Serving';
    } else {
      acceptBtn.disabled = false;
      acceptBtn.textContent = 'Accept';
    }
  }
}

function updateTaServingStatus(queue) {
  const refs = taState.queueRefs.get(queue.queue_id);
  if (!refs) return;
  const servingEl = refs.serving;
  const stopSlot = refs.stopSlot;

  if (servingEl) {
    if (queue.serving && queue.serving.student_name) {
      const taName = queue.serving.ta_name || 'TA';
      servingEl.textContent = `Serving ${queue.serving.student_name} (${taName})`;
    } else if (queue.serving && queue.serving.student_user_id) {
      servingEl.textContent = 'Serving a student';
    } else {
      servingEl.textContent = '';
    }
  }

  if (stopSlot) {
    stopSlot.innerHTML = '';
    if (queue.serving && queue.serving.ta_user_id && taState.me && queue.serving.ta_user_id === taState.me.user_id) {
      const actions = document.createElement('div');
      actions.className = 'ta-serving-actions';
      const callBtn = document.createElement('button');
      callBtn.type = 'button';
      callBtn.className = 'btn btn-secondary btn-sm';
      callBtn.dataset.action = 'call-again';
      callBtn.dataset.queueId = queue.queue_id;
      callBtn.textContent = 'Call Again';
      actions.appendChild(callBtn);
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'btn btn-danger btn-sm';
      btn.dataset.action = 'stop-serving';
      btn.dataset.queueId = queue.queue_id;
      btn.textContent = 'Stop Serving';
      actions.appendChild(btn);
      stopSlot.appendChild(actions);
    }
  }

  updateServingNotice();
}

function updateServingNotice() {
  const notice = document.getElementById('taServingNotice');
  if (!notice) return;
  let message = '';
  const selfId = taState.me?.user_id != null ? Number(taState.me.user_id) : null;
  if (selfId != null) {
    for (const queue of taState.queues) {
      if (queue?.serving && queue.serving.ta_user_id === selfId) {
        const queueName = queue.name || `Queue #${queue.queue_id}`;
        const studentName = queue.serving.student_name || 'a student';
        message = `Serving ${studentName} in ${queueName}`;
        break;
      }
    }
  }
  notice.textContent = message;
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
  if (taState.selectedRoom) {
    const roomId = payload.roomId != null ? Number(payload.roomId) : null;
    if (roomId && Number(taState.selectedRoom) !== roomId) {
      return;
    }
  }

  const queue = taState.queues.find((q) => q.queue_id === queueId);
  if (!queue) {
    if (payload.change === 'bulk_refresh' && taState.selectedRoom) {
      loadQueues(taState.selectedRoom);
    }
    return;
  }

  if (payload.change === 'bulk_refresh') {
    if (taState.selectedRoom) {
      loadQueues(taState.selectedRoom);
    }
    return;
  }

  if (payload.snapshot && Array.isArray(payload.snapshot.students)) {
    queue.students = payload.snapshot.students.map(normalizeQueueStudentEntry);
  }

  if (payload.waitingCount != null) {
    queue.occupant_count = Number(payload.waitingCount);
  } else {
    queue.occupant_count = queue.students.filter((student) => student.status === 'waiting').length;
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
    const taId = payload.servingTaId != null ? Number(payload.servingTaId) : null;
    const studentId = payload.servingStudentId != null ? Number(payload.servingStudentId) : null;
    const taName = payload.servingTaName != null ? payload.servingTaName : (queue.serving?.ta_name || '');
    const studentName = payload.servingStudentName != null ? payload.servingStudentName : (queue.serving?.student_name || '');

    if (taId || studentId) {
      queue.serving = {
        ta_user_id: taId,
        ta_name: taName,
        student_user_id: studentId,
        student_name: studentName,
        started_at: queue.serving?.started_at || null,
      };
    } else {
      queue.serving = null;
    }
  }

  queue.updated_at = payload.snapshot?.updatedAt != null
    ? Number(payload.snapshot.updatedAt)
    : Math.floor(Date.now() / 1000);

  updateTaQueueCard(queue);
}

async function handleAccept(queueId, userId, button) {
  if (!taState.selectedCourse) {
    alert('Select a course before accepting students.');
    return;
  }
  startButtonLoading(button, 'Accepting…');
  try {
    await apiPost('./api/ta/accept.php', {
      queue_id: queueId,
      user_id: userId,
    });
    taState.selectedStudent = userId;
    const queue = taState.queues.find((q) => q.queue_id === queueId);
    if (queue) {
      const studentName = taState.studentDirectory?.[userId] || queue.occupants.find((occ) => occ.user_id === userId)?.name || '';
      queue.occupants = queue.occupants.filter((occ) => occ.user_id !== userId);
      queue.students = queue.students.filter((student) => !(student.id === userId && student.status === 'waiting'));
      queue.students.push({
        id: userId,
        name: studentName,
        status: 'serving',
        joinedAt: null,
      });
      queue.occupant_count = queue.occupants.length;
      queue.serving = {
        ta_user_id: taState.me?.user_id ?? null,
        ta_name: taState.me?.name || '',
        student_user_id: userId,
        student_name: studentName,
        started_at: new Date().toISOString(),
      };
      stopButtonLoading(button, { keepDisabled: true });
      updateTaQueueCard(queue);
    }
    highlightSelectedStudent();
    await loadStudentProgress(userId);
  } catch (err) {
    console.error('accept failed', err);
    const body = err?.body;
    const message = typeof body === 'string' ? body : (body?.message || body?.error);
    alert(message || 'Failed to accept student.');
    stopButtonLoading(button);
  }
}

async function handleStopServing(queueId, button) {
  const queue = taState.queues.find((q) => q.queue_id === queueId);
  if (!queue || !queue.serving) {
    return;
  }
  startButtonLoading(button, 'Stopping…');
  try {
    const res = await apiPost('./api/ta/stop.php', { queue_id: queueId });
    if (res?.success) {
      queue.serving = null;
      queue.students = queue.students.filter((student) => student.status !== 'serving');
      queue.occupant_count = queue.students.filter((student) => student.status === 'waiting').length;
      stopButtonLoading(button, { keepDisabled: true });
      updateTaQueueCard(queue);
      showToast('Serving session ended.');
    } else {
      const message = res?.message || res?.error;
      throw new Error(message || 'Failed to stop serving.');
    }
  } catch (err) {
    console.error('stop serving failed', err);
    stopButtonLoading(button);
    const message = err?.message || 'Failed to stop serving.';
    showToast(message, { tone: 'error' });
  }
}

async function handleCallAgain(queueId, button) {
  if (!queueId) return;
  startButtonLoading(button, 'Calling…');
  try {
    const res = await apiPost('./api/ta/call_again.php', { queue_id: queueId });
    if (res?.success) {
      showToast('Call sent to projector.');
    } else {
      const message = res?.message || res?.error;
      throw new Error(message || 'Failed to notify projector.');
    }
  } catch (err) {
    console.error('call again failed', err);
    const message = err?.message || 'Failed to notify projector.';
    showToast(message);
  } finally {
    stopButtonLoading(button);
  }
}

async function loadStudentProgress(userId) {
  if (!taState.selectedCourse || !userId) return;
  try {
    const data = await apiGet(`./api/ta/student_progress.php?course_id=${encodeURIComponent(taState.selectedCourse)}&user_id=${encodeURIComponent(userId)}`);
    renderStudentPanel(data, userId);
  } catch (err) {
    console.error('progress failed', err);
    alert('Failed to load student progress.');
  }
}

function renderStudentPanel(data, userId) {
  const panel = document.getElementById('taStudentPanel');
  const empty = document.getElementById('taEmptyPanel');
  if (!panel || !empty) return;
  empty.classList.add('hidden');
  panel.classList.remove('hidden');

  const student = findStudentInQueues(userId);
  const nameEl = document.getElementById('taStudentName');
  const fallbackName = taState.studentDirectory?.[userId] || `Student #${userId}`;
  if (nameEl) nameEl.textContent = student?.name || fallbackName;
  const courseLabel = document.getElementById('taStudentCourse');
  if (courseLabel) courseLabel.textContent = taState.selectedCourse ? `Course #${taState.selectedCourse}` : '';

  taState.statusOptions = Array.isArray(data?.statuses) ? data.statuses : [];
  renderProgressList(data);
  renderCommentList(data?.comments || []);
}

function renderProgressList(data) {
  const container = document.getElementById('taProgressArea');
  if (!container) return;
  container.innerHTML = '';
  const categories = Array.isArray(data?.categories) ? data.categories : [];
  const detailsByCategory = data?.detailsByCategory || {};
  const statuses = data?.userStatuses || {};
  const options = buildStatusOptions();

  if (!categories.length) {
    container.innerHTML = '<div class="ta-queue-empty">No progress items configured for this course.</div>';
    return;
  }

  categories.forEach((cat) => {
    const wrapper = document.createElement('div');
    const title = document.createElement('div');
    title.className = 'ta-progress-category';
    title.textContent = cat.name;
    wrapper.appendChild(title);
    const group = document.createElement('div');
    group.className = 'ta-progress-group';
    const items = detailsByCategory[cat.category_id] || [];
    items.forEach((detail) => {
      const row = document.createElement('div');
      row.className = 'ta-progress-item';
      const name = document.createElement('div');
      name.className = 'detail-name';
      name.textContent = detail.name;
      const select = document.createElement('select');
      select.dataset.detail = detail.detail_id;
      options.forEach((optName) => {
        const opt = document.createElement('option');
        opt.value = optName;
        opt.textContent = optName;
        if ((statuses && statuses[detail.detail_id]) === optName) {
          opt.selected = true;
        }
        select.appendChild(opt);
      });
      if (statuses && !options.includes(statuses[detail.detail_id])) {
        select.value = statuses[detail.detail_id];
      }
      row.appendChild(name);
      row.appendChild(select);
      group.appendChild(row);
    });
    wrapper.appendChild(group);
    container.appendChild(wrapper);
  });
}

function renderCommentList(comments) {
  const list = document.getElementById('taCommentList');
  if (!list) return;
  list.innerHTML = '';
  if (!comments.length) {
    list.innerHTML = '<div class="ta-queue-empty">No comments yet.</div>';
    return;
  }
  comments.forEach((comment) => {
    const card = document.createElement('div');
    card.className = 'ta-comment';
    const meta = document.createElement('div');
    meta.className = 'ta-comment-meta';
    const name = comment.ta_name || 'TA';
    meta.textContent = `${name} · ${formatTime(comment.created_at)}`;
    const text = document.createElement('div');
    text.textContent = comment.text || '';
    card.appendChild(meta);
    card.appendChild(text);
    list.appendChild(card);
  });
}

function prependComment(comment) {
  const list = document.getElementById('taCommentList');
  if (!list) return;
  if (!list.childElementCount || list.firstElementChild?.classList.contains('ta-queue-empty')) {
    list.innerHTML = '';
  }
  const card = document.createElement('div');
  card.className = 'ta-comment';
  const meta = document.createElement('div');
  meta.className = 'ta-comment-meta';
  const name = comment.ta_name || (taState.me?.name ?? 'TA');
  meta.textContent = `${name} · ${formatTime(comment.created_at)}`;
  const text = document.createElement('div');
  text.textContent = comment.text || '';
  card.appendChild(meta);
  card.appendChild(text);
  list.prepend(card);
}

function clearStudentPanel() {
  taState.selectedStudent = null;
  const panel = document.getElementById('taStudentPanel');
  const empty = document.getElementById('taEmptyPanel');
  if (panel) panel.classList.add('hidden');
  if (empty) empty.classList.remove('hidden');
  const list = document.getElementById('taCommentList');
  if (list) list.innerHTML = '';
  const progress = document.getElementById('taProgressArea');
  if (progress) progress.innerHTML = '';
}

async function updateProgress(detailId, status) {
  try {
    await apiPost('./api/ta/update_progress.php', {
      user_id: parseInt(taState.selectedStudent, 10),
      detail_id: detailId,
      status,
    });
  } catch (err) {
    console.error('update progress failed', err);
    alert('Failed to update progress.');
  }
}

function highlightSelectedStudent() {
  document.querySelectorAll('.ta-student-item').forEach((li) => {
    const uid = parseInt(li.dataset.userId || '0', 10);
    if (taState.selectedStudent && uid === taState.selectedStudent) {
      li.classList.add('active');
    } else {
      li.classList.remove('active');
    }
  });
}

function findStudentInQueues(userId) {
  for (const queue of taState.queues) {
    if (queue?.serving && queue.serving.student_user_id === userId) {
      return {
        user_id: queue.serving.student_user_id,
        name: queue.serving.student_name,
        joined_at: queue.serving.started_at || null,
      };
    }
    for (const occ of queue.occupants || []) {
      if (occ.user_id === userId) return occ;
    }
  }
  return null;
}

function buildStatusOptions() {
  const options = ['None', 'Pending', 'Completed', 'Review'];
  taState.statusOptions.forEach((row) => {
    if (!row?.name) return;
    const name = row.name;
    if (!options.includes(name)) options.push(name);
  });
  return options;
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

async function apiGet(url) {
  const res = await fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
  const contentType = res.headers.get('content-type') || '';
  if (!res.ok) {
    const err = new Error(`${url} -> ${res.status}`);
    err.status = res.status;
    err.body = contentType.includes('application/json') ? await res.json().catch(() => null) : await res.text();
    throw err;
  }
  if (!contentType.includes('application/json')) return {};
  return res.json();
}

async function apiPost(url, body) {
  const res = await fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body || {}),
  });
  const contentType = res.headers.get('content-type') || '';
  if (!res.ok) {
    const err = new Error(`${url} -> ${res.status}`);
    err.status = res.status;
    err.body = contentType.includes('application/json') ? await res.json().catch(() => null) : await res.text();
    throw err;
  }
  if (!contentType.includes('application/json')) return {};
  return res.json();
}

function formatTime(ts) {
  if (!ts) return '';
  const d = new Date(ts);
  if (Number.isNaN(d.getTime())) return ts;
  return d.toLocaleString([], { hour: '2-digit', minute: '2-digit', hour12: true, month: 'short', day: 'numeric' });
}

function showToast(message, { tone = 'info' } = {}) {
  if (!toastStack) return;
  const toast = document.createElement('div');
  toast.className = 'toast';
  if (tone === 'error') {
    toast.classList.add('toast-error');
  }
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

