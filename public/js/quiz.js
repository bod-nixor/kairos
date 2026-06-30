/**
 * quiz.js — Quiz page controller
 * Phases: intro → attempt → result → history
 */
(function () {
    'use strict';

    const $ = id => document.getElementById(id);
    const LMS = window.KairosLMS;
    const Management = window.KairosLMSManagement;
    const params = new URLSearchParams(location.search);
    const COURSE_ID = params.get('course_id') || '';
    const QUIZ_ID = params.get('quiz_id') || '';
    const DEBUG_MODE = params.get('debug') === '1';
    const URL_MODE = params.get('mode') || 'view';

    function showEl(id) { const el = $(id); if (el) el.classList.remove('hidden'); }
    function hideEl(id) { const el = $(id); if (el) el.classList.add('hidden'); }
    function showPanel(id) {
        ['quizIntroPanel', 'quizAttemptPanel', 'quizResultPanel', 'quizHistoryPanel',
            'quizError', 'quizAccessDenied', 'quizSkeleton'].forEach(hideEl);
        showEl(id);
    }

    function renderUnavailable() {
        const target = $('quizError');
        if (!target) return;
        target.innerHTML = `<div class="k-empty"><div class="k-empty__icon" aria-hidden="true">⚡</div><p class="k-empty__title">Quiz unavailable</p><p class="k-empty__desc">This quiz was deleted or is no longer available in this course.</p><a class="btn btn-primary" href="./quizzes.html?course_id=${encodeURIComponent(COURSE_ID)}">Back to quizzes</a></div>`;
        showPanel('quizError');
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
            debugEl.className = 'k-card k-debug-panel';
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
    let attemptMode = 'idle';
    let isSubmitting = false;

    function hasOwn(obj, key) {
        return Object.prototype.hasOwnProperty.call(obj || {}, key);
    }

    function optionValue(opt, index) {
        if (opt && typeof opt === 'object') {
            if (hasOwn(opt, 'value') && opt.value !== null && opt.value !== undefined) return String(opt.value);
            if (hasOwn(opt, 'id') && opt.id !== null && opt.id !== undefined) return String(opt.id);
        }
        return String(index);
    }

    function optionText(opt, fallback) {
        if (opt && typeof opt === 'object') {
            return String(opt.text || opt.label || opt.value || fallback);
        }
        return String(opt || fallback);
    }

    function answerValues(value) {
        if (Array.isArray(value)) {
            return Array.from(new Set(value.map(entry => String(entry ?? '').trim()).filter(Boolean)));
        }
        if (value === null || value === undefined || String(value).trim() === '') return [];
        return [String(value).trim()];
    }

    function formatAnswerText(value, opts, type) {
        const values = answerValues(value);
        if (!values.length) return 'Unanswered';
        if (type === 'true_false' || type === 'boolean') {
            const formatted = values.map(raw => raw.toLowerCase() === 'true' ? 'True' : raw.toLowerCase() === 'false' ? 'False' : raw);
            return formatted.join(', ');
        }
        const options = Array.isArray(opts) ? opts : [];
        const labelByValue = new Map(options.map((opt, index) => [optionValue(opt, index), optionText(opt, optionValue(opt, index))]));
        return values.map(raw => labelByValue.get(raw) || raw).join(', ');
    }

    function appendPreviewAnswerInfo(area, q) {
        if (!isPreviewMode()) return;
        const hasCorrect = q.answer_key !== null && q.answer_key !== undefined && answerValues(q.answer_key).length > 0;
        const explanation = String(q.answer_explanation || q.explanation || '').trim();
        if (!hasCorrect && !explanation) return;
        const correctText = hasCorrect ? formatAnswerText(q.answer_key, q.options || [], q.type || q.question_type || '') : '';
        area.insertAdjacentHTML('beforeend', `
          <aside class="k-answer-explanation k-answer-explanation--preview" aria-label="Correct answer and explanation">
            ${hasCorrect ? `<div><span class="k-review-label">Correct answer</span><p>${LMS.escHtml(correctText)}</p></div>` : ''}
            ${explanation ? `<div><span class="k-review-label">Explanation</span><p>${LMS.escHtml(explanation)}</p></div>` : ''}
          </aside>
        `);
    }

    function answerProvided(question) {
        if (!question) return false;
        const raw = answers[question.id];
        if (Array.isArray(raw)) {
            return raw.some(value => value !== null && value !== undefined && String(value).trim() !== '');
        }
        if (raw === null || raw === undefined) return false;
        return String(raw).trim() !== '';
    }

    function answeredCount() {
        return questions.filter(answerProvided).length;
    }

    function requiredMissingQuestions() {
        return questions.filter(q => q.is_required && !answerProvided(q));
    }

    function isPreviewMode() {
        return attemptMode === 'preview' || !!attemptData?.preview;
    }

    function canPreviewQuiz() {
        const caps = quizData?.capabilities || {};
        return Boolean(caps.manage_course || caps.grade_course || ['admin', 'manager', 'ta'].includes(String(quizData?.course_role || '').toLowerCase()));
    }

    function canTakeStudentAttempt() {
        return Boolean(quizData?.capabilities?.participate_as_student);
    }

    function quizQuestionCount() {
        const raw = quizData?.question_count ?? quizData?.total_questions ?? 0;
        const count = Number(raw);
        return Number.isFinite(count) ? Math.max(0, count) : 0;
    }

    function setButtonBusy(button, busy, busyLabel, readyLabel) {
        if (!button) return;
        button.disabled = !!busy;
        if (busyLabel && readyLabel) {
            button.textContent = busy ? busyLabel : readyLabel;
        }
    }


    function wireAttemptNavigation() {
        if (navWired) return;
        navWired = true;

        $('quizPrevBtn') && $('quizPrevBtn').addEventListener('click', () => { if (!isSubmitting && current > 0) renderQuestion(current - 1); });
        $('quizNextBtn') && $('quizNextBtn').addEventListener('click', () => { if (!isSubmitting && current < questions.length - 1) renderQuestion(current + 1); });
        $('quizSubmitBtn') && $('quizSubmitBtn').addEventListener('click', () => {
            if (isPreviewMode()) {
                endPreview();
                return;
            }
            submitAttempt(false);
        });

        document.addEventListener('keydown', e => {
            if (!attemptData) return;
            if (['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName)) return;
            if (isSubmitting) return;
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
        stopTimer();
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
    function syncProgressUi() {
        const total = questions.length || 1;
        const answered = answeredCount();
        $('questionNum') && ($('questionNum').textContent = `Question ${current + 1} of ${questions.length}`);
        $('quizProgressText') && ($('quizProgressText').textContent = `Question ${current + 1} of ${questions.length} • ${answered} answered`);

        const fill = ((current + 1) / total) * 100;
        const pBar = $('quizProgressFill');
        if (pBar) {
            pBar.style.width = fill + '%';
            const progressRoot = pBar.closest('[role="progressbar"]');
            if (progressRoot) {
                progressRoot.setAttribute('aria-valuenow', fill.toFixed(0));
                progressRoot.setAttribute('aria-label', `Question ${current + 1} of ${questions.length}, ${answered} answered`);
            }
        }
        updateDots();
    }

    function renderChoiceEmpty(area) {
        area.innerHTML = '<div class="k-empty k-empty-inline--wide"><p class="k-empty__title">No answer options available</p><p class="k-empty__desc">This question needs answer options before it can be completed.</p></div>';
    }

    function renderQuestion(idx) {
        const q = questions[idx];
        if (!q) return;
        current = idx;

        const questionLabel = (q.text || q.prompt || '') + (q.is_required ? ' *' : '');
        $('questionText') && ($('questionText').textContent = questionLabel);
        $('quizPreviewBanner')?.classList.toggle('hidden', !isPreviewMode());

        const area = $('answerArea');
        if (!area) return;

        const saved = answers[q.id];

        if (q.type === 'multiple_choice' || q.type === 'mcq') {
            const options = Array.isArray(q.options) ? q.options : [];
            if (!options.length) {
                renderChoiceEmpty(area);
            } else {
                const groupName = `quiz-q-${q.id}`;
                area.innerHTML = `<div class="k-options" role="radiogroup" aria-label="Answer options">` +
                    options.map((opt, i) => {
                        const val = optionValue(opt, i);
                        const inputId = `quiz-q-${q.id}-option-${i}`;
                        const sel = String(saved ?? '') === val;
                        return `<label class="k-option${sel ? ' is-selected' : ''}" for="${inputId}" data-val="${LMS.escHtml(val)}">
              <input id="${inputId}" type="radio" name="${groupName}" value="${LMS.escHtml(val)}" ${sel ? 'checked' : ''} />
              <span class="k-option__indicator" aria-hidden="true"></span>
              <span class="k-option__label">${LMS.escHtml(optionText(opt, val))}</span>
            </label>`;
                    }).join('') + '</div>';

                const radios = Array.from(area.querySelectorAll(`input[type="radio"][name="${groupName}"]`));
                const syncSelection = (value) => {
                    answers[q.id] = value;
                    radios.forEach(input => {
                        const selected = input.value === value;
                        input.checked = selected;
                        input.closest('.k-option')?.classList.toggle('is-selected', selected);
                    });
                    syncProgressUi();
                };
                radios.forEach(input => {
                    input.addEventListener('change', () => {
                        if (input.checked) syncSelection(input.value);
                    });
                });
            }
        } else if (q.type === 'true_false' || q.type === 'boolean') {
            const savedVal = String(saved ?? '');
            area.innerHTML = `<div class="k-tf-options" role="group" aria-label="True or false answer">
        <button type="button" class="k-tf-btn${savedVal === 'true' ? ' is-selected' : ''}" data-val="true" aria-pressed="${savedVal === 'true' ? 'true' : 'false'}">True</button>
        <button type="button" class="k-tf-btn${savedVal === 'false' ? ' is-selected' : ''}" data-val="false" aria-pressed="${savedVal === 'false' ? 'true' : 'false'}">False</button>
      </div>`;
            area.querySelectorAll('.k-tf-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    area.querySelectorAll('.k-tf-btn').forEach(b => {
                        b.classList.remove('is-selected');
                        b.setAttribute('aria-pressed', 'false');
                    });
                    btn.classList.add('is-selected');
                    btn.setAttribute('aria-pressed', 'true');
                    answers[q.id] = btn.dataset.val;
                    syncProgressUi();
                });
            });
        } else if (q.type === 'short_answer' || q.type === 'text' || q.type === 'long_answer') {
            const rows = q.type === 'long_answer' ? 8 : 4;
            const inputId = `quiz-q-${q.id}-text`;
            area.innerHTML = `<div class="k-field">
        <label class="k-label" for="${inputId}">Your answer</label>
        <textarea class="k-textarea" id="${inputId}" rows="${rows}" placeholder="Type your answer…">${LMS.escHtml(saved || '')}</textarea>
        <span class="k-field-hint">Your answer will be manually reviewed by a grader.</span>
      </div>`;
            area.querySelector(`#${inputId}`).addEventListener('input', e => {
                answers[q.id] = e.target.value;
                syncProgressUi();
            });
        } else if (q.type === 'multiple_select' || q.type === 'msa') {
            const options = Array.isArray(q.options) ? q.options : [];
            if (!options.length) {
                renderChoiceEmpty(area);
            } else {
                const savedArr = Array.isArray(saved) ? saved.map(value => String(value)) : [];
                area.innerHTML = `<div class="k-options" role="group" aria-label="Select all that apply">` +
                    options.map((opt, i) => {
                        const val = optionValue(opt, i);
                        const inputId = `quiz-q-${q.id}-option-${i}`;
                        const sel = savedArr.includes(val);
                        return `<label class="k-option k-option--checkbox${sel ? ' is-selected' : ''}" for="${inputId}" data-val="${LMS.escHtml(val)}">
              <input id="${inputId}" type="checkbox" value="${LMS.escHtml(val)}" ${sel ? 'checked' : ''} />
              <span class="k-option__indicator" aria-hidden="true"></span>
              <span class="k-option__label">${LMS.escHtml(optionText(opt, val))}</span>
            </label>`;
                    }).join('') + '</div>';

                const checkboxes = Array.from(area.querySelectorAll('input[type="checkbox"]'));
                const syncSelection = () => {
                    const vals = [];
                    checkboxes.forEach(input => {
                        input.closest('.k-option')?.classList.toggle('is-selected', input.checked);
                        if (input.checked) vals.push(input.value);
                    });
                    answers[q.id] = vals;
                    syncProgressUi();
                };
                checkboxes.forEach(input => input.addEventListener('change', syncSelection));
            }
        } else {
            area.innerHTML = '<div class="k-empty k-empty-inline--wide"><p class="k-empty__title">Unsupported question type</p><p class="k-empty__desc">This question cannot be answered here yet.</p></div>';
        }

        appendPreviewAnswerInfo(area, q);
        syncProgressUi();
        updateNavButtons();
    }

    function updateNavButtons() {
        const prevBtn = $('quizPrevBtn');
        const nextBtn = $('quizNextBtn');
        const submitBtn = $('quizSubmitBtn');
        if (prevBtn) prevBtn.disabled = current === 0 || isSubmitting;
        if (nextBtn) {
            nextBtn.disabled = isSubmitting;
            nextBtn.classList.toggle('hidden', current >= questions.length - 1);
        }
        if (submitBtn) {
            submitBtn.disabled = isSubmitting;
            submitBtn.textContent = isPreviewMode() ? 'End Preview' : (isSubmitting ? 'Submitting…' : 'Submit Quiz');
            submitBtn.setAttribute('aria-label', isPreviewMode() ? 'End quiz preview' : 'Submit quiz');
            submitBtn.classList.toggle('hidden', current !== questions.length - 1);
        }
    }

    function updateDots() {
        const container = $('quizDots');
        if (!container) return;
        container.innerHTML = questions.map((q, i) => {
            const answered = answerProvided(q);
            const cls = [i === current ? 'is-current' : '', answered ? 'is-answered' : ''].filter(Boolean).join(' ');
            const label = `Question ${i + 1}${answered ? ', answered' : ', unanswered'}${i === current ? ', current' : ''}`;
            return `<button type="button" class="k-quiz-dot ${cls}" data-idx="${i}" aria-label="${label}" ${i === current ? 'aria-current="step"' : ''} role="listitem">${i + 1}</button>`;
        }).join('');
        container.querySelectorAll('.k-quiz-dot').forEach(dot => {
            dot.addEventListener('click', () => {
                if (!isSubmitting) renderQuestion(Number(dot.dataset.idx));
            });
        });
    }

    // ── Submit ─────────────────────────────────────────────────
    async function submitAttempt(forced) {
        if (isPreviewMode()) {
            endPreview();
            return;
        }
        if (isSubmitting) return;
        if (!attemptData?.attempt_id) {
            LMS.toast('No active quiz attempt was found. Please start the quiz again.', 'error');
            showPanel('quizIntroPanel');
            return;
        }

        if (!forced) {
            const missing = requiredMissingQuestions();
            if (missing.length > 0) {
                const firstMissingIndex = questions.findIndex(q => q.id === missing[0].id);
                LMS.toast(`Answer ${missing.length} required question${missing.length === 1 ? '' : 's'} before submitting.`, 'warning');
                if (firstMissingIndex >= 0) renderQuestion(firstMissingIndex);
                return;
            }
        }

        const payload = {
            attempt_id: attemptData && attemptData.attempt_id,
            responses: answers,
        };
        const endpoint = './api/lms/quiz/attempt/submit.php';
        const timerWasRunning = timerInterval !== null;
        stopTimer();
        isSubmitting = true;
        updateNavButtons();
        try {
            const res = await LMS.api('POST', endpoint, payload);
            logDebug({ endpoint, method: 'POST', response_status: res.status, response_body: res.data, parsed_error_message: res.error || null });
            if (!res.ok) {
                const missingIds = res.data?.error?.details?.missing_question_ids || res.data?.details?.missing_question_ids || [];
                if (Array.isArray(missingIds) && missingIds.length) {
                    const firstMissingIndex = questions.findIndex(q => missingIds.map(Number).includes(Number(q.id)));
                    LMS.toast('Some required questions still need answers.', 'warning');
                    if (firstMissingIndex >= 0) renderQuestion(firstMissingIndex);
                } else {
                    LMS.toast('Failed to submit quiz: ' + (res.error || 'Unknown error'), 'error');
                }
                if (!forced && timerWasRunning && secondsLeft > 0) startTimer(secondsLeft);
                return;
            }
            attemptMode = 'completed';
            showResult(res.data?.data || res.data || {});
        } finally {
            isSubmitting = false;
            updateNavButtons();
        }
    }

    // ── Result ─────────────────────────────────────────────────
    function reviewText(value, fallback = 'Unanswered') {
        if (Array.isArray(value)) {
            const text = value.map(entry => String(entry || '').trim()).filter(Boolean).join(', ');
            return text || fallback;
        }
        const text = String(value ?? '').trim();
        return text || fallback;
    }

    function reviewStatus(item) {
        if (!item?.is_answered) return { key: 'unanswered', label: 'Unanswered', icon: '!' };
        if (Number(item.needs_manual_grading || 0) === 1 || item.is_correct === null || item.is_correct === undefined) {
            return { key: 'manual', label: 'Needs review', icon: '...' };
        }
        return item.is_correct
            ? { key: 'correct', label: 'Correct', icon: '✓' }
            : { key: 'wrong', label: 'Incorrect', icon: '×' };
    }

    function renderReviewOptions(item) {
        const options = Array.isArray(item.options) ? item.options : [];
        if (!options.length) return '';
        return `<ul class="k-review-options" aria-label="Answer options">` + options.map((option) => {
            const isSelected = !!option.is_selected;
            const isCorrect = !!option.is_correct;
            const classes = ['k-review-option', isSelected ? 'is-selected' : '', isCorrect ? 'is-correct' : '', isSelected && !isCorrect ? 'is-wrong' : ''].filter(Boolean).join(' ');
            const marker = isCorrect ? '✓' : (isSelected ? '×' : '');
            return `<li class="${classes}">
              <span class="k-review-option__marker" aria-hidden="true">${LMS.escHtml(marker)}</span>
              <span class="k-review-option__text">${LMS.escHtml(option.text || option.value || '')}</span>
            </li>`;
        }).join('') + '</ul>';
    }

    function renderAttemptReview(reviewData, options = {}) {
        const attempt = reviewData?.attempt || {};
        const items = reviewData?.items || reviewData?.questions || [];
        const score = attempt.score === null || attempt.score === undefined ? null : Number(attempt.score);
        const maxScore = attempt.max_score === null || attempt.max_score === undefined ? null : Number(attempt.max_score);
        const pct = Number.isFinite(Number(attempt.score_pct))
            ? Number(attempt.score_pct)
            : (score !== null && maxScore > 0 ? Math.round((score / maxScore) * 100) : null);
        const submitted = attempt.submitted_at ? LMS.fmtDateTime(attempt.submitted_at) : 'Not submitted';
        const title = options.compact ? 'Attempt review' : `Attempt #${attempt.attempt_id || ''} review`;
        const snapshotNote = items.some(item => item.snapshot_source && item.snapshot_source !== 'snapshot')
            ? '<p class="k-review-note">Some older attempts may use the current question wording or choices where earlier review details were not saved.</p>'
            : '';

        return `<section class="k-attempt-review" aria-label="Quiz attempt review">
          <div class="k-attempt-review__summary">
            <div>
              <h3>${LMS.escHtml(title)}</h3>
              <p>${LMS.escHtml(submitted)} • ${items.length} question${items.length === 1 ? '' : 's'}</p>
            </div>
            <span class="k-status ${pct === null ? 'k-status--warning' : pct >= 80 ? 'k-status--success' : pct >= 50 ? 'k-status--warning' : 'k-status--danger'}">${pct === null ? 'Pending' : `${pct}%`}</span>
            <span class="k-attempt-review__score">${score === null ? 'Awaiting grade' : `${score} / ${maxScore ?? '-'} pts`}</span>
          </div>
          ${snapshotNote}
          <div class="k-review-question-list">
            ${items.map((item, index) => {
                const status = reviewStatus(item);
                const explanation = String(item.answer_explanation || item.explanation || '').trim();
                return `<article class="k-review-question is-${status.key}">
                  <div class="k-review-question__head">
                    <span class="k-review-state k-review-state--${status.key}"><span aria-hidden="true">${status.icon}</span>${status.label}</span>
                    <span class="k-review-question__points">${item.score === null || item.score === undefined ? 'Pending' : `${item.score} / ${item.max_score ?? '-'} pts`}</span>
                  </div>
                  <p class="k-review-question__prompt"><strong>Question ${index + 1}.</strong> ${LMS.escHtml(item.prompt || item.question_text || '')}</p>
                  <div class="k-review-answer-grid">
                    <div><span class="k-review-label">Your answer</span><p>${LMS.escHtml(reviewText(item.selected_answer_text))}</p></div>
                    <div><span class="k-review-label">Correct answer</span><p>${LMS.escHtml(reviewText(item.correct_answer_text, 'No automatic answer key'))}</p></div>
                  </div>
                  ${renderReviewOptions(item)}
                  ${explanation ? `<aside class="k-answer-explanation"><span class="k-review-label">Explanation</span><p>${LMS.escHtml(explanation)}</p></aside>` : ''}
                </article>`;
            }).join('')}
          </div>
        </section>`;
    }

    async function loadAttemptReview(attemptId, target, options = {}) {
        if (!attemptId || !target) return;
        const endpoint = `./api/lms/quiz/attempt/get.php?attempt_id=${encodeURIComponent(attemptId)}`;
        const requestToken = `${Date.now()}-${Math.random().toString(36).slice(2)}`;
        target.dataset.reviewRequestToken = requestToken;
        if (target.dataset.reviewRequestToken !== requestToken) return;
        target.innerHTML = '<div class="k-skeleton" style="height:160px;border-radius:8px"></div>';
        const res = await LMS.api('GET', endpoint);
        logDebug({ endpoint, method: 'GET', response_status: res.status, response_body: res.data, parsed_error_message: res.error || null });
        if (target.dataset.reviewRequestToken !== requestToken) return;
        if (!res.ok) {
            target.innerHTML = `<div class="k-empty"><p class="k-empty__title">Review unavailable</p><p class="k-empty__desc">${LMS.escHtml(res.error || 'This attempt cannot be reviewed yet.')}</p></div>`;
            return;
        }
        target.innerHTML = renderAttemptReview(res.data?.data || res.data || {}, options);
    }

    function showResult(result) {
        showPanel('quizResultPanel');
        hideEl('quizStickyHeader');
        showEl('quizTopbar');

        const score = Number(result.score || 0);
        const maxScore = Number(result.max_score || 0);
        const pct = Number.isFinite(Number(result.score_pct))
            ? Number(result.score_pct)
            : (maxScore > 0 ? Math.round((score / maxScore) * 100) : 0);
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
        $('resultDesc') && ($('resultDesc').textContent = `You scored ${score} out of ${maxScore || 0} points.`);

        if (result.has_manual_grading) {
            showEl('manualPendingBanner');
        }

        // Per-question feedback
        const feedbackList = $('quizFeedbackList');
        if (feedbackList && result.feedback && result.feedback.length) {
            feedbackList.innerHTML = result.feedback.map((f, i) => {
                const cls = f.correct ? 'is-correct' : 'is-wrong';
                const icon = f.correct ? '✅' : '❌';
                return `<div class="k-question-card k-question-card--compact">
          <div class="k-question-card__head k-question-card__head--compact">
            <div class="k-question-card__num">Question ${i + 1} ${icon}</div>
            <p class="k-question-card__text">${LMS.escHtml(f.question_text || '')}</p>
          </div>
          ${f.explanation ? `<div class="k-question-card__body"><p class="k-text-meta-sm">${LMS.escHtml(f.explanation)}</p></div>` : ''}
        </div>`;
            }).join('');
        }
        if (feedbackList && result.attempt_id) {
            loadAttemptReview(result.attempt_id, feedbackList, { compact: true });
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
        <span class="k-attempt-row__date">${LMS.escHtml(a.submitted_at ? LMS.fmtDateTime(a.submitted_at) : LMS.fmtDateTime(a.started_at))}</span>
        <span class="k-status ${a.score_pct === null || a.score_pct === undefined ? 'k-status--warning' : a.score_pct >= 80 ? 'k-status--success' : a.score_pct >= 50 ? 'k-status--warning' : 'k-status--danger'}" aria-label="Score">
          ${a.score_pct === null || a.score_pct === undefined ? LMS.escHtml(a.status || 'Pending') : `${a.score_pct}%`}
        </span>
        <span class="k-attempt-meta">${a.score === null || a.score === undefined ? 'Awaiting grade' : `${a.score}/${a.max_score ?? '-'} pts`}</span>
        ${(a.submitted_at && a.status !== 'in_progress') ? `<button class="btn btn-ghost btn-sm" type="button" data-review-attempt="${a.attempt_id}">Review</button>` : ''}
      </div>`).join('');
        const detail = $('attemptReviewDetail');
        list.querySelectorAll('[data-review-attempt]').forEach((btn) => {
            btn.addEventListener('click', () => loadAttemptReview(btn.dataset.reviewAttempt, detail));
        });
    }

    async function addQuestion() {
        await Management.openQuestionEditor({}, {
            mode: 'create',
            onSubmit: async (payload) => {
                const res = await LMS.api('POST', './api/lms/quiz/question/create.php', {
                    assessment_id: Number(QUIZ_ID),
                    ...payload,
                });
                if (!res.ok) return res;
                LMS.toast('Question added.', 'success');
                await renderStaffPanel();
                return res;
            },
        });
    }

    async function renderStaffPanel() {
        if (!canManage) return;
        const intro = $('quizIntroPanel');
        if (!intro) return;
        const existingPanel = $('quizStaffPanel');
        if (existingPanel) existingPanel.remove();
        const panel = document.createElement('section');
        panel.id = 'quizStaffPanel';
        panel.className = 'k-card k-staff-panel';
        panel.innerHTML = `<h3 class="k-staff-panel__title">Staff Quiz Management</h3><div class="k-staff-panel__actions"><button class="btn btn-secondary btn-sm" id="staffAddQuestionBtn" type="button">+ Add Question</button><button class="btn btn-ghost btn-sm" id="staffEditQuizBtn" type="button">Edit Quiz</button><button class="btn btn-ghost btn-sm" id="staffPublishQuizBtn" type="button">Publish</button><button class="btn btn-ghost btn-sm" id="staffDraftQuizBtn" type="button">Move to Draft</button><button class="btn btn-ghost btn-sm${quizData?.module_linked ? '' : ' hidden'}" id="staffMandatoryBtn" type="button"></button><button class="btn btn-ghost btn-sm" id="staffLoadAttemptsBtn" type="button">Load Attempts</button><button class="btn btn-danger btn-sm" id="staffDeleteQuizBtn" type="button">Delete quiz</button></div><div id="staffQuestions" class="k-staff-panel__list"></div><div id="staffAttempts" class="k-staff-panel__list"></div>`;
        intro.appendChild(panel);

        const staffMandatoryBtn = $('staffMandatoryBtn');
        if (staffMandatoryBtn) {
            const requiredNow = Number(quizData?.required_flag || 0) === 1;
            staffMandatoryBtn.textContent = requiredNow ? 'Set Optional' : 'Set Mandatory';
        }

        $('staffAddQuestionBtn')?.addEventListener('click', addQuestion);
        $('staffEditQuizBtn')?.addEventListener('click', async () => {
            await Management.openQuizEditor(quizData, {
                mode: 'edit',
                onSubmit: async (payload) => {
                    const res = await LMS.api('POST', './api/lms/quiz/update.php', {
                        assessment_id: Number(QUIZ_ID),
                        ...payload,
                    });
                    if (!res.ok) return res;
                    LMS.toast('Quiz updated.', 'success');
                    await loadPage();
                    return res;
                },
            });
        });
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
            target.innerHTML = `<h4>Attempts / Submissions (${items.length})</h4><div class="k-staff-attempt-list">` + items.map((a) => `
                <div class="k-attempt-row">
                    <strong>${LMS.escHtml(a.student_name || 'Student')}</strong>
                    <span>${LMS.escHtml(a.status || 'In progress')}</span>
                    <span>${a.score === null ? 'Awaiting grade' : `${a.score} / ${a.max_score ?? '-'}`}</span>
                    ${(a.submitted_at && a.status !== 'in_progress') ? `<button class="btn btn-ghost btn-sm" type="button" data-staff-review-attempt="${a.attempt_id}">Review</button>` : ''}
                </div>`).join('') + '</div><div id="staffAttemptReview" class="k-staff-attempt-review"></div>';
            const detail = $('staffAttemptReview');
            target.querySelectorAll('[data-staff-review-attempt]').forEach((btn) => {
                btn.addEventListener('click', () => loadAttemptReview(btn.dataset.staffReviewAttempt, detail));
            });
        });
        $('staffDeleteQuizBtn')?.addEventListener('click', () => {
            LMS.confirm(
                'Delete quiz',
                'This archives the quiz, removes it from every module and active course list, and hides it from student and grading views. Existing attempts and grades remain stored for audit. A quiz with an in-progress attempt cannot be deleted.',
                async () => {
                    const res = await LMS.api('POST', './api/lms/quiz/delete.php', {
                        assessment_id: Number(QUIZ_ID),
                        course_id: Number(COURSE_ID),
                    });
                    if (!res.ok) {
                        LMS.toast(res.error || res.data?.error?.message || 'Quiz could not be deleted.', 'error');
                        return;
                    }
                    LMS.toast('Quiz deleted.', 'success');
                    window.location.assign(`./quizzes.html?course_id=${encodeURIComponent(COURSE_ID)}`);
                },
                { okLabel: 'Delete quiz', okClass: 'btn-danger' }
            );
        });

        const qRes = await LMS.api('GET', `./api/lms/quiz/question/list.php?assessment_id=${encodeURIComponent(QUIZ_ID)}`);
        const questions = qRes.ok ? (qRes.data?.data?.items || qRes.data?.items || []) : [];
        const wrap = $('staffQuestions');
        if (!wrap) return;
        wrap.innerHTML = `<h4>Questions (${questions.length})</h4>` + questions.map((q, idx) => `<div class="k-card k-card__body--compact k-panel-gap"><div><strong>Q${idx + 1}.</strong> ${LMS.escHtml(q.prompt || '')} (${LMS.escHtml(q.question_type || '')})${Number(q.is_required||0)===1 ? ' <span class="k-status k-status--warning">Required</span>' : ''}</div><div class="k-inline-actions k-inline-actions--compact k-list-spacer"><button class="btn btn-ghost btn-sm" data-act="move-up" data-id="${q.question_id}" ${idx===0?'disabled':''}>Move Up</button><button class="btn btn-ghost btn-sm" data-act="move-down" data-id="${q.question_id}" ${idx===questions.length-1?'disabled':''}>Move Down</button><button class="btn btn-ghost btn-sm" data-act="toggle-required" data-id="${q.question_id}" data-required="${Number(q.is_required||0)}">${Number(q.is_required||0)===1?'Set Optional':'Set Required'}</button><button class="btn btn-ghost btn-sm" data-act="edit" data-id="${q.question_id}">Edit</button> <button class="btn btn-ghost btn-sm" data-act="delete" data-id="${q.question_id}">Delete</button></div></div>`).join('');

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
            await Management.openQuestionEditor(question, {
                mode: 'edit',
                onSubmit: async (payload) => {
                    const res = await LMS.api('POST', './api/lms/quiz/question/update.php', {
                        question_id: id,
                        ...payload,
                    });
                    if (!res.ok) return res;
                    LMS.toast('Question updated.', 'success');
                    await renderStaffPanel();
                    return res;
                },
            });
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
        if (res.status === 404) {
            renderUnavailable();
            return;
        }
        if (!res.ok) {
            showPanel('quizError');
            $('quizRetryBtn') && $('quizRetryBtn').addEventListener('click', loadPage, { once: true });
            return;
        }

        quizData = res.data?.data || res.data || {};
        canManage = !!quizData.capabilities?.manage_course;
        if (URL_MODE === 'edit' && !canManage) {
            showPanel('quizAccessDenied');
            LMS.renderAccessDenied($('quizAccessDenied'), 'You do not have permission to edit this quiz.', `./modules.html?course_id=${encodeURIComponent(COURSE_ID)}`);
            return;
        }
        document.title = `${quizData.title || 'Quiz'} — Kairos`;
        const bc = $('kBreadCourse');
        if (bc) {
            bc.href = `./course.html?course_id=${encodeURIComponent(COURSE_ID)}`;
            bc.textContent = quizData.course_name || 'Course';
        }
        LMS.nav.setCourseContext(COURSE_ID, quizData.course_name || 'Course', quizData);
        LMS.nav.setActive('quizzes');
        $('quizStickyTitle') && ($('quizStickyTitle').textContent = quizData.title || 'Quiz');

        // Populate intro panel
        $('quizIntroTitle') && ($('quizIntroTitle').textContent = quizData.title || 'Quiz');
        const introDesc = $('quizIntroDesc');
        if (introDesc) {
            const instructions = quizData.instructions || quizData.description || '';
            introDesc.innerHTML = instructions
                ? LMS.sanitizeForRender(instructions)
                : '<p class="k-text-muted">No instructions provided.</p>';
        }
        $('metaQuestions') && ($('metaQuestions').textContent = quizData.question_count || quizData.total_questions || '?');
        $('metaTime') && ($('metaTime').textContent = quizData.time_limit_min ? quizData.time_limit_min + ' min' : (quizData.time_limit_minutes ? quizData.time_limit_minutes + ' min' : 'None'));
        $('metaAttempts') && ($('metaAttempts').textContent = quizData.attempts_used || 0);
        $('metaMax') && ($('metaMax').textContent = quizData.max_attempts ? quizData.max_attempts : '∞');

        // Configure attempt and preview actions.
        const startBtn = $('quizStartBtn');
        const studentAttemptBtn = $('quizStartStudentAttemptBtn');
        const attemptsUsed = Number(quizData.attempts_used || 0);
        const maxAttempts = Number(quizData.max_attempts || 0);
        const noAttempts = maxAttempts > 0 && attemptsUsed >= maxAttempts;
        const hasQuestions = quizQuestionCount() > 0;
        const canPreview = canPreviewQuiz();
        const canAttemptAsStudent = canTakeStudentAttempt();
        if (studentAttemptBtn) {
            studentAttemptBtn.onclick = null;
            studentAttemptBtn.classList.add('hidden');
            studentAttemptBtn.disabled = false;
            studentAttemptBtn.textContent = 'Start Student Attempt';
        }
        if (startBtn) {
            startBtn.onclick = null;
            if (canPreview) {
                startBtn.disabled = false;
                startBtn.textContent = 'Preview Quiz';
                startBtn.onclick = startPreview;
                if (studentAttemptBtn && canAttemptAsStudent) {
                    studentAttemptBtn.classList.remove('hidden');
                    if (!hasQuestions) {
                        studentAttemptBtn.disabled = false;
                        studentAttemptBtn.textContent = 'No questions yet';
                        studentAttemptBtn.onclick = renderNoQuestionsState;
                    } else if (noAttempts) {
                        studentAttemptBtn.disabled = true;
                        studentAttemptBtn.textContent = 'No student attempts remaining';
                    } else {
                        studentAttemptBtn.disabled = false;
                        studentAttemptBtn.textContent = 'Start Student Attempt';
                        studentAttemptBtn.onclick = startAttempt;
                    }
                }
            } else if (!hasQuestions) {
                startBtn.disabled = false;
                startBtn.textContent = 'No questions yet';
                startBtn.onclick = renderNoQuestionsState;
            } else if (noAttempts) {
                startBtn.disabled = true;
                startBtn.textContent = 'No attempts remaining';
            } else if (canAttemptAsStudent) {
                startBtn.disabled = false;
                startBtn.textContent = 'Start Attempt';
                startBtn.onclick = startAttempt;
            } else {
                startBtn.disabled = true;
                startBtn.textContent = 'Student participation required';
            }
        }

        const historyBtn = $('quizShowHistoryBtn');
        if (historyBtn) historyBtn.onclick = loadHistory;
        showPanel('quizIntroPanel');
        await renderStaffPanel();
        if (URL_MODE === 'preview' && canPreview) {
            await startPreview();
        }
    }

    function wireRealtime() {
        if (!window.LmsWS) return;
        ['quiz.updated', 'quiz.deleted'].forEach((eventName) => {
            LmsWS.on(eventName, (payload) => {
                if (!payload || typeof payload !== 'object') return;
                if (String(payload.course_id || '') !== String(COURSE_ID)) return;
                if (Number(payload.entity_id || 0) !== Number(QUIZ_ID)) return;
                if (attemptMode === 'student') {
                    LMS.toast('Quiz details changed. Your current attempt can continue normally.', 'info');
                    return;
                }
                loadPage();
            });
        });
    }

    async function loadAttemptQuestions() {
        const questionsEndpoint = `./api/lms/quiz/question/list.php?assessment_id=${encodeURIComponent(QUIZ_ID)}`;
        const qRes = await LMS.api('GET', questionsEndpoint);
        logDebug({ endpoint: questionsEndpoint, method: 'GET', response_status: qRes.status, response_body: qRes.data, parsed_error_message: qRes.error || null });
        if (!qRes.ok) {
            LMS.toast('Could not load quiz questions: ' + (qRes.error || 'Error'), 'error');
            return [];
        }
        return (qRes.data?.data?.items || qRes.data?.items || []).map((q) => ({
            id: Number(q.question_id || q.id || 0),
            text: q.prompt || q.text || '',
            type: q.question_type || q.type || 'mcq',
            options: Array.isArray(q.options) ? q.options : [],
            answer_key: q.answer_key ?? null,
            answer_explanation: q.answer_explanation || q.explanation || '',
            position: Number(q.position || 0),
            is_required: Number(q.is_required || 0) === 1,
        }));
    }

    function renderNoQuestionsState() {
        const target = $('quizError');
        if (!target) return;
        target.innerHTML = `<div class="k-empty"><div class="k-empty__icon" aria-hidden="true">⚡</div><p class="k-empty__title">No questions yet</p><p class="k-empty__desc">This quiz cannot be attempted until questions are added.</p><button class="btn btn-primary" type="button" id="quizEmptyBackBtn">Back to quiz</button></div>`;
        showPanel('quizError');
        $('quizEmptyBackBtn')?.addEventListener('click', () => showPanel('quizIntroPanel'), { once: true });
    }

    async function beginQuestionFlow(mode, attempt) {
        attemptMode = mode;
        attemptData = attempt;
        questions = await loadAttemptQuestions();
        answers = {};
        current = 0;
        isSubmitting = false;

        if (!questions.length) {
            attemptMode = 'idle';
            attemptData = null;
            renderNoQuestionsState();
            return;
        }

        showPanel('quizAttemptPanel');
        hideEl('quizTopbar');
        showEl('quizStickyHeader');
        $('quizStickyTitle') && ($('quizStickyTitle').textContent = `${quizData?.title || 'Quiz'}${mode === 'preview' ? ' Preview' : ''}`);

        if (mode === 'student' && quizData.time_limit_min) {
            startTimer(quizData.time_limit_min * 60);
        } else {
            stopTimer();
            $('quizTimer')?.classList.add('hidden');
        }

        updateDots();
        renderQuestion(0);
        wireAttemptNavigation();
    }

    async function startPreview() {
        if (!canPreviewQuiz()) {
            LMS.toast('Preview is not available for your course role.', 'error');
            return;
        }
        const startBtn = $('quizStartBtn');
        setButtonBusy(startBtn, true, 'Loading Preview…', 'Preview Quiz');
        try {
            await beginQuestionFlow('preview', { preview: true, attempt_id: null });
        } finally {
            setButtonBusy(startBtn, false, 'Loading Preview…', 'Preview Quiz');
        }
    }

    function endPreview() {
        stopTimer();
        attemptMode = 'idle';
        attemptData = null;
        questions = [];
        answers = {};
        isSubmitting = false;
        hideEl('quizStickyHeader');
        showEl('quizTopbar');
        showPanel('quizIntroPanel');
        LMS.toast('Preview ended. No answers were submitted.', 'success');
    }

    async function startAttempt() {
        if (quizQuestionCount() <= 0) {
            renderNoQuestionsState();
            return;
        }
        const endpoint = './api/lms/quiz/attempt.php';
        const startBtn = $('quizStartBtn');
        const studentAttemptBtn = $('quizStartStudentAttemptBtn');
        const startReadyLabel = startBtn?.textContent || 'Start Attempt';
        const studentReadyLabel = studentAttemptBtn?.textContent || 'Start Student Attempt';
        setButtonBusy(startBtn, true, 'Starting…', startReadyLabel);
        setButtonBusy(studentAttemptBtn, true, 'Starting…', studentReadyLabel);
        try {
            const res = await LMS.api('POST', endpoint, { assessment_id: Number(QUIZ_ID), course_id: Number(COURSE_ID) });
            logDebug({ endpoint, method: 'POST', response_status: res.status, response_body: res.data, parsed_error_message: res.error || null });
            if (!res.ok) {
                LMS.toast('Could not start quiz: ' + (res.error || 'Error'), 'error');
                return;
            }
            await beginQuestionFlow('student', res.data?.data || res.data || {});
        } finally {
            setButtonBusy(startBtn, false, 'Starting…', startReadyLabel);
            setButtonBusy(studentAttemptBtn, false, 'Starting…', studentReadyLabel);
        }
    }

    $('historyBackBtn') && $('historyBackBtn').addEventListener('click', () => showPanel('quizIntroPanel'));

    document.addEventListener('DOMContentLoaded', async () => {
        const session = await LMS.boot();
        if (!session) return;
        LMS.nav.updateUserBar(session.me);
        wireRealtime();
        await loadPage();
    });

})();
