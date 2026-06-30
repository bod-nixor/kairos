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

for (const symbol of [
  'function optionValue',
  'function requiredMissingQuestions',
  'function beginQuestionFlow',
  'function startPreview',
  'function startAttempt',
  'function quizQuestionCount',
  'function renderNoQuestionsState',
]) {
  assert.ok(quizJs.includes(symbol), `missing quiz controller symbol: ${symbol}`);
}

assert.ok(
  quizJs.includes("'value'") && quizJs.includes("'id'") && quizJs.includes('hasOwn'),
  'option value normalization must check explicit option keys'
);
assert.doesNotMatch(quizJs, /opt\.value\s*\|\|\s*opt\.id\s*\|\|/, 'option value 0 must not be lost to truthy fallback logic');
assert.ok(quizJs.includes('for="${inputId}"') && quizJs.includes('id="${inputId}"'), 'option labels must be associated with their inputs');
assert.ok(quizJs.includes("addEventListener('change'"), 'choice selection should be driven by native input change events');
assert.ok(quizJs.includes('answers[q.id]'), 'selected answer state should be keyed by question id');
assert.ok(quizJs.includes('beginQuestionFlow') && quizJs.includes("'preview'") && quizJs.includes("'student'"), 'preview and student attempt flows should remain separate');
assert.ok(quizJs.includes('studentAttemptBtn') && quizJs.includes('startAttempt'), 'student attempts for staff must remain explicit');
assert.ok(quizJs.includes('attemptMode') && quizJs.includes('current attempt can continue normally'), 'realtime updates must not interrupt active attempts');
assert.ok(quizJs.includes('quizQuestionCount() <= 0'), 'startAttempt must defensively block empty quizzes before creating attempts');
assert.ok(quizJs.includes('No questions yet'), 'empty quizzes should route to the no-questions fallback');

for (const selector of ['.k-quiz-wrap', '.k-question-card__text', '.k-option__label', '.k-quiz-nav', '.k-quiz-dot']) {
  assert.ok(lmsCss.includes(selector), `missing quiz layout selector ${selector}`);
}
assert.match(lmsCss, /\.k-question-card__text\s*\{[\s\S]*overflow-wrap:\s*anywhere/, 'long question text must wrap');
assert.match(lmsCss, /\.k-option__label\s*\{[\s\S]*overflow-wrap:\s*anywhere/, 'long option text must wrap');
assert.ok(lmsCss.includes('grid-template-columns: auto minmax(0, 1fr) auto'), 'desktop quiz nav should keep controls predictable');
assert.ok(lmsCss.includes('grid-template-columns: 1fr 1fr'), 'mobile quiz nav should wrap controls');
assert.ok(lmsCss.includes('.k-page-body[data-quiz-page] .k-realtime-status'), 'quiz pages should move realtime status away from controls');
assert.doesNotMatch(lmsCss, /\.k-option input\[type="radio"\],[\s\S]*?clip:\s*rect/, 'option inputs should not use deprecated clip hiding');
assert.match(shellCss, /\.k-realtime-status\s*\{[\s\S]*pointer-events:\s*none/, 'realtime status must not block quiz interaction');

console.log('quiz UI contract tests passed');
