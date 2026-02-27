/**
 * assignments.js — Course assignments list controller
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
            container.innerHTML = '<div class="k-empty" style="padding:40px 16px"><div class="k-empty__icon">📤</div><p class="k-empty__title">No assignments yet</p><p class="k-empty__desc">There are no assignments available in this course.</p></div>';
            return;
        }

        container.innerHTML = '<div class="k-list" role="list">' + items.map(item => {
            const dueStr = item.due_date ? `Due ${LMS.fmtDateTime(item.due_date)}` : 'No due date';
            const ptsStr = item.max_points ? `${item.max_points} pts` : '';
            const safeDueStr = LMS.escHtml(dueStr);
            const safePtsStr = LMS.escHtml(ptsStr);

            return `
            <a href="./assignment.html?course_id=${encodeURIComponent(COURSE_ID)}&id=${encodeURIComponent(item.id)}" class="k-list-item" role="listitem">
                <div class="k-list-item__icon" aria-hidden="true">📤</div>
                <div class="k-list-item__content">
                    <div class="k-list-item__title">${LMS.escHtml(item.title || 'Untitled Assignment')}</div>
                    <div class="k-list-item__desc">${LMS.escHtml(item.description || '')}</div>
                    <div class="k-list-item__meta">
                        <span>${safeDueStr}</span>
                        ${safePtsStr ? `<span>• ${safePtsStr}</span>` : ''}
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
            LMS.api('GET', `./api/lms/assignments.php?course_id=${encodeURIComponent(COURSE_ID)}`)
        ]);

        hideEl('skeletonView');

        if (courseRes.status === 403 || listRes.status === 403) {
            LMS.renderAccessDenied($('accessDenied'), 'You are not enrolled in this course.', '/');
            showEl('accessDenied');
            return;
        }

        if (!listRes.ok) {
            showEl('errorView');
            $('retryBtn') && $('retryBtn').addEventListener('click', loadPage);
            return;
        }


        const course = courseRes.ok ? (courseRes.data?.data || courseRes.data) : null;
        if (course) {
            document.title = `Assignments — ${course.name || 'Course'} — Kairos`;
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

        const itemsPayload = listRes.ok ? (listRes.data?.data || listRes.data || []) : [];
        const items = Array.isArray(itemsPayload) ? itemsPayload : (itemsPayload.items || []);

        renderList(items);
        showEl('loadedView');
    }

    document.addEventListener('DOMContentLoaded', async () => {
        const session = await LMS.boot();
        if (!session) return;
        LMS.nav.updateUserBar(session.me);
        await loadPage();
    });

})();
