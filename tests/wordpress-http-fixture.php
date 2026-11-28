<?php
/** Test-only MU plugin. The release builder never includes tests in installers. */
defined('ABSPATH') || exit;
if (!defined('AICA_HTTP_FIXTURE_DIR')) {
    return;
}
add_filter('pre_http_request', static function ($pre, $args, $url) {
    $directory = AICA_HTTP_FIXTURE_DIR;
    $config = json_decode((string) file_get_contents($directory . '/config.json'), true);
    $mode = $config['mode'];
    if (isset($args['headers']['X-AICommentAudit-Health'])) {
        if ($mode === 'health') {
            return new WP_Error('fixture_health', 'Simulated loopback failure');
        }
        if ($mode === 'shutdown') {
            exit; // Exercise the updater's real shutdown recovery handler.
        }
        return $pre; // Use the real concurrent HTTP server and new PHP process.
    }
    $response = static function ($body) {
        return ['headers' => [], 'body' => $body, 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []];
    };
    $prefix = 'https://raw.githubusercontent.com/RainReiki/AICommentAudit/v1.7.0/';
    if ($url === 'https://api.github.com/repos/RainReiki/AICommentAudit/releases/latest') {
        $manifest = file_get_contents($directory . '/update-manifest.json');
        return $response(wp_json_encode(['tag_name' => 'v1.7.0', 'draft' => false, 'prerelease' => false,
            'body' => 'Isolated HTTP upgrade fixture', 'assets' => [['name' => 'update-manifest.json',
                'browser_download_url' => 'https://github.com/RainReiki/AICommentAudit/releases/download/v1.7.0/update-manifest.json',
                'digest' => 'sha256:' . hash('sha256', $manifest)]]]));
    }
    if ($url === 'https://github.com/RainReiki/AICommentAudit/releases/download/v1.7.0/update-manifest.json') {
        return $response(file_get_contents($directory . '/update-manifest.json'));
    }
    if (strpos($url, $prefix) === 0) {
        $path = substr($url, strlen($prefix));
        $manifest = json_decode((string) file_get_contents($directory . '/update-manifest.json'), true);
        if (!isset($manifest['files'][$path])) {
            return new WP_Error('fixture_path', 'Unexpected fixture file');
        }
        file_put_contents($directory . '/downloads.jsonl', json_encode($path) . "\n", FILE_APPEND | LOCK_EX);
        $bytes = file_get_contents($directory . '/files/' . $path);
        if ($mode === 'download' && $path === 'README.md') {
            $bytes .= 'corrupt';
        }
        return $response($bytes);
    }
    return $pre;
}, 10, 3);
