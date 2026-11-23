<?php

namespace AICommentAudit;

defined('ABSPATH') || exit;

/**
 * Plugin bootstrap.
 */
final class Plugin
{
    public static function boot(): void
    {
        Install::maybeUpgrade();
        CommentHooks::boot();
        Ajax::boot();

        add_action('admin_menu', [Admin::class, 'menu']);
        add_action('admin_enqueue_scripts', [Admin::class, 'assets']);
        add_action('admin_post_aicommentaudit_save_settings', [Admin::class, 'saveSettings']);
        add_action('admin_post_aicommentaudit_set_trust', [Admin::class, 'saveTrust']);
        add_action('admin_post_aicommentaudit_mark_comment', [Admin::class, 'markComment']);
        add_action('admin_post_aicommentaudit_clear_cache', [Admin::class, 'clearCache']);
        add_action('aicommentaudit_daily_cleanup', [__CLASS__, 'cleanup']);

        add_filter('plugin_action_links_' . plugin_basename(AICOMMENTAUDIT_FILE), static function (array $links): array {
            $links[] = '<a href="' . esc_url(admin_url('admin.php?page=aicommentaudit-settings')) . '">'
                . esc_html__('设置', 'ai-comment-audit') . '</a>';
            return $links;
        });
    }

    /**
     * Daily cleanup.
     *
     * @return void
     */
    public static function cleanup(): void
    {
        global $wpdb;

        try {
            $settings = Settings::get();
            Repository::pruneLogs((int) $settings['logRetention']);
            $table = Install::cacheTable();
            $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE expires < %d", time()));
        } catch (\Throwable $e) {
            error_log('[AICommentAudit] Cleanup failed: ' . $e->getMessage());
        }
    }
}
