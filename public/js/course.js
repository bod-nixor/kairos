/**
 * course.js — Course Home page controller
 * Loads course metadata, stats, module overview, and announcements.
 */
(function () {
    'use strict';

    const $ = id => document.getElementById(id);
    const LMS = window.KairosLMS;

    const params = new URLSearchParams(location.search);
    const COURSE_ID = params.get('course_id') || '';

    function showEl(id) { const el = $(id); if (el) el.classList.remove('hidden'); }
    function hideEl(id) { const el = $(id); if (el) el.classList.add('hidden'); }

    // ── Module overview rendering ─────────────────────────────
    function renderModuleOverview(modules) {
        const container = $('moduleOverview');
        if (!container) return;
        if (!modules || !modules.length) {
            container.innerHTML = `<div class="k-empty" style="padding:24px"><div class="k-empty__icon">📦</div><p class="k-empty__title">No modules yet</p></div>`;
            return;
        }
        // Show first 3 as a preview list
        const preview = modules.slice(0, 3);
        const accent = LMS.courseAccent(COURSE_ID);
        container.innerHTML = preview.map(m => {
            const done = m.completed_items || 0;
            const total = m.total_items || 0;
            const pct = total > 0 ? Math.round((done / total) * 100) : 0;
            return `
        <a href="./modules.html?course_id=${encodeURIComponent(COURSE_ID)}"
           class="k-module-item" style="text-decoration:none">
          <div class="k-module-item__icon" aria-hidden="true">📦</div>
          <div class="k-module-item__body">
            <div class="k-module-item__title">${LMS.escHtml(m.name)}</div>
            <div class="k-module-item__meta">${done} / ${total} items · ${pct}% done</div>
          </div>
          <span class="k-text-sm k-text-muted">›</span>
        </a>`;
        }).join('');
        if (modules.length > 3) {
            container.insertAdjacentHTML('beforeend',
                `<div style="padding:12px 20px;text-align:center">
          <a href="./modules.html?course_id=${encodeURIComponent(COURSE_ID)}" class="k-text-sm" style="color:var(--primary)">
            View all ${modules.length} modules →
          </a>
        </div>`
            );
        }
    }

    // ── Announcements feed rendering ──────────────────────────
    function renderAnnouncementsFeed(announcements) {
        const container = $('announcementsFeed');
        if (!container) return;
        if (!announcements || !announcements.length) {
            container.innerHTML = `<div class="k-empty" style="padding:24px"><div class="k-empty__icon">📢</div><p class="k-empty__title">No announcements</p></div>`;
            return;
        }
        const unread = announcements.filter(a => !a.read && !a.read_at).length;
        if (unread > 0) {
            const badge = $('newAnnBadge');
            if (badge) badge.classList.remove('hidden');
        }
        container.innerHTML = `<div class="k-announcements">${announcements.slice(0, 6).map(ann => {
            const initials = (ann.author_name || 'U').split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase();
            return `
        <div class="k-announcement${!ann.read_at ? ' k-announcement--unread' : ''}">
          <div class="k-announcement__avatar" style="display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:var(--primary)">
            ${initials}
          </div>
          <div class="k-announcement__body">
            <div class="k-announcement__meta">
              <span class="k-announcement__author">${LMS.escHtml(ann.author_name || 'Instructor')}</span>
              <span class="k-announcement__time">${LMS.timeAgo(ann.created_at)}</span>
            </div>
            <p class="k-announcement__title">${LMS.escHtml(ann.title)}</p>
            <p class="k-announcement__preview">${LMS.escHtml(ann.body || '')}</p>
          </div>
        </div>`;
        }).join('')}</div>`;
    }

    // ── Recent activity ────────────────────────────────────────
    function renderRecentActivity(events) {
        const container = $('recentActivity');
        if (!container) return;
        if (!events || !events.length) {
            container.innerHTML = `<div class="k-empty" style="padding:24px"><div class="k-empty__icon">🕒</div><p class="k-empty__title">No activity yet</p></div>`;
            return;
        }
        const icons = { lesson_complete: '✅', quiz_submit: '⚡', assignment_submit: '📤', grade_released: '🎓' };
        container.innerHTML = events.slice(0, 8).map(e => `
      <div style="display:flex;align-items:flex-start;gap:12px;padding:8px 20px;border-bottom:1px solid var(--border)">
        <span style="font-size:18px;line-height:1.5">${icons[e.type] || '📌'}</span>
        <div>
          <div style="font-size:14px;font-weight:500">${LMS.escHtml(e.message)}</div>
          <div style="font-size:12px;color:var(--muted)">${LMS.timeAgo(e.created_at)}</div>
        </div>
      </div>`).join('');
    }

    // ── Main load function ─────────────────────────────────────
    async function loadPage() {
        if (!COURSE_ID) {
            LMS.renderAccessDenied($('courseAccessDenied'), 'No course specified.', '/');
            hideEl('courseSkeleton');
            showEl('courseAccessDenied');
            return;
        }

        const [courseRes, statsRes, modulesRes, annRes, actRes] = await Promise.all([
            LMS.api('GET', `./api/lms/courses.php?course_id=${encodeURIComponent(COURSE_ID)}`),
            LMS.api('GET', `./api/lms/course_stats.php?course_id=${encodeURIComponent(COURSE_ID)}`),
            LMS.api('GET', `./api/lms/modules.php?course_id=${encodeURIComponent(COURSE_ID)}&preview=1`),
            LMS.api('GET', `./api/lms/announcements.php?course_id=${encodeURIComponent(COURSE_ID)}&limit=6`),
            LMS.api('GET', `./api/lms/activity.php?course_id=${encodeURIComponent(COURSE_ID)}&limit=8`),
        ]);

        hideEl('courseSkeleton');

        if (courseRes.status === 403) {
            LMS.renderAccessDenied($('courseAccessDenied'), 'You are not enrolled in this course.', '/');
            showEl('courseAccessDenied');
            return;
        }
        if (!courseRes.ok) {
            showEl('courseError');
            $('courseRetryBtn') && $('courseRetryBtn').addEventListener('click', loadPage, { once: true });
            return;
        }

        const course = courseRes.data?.data || courseRes.data || {};
        const stats = statsRes.ok ? (statsRes.data?.data || statsRes.data || {}) : {};
        const modules = modulesRes.ok ? (modulesRes.data?.data || modulesRes.data || []) : [];
        const annPayload = annRes.ok ? (annRes.data?.data || annRes.data || {}) : {};
        const announcements = Array.isArray(annPayload) ? annPayload : (annPayload.items || []);
        const actPayload = actRes.ok ? (actRes.data?.data || actRes.data || []) : [];
        const activity = Array.isArray(actPayload) ? actPayload : [];

        // Apply course accent
        const accent = LMS.courseAccent(course.id || COURSE_ID);
        const banner = $('courseBanner');
        if (banner) banner.setAttribute('data-course-accent', String(accent));

        // Update breadcrumb
        const breadCourse = $('kBreadCourse');
        if (breadCourse) breadCourse.textContent = course.code || course.name || 'Course';

        // Sidebar course name
        const sidebarName = document.getElementById('kSidebarCourseName');
        if (sidebarName) sidebarName.textContent = course.code || course.name || '';

        // Banner content
        $('bannerLabel') && ($('bannerLabel').textContent = course.code || '');
        $('bannerTitle') && ($('bannerTitle').textContent = course.name || '');
        $('bannerRole') && ($('bannerRole').textContent = course.my_role || 'Student');

        const pct = stats.completion_pct || 0;
        const progressFill = $('bannerProgressFill');
        if (progressFill) progressFill.style.width = pct + '%';
        $('bannerProgressText') && ($('bannerProgressText').textContent = pct + '% complete');

        // Stats
        $('statModules') && ($('statModules').textContent = stats.modules ?? '—');
        $('statCompleted') && ($('statCompleted').textContent = stats.completed_items ?? '—');
        $('statAssignments') && ($('statAssignments').textContent = stats.assignments ?? '—');
        $('statQuizzes') && ($('statQuizzes').textContent = stats.quizzes ?? '—');

        // Sidebar nav links
        document.querySelectorAll('[data-course-href]').forEach(el => {
            const base = el.dataset.courseHref;
            el.href = `${base}?course_id=${encodeURIComponent(COURSE_ID)}`;
        });

        // Show role-specific nav items (TA/Manager/Admin see grading + analytics)
        const role = String(course.my_role || '').toLowerCase();
        if (role === 'ta' || role === 'manager' || role === 'admin') {
            $('kNavGrading') && $('kNavGrading').classList.remove('hidden');
        }
        if (role === 'manager' || role === 'admin') {
            $('kNavAnalytics') && $('kNavAnalytics').classList.remove('hidden');
        }

        // View all modules link
        const viewAllModules = $('viewAllModules');
        if (viewAllModules) viewAllModules.href = `./modules.html?course_id=${encodeURIComponent(COURSE_ID)}`;

        // Render content sections
        renderModuleOverview(modules);
        renderAnnouncementsFeed(announcements);
        renderRecentActivity(activity);

        document.title = `${course.name || 'Course'} — Kairos`;
        showEl('courseLoaded');
    }

    // ── Boot ───────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', async () => {
        const session = await LMS.boot();
        if (!session) return;
        LMS.nav.updateUserBar(session.me);
        await loadPage();
    });

    // ── WS: live announcements ─────────────────────────────────
    if (window.LmsWS) {
        LmsWS.on('announcement.created', (payload) => {
            if (String(payload.course_id) !== String(COURSE_ID)) return;
            const badge = $('newAnnBadge');
            if (badge) badge.classList.remove('hidden');
            LMS.toast('New announcement posted!', 'info');
        });
    }

})();
