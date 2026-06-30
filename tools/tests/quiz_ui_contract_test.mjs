import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '../..');
const read = (relativePath) => fs.readFileSync(path.join(root, relativePath), 'utf8');

const quizJs = read('public/js/quiz.js');
const quizHtml = read('templates/pages/quiz.html');
const lmsCss = read('public/css/lms.css');
const shellCss = read('public/css/kairos-ui.css');

function extractFunctionSource(source, functionName) {
  const marker = `function ${functionName}`;
  const start = source.indexOf(marker);
  assert.notEqual(start, -1, `missing quiz controller symbol: ${marker}`);
  const bodyStart = source.indexOf('{', start);
  assert.notEqual(bodyStart, -1, `missing ${functionName} function body`);
  let depth = 0;
  for (let i = bodyStart; i < source.length; i += 1) {
    if (source[i] === '{') depth += 1;
    if (source[i] === '}') depth -= 1;
    if (depth === 0) return source.slice(start, i + 1);
  }
  throw new Error(`unterminated ${functionName} function body`);
}

assert.match(quizHtml, /data-quiz-page/, 'quiz page should expose page-scoped realtime/layout hooks');
assert.match(quizHtml, /id="quizStartStudentAttemptBtn"/, 'staff who are enrolled need an explicit student attempt action');
assert.match(quizHtml, /id="quizPreviewBanner"/, 'preview mode must be visibly separate from real attempts');
assert.match(quizHtml, /id="attemptReviewDetail"/, 'attempt history should have a dedicated review detail target');

for (const symbol of [
  'function optionValue',
  'function formatAnswerText',
  'function appendPreviewAnswerInfo',
  'function requiredMissingQuestions',
  'function renderAttemptReview',
  'function loadAttemptReview',
  'function beginQuestionFlow',
  'function startPreview',
  'function startAttempt',
  'function quizQuestionCount',
  'function renderNoQuestionsState',
]) {
  assert.ok(quizJs.includes(symbol), `missing quiz controller symbol: ${symbol}`);
}

const optionValueSource = extractFunctionSource(quizJs, 'optionValue');
assert.match(optionValueSource, /hasOwn\(\s*opt\s*,\s*'value'\s*\)/, 'optionValue must check the explicit value key');
assert.match(optionValueSource, /hasOwn\(\s*opt\s*,\s*'id'\s*\)/, 'optionValue must check the explicit id key');
assert.match(optionValueSource, /opt\.value\s*!==\s*null\s*&&\s*opt\.value\s*!==\s*undefined/, 'optionValue must preserve value 0');
assert.match(optionValueSource, /opt\.id\s*!==\s*null\s*&&\s*opt\.id\s*!==\s*undefined/, 'optionValue must preserve id 0');
assert.doesNotMatch(optionValueSource, /opt\.value\s*\|\|\s*opt\.id\s*\|\|/, 'option value 0 must not be lost to truthy fallback logic');
assert.ok(quizJs.includes('for="${inputId}"') && quizJs.includes('id="${inputId}"'), 'option labels must be associated with their inputs');
assert.ok(quizJs.includes("addEventListener('change'"), 'choice selection should be driven by native input change events');
assert.ok(quizJs.includes('answers[q.id]'), 'selected answer state should be keyed by question id');
assert.ok(quizJs.includes('beginQuestionFlow') && quizJs.includes("'preview'") && quizJs.includes("'student'"), 'preview and student attempt flows should remain separate');
assert.ok(quizJs.includes('studentAttemptBtn') && quizJs.includes('startAttempt'), 'student attempts for staff must remain explicit');
assert.ok(quizJs.includes('attemptMode') && quizJs.includes('current attempt can continue normally'), 'realtime updates must not interrupt active attempts');
assert.ok(quizJs.includes('quizQuestionCount() <= 0'), 'startAttempt must defensively block empty quizzes before creating attempts');
assert.ok(quizJs.includes('No questions yet'), 'empty quizzes should route to the no-questions fallback');
assert.ok(quizJs.includes('Correct answer') && quizJs.includes('answer_explanation'), 'completed review and staff preview should render correct answers and explanations');
assert.ok(quizJs.includes("./api/lms/quiz/attempt/get.php?attempt_id="), 'attempt review should use the completed-attempt detail endpoint');

for (const selector of ['.k-quiz-wrap', '.k-question-card__text', '.k-option__label', '.k-quiz-nav', '.k-quiz-dot', '.k-attempt-review', '.k-review-question__prompt', '.k-review-option__text']) {
  assert.ok(lmsCss.includes(selector), `missing quiz layout selector ${selector}`);
}
assert.match(lmsCss, /\.k-question-card__text\s*\{[\s\S]*overflow-wrap:\s*anywhere/, 'long question text must wrap');
assert.match(lmsCss, /\.k-option__label\s*\{[\s\S]*overflow-wrap:\s*anywhere/, 'long option text must wrap');
assert.match(lmsCss, /\.k-review-question__prompt\s*\{[\s\S]*overflow-wrap:\s*anywhere/, 'long review prompts must wrap');
assert.match(lmsCss, /\.k-review-option__text\s*\{[\s\S]*overflow-wrap:\s*anywhere/, 'long review option text must wrap');
assert.ok(lmsCss.includes('grid-template-columns: auto minmax(0, 1fr) auto'), 'desktop quiz nav should keep controls predictable');
assert.ok(lmsCss.includes('grid-template-columns: 1fr 1fr'), 'mobile quiz nav should wrap controls');
assert.ok(lmsCss.includes('.k-page-body[data-quiz-page] .k-realtime-status'), 'quiz pages should move realtime status away from controls');
const optionInputRule = lmsCss.match(/\.k-option input\[type="radio"\]\s*,\s*\.k-option input\[type="checkbox"\]\s*\{[\s\S]*?\}/);
assert.ok(optionInputRule, 'radio and checkbox option inputs should share the visually hidden rule');
assert.doesNotMatch(optionInputRule[0], /clip:\s*rect/, 'option inputs should not use deprecated clip hiding');
assert.match(shellCss, /\.k-realtime-status\s*\{[\s\S]*pointer-events:\s*none/, 'realtime status must not block quiz interaction');

console.log('quiz UI contract tests passed');
