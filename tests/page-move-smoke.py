#!/usr/bin/env python3
"""Moving a page (API move-page) in disposable storage: files, links, menus, redirect, guards."""
import json
import tempfile
from support import Client, Site, write_json

with tempfile.TemporaryDirectory(prefix='nibbly-page-move-') as folder:
    site = Site(folder)
    pages = site.root / 'content/pages'
    write_json(pages / 'en_services.json', {'page': 'en_services', 'lang': 'en', 'title': 'Services', 'nav': ['header'],
               'sections': [{'id': 'intro', 'type': 'text', 'content': '<p>See <a href="/services/test">the test</a>.</p>'}]})
    write_json(pages / 'en_about.json', {'page': 'en_about', 'lang': 'en', 'title': 'About',
               'sections': [{'id': 'intro', 'type': 'text', 'content':
                             '<p><a href="/services">a</a> <a href="/en/services/test/">b</a> <a href="/services-other">c</a> '
                             '<a href="/foo/services">d</a> <a href="/services#top">e</a></p>'}]})
    (site.root / 'content/news').mkdir(exist_ok=True)
    write_json(site.root / 'content/news/2026-11-02-post.json', {'id': '2026-11-02-post', 'title': 'Post', 'date': '2026-11-02',
               'slug': 'post', 'excerpt': 'Read /services now', 'sections': [{'type': 'text', 'content': '<a href="/services/test">x</a>'}]})
    write_json(site.root / 'content/footer.json', {'links': [{'href': '/services', 'label': 'Services'}]})
    menus = json.loads((site.root / 'content/menus.json').read_text())
    menus['menus']['header']['items'] = {'en': [
        {'uid': 'mi_1', 'type': 'page', 'page': 'services', 'label': ''},
        {'uid': 'mi_2', 'type': 'page', 'page': 'services/test', 'label': 'Test'},
        {'uid': 'mi_3', 'type': 'page', 'page': 'about', 'label': ''},
    ]}
    write_json(site.root / 'content/menus.json', menus)
    write_json(site.root / 'content/redirects.json', {'redirects': [{'from': '^/old-services/?$', 'to': '/services', 'status': 301}]})
    site.start()
    try:
        admin = Client(site).login()

        # --- guards
        assert admin.api('move-page', page='en_home', slug='start')[1]['success'] is False, 'home stays'
        assert admin.api('move-page', page='en_services', slug='services')[1]['success'] is False, 'same address'
        assert admin.api('move-page', page='en_services', slug='services/deeper')[1]['success'] is False, 'below itself'
        assert admin.api('move-page', page='en_services', slug='Bad Slug')[1]['success'] is False, 'invalid path'
        assert admin.api('move-page', page='en_services', slug='about')[1]['success'] is False, 'target exists'
        assert admin.api('move-page', page='en_missing', slug='anything')[1]['success'] is False, 'unknown page'
        assert Client(site).api('move-page', page='en_services', slug='offers')[0] == 401, 'login required'

        # --- the move: page and subpage renamed, page/path inside updated, backups kept
        status, moved = admin.api('move-page', page='en_services', slug='offers')
        assert status == 200 and moved['success'], moved
        assert moved['data']['page'] == 'en_offers' and moved['data']['slug'] == 'offers' and moved['data']['moved'] == 2, moved['data']
        assert not (pages / 'en_services.json').exists() and not (pages / 'en_services__test.json').exists()
        offers = json.loads((pages / 'en_offers.json').read_text())
        child = json.loads((pages / 'en_offers__test.json').read_text())
        assert offers['page'] == 'en_offers' and offers['path'] == 'offers' and offers['title'] == 'Services', offers
        assert child['page'] == 'en_offers__test' and child['path'] == 'offers/test', child
        assert offers['sections'][0]['content'] == '<p>See <a href="/offers/test">the test</a>.</p>', offers['sections']
        backups = sorted((site.root / 'backups').glob('en_services*_*.json'))
        assert len(backups) == 2, backups
        slugs = {p['slug'] for p in moved['data']['pageList']['pages']}
        assert 'offers' in slugs and 'services' not in slugs, slugs

        # --- links in other content: exact page and its subpages, with or without language prefix,
        #     not pages whose address merely starts with the old one or contains it deeper
        about = json.loads((pages / 'en_about.json').read_text())['sections'][0]['content']
        assert about == ('<p><a href="/offers">a</a> <a href="/en/offers/test/">b</a> <a href="/services-other">c</a> '
                         '<a href="/foo/services">d</a> <a href="/offers#top">e</a></p>'), about
        post = json.loads((site.root / 'content/news/2026-11-02-post.json').read_text())
        assert post['excerpt'] == 'Read /offers now' and post['sections'][0]['content'] == '<a href="/offers/test">x</a>', post
        footer = json.loads((site.root / 'content/footer.json').read_text())
        assert footer['links'][0]['href'] == '/offers', footer
        assert moved['data']['rewritten'] == 4, moved['data']  # about, post, footer and the moved page itself

        # --- managed menus follow, other items stay
        items = json.loads((site.root / 'content/menus.json').read_text())['menus']['header']['items']['en']
        assert [item['page'] for item in items] == ['offers', 'offers/test', 'about'], items
        assert items[1]['label'] == 'Test', items

        # --- redirect rule first in the list, older rules no longer point at the old address
        rules = json.loads((site.root / 'content/redirects.json').read_text())['redirects']
        assert rules[0]['from'] == '^(/[a-z]{2})?/services(/.+?)?/?$' and rules[0]['to'] == '$1/offers$2' and rules[0]['status'] == 301, rules[0]
        assert rules[1]['to'] == '/offers', rules[1]
        for old, new in (('/services', '/offers'), ('/services/', '/offers'), ('/services/test', '/offers/test'), ('/en/services/test/', '/en/offers/test')):
            status, headers, _ = Client(site).request(old)
            assert status == 301 and headers.get('Location', '').endswith(new), (old, status, headers.get('Location'))
        assert Client(site).request('/services-other')[0] != 301
        assert Client(site).request('/offers')[0] == 200
        assert Client(site).request('/offers/test')[0] == 200

        # --- the moved page loads and saves under its new key; the old key is gone
        loaded = admin.api('load', page='en_offers')[1]
        assert loaded['success'] and loaded['data']['title'] == 'Services', loaded
        assert admin.api('load', page='en_services')[1]['data'].get('lastModified') is None

        # --- moving below another page and back to the top level
        status, nested = admin.api('move-page', page='en_offers', slug='about/offers')
        assert status == 200 and nested['success'] and nested['data']['moved'] == 2, nested
        assert (pages / 'en_about__offers__test.json').exists()
        assert [item['page'] for item in json.loads((site.root / 'content/menus.json').read_text())['menus']['header']['items']['en']] == ['about/offers', 'about/offers/test', 'about']
        assert Client(site).request('/offers/test')[0] == 301
        assert Client(site).request('/services/test')[0] == 301, 'old rule still applies'
        rules = json.loads((site.root / 'content/redirects.json').read_text())['redirects']
        assert rules[1]['to'] == '$1/about/offers$2', 'the first move now points at the newest address'
        print('PASS page-move-smoke: guards, rename with subpages, links, menus, redirects, nested move')
    finally:
        site.close()
