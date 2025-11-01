const APP_CONFIG = window.SIGNOFF_CONFIG || {};
const ALLOWED_DOMAIN = typeof APP_CONFIG.allowedDomain === 'string' ? APP_CONFIG.allowedDomain : '';

const state = {
  courses: [],
  selectedId: null,
};

const els = {
  statusCard: document.getElementById('statusCard'),
  statusMessage: document.getElementById('message'),
  userInfo: document.getElementById('userInfo'),
  logoutBtn: document.getElementById('logoutBtn'),
  coursesTableBody: document.querySelector('#coursesTable tbody'),
  courseCount: document.getElementById('courseCount'),
  createCourseForm: document.getElementById('createCourseForm'),
  editCourseForm: document.getElementById('editCourseForm'),
  saveCourseBtn: document.getElementById('saveCourseBtn'),
  deleteCourseBtn: document.getElementById('deleteCourseBtn'),
  assignForm: document.getElementById('assignForm'),
  assignments: document.getElementById('assignments'),
  assignmentTitle: document.getElementById('assignmentTitle'),
};

document.addEventListener('DOMContentLoaded', () => {
  updateAllowedDomainCopy();
  bootstrap();
  bindEvents();
});

function updateAllowedDomainCopy() {
  const domain = (typeof ALLOWED_DOMAIN === 'string' && ALLOWED_DOMAIN)
    ? ALLOWED_DOMAIN.replace(/^@+/, '')
    : '';
  const replacement = domain ? `@${domain}` : '@example.edu';
  document.querySelectorAll('[data-allowed-domain-text]').forEach((el) => {
    el.textContent = replacement;
  });
  const placeholderInput = document.querySelector('[data-allowed-domain-placeholder]');
  if (placeholderInput) {
    const template = placeholderInput.getAttribute('data-allowed-domain-placeholder') || '';
    if (template) {
      placeholderInput.setAttribute('placeholder', template.replace('{domain}', domain || 'example.edu'));
    }
  }
}

function bindEvents() {
  els.logoutBtn?.addEventListener('click', async () => {
    try {
      await fetch('./api/logout.php', { method: 'POST', credentials: 'same-origin' });
    } catch (err) {
      console.warn('logout failed', err);
    }
    window.location.href = './index.html';
  });

  els.coursesTableBody?.addEventListener('click', (event) => {
    const btn = event.target.closest('button[data-action]');
    if (!btn) return;
    const id = Number(btn.dataset.id || '0');
    if (!id) return;
    if (btn.dataset.action === 'select') {
      selectCourse(id);
    }
  });

  els.createCourseForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const formData = new FormData(els.createCourseForm);
    const name = (formData.get('name') || '').trim();
    const description = (formData.get('description') || '').trim();
    if (!name) {
      showStatus('Course name is required.', 'error');
      return;
    }
    try {
      await fetchJSON('./api/admin/courses.php', {
        method: 'POST',
        body: { action: 'create', name, description },
      });
      els.createCourseForm.reset();
      showStatus(`Created course “${name}”.`, 'success');
      await loadCourses({ preserveSelection: false });
    } catch (err) {
      reportError(err, 'Failed to create course');
    }
  });

  els.editCourseForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const formData = new FormData(els.editCourseForm);
    const courseId = Number(formData.get('course_id') || '0');
    const name = (formData.get('name') || '').trim();
    const description = (formData.get('description') || '').trim();
    if (!courseId) return;
    if (!name) {
      showStatus('Course name cannot be empty.', 'error');
      return;
    }
    try {
      await fetchJSON('./api/admin/courses.php', {
        method: 'POST',
        body: { action: 'rename', course_id: courseId, name, description },
      });
      showStatus('Course updated successfully.', 'success');
      await loadCourses({ preserveSelection: courseId });
    } catch (err) {
      reportError(err, 'Failed to update course');
    }
  });

  els.deleteCourseBtn?.addEventListener('click', async () => {
    if (!state.selectedId) return;
    const course = getSelectedCourse();
    if (!course) return;
    const confirmed = window.confirm(`Delete course “${course.name}”? This cannot be undone.`);
    if (!confirmed) return;
    try {
      await fetchJSON('./api/admin/courses.php', {
        method: 'POST',
        body: { action: 'delete', course_id: course.course_id },
      });
      showStatus('Course deleted.', 'success');
      state.selectedId = null;
      await loadCourses({ preserveSelection: false });
      clearAssignments();
    } catch (err) {
      reportError(err, 'Failed to delete course');
    }
  });

  els.assignForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!state.selectedId) return;
    const formData = new FormData(els.assignForm);
    const userValue = (formData.get('user') || '').trim();
    const role = (formData.get('role') || '').trim().toLowerCase();
    if (!userValue) {
      showStatus('Enter a user id or email to assign.', 'error');
      return;
    }
    if (!['manager', 'ta'].includes(role)) {
      showStatus('Role must be manager or ta.', 'error');
      return;
    }
    const payload = {
      action: 'assign',
      course_id: state.selectedId,
      role,
    };
    if (/^\d+$/.test(userValue)) {
      payload.user_id = Number(userValue);
    } else {
      payload.email = userValue.toLowerCase();
    }
    try {
      await fetchJSON('./api/admin/assign.php', {
        method: 'POST',
        body: payload,
      });
      showStatus('Assignment saved.', 'success');
      els.assignForm.reset();
      const courseId = state.selectedId;
      await loadAssignments(courseId);
    } catch (err) {
      reportError(err, 'Failed to assign role');
    }
  });

  els.assignments?.addEventListener('click', async (event) => {
    const btn = event.target.closest('button[data-remove]');
    if (!btn) return;
    const uid = Number(btn.dataset.user || '0');
    const role = (btn.dataset.role || '').trim();
    const courseId = state.selectedId;
    if (!courseId || !uid || !role) return;
    try {
      await fetchJSON('./api/admin/assign.php', {
        method: 'POST',
        body: { action: 'remove', course_id: courseId, user_id: uid, role },
      });
      showStatus('Assignment removed.', 'success');
      await loadAssignments(courseId);
    } catch (err) {
      reportError(err, 'Failed to remove assignment');
    }
  });
}

