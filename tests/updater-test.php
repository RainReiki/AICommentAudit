<?php
/** Isolated integration tests. Requires an expendable MariaDB server, not a WP site. */
if (!extension_loaded('mysqli')) {
    fwrite(STDERR, "mysqli is required\n");
    exit(1);
}
mysqli_report(MYSQLI_REPORT_OFF);
function connection(?string $database = null): mysqli {
    $db = new mysqli(getenv('AICA_DB_HOST') ?: '127.0.0.1', getenv('AICA_DB_USER') ?: 'root',
        getenv('AICA_DB_PASSWORD') ?: '', $database, (int) (getenv('AICA_DB_PORT') ?: 3306));
    if ($db->connect_errno) throw new RuntimeException($db->connect_error);
    $db->set_charset('utf8mb4');
    return $db;
}
if (($argv[1] ?? '') !== 'child') {
    $db = connection();
    $cases = ['success', 'download', 'syntax', 'conflict', 'migration', 'health', 'option',
        'recover_before_swap', 'recover_after_swap', 'committed_cleanup', 'validation', 'manual', 'concurrent', 'shutdown'];
    foreach ($cases as $case) {
        $database = 'aica_test_' . bin2hex(random_bytes(6));
        $db->query('CREATE DATABASE `' . $database . '`');
        $env = getenv();
        $env['AICA_DB_NAME'] = $database;
        $process = proc_open([PHP_BINARY, __FILE__, 'child', $case], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, null, $env);
        $status = proc_close($process);
        $db->query('DROP DATABASE `' . $database . '`');
        if ($status !== 0) exit($status);
    }
    echo "PASS: all 14 updater integration scenarios on real MariaDB\n";
    exit;
}

$case = $argv[2];
$repo = dirname(__DIR__);
$work = sys_get_temp_dir() . '/aica-fixture-' . bin2hex(random_bytes(6));
mkdir($work . '/plugin', 0777, true);
define('ABSPATH', $work . '/wordpress/');
define('WP_CONTENT_DIR', $work . '/content');
define('AICOMMENTAUDIT_DIR', $work . '/plugin/');
define('AICOMMENTAUDIT_FILE', AICOMMENTAUDIT_DIR . 'ai-comment-audit.php');
preg_match('/Version:\s*(\d+\.\d+\.\d+)/', file_get_contents($repo . '/ai-comment-audit.php'), $installedMatch);
define('AICOMMENTAUDIT_VERSION', $installedMatch[1]);
define('HOUR_IN_SECONDS', 3600);
define('ARRAY_A', 'ARRAY_A');
function wp_normalize_path($path) { return str_replace('\\', '/', $path); }
function wp_mkdir_p($path) { return is_dir($path) || mkdir($path, 0777, true); }
function wp_die($message, $title = '', $args = []) { throw new RuntimeException($message); }
function esc_html__($text, $domain = '') { return $text; }
function get_bloginfo($key) { return '7.1.5'; }
function is_multisite() { return false; }
function admin_url($path) { return 'https://example.test/wp-admin/' . $path; }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = false) {
    if (($GLOBALS['case'] ?? '') === 'option' && $key === 'aicommentaudit_migration_version' && $value === '1.7.0') return false;
    $GLOBALS['options'][$key] = $value; return true;
}
function delete_option($key) { unset($GLOBALS['options'][$key]); }
function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
function set_transient($key, $value, $ttl) { $GLOBALS['transients'][$key] = $value; }
function delete_transient($key) { unset($GLOBALS['transients'][$key]); }
function wp_strip_all_tags($text) { return strip_tags($text); }
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
function is_wp_error($r) { return false; }
function wp_safe_remote_get($url, $args) {
    $GLOBALS['requests'][] = $url;
    if (isset($args['headers']['X-AICommentAudit-Health'])) {
        $token = $args['headers']['X-AICommentAudit-Health'];
        if ($GLOBALS['case'] === 'shutdown') {
            // Terminate at the latest possible precommit point, after swapping
            // both files and tables. The updater's real shutdown handler runs
            // first, then this assertion observes its complete rollback.
            register_shutdown_function(static function () {
                unchanged();
                echo "PASS: shutdown recovery after files and database swap\n";
            });
            exit;
        }
        if ($GLOBALS['case'] === 'health') return ['code' => 500, 'body' => ''];
        $process = proc_open([PHP_BINARY, __DIR__ . '/probe.php', $GLOBALS['work'], $token],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $body = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0) throw new RuntimeException('Health subprocess failed: ' . $error . $body);
        return ['code' => 200, 'body' => $body];
    }
    if (strpos($url, 'api.github.com/') !== false) {
        return ['code' => 200, 'body' => json_encode(['tag_name' => 'v1.7.0', 'draft' => false, 'prerelease' => false,
            'assets' => [['name' => 'update-manifest.json',
                'browser_download_url' => 'https://github.com/RainReiki/AICommentAudit/releases/download/v1.7.0/update-manifest.json',
                'digest' => 'sha256:' . hash('sha256', $GLOBALS['targetBytes'])]]])];
    }
    if (strpos($url, '/releases/download/') !== false) return ['code' => 200, 'body' => $GLOBALS['targetBytes']];
    $path = explode('/v1.7.0/', $url, 2)[1];
    $bytes = $GLOBALS['targetFiles'][$path];
    if ($GLOBALS['case'] === 'download' && $path === 'README.md') $bytes .= 'corrupt';
    return ['code' => 200, 'body' => $bytes];
}

