/**
 * quiz.js — Quiz page controller
 * Phases: intro → attempt → result → history
 */
(function () {
    'use strict';

    const $ = id => document.getElementById(id);
    const LMS = window.KairosLMS;
    const params = new URLSearchParams(location.search);
    const COURSE_ID = params.get('course_id') || '';
    const QUIZ_ID = params.get('quiz_id') || '';
    const DEBUG_MODE = params.get('debug') === '1';

    function showEl(id) { const el = $(id); if (el) el.classList.remove('hidden'); }
    function hideEl(id) { const el = $(id); if (el) el.classList.add('hidden'); }
    function showPanel(id) {
        ['quizIntroPanel', 'quizAttemptPanel', 'quizResultPanel', 'quizHistoryPanel',
            'quizError', 'quizAccessDenied', 'quizSkeleton'].forEach(hideEl);
        showEl(id);
    }


    const debugLogs = [];

    function safeStringify(v) {
        try { return JSON.stringify(v, null, 2); } catch (_) { return String(v); }
    }

    function logDebug(entry) {
        if (!DEBUG_MODE) return;
        debugLogs.push(entry);
        let debugEl = $('quizDebug');
        if (!debugEl) {
            debugEl = document.createElement('pre');
            debugEl.id = 'quizDebug';
            debugEl.className = 'k-card';
            debugEl.style.cssText = 'padding:12px;white-space:pre-wrap;margin-top:12px;';
            document.querySelector('.k-page')?.appendChild(debugEl);
        }
        debugEl.textContent = safeStringify(debugLogs);
    }

    let quizData = null;
    let attemptData = null;
    let questions = [];
    let answers = {};
    let current = 0;
    let timerInterval = null;
    let secondsLeft = 0;
    let navWired = false;
    let canManage = false;


    function wireAttemptNavigation() {
        if (navWired) return;
        navWired = true;

        $('quizPrevBtn') && $('quizPrevBtn').addEventListener('click', () => { if (current > 0) renderQuestion(current - 1); });
        $('quizNextBtn') && $('quizNextBtn').addEventListener('click', () => { if (current < questions.length - 1) renderQuestion(current + 1); });
        $('quizSubmitBtn') && $('quizSubmitBtn').addEventListener('click', () => submitAttempt(false));

        document.addEventListener('keydown', e => {
            if (!attemptData) return;
            if (['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName)) return;
            if (e.key === 'j' || e.key === 'ArrowLeft') { if (current > 0) renderQuestion(current - 1); }
            if (e.key === 'k' || e.key === 'ArrowRight') { if (current < questions.length - 1) renderQuestion(current + 1); }
        });
    }

    // ── Timer ──────────────────────────────────────────────────
    function formatTime(secs) {
        const m = Math.floor(secs / 60), s = secs % 60;
        return String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
    }

    function startTimer(totalSecs) {
        secondsLeft = totalSecs;
        const el = $('quizTimer');
        if (!el) return;
        el.classList.remove('hidden');
        timerInterval = setInterval(() => {
            secondsLeft--;
            el.textContent = formatTime(secondsLeft);
            el.classList.toggle('k-quiz-timer--warning', secondsLeft <= 120 && secondsLeft > 30);
            el.classList.toggle('k-quiz-timer--danger', secondsLeft <= 30);
            if (secondsLeft <= 0) {
                clearInterval(timerInterval);
                LMS.toast('Time is up! Submitting quiz…', 'warning');
                submitAttempt(true);
            }
        }, 1000);
        el.textContent = formatTime(secondsLeft);
    }

    function stopTimer() { clearInterval(timerInterval); timerInterval = null; }

    // ── Question rendering ─────────────────────────────────────
    function renderQuestion(idx) {
        const q = questions[idx];
        if (!q) return;
        current = idx;

        // Update header
        $('questionNum') && ($('questionNum').textContent = `Question ${idx + 1} of ${questions.length}`);
        const questionLabel = (q.text || q.prompt || '') + (q.is_required ? ' *' : '');
        $('questionText') && ($('questionText').textContent = questionLabel);
        $('quizProgressText') && ($('quizProgressText').textContent = `${idx + 1} / ${questions.length}`);

        const fill = ((idx + 1) / questions.length) * 100;
        const pBar = $('quizProgressFill');
        if (pBar) { pBar.style.width = fill + '%'; pBar.closest('[role="progressbar"]') && pBar.closest('[role="progressbar"]').setAttribute('aria-valuenow', fill.toFixed(0)); }

        // Render answer area
        const area = $('answerArea');
        if (!area) return;

        const saved = answers[q.id];

        if (q.type === 'multiple_choice' || q.type === 'mcq') {
            area.innerHTML = `<div class="k-options" role="radiogroup" aria-label="Answer options">` +
                (q.options || []).map((opt, i) => {
                    const val = opt.value || opt.id || String(i);
                    const sel = saved === val;
                    return `<label class="k-option${sel ? ' is-selected' : ''}" data-val="${LMS.escHtml(val)}">
            <input type="radio" name="q${q.id}" value="${LMS.escHtml(val)}" ${sel ? 'checked' : ''} />
            <span class="k-option__indicator" aria-hidden="true"></span>
            <span class="k-option__label">${LMS.escHtml(opt.text || opt.label || val)}</span>
          </label>`;
                }).join('') + '</div>';
            area.querySelectorAll('.k-option').forEach(opt => {
                opt.addEventListener('click', () => {
                    area.querySelectorAll('.k-option').forEach(o => o.classList.remove('is-selected'));
                    opt.classList.add('is-selected');
                    const radio = opt.querySelector('input[type="radio"]');
                    if (radio) radio.checked = true;
                    answers[q.id] = opt.dataset.val;
                    updateDots();
                });
            });
        } else if (q.type === 'true_false' || q.type === 'boolean') {
            area.innerHTML = `<div class="k-tf-options">
        <button class="k-tf-btn${saved === 'true' ? ' is-selected' : ''}" data-val="true">True</button>
        <button class="k-tf-btn${saved === 'false' ? ' is-selected' : ''}" data-val="false">False</button>
      </div>`;
            area.querySelectorAll('.k-tf-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    area.querySelectorAll('.k-tf-btn').forEach(b => b.classList.remove('is-selected'));
                    btn.classList.add('is-selected');
                    answers[q.id] = btn.dataset.val;
                    updateDots();
                });
            });
        } else if (q.type === 'short_answer' || q.type === 'text' || q.type === 'long_answer') {
            const rows = q.type === 'long_answer' ? 8 : 4;
            area.innerHTML = `<div class="k-field">
        <label class="k-label" for="saInput">Your answer</label>
        <textarea class="k-textarea" id="saInput" rows="${rows}" placeholder="Type your answer…">${LMS.escHtml(saved || '')}</textarea>
        <span class="k-field-hint">Your answer will be manually reviewed by a grader.</span>
      </div>`;
            area.querySelector('#saInput').addEventListener('input', e => {
                answers[q.id] = e.target.value;
                updateDots();
            });
        } else if (q.type === 'multiple_select' || q.type === 'msa') {
            const savedArr = Array.isArray(saved) ? saved : [];
            area.innerHTML = `<div class="k-options" role="group" aria-label="Select all that apply">` +
                (q.options || []).map((opt, i) => {
                    const val = opt.value || opt.id || String(i);
                    const sel = savedArr.includes(val);
                    return `<label class="k-option k-option--checkbox${sel ? ' is-selected' : ''}" data-val="${LMS.escHtml(val)}">
            <input type="checkbox" value="${LMS.escHtml(val)}" ${sel ? 'checked' : ''} />
            <span class="k-option__indicator" aria-hidden="true"></span>
            <span class="k-option__label">${LMS.escHtml(opt.text || val)}</span>
          </label>`;
                }).join('') + '</div>';
            area.querySelectorAll('.k-option').forEach(opt => {
                opt.addEventListener('click', () => {
                    opt.classList.toggle('is-selected');
                    const cb = opt.querySelector('input[type="checkbox"]');
                    if (cb) cb.checked = opt.classList.contains('is-selected');
                    const vals = Array.from(area.querySelectorAll('.k-option.is-selected')).map(o => o.dataset.val);
                    answers[q.id] = vals;
                    updateDots();
                });
            });
        }

        // Nav buttons
        updateNavButtons();
    }

    function updateNavButtons() {
        const prevBtn = $('quizPrevBtn');
        const nextBtn = $('quizNextBtn');
        const submitBtn = $('quizSubmitBtn');
        if (prevBtn) prevBtn.disabled = current === 0;
        if (nextBtn) nextBtn.style.display = current < questions.length - 1 ? '' : 'none';
        if (submitBtn) submitBtn.style.display = current === questions.length - 1 ? '' : 'none';
    }

    function updateDots() {
        const container = $('quizDots');
        if (!container) return;
        container.innerHTML = questions.map((q, i) => {
            const cls = i === current ? 'is-current' : (answers[q.id] !== undefined ? 'is-answered' : '');
            return `<button class="k-quiz-dot ${cls}" data-idx="${i}" aria-label="Question ${i + 1}" role="listitem"></button>`;
        }).join('');
        container.querySelectorAll('.k-quiz-dot').forEach(dot => {
            dot.addEventListener('click', () => renderQuestion(Number(dot.dataset.idx)));
        });
    }

    // ── Submit ─────────────────────────────────────────────────
    async function submitAttempt(forced) {
        stopTimer();
        if (!forced) {
            const unanswered = questions.filter(q => q.is_required && (answers[q.id] === undefined || answers[q.id] === null || (typeof answers[q.id] === 'string' && !answers[q.id].trim()) || (Array.isArray(answers[q.id]) && answers[q.id].length === 0))).length;
            if (unanswered > 0) {
                const confirmed = await new Promise(res => {
                    LMS.confirm('Submit Quiz?',
                        `You have ${unanswered} unanswered question(s). Are you sure you want to submit?`,
                        () => res(true), { okLabel: 'Submit Anyway', okClass: 'btn-primary' });
                    // If user dismissed modal without clicking, resolve false after timeout
                    setTimeout(() => res(false), 30000);
                });
                if (!confirmed) { startTimer(secondsLeft); return; }
            }
        }

        const payload = {
            attempt_id: attemptData && attemptData.attempt_id,
            responses: answers,
        };
        const endpoint = './api/lms/quiz/attempt/submit.php';
        const res = await LMS.api('POST', endpoint, payload);
        logDebug({ endpoint, method: 'POST', response_status: res.status, response_body: res.data, parsed_error_message: res.error || null });
        if (!res.ok) {
            LMS.toast('Failed to submit quiz: ' + (res.error || 'Unknown error'), 'error');
            return;
        }
        showResult(res.data?.data || res.data || {});
    }

    // ── Result ─────────────────────────────────────────────────
    function showResult(result) {
        showPanel('quizResultPanel');
        hideEl('quizStickyHeader');
        showEl('quizTopbar');

        const pct = result.score_pct || 0;
        const ringFill = $('scoreRingFill');
        if (ringFill) {
            const offset = 345 * (1 - pct / 100);
            ringFill.style.strokeDashoffset = offset;
            ringFill.classList.toggle('k-score-ring__fill--success', pct >= 80);
            ringFill.classList.toggle('k-score-ring__fill--warning', pct >= 50 && pct < 80);
            ringFill.classList.toggle('k-score-ring__fill--danger', pct < 50);
        }
        $('scoreValue') && ($('scoreValue').textContent = pct + '%');
        $('resultTitle') && ($('resultTitle').textContent = pct >= 80 ? 'Great job! 🎉' : pct >= 50 ? 'Not bad!' : 'Keep practicing');
        $('resultDesc') && ($('resultDesc').textContent = `You scored ${result.score || 0} out of ${result.max_score || 100} points.`);

        if (result.has_manual_grading) {
            showEl('manualPendingBanner');
        }

        // Per-question feedback
        const feedbackList = $('quizFeedbackList');
        if (feedbackList && result.feedback && result.feedback.length) {
            feedbackList.innerHTML = result.feedback.map((f, i) => {
                const cls = f.correct ? 'is-correct' : 'is-wrong';
                const icon = f.correct ? '✅' : '❌';
                return `<div class="k-question-card" style="margin-bottom:12px">
          <div class="k-question-card__head" style="padding-bottom:12px">
            <div class="k-question-card__num">Question ${i + 1} ${icon}</div>
            <p class="k-question-card__text">${LMS.escHtml(f.question_text || '')}</p>
          </div>
          ${f.explanation ? `<div class="k-question-card__body"><p style="color:var(--muted);font-size:14px">${LMS.escHtml(f.explanation)}</p></div>` : ''}
        </div>`;
            }).join('');
        }

        $('resultBackBtn') && ($('resultBackBtn').href = `./course.html?course_id=${encodeURIComponent(COURSE_ID)}`);
        $('resultViewHistoryBtn') && $('resultViewHistoryBtn').addEventListener('click', () => loadHistory(), { once: true });
    }

    // ── History ────────────────────────────────────────────────
    async function loadHistory() {
        showPanel('quizHistoryPanel');
        const endpoint = `./api/lms/quiz/attempts.php?assessment_id=${encodeURIComponent(QUIZ_ID)}${DEBUG_MODE ? '&debug=1' : ''}`;
        const res = await LMS.api('GET', endpoint);
        logDebug({ endpoint, method: 'GET', response_status: res.status, response_body: res.data, parsed_error_message: res.error || null });
        const list = $('attemptHistoryList');
        if (!list) return;
        const attempts = res.data?.data?.items || res.data?.items || [];
        if (!res.ok || !attempts.length) {
            list.innerHTML = '<div class="k-empty"><div class="k-empty__icon">📋</div><p class="k-empty__title">No attempts yet</p></div>';
            return;
        }
        list.innerHTML = attempts.map((a, i) => `
      <div class="k-attempt-row">
        <span class="k-attempt-row__num">#${a.attempt_number || i + 1}</span>
        <span class="k-attempt-row__date">${LMS.fmtDateTime(a.submitted_at)}</span>
        <span class="k-status ${a.score_pct >= 80 ? 'k-status--success' : a.score_pct >= 50 ? 'k-status--warning' : 'k-status--danger'}" aria-label="Score">
          ${a.score_pct}%
        </span>
        <span style="font-size:12px;color:var(--muted)">${a.score}/${a.max_score} pts</span>
      </div>`).join('');
    }



    function ensureQuestionEditorModal() {
        let modal = $('quizQuestionModal');
        if (modal) return modal;
        modal = document.createElement('dialog');
        modal.id = 'quizQuestionModal';
        modal.className = 'k-modal';
        modal.innerHTML = `<form method="dialog" class="k-modal__content" id="quizQuestionForm" style="max-width:640px">
            <h3 style="margin:0 0 12px">Add question</h3>
            <label style="display:grid;gap:6px;margin-bottom:8px"><span>Prompt</span><textarea id="quizQPrompt" rows="3" required></textarea></label>
            <div style="display:grid;grid-template-columns:1fr 140px;gap:10px;margin-bottom:8px">
              <label style="display:grid;gap:6px"><span>Type</span><select id="quizQType"><option value="mcq">Multiple choice</option><option value="multiple_select">Multi-select</option><option value="true_false">True/False</option><option value="short_answer">Short answer</option><option value="long_answer">Long answer</option></select></label>
              <label style="display:grid;gap:6px"><span>Points</span><input id="quizQPoints" type="number" min="1" value="1" /></label>
            </div>
            <label style="display:grid;gap:6px;margin-bottom:8px"><span>Options (comma-separated, optional)</span><input id="quizQOptions" type="text" placeholder="Option A, Option B" /></label>
            <label style="display:grid;gap:6px;margin-bottom:8px"><span>Correct answer (value or comma list)</span><input id="quizQAnswer" type="text" /></label>
            <label style="display:flex;align-items:center;gap:8px;margin-bottom:12px"><input id="quizQRequired" type="checkbox" /><span>Required question</span></label>
            <div style="display:flex;justify-content:flex-end;gap:8px"><button class="btn btn-ghost" type="button" id="quizQuestionCancel">Cancel</button><button class="btn btn-primary" type="submit">Add question</button></div>
          </form>`;
        document.body.appendChild(modal);
        $('quizQuestionCancel')?.addEventListener('click', () => modal.close());
        return modal;
    }

    function openQuestionEditorModal(initial = {}) {
        const modal = ensureQuestionEditorModal();
        const form = $('quizQuestionForm');
        const prompt = $('quizQPrompt');
        const type = $('quizQType');
        const points = $('quizQPoints');
        const options = $('quizQOptions');
        const answer = $('quizQAnswer');
        const required = $('quizQRequired');
        if (!form || !prompt || !type || !points || !options || !answer || !required) return Promise.resolve(null);
        prompt.value = initial.prompt || '';
        type.value = normalizeQuestionType(initial.question_type || 'mcq');
        points.value = String(initial.points || 1);
        options.value = initial.options_raw || '';
        answer.value = initial.answer_raw || '';
        required.checked = !!initial.is_required;
        modal.showModal();
        return new Promise((resolve) => {
            const closeHandler = () => {
                form.removeEventListener('submit', submitHandler);
                modal.removeEventListener('close', closeHandler);
                resolve(null);
            };
            const submitHandler = (event) => {
                event.preventDefault();
                const payload = {
                    prompt: prompt.value.trim(),
                    question_type: normalizeQuestionType(type.value),
                    points: Number(points.value || 1),
                    options_raw: options.value,
                    answer_raw: answer.value,
                    is_required: required.checked,
                };
                if (!payload.prompt) {
                    LMS.toast('Question prompt is required', 'warning');
                    return;
                }
                form.removeEventListener('submit', submitHandler);
                modal.removeEventListener('close', closeHandler);
                modal.close();
                resolve(payload);
            };
            form.addEventListener('submit', submitHandler);
            modal.addEventListener('close', closeHandler);
            setTimeout(() => prompt.focus(), 0);
        });
    }

    function normalizeQuestionType(type) {
        const value = String(type || 'mcq').toLowerCase();
        if (value === 'multiple_choice') return 'mcq';
        if (value === 'truefalse') return 'true_false';
        if (value === 'multi_select') return 'multiple_select';
        if (value === 'short') return 'short_answer';
        if (value === 'long') return 'long_answer';
        return value;
    }

    async function addQuestion() {
        const modalInput = await openQuestionEditorModal();
        if (!modalInput) return;
        const options = modalInput.options_raw
            ? modalInput.options_raw.split(',').map((v, i) => ({ value: `opt_${i + 1}`, text: v.trim() })).filter((o) => o.text)
            : [];
        const correctRaw = String(modalInput.answer_raw || '').trim();
        const answerKey = modalInput.question_type === 'multiple_select'
            ? correctRaw.split(',').map((v) => v.trim()).filter(Boolean)
            : (correctRaw || null);
        const payload = {
            assessment_id: Number(QUIZ_ID),
            prompt: modalInput.prompt,
            question_type: modalInput.question_type,
            points: Number(modalInput.points) > 0 ? Number(modalInput.points) : 1,
            options,
            answer_key: answerKey,
            is_required: modalInput.is_required ? 1 : 0,
        };
        const res = await LMS.api('POST', './api/lms/quiz/question/create.php', payload);
        if (!res.ok) {
            LMS.toast(res.data?.error?.message || 'Failed to add question', 'error');
            return;
        }
        LMS.toast('Question added', 'success');
        await renderStaffPanel();
    }

    async function renderStaffPanel() {
        if (!canManage) return;
        const intro = $('quizIntroPanel');
        if (!intro) return;
        const existingPanel = $('quizStaffPanel');
        if (existingPanel) existingPanel.remove();
        const panel = document.createElement('section');
        panel.id = 'quizStaffPanel';
        panel.className = 'k-card';
        panel.style.marginTop = '16px';
        panel.style.padding = '16px';
        panel.innerHTML = `<h3>Staff Quiz Management</h3><div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px"><button class="btn btn-secondary btn-sm" id="staffAddQuestionBtn" type="button">+ Add Question</button><button class="btn btn-ghost btn-sm" id="staffPublishQuizBtn" type="button">Publish</button><button class="btn btn-ghost btn-sm" id="staffDraftQuizBtn" type="button">Move to Draft</button><button class="btn btn-ghost btn-sm" id="staffMandatoryBtn" type="button"></button><button class="btn btn-ghost btn-sm" id="staffLoadAttemptsBtn" type="button">Load Attempts</button></div><div id="staffQuestions"></div><div id="staffAttempts"></div>`;
        intro.appendChild(panel);

        const staffMandatoryBtn = $('staffMandatoryBtn');
        if (staffMandatoryBtn) {
            const requiredNow = Number(quizData?.required_flag || 0) === 1;
            staffMandatoryBtn.textContent = requiredNow ? 'Set Optional' : 'Set Mandatory';
        }

        $('staffAddQuestionBtn')?.addEventListener('click', addQuestion);
        $('staffPublishQuizBtn')?.addEventListener('click', async () => {
            const res = await LMS.api('POST', './api/lms/quiz/publish.php', { assessment_id: Number(QUIZ_ID), published: 1 });
            LMS.toast(res.ok ? 'Quiz published' : 'Publish failed', res.ok ? 'success' : 'error');
            if (res.ok) await loadPage();
        });
        $('staffDraftQuizBtn')?.addEventListener('click', async () => {
            const res = await LMS.api('POST', './api/lms/quiz/publish.php', { assessment_id: Number(QUIZ_ID), published: 0 });
            LMS.toast(res.ok ? 'Quiz moved to draft' : 'Update failed', res.ok ? 'success' : 'error');
            if (res.ok) await loadPage();
        });
        $('staffMandatoryBtn')?.addEventListener('click', async () => {
            const currentRequired = Number(quizData?.required_flag || 0) === 1;
            const newRequired = currentRequired ? 0 : 1;
            LMS.confirm(
                newRequired ? 'Set as mandatory?' : 'Unset mandatory?',
                newRequired ? 'Students will be required to complete this quiz.' : 'Students will no longer be required to complete this quiz.',
                async () => {
                    const res = await LMS.api('POST', './api/lms/quiz/mandatory.php', {
                        assessment_id: Number(QUIZ_ID),
                        required: newRequired,
                    });
                    LMS.toast(
                        res.ok
                            ? (newRequired ? 'Quiz marked as mandatory' : 'Quiz marked as optional')
                            : 'Mandatory update failed',
                        res.ok ? 'success' : 'error'
                    );
                    if (res.ok) {
                        quizData = { ...(quizData || {}), required_flag: newRequired };
                        if (staffMandatoryBtn) staffMandatoryBtn.textContent = newRequired ? 'Set Optional' : 'Set Mandatory';
                        await loadPage();
                    }
                },
                { okLabel: newRequired ? 'Set mandatory' : 'Set optional', okClass: 'btn-primary' }
            );
        });
        $('staffLoadAttemptsBtn')?.addEventListener('click', async () => {
            const res = await LMS.api('GET', `./api/lms/quiz/submissions.php?assessment_id=${encodeURIComponent(QUIZ_ID)}&course_id=${encodeURIComponent(COURSE_ID)}`);
            const target = $('staffAttempts');
            if (!target) return;
            if (!res.ok) {
                target.innerHTML = '<p>Failed to load attempts.</p>';
                return;
            }
            const items = res.data?.data?.items || res.data?.items || [];
            target.innerHTML = `<h4>Attempts / Submissions (${items.length})</h4>` + items.map((a) => `<div class="k-attempt-row">Attempt #${a.attempt_id} · student ${a.student_user_id} · ${a.status} · ${a.score ?? '-'} / ${a.max_score ?? '-'}</div>`).join('');
        });

        const qRes = await LMS.api('GET', `./api/lms/quiz/question/list.php?assessment_id=${encodeURIComponent(QUIZ_ID)}`);
        const questions = qRes.ok ? (qRes.data?.data?.items || qRes.data?.items || []) : [];
        const wrap = $('staffQuestions');
        if (!wrap) return;
        wrap.innerHTML = `<h4>Questions (${questions.length})</h4>` + questions.map((q, idx) => `<div class="k-card" style="padding:8px;margin-bottom:8px"><div><strong>Q${idx + 1}.</strong> ${LMS.escHtml(q.prompt || '')} (${LMS.escHtml(q.question_type || '')})${Number(q.is_required||0)===1 ? ' <span class="k-status k-status--warning">Required</span>' : ''}</div><div style="margin-top:6px;display:flex;gap:6px;flex-wrap:wrap"><button class="btn btn-ghost btn-sm" data-act="move-up" data-id="${q.question_id}" ${idx===0?'disabled':''}>Move Up</button><button class="btn btn-ghost btn-sm" data-act="move-down" data-id="${q.question_id}" ${idx===questions.length-1?'disabled':''}>Move Down</button><button class="btn btn-ghost btn-sm" data-act="toggle-required" data-id="${q.question_id}" data-required="${Number(q.is_required||0)}">${Number(q.is_required||0)===1?'Set Optional':'Set Required'}</button><button class="btn btn-ghost btn-sm" data-act="edit" data-id="${q.question_id}">Edit</button> <button class="btn btn-ghost btn-sm" data-act="delete" data-id="${q.question_id}">Delete</button></div></div>`).join('');

        for (const btn of wrap.querySelectorAll('button[data-act="move-up"]')) {
            btn.addEventListener('click', async () => {
                const id = Number(btn.dataset.id || 0);
                const res = await LMS.api('POST', './api/lms/quiz/question/reorder.php', {
                    question_id: id,
                    direction: 'up',
                });
                LMS.toast(res.ok ? 'Question order updated' : 'Failed to reorder questions', res.ok ? 'success' : 'error');
                if (res.ok) await renderStaffPanel();
            });
        }
        for (const btn of wrap.querySelectorAll('button[data-act="move-down"]')) {
            btn.addEventListener('click', async () => {
                const id = Number(btn.dataset.id || 0);
                const res = await LMS.api('POST', './api/lms/quiz/question/reorder.php', {
                    question_id: id,
                    direction: 'down',
                });
                LMS.toast(res.ok ? 'Question order updated' : 'Failed to reorder questions', res.ok ? 'success' : 'error');
                if (res.ok) await renderStaffPanel();
            });
        }
        wrap.querySelectorAll('button[data-act="toggle-required"]').forEach((btn) => btn.addEventListener('click', async () => {
            const id = Number(btn.dataset.id || 0);
            const isRequired = Number(btn.dataset.required || 0) === 1;
            const res = await LMS.api('POST', './api/lms/quiz/question/update.php', { question_id: id, is_required: isRequired ? 0 : 1 });
            LMS.toast(res.ok ? 'Question requirement updated' : 'Failed to update requirement', res.ok ? 'success' : 'error');
            if (res.ok) await renderStaffPanel();
        }));

        wrap.querySelectorAll('button[data-act="delete"]').forEach((btn) => btn.addEventListener('click', async () => {
            const id = Number(btn.dataset.id || 0);
            const res = await LMS.api('POST', './api/lms/quiz/question/delete.php', { question_id: id });
            LMS.toast(res.ok ? 'Question deleted' : 'Delete failed', res.ok ? 'success' : 'error');
            if (res.ok) await renderStaffPanel();
        }));
        wrap.querySelectorAll('button[data-act="edit"]').forEach((btn) => btn.addEventListener('click', async () => {
            const id = Number(btn.dataset.id || 0);
            const question = questions.find((q) => Number(q.question_id) === id) || {};
            const questionOptions = Array.isArray(question.options) ? question.options : [];
            const answerSeed = Array.isArray(question.answer_key)
                ? question.answer_key.join(', ')
                : (question.answer_key || '');
            const modalInput = await openQuestionEditorModal({
                prompt: question.prompt || '',
                question_type: question.question_type || 'mcq',
                points: question.points || 1,
                options_raw: questionOptions.map((opt) => opt.text || opt.value || '').filter(Boolean).join(', '),
                answer_raw: answerSeed,
                is_required: Number(question.is_required || 0) === 1,
            });
            if (!modalInput) return;
            const options = String(modalInput.options_raw || '')
                .split(',')
                .map((v, idx) => ({ value: `opt_${idx + 1}`, text: v.trim() }))
                .filter((opt) => opt.text);
            const normalizedPoints = Number(modalInput.points) > 0 ? Number(modalInput.points) : 1;
            const answerRaw = String(modalInput.answer_raw || '').trim();
            const answerKey = modalInput.question_type === 'multiple_select'
                ? answerRaw.split(',').map((v) => v.trim()).filter(Boolean)
                : (answerRaw || null);
            const res = await LMS.api('POST', './api/lms/quiz/question/update.php', {
                question_id: id,
                prompt: modalInput.prompt,
                question_type: modalInput.question_type,
                points: normalizedPoints,
                answer_key: answerKey,
                settings: { options },
                is_required: modalInput.is_required ? 1 : 0,
            });
            LMS.toast(res.ok ? 'Question updated' : 'Update failed', res.ok ? 'success' : 'error');
            if (res.ok) await renderStaffPanel();
        }));
    }

    // ── Main load ──────────────────────────────────────────────
    async function loadPage() {
        if (!QUIZ_ID) {
            LMS.renderAccessDenied($('quizAccessDenied'), 'No quiz specified. Please select a quiz from the Modules page.', COURSE_ID ? `./modules.html?course_id=${encodeURIComponent(COURSE_ID)}` : '/signoff/');
            showPanel('quizAccessDenied');
            return;
        }

        const dbg = DEBUG_MODE ? '&debug=1' : '';
        const endpoint = `./api/lms/quiz/get.php?assessment_id=${encodeURIComponent(QUIZ_ID)}&course_id=${encodeURIComponent(COURSE_ID)}${dbg}`;
        const res = await LMS.api('GET', endpoint);
        logDebug({ endpoint, method: 'GET', response_status: res.status, response_body: res.data, parsed_error_message: res.error || null });
        hideEl('quizSkeleton');

        if (res.status === 403) {
            LMS.renderAccessDenied($('quizAccessDenied'), 'You do not have access to this quiz.', `/course.html?course_id=${COURSE_ID}`);
            showPanel('quizAccessDenied');
            return;
        }
        if (!res.ok) {
            showPanel('quizError');
            $('quizRetryBtn') && $('quizRetryBtn').addEventListener('click', loadPage, { once: true });
            return;
        }

        quizData = res.data?.data || res.data || {};
        document.title = `${quizData.title || 'Quiz'} — Kairos`;
        const bc = $('kBreadCourse');
        if (bc) {
            bc.href = `./course.html?course_id=${encodeURIComponent(COURSE_ID)}`;
            bc.textContent = quizData.course_name || 'Course';
        }
        $('quizStickyTitle') && ($('quizStickyTitle').textContent = quizData.title || 'Quiz');

        // Populate intro panel
        $('quizIntroTitle') && ($('quizIntroTitle').textContent = quizData.title || 'Quiz');
        $('quizIntroDesc') && ($('quizIntroDesc').textContent = quizData.description || quizData.instructions || '');
        $('metaQuestions') && ($('metaQuestions').textContent = quizData.question_count || quizData.total_questions || '?');
        $('metaTime') && ($('metaTime').textContent = quizData.time_limit_min ? quizData.time_limit_min + ' min' : (quizData.time_limit_minutes ? quizData.time_limit_minutes + ' min' : 'None'));
        $('metaAttempts') && ($('metaAttempts').textContent = quizData.attempts_used || 0);
        $('metaMax') && ($('metaMax').textContent = quizData.max_attempts ? quizData.max_attempts : '∞');

        // Disable start if max attempts reached
        const startBtn = $('quizStartBtn');
        if (startBtn) {
            const attemptsUsed = Number(quizData.attempts_used || 0);
            const noAttempts = quizData.max_attempts && attemptsUsed >= Number(quizData.max_attempts);
            if (noAttempts) {
                startBtn.disabled = true;
                startBtn.textContent = 'No attempts remaining';
            } else {
                startBtn.addEventListener('click', startAttempt, { once: true });
            }
        }

        $('quizShowHistoryBtn') && $('quizShowHistoryBtn').addEventListener('click', loadHistory);
        showPanel('quizIntroPanel');
        await renderStaffPanel();
    }

    async function startAttempt() {
        const endpoint = './api/lms/quiz/attempt.php';
        const res = await LMS.api('POST', endpoint, { assessment_id: Number(QUIZ_ID), course_id: Number(COURSE_ID) });
        logDebug({ endpoint, method: 'POST', response_status: res.status, response_body: res.data, parsed_error_message: res.error || null });
        if (!res.ok) {
            LMS.toast('Could not start quiz: ' + (res.error || 'Error'), 'error');
            return;
        }
        attemptData = res.data?.data || res.data || {};
        const questionsEndpoint = `./api/lms/quiz/question/list.php?assessment_id=${encodeURIComponent(QUIZ_ID)}`;
        const qRes = await LMS.api('GET', questionsEndpoint);
        logDebug({ endpoint: questionsEndpoint, method: 'GET', response_status: qRes.status, response_body: qRes.data, parsed_error_message: qRes.error || null });
        questions = qRes.ok ? (qRes.data?.data?.items || qRes.data?.items || []) : [];
        questions = questions.map((q) => ({
            id: Number(q.question_id || q.id || 0),
            text: q.prompt || q.text || '',
            type: q.question_type || q.type || 'mcq',
            options: Array.isArray(q.options) ? q.options : [],
            position: Number(q.position || 0),
            is_required: Number(q.is_required || 0) === 1,
        }));
        answers = {};

        if (!questions.length) {
            LMS.toast('This quiz has no questions.', 'warning');
            return;
        }

        showPanel('quizAttemptPanel');
        hideEl('quizTopbar');
        showEl('quizStickyHeader');

        if (quizData.time_limit_min) startTimer(quizData.time_limit_min * 60);

        updateDots();
        renderQuestion(0);
        wireAttemptNavigation();
    }

    $('historyBackBtn') && $('historyBackBtn').addEventListener('click', () => showPanel('quizIntroPanel'));

    document.addEventListener('DOMContentLoaded', async () => {
        const session = await LMS.boot();
        if (!session) return;
        LMS.nav.updateUserBar(session.me);
        const roles = session.caps?.roles || {};
        canManage = !!(roles.admin || roles.manager);
        await loadPage();
    });

})();
