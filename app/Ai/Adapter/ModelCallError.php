<?php

namespace App\Ai\Adapter;

use App\Services\Outbound\OutboundRequestFailedException;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Throwable;

/**
 * 模型调用失败的统一枚举（14 项）。
 *
 * 判定优先级：HTTP 状态码 → provider error.code/type 关键词 → 文本特征 → 兜底 Unknown。
 * 每个错误都提供中文可读文案，说明"谁的错 + 该改什么"。
 */
enum ModelCallError: string
{
    case ConfigurationInvalid = 'configuration_invalid';
    case AuthenticationFailed = 'authentication_failed';
    case QuotaExhausted = 'quota_exhausted';
    case RateLimited = 'rate_limited';
    case ContextLengthExceeded = 'context_length_exceeded';
    case OutputBudgetExhausted = 'output_budget_exhausted';
    case OutputTruncated = 'output_truncated';
    case UnsupportedCapability = 'unsupported_capability';
    case ContentFiltered = 'content_filtered';
    case ProviderTimeout = 'provider_timeout';
    case GatewayUnavailable = 'gateway_unavailable';
    case InvalidResponse = 'invalid_response';
    case InvalidStructuredOutput = 'invalid_structured_output';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::ConfigurationInvalid => '接入点配置无效',
            self::AuthenticationFailed => '模型鉴权失败',
            self::QuotaExhausted => '接入点额度用尽',
            self::RateLimited => '触发服务商限流',
            self::ContextLengthExceeded => '输入超出上下文上限',
            self::OutputBudgetExhausted => '输出预算不足',
            self::OutputTruncated => '模型输出被截断',
            self::UnsupportedCapability => '接入点不支持当前协议',
            self::ContentFiltered => '内容被服务商安全策略拦截',
            self::ProviderTimeout => '模型响应超时',
            self::GatewayUnavailable => '服务商网关不可用',
            self::InvalidResponse => '返回内容不是有效 JSON',
            self::InvalidStructuredOutput => '结构化输出不符合约定',
            self::Unknown => '模型调用失败',
        };
    }

    /**
     * 面向用户的中文文案，带修复动作。
     *
     * @param  array<string, mixed>  $context  可包含 retry_after / estimated_tokens / context_length / protocol / suggestion / max_tokens
     */
    public function message(array $context = []): string
    {
        $retryAfter = isset($context['retry_after']) ? (int) $context['retry_after'] : 0;

        return match ($this) {
            self::ConfigurationInvalid => '接入点配置无效：请检查 API Base URL、模型 ID 与协议声明是否匹配。',
            self::AuthenticationFailed => '模型鉴权失败：API Key 无效或已过期，请在「AI 模型」中更新密钥。',
            self::QuotaExhausted => '该接入点额度已用尽：请到服务商控制台充值或更换接入点。',
            self::RateLimited => $retryAfter > 0
                ? sprintf('触发服务商限流，将在 %d 秒后自动重试。', $retryAfter)
                : '触发服务商限流，请稍后重试或提高配额。',
            self::ContextLengthExceeded => isset($context['estimated_tokens'], $context['context_length'])
                ? sprintf(
                    '输入超出上下文上限（估计 %d tokens，上限 %d）：请减少证据条数或缩短文章。',
                    (int) $context['estimated_tokens'],
                    (int) $context['context_length'],
                )
                : '输入超出上下文上限：请减少证据条数或缩短文章。',
            self::OutputBudgetExhausted => '模型输出预算不足：请提高 max_tokens 或缩短输入。',
            self::OutputTruncated => isset($context['max_tokens'])
                ? sprintf('模型输出被截断（max_tokens=%d）：系统已自动提升上限重试一次。', (int) $context['max_tokens'])
                : '模型输出被截断：系统已自动提升 max_tokens 重试一次。',
            self::UnsupportedCapability => isset($context['suggestion'])
                ? sprintf(
                    '该接入点不支持当前协议：%s。请在接入点配置中改为 %s。',
                    (string) ($context['protocol'] ?? ''),
                    (string) $context['suggestion'],
                )
                : '该接入点不支持当前协议：请在接入点配置中调整协议声明。',
            self::ContentFiltered => '内容被服务商安全策略拦截：请调整提示词或待检测内容。',
            self::ProviderTimeout => '模型响应超时：请关闭思考模式或提高超时上限。',
            self::GatewayUnavailable => '服务商网关暂不可用：请稍后重试；若持续出现请检查网络与 Base URL。',
            self::InvalidResponse => '返回内容不是 JSON（疑似 HTML）：API Base URL 应填接口地址而非控制台/官网地址。',
            self::InvalidStructuredOutput => '模型未按约定返回结构化结果：请更换模型或降低结构化输出要求。',
            self::Unknown => '模型调用失败：请查看服务商返回的错误详情后重试。',
        };
    }

    public function retryable(): bool
    {
        return in_array($this, [
            self::ProviderTimeout,
            self::RateLimited,
            self::GatewayUnavailable,
            self::OutputTruncated,
            self::OutputBudgetExhausted,
            self::InvalidStructuredOutput,
        ], true);
    }

    /**
     * 兼容既有质检链路的错误码词汇，保证下游告警/熔断口径不变。
     */
    public function legacyQualityCode(): string
    {
        return match ($this) {
            self::AuthenticationFailed => 'provider_authentication_failed',
            self::QuotaExhausted => 'provider_quota_exhausted',
            self::RateLimited => 'provider_rate_limited',
            self::ProviderTimeout => 'provider_timeout',
            self::GatewayUnavailable => 'provider_gateway_error',
            self::OutputBudgetExhausted => 'output_budget_exhausted',
            self::OutputTruncated => 'model_output_truncated',
            self::InvalidStructuredOutput => 'structured_output_unsupported',
            default => 'provider_error',
        };
    }

    /**
     * 从 HTTP 状态码与 provider 返回文本归一化（embedding / 直连 HTTP 路径使用）。
     */
    public static function fromStatus(int $status, string $providerMessage = '', ?int $retryAfter = null): self
    {
        $normalized = mb_strtolower($providerMessage, 'UTF-8');

        return match (true) {
            $status === 401, $status === 403 => self::AuthenticationFailed,
            $status === 402 => self::QuotaExhausted,
            $status === 429 => self::RateLimited,
            $status === 413 => self::ContextLengthExceeded,
            $status === 404 => self::ConfigurationInvalid,
            $status === 422 => self::ConfigurationInvalid,
            in_array($status, [500, 502, 503, 504], true) => self::GatewayUnavailable,
            $status === 400 && str_contains($normalized, 'does not support this api') => self::UnsupportedCapability,
            $status === 400 && (str_contains($normalized, 'context length')
                || str_contains($normalized, 'maximum context')
                || str_contains($normalized, 'too many tokens')) => self::ContextLengthExceeded,
            $status === 400 => self::ConfigurationInvalid,
            default => $retryAfter !== null ? self::RateLimited : self::Unknown,
        };
    }

    public static function fromThrowable(Throwable $exception, string $baseUrl = ''): self
    {
        return self::diagnose($exception, $baseUrl)['error'];
    }

    /**
     * 一次遍历得到归一化错误与原始属性，供质检链复用状态码/provider code/retry_after。
     *
     * @return array{error:self,status:?int,provider_code:?string,retry_after:?int}
     */
    public static function diagnose(Throwable $exception, string $baseUrl = ''): array
    {
        if ($exception instanceof ModelCallException) {
            return [
                'error' => $exception->error,
                'status' => $exception->httpStatus,
                'provider_code' => $exception->providerCode,
                'retry_after' => $exception->retryAfterSeconds,
            ];
        }

        [$status, $providerCode, $normalized, $types] = self::failureContext($exception, $baseUrl);

        return [
            'error' => self::map($status, $providerCode, $normalized, $types),
            'status' => $status,
            'provider_code' => $providerCode,
            'retry_after' => OutboundRequestFailedException::retryAfterSeconds($exception),
        ];
    }

    private static function map(?int $status, ?string $providerCode, string $normalized, string $types): self
    {
        return match (true) {
            $status === 402, str_contains($types, 'providercategory:quota_exhausted') => self::QuotaExhausted,
            $status === 401, $status === 403, str_contains($normalized, 'auth'), str_contains($normalized, 'api key') => self::AuthenticationFailed,
            $status === 429, str_contains($types, 'transportcategory:rate_limited'), str_contains($normalized, 'rate limit') => self::RateLimited,
            str_contains($types, 'transportcategory:timeout'), str_contains($types, 'timeoutexception'),
            str_contains($normalized, 'timeout'), str_contains($normalized, 'timed out') => self::ProviderTimeout,
            in_array($status, [502, 503, 504], true),
            str_contains($types, 'transportcategory:gateway'),
            str_contains($types, 'transportcategory:connection'),
            str_contains($types, 'transportcategory:dns'),
            str_contains($types, 'transportcategory:tls'),
            str_contains($types, 'connectexception'),
            str_contains($normalized, 'gateway') => self::GatewayUnavailable,
            str_contains($normalized, 'quota'), str_contains($normalized, 'credit') => self::QuotaExhausted,
            str_contains($normalized, 'does not support this api') => self::UnsupportedCapability,
            str_contains($normalized, 'maximum output token'),
            str_contains($normalized, 'max output token'),
            str_contains($normalized, 'output token limit'),
            str_contains($normalized, 'output budget') => self::OutputBudgetExhausted,
            str_contains($normalized, 'truncated'),
            str_contains($normalized, 'finish reason: length'),
            str_contains($normalized, 'finish_reason=length') => self::OutputTruncated,
            str_contains($normalized, 'structured'), str_contains($normalized, 'schema') => self::InvalidStructuredOutput,
            str_contains($normalized, 'context length'), str_contains($normalized, 'maximum context') => self::ContextLengthExceeded,
            str_contains($normalized, 'content filter'), str_contains($normalized, 'content_filter') => self::ContentFiltered,
            str_contains($normalized, 'non json'), str_contains($normalized, 'non-json'),
            str_contains($normalized, 'html') => self::InvalidResponse,
            is_string($providerCode) && $providerCode !== '' && in_array($status, [400, 404, 422], true) => self::ConfigurationInvalid,
            default => self::Unknown,
        };
    }

    /**
     * @return array{?int,?string,string,string}
     */
    private static function failureContext(Throwable $exception, string $baseUrl): array
    {
        $status = null;
        $providerCode = null;
        $messages = [];
        $types = [];
        $current = $exception;
        for ($depth = 0; $depth < 8 && $current instanceof Throwable; $depth++) {
            $types[] = strtolower($current::class);
            if ($current instanceof OutboundRequestFailedException) {
                if (is_string($current->causeType)) {
                    $types[] = strtolower($current->causeType);
                }
                $types[] = 'transportcategory:'.$current->transportCategory;
                $types[] = 'providercategory:'.$current->providerCategory;
            }
            $message = trim(OpenAiRuntimeProvider::normalizeApiException($current, $baseUrl));
            if ($message !== '') {
                $messages[] = $message;
            }
            $response = null;
            if (isset($current->response) && is_object($current->response)) {
                $response = $current->response;
            } elseif (method_exists($current, 'getResponse')) {
                $candidate = $current->getResponse();
                $response = is_object($candidate) ? $candidate : null;
            }
            $candidateStatus = $current instanceof OutboundRequestFailedException ? $current->httpStatus : null;
            if ($status === null && is_int($candidateStatus) && $candidateStatus >= 100 && $candidateStatus <= 599) {
                $status = $candidateStatus;
            }
            if ($status === null && $current->getCode() >= 100 && $current->getCode() <= 599) {
                $status = (int) $current->getCode();
            }
            if ($status === null && $response !== null) {
                if (method_exists($response, 'status')) {
                    $candidateStatus = $response->status();
                    $status = is_int($candidateStatus) ? $candidateStatus : null;
                } elseif (method_exists($response, 'getStatusCode')) {
                    $candidateStatus = $response->getStatusCode();
                    $status = is_int($candidateStatus) ? $candidateStatus : null;
                }
            }
            $candidateProviderCode = $current instanceof OutboundRequestFailedException ? $current->providerCode : null;
            if ($candidateProviderCode === null && $response !== null && method_exists($response, 'json')) {
                $payload = $response->json();
                $error = is_array($payload) && is_array($payload['error'] ?? null) ? $payload['error'] : [];
                $candidate = $error['code'] ?? $error['type'] ?? null;
                $candidateProviderCode = is_string($candidate) && preg_match('/^[A-Za-z0-9_.:-]{1,80}$/', $candidate) === 1
                    ? $candidate
                    : null;
            }
            if ($providerCode === null && is_string($candidateProviderCode) && $candidateProviderCode !== '') {
                $providerCode = $candidateProviderCode;
            }
            $current = $current->getPrevious();
        }

        return [$status, $providerCode, strtolower(implode("\n", $messages)), implode("\n", $types)];
    }
}
