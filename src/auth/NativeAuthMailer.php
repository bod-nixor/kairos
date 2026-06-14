<?php
declare(strict_types=1);

namespace Kairos\Auth;

final class NativeAuthMailer implements AuthMailer
{
    public function send(string $to, string $subject, string $textBody): bool
    {
        $fromAddress = trim((string)\env('MAIL_FROM_ADDRESS', ''));
        $fromName = trim((string)\env('MAIL_FROM_NAME', 'Kairos'));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $safeName = str_replace(["\r", "\n"], '', $fromName);
        $safeSubject = str_replace(["\r", "\n"], '', $subject);
        $headers = [
            'From: ' . ($safeName !== '' ? $safeName . ' ' : '') . '<' . $fromAddress . '>',
            'Content-Type: text/plain; charset=UTF-8',
            'MIME-Version: 1.0',
        ];
        return mail($to, $safeSubject, $textBody, implode("\r\n", $headers), '-f ' . escapeshellarg($fromAddress));
    }
}
