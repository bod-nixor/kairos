/**
 * quizzes.js — Course quizzes list controller
 */
(function () {
    'use strict';

    const $ = id => document.getElementById(id);
    const LMS = window.KairosLMS;
    const Management = window.KairosLMSManagement;
    const params = new URLSearchParams(location.search);
    const COURSE_ID = params.get('course_id') || '';

    function showEl(id) { const el = $(id); if (el) el.classList.remove('hidden'); }
    function hideEl(id) { const el = $(id); if (el) el.classList.add('hidden'); }

    function renderList(items) {
        const container = $('listContainer');
        if (!container) return;
        if (!items || !items.length) {
            container.innerHTML = '<div class="k-empty k-empty-inline--wide"><div class="k-empty__icon">⚡</div><p class="k-empty__title">No quizzes yet</p><p class="k-empty__desc">There are no quizzes available in this course.</p></div>';
            return;
        }

        container.innerHTML = '<div class="k-lms-card-grid" role="list">' + items.map(item => {
            const dueStr = (item.available_until || item.due_date) ? `Due ${LMS.fmtDateTime(item.available_until || item.due_date)}` : 'No due date';
            const meta = [
                (item.time_limit_min || item.time_limit_minutes) ? `${item.time_limit_min || item.time_limit_minutes} min` : null,
                item.max_attempts ? `${item.max_attempts} attempts max` : null
            ].filter(Boolean);
            const excerpt = LMS.richTextExcerpt(item.instructions || item.description || '', 190);
            const status = String(item.status || '').toLowerCase();

            return `
            <a href="./quiz.html?course_id=${encodeURIComponent(COURSE_ID)}&quiz_id=${encodeURIComponent(item.assessment_id || item.id)}" class="k-lms-content-card" role="listitem">
                <div class="k-lms-content-card__top">
                    <span class="k-lms-content-card__icon" aria-hidden="true">Q</span>
                    ${status ? `<span class="k-status ${status === 'published' ? 'k-status--success' : 'k-status--neutral'}">${LMS.escHtml(status === 'published' ? 'Published' : 'Draft')}</span>` : ''}
                </div>
                <div class="k-lms-content-card__body">
                    <h2>${LMS.escHtml(item.title || 'Untitled Quiz')}</h2>
                    <p>${LMS.escHtml(excerpt || 'No instructions provided.')}</p>
                    <div class="k-lms-content-card__meta">
                        <span>${LMS.escHtml(dueStr)}</span>
                        ${meta.map((itemMeta) => `<span>${LMS.escHtml(itemMeta)}</span>`).join('')}
                    </div>
                </div>
            </a>`;
        }).join('') + '</div>';
    }

    async function createQuiz() {
        await Management.openQuizEditor({}, {
            mode: 'create',
            onSubmit: async (payload) => {
                const res = await LMS.api('POST', './api/lms/quiz/create.php', {
                    course_id: Number(COURSE_ID),
                    ...payload,
                });
                if (!res.ok) return res;
                LMS.toast('Quiz created as a draft.', 'success');
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
            LMS.api('GET', `./api/lms/quizzes.php?course_id=${encodeURIComponent(COURSE_ID)}`)
        ]);

        hideEl('skeletonView');

        if (courseRes.status === 403 || listRes.status === 403) {
            LMS.renderAccessDenied($('accessDenied'), 'You are not enrolled in this course.', '/signoff/');
            showEl('accessDenied');
            return;
        }

        if (!courseRes.ok || !listRes.ok) {
            showEl('errorView');
            return;
        }

        const course = courseRes.data?.data || courseRes.data;
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
            LMS.nav.setCourseContext(COURSE_ID, course.name || course.code || 'Course', course);
            LMS.nav.setActive('quizzes');
            $('createQuizBtn')?.classList.toggle('hidden', !course.capabilities?.manage_course);
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
        $('createQuizBtn')?.addEventListener('click', createQuiz);
        await loadPage();
    });

})();
