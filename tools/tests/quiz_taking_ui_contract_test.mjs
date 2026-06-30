import fs from 'node:fs';

const js = fs.readFileSync('public/js/quiz.js', 'utf8');
const css = fs.readFileSync('public/css/lms.css', 'utf8');
const shellCss = fs.readFileSync('public/css/kairos-ui.css', 'utf8');
const template = fs.readFileSync('templates/pages/quiz.html', 'utf8');

const failed = [];
const assert = (condition, message) => { if (!condition) failed.push(message); };

assert(js.includes('answers[q.id] = value'), 'MCQ native input changes should update selected answer state');
assert(js.includes('for="${inputId}"') && js.includes('id="${inputId}"'), 'answer labels should be wired to generated input ids');
assert(js.includes('role="radiogroup"') && js.includes('type="radio"'), 'MCQ options should expose native radio semantics');
assert(js.includes("addEventListener('change'"), 'answer cards should use native input change events');
assert(js.includes('answers[q.id] = vals'), 'multiple-select native input changes should update checkbox answer state');
assert(js.includes("isPreviewMode() ? 'End Preview'") && js.includes('if (!attemptData?.attempt_id)'), 'submit should remain unavailable outside real attempts and preview mode should not submit');
assert(js.includes('Preview ended. No answers were submitted.'), 'staff preview should be clearly separated from student attempts');
assert(js.includes("const endpoint = './api/lms/quiz/attempt.php'") && js.includes("const endpoint = './api/lms/quiz/attempt/submit.php'"), 'student start and submit endpoints should remain explicit');
assert(js.includes('loadAttemptReview(result.attempt_id') && js.includes('./api/lms/quiz/attempt/get.php?attempt_id='), 'completed attempts should load review details after submission');

assert(css.includes('overflow-wrap: anywhere'), 'quiz question/options layout should wrap long text');
assert(css.includes('width: min(100%, 860px)') && css.includes('max-width: 820px'), 'quiz container and question card should be centered with responsive max widths');
assert(css.includes('.k-option:hover') && css.includes('.k-option:focus-within'), 'options should have hover and focus states');
assert(css.includes('@media (max-width: 640px)') && css.includes('.k-quiz-nav .btn'), 'quiz navigation should have mobile layout rules');
assert(css.includes('.k-review-answer-grid') && css.includes('.k-review-option__text'), 'attempt review should have responsive answer/explanation styles');
assert(template.includes('k-quiz-page'), 'quiz page should have a page class for scoped realtime/status adjustments');
assert(template.includes('attemptReviewDetail'), 'attempt history should include a review detail mount');
assert(shellCss.includes('body.k-quiz-page .k-realtime-status') && shellCss.includes('pointer-events: none'), 'quiz realtime status should be non-blocking');

if (failed.length) {
  console.error(failed.join('\n'));
  process.exit(1);
}

console.log('quiz taking UI contract tests passed');
