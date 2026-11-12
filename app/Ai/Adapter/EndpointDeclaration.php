<?php

namespace App\Ai\Adapter;

use App\Models\AiModel;
use Illuminate\Validation\Rule;

/**
 * 后台“接入点能力”表单输入的归一化与一致性校验。
 *
 * 只负责把表单值收敛成 {@see AiModel} 的 6 个能力列，不落库、不做协议推导；
 * 协议推导统一由 {@see ModelEndpointResolver} 完成。
 */
final class EndpointDeclaration
{
    /** @return list<string> */
    public static function providerKeys(): array
    {
        return array_map(static fn (ProviderPreset $preset): string => $preset->key, ProviderPreset::all());
    }

    /** @return list<string> */
    public static function protocolValues(): array
    {
        return array_map(static fn (Protocol $protocol): string => $protocol->value, Protocol::cases());
    }

    /** @return list<string> */
    public static function thinkingModes(): array
    {
        return ['auto', 'disabled', 'enabled'];
    }

    /** @return list<string> */
    public static function formCapabilities(): array
    {
        return [
            Capability::Chat->value,
            Capability::Embedding->value,
            Capability::MultimodalEmbedding->value,
            Capability::Thinking->value,
            Capability::JsonObject->value,
            Capability::JsonSchema->value,
        ];
    }

    /**
     * 后台创建/更新表单共用的校验规则。
     *
     * @return array<string, list<mixed>>
     */
    public static function validationRules(): array
    {
        return [
            'provider_key' => ['nullable', 'string', 'max:32', Rule::in(self::providerKeys())],
            'protocol' => ['nullable', 'string', Rule::in(self::protocolValues())],
            'capabilities' => ['nullable', 'array'],
            'capabilities.*' => ['string', Rule::in(Capability::values())],
            'context_length' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'timeout_profile' => ['nullable', 'array'],
            'timeout_profile.connect' => ['nullable', 'integer', 'min:1', 'max:3600'],
            'timeout_profile.read' => ['nullable', 'integer', 'min:1', 'max:3600'],
            'timeout_profile.total' => ['nullable', 'integer', 'min:1', 'max:3600'],
            'thinking_mode' => ['nullable', Rule::in(self::thinkingModes())],
        ];
    }

    /**
     * 把表单输入归一化成 6 个能力列。
     *
     * @param  array<string, mixed>  $input
     * @return array{provider_key:?string,protocol:?string,capabilities:?array,context_length:?int,timeout_profile:?array,thinking_mode:?string}
     */
    public static function normalize(array $input): array
    {
        $providerKey = self::stringOrNull($input['provider_key'] ?? null);
        $protocol = self::stringOrNull($input['protocol'] ?? null);

        return [
            'provider_key' => $providerKey === null ? null : strtolower($providerKey),
            'protocol' => $protocol === null ? null : strtolower($protocol),
            'capabilities' => self::normalizeCapabilities($input['capabilities'] ?? null),
            'context_length' => self::nullableInt($input['context_length'] ?? null),
            'timeout_profile' => self::normalizeTimeoutProfile($input['timeout_profile'] ?? null),
            'thinking_mode' => self::normalizeThinkingMode($input['thinking_mode'] ?? null),
        ];
    }

    /**
     * @return list<string>|null
     */
    public static function normalizeCapabilities(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $values = array_values(array_unique(array_filter(
            array_map(
                static fn (mixed $value): ?string => Capability::tryFromValue($value)?->value,
                $raw,
            ),
            static fn (?string $value): bool => $value !== null,
        )));

        return $values === [] ? null : $values;
    }

    /**
     * @return array{connect:?int,read:?int,total:?int}|null
     */
    public static function normalizeTimeoutProfile(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $profile = [
            'connect' => self::nullableInt($raw['connect'] ?? null),
            'read' => self::nullableInt($raw['read'] ?? null),
            'total' => self::nullableInt($raw['total'] ?? null),
        ];

        return array_filter($profile, static fn (?int $value): bool => $value !== null) === []
            ? null
            : $profile;
    }

