<?php

namespace AICommentAudit;

use AICommentAudit\AI\Client;
use AICommentAudit\Rules\Blacklist;
use AICommentAudit\Rules\IdentityRisk;
use AICommentAudit\Rules\LanguageFilter;
use AICommentAudit\Rules\LocalPrecheck;
use AICommentAudit\Rules\Trust;
use AICommentAudit\Rules\WordFilter;

defined('ABSPATH') || exit;

/**
 * Decision engine: local rules -> soft signals -> AI -> reputation merge.
 */
final class Engine
{
    /**
     * @param array $comment
     * @param array $article
     * @param array $settings
     * @return array
     */
    public static function decide(array $comment, array $article, array $settings): array
    {
        return self::evaluate($comment, $article, $settings, false, true);
    }

    /**
     * @param array $comment
     * @param array $article
     * @param array $settings
     * @param bool $withAi
     * @return array
     */
    public static function preview(array $comment, array $article, array $settings, bool $withAi): array
    {
        return self::evaluate($comment, $article, $settings, true, $withAi);
    }

    /**
     * @param array $comment
     * @param array $article
     * @param array $settings
     * @param bool $historical
     * @param bool $withAi
     * @return array
     */
    private static function evaluate(array $comment, array $article, array $settings, bool $historical, bool $withAi): array
    {
        $rawText = (string) ($comment['text'] ?? '');
        $text = WordFilter::normalize($rawText);
        $author = trim((string) ($comment['author'] ?? ''));
        $mail = trim((string) ($comment['mail'] ?? ''));
        $url = trim((string) ($comment['url'] ?? ''));
        $ip = trim((string) ($comment['ip'] ?? ''));
        $postId = (int) ($comment['post_id'] ?? 0);

        $comment['author'] = $author;
        $comment['mail'] = $mail;
        $comment['url'] = $url;
        $comment['ip'] = $ip;

        $reputation = $withAi ? Repository::reputation($comment) : [];
        $trustLevel = (string) ($reputation['computed_level'] ?? 'new');

        if ($ip !== '' && Blacklist::matchIp($ip, $settings['ipBlacklist'] ?? '')) {
            return self::reject('local_ip_blacklist', $trustLevel);
        }

        if ($mail !== '' && Blacklist::matchEmail($mail, $settings['emailBlacklist'] ?? '')) {
            return self::reject('local_email_blacklist', $trustLevel);
        }

        if (Blacklist::matchUrl($rawText, $url, $settings['urlBlacklist'] ?? '')) {
            return self::reject('local_url_blacklist', $trustLevel);
        }

        if (WordFilter::matchBlock($comment, $settings) !== null) {
            return self::reject('local_block_word', $trustLevel);
        }

        $flags = LocalPrecheck::flags($comment, $settings, $trustLevel);

        if (LanguageFilter::matches($rawText, $settings)) {
            $flags[] = 'non_chinese_english_ratio';
            if (($settings['englishFilterAction'] ?? 'reject') === 'reject') {
                return self::reject('local_english_ratio', $trustLevel, [], $flags);
            }
            // A local-only history scan cannot complete the requested AI review.
            if (!$withAi) {
                return self::waiting('history_english_ai_required', $trustLevel, [], $flags);
            }
        }

        if (!$withAi) {
            if (in_array('suspect_word', $flags, true)) {
                return self::waiting('history_suspect_word', $trustLevel);
            }
            if (in_array('too_many_links', $flags, true)) {
                return self::waiting('history_link_limit', $trustLevel);
            }
            if (!empty($settings['identityProtection'])) {
                $localIdentity = IdentityRisk::evaluate($comment);
                if (IdentityRisk::rank($localIdentity['risk']) > 0) {
                    return self::waiting('identity_local_risk', $trustLevel, [
                        'identity' => IdentityRisk::merge(null, $localIdentity),
                    ], $flags);
                }
            }
            return self::approved('history_local_pass', $trustLevel);
        }

        $ipRecent = 0;
        if (!$historical) {
            $ipWindow = max(60, (int) ($settings['ipRateWindow'] ?? 600));
            $ipLimit = max(0, (int) ($settings['ipRateLimit'] ?? 5));
            $ipRecent = Repository::recentIpCount($ip, $ipWindow);
            if ($ipLimit > 0 && $ipRecent >= $ipLimit) {
                return self::waiting('local_rate_limit', $trustLevel, [], $flags);
            }
            if (Repository::duplicateCount($postId, $rawText, 7, $comment) > 0) {
                return self::reject('local_duplicate', $trustLevel, [], $flags);
            }
        }

        $features = [
            'trust_level' => $trustLevel,
            'approved_count' => (int) ($reputation['approved_count'] ?? 0),
            'spam_count' => (int) ($reputation['spam_count'] ?? 0),
            'last_spam_at' => (int) ($reputation['last_spam_at'] ?? 0),
            'link_count' => LocalPrecheck::linkCount($rawText),
            'comment_length' => function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text),
            'ip_recent_count' => $ipRecent,
            'suspect_word' => WordFilter::matchSuspect($comment, $settings),
            'flags' => $flags,
        ];

