<?php
/**
 * Plugin Name:       AICommentAudit
 * Plugin URI:        https://github.com/RainReiki/AICommentAudit
 * Description:       本地规则 + AI 评论审核。先拦截明显垃圾，再结合 DeepSeek 或 OpenAI 兼容接口、评论者信誉和身份信息给出最终结果。
 * Version:           1.2.0
 * Requires at least: 7.1
 * Requires PHP:      8.0
 * Tested up to:      7.1.5
 * Author:            羽忆
 * Author URI:        https://lizihao.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-comment-audit
 * Domain Path:       /languages
 */

defined('ABSPATH') || exit;

define('AICOMMENTAUDIT_VERSION', '1.2.0');
define('AICOMMENTAUDIT_FILE', __FILE__);
define('AICOMMENTAUDIT_DIR', plugin_dir_path(__FILE__));
define('AICOMMENTAUDIT_URL', plugin_dir_url(__FILE__));
define('AICOMMENTAUDIT_OPTION', 'aicommentaudit_settings');

require AICOMMENTAUDIT_DIR . 'includes/bootstrap.php';

register_activation_hook(__FILE__, ['AICommentAudit\\Install', 'activate']);
register_deactivation_hook(__FILE__, ['AICommentAudit\\Install', 'deactivate']);

add_action('plugins_loaded', static function () {
    AICommentAudit\Plugin::boot();
});
