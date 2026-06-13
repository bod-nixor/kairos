import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = relative => fs.readFileSync(path.join(root, relative), 'utf8');

const core = read('public/js/lms-core.js');
for (const label of ['Home', 'Modules', 'Quizzes', 'Assignments', 'Grading', 'Analytics']) {
  assert.match(core, new RegExp(`label: '${label}'`), `canonical course nav is missing ${label}`);
}
assert.match(core, /capability: 'grade_course'/);
assert.match(core, /capability: 'manage_course'/);
assert.match(core, /courseBread\.href = `\.\/course\.html\?course_id=/);
assert.match(core, /modulesBread\.href = `\.\/modules\.html\?course_id=/);
assert.match(core, /aria-current="page"/);
assert.match(core, /profile picture/);

const modules = read('public/js/modules.js');
assert.match(modules, /<button type="button" class="k-module__toggle"/);
assert.match(modules, /aria-expanded="/);
assert.match(modules, /<a class="k-module-item__link" href="/);
assert.doesNotMatch(modules, /role="button"/);
assert.doesNotMatch(modules, /querySelectorAll\('\.k-module__header'\)\.forEach/);
assert.doesNotMatch(modules, /const editBtn = e\.target\.closest\('\[data-action="edit-item"\]'\)/);
assert.match(modules, /container\.addEventListener\('click'/);
assert.match(modules, /course\.capabilities\?\.manage_course/);

const lmsCss = read('public/css/lms.css');
assert.match(lmsCss, /\.k-module__toggle\s*\{[\s\S]*min-height:\s*52px/);
assert.match(lmsCss, /\.k-module-item__link\s*\{[\s\S]*min-height:\s*52px/);
assert.match(lmsCss, /@media \(max-width: 640px\)[\s\S]*\.k-module__header/);
assert.doesNotMatch(lmsCss, /var\(--focus-ring\)(?!,)/);
assert.match(lmsCss, /var\(--focus-ring,\s*var\(--primary\)\)/);

const theme = read('public/js/theme.js');
const uiCss = read('public/css/kairos-ui.css');
assert.match(theme, /k-settings-fab__icon/);
assert.match(uiCss, /\.k-settings-fab\s*\{[\s\S]*display:\s*inline-flex/);
assert.match(uiCss, /\.k-settings-fab\s*\{[\s\S]*align-items:\s*center/);
assert.match(uiCss, /\.k-settings-fab\s*\{[\s\S]*justify-content:\s*center/);
assert.match(uiCss, /\.k-settings-fab__icon\s*\{[\s\S]*line-height:\s*1/);

const course = read('public/js/course.js');
assert.match(course, /course\.capabilities\?\.manage_course_announcements/);
assert.match(course, /data-ann-action="edit"/);
assert.match(course, /data-ann-action="delete"/);
assert.match(course, /LMS\.confirm\(\s*'Delete Announcement'/);
assert.match(course, /announcement\.updated/);
assert.match(course, /announcement\.deleted/);

const grading = read('public/js/grading.js');
const analytics = read('public/js/analytics.js');
assert.match(grading, /course\.capabilities\?\.grade_course/);
assert.match(grading, /if \(!courseRes\.ok\)/);
assert.match(grading, /Unable to load course/);
assert.match(analytics, /course\.capabilities\?\.manage_course/);

const quizzes = read('public/js/quizzes.js');
assert.doesNotMatch(quizzes, /resolveCourseRoleFlags/);

const coursePages = [
  'course.html',
  'modules.html',
  'lesson.html',
  'resource-viewer.html',
  'quizzes.html',
  'quiz.html',
  'assignments.html',
  'assignment.html',
  'grading.html',
  'analytics.html',
];
for (const page of coursePages) {
  const html = read(`templates/pages/${page}`);
  assert.match(html, /id="kNavCourse"/, `${page} must expose the authoritative course nav mount`);
  assert.match(html, /id="kSidebarName"/, `${page} must retain the profile identity block`);
}

for (const script of ['lesson.js', 'resource-viewer.js', 'quiz.js', 'assignment.js']) {
  const source = read(`public/js/${script}`);
  assert.doesNotMatch(source, /kNav(?:Grading|Analytics)'\)\?\.classList\.remove/, `${script} must not override capability navigation`);
  assert.match(source, /setCourseContext\([^;]+,\s*[^;]+,\s*[^;)]+\)/, `${script} must pass capability context to shared navigation`);
}

console.log('course UI contract tests passed');
