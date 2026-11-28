<?php

namespace AICommentAudit\Update;

defined('ABSPATH') || exit;

/**
 * Loaded before the rest of the plugin. No dependency on replaceable classes.
 * A journal is written before each mutation, so interruption is recoverable.
 */
final class Guard
{
    private static $handle;
    private static string $directory = '';

    public static function directory(): string
    {
        if (self::$directory === '') {
            self::$directory = WP_CONTENT_DIR . '/aicommentaudit-private/'
                . substr(hash('sha256', wp_normalize_path(AICOMMENTAUDIT_DIR)), 0, 16);
        }
        return self::$directory;
    }

    public static function enter(): void
    {
        $dir = self::directory();
        // Read-only installations still run normally before their first update.
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return;
        }
        self::protect();
        // Only the updater's unpredictable, journal-bound probe may load the
        // new bootstrap while the exclusive lock is held. No plugin hooks run.
        $token = $_SERVER['HTTP_X_AICOMMENTAUDIT_HEALTH'] ?? '';
        if (is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token)) {
            $journal = self::readJournal();
            if ($journal && $journal['phase'] === 'verifying'
                && hash_equals($journal['health_hash'] ?? '', hash('sha256', $token))) {
                self::probe($token);
            }
        }
        self::$handle = @fopen($dir . '/lock', 'c+');
        if (!self::$handle || !flock(self::$handle, LOCK_SH | LOCK_NB)) {
            self::busy();
        }
        if (is_file($dir . '/journal.json')) {
            self::exclusive();
            try {
                self::recover();
            } catch (\Throwable $e) {
                error_log('[AICommentAudit update recovery] ' . $e->getMessage());
                $remaining = self::readJournal();
                if (!$remaining || $remaining['phase'] !== 'committed') {
                    wp_die(esc_html__('评论审核插件升级尚未恢复，请检查文件权限和数据库连接后重试。恢复备份已保留。', 'ai-comment-audit'), '', ['response' => 503]);
                }
            }
            flock(self::$handle, LOCK_SH);
        }
        // A request may have compiled the old entry point just before a swap.
        $header = @file_get_contents(AICOMMENTAUDIT_FILE);
        if (!is_string($header) || !preg_match('/Version:\s*([^\s]+)/', $header, $match)
            || $match[1] !== AICOMMENTAUDIT_VERSION) {
            self::busy();
        }
    }

    private static function probe(string $token): void
    {
        ob_start();
        try {
            require AICOMMENTAUDIT_DIR . 'includes/bootstrap.php';
            if (!class_exists('AICommentAudit\\Plugin', false)
                || !class_exists('AICommentAudit\\Update\\Updater', false)) {
                throw new \RuntimeException('升级入口未完整加载。');
            }
            $result = ['version' => AICOMMENTAUDIT_VERSION, 'proof' => hash('sha256', $token)];
            $status = 200;
        } catch (\Throwable $e) {
            $result = ['error' => 'bootstrap_failed'];
            $status = 500;
        }
        ob_end_clean();
        status_header($status);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode($result);
        exit;
    }

    public static function exclusive(): void
    {
        if (!self::$handle) {
            throw new \RuntimeException('无法创建升级锁，请检查 wp-content 目录权限。');
        }
        // Nonblocking: let existing requests drain; never hold a web worker waiting.
        flock(self::$handle, LOCK_UN);
        if (!flock(self::$handle, LOCK_EX | LOCK_NB)) {
            self::busy();
        }
    }

    public static function shared(): void
    {
        if (self::$handle) {
            flock(self::$handle, LOCK_SH);
        }
    }

    private static function busy(): void
    {
        if (!headers_sent()) {
            header('Retry-After: 5');
        }
        wp_die(esc_html__('评论审核插件正在升级或仍有请求处理中，请稍后刷新重试。', 'ai-comment-audit'), '', ['response' => 503]);
    }

    private static function protect(): void
    {
        $base = dirname(self::directory());
        foreach ([
            'index.php' => "<?php http_response_code(403); exit;\n",
            '.htaccess' => "Require all denied\nDeny from all\n",
            'web.config' => '<configuration><system.webServer><security><authorization><remove users="*" roles="" verbs=""/><add accessType="Deny" users="*"/></authorization></security></system.webServer></configuration>',
        ] as $name => $content) {
            if (!is_file($base . '/' . $name)) {
                @file_put_contents($base . '/' . $name, $content, LOCK_EX);
            }
        }
    }

    public static function path(string $relative, ?string $root = null): string
    {
        if ($relative === '' || strlen($relative) > 240 || $relative[0] === '.'
            || !preg_match('~^[A-Za-z0-9_][A-Za-z0-9_./-]*$~D', $relative)
            || preg_match('~(^|/)(\.{1,2}|\.git)(/|$)~', $relative)
            || strpos($relative, '//') !== false || substr($relative, -1) === '/') {
            throw new \RuntimeException('升级清单包含不安全的文件路径。');
        }
        $root = rtrim($root ?? AICOMMENTAUDIT_DIR, '/\\');
        $cursor = $root;
        foreach (explode('/', $relative) as $part) {
            $cursor .= '/' . $part;
            if (is_link($cursor)) {
                throw new \RuntimeException('升级目录包含符号链接，已停止升级。');
            }
        }
        return $root . '/' . $relative;
    }

    public static function write(string $path, string $bytes, ?int $mode = null): void
    {
        if (!is_dir(dirname($path)) && !wp_mkdir_p(dirname($path))) {
            throw new \RuntimeException('无法创建升级所需目录。');
        }
        $temp = tempnam(dirname($path), '.aica-');
        if (!$temp) {
            throw new \RuntimeException('无法创建临时升级文件。');
        }
        try {
            if (file_put_contents($temp, $bytes, LOCK_EX) !== strlen($bytes)
                || ($mode !== null && !chmod($temp, $mode & 0777)) || !rename($temp, $path)) {
                throw new \RuntimeException('无法安全写入升级文件。');
            }
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
        clearstatcache(true, $path);
    }

    public static function journal(array $journal): void
    {
        $bytes = json_encode($journal, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        self::write(self::directory() . '/journal.json', $bytes, 0600);
    }

    public static function readJournal(): ?array
    {
        $path = self::directory() . '/journal.json';
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['root'] ?? '') !== wp_normalize_path(AICOMMENTAUDIT_DIR)
            || !preg_match('/^[a-f0-9]{16}$/D', $data['id'] ?? '')) {
            throw new \RuntimeException('升级恢复记录无效。');
        }
        return $data;
    }

    public static function sql(string $sql): void
    {
        global $wpdb;
        if ($wpdb->query($sql) === false) {
            throw new \RuntimeException('升级数据库操作失败：' . $wpdb->last_error);
        }
    }

    public static function tableExists(string $table): bool
    {
        global $wpdb;
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if ($wpdb->last_error !== '') {
            throw new \RuntimeException('无法检查数据库表。');
        }
        return $found === $table;
    }

    private static function databaseMap(array $journal): array
    {
        global $wpdb;
        $result = [];
        foreach ($journal['tables'] ?? [] as $key => $row) {
            if (!in_array($key, ['logs', 'reputation', 'ai_cache', 'state'], true)) {
                throw new \RuntimeException('数据库恢复表名无效。');
            }
            $live = $wpdb->prefix . 'aicommentaudit_' . $key;
            $suffix = substr($journal['id'], 0, 12);
            $expected = ['live' => $live, 'stage' => 'aica_s_' . $suffix . '_' . $key, 'backup' => 'aica_b_' . $suffix . '_' . $key];
            if ($row !== $expected || !preg_match('/^[A-Za-z0-9_]+$/D', $live)) {
                throw new \RuntimeException('数据库恢复表名不匹配。');
            }
            $result[$key] = $row;
        }
        return $result;
    }

    public static function recover(): void
    {
        $j = self::readJournal();
        if ($j === null) {
            return;
        }
        $tables = self::databaseMap($j);
        $workRoot = self::directory() . '/' . $j['id'];
        $backupRoot = $workRoot . '/backup';
        if ($j['phase'] !== 'committed') {
            // RENAME TABLE swaps all tables atomically. Backup existence resolves
            // an interruption between the swap and the next journal write.
            $renames = [];
            foreach ($tables as $row) {
                if (self::tableExists($row['backup'])) {
                    $renames[] = "`{$row['live']}` TO `{$row['stage']}`";
                    $renames[] = "`{$row['backup']}` TO `{$row['live']}`";
                }
            }
            if ($renames) {
                self::sql('RENAME TABLE ' . implode(', ', $renames));
            }
            foreach (array_reverse($j['operations']) as $operation) {
                $target = self::path($operation['path']);
                if ($operation['existed']) {
                    $backup = self::path($operation['path'], $backupRoot);
                    $bytes = @file_get_contents($backup);
                    if (!is_string($bytes) || hash('sha256', $bytes) !== $operation['backup_hash']) {
                        throw new \RuntimeException('恢复备份缺失或校验失败。');
                    }
                    self::write($target, $bytes, $operation['mode']);
                    touch($target, $operation['mtime']);
                } elseif (is_file($target) && !unlink($target)) {
                    throw new \RuntimeException('无法移除升级新增文件。');
                }
            }
            if ($j['old_db_version'] === null) {
                delete_option('aicommentaudit_migration_version');
            } else {
                update_option('aicommentaudit_migration_version', $j['old_db_version'], false);
            }
            if (get_option('aicommentaudit_migration_version', null) !== $j['old_db_version']) {
                throw new \RuntimeException('无法恢复数据库版本记录，备份已保留。');
            }
            self::report('rolled_back', '上次升级未完成，已恢复全部文件和插件数据库。');
        }
        foreach ($tables as $row) {
            foreach (['stage', 'backup'] as $kind) {
                self::sql('DROP TABLE IF EXISTS `' . $row[$kind] . '`');
            }
        }
        // Mark cleanup resumable before removing backups. A second interruption
        // must never try to restore already deleted backups.
        $j['phase'] = 'committed';
        self::journal($j);
        self::removeDirectory($workRoot);
        if (!unlink(self::directory() . '/journal.json')) {
            throw new \RuntimeException('无法清理升级恢复记录。');
        }
    }

    public static function removeDirectory(string $path): void
    {
        $base = wp_normalize_path(self::directory()) . '/';
        if (strpos(wp_normalize_path($path), $base) !== 0 || is_link($path)) {
            throw new \RuntimeException('无效的升级清理路径。');
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (new \FilesystemIterator($path) as $file) {
            if ($file->isDir() && !$file->isLink()) {
                self::removeDirectory($file->getPathname());
            } elseif (!unlink($file->getPathname())) {
                throw new \RuntimeException('无法清理升级临时文件。');
            }
        }
        if (!rmdir($path)) {
            throw new \RuntimeException('无法清理升级临时目录。');
        }
    }

    public static function report(string $status, string $message): void
    {
        update_option('aicommentaudit_update_result', ['status' => $status, 'message' => $message, 'time' => time()], false);
    }
}
