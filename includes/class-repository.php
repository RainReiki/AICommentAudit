<?php

namespace AICommentAudit;

defined('ABSPATH') || exit;

/**
 * Data access for logs, reputation, cache and circuit state.
 */
final class Repository
{
    /**
     * @param array $comment
     * @return array{hash:string,type:string,value:string}
     */
    public static function identity(array $comment): array
    {
        $mail = strtolower(trim((string) ($comment['mail'] ?? '')));
        if ($mail !== '') {
            return [
                'hash' => hash('sha256', 'mail:' . $mail),
                'type' => 'email',
                'value' => $mail,
            ];
        }

        $author = strtolower(trim((string) ($comment['author'] ?? '')));
        $ip = trim((string) ($comment['ip'] ?? ''));
        $value = $author . '|' . $ip;

        return [
            'hash' => hash('sha256', 'author_ip:' . $value),
            'type' => 'author_ip',
            'value' => $value,
        ];
    }

    /**
     * @param array $comment
     * @return array
     */
    public static function reputation(array $comment): array
    {
        global $wpdb;

        $identity = self::identity($comment);
        $table = Install::reputationTable();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE identity_hash = %s LIMIT 1", $identity['hash']),
            ARRAY_A
        );

        if (!$row) {
            $row = [
                'identity_hash' => $identity['hash'],
                'identity_type' => $identity['type'],
                'identity_value' => $identity['value'],
                'trust_level' => 'new',
                'approved_count' => 0,
                'spam_count' => 0,
                'waiting_count' => 0,
                'first_seen' => 0,
                'last_seen' => 0,
                'last_spam_at' => 0,
                'moderate_abuse_24h' => 0,
                'last_moderate_abuse_at' => 0,
                'updated' => 0,
            ];
        }

        $row['computed_level'] = self::computeTrustLevel($row);
        $row['identity'] = $identity;

