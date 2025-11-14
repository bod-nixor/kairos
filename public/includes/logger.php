<?php
declare(strict_types=1);

/**
 * Minimal debug logger gated by the KAIROS_DEBUG environment variable.
 * Using a helper keeps access-denied diagnostics consistent across endpoints
 * without exposing details in production when the flag is off.
 */
function kairos_debug_log(string $message, array $context = []): void
{
    static $enabled = null;
    if ($enabled === null) {
        $raw = getenv('KAIROS_DEBUG');
        $enabled = is_string($raw) && $raw !== '' && !in_array(strtolower($raw), ['0', 'false', 'off', 'no'], true);
    }

    if (!$enabled) {
        return;
    }

    $payload = $message;
    if ($context) {
        try {
            $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if ($json !== false) {
                $payload .= ' ' . $json;
            }
        } catch (Throwable $e) {
            // Ignore encoding failures – logging must never break the request.
        }
    }

    error_log('[kairos-debug] ' . $payload);
}
