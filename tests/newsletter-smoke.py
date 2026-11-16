#!/usr/bin/env python3
"""Newsletter module: sign-up with double opt-in, import, campaign, queue, tracking, unsubscribe, cron, rights."""
from email import policy
from email.parser import BytesParser
import hashlib
import html
import json
import re
import socketserver
import tempfile
import time
from threading import Thread
from urllib.parse import urlsplit
from support import Client, Site, write_json


class Mailbox(socketserver.StreamRequestHandler):
    def handle(self):
        def send(line): self.wfile.write(line.encode() + b'\r\n')
        send('220 fixture ESMTP')
        auth = 0
        while True:
            line = self.rfile.readline().strip()
            if not line: break
            if auth == 1: auth = 2; send('334 UGFzc3dvcmQ6'); continue
            if auth == 2: auth = 0; send('235 Authenticated'); continue
            if line.startswith(b'EHLO'): send('250 fixture')
            elif line == b'AUTH LOGIN': auth = 1; send('334 VXNlcm5hbWU6')
            elif line.startswith(b'RCPT') and b'reject@' in line: send('550 5.1.1 No such user')
            elif line.startswith(b'MAIL') or line.startswith(b'RCPT'): send('250 OK')
            elif line == b'DATA':
                send('354 Continue')
                lines = []
                while True:
                    part = self.rfile.readline()
                    if part == b'.\r\n' or not part: break
                    lines.append(part)
                self.server.messages.append(b''.join(lines)); send('250 Queued')
            elif line == b'QUIT': send('221 Bye'); break
            else: send('250 OK')


def parse(raw):
    message = BytesParser(policy=policy.default).parsebytes(raw)
    return message, message.get_body(('html',)).get_content(), message.get_body(('plain',)).get_content()


def path_of(url):
    parts = urlsplit(html.unescape(url))
    return parts.path + '?' + parts.query


def form_token(site):
    """A sign-up token that is old enough (the form protection wants at least four seconds)."""
    token = 'fixture-' + str(time.time_ns())
    path = site.root / 'content/form-tokens.json'
    tokens = json.loads(path.read_text()) if path.exists() else {}
    tokens[hashlib.sha256(token.encode()).hexdigest()] = {'form': 'newsletter', 'created': int(time.time()) - 10}
    write_json(path, tokens)
    return token


