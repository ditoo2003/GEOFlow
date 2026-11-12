<?php

namespace App\Services\Admin;

use App\Models\AiModelUsageEvent;
use App\Tenancy\TenantContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AiModelUsageRecorder
{
    private const IDEMPOTENCY_CONFLICT = 'ai_usage_event_idempotency_conflict';

    private const ALLOWED_FIELDS = [
        'call_key',
        'operation',
        'business_source',
        'source_type',
        'source_id',
        'status',
        'error_code',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'estimated_cost',
        'duration_ms',
        'protocol',
        'thinking_mode',
        'max_tokens_applied',
        'attempt',
    ];

    /** @param array<string, mixed> $payload */
    public function record(AiModelUsageAccessSnapshot $accessSnapshot, array $payload): AiModelUsageEvent
    {
        $whitelisted = Arr::only($payload, self::ALLOWED_FIELDS);
        if (is_int($whitelisted['source_id'] ?? null)) {
            $whitelisted['source_id'] = (string) $whitelisted['source_id'];
        }

        $attributes = Validator::make($whitelisted, [
            'call_key' => ['required', 'string', 'max:100', 'regex:/\A[a-z0-9_.:-]+\z/i'],
            'operation' => ['required', 'string', 'max:100', 'regex:/\A[a-z0-9_.:-]+\z/i'],
            'business_source' => ['required', 'string', 'max:80', 'regex:/\A[a-z0-9_.:-]+\z/i'],
            'source_type' => [
                'nullable',
                'string',
                'max:255',
                'required_with:source_id',
                'regex:/\A[A-Za-z0-9_.:\\\\-]+\z/',
            ],
            'source_id' => [
                'nullable',
                'string',
                'max:120',
                'required_with:source_type',
                'regex:/\A[a-z0-9_.:-]+\z/i',
            ],
            'status' => ['required', Rule::in([
                AiModelUsageEvent::STATUS_SUCCEEDED,
                AiModelUsageEvent::STATUS_FAILED,
                AiModelUsageEvent::STATUS_DISCARDED,
                AiModelUsageEvent::STATUS_REVOKED,
            ])],
            'error_code' => ['nullable', 'string', 'max:100', 'regex:/\A[a-z0-9_.:-]+\z/i'],
            'input_tokens' => ['nullable', 'integer', 'min:0'],
            'output_tokens' => ['nullable', 'integer', 'min:0'],
            'total_tokens' => ['nullable', 'integer', 'min:0'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'],
            'duration_ms' => ['nullable', 'integer', 'min:0'],
            'protocol' => ['nullable', 'string', 'max:40', 'regex:/\A[a-z0-9_.:-]+\z/i'],
            'thinking_mode' => ['nullable', 'string', 'max:40', 'regex:/\A[a-z0-9_.:-]+\z/i'],
            'max_tokens_applied' => ['nullable', 'integer', 'min:0'],
            'attempt' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        // 用量行必须显式带租户归属：该表带 RLS，WITH CHECK 会拒绝/静默丢弃归属错误的行，
        // 而 Eloquent creating 钩子不覆盖系统上下文。系统上下文回落默认租户以保持单站兼容。
        $attributes = [
            'tenant_id' => app(TenantContext::class)->tenantIdOrDefault(),
            ...$accessSnapshot->toUsageAttributes(),
            ...$attributes,
        ];
        $payloadFingerprint = $this->payloadFingerprint($attributes);

        DB::table((new AiModelUsageEvent)->getTable())->insertOrIgnore([
            'event_uuid' => (string) Str::uuid(),
            'payload_fingerprint' => $payloadFingerprint,
            ...$attributes,
            'created_at' => now(),
        ]);

        $existing = $this->findExisting($attributes);
        if (! $existing instanceof AiModelUsageEvent) {
            throw ValidationException::withMessages([
                'call_key' => ['ai_usage_event_insert_failed'],
            ]);
        }
        $this->assertIdempotentMatch($existing, $payloadFingerprint);

        return $existing;
    }

    /** @param array<string, mixed> $attributes */
    private function findExisting(array $attributes): ?AiModelUsageEvent
    {
        return AiModelUsageEvent::query()
            ->where('request_id', $attributes['request_id'])
            ->where('call_key', $attributes['call_key'])
            ->first();
    }

    /** @param array<string, mixed> $attributes */
    private function payloadFingerprint(array $attributes): string
    {
        ksort($attributes);

        return hash('sha256', json_encode(
            $attributes,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private function assertIdempotentMatch(AiModelUsageEvent $existing, string $payloadFingerprint): void
    {
        if (hash_equals((string) $existing->payload_fingerprint, $payloadFingerprint)) {
            return;
        }

        throw ValidationException::withMessages([
            'call_key' => [self::IDEMPOTENCY_CONFLICT],
        ]);
    }
}
