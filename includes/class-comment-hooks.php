<?php

namespace AICommentAudit;

defined('ABSPATH') || exit;

/**
 * Comment submission, status transitions and list column.
 */
final class CommentHooks
{
    /** @var array<string,array> */
    private static array $pending = [];

    public static function boot(): void
    {
        add_filter('pre_comment_approved', [__CLASS__, 'filterApproval'], 10, 2);
        add_action('comment_post', [__CLASS__, 'afterComment'], 10, 3);
        add_action('transition_comment_status', [__CLASS__, 'afterTransition'], 10, 3);
        add_action('deleted_comment', [__CLASS__, 'afterDelete'], 10, 2);

        if (!empty(Settings::get()['commentColumn'])) {
            add_filter('manage_edit-comments_columns', [__CLASS__, 'columns']);
            add_action('manage_comments_custom_column', [__CLASS__, 'column'], 10, 2);
        }
    }

    /**
     * @param int|string $approved
     * @param array $commentdata
     * @return int|string
     */
    public static function filterApproval($approved, array $commentdata)
    {
        if (($commentdata['comment_type'] ?? 'comment') !== 'comment') {
            return $approved;
        }

        $userId = get_current_user_id();
        if ($userId && user_can($userId, 'moderate_comments')) {
            return $approved;
        }

        if ($approved === 'spam' || $approved === 'trash') {
            return $approved;
        }

        $comment = self::commentFromData($commentdata, 0);
        $settings = Settings::get();
        $article = self::article((int) ($commentdata['comment_post_ID'] ?? 0));

        try {
            $result = Engine::decide($comment, $article, $settings);
        } catch (\Throwable $e) {
            error_log('[AICommentAudit] ' . $e->getMessage());
            $result = [
                'decision' => 'waiting',
                'reason_code' => 'engine_error',
                'reason' => '审核引擎异常',
                'trust_level' => 'new',
                'ai' => [],
                'flags' => ['engine_error'],
            ];
        }

        $key = self::key($commentdata);
        self::$pending[$key] = [
            'comment' => $comment,
            'result' => $result,
        ];

        if ($result['decision'] === 'approved') {
            return 1;
        }
        if ($result['decision'] === 'rejected') {
            return 'spam';
        }

        return 0;
    }

    /**
     * @param int $commentId
     * @param int|string $approved
     * @param array $commentdata
     * @return void
     */
    public static function afterComment($commentId, $approved, array $commentdata): void
    {
        $key = self::key($commentdata);
        if (!isset(self::$pending[$key])) {
            return;
        }

        $pending = self::$pending[$key];
        unset(self::$pending[$key]);

        $comment = $pending['comment'];
        $comment['comment_id'] = (int) $commentId;
        $comment['post_id'] = (int) ($commentdata['comment_post_ID'] ?? $comment['post_id']);
        $result = $pending['result'];

        $status = self::statusFromApproved($approved);
        try {
            Logger::write($comment, $result, (int) $commentId);
            Repository::recordOutcome($comment, $status, $result);
        } catch (\Throwable $e) {
            error_log('[AICommentAudit] Could not store audit record: ' . $e->getMessage());
        }
    }

    /**
     * @param string $newStatus
     * @param string $oldStatus
     * @param \WP_Comment $comment
     * @return void
     */
    public static function afterTransition($newStatus, $oldStatus, $comment): void
    {
        if (!$comment instanceof \WP_Comment) {
            return;
        }

        $old = self::statusFromWp((string) $oldStatus);
        $new = self::statusFromWp((string) $newStatus);
        $data = self::commentFromWp($comment);

        try {
            if ($newStatus === 'trash') {
                Repository::adjustForDelete(array_merge($data, ['status' => $old]));
                Logger::markManual($comment->comment_ID, 'trash', get_current_user_id());
                return;
            }

            if ($old === '' && $new === '') {
                return;
            }

            Repository::adjustForMark($data, $old, $new);
            Logger::markManual($comment->comment_ID, $new, get_current_user_id());
        } catch (\Throwable $e) {
            error_log('[AICommentAudit] Could not adjust reputation: ' . $e->getMessage());
        }
    }

    /**
     * @param int $commentId
     * @param \WP_Comment $comment
     * @return void
     */
    public static function afterDelete($commentId, $comment): void
    {
        if (!$comment instanceof \WP_Comment) {
            return;
        }

        $status = self::statusFromWp((string) $comment->comment_approved);
        if ($status === '') {
            return;
        }

        try {
            Repository::adjustForDelete(array_merge(self::commentFromWp($comment), ['status' => $status]));
        } catch (\Throwable $e) {
            error_log('[AICommentAudit] Could not roll back reputation: ' . $e->getMessage());
        }
    }

