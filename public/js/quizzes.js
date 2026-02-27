/**
 * quizzes.js — Course quizzes list controller
 */
(function () {
    'use strict';

    const $ = id => document.getElementById(id);
    const LMS = window.KairosLMS;
    const params = new URLSearchParams(location.search);
    const COURSE_ID = params.get('course_id') || '';

    function showEl(id) { const el = $(id); if (el) el.classList.remove('hidden'); }
    function hideEl(id) { const el = $(id); if (el) el.classList.add('hidden'); }

    function renderList(items) {
        const container = $('listContainer');
        if (!container) return;
        if (!items || !items.length) {
            container.innerHTML = '<div class="k-empty" style="padding:40px 16px"><div class="k-empty__icon">⚡</div><p class="k-empty__title">No quizzes yet</p><p class="k-empty__desc">There are no quizzes available in this course.</p></div>';
            return;
        }

        container.innerHTML = '<div class="k-list" role="list">' + items.map(item => {
            const dueStr = item.due_date ? `Due ${LMS.fmtDateTime(item.due_date)}` : 'No due date';
            const metaStr = [
                item.time_limit_min ? `${item.time_limit_min} min` : null,
                item.max_attempts ? `${item.max_attempts} attempts max` : null
            ].filter(Boolean).join(' • ');

            const safeDueStr = LMS.escHtml(dueStr);
            const safeMetaStr = LMS.escHtml(metaStr);

            return `
            <a href="./quiz.html?course_id=${encodeURIComponent(COURSE_ID)}&id=${encodeURIComponent(item.id)}" class="k-list-item" role="listitem">
                <div class="k-list-item__icon" aria-hidden="true">⚡</div>
                <div class="k-list-item__content">
                    <div class="k-list-item__title">${LMS.escHtml(item.title || 'Untitled Quiz')}</div>
                    <div class="k-list-item__desc">${LMS.escHtml(item.description || '')}</div>
                    <div class="k-list-item__meta">
                        <span>${safeDueStr}</span>
                        ${safeMetaStr ? `<span>• ${safeMetaStr}</span>` : ''}
                    </div>
                </div>
            </a>`;
        }).join('') + '</div>';
    }

    async function loadPage() {
        if (!COURSE_ID) {
            LMS.renderAccessDenied($('accessDenied'), 'No course specified.', '/');
            hideEl('skeletonView');
            showEl('accessDenied');
            return;
        }

        const [courseRes, listRes] = await Promise.all([
            LMS.api('GET', `./api/lms/courses.php?course_id=${encodeURIComponent(COURSE_ID)}`),
            LMS.api('GET', `./api/lms/quizzes.php?course_id=${encodeURIComponent(COURSE_ID)}`)
        ]);

        hideEl('skeletonView');

        if (courseRes.status === 403 || listRes.status === 403) {
            LMS.renderAccessDenied($('accessDenied'), 'You are not enrolled in this course.', '/');
            showEl('accessDenied');
            return;
        }

        const course = courseRes.ok ? (courseRes.data?.data || courseRes.data) : null;
        if (course) {
            document.title = `Quizzes — ${course.name || 'Course'} — Kairos`;
            $('pageSubtitle') && ($('pageSubtitle').textContent = `${course.name} · ${course.code || ''}`);
            $('kSidebarCourseName') && ($('kSidebarCourseName').textContent = course.code || course.name);
            const bc = $('kBreadCourse');
            if (bc) {
                bc.href = `./course.html?course_id=${encodeURIComponent(COURSE_ID)}`;
                bc.textContent = course.name || 'Course';
            }
            document.querySelectorAll('[data-course-href]').forEach(el => {
                el.href = `${el.dataset.courseHref}?course_id=${encodeURIComponent(COURSE_ID)}`;
            });
        }

        if (!listRes.ok) {
            showEl('errorView');
            return;
        }

        const itemsPayload = listRes.data?.data || listRes.data || [];
        const items = Array.isArray(itemsPayload) ? itemsPayload : (itemsPayload.items || []);

        renderList(items);
        hideEl('errorView');
        showEl('loadedView');
    }

    document.addEventListener('DOMContentLoaded', async () => {
        $('retryBtn') && $('retryBtn').addEventListener('click', loadPage);
        const session = await LMS.boot();
        if (!session) return;
        LMS.nav.updateUserBar(session.me);
        await loadPage();
    });

})();
