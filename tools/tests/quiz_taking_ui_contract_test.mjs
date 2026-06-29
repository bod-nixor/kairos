import fs from 'node:fs';

const js = fs.readFileSync('public/js/quiz.js', 'utf8');
const css = fs.readFileSync('public/css/lms.css', 'utf8');
const shellCss = fs.readFileSync('public/css/kairos-ui.css', 'utf8');
const template = fs.readFileSync('templates/pages/quiz.html', 'utf8');

const failed = [];
const assert = (condition, message) => { if (!condition) failed.push(message); };

assert(js.includes('setSingleChoiceAnswer(area, q.id, opt.dataset.val)'), 'MCQ option card clicks should update selected answer state');
assert(js.includes('for="${LMS.escHtml(inputId)}"'), 'answer labels should be wired to generated input ids');
assert(js.includes('role="radio"') && js.includes('aria-checked'), 'MCQ options should expose radio state semantics');
assert(js.includes("event.key === ' ' || event.key === 'Enter'"), 'answer cards should support keyboard selection');
assert(js.includes('setMultiChoiceAnswer(area, q.id, opt.dataset.val'), 'multiple-select card clicks should update checkbox answer state');
assert(js.includes('submitBtn.disabled = !attemptData || previewMode'), 'submit should be disabled outside real attempts and preview mode');
assert(js.includes('Preview mode: no student attempt was created.'), 'staff preview should be clearly separated from student attempts');
assert(js.includes("endpoint = './api/lms/quiz/attempt.php'") && js.includes("endpoint = './api/lms/quiz/attempt/submit.php'"), 'student start and submit endpoints should remain explicit');

assert(css.includes('overflow-wrap: anywhere'), 'quiz question/options layout should wrap long text');
assert(css.includes('width: min(100%, 860px)') && css.includes('width: min(100%, 820px)'), 'quiz container and question card should be centered with responsive max widths');
assert(css.includes('.k-option:hover,\n.k-option:focus-visible'), 'options should have hover and focus-visible states');
assert(css.includes('@media (max-width: 760px)') && css.includes('.k-quiz-nav .btn'), 'quiz navigation should have mobile layout rules');
assert(template.includes('k-quiz-page'), 'quiz page should have a page class for scoped realtime/status adjustments');
assert(shellCss.includes('body.k-quiz-page .k-realtime-status') && shellCss.includes('pointer-events: none'), 'quiz realtime status should be non-blocking');

if (failed.length) {
  console.error(failed.join('\n'));
  process.exit(1);
}

console.log('quiz taking UI contract tests passed');
