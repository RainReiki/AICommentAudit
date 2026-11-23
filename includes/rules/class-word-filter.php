<?php

namespace AICommentAudit\Rules;

defined('ABSPATH') || exit;

/**
 * Block words and suspect words.
 */
final class WordFilter
{
    /**
     * @param string $raw
     * @return string[]
     */
    public static function lines($raw): array
    {
        $items = preg_split('/\r\n|\r|\n/', (string) $raw);
        $result = [];

        foreach ($items as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $result[$item] = $item;
            }
        }

        return array_values($result);
    }

    /**
     * @param array $fields
     * @param string[] $words
     * @return string|null
     */
    public static function find(array $fields, array $words): ?string
    {
        foreach ($fields as $field) {
            $haystack = self::lower((string) $field);
            if ($haystack === '') {
                continue;
            }

            foreach ($words as $word) {
                $needle = self::lower(trim((string) $word));
                if ($needle === '') {
                    continue;
                }

                $position = function_exists('mb_strpos')
                    ? mb_strpos($haystack, $needle)
                    : strpos($haystack, $needle);

                if ($position !== false) {
                    return (string) $word;
                }
            }
        }

        return null;
    }

    /**
     * @param array $comment
     * @param array $settings
     * @return array
     */
    public static function scopedFields(array $comment, array $settings): array
    {
        $scopes = is_array($settings['wordScopes'] ?? null)
            ? $settings['wordScopes']
            : ['text', 'author', 'email'];

        $fields = [];
        if (in_array('text', $scopes, true)) {
            $fields[] = self::normalize($comment['text'] ?? '');
        }
        if (in_array('author', $scopes, true)) {
            $fields[] = (string) ($comment['author'] ?? '');
        }
        if (in_array('email', $scopes, true)) {
            $fields[] = (string) ($comment['mail'] ?? '');
        }
        if (in_array('url', $scopes, true)) {
            $fields[] = (string) ($comment['url'] ?? '');
        }

        return $fields;
    }

    /**
     * @param array $comment
     * @param array $settings
     * @return string|null
     */
    public static function matchBlock(array $comment, array $settings): ?string
    {
        $words = self::lines($settings['blockWords'] ?? '');
        if (!$words) {
            return null;
        }

        return self::find(self::scopedFields($comment, $settings), $words);
    }

    /**
     * @param array $comment
     * @param array $settings
     * @return string|null
     */
    public static function matchSuspect(array $comment, array $settings): ?string
    {
        $words = self::lines($settings['suspectWords'] ?? '');
        if (!$words) {
            return null;
        }

        return self::find(self::scopedFields($comment, $settings), $words);
    }

    /**
     * @param string $text
     * @return string
     */
    public static function normalize($text): string
    {
        $text = wp_strip_all_tags((string) $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t\r\n]+/u', ' ', $text);

        return trim((string) $text);
    }

    /**
     * @param string $value
     * @return string
     */
    private static function lower($value): string
    {
        return function_exists('mb_strtolower')
            ? mb_strtolower((string) $value, 'UTF-8')
            : strtolower((string) $value);
    }
}
