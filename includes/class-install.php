<?php

namespace AICommentAudit;

defined('ABSPATH') || exit;

/**
 * Database schema and lifecycle.
 */
final class Install
{
    public const DB_VERSION = '1.1.0';

    /**
     * @return string
     */
    public static function logsTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'aicommentaudit_logs';
    }

    /**
     * @return string
     */
    public static function reputationTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'aicommentaudit_reputation';
    }

    /**
     * @return string
     */
    public static function cacheTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'aicommentaudit_ai_cache';
    }

    /**
     * @return string
     */
    public static function stateTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'aicommentaudit_state';
    }

    /**
     * @return void
     */
    public static function activate(): void
    {
        wp_clear_scheduled_hook('commentaudit_daily_cleanup');
        self::migrateLegacyTables();
        self::createTables();
        Settings::installDefaults();
        add_option('aicommentaudit_db_version', self::DB_VERSION, '', false);

        if (!wp_next_scheduled('aicommentaudit_daily_cleanup')) {
            wp_schedule_event(time() + 3600, 'daily', 'aicommentaudit_daily_cleanup');
        }
    }

    /**
     * @return void
     */
    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('aicommentaudit_daily_cleanup');
        wp_clear_scheduled_hook('commentaudit_daily_cleanup');
    }

    /**
     * @return void
     */
    public static function maybeUpgrade(): void
    {
        if (get_option('aicommentaudit_db_version') !== self::DB_VERSION) {
            self::migrateLegacyTables();
            self::createTables();
            update_option('aicommentaudit_db_version', self::DB_VERSION, false);
        }
    }

    /**
     * Rename tables from the pre-rename CommentAudit release when possible.
     *
     * @return void
     */
    private static function migrateLegacyTables(): void
    {
        global $wpdb;

        $map = [
            $wpdb->prefix . 'commentaudit_logs' => self::logsTable(),
            $wpdb->prefix . 'commentaudit_reputation' => self::reputationTable(),
            $wpdb->prefix . 'commentaudit_ai_cache' => self::cacheTable(),
            $wpdb->prefix . 'commentaudit_state' => self::stateTable(),
        ];

        foreach ($map as $old => $new) {
            if ($old === $new) {
                continue;
            }
            $oldExists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($old)));
            $newExists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($new)));
            if ($oldExists && !$newExists) {
                $wpdb->query("RENAME TABLE `{$old}` TO `{$new}`");
            }
        }
    }

    /**
     * @return void
     */
    public static function createTables(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $logs = self::logsTable();
        $reputation = self::reputationTable();
        $cache = self::cacheTable();
        $state = self::stateTable();

        $sql = [];

        $sql[] = "CREATE TABLE {$logs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            comment_id bigint(20) unsigned NOT NULL DEFAULT 0,
            post_id bigint(20) unsigned NOT NULL DEFAULT 0,
            author varchar(150) DEFAULT NULL,
            email varchar(150) DEFAULT NULL,
            ip varchar(100) DEFAULT NULL,
            content longtext,
            decision varchar(20) NOT NULL DEFAULT 'waiting',
            reason_code varchar(64) NOT NULL DEFAULT '',
            trust_level varchar(20) NOT NULL DEFAULT 'new',
            ai_decision varchar(20) DEFAULT NULL,
            ai_category varchar(32) DEFAULT NULL,
            ai_severity varchar(20) DEFAULT NULL,
            ai_confidence decimal(4,3) NOT NULL DEFAULT 0.000,
            ai_reason longtext,
            identity_json longtext,
            conversation_json longtext,
            on_topic tinyint(1) NOT NULL DEFAULT 0,
            faithful tinyint(1) NOT NULL DEFAULT 0,
            threat_type varchar(20) DEFAULT NULL,
            latency_ms int(10) unsigned NOT NULL DEFAULT 0,
            tokens int(10) unsigned NOT NULL DEFAULT 0,
            manual tinyint(1) NOT NULL DEFAULT 0,
            manual_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY comment_id (comment_id),
            KEY post_id (post_id),
            KEY decision_created (decision,created),
            KEY reason_code (reason_code),
            KEY trust_level (trust_level)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$reputation} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            identity_hash char(64) NOT NULL,
            identity_type varchar(20) NOT NULL DEFAULT 'email',
            identity_value varchar(255) DEFAULT NULL,
            trust_level varchar(20) NOT NULL DEFAULT 'new',
            approved_count int(10) unsigned NOT NULL DEFAULT 0,
            spam_count int(10) unsigned NOT NULL DEFAULT 0,
            waiting_count int(10) unsigned NOT NULL DEFAULT 0,
            first_seen bigint(20) unsigned NOT NULL DEFAULT 0,
            last_seen bigint(20) unsigned NOT NULL DEFAULT 0,
            last_spam_at bigint(20) unsigned NOT NULL DEFAULT 0,
            moderate_abuse_24h int(10) unsigned NOT NULL DEFAULT 0,
            last_moderate_abuse_at bigint(20) unsigned NOT NULL DEFAULT 0,
            updated bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY identity_hash (identity_hash),
            KEY trust_level (trust_level),
            KEY last_seen (last_seen)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$cache} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            cache_key char(64) NOT NULL,
            model varchar(64) DEFAULT NULL,
            prompt_version varchar(32) DEFAULT NULL,
            response longtext,
            created bigint(20) unsigned NOT NULL DEFAULT 0,
            expires bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY cache_key (cache_key),
            KEY expires (expires)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$state} (
            name varchar(64) NOT NULL,
            value longtext,
            updated bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (name)
        ) {$charset};";

        foreach ($sql as $statement) {
            dbDelta($statement);
        }
    }

    /**
     * Remove all data when the site owner explicitly enables it.
     *
     * @return void
     */
    public static function uninstall(): void
    {
        if (!defined('WP_UNINSTALL_PLUGIN')) {
            return;
        }

        $settings = get_option(AICOMMENTAUDIT_OPTION, []);
        if (!is_array($settings) || empty($settings['deleteDataOnUninstall'])) {
            return;
        }

        global $wpdb;
        $tables = [
            self::logsTable(),
            self::reputationTable(),
            self::cacheTable(),
            self::stateTable(),
            $wpdb->prefix . 'commentaudit_logs',
            $wpdb->prefix . 'commentaudit_reputation',
            $wpdb->prefix . 'commentaudit_ai_cache',
            $wpdb->prefix . 'commentaudit_state',
        ];
        foreach (array_unique($tables) as $table) {
            $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
        }

        delete_option(AICOMMENTAUDIT_OPTION);
        delete_option('aicommentaudit_db_version');
        delete_option('commentaudit_settings');
        delete_option('commentaudit_db_version');
        wp_clear_scheduled_hook('aicommentaudit_daily_cleanup');
        wp_clear_scheduled_hook('commentaudit_daily_cleanup');
    }
}
