<?php

namespace AICommentAudit;

defined('ABSPATH') || exit;

/**
 * Admin pages and form handlers.
 */
final class Admin
{
    public static function menu(): void
    {
        add_menu_page(
            __('评论审核', 'ai-comment-audit'),
            __('评论审核', 'ai-comment-audit'),
            'moderate_comments',
            'aicommentaudit',
            [__CLASS__, 'overview'],
            'dashicons-shield-alt',
            26
        );

        add_submenu_page('aicommentaudit', __('概览', 'ai-comment-audit'), __('概览', 'ai-comment-audit'), 'moderate_comments', 'aicommentaudit', [__CLASS__, 'overview']);
        add_submenu_page('aicommentaudit', __('审核日志', 'ai-comment-audit'), __('审核日志', 'ai-comment-audit'), 'moderate_comments', 'aicommentaudit-logs', [__CLASS__, 'logs']);
        add_submenu_page('aicommentaudit', __('信誉管理', 'ai-comment-audit'), __('信誉管理', 'ai-comment-audit'), 'moderate_comments', 'aicommentaudit-reputation', [__CLASS__, 'reputation']);
        add_submenu_page('aicommentaudit', __('历史扫描', 'ai-comment-audit'), __('历史扫描', 'ai-comment-audit'), 'moderate_comments', 'aicommentaudit-history', [__CLASS__, 'history']);
        add_submenu_page('aicommentaudit', __('设置', 'ai-comment-audit'), __('设置', 'ai-comment-audit'), 'manage_options', 'aicommentaudit-settings', [__CLASS__, 'settings']);
    }

