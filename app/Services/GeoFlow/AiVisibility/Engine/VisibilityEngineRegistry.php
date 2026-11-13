<?php

namespace App\Services\GeoFlow\AiVisibility\Engine;

use RuntimeException;

/**
 * P0 引擎注册表：只暴露已接通的 API 口径引擎。
 *
 * 元宝/夸克与网页抓取路线不进 P0，注册表里没有条目即不可调用。
 */
final class VisibilityEngineRegistry
{
    /** @var array<string, VisibilityEngineAdapter> */
    private array $adapters = [];

    public function __construct(
        DoubaoEngineAdapter $doubao,
        QwenEngineAdapter $qwen,
        KimiEngineAdapter $kimi,
        PerplexityEngineAdapter $perplexity,
        ChatgptEngineAdapter $chatgpt,
    ) {
        foreach ([$doubao, $qwen, $kimi, $perplexity, $chatgpt] as $adapter) {
            $this->adapters[$adapter->key()] = $adapter;
        }
    }

    public function for(string $engineKey): VisibilityEngineAdapter
    {
        $engineKey = trim($engineKey);
        if (! isset($this->adapters[$engineKey])) {
            throw new RuntimeException('ai_visibility_engine_unsupported');
        }

        return $this->adapters[$engineKey];
    }

    public function has(string $engineKey): bool
    {
        return isset($this->adapters[trim($engineKey)]);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->adapters);
    }
}
