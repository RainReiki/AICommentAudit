<?php
/**
 * Load every class explicitly. The plugin has no Composer dependency.
 */

defined('ABSPATH') || exit;

require_once AICOMMENTAUDIT_DIR . 'includes/rules/class-word-filter.php';
require_once AICOMMENTAUDIT_DIR . 'includes/rules/class-blacklist.php';
require_once AICOMMENTAUDIT_DIR . 'includes/rules/class-local-precheck.php';
require_once AICOMMENTAUDIT_DIR . 'includes/rules/class-trust.php';
require_once AICOMMENTAUDIT_DIR . 'includes/rules/class-identity-risk.php';
require_once AICOMMENTAUDIT_DIR . 'includes/rules/class-identity-context.php';
require_once AICOMMENTAUDIT_DIR . 'includes/rules/class-conversation-context.php';
require_once AICOMMENTAUDIT_DIR . 'includes/ai/class-prompt-builder.php';
require_once AICOMMENTAUDIT_DIR . 'includes/ai/class-result-parser.php';
require_once AICOMMENTAUDIT_DIR . 'includes/ai/class-client.php';
require_once AICOMMENTAUDIT_DIR . 'includes/class-settings.php';
require_once AICOMMENTAUDIT_DIR . 'includes/class-repository.php';
require_once AICOMMENTAUDIT_DIR . 'includes/class-logger.php';
require_once AICOMMENTAUDIT_DIR . 'includes/class-engine.php';
require_once AICOMMENTAUDIT_DIR . 'includes/class-history-scan.php';
require_once AICOMMENTAUDIT_DIR . 'includes/class-history-moderation.php';
require_once AICOMMENTAUDIT_DIR . 'includes/class-comment-hooks.php';
require_once AICOMMENTAUDIT_DIR . 'includes/class-ajax.php';
require_once AICOMMENTAUDIT_DIR . 'includes/class-admin.php';
require_once AICOMMENTAUDIT_DIR . 'includes/class-install.php';
require_once AICOMMENTAUDIT_DIR . 'includes/class-plugin.php';