        $aiComment = $comment;
        $aiComment['text'] = $text;
        $ai = Client::audit($aiComment, $article, $features, $settings, $historical);

        if (!empty($settings['identityProtection'])) {
            $aiIdentity = is_array($ai['identity'] ?? null) ? $ai['identity'] : null;
            $ai['identity'] = IdentityRisk::merge($aiIdentity, IdentityRisk::evaluate($comment));
        }

        $result = self::merge($ai, $trustLevel, $comment, $settings, $flags, $historical);
        $identity = $ai['identity'] ?? null;

        if (!$historical && !empty($settings['identityProtection'])
            && empty($ai['error']) && $result['decision'] !== 'rejected'
            && is_array($identity) && ($identity['risk'] ?? 'low') === 'high'
            && (int) ($identity['score'] ?? 0) >= IdentityRisk::HIGH_SCORE
            && ($identity['category'] ?? 'none') !== 'none' && !empty($identity['evidence'])) {
            return self::reject('identity_high_risk', $trustLevel, $ai, $flags);
        }

        if (!empty($settings['identityProtection']) && $result['decision'] === 'approved'
            && is_array($identity) && in_array($identity['risk'], ['medium', 'high'], true)
            && ($identity['category'] ?? 'none') !== 'none' && !empty($identity['evidence'])) {
            $reason = IdentityRisk::rank((string) ($identity['local_risk'] ?? 'low'))
                >= IdentityRisk::rank((string) ($identity['risk'] ?? 'low'))
                ? 'identity_local_risk'
                : 'identity_review';

            return self::waiting($reason, $trustLevel, $ai, $flags);
        }

