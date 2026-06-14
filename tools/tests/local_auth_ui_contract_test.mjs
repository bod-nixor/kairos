import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');

const login = read('templates/pages/index.html');
assert.match(login, /id="googleBtn"/);
assert.match(login, /id="passwordLoginForm"/);
assert.match(login, /Google is the primary login and the only public registration method/);
assert.match(login, /For accounts created by a Kairos administrator/);
assert.doesNotMatch(login, /sign up with password|create password account/i);

for (const page of ['activate.html', 'forgot-password.html', 'reset-password.html']) {
  const html = read(`templates/pages/${page}`);
  assert.match(html, /class="k-layout"/);
  assert.match(html, /class="k-sidebar hidden"/);
  assert.match(html, /class="k-topbar hidden"/);
  assert.match(html, /class="k-main"/);
  assert.match(html, /auth-client\.js/);
  assert.match(html, /auth-pages\.js/);
}

const admin = read('templates/pages/admin.html');
const localForm = admin.match(/<form id="localAccountForm"[\s\S]*?<\/form>/)?.[0] || '';
assert.ok(localForm, 'admin local account form must render');
assert.doesNotMatch(localForm, /type="password"|name="password"/);
assert.match(localForm, /Create account and send activation/);

const settings = read('templates/pages/settings.html');
assert.match(settings, /id="changePasswordForm"/);
assert.match(settings, /id="startGoogleLinkBtn"/);
assert.match(read('public/js/settings.js'), /For account security, it cannot currently be removed/);

const adminEndpoint = read('public/api/admin/local_accounts.php');
assert.match(adminEndpoint, /require_role_or_higher\(\$pdo, \$user, 'admin'\)/);
assert.match(adminEndpoint, /createLocalAccount/);

const linkEndpoint = read('public/api/auth/google_link_complete.php');
assert.match(linkEndpoint, /require_login\(\)/);
assert.match(linkEndpoint, /GoogleIdentityVerifier/);

const authService = read('src/auth/AuthService.php');
assert.match(authService, /array_key_exists\('password', \$input\)/);
assert.match(authService, /google_link_state/);
assert.match(authService, /password_needs_rehash|needsRehash/);

const authBootstrap = read('src/auth/bootstrap.php');
assert.match(authBootstrap, /'actor_user_id'\s*=>\s*\$actorUserId/);

const localAuthMigration = read('db/migrations/20260614_2100_add_local_authentication.sql');
assert.match(localAuthMigration, /INFORMATION_SCHEMA\.COLUMNS/);
assert.match(localAuthMigration, /START TRANSACTION;[\s\S]*UPDATE users[\s\S]*UPDATE users[\s\S]*COMMIT;/);

console.log('local auth UI and endpoint contract tests passed');
