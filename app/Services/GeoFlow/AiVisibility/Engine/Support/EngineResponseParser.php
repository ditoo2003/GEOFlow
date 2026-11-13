<?php

namespace App\Services\GeoFlow\AiVisibility\Engine\Support;

use App\Services\GeoFlow\AiVisibility\Contract\VisibilityCitation;

/**
 * 两类主流返回结构的答案/引用解析：
 * - chat/completions（通义、Kimi、Perplexity）；
 * - responses（ChatGPT）。
 */
final class EngineResponseParser
{
    /** @param array<string,mixed> $json */
    public static function chatAnswer(array $json): string
    {
        $content = $json['choices'][0]['message']['content'] ?? '';

        if (is_string($content)) {
            return trim($content);
        }

        if (! is_array($content)) {
            return '';
        }

        $segments = [];
        foreach ($content as $part) {
            if (is_string($part) && trim($part) !== '') {
                $segments[] = trim($part);
                continue;
            }
            if (is_array($part) && is_string($part['text'] ?? null) && trim($part['text']) !== '') {
                $segments[] = trim($part['text']);
            }
        }

        return trim(implode("\n", $segments));
    }

    /**
     * chat/completions 的引用可能出现在三处：message.annotations、citations、search_results。
     *
     * @param  array<string,mixed>  $json
     * @return list<VisibilityCitation>
     */
    public static function chatCitations(array $json): array
    {
        $message = is_array($json['choices'][0]['message'] ?? null) ? $json['choices'][0]['message'] : [];
        $searchInfo = is_array($message['search_info'] ?? null) ? $message['search_info'] : [];

        $citations = [];
        if (is_array($message['annotations'] ?? null)) {
            $citations = array_merge(
                $citations,
                VisibilityCitationFactory::fromEntries($message['annotations'], VisibilityCitation::KIND_INLINE),
            );
        }
        if (is_array($searchInfo['search_results'] ?? null)) {
            $citations = array_merge(
                $citations,
                VisibilityCitationFactory::fromEntries($searchInfo['search_results'], VisibilityCitation::KIND_SEARCH_RESULT),
            );
        }
        if (is_array($json['citations'] ?? null)) {
            $citations = array_merge(
                $citations,
                VisibilityCitationFactory::fromUrlStrings($json['citations'], VisibilityCitation::KIND_SEARCH_RESULT),
            );
        }
        if (is_array($json['search_results'] ?? null)) {
            $citations = array_merge(
                $citations,
                VisibilityCitationFactory::fromEntries($json['search_results'], VisibilityCitation::KIND_SEARCH_RESULT),
            );
        }

        return VisibilityCitationFactory::merge($citations);
    }

    /** @param array<string,mixed> $json */
    public static function responsesAnswer(array $json): string
    {
        $segments = [];
        foreach (is_array($json['output'] ?? null) ? $json['output'] : [] as $item) {
            if (! is_array($item) || ($item['type'] ?? '') !== 'message') {
                continue;
            }
            foreach (is_array($item['content'] ?? null) ? $item['content'] : [] as $part) {
                if (is_array($part) && is_string($part['text'] ?? null) && trim($part['text']) !== '') {
                    $segments[] = trim($part['text']);
                }
            }
        }

        $answer = trim(implode("\n\n", $segments));
        if ($answer !== '') {
            return $answer;
        }

        return trim((string) ($json['output_text'] ?? ''));
    }

    /**
     * @param  array<string,mixed>  $json
     * @return list<VisibilityCitation>
     */
    public static function responsesCitations(array $json): array
    {
        $entries = [];
        foreach (is_array($json['output'] ?? null) ? $json['output'] : [] as $item) {
            if (! is_array($item) || ($item['type'] ?? '') !== 'message') {
                continue;
            }
            foreach (is_array($item['content'] ?? null) ? $item['content'] : [] as $part) {
                if (! is_array($part) || ! is_array($part['annotations'] ?? null)) {
                    continue;
                }
                foreach ($part['annotations'] as $annotation) {
                    if (is_array($annotation)) {
                        $entries[] = $annotation;
                    }
                }
            }
        }

        return VisibilityCitationFactory::merge(
            VisibilityCitationFactory::fromEntries($entries, VisibilityCitation::KIND_INLINE),
        );
    }

    /**
     * @param  array<string,mixed>  $json
     * @return array<string,mixed>
     */
    public static function usage(array $json): array
    {
        return is_array($json['usage'] ?? null) ? $json['usage'] : [];
    }
}
