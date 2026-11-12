<?php

namespace App\Services\Registration;

use DomainException;

/**
 * 注册链路中可预期的失败（链接失效、邮箱已注册、限流等），由控制器转成页面提示。
 */
final class RegistrationException extends DomainException
{
    public const INVALID = 'invalid';

    public const EXPIRED = 'expired';

    public const USED = 'used';

    public const EMAIL_TAKEN = 'email_taken';

    public const RATE_LIMITED = 'rate_limited';

    public const TURNSTILE_FAILED = 'turnstile_failed';

    public const DISPOSABLE_EMAIL = 'disposable_email';

    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
