(function () {
    'use strict';

    const $ = (id) => document.getElementById(id);
    const LMS = window.KairosLMS;
    const params = new URLSearchParams(location.search);
    const COURSE_ID = params.get('course_id') || '';
    const RESOURCE_ID = params.get('resource_id') || params.get('id') || '';
    const DEBUG_MODE = params.get('debug') === '1';

    let lastRequest = null;

    function showEl(id) { const el = $(id); if (el) el.classList.remove('hidden'); }
    function hideEl(id) { const el = $(id); if (el) el.classList.add('hidden'); }

    const TYPE_ICONS = {
        pdf: '📄', video: '🎬', link: '🔗', text: '📝', page: '📝',
        file: '📎', image: '🖼️', audio: '🎵', embed: '🎬',
    };

    function renderDebugBlock(response) {
        if (!DEBUG_MODE) return;
        let debug = $('resourceDebug');
        if (!debug) {
            debug = document.createElement('pre');
            debug.id = 'resourceDebug';
            debug.className = 'k-card';
            debug.style.cssText = 'padding:12px;white-space:pre-wrap;margin-top:12px;';
            document.querySelector('.k-page')?.appendChild(debug);
        }

        debug.textContent = JSON.stringify({
            resource_id: RESOURCE_ID,
            course_id: COURSE_ID,
            endpoint: `./api/lms/resources/get.php?course_id=${encodeURIComponent(COURSE_ID)}&resource_id=${encodeURIComponent(RESOURCE_ID)}`,
            response_status: response?.status ?? null,
            response_body: response?.data ?? null,
        }, null, 2);
    }


    function parseStartSeconds(value) {
        const raw = String(value || '').trim();
        if (!raw) return 0;
        if (/^\d+$/.test(raw)) return Number(raw);
        const m = raw.match(/(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?/i);
        if (!m) return 0;
        return (Number(m[1] || 0) * 3600) + (Number(m[2] || 0) * 60) + Number(m[3] || 0);
    }

    function toYoutubeEmbedUrl(inputUrl) {
        if (!inputUrl) return null;
        try {
            const parsed = new URL(inputUrl);
            const host = parsed.hostname.replace(/^www\./i, '').toLowerCase();
            let videoId = '';
            if (host === 'youtube.com' || host === 'm.youtube.com') {
                if (parsed.pathname === '/watch') {
                    videoId = parsed.searchParams.get('v') || '';
                } else if (parsed.pathname.startsWith('/embed/')) {
                    videoId = parsed.pathname.split('/')[2] || '';
                } else if (parsed.pathname.startsWith('/shorts/')) {
                    videoId = parsed.pathname.split('/')[2] || '';
                }
            } else if (host === 'youtu.be') {
                videoId = parsed.pathname.replace(/^\//, '').split('/')[0] || '';
            }
            if (!videoId) return null;
            const start = parseStartSeconds(parsed.searchParams.get('t') || parsed.searchParams.get('start') || '');
            const embed = new URL(`https://www.youtube.com/embed/${videoId}`);
            if (start > 0) embed.searchParams.set('start', String(start));
            return embed.toString();
        } catch (_) {
            return null;
        }
    }

    function toDrivePreviewUrl(inputUrl) {
        if (!inputUrl) return '';
        try {
            const parsed = new URL(inputUrl);
            const host = parsed.hostname.replace(/^www\./i, '').toLowerCase();
            if (host !== 'drive.google.com') return inputUrl;
            const match = parsed.pathname.match(/\/file\/d\/([^/]+)/i);
            const fileId = match ? match[1] : (parsed.searchParams.get('id') || '');
            if (!fileId) return inputUrl;
            return `https://drive.google.com/file/d/${fileId}/preview`;
        } catch (_) {
            return inputUrl;
        }
    }

    function ensurePdfHints(resource, url) {
        const notes = $('externalDesc');
        if (!notes) return;
        notes.textContent = `If preview fails, your account may not have Google Drive access for this file.`;
        const link = $('externalLink');
        if (link) {
            link.href = url;
            link.textContent = 'Open in Drive ↗';
        }
    }

    function inferType(resource) {
        if (resource.type) return String(resource.type).toLowerCase();
        const url = (resource.url || resource.file_url || '').toLowerCase();
        if (url.match(/\.(mp4|webm|mov|avi)$/)) return 'video';
        if (url.match(/\.pdf($|\?)/)) return 'pdf';
        if (url.match(/\.(png|jpg|jpeg|gif|webp|svg)$/)) return 'image';
        if (url.match(/\.(mp3|ogg|m4a|wav)$/)) return 'audio';
        if (url.startsWith('http')) return 'link';
        return 'file';
    }

    function embedSafeVideo(url) {
        try {
            const parsed = new URL(url);
            if (!['http:', 'https:'].includes(parsed.protocol)) return '';
            return parsed.toString();
        } catch (_) {
            return '';
        }
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
        lastRequest = { endpoint, ...res };
        renderDebugBlock(lastRequest);
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
        const url = (type === 'pdf' || type === 'file') ? toDrivePreviewUrl(rawUrl) : rawUrl;

        document.title = `${resource.title || 'Resource'} — Kairos`;
        $('resourceTypeIcon') && ($('resourceTypeIcon').textContent = TYPE_ICONS[type] || '📄');
        $('resourceTitle') && ($('resourceTitle').textContent = resource.title || 'Resource');
        $('resourceType') && ($('resourceType').textContent = (type || 'file').toUpperCase());
        $('kBreadResource') && ($('kBreadResource').textContent = resource.title || 'Resource');
        $('kBreadCourse') && ($('kBreadCourse').href = `./course.html?course_id=${encodeURIComponent(COURSE_ID)}`);
        $('kBreadModules') && ($('kBreadModules').href = `./modules.html?course_id=${encodeURIComponent(COURSE_ID)}`);
        $('backToModules') && ($('backToModules').href = `./modules.html?course_id=${encodeURIComponent(COURSE_ID)}`);

        if (url && type !== 'link') {
            const dlBtn = $('downloadBtn');
            if (dlBtn) { dlBtn.href = url; dlBtn.classList.remove('hidden'); }
            const openBtn = $('openNewTabBtn');
            if (openBtn) {
                openBtn.classList.remove('hidden');
                openBtn.addEventListener('click', () => window.open(url, '_blank', 'noopener'));
            }
        }

        showEl('resourceViewer');
        ['iframeWrap', 'videoWrap', 'externalWrap', 'textWrap', 'unsupportedWrap'].forEach(hideEl);

        if (type === 'pdf' || type === 'file' || type === 'embed') {
            const iframe = $('resourceIframe');
            if (iframe && url) {
                iframe.src = url;
                showEl('iframeWrap');
                ensurePdfHints(resource, rawUrl || url);
                showEl('externalWrap');
            } else {
                $('downloadFallbackBtn') && ($('downloadFallbackBtn').href = url);
                showEl('unsupportedWrap');
            }
            return;
        }

        if (type === 'video') {
            const ytUrl = toYoutubeEmbedUrl(url);
            const safeVideoUrl = ytUrl || embedSafeVideo(url);
            if (!safeVideoUrl) {
                showEl('unsupportedWrap');
                return;
            }
            const videoWrap = $('videoWrap');
            if (videoWrap) {
                if (!safeVideoUrl) {
                    showEl('externalWrap');
                    return;
                }
                videoWrap.innerHTML = `<iframe src="${safeVideoUrl}" title="Embedded video" style="width:100%;height:480px;border:0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe><p style="padding:8px 0"><a href="${LMS.escHtml(url)}" target="_blank" rel="noopener noreferrer">Open in new tab ↗</a></p>`;
                showEl('videoWrap');
            }
            return;
        }

        if (type === 'link') {
            $('externalDesc') && ($('externalDesc').textContent = `This resource links to: ${url}`);
            if ($('externalLink')) {
                $('externalLink').href = url;
                $('externalLink').textContent = 'Open Resource ↗';
            }
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
