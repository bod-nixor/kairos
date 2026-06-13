import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = (relativePath) => fs.readFileSync(path.join(root, relativePath), 'utf8');
const findHtmlFiles = (directory) => fs.readdirSync(directory, { withFileTypes: true })
  .flatMap((entry) => {
    const absolutePath = path.join(directory, entry.name);
    if (entry.isDirectory()) return findHtmlFiles(absolutePath);
    if (!entry.isFile() || !entry.name.endsWith('.html')) return [];
    return [path.relative(root, absolutePath).split(path.sep).join('/')];
  });

const htmlFiles = findHtmlFiles(path.join(root, 'templates/pages')).sort();
// The projector is a purpose-built fullscreen display, not a navigable app page.
const shellExemptHtmlFiles = new Set([
  'templates/pages/projector.html',
]);
const hasClass = (html, className) => new RegExp(
  `class=["'][^"']*\\b${className}\\b[^"']*["']`,
  'i',
).test(html);

for (const htmlFile of htmlFiles) {
  const html = read(htmlFile);
  assert.doesNotMatch(html, /cdn\.socket\.io/i, `${htmlFile} must not use the Socket.IO CDN`);
  const scripts = html.match(/<script\b[^>]*>/gi) || [];
  for (const script of scripts) {
    assert.match(script, /\bdata-cfasync="false"/i, `${htmlFile} has a script without Rocket Loader exclusion`);
  }
  for (const match of html.matchAll(/<script\b(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/gi)) {
    assert.match(match[0], /\bnonce="\{\{CSP_NONCE\}\}"/, `${htmlFile} has an inline script without the nonce placeholder`);
  }
  for (const match of html.matchAll(/<script\b[^>]*\bsrc=[^>]*>/gi)) {
    assert.doesNotMatch(match[0], /\bnonce=/i, `${htmlFile} should not nonce external scripts`);
  }
  if (!shellExemptHtmlFiles.has(htmlFile)) {
    for (const className of ['k-layout', 'k-sidebar', 'k-topbar', 'k-main']) {
      assert.ok(hasClass(html, className), `${htmlFile} must use the shared .${className} wrapper`);
    }
  }
}

const realtimePages = [
  'index.html',
  'course.html',
  'room.html',
  'ta.html',
  'manager.html',
  'modules.html',
  'quiz.html',
  'assignment.html',
  'grading.html',
  'analytics.html',
  'projector.html',
];
for (const page of realtimePages) {
  assert.match(
    read(`templates/pages/${page}`),
    /vendor\/socket\.io\/4\.7\.5\/socket\.io\.min\.js/,
    `${page} must load the repository-controlled Socket.IO client`,
  );
}

const vendorClient = path.join(root, 'public/assets/vendor/socket.io/4.7.5/socket.io.min.js');
assert.equal(fs.existsSync(vendorClient), true, 'vendored Socket.IO client is missing');
assert.ok(fs.statSync(vendorClient).size > 10000, 'vendored Socket.IO client looks incomplete');

const htmlResponse = read('src/html_response.php');
assert.match(htmlResponse, /random_bytes\(24\)/);
assert.match(htmlResponse, /script-src \{\$scriptSources\}/);
assert.match(htmlResponse, /script-src-elem \{\$scriptSources\}/);
assert.match(htmlResponse, /script-src-attr 'none'/);
assert.match(htmlResponse, /style-src-elem[^"]*https:\/\/accounts\.google\.com/);
assert.match(htmlResponse, /connect-src[^"]*wss:\/\/kairos\.nixorcorporate\.com/);
assert.doesNotMatch(htmlResponse, /static\.cloudflareinsights\.com/);
assert.doesNotMatch(htmlResponse, /play\.google\.com/);
assert.match(htmlResponse, /object-src 'none'/);
assert.doesNotMatch(htmlResponse, /script-src(?:-elem)?[^"]*'unsafe-inline'/);

for (const htaccessFile of ['.htaccess', 'public/.htaccess']) {
  const apacheConfig = read(htaccessFile);
  assert.doesNotMatch(apacheConfig, /Header always set Content-Security-Policy/);
  assert.match(apacheConfig, /html\.php\?page=/);
}
const rootApache = read('.htaccess');
assert.match(rootApache, /\|templates\|/);
assert.match(rootApache, /RewriteRule \^composer\\\.\(json\|lock\)\$/);
assert.match(rootApache, /RewriteRule \^api\/\(\.\*\)\$ public\/api\/\$1 \[L\]/);
assert.match(rootApache, /RewriteRule \^ws\$ ws:\/\/127\.0\.0\.1:8090\/ws \[P,L\]/);
assert.match(rootApache, /RewriteRule \^emit\$ http:\/\/127\.0\.0\.1:8090\/emit \[P,L\]/);
assert.ok(
  rootApache.indexOf('RewriteRule ^api/(.*)$') < rootApache.indexOf('public/html.php?page=index'),
  'API routing must remain before HTML routing',
);
assert.ok(
  rootApache.indexOf('public/html.php?page=index') < rootApache.indexOf('RewriteRule ^(.*)$ public/$1'),
  'HTML routing must remain before the generic public fallback',
);

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
  for (const label of ['Home', 'Modules', 'Quizzes', 'Assignments']) {
    assert.ok(html.includes(`>${label}<`), `${page} is missing the ${label} course navigation item`);
  }
  assert.match(html, /class="k-sidebar"/, `${page} must use the shared sidebar`);
  assert.match(html, /class="k-topbar"/, `${page} must use the shared topbar`);
  assert.match(html, /class="k-main"/, `${page} must use the shared main wrapper`);
}

const themeCss = read('public/css/style.css');
for (const theme of ['light', 'dark', 'midnight', 'graphite', 'indigo', 'emerald']) {
  assert.ok(themeCss.includes(`[data-theme="${theme}"]`), `missing ${theme} theme tokens`);
}

const uiCss = read('public/css/kairos-ui.css');
assert.match(uiCss, /prefers-reduced-motion:\s*reduce/);
assert.match(uiCss, /\.k-realtime-status/);
assert.match(uiCss, /:focus-visible/);
assert.match(uiCss, /@media \(max-width: 640px\)/);

const lmsCommon = read('public/api/lms/_common.php');
assert.match(lmsCommon, /rbac_can_manage_course/);
assert.match(lmsCommon, /rbac_can_act_as_ta/);
assert.doesNotMatch(lmsCommon, /in_array\(\$role, \['admin', 'manager'\], true\)\s*\{\s*return;/);

const driveClient = read('public/api/lms/drive_client.php');
assert.match(driveClient, /storage_unavailable/);
assert.doesNotMatch(driveClient, /'stub_'\s*\./, 'uploads must not return synthetic Drive IDs');
assert.match(driveClient, /GOOGLE_DRIVE_CREDENTIALS_PATH/);
assert.match(driveClient, /GOOGLE_DRIVE_WRITES_ENABLED/);
assert.match(driveClient, /lms_drive_download_integrity_ok/);

const driveStorage = read('public/api/lms/integrations/drive/GoogleDriveStorage.php');
assert.match(driveStorage, /MediaFileUpload/);
assert.match(driveStorage, /supportsAllDrives/);
assert.match(driveStorage, /includeItemsFromAllDrives/);
assert.match(driveStorage, /assertRemoteBytes/);
assert.doesNotMatch(driveStorage, /permissions->create|createPermission/, 'managed files must remain private');

const driveDownload = read('public/api/lms/resources/download.php');
assert.match(driveDownload, /lms_authorize_resource_access/);
assert.match(driveDownload, /resource_id/);
assert.doesNotMatch(driveDownload, /\$_GET\['file_id'\]/);

const submissionList = read('public/api/lms/assignments/submissions.php');
assert.match(submissionList, /\$hasDriveFile\s*=\s*\$row\['drive_file_id'\]\s*!==\s*null/);
assert.match(submissionList, /'download_url'\s*=>\s*\$hasDriveFile/);
assert.match(submissionList, /'preview_url'\s*=>\s*\$hasDriveFile\s*&&\s*lms_drive_inline_allowed/);

const composer = JSON.parse(read('composer.json'));
assert.match(composer.require['google/apiclient'], /^\^2\./);
assert.equal(composer.require['google/apiclient-services'], '0.444.0');

for (const endpoint of ['public/api/queue_participants.php', 'public/api/queue_eta.php']) {
  const source = read(endpoint);
  assert.match(source, /rbac_queue_scope/);
  assert.match(source, /rbac_can_view_queue/);
}

const websocketServer = read('ws_server.py');
assert.match(websocketServer, /_user_can_access_course/);
assert.match(websocketServer, /realtime subscription denied/);
assert.match(websocketServer, /_emit_scoped_event/);
assert.match(websocketServer, /COURSE_ACCESS_MAPPINGS/);
assert.match(websocketServer, /if mapping_key not in COURSE_ACCESS_MAPPINGS/);

console.log('production contract tests passed');
