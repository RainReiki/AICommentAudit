<?php
define('ABSPATH', $argv[1] . '/wordpress/');
define('WP_CONTENT_DIR', $argv[1] . '/content');
define('AICOMMENTAUDIT_DIR', $argv[1] . '/plugin/');
define('AICOMMENTAUDIT_FILE', AICOMMENTAUDIT_DIR . 'ai-comment-audit.php');
preg_match('/Version:\s*(\d+\.\d+\.\d+)/', file_get_contents(AICOMMENTAUDIT_FILE), $versionMatch);
define('AICOMMENTAUDIT_VERSION', $versionMatch[1]);
function wp_normalize_path($path) { return str_replace('\\', '/', $path); }
function wp_mkdir_p($path) { return is_dir($path) || mkdir($path, 0777, true); }
function wp_die($message, $title = '', $args = []) { throw new RuntimeException($message); }
function esc_html__($text, $domain = '') { return $text; }
require dirname(__DIR__) . '/includes/update/class-guard.php';
AICommentAudit\Update\Guard::enter();
try {
    AICommentAudit\Update\Guard::exclusive();
} catch (RuntimeException $e) {
    echo 'blocked';
    exit;
}
exit(1);
