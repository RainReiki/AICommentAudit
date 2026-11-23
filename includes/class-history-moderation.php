<?php

namespace AICommentAudit;

defined('ABSPATH') || exit;

/**
 * Apply moderation actions to scanned comments.
 */
final class HistoryModeration
{
    public const BATCH_SIZE = 50;

    /**
     * @param array $changes
     * @return array
     */
    public static function apply(array $changes): array
    {
        $results = [];

        foreach ($changes as $change) {
            $commentId = (int) ($change['id'] ?? 0);
            $action = (string) ($change['action'] ?? '');
            if ($commentId <= 0 || !in_array($action, ['approve', 'hold', 'spam', 'delete'], true)) {
                throw new \InvalidArgumentException('Invalid change');
            }

            $comment = get_comment($commentId);
            if (!$comment || $comment->comment_type !== 'comment') {
                throw new \InvalidArgumentException('Comment not found');
            }

            $success = false;
            if ($action === 'delete') {
                $success = (bool) wp_delete_comment($commentId, true);
            } else {
                $status = $action === 'approve' ? 'approve' : ($action === 'hold' ? 'hold' : 'spam');
                $success = (bool) wp_set_comment_status($commentId, $status);
            }

            $results[] = [
                'id' => $commentId,
                'action' => $action,
                'success' => $success,
            ];
        }

        return $results;
    }
}
