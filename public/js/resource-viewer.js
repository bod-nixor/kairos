(function () {
    'use strict';

    const $ = (id) => document.getElementById(id);
    const LMS = window.KairosLMS;
    const params = new URLSearchParams(location.search);
    const COURSE_ID = params.get('course_id') || '';
    const RESOURCE_ID = params.get('resource_id') || params.get('id') || '';
    const URL_MODE = params.get('mode') || 'view';

    let currentCourse = null;
    let currentResource = null;
    let isSavingResource = false;

    const TYPE_ICONS = {
        pdf: 'PDF',
        video: 'Vid',
        link: 'Link',
        text: 'Txt',
        page: 'Txt',
        file: 'File',
        image: 'Img',
        audio: 'Aud',
        embed: 'Vid',
        slides: 'Sld',
        ppt: 'PPT',
    };

    function showEl(id) { $(id)?.classList.remove('hidden'); }
    function hideEl(id) { $(id)?.classList.add('hidden'); }

    function isHttpUrl(value) {
        try {
            const parsed = new URL(String(value || ''));
            return parsed.protocol === 'http:' || parsed.protocol === 'https:';
        } catch (_) {
            return false;
        }
    }

    function isSameOriginUrl(value) {
        try {
            const parsed = new URL(String(value || ''), window.location.href);
            return (parsed.protocol === 'http:' || parsed.protocol === 'https:')
                && parsed.origin === window.location.origin;
        } catch (_) {
            return false;
        }
    }

    function isManagedResource(resource = currentResource) {
        return resource?.storage_backend === 'google_drive';
    }

    function getCurrentCourseRoleFlags() {
        return LMS.resolveCourseRoleFlags(currentCourse || {});
    }

    function syncShell(resource = currentResource) {
        const courseName = currentCourse?.name || currentCourse?.code || 'Course';
        const currentCourseId = COURSE_ID || resource?.course_id || '';
        const resourceTitle = resource?.title || 'Resource';

        LMS.nav.setCourseContext(currentCourseId, courseName);
        LMS.nav.setActive('modules');
        LMS.nav.setBreadcrumb([
            { name: 'All Courses', href: '/signoff/' },
            { name: courseName, href: `./course.html?course_id=${encodeURIComponent(currentCourseId)}` },
            { name: 'Modules', href: `./modules.html?course_id=${encodeURIComponent(currentCourseId)}` },
            { name: resourceTitle },
        ]);

        const courseRole = getCurrentCourseRoleFlags();
        if (courseRole.ta || courseRole.manager || courseRole.admin) {
            $('kNavGrading')?.classList.remove('hidden');
        }
        if (courseRole.manager || courseRole.admin) {
            $('kNavAnalytics')?.classList.remove('hidden');
        }

        $('resourceTitle') && ($('resourceTitle').textContent = resourceTitle);
        document.title = `${resourceTitle} - ${courseName} - ${LMS.getProductName()}`;
    }

    function inferType(resource) {
        const declaredType = resource && typeof resource === 'object' && resource.type
            ? String(resource.type).toLowerCase()
            : '';
        const mime = String(resource?.mime_type || resource?.mime || '').toLowerCase();
        if (mime === 'application/pdf') return 'pdf';
        if (mime.startsWith('image/')) return 'image';
        if (mime.startsWith('audio/')) return 'audio';
        if (mime.startsWith('video/')) return 'video';
        if (declaredType) return declaredType;
        const url = typeof resource === 'string'
            ? String(resource).toLowerCase()
            : String(resource?.url || resource?.file_url || '').toLowerCase();
        if (url.match(/\.pdf($|\?)/)) return 'pdf';
        if (url.match(/\.(ppt|pptx)($|\?)/)) return 'ppt';
        if (url.match(/\.(png|jpe?g|gif|webp|svg|bmp|avif)($|\?)/)) return 'image';
        if (url.match(/\.(mp3|wav|m4a|aac|flac|oga|ogg)($|\?)/)) return 'audio';
        if (url.includes('docs.google.com/presentation') || url.includes('slides.google.com')) return 'slides';
        if (url.match(/youtube\.com|youtu\.be|\.(mp4|webm|mov)($|\?)/)) return 'video';
        if (url.startsWith('http')) return 'link';
        return 'file';
    }

    function videoMimeFromUrl(rawUrl) {
        try {
            const pathname = new URL(String(rawUrl || '')).pathname.toLowerCase();
            if (pathname.endsWith('.mp4')) return 'video/mp4';
            if (pathname.endsWith('.webm')) return 'video/webm';
            if (pathname.endsWith('.mov')) return 'video/quicktime';
            if (pathname.endsWith('.ogv')) return 'video/ogg';
            if (pathname.endsWith('.m4v')) return 'video/x-m4v';
        } catch (_) {
            return '';
        }
        return '';
    }

    function isDirectVideoUrl(rawUrl) {
        try {
            const pathname = new URL(String(rawUrl || '')).pathname.toLowerCase();
            return /\.(mp4|webm|mov|ogv|m4v)($|\?)/.test(pathname);
        } catch (_) {
            return false;
        }
    }

    function hardenPreviewIframe(iframe) {
        if (!iframe) return;
        iframe.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-forms allow-popups');
        iframe.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
    }

    function confirmExternalNavigation(url, isDownload) {
        if (!url) return;
        LMS.openModal({
            title: 'External Link',
            body: `<p>You are being redirected to an external link${isDownload ? ' to download this file' : ''}. Continue?</p>`,
            narrow: true,
            actions: [
                { id: 'cancel', label: 'Cancel', class: 'btn-ghost', onClick: LMS.closeModal },
                {
                    id: 'continue',
                    label: 'Continue',
                    class: 'btn-primary',
                    onClick: () => {
                        LMS.closeModal();
                        window.open(url, '_blank', 'noopener,noreferrer');
                    },
                },
            ],
        });
    }

    function applySafeExternalLink(linkEl, rawUrl, label, isDownload = false) {
        if (!linkEl) return;
        const value = String(rawUrl || '').trim();
        linkEl.textContent = label;
        if (isHttpUrl(value)) {
            linkEl.href = value;
            linkEl.setAttribute('target', '_blank');
            linkEl.setAttribute('rel', 'noopener noreferrer');
            linkEl.onclick = (event) => {
                event.preventDefault();
                confirmExternalNavigation(value, isDownload);
            };
            return;
        }
        linkEl.removeAttribute('href');
        linkEl.removeAttribute('target');
        linkEl.removeAttribute('rel');
        linkEl.onclick = null;
    }

    function applyManagedLink(linkEl, rawUrl, label, isDownload = false) {
        if (!linkEl) return;
        const value = String(rawUrl || '').trim();
        linkEl.textContent = label;
        if (!isSameOriginUrl(value)) {
            linkEl.removeAttribute('href');
            linkEl.onclick = null;
            return;
        }
        linkEl.href = value;
        linkEl.removeAttribute('target');
        linkEl.removeAttribute('rel');
        if (isDownload) linkEl.setAttribute('download', '');
        else linkEl.removeAttribute('download');
        linkEl.onclick = null;
    }

    async function loadCourse() {
        if (!COURSE_ID) return;
        const res = await LMS.api('GET', `./api/lms/courses.php?course_id=${encodeURIComponent(COURSE_ID)}`);
        if (!res.ok) return;
        currentCourse = res.data?.data || res.data || null;
        syncShell();
    }

    function showViewerState(type) {
        showEl('resourceViewer');
        ['iframeWrap', 'videoWrap', 'externalWrap', 'textWrap', 'unsupportedWrap'].forEach(hideEl);
        showEl(type);
    }

    function setExternalDescription(message) {
        if ($('externalDesc')) $('externalDesc').textContent = message;
    }

    function renderPdfLikePreview(rawUrl, resource) {
        const managed = isManagedResource(resource);
        const iframeSrc = managed ? (resource?.preview_url || '') : LMS.toDrivePreviewUrl(rawUrl);
        const validPreview = managed ? isSameOriginUrl(iframeSrc) : isHttpUrl(iframeSrc);
        if (!validPreview) {
            const fallbackUrl = resource?.download_url || rawUrl;
            if (managed) applyManagedLink($('downloadFallbackBtn'), fallbackUrl, 'Download Resource', true);
            else applySafeExternalLink($('downloadFallbackBtn'), fallbackUrl, 'Open Resource', false);
            showViewerState('unsupportedWrap');
            return;
        }

        const iframe = $('resourceIframe');
        if (!iframe) return;
        hardenPreviewIframe(iframe);
        hideEl('externalWrap');
        iframe.src = iframeSrc;

        if (managed) {
            iframe.onerror = () => {
                setExternalDescription('Preview failed to load. Download the file instead.');
                applyManagedLink($('externalLink'), resource?.download_url || rawUrl, 'Download file', true);
                showEl('externalWrap');
            };
            applyManagedLink($('externalLink'), resource?.download_url || rawUrl, 'Download file', true);
            showViewerState('iframeWrap');
            return;
        }

        let failed = false;
        const markFailed = () => {
            if (failed) return;
            failed = true;
            setExternalDescription('Preview failed to load. Please open the file in Drive.');
            applySafeExternalLink($('externalLink'), rawUrl, 'Open in Drive');
            showEl('externalWrap');
        };

        iframe.onerror = markFailed;
        const onloadTimer = setTimeout(markFailed, 7000);

        iframe.onload = () => {
            if (failed) return;
            clearTimeout(onloadTimer);
            setExternalDescription('Having trouble viewing? Open in Drive.');
            hideEl('externalWrap');
            try {
                const doc = iframe.contentDocument || iframe.contentWindow?.document;
                if (doc) {
                    const bodyText = String(doc.body?.innerText || '').toLowerCase();
                    if (!doc.body || !doc.body.childElementCount || bodyText.includes('access denied') || bodyText.includes('refused to connect')) {
                        markFailed();
                    }
                }
            } catch (_) {
                // Cross-origin iframe content cannot be inspected. Assume the preview loaded.
            }
        };

        applySafeExternalLink($('externalLink'), rawUrl, 'Open in Drive');
        showViewerState('iframeWrap');
    }

    function renderVideo(rawUrl, resource) {
        const embedUrl = LMS.toYoutubeEmbedUrl(rawUrl);
        const videoWrap = $('videoWrap');
        if (!videoWrap) return;
        videoWrap.innerHTML = '';

        if (isManagedResource(resource) && isSameOriginUrl(resource?.preview_url || '')) {
            videoWrap.classList.remove('k-embed-16x9');
            const video = document.createElement('video');
            video.className = 'k-resource-video';
            video.controls = true;
            video.playsInline = true;
            video.preload = 'metadata';
            const source = document.createElement('source');
            source.src = resource.preview_url;
            source.type = resource.mime_type || '';
            video.appendChild(source);
            video.appendChild(document.createTextNode('Your browser does not support the video tag.'));
            videoWrap.appendChild(video);
            showViewerState('videoWrap');
            return;
        }
        if (isManagedResource(resource)) {
            setExternalDescription('Browser preview is not available for this private video. Download the file instead.');
            applyManagedLink($('externalLink'), resource?.download_url || rawUrl, 'Download video', true);
            showViewerState('externalWrap');
            return;
        }

        if (embedUrl) {
            videoWrap.classList.add('k-embed-16x9');
            const iframe = document.createElement('iframe');
            iframe.setAttribute('src', embedUrl);
            iframe.setAttribute('title', 'Embedded video');
            iframe.setAttribute('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture');
            iframe.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-presentation allow-popups');
            iframe.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
            iframe.setAttribute('allowfullscreen', 'true');
            videoWrap.appendChild(iframe);
            showViewerState('videoWrap');
            return;
        }

        if (isDirectVideoUrl(rawUrl) && isHttpUrl(rawUrl)) {
            videoWrap.classList.remove('k-embed-16x9');
            const video = document.createElement('video');
            video.className = 'k-resource-video';
            video.controls = true;
            video.playsInline = true;
            video.preload = 'metadata';
            const source = document.createElement('source');
            source.src = rawUrl;
            const mime = videoMimeFromUrl(rawUrl);
            if (mime) source.type = mime;
            video.appendChild(source);
            video.appendChild(document.createTextNode('Your browser does not support the video tag.'));
            videoWrap.appendChild(video);
            showViewerState('videoWrap');
            return;
        }

        setExternalDescription('This video URL cannot be embedded safely.');
        if (!isHttpUrl(rawUrl)) {
            setExternalDescription(`This video URL cannot be opened: ${rawUrl}`);
        }
        applySafeExternalLink($('externalLink'), rawUrl, 'Open video in new tab');
        showViewerState('externalWrap');
    }

    function renderExternal(rawUrl) {
        setExternalDescription(`This resource links to: ${rawUrl}`);
        if (!isHttpUrl(rawUrl)) {
            setExternalDescription(`This resource has an unsafe URL and cannot be opened: ${rawUrl}`);
        }
        applySafeExternalLink($('externalLink'), rawUrl, 'Open Resource');
        showViewerState('externalWrap');
    }

    function renderMediaExternal(resource, kind) {
        const rawUrl = resource?.url || resource?.drive_preview_url || resource?.file_url || '';
        const mime = resource?.mime_type || resource?.mime || '';
        if (isManagedResource(resource)) {
            setExternalDescription(`This ${kind} resource${mime ? ` (${mime})` : ''} is stored privately in Kairos.`);
            applyManagedLink($('externalLink'), resource.download_url || rawUrl, `Open ${kind}`, false);
            showViewerState('externalWrap');
            return;
        }
        setExternalDescription(`This ${kind} resource${mime ? ` (${mime})` : ''} opens in an external viewer.`);
        if (!isHttpUrl(rawUrl)) {
            setExternalDescription(`This ${kind} resource has an unsafe URL and cannot be opened: ${rawUrl}`);
        }
        applySafeExternalLink($('externalLink'), rawUrl, `Open ${kind}`);
        showViewerState('externalWrap');
    }

    function renderImage(resource) {
        renderMediaExternal(resource, 'image');
    }

    function renderAudio(resource) {
        renderMediaExternal(resource, 'audio');
    }

    function renderSlides(rawUrl) {
        const iframe = $('resourceIframe');
        const src = LMS.toDrivePreviewUrl(rawUrl);
        if (!iframe || !isHttpUrl(src)) {
            applySafeExternalLink($('downloadFallbackBtn'), rawUrl, 'Open Resource', false);
            showViewerState('unsupportedWrap');
            return;
        }
        hardenPreviewIframe(iframe);
        iframe.src = src;
        showViewerState('iframeWrap');
    }

    function renderPpt(rawUrl) {
        const officeUrl = LMS.toOfficeViewerUrl(rawUrl);
        const iframe = $('resourceIframe');
        if (!iframe || !officeUrl) {
            applySafeExternalLink($('downloadFallbackBtn'), rawUrl, 'Open Resource', false);
            showViewerState('unsupportedWrap');
            return;
        }
        hardenPreviewIframe(iframe);
        iframe.src = officeUrl;
        showViewerState('iframeWrap');
    }

    function renderText(resource) {
        if ($('textContent')) $('textContent').textContent = resource.content || resource.body || '';
        showViewerState('textWrap');
    }

    function updateActionButtons(resource, type) {
        const downloadBtn = $('downloadBtn');
        const openBtn = $('openNewTabBtn');
        downloadBtn?.classList.add('hidden');
        openBtn?.classList.add('hidden');

        const rawUrl = resource?.url || resource?.preview_url || resource?.drive_preview_url || '';
        if (isManagedResource(resource)) {
            const downloadUrl = resource?.download_url || '';
            const previewUrl = resource?.preview_url || '';
            if (downloadBtn && isSameOriginUrl(downloadUrl)) {
                downloadBtn.classList.remove('hidden');
                downloadBtn.href = downloadUrl;
                downloadBtn.onclick = null;
            }
            if (openBtn && isSameOriginUrl(previewUrl)) {
                openBtn.classList.remove('hidden');
                openBtn.onclick = () => {
                    window.open(previewUrl, '_blank', 'noopener,noreferrer');
                };
            }
            return;
        }

        if (!rawUrl || type === 'link' || !isHttpUrl(rawUrl)) return;

        const downloadUrl = LMS.toDriveDownloadUrl(rawUrl);
        if (downloadBtn && downloadUrl) {
            downloadBtn.classList.remove('hidden');
            downloadBtn.onclick = (event) => {
                event.preventDefault();
                confirmExternalNavigation(downloadUrl, true);
            };
        }

        if (openBtn) {
            openBtn.classList.remove('hidden');
            openBtn.onclick = (event) => {
                event.preventDefault();
                confirmExternalNavigation(rawUrl, false);
            };
        }
    }

    function renderEditPanel(resource) {
        const wrap = $('resourceEditPanel');
        if (!wrap) return;

        const normalizedPublished = (resource.published === 1 || resource.published === '1') ? 1 : 0;
        const managed = isManagedResource(resource);
        wrap.classList.remove('hidden');
        wrap.innerHTML = `
          <div class="k-card k-staff-panel">
            <div class="k-inline-actions k-panel-gap">
              <span class="k-badge k-badge--edit">Editing</span>
              <h3 class="k-staff-panel__title">Edit Resource</h3>
            </div>
            <div class="k-form-field">
              <label for="editResTitle">Title</label>
              <input class="k-input" id="editResTitle" value="${LMS.escHtml(resource.title || '')}" />
            </div>
            <div class="k-form-field k-panel-gap ${managed ? 'hidden' : ''}">
              <label for="editResUrl">URL / Drive Link</label>
              <input class="k-input" id="editResUrl" value="${LMS.escHtml(resource.url || resource.drive_preview_url || resource.file_url || '')}" placeholder="https://..." />
            </div>
            ${managed ? '<p class="k-muted k-panel-gap">Stored file bytes are private and cannot be replaced with an external URL. Upload a new resource to replace the file.</p>' : ''}
            <div class="k-form-field k-panel-gap">
              <label for="editResPublished">Status</label>
              <select class="k-select k-control-md" id="editResPublished">
                <option value="1" ${normalizedPublished === 1 ? 'selected' : ''}>Published</option>
                <option value="0" ${normalizedPublished === 0 ? 'selected' : ''}>Draft</option>
              </select>
            </div>
            <div class="k-inline-actions k-panel-gap">
              <button class="btn btn-primary btn-sm" id="saveResourceBtn">Save Changes</button>
              <a class="btn btn-ghost btn-sm" href="./resource-viewer.html?course_id=${encodeURIComponent(COURSE_ID)}&resource_id=${encodeURIComponent(RESOURCE_ID)}&mode=view">Cancel</a>
            </div>
          </div>`;

        $('saveResourceBtn')?.addEventListener('click', saveResource);
    }

    async function saveResource() {
        if (isSavingResource) return;

        const title = $('editResTitle')?.value?.trim() || '';
        const url = $('editResUrl')?.value?.trim() || '';
        const published = $('editResPublished')?.value === '1' ? 1 : 0;
        if (!title) {
            LMS.toast('Title is required.', 'warning');
            return;
        }

        isSavingResource = true;
        try {
            const payload = {
                course_id: Number(COURSE_ID),
                resource_id: Number(RESOURCE_ID),
                title,
                published,
            };
            if (!isManagedResource(currentResource)) payload.url = url;
            const res = await LMS.api('POST', './api/lms/resources/update.php', payload);
            if (!res.ok) {
                LMS.toast(res.data?.error?.message || 'Failed to save resource.', 'error');
                return;
            }
            LMS.toast('Resource updated.', 'success');
            window.location.href = `./resource-viewer.html?course_id=${encodeURIComponent(COURSE_ID)}&resource_id=${encodeURIComponent(RESOURCE_ID)}&mode=view`;
        } catch (_) {
            LMS.toast('Failed to save resource.', 'error');
        } finally {
            isSavingResource = false;
        }
    }

    async function loadPage() {
        if (!RESOURCE_ID) {
            LMS.renderAccessDenied($('resourceAccessDenied'), 'No resource specified.', '/signoff/');
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

        currentResource = res.data?.data || res.data || {};
        const type = inferType(currentResource);
        const rawUrl = currentResource.preview_url
            || currentResource.url
            || currentResource.drive_preview_url
            || currentResource.file_url
            || currentResource.download_url
            || '';

        $('resourceTypeIcon') && ($('resourceTypeIcon').textContent = TYPE_ICONS[type] || 'File');
        $('resourceType') && ($('resourceType').textContent = (type || 'file').toUpperCase());
        syncShell(currentResource);
        updateActionButtons(currentResource, type);

        if (type === 'video') {
            renderVideo(rawUrl, currentResource);
            return;
        }
        if (type === 'slides') {
            renderSlides(rawUrl);
            return;
        }
        if (type === 'ppt') {
            renderPpt(rawUrl);
            return;
        }
        if (type === 'pdf' || type === 'file' || type === 'embed') {
            renderPdfLikePreview(rawUrl, currentResource);
            return;
        }
        if (type === 'link') {
            renderExternal(rawUrl);
            return;
        }
        if (type === 'image') {
            renderImage(currentResource);
            return;
        }
        if (type === 'audio') {
            renderAudio(currentResource);
            return;
        }

        renderText(currentResource);
    }

    document.addEventListener('DOMContentLoaded', async () => {
        const session = await LMS.boot();
        if (!session) return;
        LMS.nav.updateUserBar(session.me);

        await Promise.all([loadCourse(), loadPage()]);

        const courseRole = getCurrentCourseRoleFlags();
        const canEditResource = !!(courseRole.manager || courseRole.admin);
        if (URL_MODE === 'edit' && !canEditResource) {
            hideEl('resourceViewer');
            hideEl('resourceEditPanel');
            LMS.renderAccessDenied($('resourceAccessDenied'), 'You do not have permission to edit this resource.', `./modules.html?course_id=${encodeURIComponent(COURSE_ID)}`);
            showEl('resourceAccessDenied');
            return;
        }

        if (URL_MODE === 'edit' && canEditResource && currentResource) {
            renderEditPanel(currentResource);
        }
    });
})();
