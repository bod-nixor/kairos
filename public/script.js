// ---------- Google Sign-In + App flow ----------
let evtSource = null;
const CLIENT_ID = '92449888009-s6re3fb58a3ik1sj90g49erpkolhcp24.apps.googleusercontent.com'; // IMPORTANT: same as in auth.php
let selectedCourse = null;
let selectedRoomId = null;
let currentUserId = null;

const queueLiveState = {
  roomId: null,
  queueIds: new Set(),
  evtSource: null,
  pollTimer: null,
};
const queuePendingFetches = new Map();

function showSignin() {
  document.getElementById('signin').classList.remove('hidden');  // show login card
  document.getElementById('userbar').classList.add('hidden');    // hide user info
  // hide app views while logged out
  document.querySelectorAll('.view').forEach(v => v.classList.add('hidden'));
  // ensure the button renders (fresh container)
  const target = document.getElementById('googleBtn');
  if (target) target.innerHTML = '';
  renderGoogleButton();
}

function showApp() {
  document.getElementById('signin').classList.add('hidden');     // hide login card
  document.getElementById('userbar').classList.remove('hidden'); // show user info
}

async function handleCredentialResponse(resp) {
  try {
    const r = await fetch('./api/auth.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
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
    if (!me?.email) { showSignin(); return; }

    // Fill userbar
    document.getElementById('avatar').src = me.picture_url || '';
    document.getElementById('name').textContent = me.name || '';
    document.getElementById('email').textContent = me.email || '';

    currentUserId = (typeof me.user_id === 'number' && Number.isFinite(me.user_id))
      ? me.user_id
      : (me?.user_id != null ? Number(me.user_id) : null);
    if (!Number.isFinite(currentUserId)) {
      currentUserId = null;
    }

    showApp();

    // Step 1: show only the user's enrolled courses as cards
    await renderCourseCards();

    // Start SSE (optional; comment out if you haven't added change_log)
    // startSSE();
  } catch (e) {
    console.warn('bootstrap -> logged-out', e);
    showSignin();
  }
}

document.addEventListener('DOMContentLoaded', () => {
  renderGoogleButton();
  bootstrap();
});

document.getElementById('logoutBtn').addEventListener('click', async () => {
  await fetch('./api/logout.php', { method: 'POST', credentials: 'same-origin' });
  stopSSE();
  showSignin();
  renderGoogleButton();
});

// ---------- API helpers ----------
async function apiGet(url) {
  const r = await fetch(url, { credentials: 'same-origin', headers: { 'Cache-Control': 'no-cache' } });
  if (!r.ok) throw new Error(`${url} -> ${r.status}`);
  return r.json();
}

// nav state
function setCrumbs(text){ document.getElementById('breadcrumbs').textContent = text; }
function showView(id){
  for (const v of document.querySelectorAll('.view')) v.classList.add('hidden');
  document.getElementById(id).classList.remove('hidden');
  document.getElementById('navCourses').classList.toggle('active', id==='viewCourses');
  document.getElementById('navRooms').classList.toggle('active', id==='viewRooms');
}

// COURSES (cards: enrolled only)
async function renderCourseCards(){
  selectedRoomId = null;                                 // reset room selection when leaving rooms view
  stopQueueLiveUpdates();
  setCrumbs('Courses');
  showView('viewCourses');
  const progressSection = document.getElementById('progressSection');
  if (progressSection) progressSection.classList.add('hidden');
  const grid = document.getElementById('coursesGrid');
  grid.innerHTML = skeletonCards(3);

  let courses = [];
  try { courses = await apiGet('./api/my_courses.php'); } catch {}
  if (!Array.isArray(courses)) courses = [];

  if (!courses.length){
    grid.innerHTML = `<div class="card"><strong>No courses yet.</strong><div class="muted small">You’re not enrolled in any courses.</div></div>`;
    return;
  }

  grid.innerHTML = '';
  courses.forEach(c=>{
    const card = document.createElement('div');
    card.className = 'course-card';
    card.innerHTML = `
      <span class="badge">Course #${c.course_id}</span>
      <h3 class="course-title">${escapeHtml(c.name)}</h3>
      <div style="margin-top:8px">
        <button class="btn btn-primary" data-course="${c.course_id}">Open</button>
      </div>
    `;
    grid.appendChild(card);
  });

  grid.onclick = async (e)=>{
    const btn = e.target.closest('button[data-course]');
    if(!btn) return;
    const id = btn.getAttribute('data-course');
    await showCourse(id);
  };
}

// ROOMS (cards) + PROGRESS (bottom)
async function showCourse(courseId){
  selectedCourse = String(courseId);                     // <- set it here
  setCrumbs(`Course #${selectedCourse}`);
  showView('viewRooms');
  document.getElementById('roomsTitle').textContent = `Rooms for Course #${selectedCourse}`;

  const grid = document.getElementById('roomsGrid');
  grid.innerHTML = skeletonCards(3);

  const rooms = await apiGet('./api/rooms.php?course_id=' + encodeURIComponent(selectedCourse));
  grid.innerHTML = '';

  if (!rooms.length) {
    grid.innerHTML = `<div class="card">No open rooms for this course.</div>`;
  }
  for (const room of rooms) {
    const card = document.createElement('div');
    card.className = 'room-card';
    card.dataset.roomId = String(room.room_id);
    card.innerHTML = `
      <div style="display:flex;align-items:center;gap:10px">
        <span class="badge">Room #${room.room_id}</span>
        <h3 class="room-title" style="margin:0">${escapeHtml(room.name)}</h3>
      </div>
      <div class="room-actions" style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn btn-primary" data-join-room="${room.room_id}">Join room</button>
        <button class="btn btn-ghost hidden" data-leave-room="${room.room_id}">Leave room</button>
      </div>
      <div class="queues hidden" id="queues-for-${room.room_id}"></div>
    `;
    grid.appendChild(card);
  }

  if (selectedRoomId && !grid.querySelector(`.room-card[data-room-id="${selectedRoomId}"]`)) {
    selectedRoomId = null;
  }

  updateRoomSelectionUI();

  if (selectedRoomId) {
    const wrap = document.getElementById(`queues-for-${selectedRoomId}`);
    if (wrap) {
      wrap.innerHTML = '<div class="sk"></div>';
      await loadQueuesForRoom(selectedRoomId);
    }
  }

  grid.onclick = async (e) => {
    const joinBtn = e.target.closest('button[data-join-room]');
    if (joinBtn) {
      const roomId = joinBtn.getAttribute('data-join-room');
      if (roomId && selectedRoomId !== roomId) {
        selectedRoomId = roomId;
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
        updateRoomSelectionUI();
      }
    }
  };

  const progressSection = document.getElementById('progressSection');
  if (progressSection) progressSection.classList.remove('hidden');
  await renderProgress(selectedCourse);

  document.getElementById('backToCourses').onclick = () => {
    const progressSection = document.getElementById('progressSection');
    if (progressSection) progressSection.classList.add('hidden');
    renderCourseCards();
  };
  document.getElementById('navRooms').classList.add('active');
  document.getElementById('navCourses').classList.remove('active');
}

// queues per room (unchanged logic, prettier buttons)
async function loadQueuesForRoom(roomId){
  const wrap = document.getElementById(`queues-for-${roomId}`);
  if (!wrap) return;
  if (String(selectedRoomId || '') !== String(roomId)) return;
  wrap.innerHTML = '<div class="sk"></div>';
  stopQueueLiveUpdates();
  try{
    const queues = await apiGet('./api/queues.php?room_id='+encodeURIComponent(roomId));
    if (String(selectedRoomId || '') !== String(roomId)) return;
    if(!queues.length){ wrap.innerHTML = `<div class="muted">No open queues for this room.</div>`; return; }
    wrap.innerHTML = '';
    const queueIds = [];
    queues.forEach(q=>{
      const row = document.createElement('div');
      row.className='queue-row';
      row.dataset.queueId = String(q.queue_id ?? '');
      row.innerHTML = `
        <div class="queue-header">
          <div class="queue-header-text">
            <div class="q-name">${escapeHtml(q.name)}</div>
            <div class="q-desc">${escapeHtml(q.description||'')}</div>
          </div>
          <div class="queue-meta">
            <div class="queue-count" data-role="queue-count">Loading…</div>
            <div class="queue-eta" data-role="queue-eta"></div>
          </div>
          <div class="queue-actions">
            <button class="btn btn-ghost" data-join="${q.queue_id}">Join</button>
            <button class="btn" data-leave="${q.queue_id}">Leave</button>
          </div>
        </div>
        <div class="queue-occupants empty" data-role="queue-occupants">
          <span class="muted small">Loading participants…</span>
        </div>
      `;
      wrap.appendChild(row);
      queueIds.push(String(q.queue_id ?? ''));
    });
    initQueueLiveUpdates(roomId, queueIds);
    wrap.onclick = async (e)=>{
      const joinId = e.target.getAttribute('data-join');
      const leaveId = e.target.getAttribute('data-leave');
      if(joinId){
        await fetch('./api/queues.php',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({action:'join',queue_id:joinId})});
        await loadQueuesForRoom(roomId);
      }
      if(leaveId){
        await fetch('./api/queues.php',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({action:'leave',queue_id:leaveId})});
        await loadQueuesForRoom(roomId);
      }
    };
  }catch{
    if (String(selectedRoomId || '') === String(roomId)) {
      wrap.innerHTML = `<div class="muted">Failed to load queues.</div>`;
    }
  }
}

