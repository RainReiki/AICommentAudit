<?php

namespace AICommentAudit\Update;

defined('ABSPATH') || exit;

final class Admin
{
    public static function boot(): void
    {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_aicommentaudit_check_update', [__CLASS__, 'check']);
        add_action('admin_post_aicommentaudit_install_update', [__CLASS__, 'install']);
        add_action('aicommentaudit_check_updates', [__CLASS__, 'scheduledCheck']);
        add_action('admin_notices', [__CLASS__, 'notice']);
        if (!wp_next_scheduled('aicommentaudit_check_updates')) {
            wp_schedule_event(time() + 60, 'twicedaily', 'aicommentaudit_check_updates');
        }
    }

    public static function menu(): void
    {
        add_submenu_page('aicommentaudit', __('在线升级', 'ai-comment-audit'), __('在线升级', 'ai-comment-audit'),
            'update_plugins', 'aicommentaudit-update', [__CLASS__, 'page']);
    }

    public static function scheduledCheck(): void
    {
        try {
            Release::latest(true);
        } catch (\Throwable $e) {
            // Keep the last successful check. Never interrupt comment moderation.
            error_log('[AICommentAudit release check] ' . $e->getMessage());
        }
    }

    private static function permission(string $action): void
    {
        if (!current_user_can('update_plugins') || !current_user_can('manage_options') || !wp_is_file_mod_allowed('aicommentaudit_update')) {
            wp_die(esc_html__('当前账户或站点设置不允许更新插件。', 'ai-comment-audit'), '', ['response' => 403]);
        }
        check_admin_referer($action);
    }

    private static function redirect(): void
    {
        wp_safe_redirect(admin_url('admin.php?page=aicommentaudit-update'));
        exit;
    }

    public static function check(): void
    {
        self::permission('aicommentaudit_check_update');
        try {
            $release = Release::latest(true);
            Guard::report('checked', '已检查 GitHub Releases，最新正式版为 ' . $release['version'] . '。');
        } catch (\Throwable $e) {
            Guard::report('error', $e->getMessage());
        }
        self::redirect();
    }

    public static function install(): void
    {
        self::permission('aicommentaudit_install_update');
        try {
            Updater::install();
        } catch (\Throwable $e) {
            Guard::report('error', $e->getMessage());
        }
        self::redirect();
    }

    public static function notice(): void
    {
        $release = get_transient(Release::CACHE);
        if (!current_user_can('update_plugins') || !is_array($release)
            || !version_compare($release['version'], AICOMMENTAUDIT_VERSION, '>')) {
            return;
        }
        echo '<div class="notice notice-info"><p>';
        echo esc_html(sprintf(__('AICommentAudit %s 已发布。', 'ai-comment-audit'), $release['version'])) . ' ';
        echo '<a href="' . esc_url(admin_url('admin.php?page=aicommentaudit-update')) . '">' . esc_html__('查看并升级', 'ai-comment-audit') . '</a></p></div>';
    }

    public static function page(): void
    {
        if (!current_user_can('update_plugins') || !current_user_can('manage_options')) {
            wp_die(esc_html__('权限不足。', 'ai-comment-audit'), '', ['response' => 403]);
        }
        $release = get_transient(Release::CACHE);
        $report = get_option('aicommentaudit_update_result', []);
        $new = is_array($release) && version_compare($release['version'], AICOMMENTAUDIT_VERSION, '>');
        $allowed = wp_is_file_mod_allowed('aicommentaudit_update') && !is_multisite();
        ?>
        <div class="wrap aicommentaudit-wrap">
            <h1><?php esc_html_e('在线升级', 'ai-comment-audit'); ?></h1>
            <p class="aicommentaudit-muted"><?php esc_html_e('从 GitHub Releases 检查正式版本。只下载和替换发生变化的文件，失败时恢复文件及插件数据库。', 'ai-comment-audit'); ?></p>
            <?php if (!empty($report['message'])): ?>
                <div class="notice <?php echo ($report['status'] ?? '') === 'error' ? 'notice-error' : 'notice-info'; ?>"><p><?php echo esc_html($report['message']); ?></p></div>
            <?php endif; ?>
            <section class="aicommentaudit-card aicommentaudit-update-card">
                <dl class="aicommentaudit-update-versions">
                    <div><dt><?php esc_html_e('当前版本', 'ai-comment-audit'); ?></dt><dd><?php echo esc_html(AICOMMENTAUDIT_VERSION); ?></dd></div>
                    <div><dt><?php esc_html_e('最新正式版', 'ai-comment-audit'); ?></dt><dd><?php echo esc_html($release['version'] ?? '尚未检查'); ?></dd></div>
                    <div><dt><?php esc_html_e('上次检查', 'ai-comment-audit'); ?></dt><dd><?php echo !empty($release['checked']) ? esc_html(wp_date('Y-m-d H:i', $release['checked'])) : '—'; ?></dd></div>
                </dl>
                <p><?php esc_html_e('升级会短暂暂停本站请求，请选择访问较少的时间执行。已有本地修改的差异文件会阻止升级；未变文件与额外文件保持原样。', 'ai-comment-audit'); ?></p>
                <?php if (!$allowed): ?><p><?php esc_html_e('站点禁止文件修改或启用了多站点，当前不能执行事务升级。', 'ai-comment-audit'); ?></p><?php endif; ?>
                <?php if ($new && empty($release['manifest'])): ?><p><?php esc_html_e('该版本尚未提供差异升级清单，请使用 GitHub 正式安装包。', 'ai-comment-audit'); ?></p><?php endif; ?>
                <div class="aicommentaudit-update-actions">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="aicommentaudit_check_update">
                        <?php wp_nonce_field('aicommentaudit_check_update'); ?>
                        <button class="button" type="submit"><?php esc_html_e('检查更新', 'ai-comment-audit'); ?></button>
                    </form>
                    <?php if ($allowed && $new && !empty($release['manifest'])): ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="aicommentaudit_install_update">
                        <?php wp_nonce_field('aicommentaudit_install_update'); ?>
                        <button class="button button-primary" type="submit"><?php esc_html_e('立即升级', 'ai-comment-audit'); ?></button>
                    </form>
                    <?php endif; ?>
                    <a class="button" href="https://github.com/RainReiki/AICommentAudit/releases" target="_blank" rel="noopener noreferrer"><?php esc_html_e('查看发布记录', 'ai-comment-audit'); ?></a>
                </div>
            </section>
            <?php if (!empty($release['notes'])): ?>
            <section class="aicommentaudit-card aicommentaudit-update-card"><h2><?php esc_html_e('更新说明', 'ai-comment-audit'); ?></h2><div class="aicommentaudit-release-notes"><?php echo esc_html($release['notes']); ?></div></section>
            <?php endif; ?>
        </div>
        <?php
    }
}
