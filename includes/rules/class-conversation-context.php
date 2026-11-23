<?php

namespace AICommentAudit\Rules;

defined('ABSPATH') || exit;

/**
 * Reply context: only the direct ancestors of this reply.
 */
final class ConversationContext
{
    /**
     * @param array $comment
     * @return array
     */
    public static function build(array $comment): array
    {
        $parent = max(0, (int) ($comment['parent'] ?? 0));
        $context = [
            'is_reply' => $parent > 0,
            'parent_id' => $parent,
            'ancestors' => [],
            'incomplete' => false,
        ];
        if ($parent === 0) {
            return $context;
        }

        $postId = (int) ($comment['post_id'] ?? 0);
        $currentId = (int) ($comment['comment_id'] ?? 0);
        $seen = $currentId > 0 ? [$currentId => true] : [];

        while ($parent > 0 && count($context['ancestors']) < 6) {
            if (isset($seen[$parent])) {
                break;
            }
            $seen[$parent] = true;

            $row = get_comment($parent);
            if (!$row || (int) $row->comment_post_ID !== $postId || $row->comment_type !== 'comment') {
                break;
            }
            if ($currentId > 0 && (int) $row->comment_ID >= $currentId) {
                break;
            }

            $text = trim(wp_strip_all_tags((string) $row->comment_content));
            $limit = $context['ancestors'] ? 240 : 600;
            $context['ancestors'][] = [
                'id' => (int) $row->comment_ID,
                'author' => self::limit((string) $row->comment_author, 60),
                'text' => self::limit($text, $limit),
                'text_truncated' => self::length($text) > $limit,
            ];

            $parent = max(0, (int) $row->comment_parent);
        }

        $context['incomplete'] = $parent > 0;
        $context['ancestors'] = array_reverse($context['ancestors']);

        return $context;
    }

    /**
     * @param string $text
     * @param int $length
     * @return string
     */
    private static function limit(string $text, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($text, 0, $length, 'UTF-8') : substr($text, 0, $length);
    }

    /**
     * @param string $text
     * @return int
     */
    private static function length(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }
}
