# CSP Nonce Apache Routing

Kairos HTML is rendered dynamically so every response receives a cryptographically random CSP nonce. Page templates live under `templates/pages/` and must never be served directly.

## Required Request Flow

For repository-root deployments:

1. Keep protected-path rules first and include `templates`.
2. Keep WebSocket and emit proxy rules before application rewrites.
3. Keep `/api/*` mapped to `public/api/*`.
4. Route only known HTML page names to `public/html.php?page=<name>`.
5. Keep the generic `public/` fallback last.

The required HTML rules are:

```apache
RewriteRule ^$ public/html.php?page=index [END,QSA]
RewriteRule ^(admin|analytics|assignment|assignments|course|grading|index|lesson|manager|modules|projector|quiz|quizzes|resource-viewer|room|settings|ta)(?:\.html)?/?$ public/html.php?page=$1 [END,QSA]
```

For deployments where `public/` is the Apache document root, use:

```apache
RewriteRule ^$ html.php?page=index [END,QSA]
RewriteRule ^(admin|analytics|assignment|assignments|course|grading|index|lesson|manager|modules|projector|quiz|quizzes|resource-viewer|room|settings|ta)(?:\.html)?/?$ html.php?page=$1 [END,QSA]
```

Do not restore a static `Content-Security-Policy` header in either `.htaccess` file. `public/html.php` emits the HTML CSP after generating a fresh nonce with `random_bytes()` and replaces only explicit `{{CSP_NONCE}}` placeholders in approved inline scripts.

## Unaffected Routes

The HTML regex is an explicit allowlist. It must not match:

- `api/`
- `assets/`, `css/`, `js/`, or `images/`
- `websocket/socket.io/`, `ws`, or `emit`
- `vendor/`, Composer files, or other protected repository paths

Keep `Options -MultiViews`; content negotiation must not bypass the PHP HTML responder by resolving a page name to a template or stale file.

## Cloudflare Requirements

- Keep Bot Fight Mode enabled.
- Keep JavaScript Detections enabled.
- Keep HTML CSP in the HTTP response header, not a `<meta>` element.
- Do not cache HTML responses. Honor `Cache-Control: private, no-store`.
- Keep `'self'` in script directives so `/cdn-cgi/challenge-platform/` remains eligible.
- Do not add `unsafe-inline` or `static.cloudflareinsights.com`.

Cloudflare documents that JavaScript Detections copies a nonce parsed from the CSP response header onto injected scripts: [Bot Fight Mode limitations](https://developers.cloudflare.com/bots/get-started/bot-fight-mode/#javascript-detections).

## Verification

Request the same page twice and compare both headers and bodies:

```bash
curl -sS -D /tmp/kairos-1.headers -o /tmp/kairos-1.html https://kairos.nixorcorporate.com/signoff/
curl -sS -D /tmp/kairos-2.headers -o /tmp/kairos-2.html https://kairos.nixorcorporate.com/signoff/
grep -oi "'nonce-[^']*'" /tmp/kairos-1.headers /tmp/kairos-2.headers
grep -o 'nonce="[^"]*"' /tmp/kairos-1.html /tmp/kairos-2.html
```

The nonce within one response must match its rendered inline script. The nonce across the two responses must differ.

Then verify representative non-HTML routes:

```bash
curl -sSI https://kairos.nixorcorporate.com/signoff/assets/vendor/socket.io/4.7.5/socket.io.min.js
curl -sSI https://kairos.nixorcorporate.com/signoff/api/config.php
curl -sS "https://kairos.nixorcorporate.com/signoff/websocket/socket.io/?EIO=4&transport=polling"
curl -sSI https://kairos.nixorcorporate.com/signoff/templates/pages/index.html
```

Expected results: the asset and API retain their normal content types, Socket.IO reaches the realtime service, and the template path returns 403/404.