        return $row;
    }

    /**
     * @param array $reputation
     * @return string
     */
    public static function computeTrustLevel(array $reputation): string
    {
        if (($reputation['trust_level'] ?? '') === 'whitelist') {
            return 'whitelist';
        }

        $now = time();
        $lastSpam = (int) ($reputation['last_spam_at'] ?? 0);
        if ($lastSpam > 0 && $now - $lastSpam < 90 * 86400) {
            return 'new';
        }

        $approved = (int) ($reputation['approved_count'] ?? 0);
        if ($approved >= 3) {
            return 'trusted';
        }
        if ($approved >= 1) {
            return 'normal';
        }

        return 'new';
    }

    /**
     * @param array $comment
     * @return string
     */
    public static function trustLevel(array $comment): string
    {
        return self::reputation($comment)['computed_level'];
    }

    /**
     * @param array $comment
     * @return int
     */
    public static function moderateAbuseCount(array $comment): int
    {
        $row = self::reputation($comment);
        $last = (int) ($row['last_moderate_abuse_at'] ?? 0);
        if ($last <= 0 || time() - $last >= 86400) {
            return 0;
        }

        return (int) ($row['moderate_abuse_24h'] ?? 0);
    }

    /**
     * @param string $ip
     * @param int $window
     * @return int
     */
    public static function recentIpCount($ip, $window = 600): int
    {
        global $wpdb;

        $ip = trim((string) $ip);
        if ($ip === '') {
            return 0;
        }

        $cutoff = gmdate('Y-m-d H:i:s', time() - max(60, (int) $window));
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_author_IP = %s AND comment_date_gmt > %s",
            $ip,
            $cutoff
        ));
    }

    /**
     * @param int $postId
     * @param string $text
     * @param int $days
     * @param array $comment
     * @return int
     */
    public static function duplicateCount($postId, $text, $days = 7, array $comment = []): int
    {
        global $wpdb;

        $postId = (int) $postId;
        $text = trim((string) $text);
        if ($postId <= 0 || $text === '') {
            return 0;
        }

        $cutoff = gmdate('Y-m-d H:i:s', time() - max(1, (int) $days) * 86400);
        $sql = "SELECT COUNT(*) FROM {$wpdb->comments}
            WHERE comment_post_ID = %d
              AND comment_content = %s
              AND comment_date_gmt > %s
              AND comment_approved <> 'spam'";
        $args = [$postId, $text, $cutoff];

        $mail = trim((string) ($comment['mail'] ?? ''));
        $author = trim((string) ($comment['author'] ?? ''));
        $ip = trim((string) ($comment['ip'] ?? ''));
        if ($mail !== '') {
            $sql .= ' AND comment_author_email = %s';
            $args[] = $mail;
        } elseif ($author !== '' || $ip !== '') {
            $sql .= ' AND comment_author = %s AND comment_author_IP = %s';
            $args[] = $author;
            $args[] = $ip;
        }

        return (int) $wpdb->get_var($wpdb->prepare($sql, $args));
    }

    /**
     * @param array $comment
     * @param string $status approved|waiting|spam
     * @param array $result
     * @return void
     */
    public static function recordOutcome(array $comment, $status, array $result): void
    {
        global $wpdb;

        $table = Install::reputationTable();
        $identity = self::identity($comment);
        $now = time();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE identity_hash = %s LIMIT 1", $identity['hash']),
            ARRAY_A
        );

        $approved = (int) ($row['approved_count'] ?? 0);
        $spam = (int) ($row['spam_count'] ?? 0);
        $waiting = (int) ($row['waiting_count'] ?? 0);
        $lastSpam = (int) ($row['last_spam_at'] ?? 0);
        $abuseCount = (int) ($row['moderate_abuse_24h'] ?? 0);
        $lastAbuse = (int) ($row['last_moderate_abuse_at'] ?? 0);

        if ($status === 'approved') {
            $approved++;
        } elseif ($status === 'spam') {
            $spam++;
            $lastSpam = $now;
        } else {
            $waiting++;
        }

        if (($result['ai']['category'] ?? '') === 'abuse_moderate') {
            if ($lastAbuse <= 0 || $now - $lastAbuse >= 86400) {
                $abuseCount = 1;
            } else {
                $abuseCount++;
            }
            $lastAbuse = $now;
        }

        $currentTrust = (string) ($row['trust_level'] ?? 'new');
        $newTrust = $currentTrust === 'whitelist'
            ? 'whitelist'
            : self::computeTrustLevel([
                'trust_level' => $currentTrust,
                'approved_count' => $approved,
                'spam_count' => $spam,
                'last_spam_at' => $lastSpam,
            ]);

        $payload = [
            'trust_level' => $newTrust,
            'approved_count' => $approved,
            'spam_count' => $spam,
            'waiting_count' => $waiting,
            'last_seen' => $now,
            'last_spam_at' => $lastSpam,
            'moderate_abuse_24h' => $abuseCount,
            'last_moderate_abuse_at' => $lastAbuse,
            'updated' => $now,
        ];

        if ($row) {
            $wpdb->update($table, $payload, ['identity_hash' => $identity['hash']]);
        } else {
            $payload['identity_hash'] = $identity['hash'];
            $payload['identity_type'] = $identity['type'];
            $payload['identity_value'] = $identity['value'];
            $payload['first_seen'] = $now;
            $wpdb->insert($table, $payload);
        }
    }

    /**
     * @param array $comment
     * @param string $oldStatus
     * @param string $newStatus
     * @return void
     */
    public static function adjustForMark(array $comment, $oldStatus, $newStatus): void
    {
        if ($oldStatus === $newStatus) {
            return;
        }

        self::adjustCounts($comment, $oldStatus, $newStatus);
    }

    /**
     * @param array $comment
     * @return void
     */
    public static function adjustForDelete(array $comment): void
    {
        $status = (string) ($comment['status'] ?? '');
        if ($status === '') {
            return;
        }

        self::adjustCounts($comment, $status, '');
    }

    /**
     * @param array $comment
     * @param string $oldStatus
     * @param string $newStatus
     * @return void
     */
    private static function adjustCounts(array $comment, $oldStatus, $newStatus): void
    {
        global $wpdb;

        $table = Install::reputationTable();
        $identity = self::identity($comment);
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE identity_hash = %s LIMIT 1", $identity['hash']),
            ARRAY_A
        );
        if (!$row) {
            return;
        }

        $approved = (int) ($row['approved_count'] ?? 0);
        $spam = (int) ($row['spam_count'] ?? 0);
        $waiting = (int) ($row['waiting_count'] ?? 0);
        $lastSpam = (int) ($row['last_spam_at'] ?? 0);

        foreach ([$oldStatus, $newStatus] as $index => $status) {
            $delta = $index === 0 ? -1 : 1;
            if ($status === 'approved') {
                $approved = max(0, $approved + $delta);
            } elseif ($status === 'spam') {
                $spam = max(0, $spam + $delta);
                if ($delta > 0) {
                    $lastSpam = time();
                }
            } elseif ($status === 'waiting') {
                $waiting = max(0, $waiting + $delta);
            }
        }

        $currentTrust = (string) ($row['trust_level'] ?? 'new');
        $newTrust = $currentTrust === 'whitelist'
            ? 'whitelist'
            : self::computeTrustLevel([
                'trust_level' => $currentTrust,
                'approved_count' => $approved,
                'spam_count' => $spam,
                'last_spam_at' => $lastSpam,
            ]);

        $wpdb->update($table, [
            'trust_level' => $newTrust,
            'approved_count' => $approved,
            'spam_count' => $spam,
            'waiting_count' => $waiting,
            'last_spam_at' => $lastSpam,
            'updated' => time(),
        ], ['identity_hash' => $identity['hash']]);
    }

    /**
     * @param string $key
     * @return array|null
     */
    public static function getCache($key)
    {
        global $wpdb;

        $table = Install::cacheTable();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE cache_key = %s LIMIT 1", $key),
            ARRAY_A
        );
        if (!$row || (int) $row['expires'] <= time()) {
            return null;
        }

        $data = json_decode((string) $row['response'], true);
        return is_array($data) ? $data : null;
    }

    /**
     * @param string $key
     * @param string $model
     * @param string $promptVersion
     * @param array $result
     * @param int $ttlDays
     * @return void
     */
    public static function setCache($key, $model, $promptVersion, array $result, $ttlDays = 7): void
    {
        global $wpdb;

        $table = Install::cacheTable();
        $now = time();
        $response = wp_json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $payload = [
            'model' => $model,
            'prompt_version' => $promptVersion,
            'response' => $response,
            'created' => $now,
            'expires' => $now + max(1, (int) $ttlDays) * 86400,
        ];

        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE cache_key = %s LIMIT 1", $key));
        if ($exists) {
            $wpdb->update($table, $payload, ['cache_key' => $key]);
        } else {
            $payload['cache_key'] = $key;
            $wpdb->insert($table, $payload);
        }
    }

    /**
     * @return void
     */
    public static function clearCache(): void
    {
        global $wpdb;
        $table = Install::cacheTable();
        $wpdb->query("DELETE FROM {$table}");
    }

    /**
     * @param int $days
     * @return void
     */
    public static function pruneLogs($days = 90): void
    {
        global $wpdb;

        $table = Install::logsTable();
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE created < %d",
            time() - max(1, (int) $days) * 86400
        ));
    }

    /**
     * @param string $name
     * @return array
     */
    public static function getState($name): array
    {        global $wpdb;

        $table = Install::stateTable();
        $raw = $wpdb->get_var($wpdb->prepare("SELECT value FROM {$table} WHERE name = %s", $name));
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /**
     * @param string $name
     * @param array $value
     * @return void
     */
    public static function setState($name, array $value): void
    {
        global $wpdb;

        $table = Install::stateTable();
        $raw = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $exists = $wpdb->get_var($wpdb->prepare("SELECT name FROM {$table} WHERE name = %s", $name));
        if ($exists) {
            $wpdb->update($table, ['value' => $raw, 'updated' => time()], ['name' => $name]);
        } else {
            $wpdb->insert($table, ['name' => $name, 'value' => $raw, 'updated' => time()]);
        }
    }

    /**
     * Key used to bucket token usage for a quota period.
     *
     * @param string $period daily|monthly|total
     * @return string
     */
    public static function quotaPeriodKey(string $period): string
    {
        return match ($period) {
            'daily' => (string) wp_date('Y-m-d'),
            'total' => 'all',
            default => (string) wp_date('Y-m'),
        };
    }

    /**
     * @param string $period daily|monthly|total
     * @return array{period:string,tokens:int}
     */
    public static function getTokenUsage(string $period = 'monthly'): array
    {
        $key = self::quotaPeriodKey($period);
        $state = self::getState('token_usage');
        if (!is_array($state) || ($state['period'] ?? '') !== $key) {
            return ['period' => $key, 'tokens' => 0];
        }

        return ['period' => $key, 'tokens' => max(0, (int) ($state['tokens'] ?? 0))];
    }

    /**
     * @param int $tokens
     * @param string $period daily|monthly|total
     * @return array{period:string,tokens:int}
     */
    public static function addTokenUsage(int $tokens, string $period = 'monthly'): array
    {
        $usage = self::getTokenUsage($period);
        $usage['tokens'] += max(0, $tokens);
        self::setState('token_usage', $usage);
        return $usage;
    }

    /**
     * @param string $period daily|monthly|total
     * @return void
     */
    public static function resetTokenUsage(string $period = 'monthly'): void
    {
        self::setState('token_usage', ['period' => self::quotaPeriodKey($period), 'tokens' => 0]);
    }

    /**
     * @param int $limit
     * @param array $filters
     * @return array
     */
    public static function recentLogs($limit = 100, array $filters = []): array
    {
        global $wpdb;

        [$where, $args] = self::logWhere($filters);
        $table = Install::logsTable();
        $sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY created DESC LIMIT %d OFFSET %d";
        $args[] = max(1, (int) $limit);
        $args[] = max(0, (int) ($filters['offset'] ?? 0));

        $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array $filters
     * @return int
     */
    public static function countLogs(array $filters = []): int
    {
        global $wpdb;

        [$where, $args] = self::logWhere($filters);
        $table = Install::logsTable();
        $sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";

        return (int) $wpdb->get_var($args ? $wpdb->prepare($sql, $args) : $sql);
    }

    /**
     * @param array $filters
     * @return array{0:string,1:array}
     */
    private static function logWhere(array $filters): array
    {
        global $wpdb;

        $where = ['1=1'];
        $args = [];
        $decision = (string) ($filters['decision'] ?? '');
        if (in_array($decision, ['approved', 'waiting', 'rejected'], true)) {
            $where[] = 'decision = %s';
            $args[] = $decision;
        }

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . $wpdb->esc_like($keyword) . '%';
            $where[] = '(author LIKE %s OR email LIKE %s OR ip LIKE %s OR ai_reason LIKE %s)';
            array_push($args, $like, $like, $like, $like);
        }

        return [implode(' AND ', $where), $args];
    }

    /**
     * @param int $commentId
     * @return array|null
     */
    public static function logByCommentId($commentId)
    {
        global $wpdb;

        $table = Install::logsTable();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE comment_id = %d ORDER BY id DESC LIMIT 1",
            (int) $commentId
        ), ARRAY_A);

        return $row ?: null;
    }

    /**
     * @param int[] $commentIds
     * @return array
     */
    public static function logsByCommentIds(array $commentIds): array
    {
        global $wpdb;

        $ids = array_values(array_filter(array_map('intval', $commentIds)));
        if (!$ids) {
            return [];
        }

        $table = Install::logsTable();
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = "SELECT * FROM {$table} WHERE comment_id IN ({$placeholders}) ORDER BY id DESC";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $ids), ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $commentId = (int) $row['comment_id'];
            if (!isset($result[$commentId])) {
                $result[$commentId] = $row;
            }
        }

        return $result;
    }

    /**
     * @param int $commentId
     * @param string $decision
     * @param int $userId
     * @return void
     */
    public static function markManual($commentId, $decision, $userId): void
    {
        global $wpdb;

        $table = Install::logsTable();
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE comment_id = %d ORDER BY id DESC LIMIT 1",
            (int) $commentId
        ));
        if (!$id) {
            return;
        }

        $wpdb->update($table, [
            'manual' => 1,
            'manual_by' => (int) $userId,
            'decision' => (string) $decision,
        ], ['id' => (int) $id]);
    }

    /**
     * @return array
     */
    public static function stats(): array
    {
        global $wpdb;

        $table = Install::logsTable();
        $today = new \DateTimeImmutable('today', wp_timezone());
        $start = $today->getTimestamp();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT decision, COUNT(*) AS num FROM {$table} WHERE created >= %d GROUP BY decision",
            $start
        ), ARRAY_A);

        $stats = ['approved' => 0, 'waiting' => 0, 'rejected' => 0, 'today_total' => 0];
        foreach ((array) $rows as $row) {
            $decision = (string) $row['decision'];
            if (isset($stats[$decision])) {
                $stats[$decision] = (int) $row['num'];
                $stats['today_total'] += (int) $row['num'];
            }
        }
        $stats['total'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

        return $stats;
    }

    /**
     * @param string $keyword
     * @param int $limit
     * @return array
     */
    public static function recentReputations($keyword = '', $limit = 100): array
    {
        global $wpdb;

        $table = Install::reputationTable();
        $sql = "SELECT * FROM {$table}";
        $args = [];
        if ($keyword !== '') {
            $sql .= ' WHERE identity_value LIKE %s';
            $args[] = '%' . $wpdb->esc_like($keyword) . '%';
        }
        $sql .= ' ORDER BY updated DESC LIMIT %d';
        $args[] = max(1, (int) $limit);

        $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }
        foreach ($rows as &$row) {
            $row['computed_level'] = self::computeTrustLevel($row);
        }
        unset($row);

        return $rows;
    }

    /**
     * @param string $hash
     * @param string $level whitelist|default
     * @return void
     */
    public static function setTrust($hash, $level): void
    {
        global $wpdb;

        $table = Install::reputationTable();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE identity_hash = %s", $hash), ARRAY_A);
        if (!$row) {
            return;
        }

        $trust = $level === 'whitelist'
            ? 'whitelist'
            : self::computeTrustLevel([
                'trust_level' => 'new',
                'approved_count' => (int) $row['approved_count'],
                'spam_count' => (int) $row['spam_count'],
                'last_spam_at' => (int) $row['last_spam_at'],
            ]);

        $wpdb->update($table, ['trust_level' => $trust, 'updated' => time()], ['identity_hash' => $hash]);
    }
}
