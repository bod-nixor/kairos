/**
 * analytics.js — Course analytics dashboard controller (Manager role)
 */
(function () {
    'use strict';

    const $ = id => document.getElementById(id);
    const LMS = window.KairosLMS;
    const params = new URLSearchParams(location.search);
    const COURSE_ID = params.get('course_id') || '';

    function showEl(id) { const el = $(id); if (el) el.classList.remove('hidden'); }
    function hideEl(id) { const el = $(id); if (el) el.classList.add('hidden'); }

    // ── Tab logic ───────────────────────────────────────────────
    function initTabs() {
        const tabBtns = document.querySelectorAll('.k-tab-btn[role="tab"]');
        tabBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                tabBtns.forEach(b => { b.setAttribute('aria-selected', 'false'); });
                btn.setAttribute('aria-selected', 'true');
                const panelId = btn.getAttribute('aria-controls');
                document.querySelectorAll('.k-tab-panel[role="tabpanel"]').forEach(p => p.hidden = true);
                const panel = document.getElementById(panelId);
                if (panel) panel.hidden = false;
            });
        });
    }

    // ── Bar chart rendering ────────────────────────────────────
    function renderBarChart(containerId, items, { colorClass, maxVal } = {}) {
        const container = $(containerId);
        if (!container) return;
        if (!items || !items.length) {
            container.innerHTML = '<div class="k-empty"><div class="k-empty__icon">📊</div><p class="k-empty__title">No data available</p></div>';
            return;
        }
        const max = maxVal || Math.max(...items.map(i => i.value || 0), 1);
        container.innerHTML = items.map(item => {
            const pct = Math.min(100, ((item.value || 0) / max) * 100);
            const cls = colorClass || (pct >= 80 ? 'k-bar-chart__fill--ok' : pct >= 50 ? '' : 'k-bar-chart__fill--warn');
            return `<div class="k-bar-chart__row">
        <span class="k-bar-chart__label" title="${LMS.escHtml(item.label)}">${LMS.escHtml(item.label)}</span>
        <div class="k-bar-chart__track">
          <div class="k-bar-chart__fill ${cls}" style="width:0%" data-target="${pct.toFixed(1)}%"></div>
        </div>
        <span class="k-bar-chart__value">${item.displayValue !== undefined ? LMS.escHtml(String(item.displayValue)) : (item.value || 0)}</span>
      </div>`;
        }).join('');
        // Animate bars in
        requestAnimationFrame(() => {
            container.querySelectorAll('.k-bar-chart__fill[data-target]').forEach(el => {
                el.style.width = el.dataset.target;
            });
        });
    }

    // ── Grade distribution chart ────────────────────────────────
    function renderGradeChart(assignments) {
        const sel = $('gradeAssignSelect');
        if (sel && assignments.length) {
            assignments.forEach(a => {
                const opt = document.createElement('option');
                opt.value = a.id;
                opt.textContent = a.title;
                sel.appendChild(opt);
            });
            sel.addEventListener('change', () => loadGradeChart(sel.value));
            if (assignments[0]) loadGradeChart(assignments[0].id);
        }
    }

    async function loadGradeChart(assignId) {
        if (!assignId) return;
        const res = await LMS.api('GET', `./api/lms/analytics_grades.php?assignment_id=${encodeURIComponent(assignId)}&course_id=${encodeURIComponent(COURSE_ID)}`);
        if (!res.ok) return;
        const buckets = (res.ok ? (res.data?.data || res.data) : null) || [];
        renderBarChart('gradeChart', buckets.map(b => ({
            label: b.range,
            value: b.count,
            displayValue: b.count + ' students',
        })), { colorClass: '' });
    }

    // ── Student table ──────────────────────────────────────────
    function renderStudentTable(students) {
        const tbody = $('studentTableBody');
        const countEl = $('studentTableCount');
        if (!tbody) return;
        if (!students || !students.length) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--muted);padding:32px">No students found.</td></tr>';
            return;
        }
        if (countEl) countEl.textContent = `${students.length} student${students.length !== 1 ? 's' : ''}`;
        tbody.innerHTML = students.map(s => {
            const compPct = s.completion_pct || 0;
            const grade = s.avg_grade !== undefined ? s.avg_grade : '—';
            const riskCls = compPct < 50 ? 'k-status--danger' : compPct < 80 ? 'k-status--warning' : 'k-status--success';
            const riskLabel = compPct < 50 ? '⚠ At risk' : compPct < 80 ? 'Progressing' : 'On track';
            return `<tr>
        <td>
          <div style="font-weight:600">${LMS.escHtml(s.name)}</div>
          <div style="font-size:12px;color:var(--muted)">${LMS.escHtml(s.email)}</div>
        </td>
        <td>
          <div style="display:flex;align-items:center;gap:8px">
            <div class="k-progress" style="width:80px">
              <div class="k-progress__fill" style="width:${compPct}%"></div>
            </div>
            <span style="font-size:12px">${compPct}%</span>
          </div>
        </td>
        <td>${typeof grade === 'number' ? grade.toFixed(1) + '%' : grade}</td>
        <td>${s.submission_count || 0}</td>
        <td>${s.last_active ? LMS.timeAgo(s.last_active) : '—'}</td>
        <td><span class="k-status ${riskCls}">${riskLabel}</span></td>
      </tr>`;
        }).join('');
    }

    function filterStudentTable(students, query) {
        if (!query) return students;
        const q = query.toLowerCase();
        return students.filter(s => (s.name || '').toLowerCase().includes(q) || (s.email || '').toLowerCase().includes(q));
    }

    // ── Export CSV ─────────────────────────────────────────────
    function exportCsv(students) {
        const rows = [['Name', 'Email', 'Completion %', 'Avg Grade', 'Submissions', 'Last Active']];
        students.forEach(s => {
            rows.push([s.name, s.email, s.completion_pct, s.avg_grade, s.submission_count, s.last_active].map(v => `"${(v || '').toString().replace(/"/g, '""')}"`));
        });
        const csv = rows.map(r => r.join(',')).join('\n');
        const blob = new Blob([csv], { type: 'text/csv' });
        const a = document.createElement('a'); a.href = URL.createObjectURL(blob);
        a.download = `analytics-course-${COURSE_ID}.csv`; a.click(); URL.revokeObjectURL(a.href);
    }

    // ── Main load ──────────────────────────────────────────────
    async function loadPage() {
        if (!COURSE_ID) {
            LMS.renderAccessDenied($('analyticsAccessDenied'), 'No course specified.', '/');
            hideEl('analyticsSkeleton'); showEl('analyticsAccessDenied'); return;
        }

        const caps = await LMS.loadCaps();
        const isManager = caps && caps.roles && (caps.roles.manager || caps.roles.admin);
        if (!isManager) {
            LMS.renderAccessDenied($('analyticsAccessDenied'), 'Course Analytics is only accessible to Managers.', `/course.html?course_id=${COURSE_ID}`);
            hideEl('analyticsSkeleton'); showEl('analyticsAccessDenied'); return;
        }

        const period = ($('periodSelect') && $('periodSelect').value) || '30';

        const [courseRes, metricsRes, completionRes, engRes, studentsRes, assignmentsRes] = await Promise.all([
            LMS.api('GET', `./api/lms/courses.php?course_id=${encodeURIComponent(COURSE_ID)}`),
            LMS.api('GET', `./api/lms/analytics_metrics.php?course_id=${encodeURIComponent(COURSE_ID)}&period=${period}`),
            LMS.api('GET', `./api/lms/analytics_completion.php?course_id=${encodeURIComponent(COURSE_ID)}`),
            LMS.api('GET', `./api/lms/analytics_engagement.php?course_id=${encodeURIComponent(COURSE_ID)}&period=${period}`),
            LMS.api('GET', `./api/lms/analytics_students.php?course_id=${encodeURIComponent(COURSE_ID)}`),
            LMS.api('GET', `./api/lms/assignments.php?course_id=${encodeURIComponent(COURSE_ID)}`),
        ]);

        hideEl('analyticsSkeleton');

        const course = courseRes.ok ? (courseRes.data?.data || courseRes.data) : null;
        if (course) {
            document.title = `Analytics — ${course.name || 'Course'} — Kairos`;
            $('kSidebarCourseName') && ($('kSidebarCourseName').textContent = course.code || course.name);
            $('analyticsSubtitle') && ($('analyticsSubtitle').textContent = `${course.name} · Manager view`);
            const bc = $('kBreadCourse');
            if (bc) {
                bc.href = `./course.html?course_id=${encodeURIComponent(COURSE_ID)}`;
                bc.textContent = course.name || 'Course';
            }
            document.querySelectorAll('[data-course-href]').forEach(el => {
                el.href = `${el.dataset.courseHref}?course_id=${encodeURIComponent(COURSE_ID)}`;
            });
        }

        // Metrics
        const metrics = metricsRes.ok ? (metricsRes.data?.data || metricsRes.data || {}) : {};
        $('metricStudents') && ($('metricStudents').textContent = metrics.total_students || '—');
        $('metricCompletion') && ($('metricCompletion').textContent = (metrics.avg_completion || 0) + '%');
        $('metricGrade') && ($('metricGrade').textContent = metrics.avg_grade ? metrics.avg_grade.toFixed(1) + '%' : '—');
        $('metricPending') && ($('metricPending').textContent = metrics.pending_reviews || '—');

        // Charts
        const completion = completionRes.ok ? (completionRes.data?.data || completionRes.data || []) : [];
        renderBarChart('completionChart', (Array.isArray(completion) ? completion : []).map(m => ({
            label: m.module_name,
            value: m.completion_pct,
            displayValue: m.completion_pct + '%',
        })), { maxVal: 100 });

        const engagement = engRes.ok ? (engRes.data?.data || engRes.data || []) : [];
        renderBarChart('engagementChart', (Array.isArray(engagement) ? engagement : []).map(e => ({
            label: e.student_name,
            value: e.activity_count,
            displayValue: e.activity_count,
        })));

        // Grade distribution with assignment selector
        const assignPayload = assignmentsRes.ok ? (assignmentsRes.data?.data || assignmentsRes.data || []) : [];
        const assignments = Array.isArray(assignPayload) ? assignPayload : (assignPayload.items || []);
        renderGradeChart(assignments);

        // Student table
        let allStudents = studentsRes.ok ? (studentsRes.data?.data || studentsRes.data || []) : [];
        if (!Array.isArray(allStudents)) allStudents = [];
        renderStudentTable(allStudents);

        let searchTimer;
        $('studentSearch') && $('studentSearch').addEventListener('input', e => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => {
                const filtered = filterStudentTable(allStudents, e.target.value);
                renderStudentTable(filtered);
            }, 250);
        });

        // Period selector
        $('periodSelect') && $('periodSelect').addEventListener('change', () => loadPage());

        // Export CSV
        $('exportCsvBtn') && $('exportCsvBtn').addEventListener('click', () => exportCsv(allStudents));

        initTabs();
        showEl('analyticsLoaded');
    }

    document.addEventListener('DOMContentLoaded', async () => {
        const session = await LMS.boot();
        if (!session) return;
        LMS.nav.updateUserBar(session.me);
        await loadPage();
    });

})();
