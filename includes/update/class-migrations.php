<?php

namespace AICommentAudit\Update;

defined('ABSPATH') || exit;

/**
 * Declarative migrations execute only on private copies of plugin tables.
 * MySQL DDL implicitly commits: never run it on the live tables before commit.
 */
final class Migrations
{
    public const BASELINE = '1.4.0';

    /** Also run the complete version chain after a manual ZIP installation. */
    public static function upgradeInstalled(): void
    {
        $old = get_option('aicommentaudit_migration_version', null);
        if ($old === AICOMMENTAUDIT_VERSION) {
            return;
        }
        try {
            if (is_multisite()) {
                // Initial no-op baseline is safe per site; future database
                // migrations require an explicitly supported network workflow.
                if (AICOMMENTAUDIT_VERSION === '1.5.0' && $old === null) {
                    update_option('aicommentaudit_migration_version', AICOMMENTAUDIT_VERSION, false);
                    return;
                }
                throw new \RuntimeException('多站点暂不支持自动数据库迁移。');
            }
            $current = $old ?? self::BASELINE;
            if (!Release::validVersion($current) || version_compare($current, AICOMMENTAUDIT_VERSION, '>')) {
                throw new \RuntimeException('数据库版本高于插件版本或记录无效，不能降级运行。');
            }
            $manifest = Release::manifest((string) file_get_contents(AICOMMENTAUDIT_DIR . Release::MANIFEST), AICOMMENTAUDIT_VERSION);
            $documents = [];
            foreach (self::pending($manifest, $current) as $entry) {
                $bytes = file_get_contents(Guard::path($entry['path']));
                Release::verify($bytes, $manifest['files'][$entry['path']]);
                $document = self::document($bytes, $entry['version']);
                if ($document['from'] !== $entry['from']) {
                    throw new \RuntimeException('数据库变更文件的前置版本不匹配。');
                }
                $documents[] = $document;
            }
            if (!array_filter($documents, static fn ($document) => !empty($document['steps']))) {
                // No DDL/data changes: an idempotent version marker also works
                // on read-only code deployments which cannot create a lock.
                update_option('aicommentaudit_migration_version', AICOMMENTAUDIT_VERSION, false);
                if (get_option('aicommentaudit_migration_version') !== AICOMMENTAUDIT_VERSION) {
                    throw new \RuntimeException('无法保存数据库迁移版本。');
                }
                return;
            }
            Guard::exclusive();
            // Another request may have completed this while the manifest loaded.
            if (get_option('aicommentaudit_migration_version', null) === AICOMMENTAUDIT_VERSION) {
                Guard::shared();
                return;
            }
            $journal = ['id' => bin2hex(random_bytes(8)), 'root' => wp_normalize_path(AICOMMENTAUDIT_DIR),
                'phase' => 'prepared', 'operations' => [], 'tables' => [], 'old_db_version' => $old];
            Guard::journal($journal);
            self::prepare($journal, $documents);
            self::swap($journal);
            update_option('aicommentaudit_migration_version', AICOMMENTAUDIT_VERSION, false);
            if (get_option('aicommentaudit_migration_version') !== AICOMMENTAUDIT_VERSION) {
                throw new \RuntimeException('无法保存数据库迁移版本。');
            }
            $journal['phase'] = 'committed';
            Guard::journal($journal);
            Guard::recover();
            Guard::shared();
        } catch (\Throwable $e) {
            try {
                Guard::recover();
            } catch (\Throwable $recovery) {
                error_log('[AICommentAudit migration recovery] ' . $recovery->getMessage());
            }
            error_log('[AICommentAudit migration] ' . $e->getMessage());
            wp_die(esc_html__('评论审核插件数据库升级未完成，备份已保留。请检查数据库权限和升级文件后重试。', 'ai-comment-audit'), '', ['response' => 503]);
        }
    }