    public static function normalizeThinkingMode(mixed $raw): ?string
    {
        $mode = self::stringOrNull($raw);
        if ($mode === null) {
            return null;
        }

        $mode = strtolower($mode);

        return in_array($mode, self::thinkingModes(), true) ? $mode : null;
    }

    /**
     * 协议与能力的一致性校验，返回“字段 => 中文原因”。
     *
     * @param  list<string>|null  $capabilities
     * @return array<string, string>
     */
    public static function consistencyErrors(
        string $modelType,
        ?string $protocol,
        ?array $capabilities,
        ?array $timeoutProfile = null,
    ): array {
        $errors = [];
        $modelType = trim($modelType) === 'embedding' ? 'embedding' : 'chat';
        $capabilities = is_array($capabilities) ? $capabilities : [];

        $executable = array_values(array_intersect(
            $capabilities,
            Capability::executableValues(),
        ));

        if (count($executable) > 1) {
            $errors['capabilities'] = __('admin.ai_models.error.capability_executable_conflict');
        }

        if (in_array(Capability::MultimodalEmbedding->value, $capabilities, true)
            && in_array(Capability::Embedding->value, $capabilities, true)) {
            $errors['capabilities'] = __('admin.ai_models.error.capability_multimodal_excludes_embedding');
        }

        if (in_array(Capability::MultimodalEmbedding->value, $capabilities, true) && $modelType !== 'embedding') {
            $errors['capabilities'] = __('admin.ai_models.error.capability_multimodal_requires_embedding');
        }

        if (in_array(Capability::Chat->value, $capabilities, true) && $modelType !== 'chat') {
            $errors['capabilities'] = __('admin.ai_models.error.capability_chat_requires_chat');
        }

        if (in_array(Capability::Embedding->value, $capabilities, true) && $modelType !== 'embedding') {
            $errors['capabilities'] = __('admin.ai_models.error.capability_embedding_requires_embedding');
        }

        if ($protocol !== null && $protocol !== '') {
            $allowed = $modelType === 'embedding'
                ? [Protocol::OpenAiEmbedding->value, Protocol::OpenAiMultimodalEmbedding->value]
                : [
                    Protocol::OpenAiChat->value,
                    Protocol::OpenAiResponses->value,
                    Protocol::VolcengineResponses->value,
                    Protocol::GeminiNative->value,
                ];

            if (! in_array($protocol, $allowed, true)) {
                $errors['protocol'] = __('admin.ai_models.error.protocol_model_type_conflict');
            }

            if ($protocol === Protocol::OpenAiEmbedding->value
                && in_array(Capability::MultimodalEmbedding->value, $capabilities, true)) {
                $errors['protocol'] = __('admin.ai_models.error.protocol_embedding_vs_multimodal');
            }
        }

        if (is_array($timeoutProfile)) {
            $timeoutError = self::timeoutProfileError($timeoutProfile);
            if ($timeoutError !== null) {
                $errors['timeout_profile'] = $timeoutError;
            }
        }

        return $errors;
    }

    /**
     * 超时画像要么三项齐全、要么全部留空；且必须满足 connect ≤ read ≤ total。
     *
     * 只填部分字段会被归一化成带 null 的数组，旧行为是静默丢弃未填项，
     * 导致运营以为"只改 read"生效，实际整条画像被 min() 跳过。
     *
     * @param  array<string, mixed>  $profile
     */
    private static function timeoutProfileError(array $profile): ?string
    {
        $values = [
            'connect' => self::nullableInt($profile['connect'] ?? null),
            'read' => self::nullableInt($profile['read'] ?? null),
            'total' => self::nullableInt($profile['total'] ?? null),
        ];

        $filled = array_filter($values, static fn (?int $value): bool => $value !== null);
        if ($filled === []) {
            return null;
        }
        if (count($filled) !== 3) {
            return __('admin.ai_models.error.timeout_profile_incomplete');
        }
        if ($values['connect'] > $values['read'] || $values['read'] > $values['total']) {
            return __('admin.ai_models.error.timeout_profile_unordered');
        }

        return null;
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
