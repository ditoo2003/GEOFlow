<?php

namespace App\Ai\Agents\Concerns;

use App\Services\GeoFlow\ArticleQualityIssueCode;
use Laravel\Ai\Enums\Lab;

trait ConfiguresArticleQualityProviderOptions
{
    /** @var array<string, mixed> */
    private array $qualityProviderOptions = [];

    private const ARTICLE_QUALITY_ISSUE_CODES = ArticleQualityIssueCode::ALL;

    /** @param array<string, mixed> $options */
    public function withQualityProviderOptions(array $options): static
    {
        $this->qualityProviderOptions = $options;

        return $this;
    }

    /**
     * 质检 thinking 主体由 ProviderOptionsBuilder 按"场景 × 接入点能力"注入；
     * DeepSeek 驱动不读取 agent 的 maxTokens 约定，且 agent 可脱离 reviewer 单独使用，
     * 因此这里保留该驱动自身的输出上限与关思考默认值（与 builder 结果一致）。
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        $providerKey = $provider instanceof Lab ? $provider->value : $provider;

        if ($providerKey !== Lab::DeepSeek->value) {
            return $this->qualityProviderOptions;
        }

        return [
            'max_tokens' => $this->outputTokenLimit,
            'thinking' => ['type' => 'disabled'],
            ...$this->qualityProviderOptions,
        ];
    }
}