    public static function pending(array $manifest, string $installed): array
    {
        $result = [];
        $previous = self::BASELINE;
        foreach ($manifest['migrations'] as $entry) {
            $version = $entry['version'] ?? '';
            if (!Release::validVersion($version) || ($entry['from'] ?? '') !== $previous || version_compare($version, $previous, '<=')
                || version_compare($version, $manifest['version'], '>')
                || ($entry['path'] ?? '') !== 'migrations/' . $version . '.json'
                || !isset($manifest['files'][$entry['path']])) {
                throw new \RuntimeException('数据库版本链无效或缺少变更文件。');
            }
            if (version_compare($version, $installed, '>')) {
                $result[] = $entry;
            }
            $previous = $version;
        }
        if ($previous !== $manifest['version']) {
            throw new \RuntimeException('数据库版本链没有覆盖目标版本。');
        }
        return $result;
    }

    public static function document(string $bytes, string $version): array
    {
        $document = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($document) || ($document['version'] ?? '') !== $version
            || !Release::validVersion($document['from'] ?? null) || !version_compare($version, $document['from'], '>')
            || !isset($document['steps']) || !is_array($document['steps']) || count($document['steps']) > 200) {
            throw new \RuntimeException('数据库变更文件格式无效。');
        }
        foreach ($document['steps'] as $step) {
            self::validateStep($step);
        }
        return $document;
    }

    private static function identifier($name): string
    {
        if (!is_string($name) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $name)) {
            throw new \RuntimeException('数据库变更字段名无效。');
        }
        return '`' . $name . '`';
    }

    private static function validateStep($step): void
    {
        if (!is_array($step) || !in_array($step['table'] ?? '', ['logs', 'reputation', 'ai_cache', 'state'], true)) {
            throw new \RuntimeException('数据库变更只能操作插件自己的四张表。');
        }
        $action = $step['action'] ?? '';
        if (in_array($action, ['add_column', 'modify_column', 'drop_column'], true)) {
            self::identifier($step['column'] ?? null);
            if ($action !== 'drop_column') {
                // Column definitions only. Reject expressions, subqueries and
                // separators so a migration cannot escape the shadow table.
                $definition = $step['definition'] ?? '';
                if (!is_string($definition) || strlen($definition) > 300
                    || !preg_match("~^(?:BIGINT|INT|SMALLINT|TINYINT|VARCHAR|CHAR|TEXT|LONGTEXT|MEDIUMTEXT|DECIMAL|DATETIME|TIMESTAMP|DATE|DOUBLE|FLOAT|BLOB|LONGBLOB|VARBINARY)(?:\([0-9, ]+\))?(?: UNSIGNED)?(?: (?:NOT NULL|NULL))?(?: DEFAULT (?:NULL|-?[0-9.]+|'[A-Za-z0-9 _.-]*'))?$~iD", $definition)) {
                    throw new \RuntimeException('数据库字段定义不受支持。');
                }
            }
        } elseif (in_array($action, ['add_index', 'drop_index'], true)) {
            self::identifier($step['name'] ?? null);
            if ($action === 'add_index') {
                if (empty($step['columns']) || !is_array($step['columns']) || count($step['columns']) > 8) {
                    throw new \RuntimeException('数据库索引定义无效。');
                }
                foreach ($step['columns'] as $column) {
                    self::identifier($column);
                }
            }
        } elseif ($action === 'update_rows') {
            foreach (['values', 'where'] as $field) {
                if (empty($step[$field]) || !is_array($step[$field])) {
                    throw new \RuntimeException('数据迁移必须指定字段值和匹配条件。');
                }
                foreach ($step[$field] as $key => $value) {
                    self::identifier($key);
                    if ($value !== null && !is_scalar($value)) {
                        throw new \RuntimeException('数据迁移字段值无效。');
                    }
                }
            }
        } else {
            throw new \RuntimeException('未知的数据库迁移操作。');
        }
    }

    public static function prepare(array &$journal, array $documents): void
    {
        global $wpdb;
        $hasChanges = false;
        foreach ($documents as $document) {
            $hasChanges = $hasChanges || !empty($document['steps']);
        }
        if (!$hasChanges) {
            return;
        }
        $suffix = substr($journal['id'], 0, 12);
        foreach (['logs', 'reputation', 'ai_cache', 'state'] as $key) {
            $live = $wpdb->prefix . 'aicommentaudit_' . $key;
            if (!preg_match('/^[A-Za-z0-9_]{1,64}$/D', $live) || !Guard::tableExists($live)) {
                throw new \RuntimeException('插件数据库表不完整，无法升级。');
            }
            // CREATE TABLE LIKE does not copy triggers or foreign keys. Refuse
            // these customized schemas rather than silently losing constraints.
            $triggers = $wpdb->get_results($wpdb->prepare('SHOW TRIGGERS WHERE `Table` = %s', $live));
            if ($wpdb->last_error !== '' || $triggers) {
                throw new \RuntimeException('插件表包含触发器或无法检查，不能安全升级。');
            }
            $foreignKeys = $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL AND (TABLE_NAME = %s OR REFERENCED_TABLE_NAME = %s)', $live, $live
            ));
            if ($wpdb->last_error !== '' || (int) $foreignKeys > 0) {
                throw new \RuntimeException('插件表包含外键或无法检查，不能安全升级。');
            }
            $row = ['live' => $live, 'stage' => 'aica_s_' . $suffix . '_' . $key, 'backup' => 'aica_b_' . $suffix . '_' . $key];
            if (Guard::tableExists($row['stage']) || Guard::tableExists($row['backup'])) {
                throw new \RuntimeException('升级临时表名冲突。');
            }
            $journal['tables'][$key] = $row;
        }
        Guard::journal($journal);
        foreach ($journal['tables'] as $row) {
            Guard::sql("CREATE TABLE `{$row['stage']}` LIKE `{$row['live']}`");
            Guard::sql("INSERT INTO `{$row['stage']}` SELECT * FROM `{$row['live']}`");
            $status = $wpdb->get_results($wpdb->prepare('SHOW TABLE STATUS WHERE Name = %s', $row['live']), ARRAY_A);
            if ($wpdb->last_error !== '') {
                throw new \RuntimeException('无法检查原表的自增序号。');
            }
            $next = (int) ($status[0]['Auto_increment'] ?? 0);
            if ($next > 0) {
                Guard::sql("ALTER TABLE `{$row['stage']}` AUTO_INCREMENT = " . $next);
            }
        }
        foreach ($documents as $document) {
            foreach ($document['steps'] as $step) {
                self::apply($journal['tables'][$step['table']]['stage'], $step);
            }
        }
    }

    private static function apply(string $table, array $step): void
    {
        global $wpdb;
        self::validateStep($step);
        $action = $step['action'];
        if ($action === 'update_rows') {
            if ($wpdb->update($table, $step['values'], $step['where']) === false) {
                throw new \RuntimeException('数据库数据迁移失败：' . $wpdb->last_error);
            }
            return;
        }
        $sql = 'ALTER TABLE `' . $table . '` ';
        if (in_array($action, ['add_column', 'modify_column', 'drop_column'], true)) {
            $verbs = ['add_column' => 'ADD', 'modify_column' => 'MODIFY', 'drop_column' => 'DROP'];
            $sql .= $verbs[$action] . ' COLUMN ' . self::identifier($step['column']);
            if ($action !== 'drop_column') {
                $sql .= ' ' . $step['definition'];
            }
        } elseif ($action === 'drop_index') {
            $sql .= 'DROP INDEX ' . self::identifier($step['name']);
        } else {
            $columns = array_map([__CLASS__, 'identifier'], $step['columns']);
            $sql .= 'ADD ' . (!empty($step['unique']) ? 'UNIQUE ' : '') . 'INDEX '
                . self::identifier($step['name']) . ' (' . implode(', ', $columns) . ')';
        }
        Guard::sql($sql);
    }

    public static function swap(array $journal): void
    {
        $renames = [];
        foreach ($journal['tables'] as $row) {
            $renames[] = "`{$row['live']}` TO `{$row['backup']}`";
            $renames[] = "`{$row['stage']}` TO `{$row['live']}`";
        }
        if ($renames) {
            Guard::sql('RENAME TABLE ' . implode(', ', $renames));
        }
    }
}
