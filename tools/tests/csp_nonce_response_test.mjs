import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import net from 'node:net';

const listener = net.createServer();
listener.listen(0, '127.0.0.1');
await once(listener, 'listening');
const address = listener.address();
assert.ok(address && typeof address === 'object');
const port = address.port;
listener.close();
await once(listener, 'close');

const php = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', 'public'], {
  cwd: new URL('../..', import.meta.url),
  env: {
    ...process.env,
    ALLOWED_DOMAIN: 'nixorcollege.edu.pk',
    APP_DEBUG: 'false',
  },
  stdio: ['ignore', 'ignore', 'pipe'],
});

let serverErrors = '';
php.stderr.setEncoding('utf8');
php.stderr.on('data', (chunk) => {
  serverErrors += chunk;
});

const endpoint = `http://127.0.0.1:${port}/html.php?page=index`;
const escapeRegExp = (value) => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

async function waitForServer() {
  for (let attempt = 0; attempt < 50; attempt += 1) {
    try {
      const response = await fetch(endpoint);
      if (response.ok) return;
    } catch {
      // The PHP development server may still be binding.
    }
    await new Promise((resolve) => setTimeout(resolve, 50));
  }
  throw new Error(`PHP server did not start.\n${serverErrors}`);
}

function responseNonce(response, html) {
  const policy = response.headers.get('content-security-policy') || '';
  const match = policy.match(/'nonce-([A-Za-z0-9+/]+={0,2})'/);
  assert.ok(match, 'CSP header must contain a nonce source');
  const nonce = match[1];
  const escapedNonce = escapeRegExp(nonce);

  const scriptSrc = policy.split(';').find((directive) => directive.trim().startsWith('script-src ')) || '';
  const scriptSrcElem = policy.split(';').find((directive) => directive.trim().startsWith('script-src-elem ')) || '';
  assert.match(scriptSrc, new RegExp(`'nonce-${escapedNonce}'`));
  assert.match(scriptSrcElem, new RegExp(`'nonce-${escapedNonce}'`));
  assert.match(policy, /script-src-attr 'none'/);
  assert.doesNotMatch(policy, /script-src(?:-elem)?\s+[^;]*'unsafe-inline'/);
  assert.match(policy, /https:\/\/accounts\.google\.com/);
  assert.doesNotMatch(policy, /static\.cloudflareinsights\.com/);

  const inlineTags = Array.from(
    html.matchAll(/<script\b(?![^>]*\bsrc=)[^>]*>/gi),
    (entry) => entry[0],
  );
  assert.ok(inlineTags.length > 0, 'rendered HTML should contain the pre-paint inline theme script');
  for (const tag of inlineTags) {
    assert.match(tag, new RegExp(`\\bnonce="${escapedNonce}"`));
  }

  const externalTags = Array.from(
    html.matchAll(/<script\b[^>]*\bsrc=[^>]*>/gi),
    (entry) => entry[0],
  );
  for (const tag of externalTags) {
    assert.doesNotMatch(tag, /\bnonce=/i, 'external application scripts should rely on the source allowlist');
  }

  return nonce;
}

try {
  await waitForServer();

  const first = await fetch(endpoint);
  const firstHtml = await first.text();
  const second = await fetch(endpoint);
  const secondHtml = await second.text();

  assert.equal(first.status, 200);
  assert.equal(second.status, 200);
  assert.match(first.headers.get('cache-control') || '', /\bno-store\b/);
  assert.match(second.headers.get('cache-control') || '', /\bno-store\b/);

  const firstNonce = responseNonce(first, firstHtml);
  const secondNonce = responseNonce(second, secondHtml);
  assert.notEqual(firstNonce, secondNonce, 'separate HTML requests must receive different nonces');
  assert.doesNotMatch(firstHtml, /\{\{CSP_NONCE\}\}/);
  assert.doesNotMatch(secondHtml, /\{\{CSP_NONCE\}\}/);
} finally {
  php.kill('SIGTERM');
  await Promise.race([
    once(php, 'exit'),
    new Promise((resolve) => setTimeout(resolve, 1000)),
  ]);
}

console.log('CSP nonce response tests passed');