        return $result;
    }

    /**
     * @param array $ai
     * @param string $trustLevel
     * @param array $comment
     * @param array $settings
     * @param array $flags
     * @param bool $historical
     * @return array
     */
    private static function merge(array $ai, $trustLevel, array $comment, array $settings, array $flags, bool $historical): array
    {
        $decision = (string) ($ai['decision'] ?? 'review');
        $category = (string) ($ai['category'] ?? 'normal');
        $severity = (string) ($ai['severity'] ?? 'low');
        $confidence = (float) ($ai['confidence'] ?? 0);
        $threatType = (string) ($ai['threat_type'] ?? 'none');
        $onTopic = !empty($ai['on_topic']);
        $faithful = !empty($ai['faithful']);
        $threshold = Trust::threshold($trustLevel, $settings);

        if (!empty($ai['error'])) {
            $errorReason = (string) ($ai['reason'] ?? 'ai_error');
            if (!in_array($errorReason, ['ai_error', 'ai_missing_key', 'ai_circuit_open', 'quota_exceeded'], true)) {
                $errorReason = 'ai_error';
            }
            return self::waiting($errorReason, $trustLevel, $ai, $flags);
        }

        if ($category === 'abuse_moderate') {
            if ($threatType === 'genuine') {
                return self::reject('abuse_moderate_threat', $trustLevel, $ai, $flags);
            }
            if (!$historical && Repository::moderateAbuseCount($comment) + 1 >= 3) {
                return self::reject('abuse_moderate_repeat', $trustLevel, $ai, $flags);
            }
            return self::waiting($historical ? 'history_moderate_abuse' : 'abuse_moderate_first', $trustLevel, $ai, $flags);
        }

        if ($category === 'abuse_severe') {
            return self::reject('abuse_severe', $trustLevel, $ai, $flags);
        }

        if ($category === 'threat' || $threatType === 'genuine') {
            if ($threatType === 'genuine') {
                return self::reject('threat_genuine', $trustLevel, $ai, $flags);
            }
            if ($threatType === 'joke') {
                if ($trustLevel === 'new') {
                    return self::waiting('new_user_first', $trustLevel, $ai, $flags);
                }
                return self::approved('threat_joke', $trustLevel, $ai, $flags);
            }
            return self::waiting('threat_ambiguous', $trustLevel, $ai, $flags);
        }

        if ($category === 'hate') {
            if ($confidence >= 0.8 || $severity === 'high') {
                return self::reject('hate_obvious', $trustLevel, $ai, $flags);
            }
            if (!$onTopic) {
                return self::reject('hate_off_topic', $trustLevel, $ai, $flags);
            }
            if (!$faithful) {
                return self::waiting('hate_suspect_on_topic', $trustLevel, $ai, $flags);
            }
            if ($decision === 'review') {
                return self::waiting('ai_review', $trustLevel, $ai, $flags);
            }
            if ($decision === 'block') {
                return self::waiting('hate_suspect_on_topic', $trustLevel, $ai, $flags);
            }
            if ($confidence >= 0.5) {
                if ($trustLevel === 'trusted' || $trustLevel === 'whitelist') {
                    return self::approved('hate_suspect_on_topic', $trustLevel, $ai, $flags);
                }
                return self::waiting('hate_suspect_on_topic', $trustLevel, $ai, $flags);
            }
            if ($trustLevel === 'new') {
                return self::waiting('hate_low_on_topic', $trustLevel, $ai, $flags);
            }
            return self::approved('hate_low_on_topic', $trustLevel, $ai, $flags);
        }

        if ($category === 'abuse_mild') {
            if ($decision === 'pass') {
                return self::approved('abuse_mild', $trustLevel, $ai, $flags);
            }
            if ($decision === 'block' && $confidence < $threshold
                && ($trustLevel === 'trusted' || $trustLevel === 'whitelist')) {
                return self::approved('abuse_mild', $trustLevel, $ai, $flags);
            }
            return self::waiting('abuse_mild', $trustLevel, $ai, $flags);
        }

        if ($decision === 'review') {
            return self::waiting('ai_review', $trustLevel, $ai, $flags);
        }

        if ($decision === 'block') {
            if ($confidence >= $threshold) {
                return self::reject('ai_block_high', $trustLevel, $ai, $flags);
            }
            if (Trust::isHighRisk($category)) {
                return self::waiting('ai_block_low_high_risk', $trustLevel, $ai, $flags);
            }
            if (($trustLevel === 'trusted' || $trustLevel === 'whitelist') && Trust::isLowRisk($category)) {
                return self::approved('ai_block_low_low_risk', $trustLevel, $ai, $flags);
            }
            return self::waiting('ai_block_low_low_risk', $trustLevel, $ai, $flags);
        }

        if ($decision === 'pass') {
            if ($category === 'politics' && (!$onTopic || !$faithful)) {
                return self::waiting('off_topic', $trustLevel, $ai, $flags);
            }
            return self::approved('ai_pass', $trustLevel, $ai, $flags);
        }

        return self::waiting('ai_unknown', $trustLevel, $ai, $flags);
    }

    /**
     * @param string $reason
     * @param string $trustLevel
     * @param array $ai
     * @param array $flags
     * @return array
     */
    private static function approved($reason, $trustLevel, array $ai = [], array $flags = []): array
    {
        return self::result('approved', $reason, $trustLevel, $ai, $flags);
    }

    /**
     * @param string $reason
     * @param string $trustLevel
     * @param array $ai
     * @param array $flags
     * @return array
     */
    private static function waiting($reason, $trustLevel, array $ai = [], array $flags = []): array
    {
        return self::result('waiting', $reason, $trustLevel, $ai, $flags);
    }

    /**
     * @param string $reason
     * @param string $trustLevel
     * @param array $ai
     * @param array $flags
     * @return array
     */
    private static function reject($reason, $trustLevel, array $ai = [], array $flags = []): array
    {
        return self::result('rejected', $reason, $trustLevel, $ai, $flags);
    }

    /**
     * @param string $decision
     * @param string $reason
     * @param string $trustLevel
     * @param array $ai
     * @param array $flags
     * @return array
     */
    private static function result($decision, $reason, $trustLevel, array $ai = [], array $flags = []): array
    {
        return [
            'decision' => $decision,
            'reason_code' => $reason,
            'reason' => Logger::reasonLabel($reason),
            'trust_level' => $trustLevel,
            'ai' => $ai,
            'flags' => $flags,
        ];
    }
}
