<?php

namespace AICommentAudit\Update;

defined('ABSPATH') || exit;

final class Updater
{
    public static function plan(array $target, array $installed): array
    {
        $operations = [];
        $unchanged = 0;
        foreach ($target['files'] as $path => $entry) {
            $absolute = Guard::path($path);
            if (file_exists($absolute) && !is_file($absolute)) {
                throw new \RuntimeException('升级文件路径被目录占用：' . $path);
            }
            $hash = is_file($absolute) ? hash_file('sha256', $absolute) : null;
            if ($hash === $entry['sha256']) {
                $unchanged++;
                continue;
            }
            if ($hash !== null && $hash !== ($installed['files'][$path]['sha256'] ?? null)) {
                throw new \RuntimeException('检测到本地修改，为保留定制内容已停止升级：' . $path);
            }
            $operations[$path] = ['path' => $path, 'delete' => false];
        }
        foreach ($installed['files'] as $path => $entry) {
            if (isset($target['files'][$path])) {
                continue;
            }
            if (strpos($path, 'migrations/') === 0) {
                throw new \RuntimeException('新版清单必须保留所有历史数据库变更文件。');
            }
            $absolute = Guard::path($path);
            if (is_file($absolute)) {
                if (hash_file('sha256', $absolute) !== $entry['sha256']) {
                    throw new \RuntimeException('待移除文件包含本地修改，已停止升级：' . $path);
                }
                $operations[$path] = ['path' => $path, 'delete' => true];
            }
        }
        // Published migrations are append-only, including already applied ones.
        foreach ($installed['migrations'] as $entry) {
            $path = $entry['path'];
            if (($installed['files'][$path]['sha256'] ?? null) !== ($target['files'][$path]['sha256'] ?? null)) {
                throw new \RuntimeException('已发布的数据库变更文件不能改写或删除。');
            }
        }
        // Entry point last. All readers are locked, but this also makes manual
        // diagnosis of an interrupted deployment less surprising.
        if (isset($operations['ai-comment-audit.php'])) {
            $entry = $operations['ai-comment-audit.php'];
            unset($operations['ai-comment-audit.php']);
            $operations['ai-comment-audit.php'] = $entry;
        }
        return ['operations' => array_values($operations), 'unchanged' => $unchanged];
    }

