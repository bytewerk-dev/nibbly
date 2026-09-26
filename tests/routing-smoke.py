#!/usr/bin/env python3
"""The same URL contract on PHP's dev server and a real Apache/PHP-FPM stack."""
import argparse
import hashlib
import json
import re
import tempfile
import urllib.parse
from support import Client, Site, write_json


def local_path(base, target):
    url = urllib.parse.urlsplit(urllib.parse.urljoin('http://127.0.0.1' + base, target))
    assert url.hostname == '127.0.0.1', 'Redirect left the test origin'
    return url.path + ('?' + url.query if url.query else '')


def follow(client, path, data=None):
    for _ in range(6):
        status, headers, body = client.request(path, data)
        if status not in (301, 302, 303, 307, 308):
            return path, status, headers, body
        path = local_path(path, headers['Location'])
        if status in (301, 302, 303):
            data = None
    raise AssertionError('Redirect loop')


def check_assets(client, path, html):
    html = re.sub(rb'<!--.*?-->', b'', html, flags=re.S)
    styles = re.findall(rb'<link[^>]+href="([^\"]+\.css(?:\?[^\"]*)?)"', html)
    assert styles, f'No stylesheet in {path}'
    for href in styles:
        asset = local_path(path, href.decode())
        status, headers, _ = client.request(asset)
        assert status == 200 and headers.get('Content-Type', '').startswith('text/css'), (path, asset, status)


def check(site):
    guest = Client(site)
    query = '?redirect=%2Fservices%2Ftest%3Fprobe%3Da%252Fb%23section&check=1'
    aliases = [('/admin', '/admin/'), ('/login', '/admin/'), ('/login/', '/admin/'),
               ('/admin/dashboard', '/admin/dashboard.php'), ('/admin/dashboard/', '/admin/dashboard.php'),
               ('/admin/index/', '/admin/index.php'), ('/tools/entry/', '/tools/entry.php'),
               ('/portal', '/portal/')]
    for source, target in aliases:
        status, headers, _ = guest.request(source + query)
        assert status == 308 and local_path(source, headers['Location']) == target + query, (source, status, headers.get('Location'))
    for source in ['/admin', '/admin/', '/login', '/login/', '/admin/index/']:
        path, status, _, body = follow(Client(site), source)
        assert status == 200 and b'id="loginForm"' in body, source
        check_assets(guest, path, body)
    # A later fragment-directory addition must not turn a working PHP URL into a 404.
    assert guest.request('/tools/entry')[0] == 200
    (site.root / 'tools/entry').mkdir()
    for source in ['/tools/entry', '/tools/entry/']:
        status, headers, _ = guest.request(source)
        assert status == 308 and local_path(source, headers['Location']) == '/tools/entry.php'
        path, status, _, body = follow(guest, source)
        assert status == 200 and b'Endpoint fixture' in body
        check_assets(guest, path, body)
    # A directory canonicalization retains the HTTP method, body and script identity.
    path, status, _, body = follow(guest, '/portal?probe=a%2Fb', {'value': 'sentinel'})
    assert status == 200 and json.loads(body) == {'method': 'POST', 'value': 'sentinel', 'script': '/portal/index.php'}
    for source in ['/', '/en', '/en/', '/services/test', '/services/test/', '/en/services/test', '/en/services/test/']:
        path, status, _, body = follow(guest, source)
        assert status == 200 and b'Public fixture' in body, (source, path, status)
        check_assets(guest, path, body)
    settings_path = site.root / 'content/settings.json'
    settings = json.loads(settings_path.read_text())
    for mode, destination in [('auto', '/services/test?probe=a%2Fb#section'), ('dashboard', '/admin/dashboard.php')]:
        settings.setdefault('general', {})['frontendLoginRedirect'] = mode
        write_json(settings_path, settings)
        client = Client(site)
        source = '/admin?redirect=' + urllib.parse.quote('/services/test?probe=a%2Fb#section', safe='')
        status, headers, _ = client.request(source, {'username': 'admin', 'password': site.password})
        assert status == 308
        source = local_path(source, headers['Location'])
        status, headers, _ = client.request(source, {'username': 'admin', 'password': site.password})
        assert status == 302
        # Keep the fragment while comparing the returned Location.
        resolved = urllib.parse.urljoin('http://127.0.0.1' + source, headers['Location'])
        assert resolved == 'http://127.0.0.1' + destination, (mode, headers['Location'])
        path, status, _, body = follow(client, local_path(source, headers['Location']))
        assert status == 200 and (b'const CSRF_TOKEN' if mode == 'dashboard' else b'Public fixture') in body
        check_assets(client, path, body)
    admin = Client(site).login()
    assert admin.api('list-pages')[1]['success']
    for path in ['/missing-route', '/missing-route/', '/admin/missing-route', '/admin/missing-route/']:
        assert follow(guest, path)[1] == 404, path
    for path in ['/content/users.json', '/%63ontent/users.json', '/admin/config.php', '/admin/config/', '/.git/config']:
        assert follow(guest, path)[1] in (403, 404), path
    assert guest.request('/admin/dashboard.php')[0] == 302
    print('PASS routing: login/slash aliases, preserved query/POST, future directory collisions, relative CSS, both login modes, pages/API/404/security')


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--apache', action='store_true')
    args = parser.parse_args()
    if args.apache:
        from apache_support import ApacheSite
        implementation = ApacheSite
    else:
        implementation = Site
    with tempfile.TemporaryDirectory(prefix='nibbly-routing-') as folder:
        site = implementation(folder)
        (site.root / 'tools').mkdir()
        (site.root / 'tools/entry.php').write_text('<link rel="stylesheet" href="style.css"><h1>Endpoint fixture</h1>')
        (site.root / 'tools/style.css').write_text('body { color: #123; }')
        (site.root / 'portal').mkdir()
        (site.root / 'portal/index.php').write_text("<?php header('Content-Type: application/json'); echo json_encode(['method'=>$_SERVER['REQUEST_METHOD'],'value'=>$_POST['value']??null,'script'=>$_SERVER['SCRIPT_NAME']]);")
        try:
            site.start()
            check(site)
            if args.apache:
                print(site.server_version + '; ' + site.php_version)
                print('Root installation; .htaccess SHA256 ' + hashlib.sha256((site.root / '.htaccess').read_bytes()).hexdigest())
        finally:
            site.close()