function updateRoomSelectionUI(){
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
    if (key === 'pending')   return 'status-pending';
    if (key === 'completed') return 'status-completed';
    if (key === 'review')    return 'status-review';
    return 'status-none';
}


// --- Main Progress Rendering ---

// progress rendered as horizontal “tables”
async function renderProgress(courseId) {
    const container = document.getElementById('progressContainer');
    container.innerHTML = '<p>Loading progress...</p>'; // Simple loading state
    const data = await apiGet('./api/progress.php?course_id=' + encodeURIComponent(courseId || ''));
    const cats   = data.categories || [];
    const byCat  = data.detailsByCategory || {};
    const status = data.userStatuses || {}; // { detail_id: "None" | "Pending" | "Completed" | "Review" }

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

// sidebar nav (Courses/Rooms)
document.getElementById('navCourses').onclick = ()=> renderCourseCards();
document.getElementById('navRooms').onclick = ()=> showView('viewRooms');

// helpers
function escapeHtml(s){
  return String(s ?? '')
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'","&#039;");
}
function skeletonCards(n=3,h=120){
  return Array.from({length:n}).map(()=>`<div class="sk" style="height:${h}px"></div>`).join('');
}

async function refreshQueueMeta(queueId){
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

function initQueueLiveUpdates(roomId, queueIds){
  stopQueueLiveUpdates();
  const ids = (Array.isArray(queueIds) ? queueIds : [])
    .map(id => String(id))
    .filter(id => /^\d+$/.test(id));
  queueLiveState.roomId = roomId != null ? String(roomId) : null;
  queueLiveState.queueIds = new Set(ids);
  if (!ids.length) {
    return;
  }
  ids.forEach(id => { refreshQueueMeta(id); });

  if (typeof EventSource === 'undefined') {
    startQueuePolling();
    return;
  }

  startQueueEventSource(queueLiveState.roomId, ids);
}

function startQueueEventSource(roomId, queueIds){
  if (queueLiveState.evtSource) {
    queueLiveState.evtSource.close();
    queueLiveState.evtSource = null;
  }
  const params = new URLSearchParams({ channels: 'queue' });
  const idList = Array.isArray(queueIds)
    ? queueIds.filter(id => /^\d+$/.test(String(id)))
    : [];
  if (idList.length) {
    params.set('queue_id', idList.join(','));
  } else if (roomId) {
    params.set('queue_id', roomId);
  }
  if (roomId) {
    params.set('room_id', roomId);
  }
  const es = new EventSource('./api/changes.php?' + params.toString());
  queueLiveState.evtSource = es;
  es.onopen = () => {
    if (queueLiveState.pollTimer) {
      clearInterval(queueLiveState.pollTimer);
      queueLiveState.pollTimer = null;
    }
  };
  es.addEventListener('queue', (evt) => {
    try {
      const payload = evt?.data ? JSON.parse(evt.data) : {};
      const ref = payload?.ref_id ?? payload?.queue_id ?? payload?.id;
      if (ref != null) {
        const refId = String(ref);
        if (queueLiveState.queueIds.has(refId)) {
          refreshQueueMeta(refId);
        }
      }
    } catch (e) {
      console.warn('Failed to parse queue SSE payload', e);
    }
  });
  es.onerror = () => {
    if (queueLiveState.evtSource) {
      queueLiveState.evtSource.close();
      queueLiveState.evtSource = null;
    }
    startQueuePolling();
  };
}

function startQueuePolling(){
  if (queueLiveState.pollTimer) {
    clearInterval(queueLiveState.pollTimer);
  }
  queueLiveState.pollTimer = setInterval(() => {
    queueLiveState.queueIds.forEach(id => {
      refreshQueueMeta(id);
    });
  }, 10000);
}

function stopQueueLiveUpdates(){
  if (queueLiveState.evtSource) {
    queueLiveState.evtSource.close();
    queueLiveState.evtSource = null;
  }
  if (queueLiveState.pollTimer) {
    clearInterval(queueLiveState.pollTimer);
    queueLiveState.pollTimer = null;
  }
  queueLiveState.queueIds = new Set();
  queueLiveState.roomId = null;
  queuePendingFetches.clear();
}

// ---------- SSE (optional; if you created change_log + triggers) ----------
function startSSE() {
  if (!selectedCourse) return;
  stopSSE();
  evtSource = new EventSource('./api/changes.php?channels=rooms,progress&course_id=' + encodeURIComponent(selectedCourse));
  evtSource.addEventListener('rooms', async () => {
    await showCourse(selectedCourse);
  });
  evtSource.addEventListener('progress', async () => {
    await renderProgress(selectedCourse);
  });
  evtSource.onerror = () => { /* auto-retry */ };
}
function stopSSE() { if (evtSource) { evtSource.close(); evtSource = null; } }