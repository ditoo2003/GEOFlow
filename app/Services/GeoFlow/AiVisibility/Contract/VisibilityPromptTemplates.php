<?php

namespace App\Services\GeoFlow\AiVisibility\Contract;

use InvalidArgumentException;

/**
 * 统一提示词模板：模板 `key + version` 一旦用于对外报数不得静默修改。
 *
 * 模板不注入品牌、不做诱导式提问；不同引擎使用同一模板与同一版本，
 * 保证跨引擎结果在"问法"上可比。
 */
final class VisibilityPromptTemplates
{
    public const KEY_QUESTION = 'question_v1';

    public const KEY_RECOMMEND = 'recommend_v1';

    public const KEY_COMPARE = 'compare_v1';

    public const KEY_EN_QUESTION = 'en_question_v1';

    /** @var array<string,array{version:string,template:string}> */
    private const TEMPLATES = [
        self::KEY_QUESTION => [
            'version' => 'v1',
            'template' => '请回答这个问题：{keyword}。请用简洁要点回答，并在有来源时附上引用链接。',
        ],
        self::KEY_RECOMMEND => [
            'version' => 'v1',
            'template' => '{keyword} 有哪些值得推荐的选择？请给出理由并注明来源。',
        ],
        self::KEY_COMPARE => [
            'version' => 'v1',
            'template' => '请比较 {keyword}，说明各自优劣并注明来源。',
        ],
        self::KEY_EN_QUESTION => [
            'version' => 'v1',
            'template' => 'Answer this question: {keyword}. Be concise and cite sources when available.',
        ],
    ];

    public static function defaultKey(): string
    {
        return self::KEY_QUESTION;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::TEMPLATES);
    }

    public static function version(string $key): string
    {
        return self::definition($key)['version'];
    }

    public static function render(string $key, string $keyword): string
    {
        return str_replace('{keyword}', trim($keyword), self::definition($key)['template']);
    }

    /** @return array{version:string,template:string} */
    private static function definition(string $key): array
    {
        if (! isset(self::TEMPLATES[$key])) {
            throw new InvalidArgumentException('unknown_visibility_prompt_template');
        }

        return self::TEMPLATES[$key];
    }
}
