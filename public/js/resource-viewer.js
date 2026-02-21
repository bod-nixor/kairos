/**
 * resource-viewer.js — Standalone resource viewer controller
 */
(function () {
    'use strict';

    const $ = id => document.getElementById(id);
    const LMS = window.KairosLMS;
    const params = new URLSearchParams(location.search);
    const COURSE_ID = params.get('course_id') || '';
    const RESOURCE_ID = params.get('resource_id') || '';

    function showEl(id) { const el = $(id); if (el) el.classList.remove('hidden'); }
    function hideEl(id) { const el = $(id); if (el) el.classList.add('hidden'); }

    const TYPE_ICONS = {
        pdf: '📄', video: '🎬', link: '🔗', text: '📝', page: '📝',
        file: '📎', image: '🖼️', audio: '🎵',
    };

    function inferType(resource) {
        if (resource.type) return resource.type.toLowerCase();
        const url = (resource.url || resource.file_url || '').toLowerCase();
        if (url.match(/\.(mp4|webm|mov|avi)$/)) return 'video';
        if (url.match(/\.pdf$/)) return 'pdf';
        if (url.match(/\.(png|jpg|jpeg|gif|webp|svg)$/)) return 'image';
        if (url.match(/\.(mp3|ogg|m4a|wav)$/)) return 'audio';
        if (url.startsWith('http')) return 'link';
        return 'file';
    }

    async function markComplete() {
        const btn = $('markCompleteBtn');
        if (btn) { btn.disabled = true; btn.textContent = '✓ Marking…'; }
        const res = await LMS.api('POST', './api/lms/complete_resource.php', {
            resource_id: RESOURCE_ID,
            course_id: COURSE_ID,
        });
        if (res.ok) {
            LMS.toast('Marked as complete!', 'success');
            if (btn) { btn.textContent = '✓ Completed'; btn.classList.add('btn-success'); }
        } else {
            LMS.toast('Could not mark complete: ' + (res.error || 'Error'), 'error');
            if (btn) { btn.disabled = false; btn.textContent = '✓ Mark as Complete'; }
        }
    }

    async function loadPage() {
        if (!RESOURCE_ID) {
            LMS.renderAccessDenied($('resourceAccessDenied'), 'No resource specified.', '/');
            hideEl('resourceSkeleton');
            showEl('resourceAccessDenied');
            return;
        }

        const res = await LMS.api('GET', `./api/lms/resource.php?id=${encodeURIComponent(RESOURCE_ID)}&course_id=${encodeURIComponent(COURSE_ID)}`);
        hideEl('resourceSkeleton');

        if (res.status === 403) {
            LMS.renderAccessDenied($('resourceAccessDenied'), 'You do not have access to this resource.', `/modules.html?course_id=${COURSE_ID}`);
            showEl('resourceAccessDenied');
            return;
        }
        if (!res.ok) {
            showEl('resourceError');
            $('resourceRetryBtn') && $('resourceRetryBtn').addEventListener('click', loadPage, { once: true });
            return;
        }

        const resource = res.data;
        const type = inferType(resource);
        const url = resource.url || resource.file_url || '';

        document.title = `${resource.title || 'Resource'} — Kairos`;
        $('resourceTypeIcon') && ($('resourceTypeIcon').textContent = TYPE_ICONS[type] || '📄');
        $('resourceTitle') && ($('resourceTitle').textContent = resource.title || 'Resource');
        $('resourceType') && ($('resourceType').textContent = (type || 'file').toUpperCase());
        $('kBreadResource') && ($('kBreadResource').textContent = resource.title || 'Resource');
        $('kBreadCourse') && ($('kBreadCourse').href = `./course.html?course_id=${encodeURIComponent(COURSE_ID)}`);
        $('kBreadModules') && ($('kBreadModules').href = `./modules.html?course_id=${encodeURIComponent(COURSE_ID)}`);
        $('kSidebarCourseName') && ($('kSidebarCourseName').textContent = resource.course_name || '');
        $('backToModules') && ($('backToModules').href = `./modules.html?course_id=${encodeURIComponent(COURSE_ID)}`);

        // Download + open buttons
        if (url && type !== 'link') {
            const dlBtn = $('downloadBtn');
            if (dlBtn) { dlBtn.href = url; dlBtn.classList.remove('hidden'); }
            const openBtn = $('openNewTabBtn');
            if (openBtn) {
                openBtn.classList.remove('hidden');
                openBtn.addEventListener('click', () => window.open(url, '_blank', 'noopener'));
            }
        }

        // Show viewer
        showEl('resourceViewer');
        ['iframeWrap', 'videoWrap', 'externalWrap', 'textWrap', 'unsupportedWrap'].forEach(hideEl);

        if (type === 'pdf' || type === 'file') {
            const iframe = $('resourceIframe');
            if (iframe && url) {
                iframe.src = url;
                showEl('iframeWrap');
            } else {
                const dlFb = $('downloadFallbackBtn');
                if (dlFb) dlFb.href = url;
                showEl('unsupportedWrap');
            }
        } else if (type === 'video') {
            const video = $('resourceVideo');
            if (video) {
                video.src = url;
                video.load();
                showEl('videoWrap');
                // Mark complete when video ends
                video.addEventListener('ended', () => {
                    if (!resource.already_completed) markComplete();
                }, { once: true });
            }
        } else if (type === 'image') {
            const wrap = document.createElement('div');
            wrap.style.cssText = 'text-align:center;border-radius:12px;overflow:hidden;border:1px solid var(--border);background:var(--surface)';
            const img = document.createElement('img');
            img.src = url; img.alt = resource.title || 'Image'; img.style.maxWidth = '100%'; img.style.display = 'block';
            wrap.appendChild(img);
            const viewer = $('resourceViewer');
            if (viewer) { viewer.innerHTML = ''; viewer.appendChild(wrap); }
        } else if (type === 'link') {
            $('externalDesc') && ($('externalDesc').textContent = `This resource links to an external source: ${url}`);
            $('externalLink') && ($('externalLink').href = url) && ($('externalLink').textContent = url);
            showEl('externalWrap');
        } else if (type === 'text' || type === 'page') {
            $('textContent') && ($('textContent').textContent = resource.content || resource.body || '');
            showEl('textWrap');
        } else {
            const dlFb = $('downloadFallbackBtn');
            if (dlFb) dlFb.href = url;
            showEl('unsupportedWrap');
        }

        // Mark complete button (shown if not already done)
        if (!resource.already_completed && type !== 'video') {
            showEl('completeFooter');
            $('markCompleteBtn') && $('markCompleteBtn').addEventListener('click', markComplete, { once: true });
        }
    }

    document.addEventListener('DOMContentLoaded', async () => {
        const session = await LMS.boot();
        if (!session) return;
        LMS.nav.updateUserBar(session.me);
        await loadPage();
    });

})();
