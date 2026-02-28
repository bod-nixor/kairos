/**
 * announcements.js — Shared announcements utilities
 * Used by course.js and any page embedding announcements.
 */
(function (global) {
    'use strict';

    const LMS = global.KairosLMS;

    /**
     * Fetch and render announcements into a container element.
     * @param {string}       courseId
     * @param {HTMLElement}  container
     * @param {object}       [opts]
     * @param {number}       [opts.limit=10]
     * @param {boolean}      [opts.autoMarkRead=false]
     */
    async function loadAnnouncements(courseId, container, opts) {
        opts = opts || {};
        const limit = opts.limit || 10;
        if (!container) return;

        container.innerHTML = `<div style="padding:32px;text-align:center;color:var(--muted)">Loading…</div>`;

        const res = await LMS.api('GET', `./api/lms/announcements.php?course_id=${encodeURIComponent(courseId)}&limit=${limit}`);
        if (!res.ok) {
            container.innerHTML = '<div class="k-empty"><div class="k-empty__icon">⚠️</div><p class="k-empty__title">Could not load announcements</p></div>';
            return;
        }
        const rawPayload = res.data?.data || res.data || [];
        const announcements = Array.isArray(rawPayload) ? rawPayload : (rawPayload.items || []);
        if (!announcements.length) {
            container.innerHTML = '<div class="k-empty" style="padding:32px 16px"><div class="k-empty__icon">📢</div><p class="k-empty__title">No announcements yet</p><p class="k-empty__desc">Check back later for course updates.</p></div>';
            return;
        }

        container.innerHTML = `<div class="k-announcements">` +
            announcements.map(ann => renderAnnouncement(ann)).join('') +
            `</div>`;

        if (opts.autoMarkRead) {
            const unread = announcements.filter(a => !a.read_at).map(a => a.id);
            if (unread.length) markRead(courseId, unread);
        }
    }

    function renderAnnouncement(ann) {
        const initials = (ann.author_name || 'U').split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase();
        const unread = !ann.read_at;
        return `
      <div class="k-announcement${unread ? ' k-announcement--unread' : ''}" data-ann-id="${LMS.escHtml(String(ann.id))}">
        <div class="k-announcement__avatar" style="display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:var(--primary)">
          ${LMS.escHtml(initials)}
        </div>
        <div class="k-announcement__body">
          <div class="k-announcement__meta">
            <span class="k-announcement__author">${LMS.escHtml(ann.author_name || 'Instructor')}</span>
            <span class="k-announcement__time">${LMS.timeAgo(ann.created_at)}</span>
            ${unread ? '<span class="k-status k-status--info" style="font-size:10px;padding:2px 6px">New</span>' : ''}
          </div>
          <p class="k-announcement__title">${LMS.escHtml(ann.title)}</p>
          <p class="k-announcement__preview">${LMS.escHtml(ann.body || '')}</p>
        </div>
      </div>`;
    }

    async function markRead(courseId, ids) {
        await LMS.api('POST', './api/lms/announcements_read.php', { course_id: courseId, ids });
    }

    // Listen for WS new announcement events
    if (global.LmsWS) {
        global.LmsWS.on('announcement.created', function (payload) {
            // Pages that have a bell dot should light it up
            const dot = document.getElementById('kBellDot');
            if (dot) dot.classList.remove('hidden');
        });
    }

    global.KairosAnnouncements = { loadAnnouncements, renderAnnouncement, markRead };

})(window);
