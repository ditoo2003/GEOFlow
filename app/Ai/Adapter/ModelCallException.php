<?php

namespace App\Ai\Adapter;

use App\Exceptions\ArticleAiQualityRuntimeException;
use RuntimeException;
use Throwable;

/**
 * 携带归一化错误、HTTP 状态与限流等待时间的模型调用异常。
 *
 * 继承 {@see RuntimeException}，现有捕获 `\RuntimeException` 的调用点无需改动。
 */
final class ModelCallException extends RuntimeException
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public readonly ModelCallError $error,
        public readonly ?int $httpStatus = null,
        public readonly ?string $providerCode = null,
        public readonly ?int $retryAfterSeconds = null,
        array $context = [],
        ?Throwable $previous = null,
        public readonly ?string $providerDetail = null,
    ) {
        $detail = trim((string) $providerDetail);
        if (mb_strlen($detail) > 300) {
            $detail = mb_substr($detail, 0, 300).'…';
        }

        parent::__construct(
            $error->message([...$context, 'retry_after' => $retryAfterSeconds])
                .($detail !== '' ? '（服务商返回：'.$detail.'）' : ''),
            0,
            $previous,
        );
    }

    public static function fromThrowable(Throwable $exception, string $baseUrl = ''): self
    {
        if ($exception instanceof self || $exception instanceof ArticleAiQualityRuntimeException) {
            return new self(
                $exception instanceof self ? $exception->error : ModelCallError::fromThrowable($exception, $baseUrl),
                previous: $exception,
            );
        }

        return new self(
            ModelCallError::fromThrowable($exception, $baseUrl),
            previous: $exception,
        );
    }
}