    /**
     * @param array $columns
     * @return array
     */
    public static function columns(array $columns): array
    {
        $new = [];
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ($key === 'author') {
                $new['aicommentaudit'] = __('审核', 'ai-comment-audit');
            }
        }
        if (!isset($new['aicommentaudit'])) {
            $new['aicommentaudit'] = __('审核', 'ai-comment-audit');
        }

        return $new;
    }

    /**
     * @param string $column
     * @param int $commentId
     * @return void
     */
    public static function column($column, $commentId): void
    {
        if ($column !== 'aicommentaudit') {
            return;
        }

        $log = Repository::logByCommentId((int) $commentId);
        if (!$log) {
            echo '<span class="aicommentaudit-muted">—</span>';
            return;
        }

        $decision = (string) $log['decision'];
        $label = Logger::decisionLabel($decision);
        $trust = Logger::trustLabel((string) $log['trust_level']);
        $latency = (int) $log['latency_ms'];

        printf(
            '<span class="aicommentaudit-badge aicommentaudit-badge-%s">%s</span><br><span class="aicommentaudit-muted">%s%s</span>',
            esc_attr($decision),
            esc_html($label),
            esc_html($trust),
            $latency > 0 ? esc_html(' · ' . $latency . 'ms') : ''
        );
    }

    /**
     * @param array $commentdata
     * @param int $commentId
     * @return array
     */
    private static function commentFromData(array $commentdata, int $commentId): array
    {
        return [
            'comment_id' => $commentId,
            'post_id' => (int) ($commentdata['comment_post_ID'] ?? 0),
            'author' => (string) ($commentdata['comment_author'] ?? ''),
            'mail' => (string) ($commentdata['comment_author_email'] ?? ''),
            'url' => (string) ($commentdata['comment_author_url'] ?? ''),
            'ip' => (string) ($commentdata['comment_author_IP'] ?? ''),
            'text' => (string) ($commentdata['comment_content'] ?? ''),
            'parent' => (int) ($commentdata['comment_parent'] ?? 0),
            'user_id' => (int) ($commentdata['user_id'] ?? 0),
        ];
    }

    /**
     * @param \WP_Comment $comment
     * @return array
     */
    private static function commentFromWp(\WP_Comment $comment): array
    {
        return [
            'comment_id' => (int) $comment->comment_ID,
            'post_id' => (int) $comment->comment_post_ID,
            'author' => (string) $comment->comment_author,
            'mail' => (string) $comment->comment_author_email,
            'url' => (string) $comment->comment_author_url,
            'ip' => (string) $comment->comment_author_IP,
            'text' => (string) $comment->comment_content,
            'parent' => (int) $comment->comment_parent,
            'user_id' => (int) $comment->user_id,
        ];
    }

    /**
     * @param int $postId
     * @return array
     */
    private static function article(int $postId): array
    {
        $post = get_post($postId);
        if (!$post) {
            return ['title' => '', 'content' => '', 'excerpt' => '', 'category' => '', 'tags' => []];
        }

        $content = wp_strip_all_tags((string) $post->post_content);
        $excerpt = (string) $post->post_excerpt;
        if ($excerpt === '') {
            $excerpt = wp_trim_words($content, 50, '…');
        }

        $category = '';
        $categories = get_the_category($postId);
        if ($categories) {
            $category = implode('、', array_map(static fn ($term) => $term->name, $categories));
        }
        $tags = get_the_tags($postId);
        $tagNames = $tags ? array_map(static fn ($term) => $term->name, $tags) : [];

        return [
            'title' => (string) $post->post_title,
            'content' => $content,
            'excerpt' => wp_strip_all_tags($excerpt),
            'category' => $category,
            'tags' => array_values($tagNames),
        ];
    }

    /**
     * @param array $commentdata
     * @return string
     */
    private static function key(array $commentdata): string
    {
        return md5(implode('|', [
            (int) ($commentdata['comment_post_ID'] ?? 0),
            (string) ($commentdata['comment_author'] ?? ''),
            (string) ($commentdata['comment_author_email'] ?? ''),
            (string) ($commentdata['comment_author_url'] ?? ''),
            (string) ($commentdata['comment_author_IP'] ?? ''),
            (string) ($commentdata['comment_content'] ?? ''),
            (int) ($commentdata['comment_parent'] ?? 0),
        ]));
    }

    /**
     * @param int|string $approved
     * @return string
     */
    private static function statusFromApproved($approved): string
    {
        if ($approved === 'spam' || $approved === 0 || $approved === '0') {
            return $approved === 'spam' ? 'spam' : 'waiting';
        }

        return 'approved';
    }

    /**
     * @param string $status
     * @return string
     */
    private static function statusFromWp(string $status): string
    {
        return match ($status) {
            'approved' => 'approved',
            'unapproved' => 'waiting',
            'spam' => 'spam',
            default => '',
        };
    }
}
