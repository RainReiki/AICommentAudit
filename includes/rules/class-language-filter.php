<?php

namespace AICommentAudit\Rules;

defined('ABSPATH') || exit;

/**
 * Optional local rule for comments without Han characters.
 */
final class LanguageFilter
{
    /**
     * Count visible non-whitespace characters, excluding markup and format controls.
     * Numbers, punctuation and emoji count toward the total; A-Z/a-z and their
     * fullwidth forms count as English. A single Han character exempts the text.
     */
    public static function matches($rawText, array $settings): bool
    {
        if (empty($settings['englishFilterEnabled'])) {
            return false;
        }

        $text = html_entity_decode((string) $rawText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = wp_strip_all_tags($text);
        $text = preg_replace('/[\s\p{Z}\p{Cf}]+/u', '', $text);
        if ($text === null || $text === '' || preg_match('/\p{Han}/u', $text) !== 0) {
            return false;
        }

        $englishCount = preg_match_all('/[A-Za-z\x{FF21}-\x{FF3A}\x{FF41}-\x{FF5A}]/u', $text);
        $totalCount = preg_match_all('/./us', $text);
        if (!$englishCount || !$totalCount) {
            return false;
        }

        $threshold = max(1, min(100, (int) ($settings['englishRatioThreshold'] ?? 80)));

        // Compare without rounding so the boundary is inclusive and predictable.
        return $englishCount * 100 >= $threshold * $totalCount;
    }
}
