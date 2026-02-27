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

    function showEl(id) { const el = $(id); if (el) el.classList.remove('hidden'); }
    function hideEl(id) { const el = $(id); if (el) el.classList.add('hidden'); }

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
            debugEl.className = 'k-card';
            debugEl.style.cssText = 'padding:12px;white-space:pre-wrap;margin-top:12px;';
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
        files.forEach(f => {
            if (f.size > MAX_MB * 1024 * 1024) {
                LMS.toast(`${f.name} exceeds ${MAX_MB}MB limit.`, 'error');
                return;
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


    function ensureAssignmentEditorModal() {
        let modal = $('assignEditorModal');
        if (modal) return modal;
        modal = document.createElement('dialog');
        modal.id = 'assignEditorModal';
        modal.className = 'k-modal';
        modal.innerHTML = `<form method="dialog" class="k-modal__content" id="assignEditorForm" style="max-width:640px">
            <h3 style="margin:0 0 12px">Edit assignment</h3>
            <label class="k-field" style="display:grid;gap:6px;margin-bottom:10px"><span>Title</span><input id="assignEditTitle" type="text" required /></label>
            <label class="k-field" style="display:grid;gap:6px;margin-bottom:10px"><span>Description</span><textarea id="assignEditDescription" rows="5"></textarea></label>
            <label class="k-field" style="display:grid;gap:6px;margin-bottom:10px"><span>Due date/time</span><input id="assignEditDueAt" type="text" placeholder="YYYY-MM-DD HH:MM:SS" /></label>
            <label class="k-field" style="display:grid;gap:6px;margin-bottom:12px"><span>Max points</span><input id="assignEditMaxPoints" type="number" min="1" step="1" /></label>
            <div class="k-modal__footer" style="display:flex;justify-content:flex-end;gap:8px"><button class="btn btn-ghost" type="button" id="assignEditorCancel">Cancel</button><button class="btn btn-primary" type="submit">Save changes</button></div>
        </form>`;
        document.body.appendChild(modal);
        $('assignEditorCancel')?.addEventListener('click', () => modal.close());
        return modal;
    }

    function openAssignmentEditor(initial) {
        const modal = ensureAssignmentEditorModal();
        const form = $('assignEditorForm');
        const title = $('assignEditTitle');
        const description = $('assignEditDescription');
        const dueAt = $('assignEditDueAt');
        const maxPoints = $('assignEditMaxPoints');
        if (!form || !title || !description || !dueAt || !maxPoints) return Promise.resolve(null);
        title.value = initial?.title || '';
        description.value = initial?.instructions || initial?.description || '';
        dueAt.value = initial?.due_at || '';
        maxPoints.value = String(initial?.max_points || 100);
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
                form.removeEventListener('submit', submitHandler);
                modal.removeEventListener('close', closeHandler);
                modal.close();
                resolve({
                    title: title.value.trim(),
                    instructions: description.value.trim(),
                    due_at: dueAt.value.trim(),
                    max_points: Number.isFinite(points) && points > 0 ? points : 100,
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
            tl.innerHTML = '<div class="k-empty" style="padding:16px 0"><div class="k-empty__icon">📋</div><p class="k-empty__title">No submissions yet</p></div>';
            return;
        }
        tl.innerHTML = submissions.map((s, i) => {
            const cls = s.grade !== undefined ? 'k-timeline-item--graded' : i === 0 ? 'k-timeline-item--current' : '';
            return `<div class="k-timeline-item ${cls}">
        <div class="k-timeline-item__version">Submission ${submissions.length - i}</div>
        <div class="k-timeline-item__title">${LMS.escHtml(s.label || s.file_name || 'Submitted')}</div>
        <div class="k-timeline-item__meta">${LMS.fmtDateTime(s.submitted_at)}${s.grade !== undefined ? ` · Grade: ${s.grade}` : ''}</div>
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
        panel.className = 'k-card';
        panel.style.marginTop = '16px';
        panel.style.padding = '16px';
        panel.innerHTML = `<h3>Staff Assignment Management</h3><div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px"><button class="btn btn-ghost btn-sm" id="assignPublishBtn" type="button">Publish</button><button class="btn btn-ghost btn-sm" id="assignDraftBtn" type="button">Move to Draft</button><button class="btn btn-ghost btn-sm" id="assignMandatoryBtn" type="button">Toggle Mandatory</button><button class="btn btn-secondary btn-sm" id="assignEditBtn" type="button">Edit Assignment</button></div><div id="assignStaffSubmissions"></div>`;
        root.appendChild(panel);

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
            LMS.confirm('Set as mandatory?', 'Students will be required to submit this assignment.', async () => {
                const res = await LMS.api('POST', './api/lms/assignments/mandatory.php', { assignment_id: Number(ASSIGN_ID), required: 1 });
                LMS.toast(res.ok ? 'Mandatory flag updated' : 'Mandatory update failed', res.ok ? 'success' : 'error');
                if (res.ok) await loadPage();
            }, { okLabel: 'Set mandatory', okClass: 'btn-primary' });
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
            });
            LMS.toast(res.ok ? 'Assignment updated' : 'Update failed', res.ok ? 'success' : 'error');
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
                desc.innerHTML = description; // server MUST sanitize
            } else {
                desc.innerHTML = '<div class="k-empty" style="padding:0"><p class="k-empty__desc">No description provided.</p></div>';
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
            $('dropzoneHint') && ($('dropzoneHint').textContent = `${assignData.allowed_file_types || 'Any file type'} · Max ${assignData.max_file_mb || 50}MB per file`);
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
        await loadPage();
    });

})();
