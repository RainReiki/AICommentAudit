<?php

namespace AICommentAudit\Rules;

defined('ABSPATH') || exit;

/**
 * IP / email / URL blacklists.
 */
final class Blacklist
{
    /**
     * @param string $raw
     * @return string[]
     */
    public static function lines($raw): array
    {
        return WordFilter::lines($raw);
    }

    /**
     * @param string $ip
     * @param string $raw
     * @return bool
     */
    public static function matchIp($ip, $raw): bool
    {
        $ip = trim((string) $ip);
        if ($ip === '') {
            return false;
        }

        $ipBin = @inet_pton($ip);
        if ($ipBin === false) {
            return false;
        }

        foreach (self::lines($raw) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (strpos($line, '/') !== false) {
                if (self::cidrMatch($ipBin, $line)) {
                    return true;
                }
            } elseif ($line === $ip) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $ipBin
     * @param string $cidr
     * @return bool
     */
    private static function cidrMatch(string $ipBin, string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, '');
        $subnetBin = @inet_pton(trim($subnet));
        if ($subnetBin === false || strlen($subnetBin) !== strlen($ipBin)) {
            return false;
        }

        $bits = (int) $bits;
        $max = strlen($ipBin) * 8;
        if ($bits < 0 || $bits > $max) {
            return false;
        }

        $bytes = intdiv($bits, 8);
        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }

        $remainder = $bits % 8;
        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;
        return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
    }

    /**
     * @param string $mail
     * @param string $raw
     * @return bool
     */
    public static function matchEmail($mail, $raw): bool
    {
        $mail = strtolower(trim((string) $mail));
        if ($mail === '' || strpos($mail, '@') === false) {
            return false;
        }

        $domain = substr($mail, strrpos($mail, '@') + 1);

        foreach (self::lines($raw) as $line) {
            $line = strtolower(trim($line));
            if ($line === '') {
                continue;
            }

            if (strpos($line, '*@') === 0) {
                $line = substr($line, 2);
            } elseif (strpos($line, '@') === 0) {
                $line = substr($line, 1);
            }

            if (strpos($line, '@') !== false) {
                if ($line === $mail) {
                    return true;
                }
                continue;
            }

            if ($domain === $line || substr($domain, -strlen('.' . $line)) === '.' . $line) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $text
     * @param string $authorUrl
     * @param string $raw
     * @return bool
     */
    public static function matchUrl($text, $authorUrl, $raw): bool
    {
        $urls = self::extractUrls((string) $text);
        $authorUrl = trim((string) $authorUrl);
        if ($authorUrl !== '') {
            $urls[] = $authorUrl;
        }

        if (!$urls) {
            return false;
        }

        foreach (self::lines($raw) as $rule) {
            $rule = trim($rule);
            if ($rule === '') {
                continue;
            }

            foreach ($urls as $url) {
                if (self::matchOneUrl($url, $rule)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param string $text
     * @return string[]
     */
    public static function extractUrls($text): array
    {
        $text = (string) $text;
        $urls = [];

        if (preg_match_all('~https?://[^\s<>"\']+~iu', $text, $matches)) {
            foreach ($matches[0] as $match) {
                $urls[] = rtrim($match, '.,;:!?)]}');
            }
        }

        if (preg_match_all(
            '~(?<![\w@])(?:www\.)?(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}(?:/[^\s<>"\']*)?~iu',
            $text,
            $matches
        )) {
            foreach ($matches[0] as $match) {
                $urls[] = rtrim($match, '.,;:!?)]}');
            }
        }

        return array_values(array_unique(array_filter($urls)));
    }

    /**
     * @param string $url
     * @param string $rule
     * @return bool
     */
    private static function matchOneUrl($url, $rule): bool
    {
        $url = trim((string) $url);
        $rule = trim((string) $rule);

        if ($url === '' || $rule === '') {
            return false;
        }

        if (strpos($url, '://') === false) {
            $url = 'http://' . $url;
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return false;
        }

        $host = self::normalizeHost($parts['host']);
        $path = isset($parts['path']) ? (string) $parts['path'] : '/';

        if (strpos($rule, '://') !== false) {
            $ruleParts = wp_parse_url($rule);
            if (!is_array($ruleParts) || empty($ruleParts['host'])) {
                return false;
            }

            $ruleHost = self::normalizeHost($ruleParts['host']);
            if (!self::hostMatches($host, $ruleHost)) {
                return false;
            }

            $rulePath = isset($ruleParts['path']) ? (string) $ruleParts['path'] : '/';
            return $rulePath === '/' || strpos($path, $rulePath) === 0;
        }

        if (strpos($rule, '*.') === 0) {
            $ruleHost = self::normalizeHost(substr($rule, 2));
            return $host !== $ruleHost && substr($host, -strlen('.' . $ruleHost)) === '.' . $ruleHost;
        }

        if (strpos($rule, '/') !== false) {
            [$ruleHost, $rulePath] = explode('/', $rule, 2);
            $ruleHost = self::normalizeHost($ruleHost);
            $rulePath = '/' . ltrim($rulePath, '/');
            return self::hostMatches($host, $ruleHost) && strpos($path, $rulePath) === 0;
        }

        return self::hostMatches($host, self::normalizeHost($rule));
    }

    /**
     * @param string $host
     * @param string $ruleHost
     * @return bool
     */
    private static function hostMatches($host, $ruleHost): bool
    {
        return $host === $ruleHost || substr($host, -strlen('.' . $ruleHost)) === '.' . $ruleHost;
    }

    /**
     * @param string $host
     * @return string
     */
    private static function normalizeHost($host): string
    {
        $host = strtolower(trim((string) $host));
        $host = rtrim($host, '.');

        if (strpos($host, 'www.') === 0) {
            $host = substr($host, 4);
        }

        return $host;
    }
}