final class TestDatabase {
    public string $prefix = 'wp_';
    public string $last_error = '';
    private mysqli $db;
    public function __construct() { $this->db = connection(getenv('AICA_DB_NAME')); }
    public function prepare($sql, ...$args) {
        foreach ($args as $arg) {
            $sql = preg_replace_callback('/%[sd]/', fn($m) => $m[0] === '%d' ? (string) (int) $arg : "'" . $this->db->real_escape_string($arg) . "'", $sql, 1);
        }
        return $sql;
    }
    public function esc_like($text) { return addcslashes($text, '_%\\'); }
    public function query($sql) {
        $r = $this->db->query($sql);
        $this->last_error = $this->db->error;
        return $r;
    }
    public function get_var($sql) { $r = $this->query($sql); return $r instanceof mysqli_result ? ($r->fetch_row()[0] ?? null) : null; }
    public function get_results($sql, $format = null) { $r = $this->query($sql); return $r instanceof mysqli_result ? $r->fetch_all(MYSQLI_ASSOC) : []; }
    public function update($table, $values, $where) {
        $set = $filter = [];
        foreach ($values as $key => $value) $set[] = '`' . $key . '` = ' . ($value === null ? 'NULL' : $this->prepare('%s', $value));
        foreach ($where as $key => $value) $filter[] = '`' . $key . '` ' . ($value === null ? 'IS NULL' : '= ' . $this->prepare('%s', $value));
        return $this->query('UPDATE `' . $table . '` SET ' . implode(', ', $set) . ' WHERE ' . implode(' AND ', $filter));
    }
}
$wpdb = new TestDatabase();
foreach (['logs', 'reputation', 'ai_cache', 'state'] as $key) {
    $wpdb->query('CREATE TABLE wp_aicommentaudit_' . $key . ' (id INT AUTO_INCREMENT PRIMARY KEY, content VARCHAR(100)) ENGINE=InnoDB');
    $wpdb->query("INSERT INTO wp_aicommentaudit_{$key} VALUES (1, 'original')");
    $wpdb->query("ALTER TABLE wp_aicommentaudit_{$key} AUTO_INCREMENT = 101");
}
$options = ['aicommentaudit_migration_version' => AICOMMENTAUDIT_VERSION];
$transients = $requests = [];
foreach (['class-guard', 'class-release', 'class-migrations', 'class-updater'] as $file) require $repo . '/includes/update/' . $file . '.php';
use AICommentAudit\Update\Guard;
use AICommentAudit\Update\Release;
use AICommentAudit\Update\Updater;
use AICommentAudit\Update\Migrations;

$installed = json_decode(file_get_contents($repo . '/dist/update-manifest.json'), true);
$originalFiles = [];
foreach ($installed['files'] as $path => $entry) {
    $bytes = str_replace("\r\n", "\n", file_get_contents($repo . '/' . $path));
    Guard::write(Guard::path($path), $bytes, 0644);
    $originalFiles[$path] = $bytes;
}
$originalFiles['assets/obsolete.txt'] = 'old release only';
Guard::write(Guard::path('assets/obsolete.txt'), $originalFiles['assets/obsolete.txt'], 0644);
$installed['files']['assets/obsolete.txt'] = ['sha256' => hash('sha256', $originalFiles['assets/obsolete.txt']), 'size' => strlen($originalFiles['assets/obsolete.txt'])];
$originalManifest = json_encode($installed);
Guard::write(AICOMMENTAUDIT_DIR . Release::MANIFEST, $originalManifest, 0644);
Guard::write(AICOMMENTAUDIT_DIR . 'local-extra.txt', 'keep me', 0644);
touch(AICOMMENTAUDIT_DIR . 'assets/admin.js', 1600000000);
$targetFiles = $originalFiles;
unset($targetFiles['assets/obsolete.txt']);
$targetFiles['README.md'] .= "\nFixture upgrade\n";
$targetFiles['ai-comment-audit.php'] = str_replace(AICOMMENTAUDIT_VERSION, '1.7.0', $targetFiles['ai-comment-audit.php']);
$targetFiles['includes/new-file.php'] = $case === 'syntax' ? '<?php syntax error here' : '<?php /* new */';
$targetFiles['migrations/1.6.0.json'] = json_encode(['version' => '1.6.0', 'from' => AICOMMENTAUDIT_VERSION, 'steps' => [
    ['table' => 'logs', 'action' => 'add_column', 'column' => 'upgrade_note', 'definition' => "VARCHAR(30) NOT NULL DEFAULT ''"]]]);
