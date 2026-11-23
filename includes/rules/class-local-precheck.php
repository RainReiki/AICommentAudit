<?php

namespace AICommentAudit\Rules;

defined('ABSPATH') || exit;

/**
 * Local soft signals.
 */
final class LocalPrecheck
{
    /**
     * @param string $text
     * @return int
     */
    public static function linkCount($text): int
    {
        return count(Blacklist::extractUrls(WordFilter::normalize($text)));
    }

    /**
     * @param array $comment
     * @param array $settings
     * @param string $trustLevel
     * @return string[]
     */
    public static function flags(array $comment, array $settings, $trustLevel): array
    {
        $flags = [];
        $rawText = (string) ($comment['text'] ?? '');
        $linkCount = self::linkCount($rawText);
        $linkLimit = max(0, (int) ($settings['linkLimit'] ?? 3));

        if ($linkLimit >= 0 && $linkCount > $linkLimit) {
            $flags[] = 'too_many_links';
        }

        if ($trustLevel === 'new' && $linkCount > 0) {
            $flags[] = 'new_user_with_link';
        }

        if (WordFilter::matchSuspect($comment, $settings) !== null) {
            $flags[] = 'suspect_word';
        }

        return $flags;
    }
}
