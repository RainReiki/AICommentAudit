<?php

namespace AICommentAudit\Rules;

defined('ABSPATH') || exit;

/**
 * Reputation thresholds and category risk.
 */
final class Trust
{
    /**
     * @param string $level
     * @param array $settings
     * @return float
     */
    public static function threshold($level, array $settings): float
    {
        if ($level === 'trusted' || $level === 'whitelist') {
            return (float) ($settings['thresholdTrusted'] ?? 0.9);
        }

        if ($level === 'normal') {
            return (float) ($settings['thresholdNormal'] ?? 0.8);
        }

        return (float) ($settings['thresholdNew'] ?? 0.7);
    }

    /**
     * @param string $category
     * @return bool
     */
    public static function isHighRisk($category): bool
    {
        return in_array((string) $category, [
            'ad', 'fraud', 'porn', 'illegal', 'abuse_severe', 'threat', 'hate',
        ], true);
    }

    /**
     * @param string $category
     * @return bool
     */
    public static function isLowRisk($category): bool
    {
        return in_array((string) $category, [
            'irrelevant', 'self-promo', 'abuse_mild', 'normal',
        ], true);
    }
}
