<?php
// Kill any isolation headers that might be injected upstream
header_remove('Cross-Origin-Opener-Policy');
header_remove('Cross-Origin-Embedder-Policy');
header_remove('Cross-Origin-Resource-Policy');

// Optional: confirm in DevTools -> Network -> document headers
// header('Cross-Origin-Opener-Policy-Report-Only: same-origin-allow-popups');

// Serve the SPA html
readfile(__DIR__ . '/index.html');