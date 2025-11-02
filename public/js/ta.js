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
};

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
      if (window.SignoffWS) {
        window.SignoffWS.updateFilters({ roomId: val ? Number(val) : null });
      }
      if (val) {
        loadQueues(val);
      }
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
        onQueue: () => reloadQueues(),
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

async function loadQueues(roomId) {
  try {
    const data = await apiGet(`./api/ta/queues.php?room_id=${encodeURIComponent(roomId)}`);
    taState.queues = Array.isArray(data?.queues) ? data.queues : [];
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
  list.innerHTML = '';
  if (notice) notice.textContent = '';
  if (!taState.studentDirectory) taState.studentDirectory = {};

  if (!taState.selectedRoom) {
    list.innerHTML = '<div class="card">Select a room to view queues.</div>';
    return;
  }
  if (!taState.queues.length) {
    list.innerHTML = '<div class="card">No active queues in this room.</div>';
    return;
  }

  let servingMessage = '';
  taState.queues.forEach((queue) => {
    const card = document.createElement('div');
    card.className = 'ta-queue-card';
    card.dataset.queueId = queue.queue_id;
    const serving = queue.serving;
    if (serving && taState.me && serving.ta_user_id === taState.me.user_id) {
      servingMessage = `Serving ${serving.student_name || 'a student'} in ${queue.name || `Queue #${queue.queue_id}`}`;
    }
    const header = document.createElement('div');
    header.className = 'ta-queue-header';
    const titleWrap = document.createElement('div');
    const title = document.createElement('h3');
    title.className = 'ta-queue-title';
    title.textContent = queue.name || `Queue #${queue.queue_id}`;
    const desc = document.createElement('div');
    desc.className = 'ta-queue-desc';
    desc.textContent = queue.description || '';
    titleWrap.appendChild(title);
    if (queue.description) titleWrap.appendChild(desc);
    header.appendChild(titleWrap);
    if (serving && serving.student_name) {
      const servingEl = document.createElement('div');
      servingEl.className = 'ta-queue-serving';
      servingEl.textContent = `Serving ${serving.student_name} (${serving.ta_name || 'TA'})`;
      header.appendChild(servingEl);
    }
    card.appendChild(header);

    const ul = document.createElement('ul');
    ul.className = 'ta-student-list';
    if (!queue.occupants.length) {
      const empty = document.createElement('div');
      empty.className = 'ta-queue-empty';
      empty.textContent = 'No students waiting.';
      card.appendChild(empty);
    } else {
      queue.occupants.forEach((occ) => {
        if (!occ || !occ.user_id) return;
        taState.studentDirectory[occ.user_id] = occ.name;
        const li = document.createElement('li');
        li.className = 'ta-student-item';
        li.dataset.queueId = queue.queue_id;
        li.dataset.userId = occ.user_id;
        if (taState.selectedStudent === occ.user_id) li.classList.add('active');

        const info = document.createElement('div');
        info.className = 'ta-student-info';
        const name = document.createElement('div');
        name.className = 'ta-student-name';
        name.textContent = occ.name || `Student #${occ.user_id}`;
        const meta = document.createElement('div');
        meta.className = 'ta-student-meta';
        meta.textContent = occ.joined_at ? `Waiting since ${formatTime(occ.joined_at)}` : 'Waiting';
        info.appendChild(name);
        info.appendChild(meta);

        const actions = document.createElement('div');
        actions.className = 'ta-student-actions';
        const viewBtn = document.createElement('button');
        viewBtn.className = 'btn btn-ghost';
        viewBtn.type = 'button';
        viewBtn.dataset.action = 'view';
        viewBtn.dataset.queueId = queue.queue_id;
        viewBtn.dataset.userId = occ.user_id;
        viewBtn.textContent = 'View';
        const acceptBtn = document.createElement('button');
        acceptBtn.className = 'btn btn-primary';
        acceptBtn.type = 'button';
        acceptBtn.dataset.action = 'accept';
        acceptBtn.dataset.queueId = queue.queue_id;
        acceptBtn.dataset.userId = occ.user_id;
        const isBusy = serving && serving.student_user_id && serving.student_user_id !== occ.user_id;
        const isCurrent = serving && serving.student_user_id === occ.user_id;
        if (isBusy) {
          acceptBtn.disabled = true;
          acceptBtn.textContent = 'In use';
        } else if (isCurrent) {
          acceptBtn.disabled = true;
          acceptBtn.textContent = 'Serving';
        } else {
          acceptBtn.textContent = 'Accept';
        }
        actions.appendChild(viewBtn);
        actions.appendChild(acceptBtn);

        li.appendChild(info);
        li.appendChild(actions);
        ul.appendChild(li);
      });
      card.appendChild(ul);
    }

    list.appendChild(card);
  });
  if (notice && servingMessage) notice.textContent = servingMessage;
}

async function handleAccept(queueId, userId, button) {
  if (!taState.selectedCourse) {
    alert('Select a course before accepting students.');
    return;
  }
  button.disabled = true;
  button.textContent = 'Accepting…';
  try {
    await apiPost('./api/ta/accept.php', {
      queue_id: queueId,
      user_id: userId,
    });
    taState.selectedStudent = userId;
    await loadQueues(taState.selectedRoom);
    highlightSelectedStudent();
    await loadStudentProgress(userId);
  } catch (err) {
    console.error('accept failed', err);
    const body = err?.body;
    const message = typeof body === 'string' ? body : (body?.message || body?.error);
    alert(message || 'Failed to accept student.');
  } finally {
    button.disabled = false;
    button.textContent = 'Accept';
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

