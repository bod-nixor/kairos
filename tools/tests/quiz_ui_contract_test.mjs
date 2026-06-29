import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '../..');
const read = (relativePath) => fs.readFileSync(path.join(root, relativePath), 'utf8');

const quizJs = read('public/js/quiz.js');
const quizHtml = read('templates/pages/quiz.html');
const lmsCss = read('public/css/lms.css');
const shellCss = read('public/css/kairos-ui.css');

assert.match(quizHtml, /data-quiz-page/, 'quiz page should expose page-scoped realtime/layout hooks');
assert.match(quizHtml, /id="quizStartStudentAttemptBtn"/, 'staff who are enrolled need an explicit student attempt action');
assert.match(quizHtml, /id="quizPreviewBanner"/, 'preview mode must be visibly separate from real attempts');

assert.match(quizJs, /function optionValue/, 'option values should be normalized in one place');
assert.match(quizJs, /hasOwn\(opt,\s*'value'\)/, 'option value normalization must not use truthy fallbacks');
assert.doesNotMatch(quizJs, /opt\.value\s*\|\|\s*opt\.id\s*\|\|/, 'option value 0 must not be lost to truthy fallback logic');
assert.match(quizJs, /for="\$\{inputId\}"/, 'option labels must be associated with their inputs');
assert.match(quizJs, /id="\$\{inputId\}" type="radio"/, 'radio inputs need stable ids for label wiring');
assert.match(quizJs, /input\.addEventListener\('change'/, 'radio selection should be driven by native input change events');
assert.match(quizJs, /answers\[q\.id\] = value/, 'selected MCQ option must persist in attempt state');
assert.match(quizJs, /String\(saved \?\? ''\) === val/, 'rendering must rehydrate selected MCQ state when navigating back');
assert.match(quizJs, /checkboxes\.forEach\(input => input\.addEventListener\('change'/, 'multi-select should use checkbox change events');
assert.match(quizJs, /requiredMissingQuestions/, 'submission must validate required questions before calling the API');

assert.match(quizJs, /function startPreview/, 'staff preview mode must be a first-class controller path');
assert.match(quizJs, /beginQuestionFlow\('preview'/, 'preview mode must load questions without creating an attempt');
assert.match(quizJs, /beginQuestionFlow\('student'/, 'student attempts must use the durable attempt flow');
assert.match(quizJs, /startBtn\.textContent = 'Preview Quiz'/, 'staff primary action should be preview');
assert.match(quizJs, /studentAttemptBtn\.onclick = startAttempt/, 'student attempt for staff must be explicit');
assert.match(quizJs, /attemptMode === 'student'[\s\S]*Your current attempt can continue normally/, 'realtime updates must not interrupt active attempts');

for (const selector of ['.k-quiz-wrap', '.k-question-card__text', '.k-option__label', '.k-quiz-nav', '.k-quiz-dot']) {
  assert.ok(lmsCss.includes(selector), `missing quiz layout selector ${selector}`);
}
assert.match(lmsCss, /\.k-question-card__text\s*\{[\s\S]*overflow-wrap:\s*anywhere/, 'long question text must wrap');
assert.match(lmsCss, /\.k-option__label\s*\{[\s\S]*overflow-wrap:\s*anywhere/, 'long option text must wrap');
assert.match(lmsCss, /\.k-quiz-nav\s*\{[\s\S]*grid-template-columns:\s*auto minmax\(0,\s*1fr\) auto/, 'desktop quiz nav should keep controls predictable');
assert.match(lmsCss, /@media \(max-width: 640px\)[\s\S]*\.k-quiz-nav\s*\{[\s\S]*grid-template-columns:\s*1fr 1fr/, 'mobile quiz nav should wrap controls');
assert.match(lmsCss, /\.k-page-body\[data-quiz-page\] \.k-realtime-status/, 'quiz pages should move realtime status away from controls');
assert.match(shellCss, /\.k-realtime-status\s*\{[\s\S]*pointer-events:\s*none/, 'realtime status must not block quiz interaction');

console.log('quiz UI contract tests passed');
