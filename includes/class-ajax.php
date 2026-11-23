<?php

namespace AICommentAudit;

use AICommentAudit\AI\Client;

defined('ABSPATH') || exit;

/**
 * Admin AJAX endpoints.
 */
final class Ajax
{
    public static function boot(): void
    {
        add_action('wp_ajax_aicommentaudit_test_connection', [__CLASS__, 'testConnection']);
        add_action('wp_ajax_aicommentaudit_models', [__CLASS__, 'models']);
        add_action('wp_ajax_aicommentaudit_scan_history', [__CLASS__, 'scanHistory']);
        add_action('wp_ajax_aicommentaudit_moderate_history', [__CLASS__, 'moderateHistory']);
    }

    /**
     * @return void
     */
    private static function verify(): void
    {
        check_ajax_referer('aicommentaudit_ajax', 'nonce');
        if (!current_user_can('moderate_comments')) {
            wp_send_json(['success' => 0, 'message' => __('权限不足。', 'ai-comment-audit')]);
        }
    }

    /**
     * @return void
     */
    public static function testConnection(): void
    {
        self::verify();

        $comment = [
            'post_id' => 0,
            'author' => '连接测试',
            'mail' => '',
            'url' => '',
            'ip' => '127.0.0.1',
            'text' => '这是一条正常的测试评论，用来检查审核连接是否正常。' . time(),
            'parent' => 0,
            'comment_id' => 0,
        ];
        $article = [
            'title' => '连接测试',
            'content' => '这是一篇用于测试评论审核连接的示例文章。',
            'excerpt' => '这是一篇用于测试评论审核连接的示例文章。',
            'category' => '',
            'tags' => [],
        ];
        $features = [
            'trust_level' => 'normal',
            'approved_count' => 1,
            'spam_count' => 0,
            'last_spam_at' => 0,
            'link_count' => 0,
            'comment_length' => 30,
            'ip_recent_count' => 0,
            'suspect_word' => null,
            'flags' => [],
        ];

        try {
            $result = Client::audit($comment, $article, $features, Settings::get(), true);
            wp_send_json([
                'success' => empty($result['error']) ? 1 : 0,
                'result' => $result,
                'message' => !empty($result['error'])
                    ? ($result['diagnostic'] ?? Logger::reasonLabel($result['reason'] ?? 'ai_error'))
                    : '',
            ]);
        } catch (\Throwable $e) {
            wp_send_json([
                'success' => 0,
                'message' => __('连接测试失败，请检查 PHP 错误日志。', 'ai-comment-audit'),
            ]);
        }
    }

    /**
     * @return void
     */
    public static function models(): void
    {
        self::verify();
        try {
            wp_send_json(Client::listModels(Settings::get()));
        } catch (\Throwable $e) {
            wp_send_json(['success' => 0, 'message' => __('获取模型列表失败，请检查服务地址和密钥。', 'ai-comment-audit')]);
        }
    }

    /**
     * @return void
     */
    public static function scanHistory(): void
    {
        self::verify();

        $scope = sanitize_key((string) ($_POST['scope'] ?? 'approved'));
        $withAi = sanitize_key((string) ($_POST['mode'] ?? 'local')) === 'ai';
        $after = max(0, (int) ($_POST['after'] ?? 0));
        $through = isset($_POST['through']) && $_POST['through'] !== ''
            ? max(0, (int) $_POST['through'])
            : null;

        if (!in_array($scope, HistoryScan::SCOPES, true) || ($through !== null && $after > $through)) {
            wp_send_json(['success' => 0, 'message' => __('扫描范围无效，请重新开始。', 'ai-comment-audit')]);
        }

        $settings = Settings::get();
        if ($withAi && trim((string) $settings['apiKey']) === '') {
            wp_send_json(['success' => 0, 'message' => __('请先配置 API Key，或选择仅用本地规则。', 'ai-comment-audit')]);
        }

        try {
            $batch = HistoryScan::batch($scope, $withAi, $after, $through, $settings);
        } catch (\Throwable $e) {
            error_log('[AICommentAudit] History scan failed: ' . $e->getMessage());
            wp_send_json(['success' => 0, 'message' => __('扫描失败。可以重试当前批次。', 'ai-comment-audit')]);
        }

        wp_send_json(['success' => 1] + $batch);
    }

    /**
     * @return void
     */
    public static function moderateHistory(): void
    {
        self::verify();

        $raw = isset($_POST['changes']) ? wp_unslash((string) $_POST['changes']) : '';
        $changes = json_decode($raw, true);
        if (!is_array($changes) || !$changes || count($changes) > HistoryModeration::BATCH_SIZE) {
            wp_send_json(['success' => 0, 'message' => __('请选择有效的评论后重试。', 'ai-comment-audit')]);
        }

        try {
            $results = HistoryModeration::apply($changes);
        } catch (\Throwable $e) {
            wp_send_json(['success' => 0, 'message' => __('评论状态或操作无效，请重新扫描。', 'ai-comment-audit')]);
        }

        wp_send_json(['success' => 1, 'results' => $results]);
    }
}
