/**
 * assignments.js — Course assignments list controller
 */
(function () {
    'use strict';

    const $ = id => document.getElementById(id);
    const LMS = window.KairosLMS;
    const Management = window.KairosLMSManagement;
    const params = new URLSearchParams(location.search);
    const COURSE_ID = params.get('course_id') || '';
    let uploadPolicy = null;

    function showEl(id) { const el = $(id); if (el) el.classList.remove('hidden'); }
    function hideEl(id) { const el = $(id); if (el) el.classList.add('hidden'); }

    function renderList(items) {
        const container = $('listContainer');
        if (!container) return;
        if (!items || !items.length) {
            container.innerHTML = '<div class="k-empty k-empty-inline--wide"><div class="k-empty__icon">📤</div><p class="k-empty__title">No assignments yet</p><p class="k-empty__desc">There are no assignments available in this course.</p></div>';
            return;
        }

        container.innerHTML = '<div class="k-lms-card-grid" role="list">' + items.map(item => {
            const dueStr = (item.due_at || item.due_date) ? `Due ${LMS.fmtDateTime(item.due_at || item.due_date)}` : 'No due date';
            const ptsStr = item.max_points ? `${item.max_points} pts` : '';
            const excerpt = LMS.richTextExcerpt(item.instructions || item.description || '', 190);
            const status = String(item.status || '').toLowerCase();

            return `
            <a href="./assignment.html?course_id=${encodeURIComponent(COURSE_ID)}&assignment_id=${encodeURIComponent(item.assignment_id || item.id)}" class="k-lms-content-card" role="listitem">
                <div class="k-lms-content-card__top">
                    <span class="k-lms-content-card__icon" aria-hidden="true">A</span>
                    ${status ? `<span class="k-status ${status === 'published' ? 'k-status--success' : 'k-status--neutral'}">${LMS.escHtml(status === 'published' ? 'Published' : 'Draft')}</span>` : ''}
                </div>
                <div class="k-lms-content-card__body">
                    <h2>${LMS.escHtml(item.title || 'Untitled Assignment')}</h2>
                    <p>${LMS.escHtml(excerpt || 'No description provided.')}</p>
                    <div class="k-lms-content-card__meta">
                        <span>${LMS.escHtml(dueStr)}</span>
                        ${ptsStr ? `<span>${LMS.escHtml(ptsStr)}</span>` : ''}
                    </div>
                </div>
            </a>`;
        }).join('') + '</div>';
    }

    async function loadUploadPolicy() {
        if (uploadPolicy) return uploadPolicy;
        const res = await LMS.api('GET', `./api/lms/assignments/upload-policy.php?course_id=${encodeURIComponent(COURSE_ID)}`);
        uploadPolicy = res.ok ? (res.data?.data || res.data || {}) : {};
        return uploadPolicy;
    }

    async function createAssignment() {
        const policy = await loadUploadPolicy();
        await Management.openAssignmentEditor({}, {
            mode: 'create',
            policy,
            onSubmit: async (payload) => {
                const res = await LMS.api('POST', './api/lms/assignments/create.php', {
                    course_id: Number(COURSE_ID),
                    ...payload,
                });
                if (!res.ok) return res;
                LMS.toast('Assignment created as a draft.', 'success');
                await loadPage();
                return res;
            },
        });
    }

    async function loadPage() {
        if (!COURSE_ID) {
            LMS.renderAccessDenied($('accessDenied'), 'No course specified.', '/signoff/');
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
            LMS.renderAccessDenied($('accessDenied'), 'You are not enrolled in this course.', '/signoff/');
            showEl('accessDenied');
            return;
        }

        if (!courseRes.ok || !listRes.ok) {
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
            LMS.nav.setCourseContext(COURSE_ID, course.name || course.code || 'Course', course);
            LMS.nav.setActive('assignments');
            $('createAssignmentBtn')?.classList.toggle('hidden', !course.capabilities?.manage_course);
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
        $('createAssignmentBtn')?.addEventListener('click', createAssignment);
        await loadPage();
    });

})();
