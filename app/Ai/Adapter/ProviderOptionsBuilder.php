<?php

namespace App\Ai\Adapter;

use Illuminate\Support\Facades\Log;

/**
 * ModelEndpoint + 调用场景 → providerOptions。
 *
 * 收敛原先分散在质检 reviewer、生成 agent、帮助助手、测试连接中的 thinking / token 字段分支，
 * 保证同一接入点在所有调用点得到一致的请求体。
 */
final class ProviderOptionsBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(
        ModelEndpoint $endpoint,
        CallScene $scene,
        ?int $maxTokens = null,
        bool $separateReasoning = false,
        ?int $estimatedInputTokens = null,
        ?string $baseUrl = null,
        bool $injectTokenLimit = true,
    ): array {
        $options = [];

        if ($this->usesReasoningSplit($endpoint)) {
            if ($separateReasoning || $scene === CallScene::ArticleQuality) {
                $options['reasoning_split'] = true;
            }
        } elseif (($thinking = $this->thinkingOption($endpoint, $scene)) !== null) {
            $options['thinking'] = $thinking;
        }

        if ($scene === CallScene::AdminHelp && $this->isOpenAiReasoningModel($endpoint)) {
            $options['reasoning'] = ['effort' => 'low'];
        }

        if ($scene === CallScene::ArticleQuality && $this->isGlm45Plus($endpoint)) {
            $options['response_format'] = ['type' => 'json_object'];
        }

        if ($injectTokenLimit && $maxTokens !== null && $maxTokens > 0) {
            $options[$endpoint->tokenField()] = $maxTokens;
        }

        $this->warnIfContextExceeded($endpoint, $estimatedInputTokens, $baseUrl);

        return $options;
    }

    /**
     * 记录本次实际生效的 thinking 形态，用于账本观测。
     *
     * @param  array<string, mixed>  $options
     */
    public function thinkingModeApplied(ModelEndpoint $endpoint, CallScene $scene, array $options): string
    {
        if ($endpoint->isEmbedding() || $scene === CallScene::Embedding) {
            return 'not_applicable';
        }
        if (($options['reasoning_split'] ?? false) === true) {
            return 'separate';
        }
        if (($options['thinking']['type'] ?? null) === 'disabled') {
            return 'disabled';
        }
        if ($endpoint->thinkingMode === 'enabled') {
            return 'enabled';
        }

        return $scene->disablesThinking() ? 'disabled-unavailable' : 'enabled';
    }

    /**
     * 生成链路与质检链路共用的 thinking 决策。
     *
     * - 显式 thinking_mode=disabled：任何场景都下发关闭（修复"声明了但生成链路不生效"）；
     * - 显式 thinking_mode=enabled：尊重声明，不再抑制；
     * - 未声明：沿用场景默认（质检关闭、生成保持开启）。
     *
     * @return array{type:string}|null
     */
    public function thinkingOption(ModelEndpoint $endpoint, CallScene $scene): ?array
    {
        if ($scene === CallScene::Embedding || $endpoint->isEmbedding()) {
            return null;
        }
        if (! $this->supportsThinkingParameter($endpoint)) {
            return null;
        }
        if ($endpoint->thinkingMode === 'enabled') {
            return null;
        }
        if ($endpoint->thinkingMode === 'disabled') {
            return ['type' => 'disabled'];
        }

        return $scene->disablesThinking() ? ['type' => 'disabled'] : null;
    }

    private function supportsThinkingParameter(ModelEndpoint $endpoint): bool
    {
        // GLM 只有 4.5 及以上支持 thinking 参数；旧版本下发会被厂商拒绝。
        if ($endpoint->providerKey === 'glm') {
            return $this->isGlm45Plus($endpoint);
        }

        if (in_array($endpoint->providerKey, ['ark', 'deepseek'], true)) {
            return true;
        }

        // 第三方兼容网关托管 deepseek-* 时，沿用基线"按驱动判定"的行为：
        // 此时 provider 预设是 custom，不能只靠 capabilities 判定。
        if (str_starts_with(strtolower($endpoint->modelId), 'deepseek')) {
            return true;
        }

        return $endpoint->hasCapability(Capability::Thinking);
    }

    private function usesReasoningSplit(ModelEndpoint $endpoint): bool
    {
        return $endpoint->providerKey === 'minimax'
            && str_starts_with(strtolower($endpoint->modelId), 'minimax-m');
    }

    private function isOpenAiReasoningModel(ModelEndpoint $endpoint): bool
    {
        if ($endpoint->providerKey !== 'openai') {
            return false;
        }

        $model = strtolower($endpoint->modelId);

        return str_starts_with($model, 'o1')
            || str_starts_with($model, 'o3')
            || str_starts_with($model, 'o4')
            || str_starts_with($model, 'gpt-5');
    }

    private function isGlm45Plus(ModelEndpoint $endpoint): bool
    {
        if ($endpoint->providerKey !== 'glm') {
            return false;
        }

        $model = strtolower($endpoint->modelId);
        if (preg_match('/^glm-(\d+)(?:\.(\d+))?(?:-|$)/', $model, $version) !== 1) {
            return false;
        }

        return (int) $version[1] > 4
            || ((int) $version[1] === 4 && (int) ($version[2] ?? 0) >= 5);
    }

    private function warnIfContextExceeded(
        ModelEndpoint $endpoint,
        ?int $estimatedInputTokens,
        ?string $baseUrl,
    ): void {
        $contextLength = $endpoint->contextLength;
        if ($estimatedInputTokens === null || $contextLength === null || $contextLength <= 0) {
            return;
        }
        if ($estimatedInputTokens <= $contextLength) {
            return;
        }

        try {
            Log::warning('ai_context_length_exceeded', [
                'provider' => $endpoint->providerKey,
                'protocol' => $endpoint->protocol->value,
                'model' => $endpoint->modelId,
                'estimated_input_tokens' => $estimatedInputTokens,
                'context_length' => $contextLength,
                'endpoint' => $baseUrl ?? $endpoint->baseUrl,
                'handling' => 'warn_only_truncate',
            ]);
        } catch (\Throwable) {
            // 观测失败不得影响主链路。
        }
    }
}
