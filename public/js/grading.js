/**
 * grading.js — Grading workspace controller (TA + Manager)
 * TAs only see assigned submissions; managers see all.
 * Keyboard shortcuts: S=save draft, R=release, J=prev, K=next
 */
(function () {
    'use strict';

    const $ = id => document.getElementById(id);
    const LMS = window.KairosLMS;
    const params = new URLSearchParams(location.search);
    const COURSE_ID = params.get('course_id') || '';

    let submissions = [];
    let activeIdx = -1;
    let rubricRows = [];
    let gradingRole = 'ta'; // 'ta' | 'manager'
    let visibleSubmissionIds = [];

    function showEl(id) { const el = $(id); if (el) el.classList.remove('hidden'); }
    function hideEl(id) { const el = $(id); if (el) el.classList.add('hidden'); }

    // ── Submission queue rendering ─────────────────────────────
    function renderQueue(list) {
        const container = $('submissionQueue');
        if (!container) return;
        if (!list.length) {
            container.innerHTML = `<div class="k-empty" style="padding:40px 16px"><div class="k-empty__icon">📋</div><p class="k-empty__title">No submissions yet</p></div>`;
            visibleSubmissionIds = [];
            activeIdx = -1;
            return;
        }
        visibleSubmissionIds = list
            .map((item) => Number(item?.id))
            .filter((id) => Number.isFinite(id));
        container.innerHTML = list.map((s) => {
            const initials = (s.student_name || 'U').split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase();
            const statusCls = s.grade_status === 'released' ? 'k-status--success' : s.grade_status === 'draft' ? 'k-status--warning' : 'k-status--neutral';
            const statusLabel = s.grade_status === 'released' ? 'Released' : s.grade_status === 'draft' ? 'Draft' : 'Ungraded';
            return `<div class="k-queue-item" data-submission-id="${Number(s.id)}" role="listitem" tabindex="0" aria-label="Submission by ${LMS.escHtml(s.student_name)}">
        <div class="k-queue-item__avatar">${initials}</div>
        <div class="k-queue-item__info">
          <div class="k-queue-item__name">${LMS.escHtml(s.student_name || 'Unknown')}</div>
          <div class="k-queue-item__date">${LMS.fmtDateTime(s.submitted_at)}</div>
        </div>
        <span class="k-status ${statusCls}" style="font-size:11px">${statusLabel}</span>
      </div>`;
        }).join('');

        $('queueCount') && ($('queueCount').textContent = list.length);

        container.querySelectorAll('.k-queue-item').forEach(item => {
            const activate = () => {
                const submissionId = Number(item.dataset.submissionId || 0);
                const idx = submissions.findIndex((entry) => Number(entry.id) === submissionId);
                if (idx >= 0) selectSubmission(idx);
            };
            item.addEventListener('click', activate);
            item.addEventListener('keydown', (e) => {
                if (e.key === ' ') {
                    e.preventDefault();
                    activate();
                    return;
                }
                if (e.key === 'Enter') activate();
            });
        });
    }

    function filterQueue() {
        const statusVal = $('filterStatus') && $('filterStatus').value;
        const search = ($('filterSearch') && $('filterSearch').value.toLowerCase()) || '';
        let filtered = submissions;
        if (statusVal) filtered = filtered.filter(s => s.grade_status === statusVal || (!s.grade_status && statusVal === 'ungraded'));
        if (search) filtered = filtered.filter(s => (s.student_name || '').toLowerCase().includes(search));
        renderQueue(filtered);
    }

    // ── Rubric form ────────────────────────────────────────────
    function renderRubricForm(rubric, existingGrades) {
        const container = $('rubricRows');
        if (!container) return;
        rubricRows = rubric || [];
        if (!rubricRows.length) {
            container.innerHTML = '<p class="k-text-muted k-text-sm">No rubric defined for this assignment.</p>';
            return;
        }
        let totalMax = 0;
        container.innerHTML = rubricRows.map(r => {
            totalMax += Number(r.max_pts) || 0;
            const saved = existingGrades && existingGrades[r.id];
            return `<div class="k-rubric-row">
        <span class="k-rubric-row__criterion">${LMS.escHtml(r.criterion)}</span>
        <span class="k-rubric-row__max">/ ${r.max_pts}</span>
        <input class="k-rubric-row__input" type="number" min="0" max="${r.max_pts}" step="0.5"
               data-criterion-id="${LMS.escHtml(String(r.id))}"
               value="${saved !== undefined ? saved : ''}"
               placeholder="—" aria-label="Score for ${LMS.escHtml(r.criterion)}" />
      </div>`;
        }).join('');
        $('totalMax') && ($('totalMax').textContent = ` / ${totalMax}`);
        // Update total on input
        container.querySelectorAll('.k-rubric-row__input').forEach(inp => {
            inp.addEventListener('input', recalcTotal);
        });
        recalcTotal();
    }

    function recalcTotal() {
        const inputs = document.querySelectorAll('.k-rubric-row__input');
        let sum = 0;
        inputs.forEach(inp => { sum += Number(inp.value) || 0; });
        $('totalScore') && ($('totalScore').textContent = sum.toFixed(1).replace(/\.0$/, ''));
    }

    function collectGrades() {
        const grades = {};
        document.querySelectorAll('.k-rubric-row__input').forEach(inp => {
            grades[inp.dataset.criterionId] = Number(inp.value) || 0;
        });
        return grades;
    }

    // ── Select submission ──────────────────────────────────────
    async function selectSubmission(idx) {
        if (idx < 0 || idx >= submissions.length) return;
        activeIdx = idx;
        const sub = submissions[idx];

        // Update queue highlight
        const activeSubmissionId = Number(sub.id);
        document.querySelectorAll('.k-queue-item').forEach((el) => {
            el.classList.toggle('is-active', Number(el.dataset.submissionId || 0) === activeSubmissionId);
        });

        // Update workspace header
        hideEl('workspaceEmpty');
        showEl('workspaceActive');
        $('workspaceStudentName') && ($('workspaceStudentName').textContent = sub.student_name || 'Unknown');
        const visibleIdx = visibleSubmissionIds.indexOf(Number(sub.id));
        const visibleTotal = visibleSubmissionIds.length || submissions.length;
        $('submissionNavPos') && ($('submissionNavPos').textContent = `${visibleIdx >= 0 ? visibleIdx + 1 : idx + 1} / ${visibleTotal}`);
        const prevVisibleId = visibleIdx > 0 ? visibleSubmissionIds[visibleIdx - 1] : null;
        const nextVisibleId = visibleIdx >= 0 && visibleIdx < visibleSubmissionIds.length - 1 ? visibleSubmissionIds[visibleIdx + 1] : null;
        $('prevSubmissionBtn') && ($('prevSubmissionBtn').disabled = prevVisibleId === null);
        $('nextSubmissionBtn') && ($('nextSubmissionBtn').disabled = nextVisibleId === null);

        // Grade state indicator
        const stateEl = $('workspaceGradeState');
        const stateLabel = $('workspaceGradeStateLabel');
        if (stateEl && stateLabel) {
            const released = sub.grade_status === 'released';
            stateEl.className = `k-grade-state ${released ? 'k-grade-state--released' : 'k-grade-state--draft'}`;
            stateLabel.textContent = released ? 'Released' : 'Draft';
        }

        // Show/hide override field (manager only)
        const overrideField = $('overrideField');
        if (overrideField) overrideField.classList.toggle('hidden', gradingRole !== 'manager');

        // Restore saved values
        $('feedbackText') && ($('feedbackText').value = sub.feedback || '');
        $('privateNote') && ($('privateNote').value = sub.private_note || '');
        if (sub.grade_override !== undefined && $('gradeOverride')) {
            $('gradeOverride').value = sub.grade_override;
        }

        // Fetch full submission detail (lazy load)
        const res = await LMS.api('GET', `./api/lms/submission.php?id=${encodeURIComponent(sub.id)}`);
        const detail = res.ok ? (res.data?.data || res.data || sub) : sub;

        // Render submission view
        ['submissionFileView', 'submissionTextView', 'submissionUrlView', 'submissionAttachments'].forEach(hideEl);
        if (detail.type === 'file' && detail.file_url) {
            $('submissionPDFFrame') && ($('submissionPDFFrame').src = detail.file_url);
            showEl('submissionFileView');
        } else if (detail.type === 'text') {
            $('submissionTextContent') && ($('submissionTextContent').textContent = detail.text_content || '');
            showEl('submissionTextView');
        } else if (detail.type === 'url') {
            const urlLink = $('submissionUrlLink');
            if (urlLink) { urlLink.href = detail.submission_url; urlLink.textContent = detail.submission_url; }
            showEl('submissionUrlView');
        }
        // Attachments
        if (detail.attachments && detail.attachments.length) {
            const al = $('attachmentList');
            if (al) {
                al.innerHTML = detail.attachments.map(a => `
          <div class="k-file-item">
            <span class="k-file-item__name">${LMS.escHtml(a.name)}</span>
            <a href="${LMS.escHtml(a.url)}" download class="btn btn-ghost btn-sm">↓</a>
          </div>`).join('');
                showEl('submissionAttachments');
            }
        }

        // Rubric
        renderRubricForm(detail.rubric || [], detail.grades || {});
    }

    // ── Save + Release ─────────────────────────────────────────

    function selectVisibleStep(step) {
        if (activeIdx < 0 || !visibleSubmissionIds.length) return;
        const activeId = Number(submissions[activeIdx]?.id || 0);
        const visibleIdx = visibleSubmissionIds.indexOf(activeId);
        if (visibleIdx < 0) return;
        const targetVisibleIdx = visibleIdx + step;
        if (targetVisibleIdx < 0 || targetVisibleIdx >= visibleSubmissionIds.length) return;
        const targetId = visibleSubmissionIds[targetVisibleIdx];
        const targetIdx = submissions.findIndex((entry) => Number(entry.id) === Number(targetId));
        if (targetIdx >= 0) {
            selectSubmission(targetIdx);
        }
    }

    async function saveGrade(release) {
        const sub = submissions[activeIdx];
        if (!sub) return;
        const grades = collectGrades();

        // Compute total score from rubric inputs (same as recalcTotal display)
        let score = 0;
        document.querySelectorAll('.k-rubric-row__input').forEach(inp => {
            score += Number(inp.value) || 0;
        });
        // Read max_score from display (set by renderRubricForm)
        const maxEl = $('totalMax');
        const maxText = maxEl ? maxEl.textContent.replace(/[^0-9.]/g, '') : '';
        const max_score = Number(maxText) || 100;

        const payload = {
            submission_id: sub.id,
            score,
            max_score,
            grades,
            feedback: ($('feedbackText') && $('feedbackText').value) || '',
            private_note: ($('privateNote') && $('privateNote').value) || '',
            override: ($('gradeOverride') && $('gradeOverride').value) || null,
            release,
        };
        const res = await LMS.api('POST', './api/lms/grade_submission.php', payload);
        if (!res.ok) {
            LMS.toast('Failed to save: ' + (res.error || 'Error'), 'error');
            return;
        }
        sub.grade_status = release ? 'released' : 'draft';
        sub.feedback = payload.feedback;
        sub.private_note = payload.private_note;
        LMS.toast(release ? 'Grade released to student' : 'Draft saved', release ? 'success' : 'info');
        filterQueue();
        // Re-render active state indicator
        const stateEl = $('workspaceGradeState');
        const stateLabel = $('workspaceGradeStateLabel');
        if (stateEl && stateLabel) {
            stateEl.className = `k-grade-state ${release ? 'k-grade-state--released' : 'k-grade-state--draft'}`;
            stateLabel.textContent = release ? 'Released' : 'Draft';
        }
    }

    async function releaseAll() {
        LMS.confirm('Release All Grades?', 'This will release all draft grades to students. This cannot be undone.', async () => {
            const res = await LMS.api('POST', './api/lms/grade_release_all.php', { course_id: COURSE_ID });
            if (!res.ok) { LMS.toast('Failed: ' + res.error, 'error'); return; }
            LMS.toast('All grades released!', 'success');
            await loadSubmissions();
        }, { okLabel: 'Release All', okClass: 'btn-primary' });
    }

    // ── Load submissions ───────────────────────────────────────
    async function loadSubmissions(assignmentId) {
        const aid = assignmentId || ($('assignmentSelector') && $('assignmentSelector').value) || '';
        if (!aid) return;
        const res = await LMS.api('GET', `./api/lms/grading_queue.php?assignment_id=${encodeURIComponent(aid)}&course_id=${encodeURIComponent(COURSE_ID)}`);
        if (!res.ok) { LMS.toast('Failed to load submissions.', 'error'); return; }
        submissions = res.ok ? (Array.isArray(res.data?.data) ? res.data.data : (Array.isArray(res.data) ? res.data : [])) : [];
        filterQueue();
        hideEl('workspaceEmpty');
        if (submissions.length === 0) {
            showEl('workspaceEmpty');
            activeIdx = -1;
            return;
        }
        if (visibleSubmissionIds.length === 0) {
            showEl('workspaceEmpty');
            activeIdx = -1;
            return;
        }

        const firstVisibleId = visibleSubmissionIds[0];
        const firstIdx = submissions.findIndex((entry) => Number(entry.id) === Number(firstVisibleId));
        if (firstIdx >= 0) {
            await selectSubmission(firstIdx);
        }
    }

    // ── Main load ──────────────────────────────────────────────
    async function loadPage() {
        if (!COURSE_ID) {
            LMS.renderAccessDenied($('gradingAccessDenied').querySelector('div'), 'No course specified.', '/signoff/');
            showEl('gradingAccessDenied'); hideEl('gradingSkeleton'); return;
        }

        const [capsRes, courseRes, assignRes] = await Promise.all([
            LMS.loadCaps(),
            LMS.api('GET', `./api/lms/courses.php?course_id=${encodeURIComponent(COURSE_ID)}`),
            LMS.api('GET', `./api/lms/assignments.php?course_id=${encodeURIComponent(COURSE_ID)}`),
        ]);

        const caps = capsRes || {};
        const isTA = caps.roles && (caps.roles.ta || caps.roles.manager || caps.roles.admin);
        if (!isTA) {
            LMS.renderAccessDenied(
                $('gradingAccessDenied').querySelector('.k-page'),
                'Grading is only accessible to TAs, Managers, and Admins.',
                `/course.html?course_id=${COURSE_ID}`
            );
            showEl('gradingAccessDenied'); hideEl('gradingSkeleton'); return;
        }

        gradingRole = (caps.roles && (caps.roles.manager || caps.roles.admin)) ? 'manager' : 'ta';
        const course = courseRes.ok ? (courseRes.data?.data || courseRes.data) : null;
        if (course) {
            $('kSidebarCourseName') && ($('kSidebarCourseName').textContent = course.code || course.name);
            const bc = $('kBreadCourse');
            if (bc) {
                bc.href = `./course.html?course_id=${encodeURIComponent(COURSE_ID)}`;
                bc.textContent = course.name || 'Course';
            }
            document.querySelectorAll('[data-course-href]').forEach(el => {
                el.href = `${el.dataset.courseHref}?course_id=${encodeURIComponent(COURSE_ID)}`;
            });
            LMS.nav.setCourseContext(COURSE_ID, course.name || course.code || 'Course');
            LMS.nav.setActive('grading');
            const role = String(course.my_role || '').toLowerCase();
            if (role === 'manager' || role === 'admin') { $('kNavAnalytics') && $('kNavAnalytics').classList.remove('hidden'); }
        }

        // Assignment selector (manager sees all; TA sees assigned only)
        const assignPayload = assignRes.ok ? (assignRes.data?.data || assignRes.data || []) : [];
        const assignments = Array.isArray(assignPayload) ? assignPayload : (assignPayload.items || []);
        const sel = $('assignmentSelector');
        if (sel && assignments.length) {
            sel.style.display = '';
            assignments.forEach(a => {
                const opt = document.createElement('option');
                opt.value = a.id;
                opt.textContent = a.title;
                sel.appendChild(opt);
            });
            sel.addEventListener('change', () => loadSubmissions(sel.value));
            // Auto-load first assignment
            if (assignments[0]) {
                sel.value = assignments[0].id;
                await loadSubmissions(assignments[0].id);
            }
        }

        // Release all (manager only)
        if (gradingRole === 'manager') {
            const releaseBtn = $('releaseAllBtn');
            if (releaseBtn) { releaseBtn.classList.remove('hidden'); releaseBtn.addEventListener('click', releaseAll); }
        }

        // Filters
        $('filterStatus') && $('filterStatus').addEventListener('change', filterQueue);
        let searchTimer;
        $('filterSearch') && $('filterSearch').addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(filterQueue, 250); });

        // Grading actions
        $('saveDraftBtn') && $('saveDraftBtn').addEventListener('click', () => saveGrade(false));
        $('releaseGradeBtn') && $('releaseGradeBtn').addEventListener('click', () => saveGrade(true));

        // Bulk nav
        $('prevSubmissionBtn') && $('prevSubmissionBtn').addEventListener('click', () => selectVisibleStep(-1));
        $('nextSubmissionBtn') && $('nextSubmissionBtn').addEventListener('click', () => selectVisibleStep(1));

        // Keyboard shortcuts
        document.addEventListener('keydown', e => {
            if (['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName)) return;
            if (e.key === 's' || e.key === 'S') { e.preventDefault(); saveGrade(false); }
            if (e.key === 'r' || e.key === 'R') { e.preventDefault(); saveGrade(true); }
            if (e.key === 'j' || e.key === 'J') selectVisibleStep(-1);
            if (e.key === 'k' || e.key === 'K') selectVisibleStep(1);
        });

        hideEl('gradingSkeleton');
        showEl('gradingLayout');
    }

    document.addEventListener('DOMContentLoaded', async () => {
        const session = await LMS.boot();
        if (!session) return;
        LMS.nav.updateUserBar(session.me);
        await loadPage();
    });

    // WS: live grade update notification
    if (window.LmsWS) {
        LmsWS.on('grade.released', payload => {
            if (String(payload.course_id) !== String(COURSE_ID)) return;
            LMS.toast('A grade was released by another grader.', 'info');
        });
    }

})();
