<?php
// Separate PHP process: loads the installed entry and complete new bootstrap.
define('ABSPATH', $argv[1] . '/wordpress/');
define('WP_CONTENT_DIR', $argv[1] . '/content');
$_SERVER['HTTP_X_AICOMMENTAUDIT_HEALTH'] = $argv[2];
function plugin_dir_path($file) { return dirname($file) . '/'; }
function plugin_dir_url($file) { return 'https://example.test/plugin/'; }
function wp_normalize_path($path) { return str_replace('\\', '/', $path); }
function wp_mkdir_p($path) { return is_dir($path) || mkdir($path, 0777, true); }
function wp_die($message, $title = '', $args = []) { throw new RuntimeException($message); }
function esc_html__($text, $domain = '') { return $text; }
function status_header($code) { http_response_code($code); }
require $argv[1] . '/plugin/ai-comment-audit.php';
throw new RuntimeException('Health probe did not intercept plugin boot');
