<?php

namespace AICommentAudit;

defined('ABSPATH') || exit;

/**
 * Audit log writing and labels.
 */
final class Logger
{
    /**
     * @param array $comment
     * @param array $result
     * @param int $commentId
     * @return void
     */
    public static function write(array $comment, array $result, $commentId = 0): void
    {
        global $wpdb;

        $ai = is_array($result['ai'] ?? null) ? $result['ai'] : [];
        $identity = is_array($ai['identity'] ?? null) ? $ai['identity'] : null;
        $conversation = is_array($ai['conversation'] ?? null) ? $ai['conversation'] : null;

        $wpdb->insert(Install::logsTable(), [
            'comment_id' => (int) $commentId,
            'post_id' => (int) ($comment['post_id'] ?? 0),
            'author' => (string) ($comment['author'] ?? ''),
            'email' => (string) ($comment['mail'] ?? ''),
            'ip' => (string) ($comment['ip'] ?? ''),
            'content' => (string) ($comment['text'] ?? ''),
            'decision' => (string) ($result['decision'] ?? 'waiting'),
            'reason_code' => (string) ($result['reason_code'] ?? ''),
            'trust_level' => (string) ($result['trust_level'] ?? 'new'),
            'ai_decision' => isset($ai['decision']) ? (string) $ai['decision'] : null,
            'ai_category' => isset($ai['category']) ? (string) $ai['category'] : null,
            'ai_severity' => isset($ai['severity']) ? (string) $ai['severity'] : null,
            'ai_confidence' => isset($ai['confidence']) ? (float) $ai['confidence'] : 0,
            'ai_reason' => isset($ai['reason']) ? (string) $ai['reason'] : (string) ($result['reason'] ?? ''),
            'identity_json' => $identity ? wp_json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null,
            'conversation_json' => $conversation ? wp_json_encode($conversation, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null,
            'on_topic' => !empty($ai['on_topic']) ? 1 : 0,
            'faithful' => !empty($ai['faithful']) ? 1 : 0,
            'threat_type' => isset($ai['threat_type']) ? (string) $ai['threat_type'] : null,
            'latency_ms' => isset($ai['latency_ms']) ? (int) $ai['latency_ms'] : 0,
            'tokens' => isset($ai['tokens']) ? (int) $ai['tokens'] : 0,
            'manual' => 0,
            'manual_by' => 0,
            'created' => time(),
        ]);
    }

    /**
     * @param array $row
     * @return array
     */
    public static function expandLog(array $row): array
    {
        $row['identity'] = null;
        $row['conversation'] = null;

        if (!empty($row['identity_json'])) {
            $identity = json_decode((string) $row['identity_json'], true);
            $row['identity'] = is_array($identity) ? $identity : null;
        }
        if (!empty($row['conversation_json'])) {
            $conversation = json_decode((string) $row['conversation_json'], true);
            $row['conversation'] = is_array($conversation) ? $conversation : null;
        }

        return $row;
    }

    /**
     * @param array|null $identity
     * @return string
     */
    public static function identitySummary(?array $identity): string
    {
        if ($identity === null) {
            return __('未记录', 'ai-comment-audit');
        }

        $risks = ['low' => __('低', 'ai-comment-audit'), 'medium' => __('中', 'ai-comment-audit'), 'high' => __('高', 'ai-comment-audit')];
        $categories = [
            'none' => __('未发现明确疑点', 'ai-comment-audit'),
            'name_confusion' => __('昵称混淆', 'ai-comment-audit'),
            'profile_conflict' => __('身份组合不一致', 'ai-comment-audit'),
            'possible_impersonation' => __('疑似冒用身份', 'ai-comment-audit'),
        ];

        $summary = ($risks[$identity['risk'] ?? ''] ?? __('未知', 'ai-comment-audit')) . ' · '
            . ($categories[$identity['category'] ?? ''] ?? __('需复核', 'ai-comment-audit'));

        if (isset($identity['score'])) {
            $summary .= sprintf(__('（%d 分）', 'ai-comment-audit'), (int) $identity['score']);
        }

        return $summary;
    }

    /**
     * @param array|null $context
     * @return string
     */
    public static function conversationSummary(?array $context): string
    {
        if ($context === null) {
            return __('旧记录未保存对话上下文', 'ai-comment-audit');
        }
        if (empty($context['is_reply'])) {
            return __('一级评论 · 参考文章', 'ai-comment-audit');
        }

        $count = count($context['ancestors'] ?? []);
        return sprintf(
            __('回复评论 · 父评论 #%1$d · 读取 %2$d 条上级对话', 'ai-comment-audit'),
            (int) ($context['parent_id'] ?? 0),
            $count
        ) . (!empty($context['incomplete']) ? __('（上下文不完整）', 'ai-comment-audit') : '');
    }

    /**
     * @param string $code
     * @return string
     */
    public static function reasonLabel($code): string
    {
        $map = [
            'local_ip_blacklist' => __('IP 黑名单', 'ai-comment-audit'),
            'local_email_blacklist' => __('邮箱黑名单', 'ai-comment-audit'),
            'local_url_blacklist' => __('网址黑名单', 'ai-comment-audit'),
            'local_block_word' => __('明确违禁词', 'ai-comment-audit'),
            'local_rate_limit' => __('同一 IP 评论过于频繁', 'ai-comment-audit'),
            'local_duplicate' => __('重复评论', 'ai-comment-audit'),
            'engine_error' => __('审核引擎异常', 'ai-comment-audit'),
            'ai_block_high' => __('AI 高置信拒绝', 'ai-comment-audit'),
            'ai_block_low_high_risk' => __('AI 低置信 + 高风险类别', 'ai-comment-audit'),
            'ai_block_low_low_risk' => __('AI 低置信 + 低风险类别', 'ai-comment-audit'),
            'ai_pass' => __('AI 通过', 'ai-comment-audit'),
            'ai_review' => __('AI 转待审', 'ai-comment-audit'),
            'ai_error' => __('AI 审核失败', 'ai-comment-audit'),
            'ai_missing_key' => __('未配置 API Key', 'ai-comment-audit'),
            'quota_exceeded' => __('API 令牌额度已用完', 'ai-comment-audit'),
            'ai_circuit_open' => __('AI 熔断中', 'ai-comment-audit'),
            'ai_unknown' => __('AI 结果无法判断', 'ai-comment-audit'),
            'abuse_mild' => __('轻度负面评论', 'ai-comment-audit'),
            'abuse_moderate_first' => __('中度辱骂第一次', 'ai-comment-audit'),
            'abuse_moderate_repeat' => __('中度辱骂 24 小时内第 3 次', 'ai-comment-audit'),
            'abuse_moderate_threat' => __('中度辱骂并含威胁', 'ai-comment-audit'),
            'abuse_severe' => __('严重辱骂', 'ai-comment-audit'),
            'threat_genuine' => __('真实威胁', 'ai-comment-audit'),
            'threat_ambiguous' => __('威胁语境不明确', 'ai-comment-audit'),
            'threat_joke' => __('威胁为玩笑', 'ai-comment-audit'),
            'hate_obvious' => __('明显仇恨言论', 'ai-comment-audit'),
            'hate_off_topic' => __('仇恨言论且与文章无关', 'ai-comment-audit'),
            'hate_suspect_on_topic' => __('疑似仇恨且与文章相关', 'ai-comment-audit'),
            'hate_suspect_off_topic' => __('疑似仇恨且与文章无关', 'ai-comment-audit'),
            'hate_low_on_topic' => __('低置信仇恨且与文章相关', 'ai-comment-audit'),
            'hate_low_off_topic' => __('低置信仇恨且与文章无关', 'ai-comment-audit'),
            'new_user_first' => __('新用户首条评论', 'ai-comment-audit'),
            'off_topic' => __('与文章无关', 'ai-comment-audit'),
            'history_suspect_word' => __('命中疑似敏感词', 'ai-comment-audit'),
            'history_link_limit' => __('链接数量超过上限', 'ai-comment-audit'),
            'history_local_pass' => __('未命中本地规则', 'ai-comment-audit'),
            'history_moderate_abuse' => __('疑似中度辱骂', 'ai-comment-audit'),
            'identity_review' => __('身份信息存在疑点，转人工审核', 'ai-comment-audit'),
            'identity_local_risk' => __('昵称、邮箱或网址存在风险组合，转人工审核', 'ai-comment-audit'),
            'identity_high_risk' => __('昵称、邮箱或网址风险很高，直接拒绝', 'ai-comment-audit'),
        ];

        return $map[$code] ?? (string) $code;
    }

    /**
     * @param string $decision
     * @return string
     */
    public static function decisionLabel($decision): string
    {
        return match ((string) $decision) {
            'approved' => __('通过', 'ai-comment-audit'),
            'waiting' => __('待审核', 'ai-comment-audit'),
            'rejected' => __('拒绝', 'ai-comment-audit'),
            'spam' => __('垃圾', 'ai-comment-audit'),
            'trash' => __('回收站', 'ai-comment-audit'),
            default => (string) $decision,
        };
    }

    /**
     * @param string $level
     * @return string
     */
    public static function trustLabel($level): string
    {
        return match ((string) $level) {
            'new' => __('新用户', 'ai-comment-audit'),
            'normal' => __('普通用户', 'ai-comment-audit'),
            'trusted' => __('老访客', 'ai-comment-audit'),
            'whitelist' => __('白名单', 'ai-comment-audit'),
            default => (string) $level,
        };
    }

    /**
     * @param int $commentId
     * @param string $decision
     * @param int $userId
     * @return void
     */
    public static function markManual($commentId, $decision, $userId): void
    {
        Repository::markManual($commentId, $decision, $userId);
    }
}
