import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = relative => fs.readFileSync(path.join(root, relative), 'utf8');

const managementSource = read('public/js/lms-management-ui.js');
const context = {
  window: {
    KairosLMS: {
      escHtml: value => String(value ?? ''),
      sanitizeForRender: value => String(value ?? ''),
      richTextToPlainText: value => String(value ?? ''),
    },
  },
  Set,
};
vm.createContext(context);
vm.runInContext(managementSource, context);
const management = context.window.KairosLMSManagement;

const documents = management.resolveExtensions(new Set(['documents']), new Set(), {}).extensions;
assert.deepEqual(Array.from(documents), ['pdf', 'doc', 'docx', 'txt', 'rtf', 'odt']);

const combined = management.resolveExtensions(
  new Set(['documents', 'pdf', 'spreadsheets']),
  new Set(['PDF', '.json']),
  {},
).extensions;
assert.equal(combined.filter(ext => ext === 'pdf').length, 1, 'preset overlap must deduplicate PDF');
assert.ok(combined.includes('xlsx'));
assert.ok(combined.includes('json'));

const custom = management.normalizeExtensions('.JSON, Md;json', {});
assert.deepEqual(Array.from(custom.extensions), ['json', 'md']);
assert.deepEqual(Array.from(custom.errors), []);

for (const dangerous of ['svg', 'html', 'js', 'php']) {
  const result = management.normalizeExtensions(dangerous, {});
  assert.equal(result.extensions.length, 0);
  assert.match(result.errors.join(' '), /blocked/);
}

const restoredDocuments = management.classifyExtensions('pdf,doc,docx,txt,rtf,odt', {});
assert.ok(restoredDocuments.groups.includes('documents'));
assert.deepEqual(Array.from(restoredDocuments.custom), []);

const restoredCustom = management.classifyExtensions('pdf,json', {});
assert.ok(restoredCustom.groups.includes('pdf'));
assert.deepEqual(Array.from(restoredCustom.custom), ['json']);
assert.equal(management.extensionsToAccept('PDF, .docx, json', {}), '.pdf,.docx,.json');

const core = read('public/js/lms-core.js');
assert.match(core, /function sanitizeForRender/);
assert.match(core, /dropContentTags[\s\S]*'script'/);
assert.match(core, /name\.startsWith\((['"])on\1\)/, 'sanitizeForRender must check and remove event-handler attributes');
assert.match(core, /allowedTags/, 'sanitizer allowlist contract must exist');
assert.match(core, /\['http:', 'https:', 'mailto:'\]/);
assert.match(core, /getEmbedDescriptor/);

const assignments = read('public/js/assignments.js');
const assignment = read('public/js/assignment.js');
const quizzes = read('public/js/quizzes.js');
const quiz = read('public/js/quiz.js');
assert.match(assignments, /LMS\.richTextExcerpt/);
assert.doesNotMatch(assignments, /escHtml\(item\.instructions|escHtml\(item\.description/);
assert.match(assignment, /desc\.innerHTML = LMS\.sanitizeForRender/);
assert.match(assignment, /No description provided/);
assert.match(assignment, /fileInput\.accept = Management\.extensionsToAccept/);
assert.match(quizzes, /k-lms-content-card/);
assert.match(quiz, /Management\.openQuizEditor/);
assert.match(quiz, /Management\.openQuestionEditor/);
assert.match(quiz, /startBtn\.onclick = startAttempt/);
assert.match(quiz, /historyBtn\.onclick = loadHistory/);
assert.doesNotMatch(quiz, /ensureQuestionEditorModal|options \(comma-separated/i);

const css = read('public/css/kairos-ui.css');
for (const selector of [
  '.k-lms-card-grid',
  '.k-lms-content-card',
  '.k-management-form',
  '.k-preset-grid',
  '.k-preset-chip',
  '.k-upload-rule-card',
]) {
  assert.ok(css.includes(selector), `missing polished LMS selector ${selector}`);
}
assert.match(css, /@media \(max-width: 760px\)[\s\S]*\.k-form-grid[\s\S]*grid-template-columns:\s*1fr/);

for (const page of ['assignments.html', 'assignment.html', 'quizzes.html', 'quiz.html']) {
  const html = read(`templates/pages/${page}`);
  if (page.includes('assignment') || page.includes('quiz')) {
    assert.match(html, /lms-management-ui\.js/);
  }
}

console.log('LMS management UI contract tests passed');
