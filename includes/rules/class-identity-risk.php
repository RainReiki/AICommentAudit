<?php

namespace AICommentAudit\Rules;

defined('ABSPATH') || exit;

/**
 * Identity risk score.
 *
 * The AI is the main judge (0-10). Local checks only cover objective patterns:
 * placeholder email addresses, IP URLs, punycode and embedded credentials.
 * Final score = max(AI score, local score).
 */
final class IdentityRisk
{
    private const RANK = ['low' => 0, 'medium' => 1, 'high' => 2];
    public const MEDIUM_SCORE = 3;
    public const HIGH_SCORE = 6;

    private const PLACEHOLDER_EMAILS = [
        'test', 'testing', 'ceshi', 'demo', 'admin', 'administrator', 'root',
        'guest', 'example', 'sample', 'fake', 'spam', 'temp', 'tmp', 'abc',
        'aaa', 'yourname', 'your', 'name', 'mail', 'email',
    ];

    /**
     * @param array $comment
     * @return array{risk:string,score:int,category:string,sources:array,evidence:array}
     */
    public static function evaluate(array $comment): array
    {
        $mail = strtolower(trim((string) ($comment['mail'] ?? '')));
        $url = trim((string) ($comment['url'] ?? ''));

        $signals = [];
        if (self::placeholderEmail($mail)) {
            self::addSignal($signals, 'placeholder_email', '邮箱前缀为纯数字或测试式占位', 2);
        }

        $host = self::host($url);
        if ($host !== '') {
            if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
                self::addSignal($signals, 'ip_website', '网址使用 IP 地址', 2);
            }
            if (strpos($host, 'xn--') !== false) {
                self::addSignal($signals, 'encoded_website', '网址域名包含异常编码', 2);
            }
            if (self::hasUserInfo($url)) {
                self::addSignal($signals, 'credential_website', '网址包含跳转凭据', 2);
            }
        }

        $score = 0;
        $sources = [];
        $evidence = [];
        foreach ($signals as $signal) {
            $score += (int) $signal['weight'];
            $sources[] = (string) $signal['code'];
            $evidence[] = (string) $signal['label'];
        }

        return [
            'risk' => self::riskFromScore($score),
            'score' => $score,
            'category' => $score >= self::MEDIUM_SCORE ? 'profile_conflict' : 'none',
            'sources' => $sources,
            'evidence' => array_slice($evidence, 0, 4),
        ];
    }

    /**
     * @param array|null $identity
     * @param array $local
     * @return array|null
     */
    public static function merge($identity, array $local): ?array
    {
        $hasAi = is_array($identity) && isset($identity['risk']);
        $localScore = max(0, (int) ($local['score'] ?? 0));
        $localRisk = (string) ($local['risk'] ?? self::riskFromScore($localScore));

        if (!$hasAi && self::rank($localRisk) === 0) {
            return null;
        }

        if (!$hasAi) {
            $identity = ['risk' => 'low', 'category' => 'none', 'sources' => [], 'evidence' => []];
        }

        $aiScore = self::aiScore($identity);
        $score = max($aiScore, $localScore);
        $risk = self::riskFromScore($score);
        $aiCategory = (string) ($identity['category'] ?? 'none');
        $localCategory = (string) ($local['category'] ?? 'none');

        if ($aiScore >= $localScore && $aiCategory !== 'none') {
            $category = $aiCategory;
        } elseif ($localCategory !== 'none') {
            $category = $localCategory;
        } else {
            $category = $aiCategory;
        }
        if ($category === 'none' && $risk !== 'low') {
            $category = 'profile_conflict';
        }

        $sources = array_values(array_unique(array_merge(
            (array) ($identity['sources'] ?? []),
            (array) ($local['sources'] ?? [])
        )));

        $aiEvidence = array_values(array_filter((array) ($identity['evidence'] ?? []), 'is_string'));
        $localEvidence = array_values(array_filter((array) ($local['evidence'] ?? []), 'is_string'));
        $evidence = $aiScore >= $localScore
            ? array_merge($aiEvidence, $localEvidence)
            : array_merge($localEvidence, $aiEvidence);
        $evidence = array_slice(array_values(array_unique($evidence)), 0, 4);
        if ($risk !== 'low' && !$evidence) {
            $evidence = ['身份信息组合异常'];
        }

        return [
            'risk' => $risk,
            'category' => $category,
            'sources' => $sources,
            'evidence' => $evidence,
            'score' => $score,
            'ai_score' => $aiScore,
            'local_score' => $localScore,
            'local_risk' => $localRisk,
        ];
    }

    /**
     * @param string $risk
     * @return int
     */
    public static function rank(string $risk): int
    {
        return self::RANK[$risk] ?? 0;
    }

    /**
     * @param array $identity
     * @return int
     */
    private static function aiScore(array $identity): int
    {
        if (isset($identity['score']) && is_numeric($identity['score'])) {
            return max(0, min(10, (int) round((float) $identity['score'])));
        }

        return match (self::rank((string) ($identity['risk'] ?? 'low'))) {
            2 => 7,
            1 => 4,
            default => 0,
        };
    }

    /**
     * @param int $score
     * @return string
     */
    private static function riskFromScore(int $score): string
    {
        if ($score >= self::HIGH_SCORE) {
            return 'high';
        }

        return $score >= self::MEDIUM_SCORE ? 'medium' : 'low';
    }

    /**
     * @param string $mail
     * @return bool
     */
    private static function placeholderEmail(string $mail): bool
    {
        $at = strrpos($mail, '@');
        if ($at === false || $at === 0) {
            return false;
        }

        $local = strtolower(substr($mail, 0, $at));
        if (preg_match('/^\d{1,6}$/', $local)) {
            return true;
        }

        $compact = preg_replace('/[^a-z0-9]/', '', $local) ?? '';
        if ($compact === '') {
            return false;
        }

        foreach (self::PLACEHOLDER_EMAILS as $token) {
            if ($compact === $token) {
                return true;
            }
            if (strpos($compact, $token) === 0
                && preg_match('/^\d{0,6}$/', substr($compact, strlen($token)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $url
     * @return string
     */
    private static function host(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $candidate = strpos($url, '://') === false ? 'http://' . $url : $url;
        $host = wp_parse_url($candidate, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return '';
        }

        return strtolower(trim($host, " \t\n\r\0\x0B."));
    }

    /**
     * @param string $url
     * @return bool
     */
    private static function hasUserInfo(string $url): bool
    {
        $candidate = strpos($url, '://') === false ? 'http://' . $url : $url;
        return (bool) preg_match('~^[a-z][a-z0-9+.-]*://[^/?#]*@~i', $candidate);
    }

    /**
     * @param array $signals
     * @param string $code
     * @param string $label
     * @param int $weight
     * @return void
     */
    private static function addSignal(array &$signals, string $code, string $label, int $weight): void
    {
        if (!isset($signals[$code])) {
            $signals[$code] = ['code' => $code, 'label' => $label, 'weight' => $weight];
        }
    }
}
