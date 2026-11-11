<?php

namespace App\Ai\Adapter;

/**
 * 内置 provider 预设（只读）。
 *
 * 预设只提供默认值，任何字段都可以被接入点显式声明覆盖；不落库、不可在后台编辑。
 */
final readonly class ProviderPreset
{
    /**
     * @param  list<string>  $hosts  host 后缀匹配规则
     * @param  list<Capability>  $capabilities
     * @param  array{connect:int,read:int,total:int}  $timeouts
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $hosts,
        public Protocol $protocol,
        public array $capabilities,
        public bool $thinkingDisabledByDefault,
        public array $timeouts,
    ) {}

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return [
            new self(
                'ark',
                '火山方舟（Volcengine Ark）',
                ['.volces.com'],
                Protocol::OpenAiChat,
                [Capability::Chat, Capability::Embedding, Capability::MultimodalEmbedding, Capability::Thinking],
                true,
                ['connect' => 8, 'read' => 180, 'total' => 240],
            ),
            new self(
                'deepseek',
                'DeepSeek',
                ['api.deepseek.com'],
                Protocol::OpenAiChat,
                [Capability::Chat, Capability::Thinking],
                true,
                ['connect' => 8, 'read' => 180, 'total' => 240],
            ),
            new self(
                'openai',
                'OpenAI',
                ['api.openai.com'],
                Protocol::OpenAiChat,
                [Capability::Chat, Capability::Embedding, Capability::Thinking, Capability::JsonObject, Capability::JsonSchema, Capability::Tools, Capability::Vision, Capability::Streaming],
                false,
                ['connect' => 8, 'read' => 120, 'total' => 180],
            ),
            new self(
                'gemini',
                'Google Gemini',
                ['generativelanguage.googleapis.com'],
                Protocol::GeminiNative,
                [Capability::Chat, Capability::Embedding, Capability::Thinking, Capability::Vision, Capability::Streaming],
                false,
                ['connect' => 8, 'read' => 120, 'total' => 180],
            ),
            new self(
                'qwen',
                '通义千问（DashScope 兼容模式）',
                ['dashscope.aliyuncs.com'],
                Protocol::OpenAiChat,
                [Capability::Chat, Capability::Embedding],
                false,
                ['connect' => 8, 'read' => 120, 'total' => 180],
            ),
            new self(
                'glm',
                '智谱 GLM',
                ['open.bigmodel.cn', 'api.z.ai'],
                Protocol::OpenAiChat,
                [Capability::Chat, Capability::Thinking, Capability::JsonObject],
                false,
                ['connect' => 8, 'read' => 120, 'total' => 180],
            ),
            new self(
                'kimi',
                'Moonshot Kimi',
                ['api.moonshot.cn'],
                Protocol::OpenAiChat,
                [Capability::Chat],
                false,
                ['connect' => 8, 'read' => 120, 'total' => 180],
            ),
            new self(
                'minimax',
                'MiniMax',
                ['api.minimaxi.com', 'api.minimax.io'],
                Protocol::OpenAiChat,
                [Capability::Chat],
                false,
                ['connect' => 8, 'read' => 120, 'total' => 180],
            ),
            new self(
                'custom',
                '自定义（OpenAI 兼容）',
                [],
                Protocol::OpenAiChat,
                [Capability::Chat, Capability::Embedding],
                false,
                ['connect' => 8, 'read' => 120, 'total' => 180],
            ),
        ];
    }

    public static function forKey(?string $key): ?self
    {
        $key = strtolower(trim((string) $key));
        if ($key === '') {
            return null;
        }

        foreach (self::all() as $preset) {
            if ($preset->key === $key) {
                return $preset;
            }
        }

        return null;
    }

    public static function forHost(string $host): ?self
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return null;
        }

        foreach (self::all() as $preset) {
            foreach ($preset->hosts as $suffix) {
                if ($host === ltrim($suffix, '.') || str_ends_with($host, $suffix)) {
                    return $preset;
                }
            }
        }

        return null;
    }

    public static function forUrl(string $apiUrl): ?self
    {
        $host = strtolower((string) (parse_url(trim($apiUrl), PHP_URL_HOST) ?? ''));

        return self::forHost($host);
    }

    /** @return list<string> */
    public function capabilityValues(): array
    {
        return array_map(static fn (Capability $capability): string => $capability->value, $this->capabilities);
    }

    public function hasCapability(Capability $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }
}