async function bootstrap() {
  try {
    const me = await fetchJSON('./api/me.php');
    if (!me || !me.email) {
      showStatus('Please sign in via the main portal before accessing the admin dashboard.', 'error');
      disableInterface();
      return;
    }
    els.userInfo.textContent = `${me.name || ''} ${me.email ? `(${me.email})` : ''}`.trim();
  } catch (err) {
    reportError(err, 'Unable to verify session.');
    disableInterface();
    return;
  }

  try {
    await loadCourses({ preserveSelection: false });
  } catch (err) {
    reportError(err, 'Failed to load courses');
    disableInterface();
  }
}

function disableInterface() {
  toggleEditForm(false);
  toggleAssignForm(false);
  els.createCourseForm?.querySelectorAll('input, textarea, button').forEach(el => el.disabled = true);
}

async function loadCourses({ preserveSelection = null } = {}) {
  const keepId = typeof preserveSelection === 'number' ? preserveSelection : (preserveSelection ? state.selectedId : null);
  const data = await fetchJSON('./api/admin/courses.php');
  const courses = Array.isArray(data?.courses) ? data.courses : Array.isArray(data) ? data : [];
  state.courses = courses.map((c) => ({
    course_id: Number(c.course_id),
    name: c.name || '',
    description: c.description || '',
  }));
  renderCourses();
  const toSelect = keepId && state.courses.some(c => c.course_id === keepId) ? keepId : null;
  if (toSelect) {
    selectCourse(toSelect, { skipReload: true });
    await loadAssignments(toSelect);
  } else {
    selectCourse(null, { skipAssignments: true });
    clearAssignments();
  }
}

function renderCourses() {
  if (!els.coursesTableBody) return;
  if (!state.courses.length) {
    els.coursesTableBody.innerHTML = '<tr><td colspan="4" class="muted">No courses found.</td></tr>';
    els.courseCount.textContent = '0 courses';
    return;
  }
  const rows = state.courses.map((course) => {
    const selected = state.selectedId === course.course_id ? 'selected' : '';
    const desc = course.description ? escapeHtml(course.description) : '<span class="muted">—</span>';
    return `
      <tr data-id="${course.course_id}" class="${selected}">
        <td>${course.course_id}</td>
        <td>${escapeHtml(course.name)}</td>
        <td>${desc}</td>
        <td class="actions">
          <button type="button" data-action="select" data-id="${course.course_id}">Select</button>
        </td>
      </tr>
    `;
  }).join('');
  els.coursesTableBody.innerHTML = rows;
  const count = state.courses.length;
  els.courseCount.textContent = count === 1 ? '1 course' : `${count} courses`;
}

function selectCourse(courseId, options = {}) {
  if (!courseId) {
    state.selectedId = null;
    updateEditForm(null);
    renderCourses();
    els.assignmentTitle.textContent = 'Select a course to view staff';
    toggleAssignForm(false);
    if (!options.skipAssignments) {
      clearAssignments();
    }
    return;
  }
  const course = state.courses.find(c => c.course_id === courseId);
  if (!course) {
    state.selectedId = null;
    updateEditForm(null);
    renderCourses();
    toggleAssignForm(false);
    return;
  }
  state.selectedId = courseId;
  renderCourses();
  updateEditForm(course);
  els.assignmentTitle.textContent = `Course #${course.course_id} – ${course.name}`;
  toggleAssignForm(true);
  if (!options.skipReload) {
    loadAssignments(course.course_id).catch((err) => {
      reportError(err, 'Failed to load assignments');
    });
  }
}

