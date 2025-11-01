// ---------- Google Sign-In + App flow ----------
let evtSource = null;
const CLIENT_ID = '92449888009-s6re3fb58a3ik1sj90g49erpkolhcp24.apps.googleusercontent.com'; // IMPORTANT: same as in auth.php
let selectedCourse = null;

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
    card.innerHTML = `
      <div style="display:flex;align-items:center;gap:10px">
        <span class="badge">Room #${room.room_id}</span>
        <h3 class="room-title" style="margin:0">${escapeHtml(room.name)}</h3>
      </div>
      <div class="queues" id="queues-for-${room.room_id}">
        <div class="sk"></div>
      </div>
    `;
    grid.appendChild(card);
    loadQueuesForRoom(room.room_id);
  }

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
  try{
    const queues = await apiGet('./api/queues.php?room_id='+encodeURIComponent(roomId));
    if(!queues.length){ wrap.innerHTML = `<div class="muted">No open queues for this room.</div>`; return; }
    wrap.innerHTML = '';
    queues.forEach(q=>{
      const row = document.createElement('div');
      row.className='queue-row';
      row.innerHTML = `
        <div class="q-name">${escapeHtml(q.name)}</div>
        <div class="q-desc">${escapeHtml(q.description||'')}</div>
        <div class="spacer"></div>
        <button class="btn btn-ghost" data-join="${q.queue_id}">Join</button>
        <button class="btn" data-leave="${q.queue_id}">Leave</button>
      `;
      wrap.appendChild(row);
    });
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
    wrap.innerHTML = `<div class="muted">Failed to load queues.</div>`;
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