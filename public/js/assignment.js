/**
 * assignment.js — Assignment page controller
 */
(function () {
    'use strict';

    const $ = id => document.getElementById(id);
    const LMS = window.KairosLMS;
    const params = new URLSearchParams(location.search);
    const COURSE_ID = params.get('course_id') || '';
    const ASSIGN_ID = params.get('assignment_id') || '';
    const DEBUG_MODE = params.get('debug') === '1';
    const URL_MODE = params.get('mode') || 'view';

    function showEl(id) { const el = $(id); if (el) el.classList.remove('hidden'); }
    function hideEl(id) { const el = $(id); if (el) el.classList.add('hidden'); }

    function resolveCourseRoleFlags(value) {
        if (value && typeof value === 'object') {
            return {
                student: !!value.student,
                ta: !!value.ta,
                manager: !!value.manager,
                admin: !!value.admin,
            };
        }
        const role = String(value || '').toLowerCase();
        return {
            student: role === 'student',
            ta: role === 'ta',
            manager: role === 'manager',
            admin: role === 'admin',
        };
    }

    let assignData = null;
    let uploadedFiles = [];
    let canManage = false;
    const debugLogs = [];

    function safeStringify(v) {
        try { return JSON.stringify(v, null, 2); } catch (_) { return String(v); }
    }

    function logDebug(entry) {
        if (!DEBUG_MODE) return;
        debugLogs.push(entry);
        let debugEl = $('assignDebug');
        if (!debugEl) {
            debugEl = document.createElement('pre');
            debugEl.id = 'assignDebug';
            debugEl.className = 'k-card k-debug-panel';
            document.querySelector('.k-page')?.appendChild(debugEl);
        }
        debugEl.textContent = safeStringify(debugLogs);
    }

    // ── Dropzone ───────────────────────────────────────────────
    function initDropzone() {
        const dz = $('dropzone');
        const fileInput = $('fileInput');
        if (!dz || !fileInput) return;

        dz.addEventListener('click', () => fileInput.click());
        dz.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') fileInput.click(); });
        dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('is-dragover'); });
        dz.addEventListener('dragleave', () => dz.classList.remove('is-dragover'));
        dz.addEventListener('drop', e => {
            e.preventDefault();
            dz.classList.remove('is-dragover');
            addFiles(Array.from(e.dataTransfer.files));
        });
        fileInput.addEventListener('change', () => {
            addFiles(Array.from(fileInput.files));
            fileInput.value = '';
        });
    }

    function formatBytes(b) {
        if (b < 1024) return b + ' B';
        if (b < 1048576) return (b / 1024).toFixed(1) + ' KB';
        return (b / 1048576).toFixed(1) + ' MB';
    }

    function addFiles(files) {
        const MAX_MB = (assignData && assignData.max_file_mb) || 50;
        const allowedExtRaw = String(assignData?.allowed_file_extensions || '').toLowerCase();
        const allowedExts = allowedExtRaw ? allowedExtRaw.split(',').map((v) => v.trim()).filter(Boolean) : [];
        files.forEach(f => {
            if (f.size > MAX_MB * 1024 * 1024) {
                LMS.toast(`${f.name} exceeds ${MAX_MB}MB limit.`, 'error');
                return;
            }
            if (allowedExts.length) {
                const parts = String(f.name || '').toLowerCase().split('.');
                const ext = parts.length > 1 ? parts.pop() : '';
                if (!ext || !allowedExts.includes(ext)) {
                    LMS.toast(`${f.name} is not an allowed file type (${allowedExts.join(', ')}).`, 'error');
                    return;
                }
            }
            uploadedFiles.push(f);
        });
        renderFileList();
    }

    function renderFileList() {
        const list = $('fileList');
        if (!list) return;
        list.innerHTML = uploadedFiles.map((f, i) => `
      <div class="k-file-item">
        <span class="k-file-item__name">${LMS.escHtml(f.name)}</span>
        <span class="k-file-item__size">${formatBytes(f.size)}</span>
        <button class="k-file-item__remove" data-idx="${i}" aria-label="Remove ${LMS.escHtml(f.name)}">×</button>
      </div>`).join('');
        list.querySelectorAll('.k-file-item__remove').forEach(btn => {
            btn.addEventListener('click', () => {
                uploadedFiles.splice(Number(btn.dataset.idx), 1);
                renderFileList();
            });
        });
    }

    function insertTextAtCursor(text) {
        const selection = document.getSelection();
        if (!selection || selection.rangeCount === 0) return;
        const range = selection.getRangeAt(0);
        range.deleteContents();
        range.insertNode(document.createTextNode(text));
        range.collapse(false);
        selection.removeAllRanges();
        selection.addRange(range);
    }

    function ensureAssignmentEditorModal() {
        let modal = $('assignEditorModal');
        if (modal) return modal;
        modal = document.createElement('dialog');
        modal.id = 'assignEditorModal';
        modal.className = 'k-modal';
        modal.innerHTML = `<form method="dialog" class="k-modal__content k-modal__content--lg" id="assignEditorForm">
            <h3>Edit assignment</h3>
            <label class="k-field-stack"><span>Title</span><input id="assignEditTitle" type="text" required /></label>
            <div class="k-inline-actions k-inline-actions--compact">
              <button type="button" class="btn btn-ghost btn-sm" data-assign-cmd="formatBlock" data-assign-cmd-value="<h2>">H2</button>
              <button type="button" class="btn btn-ghost btn-sm" data-assign-cmd="bold">Bold</button>
              <button type="button" class="btn btn-ghost btn-sm" data-assign-cmd="italic">Italic</button>
              <button type="button" class="btn btn-ghost btn-sm" data-assign-cmd="underline">Underline</button>
              <button type="button" class="btn btn-ghost btn-sm" data-assign-cmd="insertUnorderedList">List</button>
              <button type="button" class="btn btn-ghost btn-sm" id="assignCopyMarkdownBtn">Copy Markdown</button>
            </div>
            <label class="k-field-stack"><span>Description</span><div id="assignEditDescription" class="k-card k-editor-surface k-editor-surface--compact" contenteditable="true"></div></label>
            <label class="k-field-stack"><span>Due date/time</span><input id="assignEditDueAt" type="text" placeholder="YYYY-MM-DD HH:MM:SS" /></label>
            <label class="k-field-stack"><span>Max points</span><input id="assignEditMaxPoints" type="number" min="1" step="1" /></label>
            <label class="k-field-stack"><span>Allowed extensions (comma separated)</span><input id="assignEditAllowedExt" type="text" placeholder="pdf,docx,png" /></label>
            <label class="k-field-stack"><span>Max file size (MB)</span><input id="assignEditMaxFileMb" type="number" min="1" max="1024" step="1" /></label>
            <div class="k-modal__footer"><button class="btn btn-ghost" type="button" id="assignEditorCancel">Cancel</button><button class="btn btn-primary" type="submit">Save changes</button></div>
        </form>`;
        document.body.appendChild(modal);
        $('assignEditorCancel')?.addEventListener('click', () => modal.close());
        modal.querySelectorAll('[data-assign-cmd]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const editor = $('assignEditDescription');
                if (!editor) return;
                editor.focus();
                if (typeof document.execCommand === 'function') {
                    document.execCommand(btn.dataset.assignCmd, false, btn.dataset.assignCmdValue || null);
                    return;
                }
                const value = btn.dataset.assignCmdValue || '';
                if (value) {
                    insertTextAtCursor(value);
                }
            });
        });
        $('assignCopyMarkdownBtn')?.addEventListener('click', async () => {
            const editor = $('assignEditDescription');
            if (!editor) return;
            try {
                await navigator.clipboard.writeText(LMS.htmlToMarkdown(editor.innerHTML || ''));
                LMS.toast('Markdown copied to clipboard.', 'success');
            } catch (_) {
                LMS.toast('Unable to copy markdown.', 'error');
            }
        });
        $('assignEditDescription')?.addEventListener('paste', (event) => {
            const text = event.clipboardData?.getData('text/plain') || '';
            if (!text || !/[#*\-\[\]]/.test(text)) return;
            event.preventDefault();
            const html = LMS.markdownToHtml(text);
            if (typeof document.execCommand === 'function') {
                document.execCommand('insertHTML', false, html);
            } else {
                insertTextAtCursor(text);
            }
        });
        return modal;
    }

    function openAssignmentEditor(initial) {
        const modal = ensureAssignmentEditorModal();
        const form = $('assignEditorForm');
        const title = $('assignEditTitle');
        const description = $('assignEditDescription');
        const dueAt = $('assignEditDueAt');
        const maxPoints = $('assignEditMaxPoints');
        const allowedExt = $('assignEditAllowedExt');
        const maxFileMb = $('assignEditMaxFileMb');
        if (!form || !title || !description || !dueAt || !maxPoints || !allowedExt || !maxFileMb) {
            LMS.toast('Assignment editor failed to open. Please refresh and try again.', 'error');
            return Promise.resolve(null);
        }
        title.value = initial?.title || '';
        description.innerHTML = LMS.sanitizeForRender(initial?.instructions || initial?.description || '');
        dueAt.value = initial?.due_at || '';
        maxPoints.value = String(initial?.max_points || 100);
        allowedExt.value = initial?.allowed_file_extensions || '';
        maxFileMb.value = String(initial?.max_file_mb || 50);
        modal.showModal();
        return new Promise((resolve) => {
            const closeHandler = () => {
                form.removeEventListener('submit', submitHandler);
                modal.removeEventListener('close', closeHandler);
                resolve(null);
            };
            const submitHandler = (event) => {
                event.preventDefault();
                const points = Number.parseInt(maxPoints.value || '100', 10);
                const maxMb = Number.parseInt(maxFileMb.value || '50', 10);
                form.removeEventListener('submit', submitHandler);
                modal.removeEventListener('close', closeHandler);
                modal.close();
                resolve({
                    title: title.value.trim(),
                    instructions: (description.innerHTML || '').trim(),
                    due_at: dueAt.value.trim(),
                    max_points: Number.isFinite(points) && points > 0 ? points : 100,
                    allowed_file_extensions: allowedExt.value.trim().toLowerCase(),
                    max_file_mb: Number.isFinite(maxMb) && maxMb > 0 ? maxMb : 50,
                });
            };
            form.addEventListener('submit', submitHandler);
            modal.addEventListener('close', closeHandler);
            setTimeout(() => title.focus(), 0);
        });
    }

    // ── Rubric ─────────────────────────────────────────────────
    function renderRubric(rubric) {
        if (!rubric || !rubric.length) return;
        showEl('rubricCard');
        const tbody = $('rubricRows');
        const totalEl = $('rubricTotal');
        if (!tbody) return;
        let total = 0;
        tbody.innerHTML = rubric.map(r => {
            total += Number(r.max_pts) || 0;
            return `<tr>
        <td>${LMS.escHtml(r.criterion)}</td>
        <td>${LMS.escHtml(r.description || '')}</td>
        <td>${r.max_pts || 0}</td>
      </tr>`;
        }).join('');
        if (totalEl) totalEl.textContent = total;
    }

    // ── Submission history ─────────────────────────────────────
    function renderTimeline(submissions) {
        const tl = $('submissionTimeline');
        if (!tl) return;
        if (!submissions || !submissions.length) {
            tl.innerHTML = '<div class="k-empty k-card-empty--sm"><div class="k-empty__icon">📋</div><p class="k-empty__title">No submissions yet</p></div>';
            return;
        }
        tl.innerHTML = submissions.map((s, i) => {
            const cls = s.grade !== undefined ? 'k-timeline-item--graded' : i === 0 ? 'k-timeline-item--current' : '';
            return `<div class="k-timeline-item ${cls}">
        <div class="k-timeline-item__version">Submission ${submissions.length - i}</div>
        <div class="k-timeline-item__title">${LMS.escHtml(s.label || s.file_name || 'Submitted')}</div>
        <div class="k-timeline-item__meta">${LMS.fmtDateTime(s.submitted_at)}${s.grade !== undefined ? ` · Grade: ${s.grade}` : ''}${s.submission_comment ? ` · Comment: ${LMS.escHtml(s.submission_comment)}` : ''}</div>
      </div>`;
        }).join('');
    }


    async function renderStaffPanel(submissions) {
        if (!canManage) return;
        const root = $('assignLoaded');
        if (!root) return;
        const existingPanel = $('assignStaffPanel');
        if (existingPanel) existingPanel.remove();
        const panel = document.createElement('section');
        panel.id = 'assignStaffPanel';
        panel.className = 'k-card k-staff-panel';
        panel.innerHTML = `<h3 class="k-staff-panel__title">Staff Assignment Management</h3><div class="k-staff-panel__actions"><button class="btn btn-ghost btn-sm" id="assignPublishBtn" type="button">Publish</button><button class="btn btn-ghost btn-sm" id="assignDraftBtn" type="button">Move to Draft</button><button class="btn btn-ghost btn-sm" id="assignMandatoryBtn" type="button"></button><button class="btn btn-secondary btn-sm" id="assignEditBtn" type="button">Edit Assignment</button></div><div id="assignStaffSubmissions" class="k-staff-panel__list"></div>`;
        root.appendChild(panel);

        const assignMandatoryBtn = $('assignMandatoryBtn');
        if (assignMandatoryBtn) {
            const requiredNow = Number(assignData?.required_flag || 0) === 1;
            assignMandatoryBtn.textContent = requiredNow ? 'Set Optional' : 'Set Mandatory';
        }

        $('assignPublishBtn')?.addEventListener('click', async () => {
            const res = await LMS.api('POST', './api/lms/assignments/publish.php', { assignment_id: Number(ASSIGN_ID), published: 1 });
            LMS.toast(res.ok ? 'Assignment published' : 'Publish failed', res.ok ? 'success' : 'error');
            if (res.ok) await loadPage();
        });
        $('assignDraftBtn')?.addEventListener('click', async () => {
            const res = await LMS.api('POST', './api/lms/assignments/publish.php', { assignment_id: Number(ASSIGN_ID), published: 0 });
            LMS.toast(res.ok ? 'Assignment moved to draft' : 'Update failed', res.ok ? 'success' : 'error');
            if (res.ok) await loadPage();
        });
        $('assignMandatoryBtn')?.addEventListener('click', async () => {
            const currentRequired = Number(assignData?.required_flag || 0) === 1;
            const newRequired = currentRequired ? 0 : 1;
            LMS.confirm(
                newRequired ? 'Set as mandatory?' : 'Unset mandatory?',
                newRequired ? 'Students will be required to submit this assignment.' : 'Students will no longer be required to submit this assignment.',
                async () => {
                    const res = await LMS.api('POST', './api/lms/assignments/mandatory.php', {
                        assignment_id: Number(ASSIGN_ID),
                        required: newRequired,
                    });
                    LMS.toast(
                        res.ok
                            ? (newRequired ? 'Assignment marked as mandatory' : 'Assignment marked as optional')
                            : 'Mandatory update failed',
                        res.ok ? 'success' : 'error'
                    );
                    if (res.ok) {
                        assignData = { ...(assignData || {}), required_flag: newRequired };
                        if (assignMandatoryBtn) assignMandatoryBtn.textContent = newRequired ? 'Set Optional' : 'Set Mandatory';
                        await loadPage();
                    }
                },
                { okLabel: newRequired ? 'Set mandatory' : 'Set optional', okClass: 'btn-primary' }
            );
        });
        $('assignEditBtn')?.addEventListener('click', async () => {
            const updatePayload = await openAssignmentEditor(assignData);
            if (!updatePayload || !updatePayload.title) return;
            const res = await LMS.api('POST', './api/lms/assignments/update.php', {
                assignment_id: Number(ASSIGN_ID),
                title: updatePayload.title,
                instructions: updatePayload.instructions,
                due_at: updatePayload.due_at,
                max_points: updatePayload.max_points,
                allowed_file_extensions: updatePayload.allowed_file_extensions,
                max_file_mb: updatePayload.max_file_mb,
            });
LMS.toast(res.ok ? 'Assignment updated' : `Update failed: ${res.error || 'Unknown error'}`, res.ok ? 'success' : 'error');
            if (res.ok) await loadPage();
        });

        const target = $('assignStaffSubmissions');
        if (target) {
            target.innerHTML = `<h4>Submissions (${submissions.length})</h4>` + submissions.map((s) => `<div class="k-attempt-row">Submission #${s.submission_id} · student ${s.student_user_id} · ${LMS.fmtDateTime(s.submitted_at)} · grade ${s.grade ?? '-'}</div>`).join('');
        }
    }

    // ── Submit ─────────────────────────────────────────────────
    async function submitWork() {
        const btn = $('submitBtn');
        if (btn) { btn.disabled = true; btn.textContent = 'Submitting…'; }

        try {
            const submType = assignData.submission_type || 'file';
            const formData = new FormData();
            formData.append('assignment_id', ASSIGN_ID);
            formData.append('course_id', COURSE_ID);

            if (submType === 'file') {
                if (!uploadedFiles.length) {
                    LMS.toast('Please attach at least one file.', 'warning');
                    return;
                }
            } else if (submType === 'text') {
                const ta = $('textInput');
                if (!ta || !ta.value.trim()) { LMS.toast('Please enter your answer.', 'warning'); return; }
            } else if (submType === 'url') {
                const inp = $('urlInput');
                if (!inp || !inp.value.trim()) { LMS.toast('Please enter a URL.', 'warning'); return; }
                formData.append('url', inp.value.trim());
            }

            const commentInput = $('submissionCommentInput');
            const submissionComment = (commentInput?.value || '').trim();
            if (submissionComment) {
                formData.append('submission_comment', submissionComment);
            }

            if (submType === 'file' && uploadedFiles[0]) {
                formData.append('file', uploadedFiles[0]);
            }
            if (submType === 'text') {
                const ta = $('textInput');
                formData.append('text_submission', (ta?.value || '').trim());
            }

            const endpoint = './api/lms/assignments/submit.php';
            const res = await LMS.api('POST', endpoint, formData);
            logDebug({ endpoint, method: 'POST', response_status: res.status, response_body: res.data, parsed_error_message: res.error || null });
            if (!res.ok) {
                LMS.toast('Submission failed: ' + (res.error || 'Unknown error'), 'error');
                return;
            }
            LMS.toast('Submitted successfully!', 'success');
            // Refresh page state
            await loadPage();
        } finally {
            if (btn) { btn.disabled = false; btn.textContent = 'Submit'; }
        }
    }

    // ── Main load ──────────────────────────────────────────────
    async function loadPage() {
        if (!ASSIGN_ID) {
            LMS.renderAccessDenied($('assignAccessDenied'), 'No assignment specified. Please select an assignment from the Modules page.', COURSE_ID ? `./modules.html?course_id=${encodeURIComponent(COURSE_ID)}` : '/signoff/');
            hideEl('assignSkeleton');
            showEl('assignAccessDenied');
            return;
        }

        const dbg = DEBUG_MODE ? '&debug=1' : '';
        const assignEndpoint = `./api/lms/assignments/get.php?assignment_id=${encodeURIComponent(ASSIGN_ID)}&course_id=${encodeURIComponent(COURSE_ID)}${dbg}`;
        const subsEndpoint = `./api/lms/assignments/submissions.php?assignment_id=${encodeURIComponent(ASSIGN_ID)}&course_id=${encodeURIComponent(COURSE_ID)}${dbg}`;
        const [assignRes, subsRes] = await Promise.all([
            LMS.api('GET', assignEndpoint),
            LMS.api('GET', subsEndpoint),
        ]);
        logDebug({ endpoint: assignEndpoint, method: 'GET', response_status: assignRes.status, response_body: assignRes.data, parsed_error_message: assignRes.error || null });
        logDebug({ endpoint: subsEndpoint, method: 'GET', response_status: subsRes.status, response_body: subsRes.data, parsed_error_message: subsRes.error || null });

        hideEl('assignSkeleton');

        if (assignRes.status === 403) {
            LMS.renderAccessDenied($('assignAccessDenied'), 'You do not have access to this assignment.', `/course.html?course_id=${COURSE_ID}`);
            showEl('assignAccessDenied');
            return;
        }
        if (!assignRes.ok) {
            console.error('Failed to load assignment', assignRes);
            LMS.toast(assignRes.error || 'Failed to load assignment', 'error');
            showEl('assignError');
            $('assignRetryBtn') && $('assignRetryBtn').addEventListener('click', loadPage, { once: true });
            return;
        }

        assignData = assignRes.data?.data || assignRes.data || {};
        const submissions = subsRes.ok ? (subsRes.data?.data?.items || subsRes.data?.data || subsRes.data?.items || []) : [];
        const latestSub = submissions[0] || null;

        document.title = `${assignData.title || 'Assignment'} — Kairos`;
        $('kBreadAssign') && ($('kBreadAssign').textContent = assignData.title || 'Assignment');
        const bc = $('kBreadCourse');
        if (bc) {
            bc.href = `./course.html?course_id=${encodeURIComponent(COURSE_ID)}`;
            bc.textContent = assignData.course_name || 'Course';
        }
        document.querySelectorAll('[data-course-href]').forEach(el => {
            el.href = `${el.dataset.courseHref}?course_id=${encodeURIComponent(COURSE_ID)}`;
        });
        LMS.nav.setCourseContext(COURSE_ID, assignData.course_name || 'Course');
        LMS.nav.setActive('assignments');
        const courseRole = resolveCourseRoleFlags(assignData.course_role || assignData.my_role || assignData.role || window.KairosLMS.getRole());
        if (courseRole.ta || courseRole.manager || courseRole.admin) {
            $('kNavGrading')?.classList.remove('hidden');
        }
        if (courseRole.manager || courseRole.admin) {
            $('kNavAnalytics')?.classList.remove('hidden');
        }
        $('kSidebarCourseName') && ($('kSidebarCourseName').textContent = assignData.course_name || '');

        $('assignTitle') && ($('assignTitle').textContent = assignData.title || '');

        // Deadline
        const deadlineEl = $('assignDeadline');
        if (deadlineEl && assignData.due_at) {
            const isPast = new Date(assignData.due_at) < new Date();
            deadlineEl.innerHTML = `<span aria-hidden="true">📅</span> Due: ${LMS.fmtDateTime(assignData.due_at)}`;
            if (isPast) deadlineEl.classList.add('k-assign-deadline--late');
        }

        // Status
        const statusEl = $('assignStatus');
        if (statusEl) {
            if (latestSub) {
                statusEl.textContent = 'Submitted';
                statusEl.className = 'k-status k-status--success';
            } else {
                statusEl.textContent = 'Not Submitted';
                statusEl.className = 'k-status k-status--neutral';
            }
        }

        // Grade status
        if (latestSub && latestSub.grade !== undefined) {
            const gs = $('assignGradeStatus');
            if (gs) {
                gs.textContent = `Grade: ${latestSub.grade} / ${assignData.max_points || '?'}`;
                gs.className = 'k-status k-status--info';
                gs.classList.remove('hidden');
            }
            // Show feedback card
            const fc = $('gradeFeedbackCard');
            if (fc) {
                fc.classList.remove('hidden');
                $('gradeScoreDisplay') && ($('gradeScoreDisplay').textContent = `${latestSub.grade}/${assignData.max_points || '?'}`);
                $('gradeFeedbackText') && ($('gradeFeedbackText').textContent = latestSub.feedback || '—');
            }
        }

        // Description
        const desc = $('assignDescription');
        if (desc) {
            const description = assignData.description || assignData.instructions || '';
            if (description) {
                desc.innerHTML = LMS.sanitizeForRender(description);
            } else {
                desc.innerHTML = '<div class="k-empty"><p class="k-empty__desc">No description provided.</p></div>';
            }
        }

        // Rubric
        renderRubric(assignData.rubric);

        // Submission panel
        const submType = assignData.submission_type || 'file';
        ['fileSubmission', 'textSubmission', 'urlSubmission'].forEach(hideEl);

        if (latestSub) {
            // Already submitted
            showEl('submittedState');
            hideEl('submissionActions');
            $('submissionPanelTitle') && ($('submissionPanelTitle').textContent = 'Submission Status');
            $('resubmitBtn') && $('resubmitBtn').addEventListener('click', () => {
                hideEl('submittedState');
                showEl('submissionActions');
                showEl(submType + 'Submission');
                initDropzone();
            });
        } else {
            showEl(submType + 'Submission');
            const allowedTypesHint = assignData.allowed_file_extensions ? assignData.allowed_file_extensions.split(',').map((v) => v.trim()).filter(Boolean).join(', ') : 'Any file type';
            $('dropzoneHint') && ($('dropzoneHint').textContent = `${allowedTypesHint} · Max ${assignData.max_file_mb || 50}MB per file`);
            initDropzone();
            $('submitBtn') && $('submitBtn').addEventListener('click', submitWork);
        }

        // Submission count note
        if (assignData.max_attempts) {
            const note = $('submissionNote');
            if (note) note.textContent = `${submissions.length} of ${assignData.max_attempts} attempt(s) used`;
        }

        renderTimeline(submissions);
        showEl('assignLoaded');
        await renderStaffPanel(submissions);
    }

    document.addEventListener('DOMContentLoaded', async () => {
        const session = await LMS.boot();
        if (!session) return;
        LMS.nav.updateUserBar(session.me);
        const roles = session.caps?.roles || {};
        canManage = !!(roles.admin || roles.manager);

        // Enforce RBAC: students cannot access edit mode
        if (URL_MODE === 'edit' && !canManage) {
            const denied = $('assignAccessDenied');
            if (denied) {
                LMS.renderAccessDenied(denied, 'You do not have permission to edit this assignment.', `./modules.html?course_id=${encodeURIComponent(COURSE_ID)}`);
                denied.classList.remove('hidden');
            }

            hideEl('assignSkeleton');
            hideEl('assignLoaded');
            hideEl('assignError');

            document.querySelectorAll('#assignLoaded button, #assignLoaded input, #assignLoaded select, #assignLoaded textarea').forEach((el) => {
                el.disabled = true;
            });
            return;
        }

        await loadPage();
    });

})();