function updateEditForm(course) {
  const idInput = els.editCourseForm?.querySelector('input[name="course_id"]');
  const nameInput = els.editCourseForm?.querySelector('input[name="name"]');
  const descInput = els.editCourseForm?.querySelector('textarea[name="description"]');
  if (!idInput || !nameInput || !descInput) return;

  if (!course) {
    idInput.value = '';
    nameInput.value = '';
    descInput.value = '';
    toggleEditForm(false);
    return;
  }

  idInput.value = course.course_id;
  nameInput.value = course.name || '';
  descInput.value = course.description || '';
  toggleEditForm(true);
}

function toggleEditForm(enabled) {
  const inputs = els.editCourseForm?.querySelectorAll('input, textarea, button');
  inputs?.forEach((el) => {
    if (el.name === 'course_id') return;
    el.disabled = !enabled;
  });
  if (!enabled) {
    els.saveCourseBtn && (els.saveCourseBtn.disabled = true);
    els.deleteCourseBtn && (els.deleteCourseBtn.disabled = true);
  } else {
    els.saveCourseBtn && (els.saveCourseBtn.disabled = false);
    els.deleteCourseBtn && (els.deleteCourseBtn.disabled = false);
  }
}

function toggleAssignForm(enabled) {
  const inputs = els.assignForm?.querySelectorAll('input, select, button');
  inputs?.forEach((el) => {
    el.disabled = !enabled;
  });
  if (!enabled) {
    els.assignForm?.reset();
  }
}

async function loadAssignments(courseId) {
  if (!courseId) {
    clearAssignments();
    return;
  }
  const data = await fetchJSON(`./api/admin/assign.php?course_id=${encodeURIComponent(courseId)}`);
  const assignments = Array.isArray(data?.assignments) ? data.assignments : Array.isArray(data) ? data : [];
  renderAssignments(assignments);
}

function clearAssignments() {
  if (!els.assignments) return;
  els.assignments.innerHTML = '<div class="muted">No course selected.</div>';
}

function renderAssignments(assignments) {
  if (!els.assignments) return;
  if (!assignments.length) {
    els.assignments.innerHTML = '<div class="muted">No staff assigned yet.</div>';
    return;
  }
  const rows = assignments.map((a) => {
    const role = escapeHtml((a.role || '').toUpperCase());
    const name = escapeHtml(a.name || 'Unknown user');
    const email = escapeHtml(a.email || '');
    return `
      <div class="assignment-row">
        <div>
          <div><strong>${name}</strong> ${email ? `<span class="muted">(${email})</span>` : ''}</div>
          <div class="pill">${role}</div>
        </div>
        <button type="button" data-remove="1" data-user="${Number(a.user_id)}" data-role="${escapeAttr(a.role || '')}">Remove</button>
      </div>
    `;
  }).join('');
  els.assignments.innerHTML = rows;
}

function getSelectedCourse() {
  return state.courses.find(c => c.course_id === state.selectedId) || null;
}

function showStatus(message, type = 'info') {
  if (!els.statusCard || !els.statusMessage) return;
  els.statusCard.classList.remove('hidden');
  els.statusMessage.textContent = message;
  els.statusMessage.classList.remove('danger');
  if (type === 'error') {
    els.statusMessage.classList.add('danger');
  } else if (type === 'success') {
    els.statusMessage.classList.remove('danger');
  }
}

function reportError(err, fallback) {
  console.error(fallback, err);
  const message = extractErrorMessage(err) || fallback;
  showStatus(message, 'error');
}

function extractErrorMessage(err) {
  if (!err) return '';
  if (err.data?.error) return err.data.error;
  if (typeof err.message === 'string') return err.message;
  return '';
}

async function fetchJSON(url, options = {}) {
  const opts = { credentials: 'same-origin', ...options };
  if (opts.body && typeof opts.body !== 'string') {
    opts.body = JSON.stringify(opts.body);
    opts.headers = { 'Content-Type': 'application/json', ...(opts.headers || {}) };
  }
  const res = await fetch(url, opts);
  const text = await res.text();
  const data = text ? safeJsonParse(text) : null;
  if (!res.ok) {
    const err = new Error(data?.error || res.statusText || 'Request failed');
    err.status = res.status;
    err.data = data;
    throw err;
  }
  return data;
}

function safeJsonParse(text) {
  try {
    return JSON.parse(text);
  } catch (err) {
    console.warn('Failed to parse JSON', text);
    return null;
  }
}

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function escapeAttr(value) {
  return escapeHtml(value).replace(/`/g, '&#96;');
}
