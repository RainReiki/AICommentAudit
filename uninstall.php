<?php
/**
 * Uninstall handler.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

if (!defined('AICOMMENTAUDIT_OPTION')) {
    define('AICOMMENTAUDIT_OPTION', 'aicommentaudit_settings');
}

require_once __DIR__ . '/includes/class-install.php';

\AICommentAudit\Install::uninstall();
