/**
 * modules.js — Modules list page controller
 * Handles module display + admin content creation (module, lesson, assignment, quiz)
 */
(function () {
    'use strict';

    const $ = id => document.getElementById(id);
    const LMS = window.KairosLMS;
    const params = new URLSearchParams(location.search);
    const COURSE_ID = params.get('course_id') || '';

    function showEl(id) { const el = $(id); if (el) el.classList.remove('hidden'); }
    function hideEl(id) { const el = $(id); if (el) el.classList.add('hidden'); }

    let isAdmin = false;

    const TYPE_ICONS = {
        lesson: '📄', quiz: '⚡', assignment: '📤', file: '📎',
        link: '🔗', page: '📝', video: '🎬',
    };
    const TYPE_CLASS = {
        quiz: 'k-module-item__icon--quiz', assignment: 'k-module-item__icon--assign',
        file: 'k-module-item__icon--file', link: 'k-module-item__icon--link',
    };
    const TYPE_HREF = {
        quiz: './quiz.html', assignment: './assignment.html',
        file: './resource-viewer.html', video: './resource-viewer.html',
        link: './resource-viewer.html', page: './resource-viewer.html', lesson: './resource-viewer.html',
    };

    function itemHref(item) {
        const base = TYPE_HREF[item.type] || './resource-viewer.html';
        const idKey = item.type === 'quiz' ? 'quiz_id' : item.type === 'assignment' ? 'assignment_id' : 'resource_id';
        return `${base}?course_id=${encodeURIComponent(COURSE_ID)}&${idKey}=${encodeURIComponent(item.id)}`;
    }

    function renderModuleItem(item) {
        const iconClass = TYPE_CLASS[item.type] || '';
        const icon = TYPE_ICONS[item.type] || '📌';
        const locked = item.locked;
        const done = item.completed;
        const metaParts = [];
        if (item.due_date) metaParts.push(`Due ${LMS.fmtDate(item.due_date)}`);
        if (item.points) metaParts.push(`${item.points} pts`);
        if (item.duration_min) metaParts.push(`${item.duration_min} min`);

        // Status badges for admin visibility
        const isDraft = item.status === 'draft' || item.published === false || item.published === 0;
        const isMandatory = item.mandatory === true || item.mandatory === 1 || item.is_mandatory === true || item.is_mandatory === 1;
        const statusBadges = [];
        if (isDraft) statusBadges.push('<span class="k-badge k-badge--draft" title="Draft">Draft</span>');
        if (isMandatory) statusBadges.push('<span class="k-badge k-badge--mandatory" title="Mandatory">Required</span>');

        // Admin per-item controls
        const adminBtns = isAdmin ? `
          <span class="k-module-item__admin-actions" onclick="event.preventDefault();event.stopPropagation();">
            <a href="${LMS.escHtml(itemHref(item))}" class="k-btn-icon" title="Edit" onclick="event.stopPropagation();">
              ✏️
            </a>
          </span>` : '';

        return `
      <a href="${locked ? '#' : LMS.escHtml(itemHref(item))}"
         class="k-module-item${locked ? ' k-module-item--locked' : ''}${done ? ' k-module-item--completed' : ''}${isDraft ? ' k-module-item--draft' : ''}"
         aria-disabled="${locked ? 'true' : 'false'}"
         ${locked ? 'tabindex="-1"' : ''}
         role="listitem">
        <div class="k-module-item__icon ${iconClass}" aria-hidden="true">${done ? '✅' : icon}</div>
        <div class="k-module-item__body">
          <div class="k-module-item__title">
            ${LMS.escHtml(item.name)}
            ${statusBadges.join(' ')}
          </div>
          ${metaParts.length ? `<div class="k-module-item__meta">${LMS.escHtml(metaParts.join(' · '))}</div>` : ''}
        </div>
        <div class="k-module-item__right">
          ${adminBtns}
          ${done ? '<span class="k-status k-status--success" aria-label="Completed">✓</span>' : ''}
          ${locked ? '<span class="k-module-item__lock" aria-label="Locked">🔒</span>' : ''}
        </div>
      </a>`;
    }

    function renderModules(modules) {
        const container = $('moduleList');
        if (!container) return;

        container.innerHTML = modules.map((mod, idx) => {
            const itemsHtml = mod.items && mod.items.length
                ? mod.items.map(renderModuleItem).join('')
                : `<div class="k-empty" style="padding:20px"><p class="k-empty__desc">No items in this module.</p></div>`;

            const done = mod.completed_items || 0;
            const total = mod.total_items || mod.items?.length || 0;
            const pct = total > 0 ? Math.round((done / total) * 100) : 0;

            const statusBadge = mod.locked
                ? `<span class="k-status k-status--neutral" aria-label="Locked">🔒 Locked</span>`
                : pct === 100
                    ? `<span class="k-status k-status--success" aria-label="Completed">✓ Completed</span>`
                    : `<span class="k-status k-status--info" aria-label="In progress">${done}/${total}</span>`;

            const safeSectionId = parseInt(mod.id) || 0;
            const addItemHtml = isAdmin ? `
              <div class="k-add-item-menu" data-section-id="${safeSectionId}">
                <button type="button" class="k-admin-btn k-admin-btn--sm k-add-item-toggle">+ Add Item</button>
                <div class="k-add-item-menu__dropdown">
                  <button type="button" class="k-add-item-menu__item" data-action="add-lesson" data-section-id="${safeSectionId}">📄 Lesson</button>
                  <button type="button" class="k-add-item-menu__item" data-action="add-assignment" data-section-id="${safeSectionId}">📤 Assignment</button>
                  <button type="button" class="k-add-item-menu__item" data-action="add-quiz" data-section-id="${safeSectionId}">⚡ Quiz</button>
                </div>
              </div>` : '';

            return `
        <div class="k-module${mod.locked ? ' k-module--locked' : ''}" role="listitem">
          <div class="k-module__header"
               tabindex="0" role="button"
               aria-expanded="${idx === 0 ? 'true' : 'false'}"
               aria-controls="mod-items-${mod.id}"
               id="mod-hdr-${mod.id}">
            <span class="k-module__chevron" aria-hidden="true">▶</span>
            <h2 class="k-module__title">${LMS.escHtml(mod.name)}</h2>
            <div class="k-module__meta">
              ${statusBadge}
              <span class="k-module__count">${total} item${total !== 1 ? 's' : ''}</span>
              ${addItemHtml}
            </div>
          </div>
          <div class="k-module__items" id="mod-items-${mod.id}"
               role="list" aria-labelledby="mod-hdr-${mod.id}"
               ${idx === 0 ? '' : 'style="display:none"'}>
            ${itemsHtml}
          </div>
        </div>`;
        }).join('');

        showEl('moduleList');
        attachAccordion();
        if (isAdmin) attachAddItemMenus();
    }

    function attachAccordion() {
        document.querySelectorAll('.k-module__header').forEach(header => {
            const toggleFn = (e) => {
                // Don't toggle if clicking an admin button
                if (e && e.target.closest('.k-add-item-menu')) return;
                const module = header.closest('.k-module');
                if (!module) return;
                if (module.classList.contains('k-module--locked')) return;
                const itemsId = header.getAttribute('aria-controls');
                const items = document.getElementById(itemsId);
                if (!items) return;
                const isOpen = header.getAttribute('aria-expanded') === 'true';
                header.setAttribute('aria-expanded', !isOpen ? 'true' : 'false');
                module.classList.toggle('is-open', !isOpen);
                items.style.display = isOpen ? 'none' : '';
            };
            header.addEventListener('click', toggleFn);
            header.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggleFn(e); } });
        });

        // Open first module by default
        const first = document.querySelector('.k-module');
        if (first) first.classList.add('is-open');
    }

    // Collapse all button
    $('collapseAllBtn') && $('collapseAllBtn').addEventListener('click', () => {
        document.querySelectorAll('.k-module').forEach(mod => {
            const header = mod.querySelector('.k-module__header');
            const itemsId = header && header.getAttribute('aria-controls');
            const items = itemsId && document.getElementById(itemsId);
            if (header) header.setAttribute('aria-expanded', 'false');
            mod.classList.remove('is-open');
            if (items) items.style.display = 'none';
        });
    });

    // ── Admin: Add-item dropdown menus ────────────────────────
    let outsideClickAttached = false;
    function handleOutsideClick() {
        document.querySelectorAll('.k-add-item-menu.is-open').forEach(m => m.classList.remove('is-open'));
    }
    function attachAddItemMenus() {
        // Toggle dropdown
        document.querySelectorAll('.k-add-item-toggle').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const menu = btn.closest('.k-add-item-menu');
                document.querySelectorAll('.k-add-item-menu.is-open').forEach(m => {
                    if (m !== menu) m.classList.remove('is-open');
                });
                menu.classList.toggle('is-open');
            });
        });

        // Menu item clicks
        document.querySelectorAll('.k-add-item-menu__item').forEach(item => {
            item.addEventListener('click', (e) => {
                e.stopPropagation();
                const action = item.dataset.action;
                const sectionId = item.dataset.sectionId;
                item.closest('.k-add-item-menu').classList.remove('is-open');
                if (action === 'add-lesson') openCreateModal('lesson', sectionId);
                else if (action === 'add-assignment') openCreateModal('assignment', sectionId);
                else if (action === 'add-quiz') openCreateModal('quiz', sectionId);
            });
        });

        // Close menus on outside click — attach only once
        if (!outsideClickAttached) {
            document.addEventListener('click', handleOutsideClick);
            outsideClickAttached = true;
        }
    }

    // ── Admin: Create modal logic ─────────────────────────────
    const MODAL_CONFIG = {
        module: {
            title: 'Create New Module',
            fields: `
                <div class="k-form-field"><label for="kf-title">Module Title *</label><input id="kf-title" name="title" required placeholder="e.g. Week 1: Introduction"></div>
                <div class="k-form-field"><label for="kf-desc">Description</label><textarea id="kf-desc" name="description" placeholder="Brief module description (optional)"></textarea></div>`,
            api: './api/lms/sections/create.php',
            payload: (fd) => ({ course_id: parseInt(COURSE_ID), title: fd.get('title'), description: fd.get('description') || null }),
            successMsg: 'Module created!',
        },
        lesson: {
            title: 'Add Lesson',
            fields: `
                <div class="k-form-field"><label for="kf-title">Lesson Title *</label><input id="kf-title" name="title" required placeholder="e.g. Introduction to PHP"></div>
                <div class="k-form-field"><label for="kf-summary">Summary</label><textarea id="kf-summary" name="summary" placeholder="Brief lesson summary (optional)"></textarea></div>`,
            api: './api/lms/lessons/create.php',
            payload: (fd, sectionId) => ({ course_id: parseInt(COURSE_ID), section_id: parseInt(sectionId), title: fd.get('title'), summary: fd.get('summary') || null }),
            successMsg: 'Lesson added!',
        },
        assignment: {
            title: 'Add Assignment',
            fields: `
                <div class="k-form-field"><label for="kf-title">Assignment Title *</label><input id="kf-title" name="title" required placeholder="e.g. Homework 1"></div>
                <div class="k-form-field"><label for="kf-instructions">Instructions</label><textarea id="kf-instructions" name="instructions" placeholder="Describe what students should submit"></textarea></div>
                <div class="k-form-field"><label for="kf-points">Max Points</label><input id="kf-points" name="max_points" type="number" min="0" step="1" value="100"></div>
                <div class="k-form-field"><label for="kf-due">Due Date</label><input id="kf-due" name="due_at" type="datetime-local"></div>
                <div class="k-form-field"><label for="kf-status">Status</label><select id="kf-status" name="status"><option value="draft">Draft</option><option value="published">Published</option></select></div>`,
            api: './api/lms/assignments/create.php',
            payload: (fd, sectionId) => {
                const parsed = parseFloat(fd.get('max_points'));
                return {
                    course_id: parseInt(COURSE_ID),
                    section_id: parseInt(sectionId),
                    title: fd.get('title'),
                    instructions: fd.get('instructions') || null,
                    max_points: Number.isFinite(parsed) ? parsed : 100,
                    due_at: fd.get('due_at') || null,
                    status: fd.get('status') || 'draft',
                };
            },
            successMsg: 'Assignment added!',
        },
        quiz: {
            title: 'Add Quiz',
            fields: `
                <div class="k-form-field"><label for="kf-title">Quiz Title *</label><input id="kf-title" name="title" required placeholder="e.g. Week 1 Practice Quiz"></div>
                <div class="k-form-field"><label for="kf-instructions">Instructions</label><textarea id="kf-instructions" name="instructions" placeholder="Quiz instructions for students"></textarea></div>
                <div class="k-form-field"><label for="kf-attempts">Max Attempts</label><input id="kf-attempts" name="max_attempts" type="number" min="1" value="1"></div>
                <div class="k-form-field"><label for="kf-time">Time Limit (minutes)</label><input id="kf-time" name="time_limit_minutes" type="number" min="0" placeholder="Leave blank for unlimited"></div>
                <div class="k-form-field"><label for="kf-due">Due Date</label><input id="kf-due" name="due_at" type="datetime-local"></div>
                <div class="k-form-field"><label for="kf-status">Status</label><select id="kf-status" name="status"><option value="draft">Draft</option><option value="published">Published</option></select></div>`,
            api: './api/lms/quiz/create.php',
            payload: (fd, sectionId) => ({
                course_id: parseInt(COURSE_ID),
                section_id: parseInt(sectionId),
                title: fd.get('title'),
                instructions: fd.get('instructions') || null,
                max_attempts: parseInt(fd.get('max_attempts')) || 1,
                time_limit_minutes: fd.get('time_limit_minutes') ? parseInt(fd.get('time_limit_minutes')) : null,
                due_at: fd.get('due_at') || null,
                status: fd.get('status') || 'draft',
            }),
            successMsg: 'Quiz added!',
        },
    };

    let activeModalType = null;
    let activeModalSectionId = null;

    function openCreateModal(type, sectionId) {
        const config = MODAL_CONFIG[type];
        if (!config) return;
        activeModalType = type;
        activeModalSectionId = sectionId || null;

        $('kCreateModalTitle').textContent = config.title;
        $('kCreateModalBody').innerHTML = config.fields;
        $('kCreateModalSubmit').textContent = type === 'module' ? 'Create Module' : 'Add ' + type.charAt(0).toUpperCase() + type.slice(1);
        $('kCreateModal').showModal();

        // Focus first input
        setTimeout(() => {
            const first = $('kCreateModalBody').querySelector('input, textarea');
            if (first) first.focus();
        }, 100);
    }

    function closeCreateModal() {
        $('kCreateModal').close();
        activeModalType = null;
        activeModalSectionId = null;
    }

    // Modal event wiring moved to DOMContentLoaded (dialog element is after scripts)
    function setupCreateModal() {
        const modal = $('kCreateModal');
        if (!modal) return;
        $('kCreateModalClose').addEventListener('click', closeCreateModal);
        $('kCreateModalCancel').addEventListener('click', closeCreateModal);
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeCreateModal();
        });

        $('kCreateForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const config = MODAL_CONFIG[activeModalType];
            if (!config) return;

            const submitBtn = $('kCreateModalSubmit');
            const originalLabel = submitBtn.textContent;
            submitBtn.disabled = true;
            submitBtn.textContent = 'Creating…';

            try {
                const fd = new FormData($('kCreateForm'));
                const payload = config.payload(fd, activeModalSectionId);
                const res = await LMS.api('POST', config.api, payload);

                if (res.ok) {
                    LMS.toast(config.successMsg, 'success');
                    closeCreateModal();
                    await loadPage(); // Refresh module list
                } else {
                    const msg = res.data?.error?.message || 'Failed to create. Please try again.';
                    LMS.toast(msg, 'error');
                }
            } catch (err) {
                LMS.toast('Network error. Please try again.', 'error');
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = originalLabel;
            }
        });
    }

    // "New Module" button wired in DOMContentLoaded below

    // ── Main load ─────────────────────────────────────────────
    async function loadPage() {
        if (!COURSE_ID) {
            LMS.renderAccessDenied($('modulesAccessDenied'), 'No course specified.', '/');
            hideEl('modulesSkeleton');
            showEl('modulesAccessDenied');
            return;
        }

        const [courseRes, modulesRes] = await Promise.all([
            LMS.api('GET', `./api/lms/courses.php?course_id=${encodeURIComponent(COURSE_ID)}`),
            LMS.api('GET', `./api/lms/modules.php?course_id=${encodeURIComponent(COURSE_ID)}`),
        ]);

        hideEl('modulesSkeleton');

        if (courseRes.status === 403 || modulesRes.status === 403) {
            LMS.renderAccessDenied($('modulesAccessDenied'), 'You are not enrolled in this course.', '/');
            showEl('modulesAccessDenied');
            return;
        }

        const course = courseRes.ok ? (courseRes.data?.data || courseRes.data) : null;
        if (course) {
            document.title = `Modules — ${course.name || 'Course'} — Kairos`;
            const bc = $('kBreadCourse');
            if (bc) { bc.textContent = course.code || course.name; bc.href = `./course.html?course_id=${encodeURIComponent(COURSE_ID)}`; }
            $('kSidebarCourseName') && ($('kSidebarCourseName').textContent = course.code || course.name);
            $('modulesSubtitle') && ($('modulesSubtitle').textContent = `${course.name} · ${course.code || ''}`);
            document.querySelectorAll('[data-course-href]').forEach(el => {
                el.href = `${el.dataset.courseHref}?course_id=${encodeURIComponent(COURSE_ID)}`;
            });
        }

        const modPayload = modulesRes.ok ? (modulesRes.data?.data || modulesRes.data || []) : [];
        const modules = Array.isArray(modPayload) ? modPayload : [];

        // Compute overall progress
        let doneAll = 0, totalAll = 0;
        modules.forEach(m => { doneAll += m.completed_items || 0; totalAll += m.total_items || 0; });
        const overallPct = totalAll > 0 ? Math.round((doneAll / totalAll) * 100) : 0;
        const pFill = $('modulesProgressFill');
        if (pFill) pFill.style.width = overallPct + '%';
        $('modulesProgressText') && ($('modulesProgressText').textContent = overallPct + '% complete');

        if (!modules.length) {
            showEl('modulesEmpty');
            hideEl('moduleList');
            return;
        }

        hideEl('modulesEmpty');
        renderModules(modules);
    }

    document.addEventListener('DOMContentLoaded', async () => {
        const session = await LMS.boot();
        if (!session) return;
        LMS.nav.updateUserBar(session.me);

        // Show admin controls using capabilities (session.me has role_id, not role_name)
        const roles = session.caps?.roles || {};
        isAdmin = !!(roles.admin || roles.manager);
        if (isAdmin) {
            showEl('addModuleBtn');
            $('addModuleBtn')?.addEventListener('click', () => openCreateModal('module'));
        }

        // Wire modal (dialog is after scripts, so must be in DOMContentLoaded)
        setupCreateModal();

        await loadPage();
    });

})();