$targetFiles['migrations/1.7.0.json'] = json_encode(['version' => '1.7.0', 'from' => '1.6.0', 'steps' => [
    ['table' => 'logs', 'action' => 'update_rows', 'values' => [$case === 'migration' ? 'missing_column' : 'upgrade_note' => 'done'], 'where' => ['id' => 1]]]]);
$target = $installed;
$target['version'] = '1.7.0';
$target['files'] = [];
foreach ($targetFiles as $path => $bytes) $target['files'][$path] = ['sha256' => hash('sha256', $bytes), 'size' => strlen($bytes)];
$target['migrations'][] = ['version' => '1.6.0', 'from' => AICOMMENTAUDIT_VERSION, 'path' => 'migrations/1.6.0.json'];
$target['migrations'][] = ['version' => '1.7.0', 'from' => '1.6.0', 'path' => 'migrations/1.7.0.json'];
$targetBytes = json_encode($target);
Guard::enter();
$checks = 0;
function check($condition, $message) { global $checks; $checks++; if (!$condition) throw new RuntimeException('FAILED: ' . $message); }
function expectFailure(callable $f, string $message) { try { $f(); } catch (Throwable $e) { return; } throw new RuntimeException('Expected failure: ' . $message); }
function unchanged(): void {
    global $originalFiles, $originalManifest, $wpdb;
    foreach ($originalFiles as $path => $bytes) check(file_get_contents(Guard::path($path)) === $bytes, 'restored ' . $path);
    check(file_get_contents(AICOMMENTAUDIT_DIR . Release::MANIFEST) === $originalManifest, 'restored manifest');
    check(!file_exists(AICOMMENTAUDIT_DIR . 'includes/new-file.php'), 'new file rolled back');
    check(!file_exists(AICOMMENTAUDIT_DIR . 'migrations/1.6.0.json'), 'migration file rolled back');
    check($wpdb->get_var('SELECT content FROM wp_aicommentaudit_logs WHERE id=1') === 'original', 'data restored');
    check(!$wpdb->get_results("SHOW COLUMNS FROM wp_aicommentaudit_logs LIKE 'upgrade_note'"), 'schema restored');
    check(get_option('aicommentaudit_migration_version') === AICOMMENTAUDIT_VERSION, 'db version restored');
    check(filemtime(AICOMMENTAUDIT_DIR . 'assets/admin.js') === 1600000000, 'untouched mtime');
    check(file_get_contents(AICOMMENTAUDIT_DIR . 'local-extra.txt') === 'keep me', 'extra preserved');
    check(!is_file(Guard::directory() . '/journal.json'), 'journal cleaned');
    check(!$wpdb->get_results("SHOW TABLES LIKE 'aica\\_%'"), 'no leftover database backups');
}
try {
    if ($case === 'success') {
        $result = Updater::install();
        foreach ($targetFiles as $path => $bytes) check(file_get_contents(Guard::path($path)) === $bytes, 'installed ' . $path);
        check(!file_exists(Guard::path('assets/obsolete.txt')), 'obsolete removed');
        check($wpdb->get_var('SELECT upgrade_note FROM wp_aicommentaudit_logs WHERE id=1') === 'done', 'ordered schema then data migrations');
        $status = $wpdb->get_results("SHOW TABLE STATUS WHERE Name = 'wp_aicommentaudit_logs'");
        check((int) $status[0]['Auto_increment'] === 101, 'auto-increment high water mark retained');
        check(get_option('aicommentaudit_migration_version') === '1.7.0', 'version advanced');
        check(filemtime(AICOMMENTAUDIT_DIR . 'assets/admin.js') === 1600000000, 'unchanged file never touched');
        check(file_get_contents(AICOMMENTAUDIT_DIR . 'local-extra.txt') === 'keep me', 'local extras preserved');
        check($result['files'] === 6 && $result['migrations'] === 2, 'delta counts');
        check(count(array_filter($requests, fn($u) => strpos($u, 'raw.githubusercontent.com') !== false)) === 5, 'only five changed or added files downloaded');
        check(!is_file(Guard::directory() . '/journal.json'), 'success cleanup');
        check(!$wpdb->get_results("SHOW TABLES LIKE 'aica\\_%'"), 'success database cleanup');
    } elseif ($case === 'validation') {
        foreach (['../outside.php', 'includes/../../outside', '/absolute', 'C:/absolute', 'includes\\evil', '.git/config', 'includes//x'] as $path) {
            expectFailure(fn() => Guard::path($path), $path);
        }
        $bad = $target; $bad['files']['../escape.php'] = ['sha256' => str_repeat('a', 64), 'size' => 1];
        expectFailure(fn() => Release::manifest(json_encode($bad), '1.7.0'), 'manifest path');
        $bad = $target; $bad['migrations'] = array_reverse($bad['migrations']);
        expectFailure(fn() => Migrations::pending($bad, '1.5.0'), 'out of order');
        $bad = $target; array_pop($bad['migrations']);
        expectFailure(fn() => Migrations::pending($bad, '1.5.0'), 'missing final version');
        $bad = $target; $bad['files']['migrations/1.5.0.json']['sha256'] = str_repeat('b', 64);
        expectFailure(fn() => Updater::plan($bad, $installed), 'rewritten history');
        expectFailure(fn() => Migrations::document(json_encode(['version' => '1.6.0', 'from' => AICOMMENTAUDIT_VERSION, 'steps' => [['table' => 'options', 'action' => 'drop_column', 'column' => 'secret']]]), '1.6.0'), 'outside plugin tables');
        expectFailure(fn() => Migrations::document(json_encode(['version' => '1.6.0', 'from' => AICOMMENTAUDIT_VERSION, 'steps' => [['table' => 'logs', 'action' => 'add_column', 'column' => 'x', 'definition' => 'INT; DROP TABLE wp_options']]]), '1.6.0'), 'SQL escape');
        check(true, '13 validation rejections');
        unchanged();
    } elseif (in_array($case, ['recover_before_swap', 'recover_after_swap', 'committed_cleanup'], true)) {
        Guard::exclusive();
        $id = bin2hex(random_bytes(8));
        $j = ['id' => $id, 'root' => wp_normalize_path(AICOMMENTAUDIT_DIR), 'phase' => 'applying', 'old_db_version' => AICOMMENTAUDIT_VERSION, 'tables' => [], 'operations' => []];
        $path = 'README.md';
        Guard::write(Guard::directory() . '/' . $id . '/backup/' . $path, $originalFiles[$path], 0600);
        $j['operations'][] = ['path' => $path, 'existed' => true, 'mode' => 0644, 'mtime' => 1600000001, 'backup_hash' => hash('sha256', $originalFiles[$path])];
        Guard::journal($j);
        Migrations::prepare($j, [Migrations::document($targetFiles['migrations/1.6.0.json'], '1.6.0')]);
        Guard::write(Guard::path($path), 'interrupted update', 0644);
        if ($case !== 'recover_before_swap') {
            Migrations::swap($j);
            update_option('aicommentaudit_migration_version', '1.7.0');
        }
        if ($case === 'committed_cleanup') {
            $j['phase'] = 'committed'; Guard::journal($j);
            Guard::recover();
            check(file_get_contents(Guard::path($path)) === 'interrupted update', 'committed files kept');
            check($wpdb->get_results("SHOW COLUMNS FROM wp_aicommentaudit_logs LIKE 'upgrade_note'"), 'committed database kept');
        } else {
            Guard::recover();
            unchanged();
        }
        Guard::recover(); // A repeated recovery is harmless.
    } elseif ($case === 'concurrent') {
        $process = proc_open([PHP_BINARY, __DIR__ . '/lock-probe.php', $work],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]); $body = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
        check(proc_close($process) === 0 && $body === 'blocked', 'exclusive upgrade waits for existing shared requests');
        unchanged();
    } elseif ($case === 'manual') {
        delete_option('aicommentaudit_migration_version');
        Migrations::upgradeInstalled();
        check(get_option('aicommentaudit_migration_version') === AICOMMENTAUDIT_VERSION, 'first install baseline migrated');
        check(!is_file(Guard::directory() . '/journal.json'), 'first install cleanup');
    } else {
        if ($case === 'conflict') {
            Guard::write(Guard::path('README.md'), 'my local customization', 0644);
            $originalFiles['README.md'] = 'my local customization';
        }
        expectFailure(fn() => Updater::install(), $case);
        unchanged();
    }
    echo 'PASS: ' . $case . ' (' . $checks . " assertions)\n";
} catch (Throwable $e) {
    fwrite(STDERR, $case . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\nFixture retained at " . $work . "\n");
    exit(1);
}
// Never delete a caller-selected path. The fixture directory was generated above.
function removeFixture($path) {
    foreach (new FilesystemIterator($path) as $f) $f->isDir() && !$f->isLink() ? removeFixture($f->getPathname()) : unlink($f->getPathname());
    rmdir($path);
}
Guard::shared();
// Windows keeps the guard lock open until process exit; parent/OS temp cleanup
// can reclaim this small isolated fixture later. Nothing is created in a WP site.
