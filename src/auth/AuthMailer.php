<?php
declare(strict_types=1);

namespace Kairos\Auth;

interface AuthMailer
{
    public function send(string $to, string $subject, string $textBody): bool;
}
