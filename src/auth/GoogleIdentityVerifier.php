<?php
declare(strict_types=1);

namespace Kairos\Auth;

final class GoogleIdentityVerifier
{
    public function verify(string $idToken, string $clientId, string $allowedDomain): array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            throw new AuthException('google_auth_failed', 401, 'Authentication failed.');
        }
        [$headerPart, $payloadPart, $signaturePart] = $parts;
        $header = json_decode($this->base64UrlDecode($headerPart), true);
        $payload = json_decode($this->base64UrlDecode($payloadPart), true);
        $signature = $this->base64UrlDecode($signaturePart);
        if (!is_array($header) || !is_array($payload) || empty($header['kid']) || ($header['alg'] ?? '') !== 'RS256') {
            throw new AuthException('google_auth_failed', 401, 'Authentication failed.');
        }

        $now = time();
        if (($payload['aud'] ?? '') !== $clientId) {
            throw new AuthException('google_auth_failed', 401, 'Authentication failed.');
        }
        if (!in_array($payload['iss'] ?? '', ['https://accounts.google.com', 'accounts.google.com'], true)) {
            throw new AuthException('google_auth_failed', 401, 'Authentication failed.');
        }
        if (!is_numeric($payload['exp'] ?? null) || (int)$payload['exp'] < $now) {
            throw new AuthException('google_auth_failed', 401, 'Authentication failed.');
        }
        if (!is_numeric($payload['iat'] ?? null)) {
            throw new AuthException('google_auth_failed', 401, 'Authentication failed.');
        }
        $issuedAt = (int)$payload['iat'];
        if ($issuedAt > $now + 120 || $issuedAt < $now - 86400) {
            throw new AuthException('google_auth_failed', 401, 'Authentication failed.');
        }
        if (($payload['email_verified'] ?? false) !== true && ($payload['email_verified'] ?? '') !== 'true') {
            throw new AuthException('google_auth_failed', 401, 'Authentication failed.');
        }

        $email = strtolower(trim((string)($payload['email'] ?? '')));
        $emailDomain = strtolower(substr(strrchr($email, '@') ?: '', 1));
        $hostedDomain = strtolower(trim((string)($payload['hd'] ?? '')));
        if (
            !filter_var($email, FILTER_VALIDATE_EMAIL)
            || $emailDomain !== strtolower($allowedDomain)
            || $hostedDomain !== strtolower($allowedDomain)
        ) {
            throw new AuthException('google_auth_failed', 401, 'Authentication failed.');
        }

        $key = null;
        foreach ($this->certificates()['keys'] ?? [] as $candidate) {
            if (($candidate['kid'] ?? '') === $header['kid']) {
                $key = $candidate;
                break;
            }
        }
        if (!is_array($key) || ($key['kty'] ?? '') !== 'RSA' || empty($key['n']) || empty($key['e'])) {
            throw new AuthException('google_auth_failed', 401, 'Authentication failed.');
        }
        $publicKey = $this->rsaPublicKey(
            $this->base64UrlDecode((string)$key['n']),
            $this->base64UrlDecode((string)$key['e'])
        );
        $verified = openssl_verify(
            $headerPart . '.' . $payloadPart,
            $signature,
            $publicKey,
            OPENSSL_ALGO_SHA256
        );
        if ($verified !== 1) {
            throw new AuthException('google_auth_failed', 401, 'Authentication failed.');
        }
        return $payload;
    }

    private function certificates(): array
    {
        static $cache = null;
        static $expiresAt = 0;
        if (is_array($cache) && time() < $expiresAt) {
            return $cache;
        }
        $handle = curl_init('https://www.googleapis.com/oauth2/v3/certs');
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if (!is_string($body) || $body === '' || $status !== 200) {
            throw new AuthException('google_auth_unavailable', 503, 'Google sign-in is temporarily unavailable.');
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || empty($decoded['keys'])) {
            throw new AuthException('google_auth_unavailable', 503, 'Google sign-in is temporarily unavailable.');
        }
        $cache = $decoded;
        $expiresAt = time() + 300;
        return $cache;
    }

    private function base64UrlDecode(string $value): string
    {
        $value = strtr($value, '-_', '+/');
        $padding = strlen($value) % 4;
        if ($padding) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            throw new AuthException('google_auth_failed', 401, 'Authentication failed.');
        }
        return $decoded;
    }

    private function rsaPublicKey(string $modulus, string $exponent): string
    {
        $algorithm = $this->asn1Sequence(
            $this->asn1Tlv("\x06", "\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01"),
            "\x05\x00"
        );
        $rsa = $this->asn1Sequence($this->asn1Integer($modulus), $this->asn1Integer($exponent));
        $sequence = $this->asn1Sequence($algorithm, $this->asn1Tlv("\x03", "\x00" . $rsa));
        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($sequence), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private function asn1Sequence(string ...$parts): string
    {
        return $this->asn1Tlv("\x30", implode('', $parts));
    }

    private function asn1Integer(string $value): string
    {
        if ($value === '') {
            $value = "\x00";
        }
        if (ord($value[0]) > 0x7f) {
            $value = "\x00" . $value;
        }
        return $this->asn1Tlv("\x02", $value);
    }

    private function asn1Tlv(string $type, string $value): string
    {
        return $type . $this->asn1Length(strlen($value)) . $value;
    }

    private function asn1Length(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }
        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xff) . $bytes;
            $length >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }
}
