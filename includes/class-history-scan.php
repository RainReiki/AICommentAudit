<?php

namespace AICommentAudit;

defined('ABSPATH') || exit;

/**
 * Batch scan for existing comments.
 */
final class HistoryScan
{
    public const SCOPES = ['approved', 'waiting', 'spam', 'all'];

    /**
     * @param string $scope
     * @param bool $withAi
     * @param int $after
     * @param int|null $through
     * @param array $settings
     * @return array
     */
    public static function batch(string $scope, bool $withAi, int $after, ?int $through, array $settings): array
    {
        global $wpdb;

        $statuses = self::statuses($scope);
        $where = "c.comment_type = 'comment' AND c.comment_approved IN (" . implode(',', array_fill(0, count($statuses), '%s')) . ')';
        $args = $statuses;

        if ($through === null) {
            $sql = "SELECT MAX(c.comment_ID) FROM {$wpdb->comments} c WHERE {$where}";
            $through = (int) $wpdb->get_var($wpdb->prepare($sql, $args));
        }

        $limit = $withAi ? 1 : 40;
        $sql = "SELECT c.*, p.post_title
            FROM {$wpdb->comments} c
            LEFT JOIN {$wpdb->posts} p ON p.ID = c.comment_post_ID
            WHERE {$where} AND c.comment_ID > %d AND c.comment_ID <= %d
            ORDER BY c.comment_ID ASC
            LIMIT %d";
        $queryArgs = array_merge($args, [$after, $through, $limit + 1]);
        $rows = $wpdb->get_results($wpdb->prepare($sql, $queryArgs), ARRAY_A);
        $rows = is_array($rows) ? $rows : [];

        $pauseReason = null;
        $results = [];
        foreach (array_slice($rows, 0, $limit) as $row) {
            $commentId = (int) $row['comment_ID'];
            $postId = (int) $row['comment_post_ID'];
            $article = self::article($postId, $withAi);
            $comment = [
                'comment_id' => $commentId,
                'post_id' => $postId,
                'author' => (string) $row['comment_author'],
                'mail' => (string) $row['comment_author_email'],
                'url' => (string) $row['comment_author_url'],
                'ip' => (string) $row['comment_author_IP'],
                'text' => (string) $row['comment_content'],
                'parent' => (int) $row['comment_parent'],
                'user_id' => (int) $row['user_id'],
            ];

            $result = Engine::preview($comment, $article, $settings, $withAi);

            if (!empty($result['ai']['error'])) {
                $pauseReason = $result['ai']['diagnostic'] ?? $result['reason'];
                break;
            }

            $after = $commentId;
            $results[] = [
                'id' => $commentId,
                'postId' => $postId,
                'post' => (string) ($row['post_title'] ?? ''),
                'author' => (string) $row['comment_author'],
                'excerpt' => self::limit(wp_strip_all_tags((string) $row['comment_content']), 0, 180),
                'status' => self::statusName((string) $row['comment_approved']),
                'suggestion' => $result['decision'],
                'reason' => $result['reason'],
                'aiReason' => self::limit((string) ($result['ai']['reason'] ?? ''), 0, 160),
                'identitySummary' => isset($result['ai']['identity'])
                    ? Logger::identitySummary($result['ai']['identity'])
                    : '',
                'identityEvidence' => $result['ai']['identity']['evidence'] ?? [],
                'conversationSummary' => isset($result['ai']['conversation'])
                    ? Logger::conversationSummary($result['ai']['conversation'])
                    : '',
            ];
        }

        return [
            'through' => $through,
            'after' => $after,
            'done' => $pauseReason === null && count($rows) <= $limit,
            'pauseReason' => $pauseReason,
            'results' => $results,
        ];
    }

    /**
     * @param string $scope
     * @return string[]
     */
    private static function statuses(string $scope): array
    {
        return match ($scope) {
            'approved' => ['1'],
            'waiting' => ['0'],
            'spam' => ['spam'],
            default => ['1', '0', 'spam'],
        };
    }

    /**
     * @param int $postId
     * @param bool $withAi
     * @return array
     */
    private static function article(int $postId, bool $withAi): array
    {
        $post = get_post($postId);
        if (!$post) {
            return ['title' => '', 'content' => '', 'excerpt' => '', 'category' => '', 'tags' => []];
        }

        $content = wp_strip_all_tags((string) $post->post_content);
        $article = [
            'title' => (string) $post->post_title,
            'content' => $content,
            'excerpt' => self::limit(wp_strip_all_tags((string) $post->post_excerpt), 0, 200),
            'category' => '',
            'tags' => [],
        ];

        if ($withAi) {
            $categories = get_the_category($postId);
            if ($categories) {
                $article['category'] = implode('、', array_map(static fn ($term) => $term->name, $categories));
            }
            $tags = get_the_tags($postId);
            if ($tags) {
                $article['tags'] = array_map(static fn ($term) => $term->name, $tags);
            }
        }

        return $article;
    }

    /**
     * @param string $status
     * @return string
     */
    private static function statusName(string $status): string
    {
        return match ($status) {
            '1' => 'approved',
            '0' => 'waiting',
            'spam' => 'spam',
            'trash' => 'trash',
            default => $status,
        };
    }

    /**
     * @param string $text
     * @param int $offset
     * @param int $length
     * @return string
     */
    private static function limit(string $text, int $offset, int $length): string
    {
        return function_exists('mb_substr')
            ? mb_substr($text, $offset, $length, 'UTF-8')
            : substr($text, $offset, $length);
    }
}
