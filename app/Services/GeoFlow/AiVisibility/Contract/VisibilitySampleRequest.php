<?php

namespace App\Services\GeoFlow\AiVisibility\Contract;

use RuntimeException;

/**
 * 一次采样的统一请求契约。
 *
 * 所有引擎使用同一提示词模板、同一采样参数（temperature=0、单轮、关闭流式），
 * 差异只体现在 `searchEnabled` 对应的引擎联网能力上。
 */
final class VisibilitySampleRequest
{
    public function __construct(
        public readonly string $keyword,
        public readonly string $promptTemplateKey,
        public readonly string $promptText,
        public readonly string $region = 'global',
        public readonly string $locale = 'zh_CN',
        public readonly bool $searchEnabled = true,
        public readonly float $temperature = 0.0,
        public readonly int $maxOutputTokens = 1000,
        public readonly int $searchResultCount = 8,
        public readonly ?int $sampleIndex = null,
        public readonly int $attemptNo = 1,
    ) {
        if (trim($this->keyword) === '') {
            throw new RuntimeException('ai_visibility_keyword_empty');
        }
        if (trim($this->promptText) === '') {
            throw new RuntimeException('ai_visibility_prompt_missing');
        }
    }

    public static function forKeyword(
        string $keyword,
        ?string $promptTemplateKey = null,
        bool $searchEnabled = true,
        string $region = 'global',
        string $locale = 'zh_CN',
        ?int $sampleIndex = null,
    ): self {
        $templateKey = $promptTemplateKey ?? VisibilityPromptTemplates::defaultKey();

        return new self(
            keyword: trim($keyword),
            promptTemplateKey: $templateKey,
            promptText: VisibilityPromptTemplates::render($templateKey, $keyword),
            region: $region,
            locale: $locale,
            searchEnabled: $searchEnabled,
            sampleIndex: $sampleIndex,
        );
    }

    public function promptTemplateVersion(): string
    {
        return VisibilityPromptTemplates::version($this->promptTemplateKey);
    }
}
