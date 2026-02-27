(function () {
    'use strict';

    const $ = id => document.getElementById(id);
    const LMS = window.KairosLMS;
    const params = new URLSearchParams(location.search);
    const COURSE_ID = params.get('course_id') || '';
    const COURSE_ID_INT = parseInt(COURSE_ID, 10);
    const DEBUG_MODULES = params.get('debug') === '1';

    let isAdmin = false;
    const expandedModules = new Set();

    const TYPE_ICONS = { lesson: '📄', assignment: '📤', quiz: '⚡', file: '📎', video: '🎬', link: '🔗', resource: '📎' };

    function showEl(id) { const el = $(id); if (el) el.classList.remove('hidden'); }
    function hideEl(id) { const el = $(id); if (el) el.classList.add('hidden'); }


    function normalizeExternalUrl(raw) {
        const value = String(raw || '').trim();
        if (!value) return '';
        if (/^https?:\/\//i.test(value)) return value;
        return `https://${value}`;
    }

    function itemHref(item) {
        const type = String(item.item_type || item.type || '').toLowerCase();
        const entityId = parseInt(item.entity_id || item.id || 0, 10);
        if (type === 'assignment') return `./assignment.html?course_id=${encodeURIComponent(COURSE_ID)}&assignment_id=${entityId}`;
        if (type === 'quiz') return `./quiz.html?course_id=${encodeURIComponent(COURSE_ID)}&quiz_id=${entityId}`;
        if (type === 'lesson') return `./lesson.html?course_id=${encodeURIComponent(COURSE_ID)}&lesson_id=${entityId}`;
        if (type === 'link') {
            const external = normalizeExternalUrl(item.url || item.resource_url || item.external_url || '');
            if (external) return external;
        }
        if (type === 'file' || type === 'video' || type === 'resource' || type === 'link') return `./resource-viewer.html?course_id=${encodeURIComponent(COURSE_ID)}&resource_id=${entityId}`;
        if (entityId > 0) return `./resource-viewer.html?course_id=${encodeURIComponent(COURSE_ID)}&resource_id=${entityId}`;
        return `./modules.html?course_id=${encodeURIComponent(COURSE_ID)}&debug=1`;
    }

    function renderModuleItem(item) {
        const iconClass = '';
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


    function logModuleDebug(modules) {
        if (!DEBUG_MODULES) return;
        const list = Array.isArray(modules) ? modules : [];
        const summary = list.reduce((acc, mod) => {
            const parsedModuleId = parseInt(mod.section_id ?? mod.id ?? 0, 10);
            const moduleId = Number.isFinite(parsedModuleId) ? parsedModuleId : 0;
            const count = Array.isArray(mod.items) ? mod.items.length : 0;
            acc.items_count += count;
            acc.module_item_counts[moduleId] = (acc.module_item_counts[moduleId] || 0) + count;
            return acc;
        }, { items_count: 0, module_item_counts: {} });

        console.debug('[modules] debug', {
            course_id: COURSE_ID_INT,
            modules_count: list.length,
            items_count: summary.items_count,
            module_item_counts: summary.module_item_counts,
        });
    }

    function renderModuleHtml(mod, isExpanded) {
        const moduleId = parseInt(mod.section_id ?? mod.id ?? 0, 10);
        const bodyId = `mod-items-${moduleId}`;
        const hdrId = `mod-hdr-${moduleId}`;
        const items = Array.isArray(mod.items) ? mod.items : [];
        const itemsHtml = items.length
            ? items.map(renderModuleItem).join('')
            : '<div class="k-empty" style="padding:20px"><p class="k-empty__desc">No items in this module.</p></div>';

        const headerHtml = `<div class="k-module__header" tabindex="0" role="button" aria-expanded="${isExpanded ? 'true' : 'false'}" aria-controls="${bodyId}" id="${hdrId}"><span class="k-module__chevron" aria-hidden="true">▶</span><h2 class="k-module__title">${LMS.escHtml(mod.name || mod.title || 'Untitled Module')}</h2><div class="k-module__meta">${isAdmin ? `<button type="button" class="k-admin-btn k-admin-btn--sm" data-action="open-add-item" data-module-id="${moduleId}">+ Add Item</button>` : ''}</div></div>`;
        const itemsWrapHtml = `<div class="k-module__items" id="${bodyId}" role="list" aria-labelledby="${hdrId}" ${isExpanded ? '' : 'style="display:none"'}>${itemsHtml}</div>`;

        return `<section class="k-module${isExpanded ? ' is-open' : ''}" data-module-id="${moduleId}">${headerHtml}${itemsWrapHtml}</section>`;
    }

    function renderModules(modules) {
        const container = $('moduleList');
        if (!container) return;

        container.innerHTML = modules.map((mod) => {
            const moduleId = parseInt(mod.section_id ?? mod.id ?? 0, 10);
            const isExpanded = expandedModules.has(moduleId);
            return renderModuleHtml(mod, isExpanded);
        }).join('');

        container.querySelectorAll('.k-module__header').forEach(header => {
            const toggle = () => {
                const moduleEl = header.closest('[data-module-id]');
                const moduleId = parseInt(moduleEl?.dataset.moduleId || '0', 10);
                const expanded = header.getAttribute('aria-expanded') === 'true';
                header.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                const panel = container.querySelector(`#${header.getAttribute('aria-controls')}`);
                if (panel) panel.style.display = expanded ? 'none' : '';
                if (moduleEl) moduleEl.classList.toggle('is-open', !expanded);
                if (expanded) expandedModules.delete(moduleId); else expandedModules.add(moduleId);
            };
            header.addEventListener('click', toggle);
            header.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); }
            });
        });

        if (isAdmin) {
            container.querySelectorAll('[data-action="open-add-item"]').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    openCreateModal('module_item', btn.dataset.moduleId || '');
                });
            });
        }

        showEl('moduleList');
    }

    const MODAL_CONFIG = {
        module: {
            title: 'Create New Module',
            fields: '<div class="k-form-field"><label for="kf-title">Module Title *</label><input id="kf-title" name="title" required></div><div class="k-form-field"><label for="kf-desc">Description</label><textarea id="kf-desc" name="description"></textarea></div>',
            api: './api/lms/sections/create.php',
            payload: (fd) => ({ course_id: COURSE_ID_INT, title: fd.get('title'), description: fd.get('description') || null }),
            successMsg: 'Module created!'
        },
        module_item: {
            title: 'Add Module Item',
            fields: `<div class="k-form-field"><label for="kf-item-type">Item Type *</label><select id="kf-item-type" name="item_type"><option value="lesson">Lesson</option><option value="file">File / PDF</option><option value="video">Video Embed</option><option value="link">External Link</option><option value="assignment">Assignment</option><option value="quiz">Quiz</option></select></div><div class="k-form-field"><label for="kf-title">Title *</label><input id="kf-title" name="title" required></div><div id="kTypeSpecificFields"></div>`,
            api: './api/lms/module_items/create.php',
            payload: (fd, sectionId) => {
                const rawSection = typeof sectionId === 'string' ? sectionId.trim() : '';
                const parsedSectionId = rawSection !== '' ? parseInt(rawSection, 10) : null;
                return {
                    course_id: COURSE_ID_INT,
                    section_id: Number.isFinite(parsedSectionId) ? parsedSectionId : null,
                    item_type: fd.get('item_type'),
                    title: fd.get('title'),
                    html_content: fd.get('html_content') || null,
                    url: fd.get('url') ? normalizeExternalUrl(fd.get('url')) : null,
                    assignment_id: fd.get('assignment_id') || null,
                    quiz_id: fd.get('quiz_id') || null,
                };
            },
            successMsg: 'Module item added!'
        }
    };

    let activeModalType = null;
    let activeModalSectionId = null;

    function renderTypeSpecificFields() {
        const sel = $('kf-item-type');
        const wrap = $('kTypeSpecificFields');
        if (!sel || !wrap) return;
        const t = sel.value;
        if (t === 'lesson') {
            wrap.innerHTML = '<div class="k-form-field"><label for="kf-html">Lesson Content *</label><textarea id="kf-html" name="html_content" rows="8" placeholder="Use lesson editor page for richer editing."></textarea></div>';
        } else if (t === 'file' || t === 'video' || t === 'link') {
            wrap.innerHTML = '<div class="k-form-field"><label for="kf-url">URL *</label><input id="kf-url" name="url" required placeholder="https://..."></div>';
        } else if (t === 'assignment') {
            wrap.innerHTML = '<div class="k-form-field"><label for="kf-assignment-id">Existing Assignment ID (optional)</label><input id="kf-assignment-id" name="assignment_id" type="number" min="1"></div>';
        } else if (t === 'quiz') {
            wrap.innerHTML = '<div class="k-form-field"><label for="kf-quiz-id">Existing Quiz ID (optional)</label><input id="kf-quiz-id" name="quiz_id" type="number" min="1"></div>';
        } else {
            wrap.innerHTML = '';
        }
    }

    function openCreateModal(type, sectionId) {
        const config = MODAL_CONFIG[type];
        if (!config) return;
        activeModalType = type;
        activeModalSectionId = sectionId || '';
        $('kCreateModalTitle').textContent = config.title;
        $('kCreateModalBody').innerHTML = config.fields;
        $('kCreateModalSubmit').textContent = type === 'module' ? 'Create Module' : 'Create Item';
        $('kCreateModal').showModal();
        if (type === 'module_item') {
            $('kf-item-type')?.addEventListener('change', renderTypeSpecificFields);
            renderTypeSpecificFields();
        }
    }

    function closeCreateModal() { $('kCreateModal').close(); activeModalType = null; activeModalSectionId = ''; }

    function setupCreateModal() {
        const modal = $('kCreateModal');
        const form = $('kCreateForm');
        if (!modal || !form) return;
        $('kCreateModalClose').addEventListener('click', closeCreateModal);
        $('kCreateModalCancel').addEventListener('click', closeCreateModal);
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            if (form.dataset.busy === '1') return;
            const config = MODAL_CONFIG[activeModalType];
            if (!config) return;
            const submitBtn = $('kCreateModalSubmit');
            form.dataset.busy = '1';
            if (submitBtn) submitBtn.disabled = true;
            try {
                const fd = new FormData(form);
                const payload = config.payload(fd, activeModalSectionId);
                const res = await LMS.api('POST', config.api, payload);
                if (res.ok) {
                    LMS.toast(config.successMsg, 'success');
                    closeCreateModal();
                    await loadPage();
                } else {
                    LMS.toast(res.data?.error?.message || 'Failed request', 'error');
                }
            } finally {
                form.dataset.busy = '0';
                if (submitBtn) submitBtn.disabled = false;
            }
        });
    }

    async function loadPage() {
        const [courseRes, modulesRes] = await Promise.all([
            LMS.api('GET', `./api/lms/courses.php?course_id=${encodeURIComponent(COURSE_ID)}`),
            LMS.api('GET', `./api/lms/modules.php?course_id=${encodeURIComponent(COURSE_ID)}`),
        ]);

        hideEl('modulesSkeleton');

        if (!courseRes.ok || !modulesRes.ok) {
            console.error('Failed to load modules page', { courseRes, modulesRes });
            hideEl('moduleList');
            showEl('modulesEmpty');
            const desc = document.querySelector('#modulesEmpty .k-empty__desc');
            if (desc) desc.textContent = 'Failed to load modules. Please try again.';
            return;
        }

        const course = courseRes.data?.data || courseRes.data || {};
        const modules = modulesRes.data?.data || modulesRes.data || [];

        if (!expandedModules.size && Array.isArray(modules) && modules.length > 0) {
            const firstId = parseInt(modules[0].section_id ?? modules[0].id ?? 0, 10);
            if (firstId > 0) expandedModules.add(firstId);
        }

        $('modulesSubtitle') && ($('modulesSubtitle').textContent = `${course.name || ''} · ${course.code || ''}`);
        document.querySelectorAll('[data-course-href]').forEach(el => el.href = `${el.dataset.courseHref}?course_id=${encodeURIComponent(COURSE_ID)}`);

        if (!modules.length) {
            showEl('modulesEmpty');
            hideEl('moduleList');
            return;
        }

        hideEl('modulesEmpty');
        logModuleDebug(modules);
        renderModules(modules);
    }

    document.addEventListener('DOMContentLoaded', async () => {
        if (!Number.isInteger(COURSE_ID_INT) || COURSE_ID_INT <= 0) {
            hideEl('modulesSkeleton');
            hideEl('moduleList');
            showEl('modulesEmpty');
            const desc = document.querySelector('#modulesEmpty .k-empty__desc');
            if (desc) desc.textContent = 'Missing or invalid course id.';
            LMS.toast('Missing or invalid course id.', 'error');
            return;
        }

        const session = await LMS.boot();
        if (!session) return;
        LMS.nav.updateUserBar(session.me);
        const roles = session.caps?.roles || {};
        isAdmin = !!(roles.admin || roles.manager);
        if (isAdmin) {
            showEl('addModuleBtn');
            $('addModuleBtn')?.addEventListener('click', () => openCreateModal('module'));
        }
        setupCreateModal();
        await loadPage();
    });
})();
