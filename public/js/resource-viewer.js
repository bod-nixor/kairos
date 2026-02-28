(function () {
    'use strict';

    const $ = (id) => document.getElementById(id);
    const LMS = window.KairosLMS;
    const params = new URLSearchParams(location.search);
    const COURSE_ID = params.get('course_id') || '';
    const RESOURCE_ID = params.get('resource_id') || params.get('id') || '';
    const URL_MODE = params.get('mode') || 'view';

    let isAdmin = false;
    let currentResource = null;
    let isSavingResource = false;

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

    function toOfficeViewerUrl(rawUrl) {
        return LMS.toOfficeViewerUrl(rawUrl);
    }

    function toDrivePreviewUrl(rawUrl) {
        return LMS.toDrivePreviewUrl ? LMS.toDrivePreviewUrl(rawUrl) : '';
    }

    function toDriveDownloadUrl(rawUrl) {
        return LMS.toDriveDownloadUrl ? LMS.toDriveDownloadUrl(rawUrl) : '';
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
                    id: 'continue', label: 'Continue', class: 'btn-primary', onClick: () => {
                        LMS.closeModal();
                        window.open(url, '_blank', 'noopener,noreferrer');
                    }
                }
            ]
        });
    }

    function applySafeExternalLink(linkEl, rawUrl, label, isDownload = false) {
        if (!linkEl) return;
        const value = String(rawUrl || '').trim();
        linkEl.textContent = label;
        if (isHttpUrl(value)) {
            // Keep native href to allow keyboard and screen-reader support
            linkEl.href = value;
            linkEl.setAttribute('target', '_blank');
            linkEl.setAttribute('rel', 'noopener noreferrer');
            linkEl.style.cursor = 'pointer';
            linkEl.onclick = (e) => {
                e.preventDefault();
                confirmExternalNavigation(value, isDownload);
            };
            return;
        }
        linkEl.removeAttribute('href');
        linkEl.removeAttribute('target');
        linkEl.removeAttribute('rel');
        linkEl.onclick = null;
    }

    function hardenPreviewIframe(iframe) {
        if (!iframe) return;
        iframe.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-forms allow-popups');
        iframe.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
    }

    function inferType(resource) {
        if (resource.type) return String(resource.type).toLowerCase();
        const url = (resource.url || resource.file_url || '').toLowerCase();
        if (url.match(/\.pdf($|\?)/)) return 'pdf';
        if (url.match(/\.(ppt|pptx)($|\?)/)) return 'ppt';
        if (url.includes('docs.google.com/presentation') || url.includes('slides.google.com')) return 'slides';
        if (url.match(/youtube\.com|youtu\.be|\.(mp4|webm|mov|avi)($|\?)/)) return 'video';
        if (url.startsWith('http')) return 'link';
        return 'file';
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

        const resource = res.data?.data || res.data || {};
        currentResource = resource;
        const type = inferType(resource);
        const rawUrl = resource.url || resource.drive_preview_url || resource.file_url || '';
        const drivePreviewUrl = toDrivePreviewUrl(rawUrl);

        document.title = `${resource.title || 'Resource'} — Kairos`;
        $('resourceTypeIcon') && ($('resourceTypeIcon').textContent = TYPE_ICONS[type] || '📄');
        $('resourceTitle') && ($('resourceTitle').textContent = resource.title || 'Resource');
        $('resourceType') && ($('resourceType').textContent = (type || 'file').toUpperCase());
        $('kBreadResource') && ($('kBreadResource').textContent = resource.title || 'Resource');
        const bc = $('kBreadCourse');
        if (bc) {
            bc.href = `./course.html?course_id=${encodeURIComponent(COURSE_ID)}`;
            bc.textContent = resource.course_name || 'Course';
        }
        $('kBreadModules') && ($('kBreadModules').href = `./modules.html?course_id=${encodeURIComponent(COURSE_ID)}`);
        $('kSidebarCourseName') && ($('kSidebarCourseName').textContent = resource.course_name || '');
        $('backToModules') && ($('backToModules').href = `./modules.html?course_id=${encodeURIComponent(COURSE_ID)}`);

        // Download + open buttons
        if (rawUrl && type !== 'link' && isHttpUrl(rawUrl)) {
            const dlBtn = $('downloadBtn');
            if (dlBtn) {
                // If it's a Drive file, try to get a direct download URL, otherwise generic download action is not supported cleanly so we hide it.
                // Or we can just use the rawUrl as a fallback but that just opens the file.
                // The requirements say "If export is straightforward, use export endpoints. Otherwise, do NOT fake download — instead route to Open with confirmation."
                const dlUrl = toDriveDownloadUrl(rawUrl);
                if (dlUrl) {
                    dlBtn.removeAttribute('href');
                    dlBtn.removeAttribute('download');
                    dlBtn.style.cursor = 'pointer';
                    dlBtn.classList.remove('hidden');
                    dlBtn.onclick = (e) => {
                        e.preventDefault();
                        confirmExternalNavigation(dlUrl, true);
                    };
                } else {
                    dlBtn.classList.add('hidden'); // Cannot reliably download, keep hidden
                }
            }
            const openBtn = $('openNewTabBtn');
            if (openBtn) {
                openBtn.classList.remove('hidden');
                openBtn.onclick = (e) => {
                    e.preventDefault();
                    confirmExternalNavigation(rawUrl, false);
                };
            }
        }

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
            videoWrap.classList.add('k-embed-16x9');
            videoWrap.innerHTML = '';
            const iframe = document.createElement('iframe');
            iframe.setAttribute('src', embedUrl);
            iframe.setAttribute('title', 'Embedded video');
            iframe.setAttribute('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture');
            iframe.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-presentation allow-popups');
            iframe.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
            iframe.setAttribute('allowfullscreen', 'true');
            videoWrap.appendChild(iframe);
            showEl('videoWrap');
            return;
        }

        if (type === 'slides') {
            const iframe = $('resourceIframe');
            const src = toDrivePreviewUrl(rawUrl);
            if (!isHttpUrl(src)) {
                applySafeExternalLink($('downloadFallbackBtn'), rawUrl, 'Open Resource ↗', false);
                showEl('unsupportedWrap');
                return;
            }
            hardenPreviewIframe(iframe);
            if (!iframe) {
                applySafeExternalLink($('downloadFallbackBtn'), rawUrl, 'Open Resource ↗', false);
                showEl('unsupportedWrap');
                return;
            }
            iframe.src = src;
            showEl('iframeWrap');
            return;
        }

        if (type === 'ppt') {
            const officeUrl = toOfficeViewerUrl(rawUrl);
            if (officeUrl) {
                const iframe = $('resourceIframe');
                hardenPreviewIframe(iframe);
                if (!iframe) {
                    applySafeExternalLink($('downloadFallbackBtn'), rawUrl, 'Open Resource ↗', false);
                    showEl('unsupportedWrap');
                    return;
                }
                iframe.src = officeUrl;
                showEl('iframeWrap');
                return;
            }
            applySafeExternalLink($('downloadFallbackBtn'), rawUrl, 'Open Resource ↗', false);
            showEl('unsupportedWrap');
            return;
        }

        if (type === 'pdf' || type === 'file' || type === 'embed') {
            const iframeSrc = toDrivePreviewUrl(rawUrl);
            if (!isHttpUrl(iframeSrc)) {
                applySafeExternalLink($('downloadFallbackBtn'), rawUrl, 'Open Resource ↗', false);
                showEl('unsupportedWrap');
                return;
            }
            const iframe = $('resourceIframe');
            hardenPreviewIframe(iframe);
            if (!iframe) {
                applySafeExternalLink($('downloadFallbackBtn'), rawUrl, 'Open Resource ↗', false);
                showEl('unsupportedWrap');
                return;
            }
            // State: loading. Hide fallback.
            hideEl('externalWrap');

            iframe.src = iframeSrc;

            let failed = false;

            const markFailed = () => {
                if (failed) return;
                failed = true;
                $('externalDesc') && ($('externalDesc').textContent = 'Preview failed to load. Please open the file in Drive.');
                showEl('externalWrap');
            };

            iframe.onerror = () => {
                markFailed();
            };

            const onloadTimer = setTimeout(() => {
                markFailed();
            }, 7000); // 7 seconds timeout

            iframe.onload = () => {
                if (failed) return; // already failed via timeout
                clearTimeout(onloadTimer);
                // We assume success by default since checking cross-origin docs throws errors.
                // We keep externalWrap hidden.
                // Optional: subtle hint
                $('externalDesc') && ($('externalDesc').textContent = 'Having trouble viewing? Open in Drive ↗');
                // The requirements say "Instead, optionally show a small subtle helper link below the preview. The large fallback banner should ONLY appear on confirmed failure."
                // I will hide the big fallback wrapper entirely
                hideEl('externalWrap');

                // If it successfully loads but it's an error page (same origin frame), detect it:
                try {
                    const doc = iframe.contentDocument || iframe.contentWindow?.document;
                    if (doc) {
                        const bodyText = String(doc.body?.innerText || '').toLowerCase();
                        if (!doc.body || !doc.body.childElementCount || bodyText.includes('access denied') || bodyText.includes('refused to connect')) {
                            markFailed();
                        }
                    }
                } catch (_) {
                    // Cross-origin error means it loaded Google's page successfully. We assume preview works.
                }
            };

            applySafeExternalLink($('externalLink'), rawUrl, 'Open in Drive ↗');
            showEl('iframeWrap');
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

    function renderEditPanel(resource) {
        const wrap = $('resourceEditPanel');
        if (!wrap) return;
        wrap.classList.remove('hidden');
        // Normalize published value to handle string '0', numeric 0, and false as Draft
        const normalizedPublished = (resource.published === 1 || resource.published === '1') ? 1 : 0;
        wrap.innerHTML = `
          <div class="k-card" style="padding:var(--space-5);margin-bottom:var(--space-4)">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:var(--space-4)">
              <span class="k-badge k-badge--edit">Editing</span>
              <h3 style="margin:0;font-size:var(--font-lg)">Edit Resource</h3>
            </div>
            <div class="k-form-field" style="margin-bottom:var(--space-3)">
              <label for="editResTitle">Title</label>
              <input class="k-input" id="editResTitle" value="${LMS.escHtml(resource.title || '')}" />
            </div>
            <div class="k-form-field" style="margin-bottom:var(--space-3)">
              <label for="editResUrl">URL / Drive Link</label>
              <input class="k-input" id="editResUrl" value="${LMS.escHtml(resource.url || resource.drive_preview_url || resource.file_url || '')}" placeholder="https://..." />
            </div>
            <div class="k-form-field" style="margin-bottom:var(--space-4)">
              <label for="editResPublished">Status</label>
              <select class="k-select" id="editResPublished">
                <option value="1" ${normalizedPublished === 1 ? 'selected' : ''}>Published</option>
                <option value="0" ${normalizedPublished === 0 ? 'selected' : ''}>Draft</option>
              </select>
            </div>
            <div style="display:flex;gap:8px">
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
        if (!title) { LMS.toast('Title is required.', 'warning'); return; }

        isSavingResource = true;
        try {
            const res = await LMS.api('POST', './api/lms/resources/update.php', {
                course_id: Number(COURSE_ID),
                resource_id: Number(RESOURCE_ID),
                title,
                url,
                published,
            });
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

    document.addEventListener('DOMContentLoaded', async () => {
        const session = await LMS.boot();
        if (!session) return;
        LMS.nav.updateUserBar(session.me);
        const roles = session.caps?.roles || {};
        isAdmin = !!(roles.admin || roles.manager);

        // If mode=edit but not admin, deny access
        if (URL_MODE === 'edit' && !isAdmin) {
            hideEl('resourceSkeleton');
            LMS.renderAccessDenied($('resourceAccessDenied'), 'You do not have permission to edit this resource.', `./modules.html?course_id=${encodeURIComponent(COURSE_ID)}`);
            showEl('resourceAccessDenied');
            return;
        }

        await loadPage();

        // Show edit panel if mode=edit and admin
        if (URL_MODE === 'edit' && isAdmin && currentResource) {
            renderEditPanel(currentResource);
        }
    });
})();
