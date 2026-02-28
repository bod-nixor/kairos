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
    const notifications = [];
    const seenNotificationIds = new Set();
    let seenNotificationsHydrated = false;
    const queuedNotifications = [];

    function showEl(id) { const el = $(id); if (el) el.classList.remove('hidden'); }
    function hideEl(id) { const el = $(id); if (el) el.classList.add('hidden'); }


    function pushNotification(entry) {
        if (!entry || !entry.message) return;
        if (!seenNotificationsHydrated) {
            queuedNotifications.push(entry);
            return;
        }
        const eventId = String(entry.event_id || `${entry.type || 'event'}:${entry.created_at || Date.now()}`);
        if (notifications.some(n => n.event_id === eventId) || seenNotificationIds.has(eventId)) return;
        notifications.unshift({
            event_id: eventId,
            type: entry.type || 'update',
            message: entry.message,
            created_at: entry.created_at || new Date().toISOString(),
        });
        if (notifications.length > 25) notifications.length = 25;
        renderNotifications();
    }

    function renderNotifications() {
        const list = $('kNotificationsList');
        const dot = $('kBellDot');
        if (!list) return;
        if (!notifications.length) {
            list.innerHTML = '<div class="k-empty" style="padding:12px"><p class="k-empty__title">No notifications yet</p></div>';
            if (dot) dot.classList.add('hidden');
            return;
        }
        list.innerHTML = notifications.map((n) => `
          <article class="k-notification-item">
            <div class="k-notification-item__title">${LMS.escHtml(n.message)}</div>
            <div class="k-notification-item__meta">${LMS.timeAgo(n.created_at)}</div>
          </article>
        `).join('');
        if (dot) dot.classList.remove('hidden');
    }

    function setupNotificationsPanel() {
        const bell = $('kBellBtn');
        const panel = $('kNotificationsPanel');
        if (!bell || !panel) return;
        bell.addEventListener('click', (e) => {
            e.stopPropagation();
            panel.classList.toggle('hidden');
            if (!panel.classList.contains('hidden')) {
                $('kBellDot')?.classList.add('hidden');
            }
        });
        $('kNotifClearBtn')?.addEventListener('click', async () => {
            const ids = notifications.map((n) => n.event_id).filter(Boolean);
            notifications.length = 0;
            ids.forEach((id) => seenNotificationIds.add(String(id)));
            renderNotifications();
            $('kBellDot')?.classList.add('hidden');
            panel.classList.add('hidden');
            if (ids.length) {
                await LMS.api('POST', './api/lms/notifications_seen.php', { course_id: Number(COURSE_ID), event_ids: ids });
            }
        });
        document.addEventListener('click', (e) => {
            if (!panel.classList.contains('hidden') && !panel.contains(e.target) && !bell.contains(e.target)) {
                panel.classList.add('hidden');
            }
        });
    }

    async function hydrateSeenNotificationIds() {
        try {
            const res = await LMS.api('GET', `./api/lms/notifications_seen_list.php?course_id=${encodeURIComponent(COURSE_ID)}`);
            if (!res.ok) return;
            const payload = res.data?.data || res.data || {};
            const eventIds = Array.isArray(payload.event_ids) ? payload.event_ids : [];
            seenNotificationIds.clear();
            eventIds.forEach((id) => {
                const normalized = String(id || '').trim();
                if (normalized) seenNotificationIds.add(normalized);
            });
            seenNotificationsHydrated = true;
            while (queuedNotifications.length > 0) {
                const pending = queuedNotifications.shift();
                if (pending) pushNotification(pending);
            }
        } catch (err) {
            console.error('Failed to hydrate seen notifications', err);
            seenNotificationsHydrated = true;
            while (queuedNotifications.length > 0) {
                const pending = queuedNotifications.shift();
                if (pending) pushNotification(pending);
            }
        }
    }

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
            LMS.renderAccessDenied($('courseAccessDenied'), 'No course specified.', '/signoff/');
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
            LMS.renderAccessDenied($('courseAccessDenied'), 'You are not enrolled in this course.', '/signoff/');
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
            $('postAnnBtn') && $('postAnnBtn').classList.remove('hidden');
        }

        // View all modules link
        const viewAllModules = $('viewAllModules');
        if (viewAllModules) viewAllModules.href = `./modules.html?course_id=${encodeURIComponent(COURSE_ID)}`;

        // Render content sections
        renderModuleOverview(modules);
        renderAnnouncementsFeed(announcements);
        renderRecentActivity(activity);
        notifications.length = 0;
        announcements.slice(0, 10).forEach((a) => {
            pushNotification({
                event_id: `announcement:${a.announcement_id || a.id || a.created_at}`,
                type: 'announcement',
                message: `New announcement: ${a.title || 'Course update'}`,
                created_at: a.created_at,
            });
        });
        activity.slice(0, 10).forEach((evt) => {
            pushNotification({
                event_id: `activity:${evt.id || evt.event_id || evt.created_at}:${evt.type || 'event'}`,
                type: evt.type || 'activity',
                message: evt.message || 'Course activity updated',
                created_at: evt.created_at,
            });
        });
        renderNotifications();

        document.title = `${course.name || 'Course'} — Kairos`;
        showEl('courseLoaded');
    }

    // ── Admin: Announcement creation ──────────────────────────────
    function setupAnnouncementModal() {
        const modal = $('kAnnModal');
        const form = $('kAnnForm');
        if (!modal || !form) return;

        $('postAnnBtn')?.addEventListener('click', () => {
            modal.showModal();
            setTimeout(() => { const f = form.querySelector('input'); if (f) f.focus(); }, 100);
        });
        $('kAnnModalClose')?.addEventListener('click', () => modal.close());
        $('kAnnModalCancel')?.addEventListener('click', () => modal.close());
        modal.addEventListener('click', (e) => { if (e.target === modal) modal.close(); });

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = $('kAnnModalSubmit');
            btn.disabled = true; btn.textContent = 'Posting…';
            try {
                const fd = new FormData(form);
                const res = await LMS.api('POST', './api/lms/announcements/create.php', {
                    course_id: parseInt(COURSE_ID),
                    title: fd.get('title'),
                    body: fd.get('body'),
                });
                if (res.ok) {
                    LMS.toast('Announcement posted!', 'success');
                    modal.close();
                    form.reset();
                    await loadPage(); // Refresh to show new announcement
                } else {
                    LMS.toast(res.data?.error?.message || 'Failed to post announcement.', 'error');
                }
            } catch (err) {
                LMS.toast('Network error. Please try again.', 'error');
            } finally {
                btn.disabled = false; btn.textContent = 'Post Announcement';
            }
        });
    }

    // ── Boot ───────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', async () => {
        const session = await LMS.boot();
        if (!session) return;
        LMS.nav.updateUserBar(session.me);

        setupAnnouncementModal();
        setupNotificationsPanel();

        await hydrateSeenNotificationIds();
        renderNotifications();
        await loadPage();
    });


    function pushCourseEventNotification(payload, fallbackType, fallbackMessage) {
        if (!payload || String(payload.course_id || '') !== String(COURSE_ID)) return;
        pushNotification({
            event_id: payload.event_id || `${fallbackType}:${payload.entity_id || Date.now()}`,
            type: payload.event_name || fallbackType,
            message: payload.message || fallbackMessage,
            created_at: payload.occurred_at || payload.created_at || new Date().toISOString(),
        });
    }

    // ── WS: live announcements ─────────────────────────────────
    if (window.LmsWS) {
        LmsWS.on('announcement.created', (payload) => {
            if (String(payload.course_id) !== String(COURSE_ID)) return;
            const badge = $('newAnnBadge');
            if (badge) badge.classList.remove('hidden');
            LMS.toast('New announcement posted!', 'info');
            pushNotification({
                event_id: `announcement:${payload.announcement_id || payload.event_id || Date.now()}`,
                type: 'announcement',
                message: `New announcement: ${payload.title || 'Course update'}`,
                created_at: payload.created_at || new Date().toISOString(),
            });
        });
        LmsWS.on('quiz.published', (payload) => {
            pushCourseEventNotification(payload, 'quiz.published', `Quiz published: ${payload.title || 'New quiz available'}`);
        });
        LmsWS.on('grade.released', (payload) => {
            pushCourseEventNotification(payload, 'grade.released', payload.message || 'A new grade was released.');
        });
        LmsWS.on('assignment.due_soon', (payload) => {
            pushCourseEventNotification(payload, 'assignment.due_soon', `Assignment due soon: ${payload.title || 'Check deadlines'}`);
        });
        LmsWS.on('assignment.submission.created', (payload) => {
            pushCourseEventNotification(payload, 'assignment.submission.created', payload.message || 'A new assignment submission was added.');
        });
        LmsWS.on('quiz.attempt.submitted', (payload) => {
            pushCourseEventNotification(payload, 'quiz.attempt.submitted', payload.message || 'A quiz attempt was submitted.');
        });
        LmsWS.on('lesson.published', (payload) => {
            pushCourseEventNotification(payload, 'lesson.published', payload.message || 'A lesson was published.');
        });
    }

})();
