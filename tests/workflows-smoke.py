#!/usr/bin/env python3
"""Full local setup, multipart media lifecycle, page save and mock SMTP."""
from email import policy
from email.parser import BytesParser
import base64
import json
import re
import socketserver
import tempfile
from threading import Thread
from support import Client, Site

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

def check_form_notifications(site, mailbox, config):
    guest = Client(site)
    message = "Grüße & Wünsche\n\nTermin am <Mittwoch>?"
    capture = site.root / 'notification.eml'
    sink = site.root / 'capture-mail'
    sink.write_text('#!/bin/sh\ncat > "$(dirname "$0")/notification.eml"\n')
    sink.chmod(0o700)
    site.start(ini={'sendmail_path': str(sink)})
    settings_path = site.root / 'content/settings.json'
    settings = json.loads(settings_path.read_bytes())
    for method in ['smtp', 'sendmail']:
        settings['email'] = {**config, 'method': method}
        settings_path.write_text(json.dumps(settings))
        html = guest.request('/api/form.php?form=contact&lang=de')[2]
        token = re.search(rb'name="form_token" value="([^"]+)"', html)[1].decode()
        token_file = site.root / 'content/form-tokens.json'
        tokens = json.loads(token_file.read_bytes())
        for entry in tokens.values():
            entry['created'] -= 5
        token_file.write_text(json.dumps(tokens))
        payload = {'form_id': 'contact', 'form_token': token, 'name': 'Zoë', 'email': 'guest@example.invalid', 'message': message}
        status, _, response = guest.request('/api/form-submit.php', payload)
        assert status == 200 and json.loads(response)['success'], response
        raw = mailbox.messages[-1] if method == 'smtp' else capture.read_bytes()
        mail = BytesParser(policy=policy.default).parsebytes(raw)
        assert mail.get_content_type() == 'multipart/alternative' and not mail.defects
        assert [part.get_content_type() for part in mail.iter_parts()] == ['text/plain', 'text/html']
        assert mail['Reply-To'].addresses[0].addr_spec == payload['email']
        assert mail['Bcc'] is None and 'Zoë' in str(mail['Subject'])
        plain, rich = [part.get_content().replace('\r\n', '\n') for part in mail.iter_parts()]
        assert message in plain and '\n\n' in plain
        assert '&lt;Mittwoch&gt;' in rich and '<br>\n<br>' in rich and 'Grüße &amp; Wünsche' in rich
        assert 'mailto:guest@example.invalid' in rich and 'Fixture' in rich
        if method == 'smtp':
            assert not re.search(rb'(?<!\r)\n', raw)


with tempfile.TemporaryDirectory(prefix='nibbly-workflows-') as folder:
    site = Site(folder).start()
    mailbox = socketserver.ThreadingTCPServer(('127.0.0.1', 0), Mailbox)
    mailbox.messages = []
    worker = Thread(target=mailbox.serve_forever, daemon=True); worker.start()
    try:
        # A fresh setup must produce a real admin account and routable starter pages.
        (site.root / 'admin/config.php').unlink()
        guest = Client(site)
        body = guest.request('/admin/setup.php')[2]
        token = re.search(rb'name="csrf_token" value="([^"]+)"', body)[1].decode()
        fields = {'site_name': 'Workflow fixture', 'username': 'admin', 'email': 'admin@example.invalid',
                  'password': site.password, 'password_confirm': site.password,
                  'languages[0]': 'en', 'languages[1]': 'de', 'primary_lang': 'en'}
        guest.request('/admin/setup.php', fields)
        assert not (site.root / 'admin/config.php').exists(), 'Setup accepted a missing CSRF token'
        status, _, body = guest.request('/admin/setup.php', {**fields, 'csrf_token': token})
        assert status == 200 and (site.root / 'admin/config.php').exists(), body[:200]
        assert guest.request('/admin/setup.php')[0] == 302
        admin = Client(site).login()
        page = admin.api('load', page='en_home')[1]
        content = page['data']; content['title'] = 'Saved through HTTP'
        status, result = admin.api('save', page='en_home', revision=page['revision'], content=json.dumps(content), render_sections='1')
        assert status == 200 and result['success'] and 'sectionsHtml' in result['data'], result
        assert guest.request('/')[0] == 200 and guest.request('/de/')[0] == 200
        # A real multipart upload goes through MIME validation and the complete trash lifecycle.
        png = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aVwAAAABJRU5ErkJggg==')
        boundary = 'nibbly-fixture-boundary'
        parts = []
        for key, value in {'action': 'upload-media', 'csrf_token': admin.csrf, 'type': 'image'}.items():
            parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="pixel.png"\r\nContent-Type: image/png\r\n\r\n'.encode() + png + b'\r\n')
        parts.append(f'--{boundary}--\r\n'.encode())
        status, _, body = admin.request('/admin/api.php', b''.join(parts), headers={'Content-Type': 'multipart/form-data; boundary=' + boundary})
        uploaded = json.loads(body)
        assert status == 200 and uploaded['success'], uploaded
        name = uploaded['data']['name']
        assert (site.root / 'assets/images' / name).read_bytes() == png
        assert admin.api('delete-media', type='image', filename=name)[1]['success']
        trash = list((site.root / 'assets/images-trash').glob('*.png'))
        assert len(trash) == 1
        assert admin.api('restore-media', type='image', filename=trash[0].name)[1]['success']
        assert (site.root / 'assets/images' / name).is_file()
        config = {'method': 'smtp', 'recipientEmail': 'to@example.invalid', 'fromEmail': 'from@example.invalid', 'fromName': 'Fixture',
                  'smtpHost': '127.0.0.1', 'smtpPort': mailbox.server_address[1], 'smtpUsername': 'fixture', 'smtpPassword': 'fixture', 'smtpEncryption': 'none'}
        status, result = admin.api('test-email', emailConfig=json.dumps(config))
        assert result['success'] and len(mailbox.messages) == 1, result
        assert b'to@example.invalid' in mailbox.messages[0]
        status, response = admin.api('system-status')
        assert status == 200 and response['data']['extensions'] and response['data']['lastBackup'] is None
        check_form_notifications(site, mailbox, config)
        print('PASS form notifications over SMTP and sendmail as HTML and plain text')
        print('PASS setup with CSRF, generated login, page edit/render, multipart upload/trash/restore, loopback SMTP and system status')
    finally:
        site.close(); mailbox.shutdown(); mailbox.server_close(); worker.join()
