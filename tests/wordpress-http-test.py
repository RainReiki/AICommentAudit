"""Authenticated WordPress menu/form regression on a disposable loopback site.

Requires a separately running concurrent PHP HTTP server, a fresh WP installation
and its test administrator password via AICA_WP_PASSWORD. Never targets a remote site.
"""
import argparse
import hashlib
import html
import http.cookiejar
import json
from pathlib import Path
import re
import shutil
import subprocess
import urllib.error
import urllib.parse
import urllib.request


def main():
    import os
    parser = argparse.ArgumentParser()
    parser.add_argument('--site', type=Path, required=True)
    parser.add_argument('--base-url', required=True)
    parser.add_argument('--wp-cli', required=True)
    parser.add_argument('--php', default='php')
    args = parser.parse_args()
    if urllib.parse.urlsplit(args.base_url).hostname not in {'127.0.0.1', 'localhost'}:
        raise ValueError('Only a disposable loopback WordPress installation is allowed')
    password = os.environ['AICA_WP_PASSWORD']
    base = args.base_url.rstrip('/')
    site = args.site.resolve()
    fixture = site.parent / 'http-fixture'
    fixture.mkdir(exist_ok=True)

    def cli(*command):
        result = subprocess.run([args.php, args.wp_cli, '--path=' + str(site), '--skip-themes', *command],
                                text=True, encoding='utf-8', capture_output=True)
        if result.returncode:
            raise RuntimeError(result.stdout + result.stderr)
        return result.stdout.strip()

    assert cli('option', 'get', 'siteurl') == base
    cli('config', 'set', 'AICA_HTTP_FIXTURE_DIR', str(fixture))
    mu = site / 'wp-content/mu-plugins'
    mu.mkdir(exist_ok=True)
    shutil.copy2(Path(__file__).with_name('wordpress-http-fixture.php'), mu / 'aica-http-fixture.php')
    plugin = site / 'wp-content/plugins/ai-comment-audit'
    installed = json.loads((plugin / 'update-manifest.json').read_bytes())
    current = installed['version']
    originals = {path: (plugin / path).read_bytes() for path in installed['files']}
    originals['update-manifest.json'] = (plugin / 'update-manifest.json').read_bytes()
    assert all(hashlib.sha256(originals[path]).hexdigest() == entry['sha256']
               for path, entry in installed['files'].items()), 'Installer differs from its manifest'
    extra = plugin / 'local-extra.txt'
    extra.write_text('test local extra; must survive', encoding='utf-8')
    untouched = plugin / 'assets/admin.js'
    os.utime(untouched, (1600000000, 1600000000))
    target = json.loads(json.dumps(installed))
    target['version'] = '1.7.0'
    # The synthetic future release targets the actual test core, allowing CI
    # to exercise menu routing on the latest public WP as well as local fixtures.
    target['requires_wp'] = cli('core', 'version')
    target['migrations'] += [
        {'version': '1.6.0', 'from': current, 'path': 'migrations/1.6.0.json'},
        {'version': '1.7.0', 'from': '1.6.0', 'path': 'migrations/1.7.0.json'},
    ]
    target_files = {path: data for path, data in originals.items() if path != 'update-manifest.json'}
    target_files['ai-comment-audit.php'] = originals['ai-comment-audit.php'].replace(current.encode(), b'1.7.0')
    target_files['README.md'] += b'\nHTTP regression target\n'
    target_files['includes/http-upgrade-fixture.php'] = b'<?php /* Added by test upgrade. */\n'

    def configure(mode):
        target_files['migrations/1.6.0.json'] = json.dumps({'version': '1.6.0', 'from': current, 'steps': [
            {'table': 'logs', 'action': 'add_column', 'column': 'http_upgrade_note',
             'definition': "VARCHAR(30) NOT NULL DEFAULT ''"}]}).encode()
        target_files['migrations/1.7.0.json'] = json.dumps({'version': '1.7.0', 'from': '1.6.0', 'steps': [
            {'table': 'logs', 'action': 'update_rows',
             'values': {'missing_fixture_column' if mode == 'migration' else 'http_upgrade_note': 'ordered'},
             'where': {'id': 1}}]}).encode()
        for path, data in target_files.items():
            destination = fixture / 'files' / path
            destination.parent.mkdir(parents=True, exist_ok=True)
            destination.write_bytes(data)
            target['files'][path] = {'sha256': hashlib.sha256(data).hexdigest(), 'size': len(data)}
        (fixture / 'update-manifest.json').write_text(json.dumps(target), encoding='utf-8')
        (fixture / 'config.json').write_text(json.dumps({'mode': mode}), encoding='utf-8')
        (fixture / 'downloads.jsonl').write_text('', encoding='utf-8')

    configure('download')
    cli('eval', "$s = AICommentAudit\\Settings::get(); $s['apiKey']='http-preserved-placeholder'; "
        "update_option(AICOMMENTAUDIT_OPTION, $s); global $wpdb; "
        "$wpdb->replace($wpdb->prefix . 'aicommentaudit_logs', ['id'=>1,'content'=>'http seed']);")
    jar = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))

    def request(url, data=None):
        url = urllib.parse.urljoin(base + '/wp-admin/', url)
        encoded = None if data is None else urllib.parse.urlencode(data).encode()
        try:
            response = opener.open(url, encoded, timeout=150)
        except urllib.error.HTTPError as error:
            response = error
        body = response.read().decode('utf-8', errors='replace')
        return response.code, response.url, body

    request(base + '/wp-login.php')
    status, url, dashboard = request(base + '/wp-login.php', {
        'log': 'audit-test', 'pwd': password, 'wp-submit': 'Log In',
        'redirect_to': base + '/wp-admin/', 'testcookie': '1'})
    assert status == 200 and urllib.parse.urlsplit(url).path == '/wp-admin/', (status, url)
    links = re.findall(r'<a\b[^>]*href=[\'"]([^\'"]+)[\'"][^>]*>(.*?)</a>', dashboard, re.S)
    upgrade_links = [html.unescape(link) for link, label in links
                     if re.sub('<[^>]*>', '', label).strip() == '在线升级']
    assert upgrade_links == ['admin.php?page=aicommentaudit-update'], upgrade_links
    page = upgrade_links[0]
    status, url, body = request(page)
    assert status == 200 and 'aicommentaudit_check_update' in body
    print('PASS: actual sidebar URL and authenticated update page HTTP 200', flush=True)

    def form(action, source=None):
        source = request(page)[2] if source is None else source
        for attributes, content in re.findall(r'<form\b([^>]*)>(.*?)</form>', source, re.S):
            fields = dict(re.findall(r'<input\b[^>]*name="([^"]+)"[^>]*value="([^"]*)"', content))
            if fields.get('action') == action:
                address = html.unescape(re.search(r'action="([^"]+)"', attributes).group(1))
                return address, {key: html.unescape(value) for key, value in fields.items()}
        raise AssertionError('Expected form missing: ' + action)

    address, fields = form('aicommentaudit_check_update', body)
    invalid = dict(fields, _wpnonce='invalid-nonce')
    assert request(address, invalid)[0] == 403
    print('PASS: real admin-post endpoint rejects invalid nonce', flush=True)

    def db_state():
        output = cli('eval', "global $wpdb; echo wp_json_encode(["
            "'version'=>get_option('aicommentaudit_migration_version'),"
            "'columns'=>$wpdb->get_results('SHOW COLUMNS FROM ' . $wpdb->prefix . 'aicommentaudit_logs', ARRAY_A),"
            "'note'=>$wpdb->get_var('SELECT ' . "
            "($wpdb->get_var(\"SHOW COLUMNS FROM {$wpdb->prefix}aicommentaudit_logs LIKE 'http_upgrade_note'\") ? 'http_upgrade_note' : 'content') . "
            "' FROM ' . $wpdb->prefix . 'aicommentaudit_logs WHERE id=1'),"
            "'key'=>AICommentAudit\\Settings::get()['apiKey'],"
            "'backups'=>$wpdb->get_results(\"SHOW TABLES LIKE 'aica\\_%'\", ARRAY_A)]);")
        return json.loads(output)

    original_db = db_state()
    assert original_db['version'] == current
    for mode in ['download', 'migration', 'health', 'shutdown', 'success']:
        configure(mode)
        address, fields = form('aicommentaudit_check_update')
        status, url, checked = request(address, fields)
        assert status == 200 and url.endswith('admin.php?page=aicommentaudit-update'), (mode, status, url)
        assert '1.7.0' in checked and 'aicommentaudit_install_update' in checked
        notes = re.search(r'<div class="aicommentaudit-release-notes">(.*?)</div>', checked, re.S)
        assert notes is not None, 'Formatted release notes missing'
        rendered = notes.group(1)
        assert all(fragment in rendered for fragment in ['<h2>', '<ul>', '<li>', '<strong>', '<code>', '<a href=', '<pre>']), rendered
        assert not any(fragment in rendered for fragment in ['## ', '**', '&lt;h2', '<script', 'javascript:', 'onclick=']), rendered
        address, fields = form('aicommentaudit_install_update', checked)
        status, url, result = request(address, fields)
        assert status == 200, (mode, status, result[:500])
        if mode == 'shutdown':
            status, url, result = request(page)
            assert status == 200
        assert url.endswith('admin.php?page=aicommentaudit-update'), (mode, url)
        assert extra.read_text(encoding='utf-8') == 'test local extra; must survive'
        assert untouched.stat().st_mtime == 1600000000, 'Unchanged asset was rewritten'
        db = db_state()
        assert not db['backups'] and db['key'] == 'http-preserved-placeholder'
        if mode != 'success':
            for path, original in originals.items():
                assert (plugin / path).read_bytes() == original, (mode, 'rollback mismatch', path)
            assert not (plugin / 'includes/http-upgrade-fixture.php').exists()
            assert db == original_db, (mode, db, original_db)
            print('PASS: authenticated check/install flow, complete file/DB recovery:', mode, flush=True)
        else:
            for path, data in target_files.items():
                assert (plugin / path).read_bytes() == data, path
            assert db['version'] == '1.7.0' and db['note'] == 'ordered', db
            assert 'success' == cli('eval', "echo get_option('aicommentaudit_update_result')['status'];")
            paths = [json.loads(line) for line in (fixture / 'downloads.jsonl').read_text().splitlines()]
            expected = {path for path, data in target_files.items() if originals.get(path) != data}
            assert set(paths) == expected and len(paths) == len(expected), paths
            status, url, final_dashboard = request(base + '/wp-admin/')
            assert status == 200 and 'admin.php?page=aicommentaudit-update' in final_dashboard
            print('PASS: real HTTP loopback, cross-version migration, delta-only download and success redirect', flush=True)
    print('PASS: WordPress login, menu, nonce and 5 HTTP upgrade/recovery scenarios', flush=True)


if __name__ == '__main__':
    main()
