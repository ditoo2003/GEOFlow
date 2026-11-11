<?php

namespace App\Ai\Adapter;

/**
 * 调用场景：thinking 开关与预算画像由"场景 × 接入点能力"共同决定，而不是按 host 字符串判断。
 */
enum CallScene: string
{
    case ContentGeneration = 'content_generation';
    case ArticleQuality = 'article_quality';
    case AdminHelp = 'admin_help';
    case KnowledgeFact = 'knowledge_fact';
    case Embedding = 'embedding';
    case Probe = 'probe';

    public function label(): string
    {
        return match ($this) {
            self::ContentGeneration => '文章生成',
            self::ArticleQuality => '文章质检',
            self::AdminHelp => '后台帮助助手',
            self::KnowledgeFact => '知识检索与事实抽取',
            self::Embedding => '向量化',
            self::Probe => '连接测试',
        };
    }

    /**
     * 该场景是否默认关闭深度思考。
     *
     * 生成链路保持可配置（接入点显式 thinking_mode=enabled 时仍可开启），其余场景强制关闭。
     */
    public function disablesThinking(): bool
    {
        return $this !== self::ContentGeneration;
    }

    /**
     * 场景的默认预算画像（秒）：connect / read / total。
     *
     * 仅作为上限基线；调用方显式传入的预算与接入点显式超时画像会一起取 min。
     *
     * @return array{connect:int,read:int,total:int}
     */
    public function budget(): array
    {
        return match ($this) {
            self::ContentGeneration => ['connect' => 10, 'read' => 420, 'total' => 420],
            self::ArticleQuality => [
                'connect' => 8,
                'read' => (int) config('geoflow.ai_quality_request_timeout_seconds', 160),
                'total' => (int) config('geoflow.ai_quality_deadline_seconds', 180),
            ],
            self::AdminHelp => ['connect' => 8, 'read' => 45, 'total' => 60],
            self::KnowledgeFact => [
                'connect' => 8,
                'read' => (int) config('ai-workspace.model_attempt_timeout_seconds', 30),
                'total' => (int) config('ai-workspace.total_timeout_seconds', 90),
            ],
            self::Embedding => [
                'connect' => (int) config('geoflow.embedding_connect_timeout_seconds', 8),
                'read' => (int) config('geoflow.embedding_request_timeout_seconds', 45),
                'total' => (int) config('geoflow.embedding_request_timeout_seconds', 45) + 15,
            ],
            self::Probe => ['connect' => 8, 'read' => 30, 'total' => 45],
        };
    }
}
