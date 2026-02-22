(function () {
    'use strict';

    const $ = (id) => document.getElementById(id);
    const LMS = window.KairosLMS;
    const params = new URLSearchParams(location.search);
    const COURSE_ID = params.get('course_id') || '';
    const RESOURCE_ID = params.get('resource_id') || params.get('id') || '';

    const TYPE_ICONS = {
        pdf: '📄', video: '🎬', link: '🔗', text: '📝', page: '📝',
        file: '📎', image: '🖼️', audio: '🎵', embed: '🎬',
    };

    function showEl(id) { const el = $(id); if (el) el.classList.remove('hidden'); }
    function hideEl(id) { const el = $(id); if (el) el.classList.add('hidden'); }

    function isHttpUrl(value) {
        try {
            const parsed = new URL(String(value || ''));
            return parsed.protocol === 'http:' || parsed.protocol === 'https:';
        } catch (_) {
            return false;
        }
    }

    function applySafeExternalLink(linkEl, rawUrl, label) {
        if (!linkEl) return;
        const value = String(rawUrl || '').trim();
        linkEl.textContent = label;
        if (isHttpUrl(value)) {
            linkEl.href = value;
            linkEl.setAttribute('target', '_blank');
            linkEl.setAttribute('rel', 'noopener noreferrer');
            return;
        }
        linkEl.removeAttribute('href');
        linkEl.removeAttribute('target');
        linkEl.removeAttribute('rel');
    }

    function toDrivePreviewUrl(inputUrl) {
        if (!inputUrl) return '';
        try {
            const parsed = new URL(inputUrl);
            const host = parsed.hostname.replace(/^www\./i, '').toLowerCase();
            if (host !== 'drive.google.com') return inputUrl;
            const pathId = parsed.pathname.match(/\/file\/d\/([^/]+)/i)?.[1] || '';
            const queryId = parsed.searchParams.get('id') || '';
            const fileId = pathId || queryId;
            if (!fileId) return inputUrl;
            return `https://drive.google.com/file/d/${fileId}/preview`;
        } catch (_) {
            return inputUrl;
        }
    }

    function inferType(resource) {
        if (resource.type) return String(resource.type).toLowerCase();
        const url = (resource.url || resource.file_url || '').toLowerCase();
        if (url.match(/\.pdf($|\?)/)) return 'pdf';
        if (url.match(/youtube\.com|youtu\.be|\.(mp4|webm|mov|avi)($|\?)/)) return 'video';
        if (url.startsWith('http')) return 'link';
        return 'file';
    }

    async function loadPage() {
        if (!RESOURCE_ID) {
            LMS.renderAccessDenied($('resourceAccessDenied'), 'No resource specified.', '/');
            hideEl('resourceSkeleton');
            showEl('resourceAccessDenied');
            return;
        }

        const endpoint = `./api/lms/resources/get.php?course_id=${encodeURIComponent(COURSE_ID)}&resource_id=${encodeURIComponent(RESOURCE_ID)}`;
        const res = await LMS.api('GET', endpoint);
        LMS.debug({ endpoint, response_status: res.status, response_body: res.data, parsed_error_message: res.error || null }, { paneId: 'resourceDebug' });

        hideEl('resourceSkeleton');
        if (res.status === 403) {
            LMS.renderAccessDenied($('resourceAccessDenied'), 'You do not have access to this resource.', `./modules.html?course_id=${encodeURIComponent(COURSE_ID)}`);
            showEl('resourceAccessDenied');
            return;
        }
        if (!res.ok) {
            showEl('resourceError');
            $('resourceRetryBtn')?.addEventListener('click', loadPage, { once: true });
            return;
        }

        const resource = res.data?.data || res.data || {};
        const type = inferType(resource);
        const rawUrl = resource.url || resource.drive_preview_url || resource.file_url || '';
        const drivePreviewUrl = toDrivePreviewUrl(rawUrl);

        document.title = `${resource.title || 'Resource'} — Kairos`;
        $('resourceTypeIcon') && ($('resourceTypeIcon').textContent = TYPE_ICONS[type] || '📄');
        $('resourceTitle') && ($('resourceTitle').textContent = resource.title || 'Resource');
        $('resourceType') && ($('resourceType').textContent = (type || 'file').toUpperCase());

        showEl('resourceViewer');
        ['iframeWrap', 'videoWrap', 'externalWrap', 'textWrap', 'unsupportedWrap'].forEach(hideEl);

        if (type === 'video') {
            const embedUrl = LMS.toYoutubeEmbedUrl(rawUrl);
            if (!embedUrl) {
                $('externalDesc') && ($('externalDesc').textContent = 'This video URL cannot be embedded safely.');
                if (!isHttpUrl(rawUrl) && $('externalDesc')) {
                    $('externalDesc').textContent += ` URL: ${rawUrl}`;
                }
                applySafeExternalLink($('externalLink'), rawUrl, 'Open video in new tab ↗');
                showEl('externalWrap');
                return;
            }
            const videoWrap = $('videoWrap');
            if (!videoWrap) return;
            videoWrap.innerHTML = '';
            const iframe = document.createElement('iframe');
            iframe.setAttribute('src', embedUrl);
            iframe.setAttribute('title', 'Embedded video');
            iframe.setAttribute('style', 'width:100%;height:480px;border:0');
            iframe.setAttribute('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture');
            iframe.setAttribute('allowfullscreen', '');
            videoWrap.appendChild(iframe);
            const p = document.createElement('p');
            p.style.padding = '8px 0';
            const a = document.createElement('a');
            a.textContent = 'Open in new tab ↗';
            if (isHttpUrl(rawUrl)) {
                a.href = rawUrl;
                a.target = '_blank';
                a.rel = 'noopener noreferrer';
            }
            p.appendChild(a);
            videoWrap.appendChild(p);
            showEl('videoWrap');
            return;
        }

        if (type === 'pdf' || type === 'file' || type === 'embed') {
            const iframeSrc = type === 'pdf' ? drivePreviewUrl : rawUrl;
            if (!isHttpUrl(iframeSrc)) {
                showEl('unsupportedWrap');
                return;
            }
            const iframe = $('resourceIframe');
            iframe.src = iframeSrc;
            iframe.onerror = () => {
                $('externalDesc') && ($('externalDesc').textContent = 'Preview failed. Your account may not have access to this file.');
                showEl('externalWrap');
            };
            const onloadTimer = setTimeout(() => {
                $('externalDesc') && ($('externalDesc').textContent = 'Preview failed. Your account may not have access to this file.');
                showEl('externalWrap');
            }, 3000);
            iframe.onload = () => {
                clearTimeout(onloadTimer);
                try {
                    const doc = iframe.contentDocument || iframe.contentWindow?.document;
                    const bodyText = String(doc?.body?.innerText || '').toLowerCase();
                    if (!doc?.body || !doc.body.childElementCount || bodyText.includes('access denied') || bodyText.includes('refused to connect')) {
                        $('externalDesc') && ($('externalDesc').textContent = 'Preview failed. Your account may not have access to this file.');
                        showEl('externalWrap');
                    }
                } catch (_) {
                    $('externalDesc') && ($('externalDesc').textContent = 'Preview may be blocked by provider policy. Open file in Drive.');
                    showEl('externalWrap');
                }
            };
            $('externalDesc') && ($('externalDesc').textContent = 'If preview fails, open this file in Drive in a new tab.');
            if (!isHttpUrl(rawUrl) && $('externalDesc')) {
                $('externalDesc').textContent += ` URL: ${rawUrl}`;
            }
            applySafeExternalLink($('externalLink'), rawUrl, 'Open in Drive ↗');
            showEl('iframeWrap');
            showEl('externalWrap');
            return;
        }

        if (type === 'link') {
            $('externalDesc') && ($('externalDesc').textContent = `This resource links to: ${rawUrl}`);
            if (!isHttpUrl(rawUrl) && $('externalDesc')) {
                $('externalDesc').textContent = `This resource has an unsafe URL and cannot be opened: ${rawUrl}`;
            }
            applySafeExternalLink($('externalLink'), rawUrl, 'Open Resource ↗');
            showEl('externalWrap');
            return;
        }

        $('textContent') && ($('textContent').textContent = resource.content || resource.body || '');
        showEl('textWrap');
    }

    document.addEventListener('DOMContentLoaded', async () => {
        const session = await LMS.boot();
        if (!session) return;
        LMS.nav.updateUserBar(session.me);
        await loadPage();
    });
})();
