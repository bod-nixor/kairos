/**
 * modules.js — Modules list page controller
 */
(function () {
    'use strict';

    const $ = id => document.getElementById(id);
    const LMS = window.KairosLMS;
    const params = new URLSearchParams(location.search);
    const COURSE_ID = params.get('course_id') || '';

    function showEl(id) { const el = $(id); if (el) el.classList.remove('hidden'); }
    function hideEl(id) { const el = $(id); if (el) el.classList.add('hidden'); }

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

        return `
      <a href="${locked ? '#' : LMS.escHtml(itemHref(item))}"
         class="k-module-item${locked ? ' k-module-item--locked' : ''}${done ? ' k-module-item--completed' : ''}"
         aria-disabled="${locked}"
         ${locked ? 'tabindex="-1"' : ''}
         role="listitem">
        <div class="k-module-item__icon ${iconClass}" aria-hidden="true">${done ? '✅' : icon}</div>
        <div class="k-module-item__body">
          <div class="k-module-item__title">${LMS.escHtml(item.name)}</div>
          ${metaParts.length ? `<div class="k-module-item__meta">${LMS.escHtml(metaParts.join(' · '))}</div>` : ''}
        </div>
        <div class="k-module-item__right">
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
    }

    function attachAccordion() {
        document.querySelectorAll('.k-module__header').forEach(header => {
            const toggleFn = () => {
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
            header.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggleFn(); } });
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
            return;
        }

        renderModules(modules);
    }

    document.addEventListener('DOMContentLoaded', async () => {
        const session = await LMS.boot();
        if (!session) return;
        LMS.nav.updateUserBar(session.me);
        await loadPage();
    });

})();
