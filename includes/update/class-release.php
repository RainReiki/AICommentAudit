<?php

namespace AICommentAudit\Update;

defined('ABSPATH') || exit;

final class Release
{
    public const REPO = 'RainReiki/AICommentAudit';
    // A new key discards older cached releases containing Markdown text only.
    public const CACHE = 'aicommentaudit_github_release_html';
    public const MANIFEST = 'update-manifest.json';
    public const MAX_FILE = 2097152;

    public static function validVersion($version): bool
    {
        return is_string($version) && (bool) preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D', $version);
    }

    public static function latest(bool $force = false): array
    {
        $cached = get_transient(self::CACHE);
        if (!$force && is_array($cached)) {
            return $cached;
        }
        $response = self::request('https://api.github.com/repos/' . self::REPO . '/releases/latest', 262144,
            'application/vnd.github.full+json');
        $release = json_decode($response, true, 64, JSON_THROW_ON_ERROR);
        $tag = $release['tag_name'] ?? '';
        $version = preg_replace('/^v/', '', $tag);
        if (!self::validVersion($version) || !empty($release['draft']) || !empty($release['prerelease'])
            || !in_array($tag, [$version, 'v' . $version], true)) {
            throw new \RuntimeException('GitHub Release 不是受支持的正式版本。');
        }
        $asset = null;
        foreach ($release['assets'] ?? [] as $candidate) {
            if (($candidate['name'] ?? '') === self::MANIFEST) {
                $asset = $candidate;
                break;
            }
        }
        $manifest = null;
        if ($asset) {
            $expected = 'https://github.com/' . self::REPO . '/releases/download/' . $tag . '/' . self::MANIFEST;
            if (($asset['browser_download_url'] ?? '') !== $expected) {
                throw new \RuntimeException('Release 升级清单下载地址无效。');
            }
            $bytes = self::request($expected, 524288);
            $digest = $asset['digest'] ?? '';
            if (!is_string($digest) || !preg_match('/^sha256:[a-f0-9]{64}$/D', $digest)
                || !hash_equals(substr($digest, 7), hash('sha256', $bytes))) {
                throw new \RuntimeException('Release 升级清单校验失败。');
            }
            $manifest = self::manifest($bytes, $version);
        }
        $result = ['version' => $version, 'tag' => $tag, 'manifest' => $manifest,
            'notes' => substr(wp_strip_all_tags((string) ($release['body'] ?? '')), 0, 12000),
            'notes_html' => !empty($release['body_html']) ? wp_kses_post((string) $release['body_html']) : '',
            'checked' => time()];
        set_transient(self::CACHE, $result, 6 * HOUR_IN_SECONDS);
        return $result;
    }

    public static function manifest(string $bytes, string $version): array
    {
        $m = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($m) || ($m['format'] ?? 0) !== 1 || ($m['repository'] ?? '') !== self::REPO
            || ($m['version'] ?? '') !== $version || !self::validVersion($version)
            || ($m['migration_baseline'] ?? '') !== Migrations::BASELINE
            || !isset($m['files'], $m['migrations']) || !is_array($m['files']) || !is_array($m['migrations'])
            || count($m['files']) < 5 || count($m['files']) > 500
            || !preg_match('/^\d+\.\d+(?:\.\d+)?$/D', $m['requires_php'] ?? '')
            || !preg_match('/^\d+\.\d+(?:\.\d+)?$/D', $m['requires_wp'] ?? '')) {
            throw new \RuntimeException('Release 升级清单格式或版本不匹配。');
        }
        $casePaths = [];
        foreach ($m['files'] as $path => $entry) {
            Guard::path($path);
            if (!is_array($entry) || !preg_match('/^[a-f0-9]{64}$/D', $entry['sha256'] ?? '')
                || !is_int($entry['size'] ?? null) || $entry['size'] < 0 || $entry['size'] > self::MAX_FILE
                || isset($casePaths[strtolower($path)]) || $path === self::MANIFEST) {
                throw new \RuntimeException('Release 文件清单无效。');
            }
            $casePaths[strtolower($path)] = true;
        }
        foreach (['ai-comment-audit.php', 'includes/bootstrap.php', 'includes/update/class-guard.php', 'includes/update/class-updater.php'] as $required) {
            if (!isset($m['files'][$required])) {
                throw new \RuntimeException('Release 缺少必要文件。');
            }
        }
        Migrations::pending($m, Migrations::BASELINE);
        return $m;
    }

    public static function request(string $url, int $limit, string $accept = 'application/vnd.github+json'): string
    {
        $response = wp_safe_remote_get($url, ['timeout' => 30, 'redirection' => 5,
            'limit_response_size' => $limit + 1, 'headers' => ['Accept' => $accept,
                'User-Agent' => 'AICommentAudit/' . AICOMMENTAUDIT_VERSION]]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            throw new \RuntimeException('无法从 GitHub 获取升级文件，请稍后重试（可能是网络或 API 频率限制）。');
        }
        $body = wp_remote_retrieve_body($response);
        if (!is_string($body) || strlen($body) > $limit) {
            throw new \RuntimeException('升级文件超过允许大小。');
        }
        return $body;
    }

    public static function file(array $release, string $path): string
    {
        Guard::path($path);
        $entry = $release['manifest']['files'][$path];
        $url = 'https://raw.githubusercontent.com/' . self::REPO . '/' . $release['tag'] . '/' . $path;
        $bytes = self::request($url, self::MAX_FILE);
        self::verify($bytes, $entry);
        return $bytes;
    }

    public static function verify(string $bytes, array $entry): void
    {
        if (strlen($bytes) !== $entry['size'] || !hash_equals($entry['sha256'], hash('sha256', $bytes))) {
            throw new \RuntimeException('差异文件大小或 SHA-256 校验失败。');
        }
    }
}
