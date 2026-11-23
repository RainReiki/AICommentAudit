<?php

namespace AICommentAudit\Rules;

defined('ABSPATH') || exit;

/**
 * Bounded local context for the AI identity check.
 *
 * Submitted contact details are not credentials. Only the email prefix/domain,
 * website host, homepage flag and a small set of related profiles are sent.
 */
final class IdentityContext
{
    /**
     * @param array $comment
     * @return array
     */
    public static function build(array $comment): array
    {
        global $wpdb;

        $author = trim((string) ($comment['author'] ?? ''));
        $mail = strtolower(trim((string) ($comment['mail'] ?? '')));
        $url = trim((string) ($comment['url'] ?? ''));
        $host = self::host($url);
        $commentId = (int) ($comment['comment_id'] ?? 0);

        $ownerNames = [];
        $owners = get_users([
            'role' => 'administrator',
            'number' => 8,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);
        foreach ($owners as $owner) {
            if (!empty($owner->display_name)) {
                $ownerNames[] = self::limit((string) $owner->display_name, 60);
            }
            if (!empty($owner->user_login) && $owner->user_login !== $owner->display_name) {
                $ownerNames[] = self::limit((string) $owner->user_login, 60);
            }
        }

        $base = "SELECT comment_ID, comment_author, comment_author_email, comment_author_url, comment_approved
            FROM {$wpdb->comments}
            WHERE comment_type = 'comment' AND comment_approved IN ('1','0','spam')";
        $baseArgs = [];
        if ($commentId > 0) {
            $base .= ' AND comment_ID < %d';
            $baseArgs[] = $commentId;
        }

        $candidates = [];
        foreach (['comment_author_email' => $mail, 'comment_author_url' => $url, 'comment_author' => $author] as $field => $value) {
            if ($value === '') {
                continue;
            }
            $sql = $base . " AND {$field} = %s ORDER BY comment_ID DESC LIMIT 12";
            $args = array_merge($baseArgs, [$value]);
            $rows = $wpdb->get_results($wpdb->prepare($sql, $args));
            foreach ($rows as $row) {
                $candidates[(int) $row->comment_ID] = $row;
            }
        }

        $normalized = self::nameKey($author);
        $sql = $base . ' ORDER BY comment_ID DESC LIMIT 120';
        $rows = $baseArgs
            ? $wpdb->get_results($wpdb->prepare($sql, $baseArgs))
            : $wpdb->get_results($sql);
        foreach ($rows as $row) {
            $sameName = $normalized !== '' && self::nameKey((string) $row->comment_author) === $normalized;
            $sameHost = $host !== '' && self::host((string) $row->comment_author_url) === $host;
            if ($sameName || $sameHost) {
                $candidates[(int) $row->comment_ID] = $row;
            }
        }
        krsort($candidates);

        $profiles = [];
        foreach ($candidates as $row) {
            $profileHost = self::host((string) $row->comment_author_url);
            $rowMail = trim((string) $row->comment_author_email);
            $relation = $mail === '' || $rowMail === ''
                ? 'unknown'
                : (strcasecmp($mail, $rowMail) === 0 ? 'same_unverified_email' : 'different_email');
            $profileAuthor = self::limit((string) $row->comment_author, 60);
            $key = wp_json_encode([$profileAuthor, $profileHost, $relation]);
            if (!isset($profiles[$key])) {
                if (count($profiles) >= 6) {
                    continue;
                }
                $profiles[$key] = [
                    'nickname' => $profileAuthor,
                    'website_host' => $profileHost,
                    'email_relation' => $relation,
                    'approved' => 0,
                    'waiting' => 0,
                    'spam' => 0,
                ];
            }
            $status = (string) $row->comment_approved;
            if ($status === '1') {
                $profiles[$key]['approved']++;
            } elseif ($status === 'spam') {
                $profiles[$key]['spam']++;
            } else {
                $profiles[$key]['waiting']++;
            }
        }

        $at = strrpos($mail, '@');
        $emailName = $at === false ? $mail : substr($mail, 0, $at);
        $emailDomain = $at === false ? '' : strtolower(trim(substr($mail, $at + 1)));

        $userId = (int) ($comment['user_id'] ?? 0);
        $authenticatedOwner = $userId > 0 && user_can($userId, 'moderate_comments');

        return [
            'site' => [
                'title' => self::limit((string) get_bloginfo('name'), 100),
                'host' => self::host((string) home_url('/')),
                'owner_names' => array_values(array_unique($ownerNames)),
            ],
            'current' => [
                'nickname' => self::limit($author, 60),
                'email_name' => self::limit($emailName, 48),
                'email_domain' => self::limit($emailDomain, 100),
                'email_format_valid' => $mail !== '' && is_email($mail) !== false,
                'website_host' => $host,
                'website_is_homepage' => self::isHomepage($url),
                'authenticated_site_owner' => $authenticatedOwner,
            ],
            'related_profiles' => array_values($profiles),
            'history_note' => 'bounded_sample_of_submitted_details_not_verified_identities',
        ];
    }

    /**
     * @param string $url
     * @return string
     */
    private static function host(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $host = wp_parse_url(strpos($url, '://') === false ? 'https://' . $url : $url, PHP_URL_HOST);
        return is_string($host) ? strtolower(self::limit($host, 253)) : '';
    }

    /**
     * @param string $url
     * @return bool
     */
    private static function isHomepage(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        $path = wp_parse_url(strpos($url, '://') === false ? 'https://' . $url : $url, PHP_URL_PATH);
        return !is_string($path) || $path === '' || $path === '/';
    }

    /**
     * @param string $name
     * @return string
     */
    private static function nameKey(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }

        if (function_exists('mb_convert_kana')) {
            $name = mb_convert_kana($name, 'as', 'UTF-8');
        }
        $name = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        return preg_replace('/[\p{Z}\p{P}\p{S}\p{N}\p{Cf}]+/u', '', $name) ?? '';
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
}