with tempfile.TemporaryDirectory(prefix='nibbly-newsletter-') as folder:
    mailbox = socketserver.ThreadingTCPServer(('127.0.0.1', 0), Mailbox)
    mailbox.messages = []
    Thread(target=mailbox.serve_forever, daemon=True).start()
    site = Site(folder)
    settings_path = site.root / 'content/settings.json'
    mail = {'method': 'smtp', 'recipientEmail': 'to@example.invalid', 'fromEmail': 'news@example.invalid', 'fromName': 'Fixture',
            'smtpHost': '127.0.0.1', 'smtpPort': mailbox.server_address[1], 'smtpUsername': 'fixture', 'smtpPassword': 'fixture', 'smtpEncryption': 'none'}
    write_json(settings_path, {'modules': {'ai': False}, 'privacy': {'analyticsEnabled': False}, 'email': mail})
    site.start()
    try:
        admin = Client(site).login()
        guest = Client(site)

        # Switched off by default: no tab, no API, no public endpoint.
        assert b'id="newsletterTab"' not in admin.request('/admin/dashboard.php')[2]
        assert admin.request('/admin/api.php?action=load-newsletter')[0] == 403
        assert guest.request('/api/newsletter.php?a=confirm&s=x&t=y')[0] == 404
        settings = json.loads(settings_path.read_text())
        settings['modules']['newsletter'] = True
        write_json(settings_path, settings)
        assert b'id="newsletterTab"' in admin.request('/admin/dashboard.php')[2]

        def load():
            status, _, body = admin.request('/admin/api.php?action=load-newsletter')
            assert status == 200, body
            return json.loads(body)['data']
        data = load()
        assert data['ready'] and data['sender'] == 'news@example.invalid' and data['counts']['subscribed'] == 0, data
        assert data['settings']['doubleOptIn'] and not data['settings']['trackOpens'] and 'gmailPassword' not in data['settings']

        status, result = admin.api('newsletter-save-list', id='', name='Monthly', description='')
        assert status == 200 and result['data']['lists'][0]['name'] == 'Monthly', result
        list_id = result['data']['lists'][0]['id']

        # Public sign-up: pending until the link in the mail is opened.
        def subscribe(email, **extra):
            fields = {'email': email, 'consent': '1', 'lang': 'en', 'lists': list_id, 'form_token': form_token(site), 'firstName': 'Ada', **extra}
            status, _, body = guest.request('/api/newsletter.php?a=subscribe', fields, headers={'Accept': 'application/json'})
            return status, json.loads(body)
        status, answer = subscribe('Ada@Example.invalid')
        assert status == 200 and answer['success'] and 'email' in answer['message'].lower(), answer
        assert len(mailbox.messages) == 1
        message, body_html, body_text = parse(mailbox.messages[-1])
        assert 'ada@example.invalid' in str(message['To']) and 'confirm' in message['Subject'].lower() and message['List-Unsubscribe'] is None, (message['To'], message['Subject'])
        confirm = re.search(r'href="([^"]+a=confirm[^"]+)"', body_html)[1]
        assert html.unescape(confirm) in body_text
        assert load()['counts'] == {'subscribed': 0, 'pending': 1, 'unsubscribed': 0, 'bounced': 0}
        assert guest.request(path_of(confirm).replace('t=', 't=0'))[0] == 404, 'a wrong token must not confirm'
        assert guest.request(path_of(confirm))[0] == 200
        assert load()['counts']['subscribed'] == 1
        # The same address again: same answer, no second mail, nothing to probe.
        status, again = subscribe('ada@example.invalid')
        assert status == 200 and again['message'] == answer['message'] and len(mailbox.messages) == 1
        status, bad = guest.request('/api/newsletter.php?a=subscribe', {'email': 'x@example.invalid', 'consent': '1', 'form_token': 'nope'}, headers={'Accept': 'application/json'})[0::2]
        assert status == 400 and not json.loads(bad)['success']
        status, bot = subscribe('bot@example.invalid', website='http://spam.example')
        assert status == 200 and load()['counts']['pending'] == 0, 'the honeypot must not store anything'

        # Import: header row, one invalid row, an existing address is skipped.
        csv = 'E-Mail;Vorname;Nachname\nbob@example.invalid;Bob;Builder\nnot-an-address;X;Y\nreject@example.invalid;Rita;Reject\nada@example.invalid;Other;Name\n'
        status, result = admin.api('newsletter-import', csv=csv, existing='skip', lists=json.dumps([list_id]))
        assert status == 200 and result['data']['result'] == {'added': 2, 'updated': 0, 'skipped': 1, 'invalid': 1}, result
        status, _, body = admin.request('/admin/api.php?action=load-newsletter-subscribers')
        people = {p['email']: p for p in json.loads(body)['data']['subscribers']}
        assert people['bob@example.invalid']['firstName'] == 'Bob' and people['ada@example.invalid']['firstName'] == 'Ada' and 'token' not in people['bob@example.invalid']
        status, _, body = admin.request('/admin/api.php?action=get-newsletter-export&status=subscribed')
        assert status == 200 and b'bob@example.invalid' in body and body.startswith(b'\xef\xbb\xbf')

        # Settings: admins only; tracking on for the campaign below.
        editor = Client(site).login('editor')
        assert editor.api('newsletter-save-settings', settings=json.dumps({'trackOpens': False}))[0] == 403
        assert 'settings' not in json.loads(editor.request('/admin/api.php?action=load-newsletter')[2])['data']
        status, result = admin.api('newsletter-save-settings', settings=json.dumps({'trackOpens': True, 'trackClicks': True, 'footerText': 'Fixture Ltd\n1 Test Street', 'batchSize': 2, 'accentColor': 'red'}))
        assert status == 200 and result['data']['settings']['trackClicks'] and result['data']['settings']['accentColor'] == '#3858e9', result

        # Campaign: content is sanitised, placeholders resolve, the preview carries no tracking.
        campaign = {'subject': 'Hello {firstname|friend}', 'preheader': 'Short teaser', 'lists': [list_id], 'blocks': [
            {'type': 'heading', 'text': 'News <b>today</b>', 'level': 2},
            {'type': 'text', 'html': '<p>Dear {firstname|reader}, <a href="https://example.com/offer?a=1&amp;b=2">offer</a><script>alert(1)</script></p>'},
            {'type': 'button', 'label': 'Shop', 'url': 'javascript:alert(1)'},
            {'type': 'button', 'label': 'Visit', 'url': '/en/services'},
            {'type': 'unknown', 'x': 1}]}
        status, result = editor.api('newsletter-save-campaign', id='', campaign=json.dumps(campaign))
        assert status == 200, result
        saved = result['data']['campaign']
        cid = saved['id']
        assert [b['type'] for b in saved['blocks']] == ['heading', 'text', 'button', 'button'] and saved['blocks'][2]['url'] == '' and 'script' not in saved['blocks'][1]['html']
        assert result['data']['recipientCount'] == 3
        status, result = editor.api('newsletter-preview', campaign=json.dumps(campaign))
        preview = result['data']['html']
        assert status == 200 and 'News &lt;b&gt;today' in preview and 'Dear Alex' in preview and 'a=click' not in preview and 'a=open' not in preview and 'Fixture Ltd' in preview
        assert result['data']['subject'] == 'Hello Alex' and f'http://127.0.0.1:{site.port}/en/services' in preview
        before = len(mailbox.messages)
        status, result = editor.api('newsletter-test', id=cid, email='tester@example.invalid')
        assert status == 200 and len(mailbox.messages) == before + 1 and parse(mailbox.messages[-1])[0]['Subject'].startswith('[Test]')

        # Sending in batches; a refused recipient becomes undeliverable, the others arrive once.
        before = len(mailbox.messages)
        status, result = editor.api('newsletter-start', id=cid, sendAt='')
        assert status == 200 and result['data']['campaign']['status'] == 'sending' and result['data']['campaign']['stats']['total'] == 3, result
        assert editor.api('newsletter-save-campaign', id=cid, campaign=json.dumps(campaign))[0] == 422, 'a running campaign must not change'
        status, result = editor.api('newsletter-run')
        assert status == 200 and result['data']['run']['sent'] == 2 and result['data']['run']['remaining'] in (0, 1), result
        status, result = editor.api('newsletter-run')
        status, result = editor.api('newsletter-run')
        status, _, body = admin.request('/admin/api.php?action=load-newsletter-campaign&id=' + cid)
        detail = json.loads(body)['data']
        assert detail['campaign']['status'] == 'sent' and detail['campaign']['stats']['sent'] == 2 and detail['campaign']['stats']['failed'] == 1, detail['campaign']
        assert len(mailbox.messages) == before + 2
        assert load()['counts']['bounced'] == 1
        mails = {str(parse(raw)[0]['To']).strip('<>'): parse(raw) for raw in mailbox.messages[before:]}
        message, body_html, body_text = mails['ada@example.invalid']
        assert message['Subject'] == 'Hello Ada' and message['List-Unsubscribe-Post'] == 'List-Unsubscribe=One-Click' and 'a=unsubscribe' in message['List-Unsubscribe']
        assert 'Dear Ada' in body_html and 'Dear Ada' in body_text and 'Short teaser' in body_html
        assert mails['bob@example.invalid'][0]['Subject'] == 'Hello Bob'

        # Tracking: signed links only.
        pixel = path_of(re.search(r'src="([^"]+a=open[^"]+)"', body_html)[1])
        click = path_of(re.search(r'href="([^"]+a=click[^"]+)"', body_html)[1])
        status, headers, body = guest.request(pixel)
        assert status == 200 and headers['Content-Type'] == 'image/gif'
        guest.request(pixel)
        status, headers, _ = guest.request(click)
        assert status == 302 and headers['Location'] == 'https://example.com/offer?a=1&b=2', headers
        forged = re.sub(r'u=[^&]+', 'u=aHR0cHM6Ly9ldmlsLmV4YW1wbGUv', click)
        assert guest.request(forged)[0] == 404, 'a changed target must not redirect'
        stats = json.loads(admin.request('/admin/api.php?action=load-newsletter-campaign&id=' + cid)[2])['data']
        assert stats['campaign']['stats']['opens'] == 1 and stats['campaign']['stats']['clicks'] == 1 and stats['report']['links'][0]['count'] == 1, stats['campaign']['stats']

        # Browser version and unsubscribe: a GET never unsubscribes.
        web = path_of(re.search(r'href="([^"]+a=view[^"]+)"', body_html)[1])
        status, _, body = guest.request(web)
        assert status == 200 and b'News &lt;b&gt;today' in body and b'ada@example.invalid' not in body and b'a=unsubscribe' not in body
        assert guest.request(web[:-1] + ('0' if web[-1] != '0' else '1'))[0] == 404
        unsubscribe = path_of(re.search(r'<(.+)>', message['List-Unsubscribe'])[1])
        status, _, body = guest.request(unsubscribe)
        assert status == 200 and b'<form method="post">' in body and load()['counts']['subscribed'] == 2
        status, _, body = guest.request(unsubscribe, 'List-Unsubscribe=One-Click', headers={'Content-Type': 'application/x-www-form-urlencoded'})
        assert status == 200
        data = load()
        assert data['counts']['unsubscribed'] == 1 and data['campaigns'][0]['stats']['unsubs'] == 1, data

        # Mail log: every outgoing mail with its result; clearing is for admins.
        entries = json.loads(editor.request('/admin/api.php?action=load-newsletter-log')[2])['data']['entries']
        kinds = [(e['type'], e['to'], bool(e['error'])) for e in entries]
        assert ('confirm', 'ada@example.invalid', False) in kinds and ('test', 'tester@example.invalid', False) in kinds, kinds
        assert ('campaign', 'bob@example.invalid', False) in kinds and ('campaign', 'reject@example.invalid', True) in kinds, kinds
        assert entries[0]['at'] >= entries[-1]['at'] and all('token' not in json.dumps(e) for e in entries)
        assert guest.request('/content/newsletter/mail-log.jsonl')[0] in (403, 404)
        assert editor.api('newsletter-clear-log')[0] == 403
        assert admin.api('newsletter-clear-log')[0] == 200
        assert json.loads(editor.request('/admin/api.php?action=load-newsletter-log')[2])['data']['entries'] == []

        # Cron endpoint and CLI.
        assert guest.request('/api/newsletter.php?a=cron&token=wrong')[0] == 403
        status, _, body = guest.request(path_of(data['cronUrl']))
        assert status == 200 and json.loads(body)['success'], body
        # A scheduled campaign waits for its time.
        status, result = editor.api('newsletter-duplicate-campaign', id=cid)
        copy = result['data']['id']
        status, result = editor.api('newsletter-start', id=copy, sendAt='2099-01-01T10:00:00Z')
        assert status == 200 and result['data']['campaign']['status'] == 'scheduled', result
        before = len(mailbox.messages)
        editor.api('newsletter-run')
        assert len(mailbox.messages) == before
        assert editor.api('newsletter-unschedule', id=copy)[1]['data']['campaign']['status'] == 'draft'
        assert editor.api('newsletter-delete-campaign', id=copy)[0] == 200

        # Without CSRF nothing is written; switching the module off closes everything again.
        assert admin.request('/admin/api.php', {'action': 'newsletter-delete-campaign', 'id': cid})[0] == 403
        assert not list((site.root / 'content/newsletter').glob('*.tmp'))
        assert guest.request('/content/newsletter/subscribers.json')[0] in (403, 404)
        settings = json.loads(settings_path.read_text())
        settings['modules']['newsletter'] = False
        write_json(settings_path, settings)
        assert admin.request('/admin/api.php?action=load-newsletter')[0] == 403 and guest.request(pixel)[0] == 404
        print('PASS newsletter: double opt-in, import/export, sanitised campaign, batches, bounce, mail log, signed tracking, unsubscribe, cron, rights, module switch')
    finally:
        site.close()
        mailbox.shutdown()
