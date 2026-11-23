<?php

namespace AICommentAudit\AI;

defined('ABSPATH') || exit;

/**
 * Parse and normalise the JSON returned by the AI.
 */
final class ResultParser
{
    /**
     * @param string $content
     * @param bool $requireIdentity
     * @return array|null
     */
    public static function parse($content, bool $requireIdentity = false): ?array
    {
        $json = self::extractJson((string) $content);
        if ($json === '') {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }

        $decision = strtolower((string) ($data['decision'] ?? ''));
        if (!in_array($decision, ['pass', 'review', 'block'], true)) {
            return null;
        }

        $categories = [
            'normal', 'ad', 'fraud', 'porn', 'illegal',
            'abuse_mild', 'abuse_moderate', 'abuse_severe',
            'threat', 'hate', 'politics', 'irrelevant', 'self-promo',
        ];
        $category = strtolower((string) ($data['category'] ?? 'normal'));
        if (!in_array($category, $categories, true)) {
            $category = 'normal';
        }

        $severities = ['low', 'medium', 'high'];
        $severity = strtolower((string) ($data['severity'] ?? 'low'));
        if (!in_array($severity, $severities, true)) {
            $severity = 'low';
        }

        $threatTypes = ['none', 'joke', 'genuine', 'ambiguous'];
        $threatType = strtolower((string) ($data['threat_type'] ?? 'none'));
        if (!in_array($threatType, $threatTypes, true)) {
            $threatType = 'none';
        }

        $confidence = (float) ($data['confidence'] ?? 0);
        $confidence = max(0, min(1, $confidence));

        $identity = null;
        if (isset($data['identity']) && is_array($data['identity'])) {
            $raw = $data['identity'];
            $scoreRaw = $raw['score'] ?? null;
            $hasScore = is_numeric($scoreRaw);
            $score = $hasScore ? max(0, min(10, (int) round((float) $scoreRaw))) : null;
            $risk = strtolower(trim((string) ($raw['risk'] ?? '')));
            $categoryName = strtolower(trim((string) ($raw['category'] ?? '')));
            if (!$hasScore && !in_array($risk, ['low', 'medium', 'high'], true)) {
                return null;
            }
            if (!in_array($categoryName, ['none', 'name_confusion', 'profile_conflict', 'possible_impersonation'], true)) {
                $categoryName = $hasScore && $score >= 3 ? 'profile_conflict' : 'none';
            }
            if ($hasScore) {
                $risk = $score >= 6 ? 'high' : ($score >= 3 ? 'medium' : 'low');
            } else {
                $score = $risk === 'high' ? 7 : ($risk === 'medium' ? 4 : 0);
            }
            if ($score >= 3 && $categoryName === 'none') {
                $categoryName = 'profile_conflict';
            }

            $sourcesRaw = $raw['sources'] ?? [];
            if (is_string($sourcesRaw)) {
                $sourcesRaw = preg_split('/[|,，、\s]+/u', $sourcesRaw) ?: [];
            }
            if (!is_array($sourcesRaw)) {
                $sourcesRaw = [];
            }

            $evidenceRaw = $raw['evidence'] ?? [];
            if (is_string($evidenceRaw)) {
                $evidenceRaw = [$evidenceRaw];
            }
            if (!is_array($evidenceRaw)) {
                $evidenceRaw = [];
            }

            $sources = array_values(array_intersect([
                'site_name_similarity', 'historical_name_similarity', 'history_profile_conflict',
                'history_spam_pattern', 'placeholder_email', 'public_mailbox', 'platform_homepage',
                'search_engine_homepage', 'shortened_website', 'brand_impersonation',
                'profile_mismatch', 'unverified_website',
            ], array_filter($sourcesRaw, 'is_string')));
            $evidence = array_slice(array_values(array_filter(array_map(
                static fn ($item) => is_string($item) ? self::short(trim($item), 160) : '',
                $evidenceRaw
            ))), 0, 3);
            $identity = [
                'risk' => $risk,
                'score' => $score,
                'category' => $categoryName,
                'sources' => $sources,
                'evidence' => $evidence,
            ];
        } elseif ($requireIdentity) {
            return null;
        }

        return [
            'decision' => $decision,
            'category' => $category,
            'severity' => $severity,
            'threat_type' => $threatType,
            'on_topic' => self::boolValue($data['on_topic'] ?? true),
            'faithful' => self::boolValue($data['faithful'] ?? true),
            'confidence' => $confidence,
            'reason' => trim((string) ($data['reason'] ?? '')),
            'identity' => $identity,
        ];
    }

    /**
     * @param mixed $value
     * @return bool
     */
    private static function boolValue($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = strtolower(trim($value));
            return in_array($value, ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }

    /**
     * @param string $text
     * @param int $length
     * @return string
     */
    private static function short(string $text, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($text, 0, $length, 'UTF-8') : substr($text, 0, $length);
    }

    /**
     * @param string $content
     * @return string
     */
    public static function extractJson($content): string
    {
        $content = trim((string) $content);
        $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
        $content = preg_replace('/\s*```$/', '', $content);

        $start = strpos($content, '{');
        $end = strrpos($content, '}');

        if ($start === false || $end === false || $end <= $start) {
            return '';
        }

        return substr($content, $start, $end - $start + 1);
    }
}