    public static function install(): array
    {
        if (is_multisite()) {
            throw new \RuntimeException('当前事务升级支持单站点；多站点数据库需要逐站迁移，请暂用手动升级。');
        }
        if (!is_dir(Guard::directory())) {
            throw new \RuntimeException('升级工作目录不可写。');
        }
        // GitHub is rechecked at execution time; the browser cannot choose a URL.
        $release = Release::latest(true);
        if (!version_compare($release['version'], AICOMMENTAUDIT_VERSION, '>')) {
            throw new \RuntimeException('当前已是最新正式版。');
        }
        if (!$release['manifest']) {
            throw new \RuntimeException('该 Release 尚未提供差异升级清单，请使用正式安装包。');
        }
        $target = $release['manifest'];
        self::requirements($target);
        $installedBytes = @file_get_contents(AICOMMENTAUDIT_DIR . Release::MANIFEST);
        if (!is_string($installedBytes)) {
            throw new \RuntimeException('本机升级清单缺失，请先手动安装带在线升级功能的正式版。');
        }
        $installed = Release::manifest($installedBytes, AICOMMENTAUDIT_VERSION);
        $version = get_option('aicommentaudit_migration_version', Migrations::BASELINE);
        if (!is_string($version) || !Release::validVersion($version) || $version !== AICOMMENTAUDIT_VERSION) {
            throw new \RuntimeException('本机数据库版本与插件版本不一致，请先完成当前版本的数据库迁移。');
        }
        $pending = Migrations::pending($target, $version);
        $plan = self::plan($target, $installed);
        $id = bin2hex(random_bytes(8));
        $work = Guard::directory() . '/' . $id;
        $stage = $work . '/staged';
        if (!wp_mkdir_p($stage)) {
            throw new \RuntimeException('无法创建升级暂存目录。');
        }
        $active = false;
        $reserve = str_repeat('x', 262144);
        register_shutdown_function(static function () use (&$active, &$reserve) {
            if (!$active) {
                return;
            }
            $reserve = '';
            try {
                Guard::recover();
            } catch (\Throwable $e) {
                error_log('[AICommentAudit update shutdown] ' . $e->getMessage());
            }
        });
        try {
            $downloaded = 0;
            foreach ($plan['operations'] as $operation) {
                if (!$operation['delete']) {
                    $bytes = Release::file($release, $operation['path']);
                    self::lint($operation['path'], $bytes);
                    Guard::write(Guard::path($operation['path'], $stage), $bytes, 0644);
                    $downloaded += strlen($bytes);
                }
            }
            $documents = [];
            foreach ($pending as $entry) {
                $staged = Guard::path($entry['path'], $stage);
                $bytes = file_get_contents(is_file($staged) ? $staged : Guard::path($entry['path']));
                Release::verify($bytes, $target['files'][$entry['path']]);
                $document = Migrations::document($bytes, $entry['version']);
                if ($document['from'] !== $entry['from']) {
                    throw new \RuntimeException('数据库变更文件的前置版本不匹配。');
                }
                $documents[] = $document;
            }
            $headerPath = Guard::path('ai-comment-audit.php', $stage);
            $header = file_get_contents(is_file($headerPath) ? $headerPath : AICOMMENTAUDIT_FILE);
            if (!preg_match('/Version:\s*([^\s]+)/', $header, $match) || $match[1] !== $target['version']) {
                throw new \RuntimeException('插件入口版本与 Release 不一致。');
            }
            Guard::exclusive();
            // Recheck after taking the lock, including all local fingerprints.
            if (file_get_contents(AICOMMENTAUDIT_DIR . Release::MANIFEST) !== $installedBytes
                || self::plan($target, $installed) !== $plan) {
                throw new \RuntimeException('准备期间插件文件发生变化，请重新检查更新。');
            }
            $journal = ['id' => $id, 'root' => wp_normalize_path(AICOMMENTAUDIT_DIR), 'phase' => 'prepared',
                'operations' => [], 'tables' => [], 'old_db_version' => get_option('aicommentaudit_migration_version', null)];
            $operations = $plan['operations'];
            $operations[] = ['path' => Release::MANIFEST, 'delete' => false];
            $targetBytes = json_encode($target, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            Guard::write($stage . '/' . Release::MANIFEST, $targetBytes, 0644);
            foreach ($operations as $operation) {
                $path = Guard::path($operation['path']);
                self::writable($path);
                $operation['existed'] = is_file($path);
                $operation['mode'] = $operation['existed'] ? (fileperms($path) & 0777) : 0644;
                $operation['mtime'] = $operation['existed'] ? filemtime($path) : 0;
                $operation['backup_hash'] = null;
                if ($operation['existed']) {
                    $bytes = file_get_contents($path);
                    $operation['backup_hash'] = hash('sha256', $bytes);
                    Guard::write(Guard::path($operation['path'], $work . '/backup'), $bytes, 0600);
                }
                $journal['operations'][] = $operation;
            }
            Guard::journal($journal);
            $active = true;
            ignore_user_abort(true);
            if (function_exists('set_time_limit')) {
                @set_time_limit(300);
            }
            Migrations::prepare($journal, $documents);
            $journal['phase'] = 'applying';
            Guard::journal($journal);
            foreach ($journal['operations'] as $operation) {
                $path = Guard::path($operation['path']);
                if ($operation['delete']) {
                    if (!unlink($path)) {
                        throw new \RuntimeException('无法删除已淘汰文件：' . $operation['path']);
                    }
                    if (function_exists('opcache_invalidate')) {
                        @opcache_invalidate($path, true);
                    }
                } else {
                    $bytes = file_get_contents(Guard::path($operation['path'], $stage));
                    Guard::write($path, $bytes, $operation['mode']);
                    if (hash_file('sha256', $path) !== hash('sha256', $bytes)) {
                        throw new \RuntimeException('安装后文件校验失败。');
                    }
                }
            }
            // The journal already lists backups before the atomic database swap.
            Migrations::swap($journal);
            update_option('aicommentaudit_migration_version', $target['version'], false);
            if (get_option('aicommentaudit_migration_version') !== $target['version']) {
                throw new \RuntimeException('数据库版本记录写入失败。');
            }
            $token = bin2hex(random_bytes(32));
            $journal['phase'] = 'verifying';
            $journal['health_hash'] = hash('sha256', $token);
            Guard::journal($journal);
            self::health($target['version'], $token);
            $journal['phase'] = 'committed';
            Guard::journal($journal);
            $active = false;
            $result = ['version' => $target['version'], 'files' => count($plan['operations']),
                'unchanged' => $plan['unchanged'], 'bytes' => $downloaded, 'migrations' => count($documents)];
            Guard::report('success', sprintf('已升级至 %s；处理 %d 个差异文件，跳过 %d 个未变文件，依次完成 %d 个版本记录。',
                $result['version'], $result['files'], $result['unchanged'], $result['migrations']));
            try {
                Guard::recover(); // Committed: cleanup only, never undo success.
            } catch (\Throwable $e) {
                error_log('[AICommentAudit update cleanup] ' . $e->getMessage());
            }
            delete_transient(Release::CACHE);
            return $result;
        } catch (\Throwable $e) {
            if ($active) {
                try {
                    Guard::recover();
                    $active = false;
                } catch (\Throwable $recovery) {
                    throw new \RuntimeException('升级失败，自动恢复尚未完成；备份已保留，下次请求会重试恢复。' . $recovery->getMessage(), 0, $e);
                }
            } else {
                Guard::removeDirectory($work);
            }
            throw $e;
        }
    }

    public static function requirements(array $manifest): void
    {
        if (version_compare(PHP_VERSION, $manifest['requires_php'], '<')
            || version_compare(get_bloginfo('version'), $manifest['requires_wp'], '<')) {
            throw new \RuntimeException('服务器的 PHP 或 WordPress 版本不满足新版要求。');
        }
    }

    private static function health(string $version, string $token): void
    {
        $response = wp_safe_remote_get(admin_url('admin-ajax.php'), ['timeout' => 30, 'redirection' => 0,
            'limit_response_size' => 4096, 'headers' => ['X-AICommentAudit-Health' => $token,
                'Cache-Control' => 'no-cache']]);
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (is_array($body) && ($body['version'] ?? '') === $version
                && hash_equals(hash('sha256', $token), $body['proof'] ?? '')) {
                return;
            }
        }
        throw new \RuntimeException('新版加载验收失败，或站点不允许回环请求；本次升级将整体回滚。');
    }

    public static function lint(string $path, string $bytes): void
    {
        if (substr($path, -4) === '.php') {
            try {
                token_get_all($bytes, TOKEN_PARSE);
            } catch (\ParseError $e) {
                throw new \RuntimeException('新版 PHP 文件语法不兼容：' . $path, 0, $e);
            }
        }
    }

    private static function writable(string $path): void
    {
        $parent = dirname($path);
        while (!file_exists($parent)) {
            $parent = dirname($parent);
        }
        if (!is_dir($parent) || !is_writable($parent) || (is_file($path) && !is_readable($path))) {
            throw new \RuntimeException('插件目录权限不允许直接安全升级。');
        }
    }
}