    /**
     * @param string $hook
     * @return void
     */
    public static function assets($hook): void
    {
        if (strpos((string) $hook, 'aicommentaudit') === false) {
            return;
        }

        wp_enqueue_style('aicommentaudit-admin', AICOMMENTAUDIT_URL . 'assets/admin.css', [], AICOMMENTAUDIT_VERSION);
        wp_enqueue_script('aicommentaudit-admin', AICOMMENTAUDIT_URL . 'assets/admin.js', [], AICOMMENTAUDIT_VERSION, true);
        wp_localize_script('aicommentaudit-admin', 'AICommentAudit', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('aicommentaudit_ajax'),
            'strings' => [
                'testing' => __('测试中...', 'ai-comment-audit'),
                'testOk' => __('连接正常', 'ai-comment-audit'),
                'testFail' => __('连接失败，请检查设置', 'ai-comment-audit'),
                'fetching' => __('获取中…', 'ai-comment-audit'),
                'modelsFound' => __('找到 %d 个模型，请选择', 'ai-comment-audit'),
                'noModels' => __('没有找到模型', 'ai-comment-audit'),
                'scanning' => __('扫描中...', 'ai-comment-audit'),
                'scanDone' => __('扫描完成', 'ai-comment-audit'),
                'scanPaused' => __('已暂停', 'ai-comment-audit'),
                'selectFirst' => __('请先勾选评论', 'ai-comment-audit'),
                'processing' => __('处理中...', 'ai-comment-audit'),
                'done' => __('完成', 'ai-comment-audit'),
                'error' => __('操作失败，请重试', 'ai-comment-audit'),
                'deleteConfirm' => __('确定删除选中的评论？此操作无法撤销。', 'ai-comment-audit'),
            ],
        ]);
    }

    public static function overview(): void
    {
        self::requireCap('moderate_comments');
        $stats = Repository::stats();
        $settings = Settings::get();
        $recent = Repository::recentLogs(10);
        ?>
        <div class="wrap aicommentaudit-wrap">
            <h1><?php esc_html_e('评论审核', 'ai-comment-audit'); ?></h1>
            <p class="aicommentaudit-muted"><?php esc_html_e('本地规则先拦截，AI 结合文章、回复上下文和评论者资料给出最终结果。', 'ai-comment-audit'); ?></p>

            <div class="aicommentaudit-cards">
                <div class="aicommentaudit-card aicommentaudit-stat"><span><?php esc_html_e('今日通过', 'ai-comment-audit'); ?></span><strong><?php echo (int) $stats['approved']; ?></strong></div>
                <div class="aicommentaudit-card aicommentaudit-stat"><span><?php esc_html_e('今日待审', 'ai-comment-audit'); ?></span><strong><?php echo (int) $stats['waiting']; ?></strong></div>
                <div class="aicommentaudit-card aicommentaudit-stat"><span><?php esc_html_e('今日拒绝', 'ai-comment-audit'); ?></span><strong><?php echo (int) $stats['rejected']; ?></strong></div>
                <div class="aicommentaudit-card aicommentaudit-stat"><span><?php esc_html_e('今日合计 / 累计', 'ai-comment-audit'); ?></span><strong><?php echo (int) $stats['today_total']; ?> / <?php echo (int) $stats['total']; ?></strong></div>
            </div>

            <div class="aicommentaudit-grid">
                <div class="aicommentaudit-card">
                    <h2><?php esc_html_e('运行状态', 'ai-comment-audit'); ?></h2>
                    <table class="aicommentaudit-table">
                        <tbody>
                        <tr><th><?php esc_html_e('插件版本', 'ai-comment-audit'); ?></th><td><?php echo esc_html(AICOMMENTAUDIT_VERSION); ?></td></tr>
                        <tr><th><?php esc_html_e('模型', 'ai-comment-audit'); ?></th><td><?php echo esc_html($settings['model']); ?></td></tr>
                        <tr><th><?php esc_html_e('服务地址', 'ai-comment-audit'); ?></th><td><?php echo esc_html($settings['baseUrl']); ?></td></tr>
                        <tr><th><?php esc_html_e('API Key', 'ai-comment-audit'); ?></th><td><?php echo $settings['apiKey'] !== '' ? esc_html__('已配置', 'ai-comment-audit') : esc_html__('未配置', 'ai-comment-audit'); ?></td></tr>
                        <tr><th><?php esc_html_e('SSL / 调试', 'ai-comment-audit'); ?></th><td><?php echo !empty($settings['sslVerify']) ? esc_html__('SSL 验证开启', 'ai-comment-audit') : esc_html__('SSL 验证关闭', 'ai-comment-audit'); ?> / <?php echo !empty($settings['debug']) ? esc_html__('调试开启', 'ai-comment-audit') : esc_html__('调试关闭', 'ai-comment-audit'); ?></td></tr>
                        </tbody>
                    </table>
                    <p>
                        <button type="button" class="button" id="aicommentaudit-test"><?php esc_html_e('测试连接', 'ai-comment-audit'); ?></button>
                        <span id="aicommentaudit-test-result" class="aicommentaudit-muted" role="status"><?php esc_html_e('尚未测试', 'ai-comment-audit'); ?></span>
                    </p>
                    <p><a class="button button-secondary" href="<?php echo esc_url(admin_url('admin.php?page=aicommentaudit-settings')); ?>"><?php esc_html_e('打开设置', 'ai-comment-audit'); ?></a></p>
                </div>

                <div class="aicommentaudit-card">
                    <h2><?php esc_html_e('最近决策', 'ai-comment-audit'); ?></h2>
                    <table class="aicommentaudit-table aicommentaudit-compact">
                        <thead><tr><th><?php esc_html_e('时间', 'ai-comment-audit'); ?></th><th><?php esc_html_e('评论者', 'ai-comment-audit'); ?></th><th><?php esc_html_e('状态', 'ai-comment-audit'); ?></th><th><?php esc_html_e('原因', 'ai-comment-audit'); ?></th></tr></thead>
                        <tbody>
                        <?php if (!$recent): ?>
                            <tr><td colspan="4" class="aicommentaudit-muted"><?php esc_html_e('暂无记录', 'ai-comment-audit'); ?></td></tr>
                        <?php else: ?>
                            <?php foreach ($recent as $row): ?>
                                <tr>
                                    <td><?php echo esc_html(wp_date('Y-m-d H:i', (int) $row['created'])); ?></td>
                                    <td><?php echo esc_html($row['author']); ?></td>
                                    <td><?php echo self::badge((string) $row['decision']); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                                    <td><?php echo esc_html(Logger::reasonLabel((string) $row['reason_code'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                    <p><a href="<?php echo esc_url(admin_url('admin.php?page=aicommentaudit-logs')); ?>"><?php esc_html_e('查看全部日志', 'ai-comment-audit'); ?></a></p>
                </div>
            </div>
        </div>
        <?php
    }

    public static function settings(): void
    {
        self::requireCap('manage_options');
        $s = Settings::get();
        $updated = isset($_GET['updated']);
        $cacheCleared = isset($_GET['cache_cleared']);
        $quota = max(0, (int) $s['tokenQuota']);
        $usage = Repository::getTokenUsage((string) $s['quotaPeriod']);
        $used = (int) $usage['tokens'];
        $remaining = $quota > 0 ? max(0, $quota - $used) : null;
        $quotaPercent = $quota > 0 ? min(100, (int) round($used / max(1, $quota) * 100)) : 0;
        $quotaReached = $quota > 0 && $used >= $quota;
        ?>
        <div class="wrap aicommentaudit-wrap">
            <div class="aicommentaudit-page-head">
                <div class="aicommentaudit-page-head-main">
                    <span class="aicommentaudit-eyebrow">AICommentAudit</span>
                    <h1><?php esc_html_e('评论审核设置', 'ai-comment-audit'); ?></h1>
                    <p class="aicommentaudit-subtitle"><?php esc_html_e('本地规则 + AI 审核。所有密钥和规则只保存在本机，保存后立即生效。', 'ai-comment-audit'); ?></p>
                </div>
                <div class="aicommentaudit-page-head-meta">
                    <span class="aicommentaudit-pill"><?php echo esc_html('v' . AICOMMENTAUDIT_VERSION); ?></span>
                    <a class="aicommentaudit-pill is-link" href="https://github.com/RainReiki/AICommentAudit" target="_blank" rel="noopener noreferrer"><?php esc_html_e('查看更新', 'ai-comment-audit'); ?> ↗</a>
                    <span class="aicommentaudit-pill <?php echo $s['apiKey'] !== '' ? 'is-ok' : 'is-warn'; ?>">
                        <?php echo $s['apiKey'] !== '' ? esc_html__('AI 已配置', 'ai-comment-audit') : esc_html__('AI 未配置', 'ai-comment-audit'); ?>
                    </span>
                    <span class="aicommentaudit-pill is-neutral"><?php echo esc_html('WordPress ' . get_bloginfo('version')); ?></span>
                </div>
            </div>
            <?php if ($updated): ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('设置已保存。', 'ai-comment-audit'); ?></p></div><?php endif; ?>
            <?php if ($cacheCleared): ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('AI 缓存已清空。', 'ai-comment-audit'); ?></p></div><?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="aicommentaudit-form aicommentaudit-settings-form">
                <input type="hidden" name="action" value="aicommentaudit_save_settings">
                <?php wp_nonce_field('aicommentaudit_save_settings'); ?>

                <nav class="nav-tab-wrapper aicommentaudit-tabs" role="tablist" aria-label="<?php esc_attr_e('设置分组', 'ai-comment-audit'); ?>">
                    <button type="button" class="nav-tab nav-tab-active" id="aicommentaudit-tab-connection" data-tab="connection" role="tab" aria-selected="true" aria-controls="aicommentaudit-panel-connection"><?php esc_html_e('连接', 'ai-comment-audit'); ?></button>
                    <button type="button" class="nav-tab" id="aicommentaudit-tab-quota" data-tab="quota" role="tab" aria-selected="false" aria-controls="aicommentaudit-panel-quota"><?php esc_html_e('额度', 'ai-comment-audit'); ?></button>
                    <button type="button" class="nav-tab" id="aicommentaudit-tab-words" data-tab="words" role="tab" aria-selected="false" aria-controls="aicommentaudit-panel-words"><?php esc_html_e('词表', 'ai-comment-audit'); ?></button>
                    <button type="button" class="nav-tab" id="aicommentaudit-tab-blacklist" data-tab="blacklist" role="tab" aria-selected="false" aria-controls="aicommentaudit-panel-blacklist"><?php esc_html_e('黑名单', 'ai-comment-audit'); ?></button>
                    <button type="button" class="nav-tab" id="aicommentaudit-tab-precheck" data-tab="precheck" role="tab" aria-selected="false" aria-controls="aicommentaudit-panel-precheck"><?php esc_html_e('本地预检', 'ai-comment-audit'); ?></button>
                    <button type="button" class="nav-tab" id="aicommentaudit-tab-policy" data-tab="policy" role="tab" aria-selected="false" aria-controls="aicommentaudit-panel-policy"><?php esc_html_e('审核策略', 'ai-comment-audit'); ?></button>
                    <button type="button" class="nav-tab" id="aicommentaudit-tab-advanced" data-tab="advanced" role="tab" aria-selected="false" aria-controls="aicommentaudit-panel-advanced"><?php esc_html_e('高级', 'ai-comment-audit'); ?></button>
                </nav>

                <section class="aicommentaudit-panel" data-panel="connection" id="aicommentaudit-panel-connection" role="tabpanel" aria-labelledby="aicommentaudit-tab-connection">
                    <h3><?php esc_html_e('AI 服务连接', 'ai-comment-audit'); ?></h3>
                    <p class="aicommentaudit-section-desc"><?php esc_html_e('配置服务地址、API Key 和模型。支持 DeepSeek 官方和 OpenAI 兼容中转站。', 'ai-comment-audit'); ?></p>
                    <table class="form-table">
                        <tr><th><label for="aicommentaudit-base-url"><?php esc_html_e('服务地址', 'ai-comment-audit'); ?></label></th>
                            <td><input class="regular-text" id="aicommentaudit-base-url" name="baseUrl" value="<?php echo esc_attr($s['baseUrl']); ?>">
                            <p class="description"><?php esc_html_e('默认 https://api.deepseek.com。可填根地址、.../v1 或完整 .../v1/chat/completions。', 'ai-comment-audit'); ?></p></td></tr>
                        <tr><th><label for="aicommentaudit-api-key"><?php esc_html_e('API Key', 'ai-comment-audit'); ?></label></th>
                            <td><input class="regular-text" type="password" id="aicommentaudit-api-key" name="api_key" value="" autocomplete="new-password" placeholder="<?php echo $s['apiKey'] !== '' ? esc_attr__('已配置，留空保持不变', 'ai-comment-audit') : esc_attr__('尚未配置', 'ai-comment-audit'); ?>">
                            <label class="aicommentaudit-inline"><input type="checkbox" name="api_key_clear" value="1"> <?php esc_html_e('清除已保存的 Key', 'ai-comment-audit'); ?></label></td></tr>
                        <tr><th><label for="aicommentaudit-model"><?php esc_html_e('模型', 'ai-comment-audit'); ?></label></th>
                            <td><input class="regular-text" id="aicommentaudit-model" name="model" value="<?php echo esc_attr($s['model']); ?>">
                            <button type="button" class="button" id="aicommentaudit-models"><?php esc_html_e('获取模型列表', 'ai-comment-audit'); ?></button>
                            <select id="aicommentaudit-model-select" class="aicommentaudit-model-select" style="display:none;"></select>
                            <span id="aicommentaudit-models-status" class="aicommentaudit-muted" role="status"><?php esc_html_e('保存设置后可获取列表', 'ai-comment-audit'); ?></span>
                            <p class="description"><?php esc_html_e('不同服务商模型名不同：官方常用 deepseek-flash，中转站请选它返回的 ID。', 'ai-comment-audit'); ?></p></td></tr>
                        <tr><th><?php esc_html_e('思考模式', 'ai-comment-audit'); ?></th>
                            <td><label><input type="radio" name="disableThinking" value="0" <?php checked(empty($s['disableThinking'])); ?>> <?php esc_html_e('保持默认', 'ai-comment-audit'); ?></label>
                            <label class="aicommentaudit-inline"><input type="radio" name="disableThinking" value="1" <?php checked(!empty($s['disableThinking'])); ?>> <?php esc_html_e('关闭思考', 'ai-comment-audit'); ?></label>
                            <p class="description"><?php esc_html_e('中转站较慢时可关闭思考；若提示不支持 thinking 参数，请改回默认。', 'ai-comment-audit'); ?></p></td></tr>
                        <tr><th><label for="aicommentaudit-timeout"><?php esc_html_e('请求超时（秒）', 'ai-comment-audit'); ?></label></th>
                            <td><input type="number" min="3" id="aicommentaudit-timeout" name="timeout" value="<?php echo (int) $s['timeout']; ?>"></td></tr>
                        <tr><th><label for="aicommentaudit-max-tokens"><?php esc_html_e('最大 token 数', 'ai-comment-audit'); ?></label></th>
                            <td><input type="number" min="300" id="aicommentaudit-max-tokens" name="maxTokens" value="<?php echo (int) $s['maxTokens']; ?>">
                            <p class="description"><?php esc_html_e('默认 1200；中转站较慢时可尝试 800。', 'ai-comment-audit'); ?></p></td></tr>
                        <tr><th><?php esc_html_e('验证 SSL 证书', 'ai-comment-audit'); ?></th>
                            <td><label><input type="radio" name="sslVerify" value="1" <?php checked(!empty($s['sslVerify'])); ?>> <?php esc_html_e('开启', 'ai-comment-audit'); ?></label>
                            <label class="aicommentaudit-inline"><input type="radio" name="sslVerify" value="0" <?php checked(empty($s['sslVerify'])); ?>> <?php esc_html_e('关闭', 'ai-comment-audit'); ?></label></td></tr>
                        <tr><th><?php esc_html_e('调试日志', 'ai-comment-audit'); ?></th>
                            <td><label><input type="radio" name="debug" value="1" <?php checked(!empty($s['debug'])); ?>> <?php esc_html_e('开启', 'ai-comment-audit'); ?></label>
                            <label class="aicommentaudit-inline"><input type="radio" name="debug" value="0" <?php checked(empty($s['debug'])); ?>> <?php esc_html_e('关闭', 'ai-comment-audit'); ?></label></td></tr>
                    </table>
                </section>

                <section class="aicommentaudit-panel" data-panel="quota" id="aicommentaudit-panel-quota" role="tabpanel" aria-labelledby="aicommentaudit-tab-quota" hidden>
                    <h3><?php esc_html_e('API 额度', 'ai-comment-audit'); ?></h3>
                    <p class="aicommentaudit-section-desc"><?php esc_html_e('限制插件调用 AI 时最多消耗多少令牌。达到额度后，新评论会转为待审，不再请求接口。', 'ai-comment-audit'); ?></p>
                    <div class="aicommentaudit-quota-help">
                        <strong><?php esc_html_e('怎么填？', 'ai-comment-audit'); ?></strong>
                        <p><?php esc_html_e('一次审核约消耗 3,000–6,000 tokens。建议额度 = 每月评论数 × 5,000 × 1.2，并额外留 20% 缓冲。', 'ai-comment-audit'); ?></p>
                        <p><?php esc_html_e('例：300 条/月 ≈ 1,800,000；1,000 条/月 ≈ 6,000,000。测试可先填 100,000；填 0 表示不限制。', 'ai-comment-audit'); ?></p>
                    </div>
                    <table class="form-table">
                        <tr><th><label for="aicommentaudit-token-quota"><?php esc_html_e('令牌额度', 'ai-comment-audit'); ?></label></th>
                            <td>
                                <div class="aicommentaudit-quota-fields">
                                    <input type="number" min="0" step="1000" id="aicommentaudit-token-quota" name="tokenQuota" value="<?php echo (int) $s['tokenQuota']; ?>" placeholder="0">
                                    <select name="quotaPeriod">
                                        <option value="daily" <?php selected($s['quotaPeriod'], 'daily'); ?>><?php esc_html_e('每日重置', 'ai-comment-audit'); ?></option>
                                        <option value="monthly" <?php selected($s['quotaPeriod'], 'monthly'); ?>><?php esc_html_e('每月重置', 'ai-comment-audit'); ?></option>
                                        <option value="total" <?php selected($s['quotaPeriod'], 'total'); ?>><?php esc_html_e('累计（不自动重置）', 'ai-comment-audit'); ?></option>
                                    </select>
                                </div>
                                <p class="description"><?php esc_html_e('填 0 表示不限制。按服务商返回的 usage.total_tokens 统计，包括评论审核、历史扫描和测试连接。', 'ai-comment-audit'); ?></p>
                            </td></tr>
                        <tr><th><?php esc_html_e('当前周期用量', 'ai-comment-audit'); ?></th>
                            <td>
                                <div class="aicommentaudit-quota-summary <?php echo $quotaReached ? 'is-reached' : ''; ?>">
                                    <strong><?php echo esc_html(number_format_i18n($used)); ?> tokens</strong>
                                    <?php if ($quota > 0): ?>
                                        <span>/ <?php echo esc_html(number_format_i18n($quota)); ?>（<?php echo (int) $quotaPercent; ?>%）· <?php esc_html_e('剩余', 'ai-comment-audit'); ?> <?php echo esc_html(number_format_i18n($remaining)); ?></span>
                                    <?php else: ?>
                                        <span><?php esc_html_e('未设置上限', 'ai-comment-audit'); ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($quota > 0): ?>
                                    <div class="aicommentaudit-quota-meter <?php echo $quotaReached ? 'is-reached' : ''; ?>">
                                        <span style="width:<?php echo (int) $quotaPercent; ?>%"></span>
                                    </div>
                                <?php endif; ?>
                                <p class="description">
                                    <?php if ($quotaReached): ?>
                                        <?php esc_html_e('额度已用完，新评论会转待审。可以调高额度或重置已用额度。', 'ai-comment-audit'); ?>
                                    <?php else: ?>
                                        <?php esc_html_e('额度用完后不会继续调用 AI，避免产生额外费用。', 'ai-comment-audit'); ?>
                                    <?php endif; ?>
                                </p>
                                <label class="aicommentaudit-inline"><input type="checkbox" name="reset_token_usage" value="1"> <?php esc_html_e('保存时重置已用额度', 'ai-comment-audit'); ?></label>
                            </td></tr>
                    </table>
                </section>

                <section class="aicommentaudit-panel" data-panel="words" id="aicommentaudit-panel-words" role="tabpanel" aria-labelledby="aicommentaudit-tab-words" hidden>
                    <h3><?php esc_html_e('词表与匹配范围', 'ai-comment-audit'); ?></h3>
                    <p class="aicommentaudit-section-desc"><?php esc_html_e('明确违禁词直接拒绝；疑似敏感词只作为 AI 的参考信号。', 'ai-comment-audit'); ?></p>
                    <table class="form-table">
                        <tr><th><label for="aicommentaudit-block-words"><?php esc_html_e('明确违禁词', 'ai-comment-audit'); ?></label></th>
                            <td><textarea class="large-text" rows="6" id="aicommentaudit-block-words" name="blockWords"><?php echo esc_textarea($s['blockWords']); ?></textarea><p class="description"><?php esc_html_e('每行一个词，命中直接拒绝。', 'ai-comment-audit'); ?></p></td></tr>
                        <tr><th><label for="aicommentaudit-suspect-words"><?php esc_html_e('疑似敏感词', 'ai-comment-audit'); ?></label></th>
                            <td><textarea class="large-text" rows="6" id="aicommentaudit-suspect-words" name="suspectWords"><?php echo esc_textarea($s['suspectWords']); ?></textarea><p class="description"><?php esc_html_e('每行一个词，只作为 AI 的参考信号。', 'ai-comment-audit'); ?></p></td></tr>
                        <tr><th><?php esc_html_e('检查哪些字段', 'ai-comment-audit'); ?></th>
                            <td><?php
                            $scopes = ['text' => __('评论内容', 'ai-comment-audit'), 'author' => __('昵称', 'ai-comment-audit'), 'email' => __('邮箱', 'ai-comment-audit'), 'url' => __('网址', 'ai-comment-audit')];
                            foreach ($scopes as $key => $label) {
                                printf('<label class="aicommentaudit-inline"><input type="checkbox" name="wordScopes[]" value="%s" %s> %s</label>', esc_attr($key), checked(in_array($key, (array) $s['wordScopes'], true), true, false), esc_html($label));
                            }
                            ?></td></tr>
                    </table>
                </section>

                <section class="aicommentaudit-panel" data-panel="blacklist" id="aicommentaudit-panel-blacklist" role="tabpanel" aria-labelledby="aicommentaudit-tab-blacklist" hidden>
                    <h3><?php esc_html_e('黑名单', 'ai-comment-audit'); ?></h3>
                    <p class="aicommentaudit-section-desc"><?php esc_html_e('命中任意名单的评论会被直接拒绝，不消耗 AI 调用。', 'ai-comment-audit'); ?></p>
                    <table class="form-table">
                        <tr><th><label for="aicommentaudit-ip-blacklist"><?php esc_html_e('IP 黑名单', 'ai-comment-audit'); ?></label></th>
                            <td><textarea class="large-text" rows="5" id="aicommentaudit-ip-blacklist" name="ipBlacklist"><?php echo esc_textarea($s['ipBlacklist']); ?></textarea><p class="description"><?php esc_html_e('每行一个 IP 或 CIDR，例如 192.0.2.0/24。', 'ai-comment-audit'); ?></p></td></tr>
                        <tr><th><label for="aicommentaudit-email-blacklist"><?php esc_html_e('邮箱黑名单', 'ai-comment-audit'); ?></label></th>
                            <td><textarea class="large-text" rows="5" id="aicommentaudit-email-blacklist" name="emailBlacklist"><?php echo esc_textarea($s['emailBlacklist']); ?></textarea><p class="description"><?php esc_html_e('每行一个邮箱或域名，例如 *@example.com。', 'ai-comment-audit'); ?></p></td></tr>
                        <tr><th><label for="aicommentaudit-url-blacklist"><?php esc_html_e('网址黑名单', 'ai-comment-audit'); ?></label></th>
                            <td><textarea class="large-text" rows="5" id="aicommentaudit-url-blacklist" name="urlBlacklist"><?php echo esc_textarea($s['urlBlacklist']); ?></textarea><p class="description"><?php esc_html_e('每行一个域名、通配子域名或 URL 前缀。', 'ai-comment-audit'); ?></p></td></tr>
                    </table>
                </section>

                <section class="aicommentaudit-panel" data-panel="precheck" id="aicommentaudit-panel-precheck" role="tabpanel" aria-labelledby="aicommentaudit-tab-precheck" hidden>
                    <h3><?php esc_html_e('非中文评论规则', 'ai-comment-audit'); ?></h3>
                    <p class="aicommentaudit-section-desc"><?php esc_html_e('仅检查评论正文：不含任何汉字，且英文字母占比达到阈值时触发。含中文或未达到阈值的评论继续原审核流程。', 'ai-comment-audit'); ?></p>
                    <table class="form-table">
                        <tr><th><?php esc_html_e('启用非中文评论规则', 'ai-comment-audit'); ?></th>
                            <td><fieldset class="aicommentaudit-language-options" aria-label="<?php esc_attr_e('启用非中文评论规则', 'ai-comment-audit'); ?>">
                                <label><input type="radio" name="englishFilterEnabled" value="1" <?php checked(!empty($s['englishFilterEnabled'])); ?>> <?php esc_html_e('开启', 'ai-comment-audit'); ?></label>
                                <label><input type="radio" name="englishFilterEnabled" value="0" <?php checked(empty($s['englishFilterEnabled'])); ?>> <?php esc_html_e('关闭', 'ai-comment-audit'); ?></label>
                            </fieldset><p class="description"><?php esc_html_e('默认关闭；开启后保存生效。', 'ai-comment-audit'); ?></p></td></tr>
                        <tr><th><label for="aicommentaudit-english-ratio"><?php esc_html_e('英文占比阈值', 'ai-comment-audit'); ?></label></th>
                            <td><div class="aicommentaudit-ratio-input"><input type="number" min="1" max="100" step="1" id="aicommentaudit-english-ratio" name="englishRatioThreshold" value="<?php echo (int) $s['englishRatioThreshold']; ?>" aria-describedby="aicommentaudit-english-ratio-help"><span aria-hidden="true">%</span></div>
                            <p class="description" id="aicommentaudit-english-ratio-help"><?php esc_html_e('范围 1–100%，默认 80%。英文含 A–Z / a–z 及全角形式；以去除 HTML 标签、空白和不可见格式字符后的正文字符数为分母，数字、标点、表情计入总数。例如 abcd1 的英文占比为 80%，达到 80% 即触发。', 'ai-comment-audit'); ?></p></td></tr>
                        <tr><th><?php esc_html_e('达到阈值时', 'ai-comment-audit'); ?></th>
                            <td><fieldset class="aicommentaudit-language-options" aria-label="<?php esc_attr_e('达到阈值时', 'ai-comment-audit'); ?>">
                                <label><input type="radio" name="englishFilterAction" value="reject" <?php checked($s['englishFilterAction'], 'reject'); ?>> <?php esc_html_e('直接拒绝', 'ai-comment-audit'); ?></label>
                                <label><input type="radio" name="englishFilterAction" value="ai" <?php checked($s['englishFilterAction'], 'ai'); ?>> <?php esc_html_e('继续 AI 审核', 'ai-comment-audit'); ?></label>
                            </fieldset><p class="description"><?php esc_html_e('直接拒绝：不调用 AI，按原插件行为存为垃圾评论并记录原因。继续 AI 审核：仍执行后续本地检查、AI 和信誉判断；AI 异常或额度不足时转待审。历史扫描若选择「仅本地规则」，需要 AI 的命中项显示为待审。', 'ai-comment-audit'); ?></p></td></tr>
                    </table>
                    <h3><?php esc_html_e('频率与链接', 'ai-comment-audit'); ?></h3>
                    <p class="aicommentaudit-section-desc"><?php esc_html_e('在提交给 AI 之前检查同一 IP 的发评频率和评论中的链接数。', 'ai-comment-audit'); ?></p>
                    <table class="form-table">
                        <tr><th><label for="aicommentaudit-ip-limit"><?php esc_html_e('同一 IP 评论数上限', 'ai-comment-audit'); ?></label></th>
                            <td><input type="number" min="0" id="aicommentaudit-ip-limit" name="ipRateLimit" value="<?php echo (int) $s['ipRateLimit']; ?>"><p class="description"><?php esc_html_e('填 0 可关闭频率限制。', 'ai-comment-audit'); ?></p></td></tr>
                        <tr><th><label for="aicommentaudit-ip-window"><?php esc_html_e('统计窗口（秒）', 'ai-comment-audit'); ?></label></th>
                            <td><input type="number" min="60" id="aicommentaudit-ip-window" name="ipRateWindow" value="<?php echo (int) $s['ipRateWindow']; ?>"></td></tr>
                        <tr><th><label for="aicommentaudit-link-limit"><?php esc_html_e('链接数量上限', 'ai-comment-audit'); ?></label></th>
                            <td><input type="number" min="0" id="aicommentaudit-link-limit" name="linkLimit" value="<?php echo (int) $s['linkLimit']; ?>"><p class="description"><?php esc_html_e('超过后标记为疑似，交给 AI 判断。', 'ai-comment-audit'); ?></p></td></tr>
                    </table>
                </section>

                <section class="aicommentaudit-panel" data-panel="policy" id="aicommentaudit-panel-policy" role="tabpanel" aria-labelledby="aicommentaudit-tab-policy" hidden>
                    <h3><?php esc_html_e('审核策略', 'ai-comment-audit'); ?></h3>
                    <p class="aicommentaudit-section-desc"><?php esc_html_e('身份检查和 AI 拒绝阈值。AI 调用失败时始终转待审。', 'ai-comment-audit'); ?></p>
                    <table class="form-table">
                        <tr><th><?php esc_html_e('自动检查身份信息', 'ai-comment-audit'); ?></th>
                            <td><label><input type="radio" name="identityProtection" value="1" <?php checked(!empty($s['identityProtection'])); ?>> <?php esc_html_e('开启', 'ai-comment-audit'); ?></label>
                            <label class="aicommentaudit-inline"><input type="radio" name="identityProtection" value="0" <?php checked(empty($s['identityProtection'])); ?>> <?php esc_html_e('关闭', 'ai-comment-audit'); ?></label>
                            <p class="description"><?php esc_html_e('AI 根据昵称、邮箱、网址和站点历史给出 0–10 分；3–5 待审，6 分以上拒绝。', 'ai-comment-audit'); ?></p></td></tr>
                        <tr><th><?php esc_html_e('拒绝置信度阈值', 'ai-comment-audit'); ?></th>
                            <td><label><?php esc_html_e('新用户', 'ai-comment-audit'); ?> <input type="number" step="0.01" min="0" max="1" name="thresholdNew" value="<?php echo esc_attr($s['thresholdNew']); ?>"></label>
                            <label class="aicommentaudit-inline"><?php esc_html_e('普通用户', 'ai-comment-audit'); ?> <input type="number" step="0.01" min="0" max="1" name="thresholdNormal" value="<?php echo esc_attr($s['thresholdNormal']); ?>"></label>
                            <label class="aicommentaudit-inline"><?php esc_html_e('老访客', 'ai-comment-audit'); ?> <input type="number" step="0.01" min="0" max="1" name="thresholdTrusted" value="<?php echo esc_attr($s['thresholdTrusted']); ?>"></label></td></tr>
                    </table>
                </section>

                <section class="aicommentaudit-panel" data-panel="advanced" id="aicommentaudit-panel-advanced" role="tabpanel" aria-labelledby="aicommentaudit-tab-advanced" hidden>
                    <h3><?php esc_html_e('高级设置', 'ai-comment-audit'); ?></h3>
                    <p class="aicommentaudit-section-desc"><?php esc_html_e('日志保留、AI 缓存、评论列表显示和卸载行为。', 'ai-comment-audit'); ?></p>
                    <table class="form-table">
                        <tr><th><label for="aicommentaudit-log-retention"><?php esc_html_e('日志保留天数', 'ai-comment-audit'); ?></label></th>
                            <td><input type="number" min="1" max="3650" id="aicommentaudit-log-retention" name="logRetention" value="<?php echo (int) $s['logRetention']; ?>"></td></tr>
                        <tr><th><label for="aicommentaudit-cache-ttl"><?php esc_html_e('AI 缓存有效期（天）', 'ai-comment-audit'); ?></label></th>
                            <td><input type="number" min="1" id="aicommentaudit-cache-ttl" name="cacheTtl" value="<?php echo (int) $s['cacheTtl']; ?>"></td></tr>
                        <tr><th><?php esc_html_e('原生评论列表显示审核状态', 'ai-comment-audit'); ?></th>
                            <td><label><input type="radio" name="commentColumn" value="1" <?php checked(!empty($s['commentColumn'])); ?>> <?php esc_html_e('开启', 'ai-comment-audit'); ?></label>
                            <label class="aicommentaudit-inline"><input type="radio" name="commentColumn" value="0" <?php checked(empty($s['commentColumn'])); ?>> <?php esc_html_e('关闭', 'ai-comment-audit'); ?></label></td></tr>
                        <tr><th><?php esc_html_e('卸载时删除数据', 'ai-comment-audit'); ?></th>
                            <td><label><input type="radio" name="deleteDataOnUninstall" value="1" <?php checked(!empty($s['deleteDataOnUninstall'])); ?>> <?php esc_html_e('删除', 'ai-comment-audit'); ?></label>
                            <label class="aicommentaudit-inline"><input type="radio" name="deleteDataOnUninstall" value="0" <?php checked(empty($s['deleteDataOnUninstall'])); ?>> <?php esc_html_e('保留', 'ai-comment-audit'); ?></label></td></tr>
                    </table>
                </section>

                <div class="aicommentaudit-savebar">
                    <div class="aicommentaudit-savebar-info">
                        <strong><?php esc_html_e('保存更改', 'ai-comment-audit'); ?></strong>
                        <span id="aicommentaudit-save-state" class="aicommentaudit-save-state"><?php esc_html_e('所有更改已保存', 'ai-comment-audit'); ?></span>
                    </div>
                    <p class="submit"><button type="submit" class="button button-primary aicommentaudit-save-button"><?php esc_html_e('保存设置', 'ai-comment-audit'); ?></button></p>
                </div>
            </form>

            <div class="aicommentaudit-utility-card">
                <div class="aicommentaudit-utility-copy">
                    <strong><?php esc_html_e('维护', 'ai-comment-audit'); ?></strong>
                    <p><?php esc_html_e('清空 AI 缓存。仅在修改提示词、模型或怀疑缓存异常时需要。', 'ai-comment-audit'); ?></p>
                </div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="aicommentaudit-clear-cache">
                    <input type="hidden" name="action" value="aicommentaudit_clear_cache">
                    <?php wp_nonce_field('aicommentaudit_clear_cache'); ?>
                    <button type="submit" class="button button-secondary"><?php esc_html_e('清空 AI 缓存', 'ai-comment-audit'); ?></button>
                </form>
            </div>
        </div>
        <?php
    }

    public static function logs(): void
    {
        self::requireCap('moderate_comments');

        $decision = sanitize_key((string) ($_GET['decision'] ?? ''));
        $keyword = sanitize_text_field((string) ($_GET['keyword'] ?? ''));
        $paged = max(1, (int) ($_GET['paged'] ?? 1));
        $perPage = 50;
        $filters = [
            'decision' => $decision,
            'keyword' => $keyword,
            'offset' => ($paged - 1) * $perPage,
        ];
        $rows = Repository::recentLogs($perPage, $filters);
        $total = Repository::countLogs($filters);
        $pages = max(1, (int) ceil($total / $perPage));
        ?>
        <div class="wrap aicommentaudit-wrap">
            <h1><?php esc_html_e('审核日志', 'ai-comment-audit'); ?></h1>

            <form method="get" class="aicommentaudit-filters">
                <input type="hidden" name="page" value="aicommentaudit-logs">
                <select name="decision">
                    <option value=""><?php esc_html_e('全部状态', 'ai-comment-audit'); ?></option>
                    <option value="approved" <?php selected($decision, 'approved'); ?>><?php esc_html_e('通过', 'ai-comment-audit'); ?></option>
                    <option value="waiting" <?php selected($decision, 'waiting'); ?>><?php esc_html_e('待审核', 'ai-comment-audit'); ?></option>
                    <option value="rejected" <?php selected($decision, 'rejected'); ?>><?php esc_html_e('拒绝', 'ai-comment-audit'); ?></option>
                </select>
                <input type="search" name="keyword" value="<?php echo esc_attr($keyword); ?>" placeholder="<?php esc_attr_e('作者 / 邮箱 / IP / AI 原因', 'ai-comment-audit'); ?>">
                <button class="button"><?php esc_html_e('筛选', 'ai-comment-audit'); ?></button>
            </form>

            <table class="widefat striped aicommentaudit-table">
                <thead><tr>
                    <th><?php esc_html_e('时间', 'ai-comment-audit'); ?></th>
                    <th><?php esc_html_e('评论者', 'ai-comment-audit'); ?></th>
                    <th><?php esc_html_e('内容', 'ai-comment-audit'); ?></th>
                    <th><?php esc_html_e('状态', 'ai-comment-audit'); ?></th>
                    <th><?php esc_html_e('原因', 'ai-comment-audit'); ?></th>
                    <th><?php esc_html_e('AI', 'ai-comment-audit'); ?></th>
                    <th><?php esc_html_e('耗时 / tokens', 'ai-comment-audit'); ?></th>
                    <th><?php esc_html_e('操作', 'ai-comment-audit'); ?></th>
                </tr></thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="8" class="aicommentaudit-muted"><?php esc_html_e('暂无记录', 'ai-comment-audit'); ?></td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row):
                        $log = Logger::expandLog($row);
                        $detail = wp_json_encode([
                            'decision' => (string) $log['decision'],
                            'decision_label' => Logger::decisionLabel((string) $log['decision']),
                            'reason' => Logger::reasonLabel((string) $log['reason_code']),
                            'author' => (string) $log['author'],
                            'email' => (string) $log['email'],
                            'ip' => (string) $log['ip'],
                            'content' => (string) $log['content'],
                            'trust' => Logger::trustLabel((string) $log['trust_level']),
                            'ai_decision' => (string) $log['ai_decision'],
                            'ai_category' => (string) $log['ai_category'],
                            'ai_confidence' => (float) $log['ai_confidence'],
                            'ai_severity' => (string) $log['ai_severity'],
                            'ai_reason' => (string) $log['ai_reason'],
                            'identity' => Logger::identitySummary($log['identity']),
                            'identity_evidence' => $log['identity']['evidence'] ?? [],
                            'conversation' => Logger::conversationSummary($log['conversation']),
                            'latency' => (int) $log['latency_ms'],
                            'tokens' => (int) $log['tokens'],
                            'comment_id' => (int) $log['comment_id'],
                            'post_id' => (int) $log['post_id'],
                            'manual' => (int) $log['manual'],
                            'created' => (int) $log['created'],
                        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
                        ?>
                        <tr>
                            <td><?php echo esc_html(wp_date('Y-m-d H:i', (int) $log['created'])); ?></td>
                            <td><?php echo esc_html($log['author']); ?></td>
                            <td><?php echo esc_html(wp_html_excerpt((string) $log['content'], 60, '…')); ?></td>
                            <td><?php echo self::badge((string) $log['decision']); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                            <td><?php echo esc_html(Logger::reasonLabel((string) $log['reason_code'])); ?></td>
                            <td><?php echo esc_html(trim($log['ai_decision'] . ' / ' . $log['ai_category'], ' /')); ?></td>
                            <td><?php echo (int) $log['latency_ms']; ?>ms / <?php echo (int) $log['tokens']; ?></td>
                            <td>
                                <button type="button" class="button button-small aicommentaudit-detail" data-log="<?php echo esc_attr($detail); ?>"><?php esc_html_e('详情', 'ai-comment-audit'); ?></button>
                                <?php if (!empty($log['comment_id'])): ?>
                                    <a class="button button-small" href="<?php echo esc_url(get_edit_comment_link((int) $log['comment_id'])); ?>"><?php esc_html_e('编辑', 'ai-comment-audit'); ?></a>
                                    <?php
                                    $base = admin_url('admin-post.php?action=aicommentaudit_mark_comment&comment_id=' . (int) $log['comment_id'] . '&status=');
                                    ?>
                                    <a href="<?php echo esc_url(wp_nonce_url($base . 'approve', 'aicommentaudit_mark')); ?>"><?php esc_html_e('通过', 'ai-comment-audit'); ?></a>
                                    <a href="<?php echo esc_url(wp_nonce_url($base . 'hold', 'aicommentaudit_mark')); ?>"><?php esc_html_e('待审', 'ai-comment-audit'); ?></a>
                                    <a href="<?php echo esc_url(wp_nonce_url($base . 'spam', 'aicommentaudit_mark')); ?>"><?php esc_html_e('垃圾', 'ai-comment-audit'); ?></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <?php if ($pages > 1): ?>
                <div class="tablenav"><div class="tablenav-pages"><?php
                echo paginate_links([
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'current' => $paged,
                    'total' => $pages,
                ]);
                ?></div></div>
            <?php endif; ?>

            <dialog id="aicommentaudit-detail" class="aicommentaudit-modal">
                <div class="aicommentaudit-modal-head"><h2><?php esc_html_e('审核详情', 'ai-comment-audit'); ?></h2><button type="button" class="aicommentaudit-modal-close" aria-label="<?php esc_attr_e('关闭', 'ai-comment-audit'); ?>">×</button></div>
                <div class="aicommentaudit-modal-body" id="aicommentaudit-detail-body"></div>
            </dialog>
        </div>
        <?php
    }

    public static function reputation(): void
    {
        self::requireCap('moderate_comments');

        $keyword = sanitize_text_field((string) ($_GET['keyword'] ?? ''));
        $rows = Repository::recentReputations($keyword, 200);
        ?>
        <div class="wrap aicommentaudit-wrap">
            <h1><?php esc_html_e('信誉管理', 'ai-comment-audit'); ?></h1>
            <form method="get" class="aicommentaudit-filters">
                <input type="hidden" name="page" value="aicommentaudit-reputation">
                <input type="search" name="keyword" value="<?php echo esc_attr($keyword); ?>" placeholder="<?php esc_attr_e('邮箱 / 昵称+IP', 'ai-comment-audit'); ?>">
                <button class="button"><?php esc_html_e('搜索', 'ai-comment-audit'); ?></button>
            </form>

            <table class="widefat striped aicommentaudit-table">
                <thead><tr>
                    <th><?php esc_html_e('身份', 'ai-comment-audit'); ?></th>
                    <th><?php esc_html_e('等级', 'ai-comment-audit'); ?></th>
                    <th><?php esc_html_e('通过', 'ai-comment-audit'); ?></th>
                    <th><?php esc_html_e('待审', 'ai-comment-audit'); ?></th>
                    <th><?php esc_html_e('垃圾', 'ai-comment-audit'); ?></th>
                    <th><?php esc_html_e('24h 中度辱骂', 'ai-comment-audit'); ?></th>
                    <th><?php esc_html_e('最近出现', 'ai-comment-audit'); ?></th>
                    <th><?php esc_html_e('操作', 'ai-comment-audit'); ?></th>
                </tr></thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="8" class="aicommentaudit-muted"><?php esc_html_e('暂无记录', 'ai-comment-audit'); ?></td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?php echo esc_html($row['identity_value']); ?><br><span class="aicommentaudit-muted"><?php echo esc_html($row['identity_type']); ?></span></td>
                            <td><?php echo esc_html(Logger::trustLabel((string) $row['computed_level'])); ?></td>
                            <td><?php echo (int) $row['approved_count']; ?></td>
                            <td><?php echo (int) $row['waiting_count']; ?></td>
                            <td><?php echo (int) $row['spam_count']; ?></td>
                            <td><?php echo (int) $row['moderate_abuse_24h']; ?></td>
                            <td><?php echo $row['last_seen'] ? esc_html(wp_date('Y-m-d H:i', (int) $row['last_seen'])) : '—'; ?></td>
                            <td>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <input type="hidden" name="action" value="aicommentaudit_set_trust">
                                    <input type="hidden" name="identity_hash" value="<?php echo esc_attr($row['identity_hash']); ?>">
                                    <?php wp_nonce_field('aicommentaudit_set_trust'); ?>
                                    <select name="level">
                                        <option value="whitelist" <?php selected($row['trust_level'], 'whitelist'); ?>><?php esc_html_e('白名单', 'ai-comment-audit'); ?></option>
                                        <option value="default" <?php selected($row['trust_level'] !== 'whitelist'); ?>><?php esc_html_e('按记录计算', 'ai-comment-audit'); ?></option>
                                    </select>
                                    <button class="button button-small"><?php esc_html_e('保存', 'ai-comment-audit'); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function history(): void
    {
        self::requireCap('moderate_comments');
        ?>
        <div class="wrap aicommentaudit-wrap">
            <h1><?php esc_html_e('历史扫描', 'ai-comment-audit'); ?></h1>
            <p class="aicommentaudit-muted"><?php esc_html_e('检查已经存在的评论。可以只用本地规则，也可以加上 AI 复核；结果仅在当前页面显示。', 'ai-comment-audit'); ?></p>

            <div class="aicommentaudit-card aicommentaudit-scan-controls">
                <label><?php esc_html_e('范围', 'ai-comment-audit'); ?>
                    <select id="aicommentaudit-scan-scope">
                        <option value="approved"><?php esc_html_e('已通过', 'ai-comment-audit'); ?></option>
                        <option value="waiting"><?php esc_html_e('待审核', 'ai-comment-audit'); ?></option>
                        <option value="spam"><?php esc_html_e('垃圾', 'ai-comment-audit'); ?></option>
                        <option value="all"><?php esc_html_e('全部', 'ai-comment-audit'); ?></option>
                    </select>
                </label>
                <label><?php esc_html_e('方式', 'ai-comment-audit'); ?>
                    <select id="aicommentaudit-scan-mode">
                        <option value="local"><?php esc_html_e('仅本地规则', 'ai-comment-audit'); ?></option>
                        <option value="ai"><?php esc_html_e('本地 + AI', 'ai-comment-audit'); ?></option>
                    </select>
                </label>
                <button type="button" class="button button-primary" id="aicommentaudit-scan-start"><?php esc_html_e('开始扫描', 'ai-comment-audit'); ?></button>
                <button type="button" class="button" id="aicommentaudit-scan-pause" disabled><?php esc_html_e('暂停', 'ai-comment-audit'); ?></button>
                <span id="aicommentaudit-scan-status" class="aicommentaudit-muted" role="status"><?php esc_html_e('尚未开始', 'ai-comment-audit'); ?></span>
            </div>

            <div class="aicommentaudit-card aicommentaudit-scan-actions">
                <select id="aicommentaudit-bulk-action">
                    <option value="approve"><?php esc_html_e('设为通过', 'ai-comment-audit'); ?></option>
                    <option value="hold"><?php esc_html_e('转为待审', 'ai-comment-audit'); ?></option>
                    <option value="spam"><?php esc_html_e('标记为垃圾', 'ai-comment-audit'); ?></option>
                    <option value="delete"><?php esc_html_e('直接删除', 'ai-comment-audit'); ?></option>
                </select>
                <button type="button" class="button" id="aicommentaudit-bulk-apply"><?php esc_html_e('处理选中项', 'ai-comment-audit'); ?></button>
                <span id="aicommentaudit-bulk-status" class="aicommentaudit-muted" role="status"></span>
            </div>

            <table class="widefat striped aicommentaudit-table" id="aicommentaudit-scan-table">
                <thead><tr>
                    <th><input type="checkbox" id="aicommentaudit-select-all"></th>
                    <th><?php esc_html_e('ID', 'ai-comment-audit'); ?></th>
                    <th><?php esc_html_e('文章', 'ai-comment-audit'); ?></th>
                    <th><?php esc_html_e('评论者', 'ai-comment-audit'); ?></th>
                    <th><?php esc_html_e('内容', 'ai-comment-audit'); ?></th>
                    <th><?php esc_html_e('原状态', 'ai-comment-audit'); ?></th>
                    <th><?php esc_html_e('建议', 'ai-comment-audit'); ?></th>
                    <th><?php esc_html_e('依据', 'ai-comment-audit'); ?></th>
                    <th><?php esc_html_e('操作', 'ai-comment-audit'); ?></th>
                </tr></thead>
                <tbody></tbody>
            </table>
        </div>
        <?php
    }

    public static function saveSettings(): void
    {
        self::requireCap('manage_options');
        check_admin_referer('aicommentaudit_save_settings');

        $current = Settings::get();
        $settings = $current;

        $settings['baseUrl'] = sanitize_text_field(wp_unslash((string) ($_POST['baseUrl'] ?? 'https://api.deepseek.com')));
        $settings['model'] = sanitize_text_field(wp_unslash((string) ($_POST['model'] ?? 'deepseek-flash')));
        $settings['timeout'] = max(3, (int) ($_POST['timeout'] ?? 8));
        $settings['maxTokens'] = max(300, (int) ($_POST['maxTokens'] ?? 1200));
        $settings['tokenQuota'] = max(0, min(1000000000000, (int) ($_POST['tokenQuota'] ?? 0)));
        $quotaPeriod = sanitize_key((string) ($_POST['quotaPeriod'] ?? 'monthly'));
        $settings['quotaPeriod'] = in_array($quotaPeriod, ['daily', 'monthly', 'total'], true) ? $quotaPeriod : 'monthly';

        if (!empty($_POST['api_key_clear'])) {
            $settings['apiKey'] = '';
        } elseif (isset($_POST['api_key'])) {
            $key = trim(wp_unslash((string) $_POST['api_key']));
            if ($key !== '' && strpos($key, '•') === false) {
                $settings['apiKey'] = sanitize_text_field($key);
            }
        }

        foreach (['disableThinking', 'sslVerify', 'debug', 'identityProtection', 'commentColumn', 'deleteDataOnUninstall'] as $flag) {
            $settings[$flag] = !empty($_POST[$flag]) ? 1 : 0;
        }

        foreach (['blockWords', 'suspectWords', 'ipBlacklist', 'emailBlacklist', 'urlBlacklist'] as $field) {
            $raw = sanitize_textarea_field(wp_unslash((string) ($_POST[$field] ?? '')));
            $lines = preg_split('/\r\n|\r|\n/', $raw);
            $lines = array_values(array_unique(array_filter(array_map('trim', $lines), static fn ($line) => $line !== '')));
            $settings[$field] = implode("\n", $lines);
        }

        $scopes = isset($_POST['wordScopes']) && is_array($_POST['wordScopes'])
            ? array_filter(wp_unslash($_POST['wordScopes']), 'is_string')
            : [];
        $scopes = array_map('sanitize_key', $scopes);
        $settings['wordScopes'] = array_values(array_intersect(['text', 'author', 'email', 'url'], $scopes));

        $settings['ipRateLimit'] = max(0, (int) ($_POST['ipRateLimit'] ?? 5));
        $settings['ipRateWindow'] = max(60, (int) ($_POST['ipRateWindow'] ?? 600));
        $settings['linkLimit'] = max(0, (int) ($_POST['linkLimit'] ?? 3));
        $settings['englishFilterEnabled'] = isset($_POST['englishFilterEnabled'])
            ? (($_POST['englishFilterEnabled'] === '1') ? 1 : 0)
            : (int) $current['englishFilterEnabled'];
        $englishRatio = $_POST['englishRatioThreshold'] ?? $current['englishRatioThreshold'];
        $settings['englishRatioThreshold'] = is_scalar($englishRatio) && is_numeric($englishRatio)
            ? max(1, min(100, (int) $englishRatio)) : 80;
        $englishAction = $_POST['englishFilterAction'] ?? $current['englishFilterAction'];
        $settings['englishFilterAction'] = in_array($englishAction, ['reject', 'ai'], true)
            ? $englishAction : 'reject';
        $settings['logRetention'] = max(1, min(3650, (int) ($_POST['logRetention'] ?? 90)));
        $settings['cacheTtl'] = max(1, (int) ($_POST['cacheTtl'] ?? 7));

        foreach (['thresholdNew' => 0.7, 'thresholdNormal' => 0.8, 'thresholdTrusted' => 0.9] as $field => $default) {
            $value = isset($_POST[$field]) ? (float) $_POST[$field] : $default;
            $settings[$field] = max(0, min(1, $value));
        }

        Settings::update($settings);
        if (!empty($_POST['reset_token_usage'])) {
            Repository::resetTokenUsage($settings['quotaPeriod']);
        }
        wp_safe_redirect(admin_url('admin.php?page=aicommentaudit-settings&updated=1'));
        exit;
    }

    public static function saveTrust(): void
    {
        self::requireCap('moderate_comments');
        check_admin_referer('aicommentaudit_set_trust');

        $hash = sanitize_text_field(wp_unslash((string) ($_POST['identity_hash'] ?? '')));
        $level = sanitize_key((string) ($_POST['level'] ?? 'default'));
        if ($hash !== '' && in_array($level, ['whitelist', 'default'], true)) {
            Repository::setTrust($hash, $level);
        }

        wp_safe_redirect(admin_url('admin.php?page=aicommentaudit-reputation'));
        exit;
    }

    public static function markComment(): void
    {
        self::requireCap('moderate_comments');
        check_admin_referer('aicommentaudit_mark');

        $commentId = (int) ($_GET['comment_id'] ?? 0);
        $status = sanitize_key((string) ($_GET['status'] ?? ''));
        $map = ['approve' => 'approve', 'hold' => 'hold', 'spam' => 'spam'];
        if ($commentId > 0 && isset($map[$status])) {
            wp_set_comment_status($commentId, $map[$status]);
        }

        wp_safe_redirect(admin_url('admin.php?page=aicommentaudit-logs'));
        exit;
    }

    public static function clearCache(): void
    {
        self::requireCap('manage_options');
        check_admin_referer('aicommentaudit_clear_cache');
        Repository::clearCache();
        wp_safe_redirect(admin_url('admin.php?page=aicommentaudit-settings&cache_cleared=1'));
        exit;
    }

    /**
     * @param string $cap
     * @return void
     */
    private static function requireCap(string $cap): void
    {
        if (!current_user_can($cap)) {
            wp_die(esc_html__('权限不足。', 'ai-comment-audit'));
        }
    }

    /**
     * @param string $decision
     * @return string
     */
    private static function badge(string $decision): string
    {
        $label = Logger::decisionLabel($decision);
        return '<span class="aicommentaudit-badge aicommentaudit-badge-' . esc_attr($decision) . '">' . esc_html($label) . '</span>';
    }
}
