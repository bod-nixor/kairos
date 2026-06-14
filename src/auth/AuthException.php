<?php
declare(strict_types=1);

namespace Kairos\Auth;

final class AuthException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        public readonly string $publicMessage,
    ) {
        parent::__construct($publicMessage);
    }
}
