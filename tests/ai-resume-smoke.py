#!/usr/bin/env python3
"""A Kie task survives a failed poll and resumes in a new PHP process without re-submission."""
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from threading import Thread
import json
import subprocess
import tempfile
from support import copy_core, write_json

class Provider(BaseHTTPRequestHandler):
    def log_message(self, *_): pass
    def reply(self, status, body, kind='application/json'):
        if isinstance(body, dict): body = json.dumps(body).encode()
        self.send_response(status); self.send_header('Content-Type', kind); self.send_header('Content-Length', str(len(body))); self.end_headers(); self.wfile.write(body)
    def do_POST(self):
        self.rfile.read(int(self.headers['Content-Length']))
        self.server.submissions += 1
        self.reply(200, {'data': {'taskId': 'persistent-task'}})
    def do_GET(self):
        if self.path == '/image.png': self.reply(200, self.server.png, 'image/png'); return
        self.server.polls += 1
        if self.server.polls == 1: self.reply(503, {'message': 'Temporary poll outage'}); return
        self.reply(200, {'data': {'state': 'success', 'resultJson': json.dumps({'resultUrls': [self.server.url + '/image.png']})}})

with tempfile.TemporaryDirectory(prefix='nibbly-ai-resume-') as folder:
    root = copy_core(folder)
    server = ThreadingHTTPServer(('127.0.0.1', 0), Provider)
    server.url = f'http://127.0.0.1:{server.server_port}'
    server.submissions = server.polls = 0
    server.png = subprocess.check_output(['php', '-r', '$im=imagecreatetruecolor(16,16);imagepng($im);'])
    thread = Thread(target=server.serve_forever, daemon=True); thread.start()
    try:
        write_json(root / 'content/ai-settings.json', {'enabled': True, 'provider': 'kie', 'baseUrl': server.url, 'allowLocalProvider': True, 'apiKey': 'fixture', 'features': {'imageGeneration': True}})
        prefix = "require 'includes/ai/ai-helper.php'; "
        def php(code): return subprocess.check_output(['php', '-r', prefix + code], cwd=root, text=True)
        options = "['_requestId'=>'image_job_0123456789abcdef','model'=>'gpt-image-2','size'=>'auto','count'=>1]"
        first = php("try { nibblyAiGenerateImage('Fixture',"+options+"); } catch(Throwable $e) { echo $e->getMessage(); }")
        assert 'outage' in first and server.submissions == 1
        usage = json.loads((root / 'content/ai-usage.json').read_text())
        entry = usage['reservations']['image_job_0123456789abcdef']
        assert entry['status'] == 'uncertain' and entry['tasks'][0]['id'] == 'persistent-task'
        php("nibblyAiSaveImageJob(['id'=>'job_0123456789abcdef','status'=>'running','startedAt'=>gmdate('c',time()-2000)]);")
        state = json.loads(php("echo json_encode(nibblyAiRefreshImageJobState(nibblyAiLoadImageJob('job_0123456789abcdef')));"))
        assert state['status'] == 'queued', state
        result = json.loads(php("echo json_encode(nibblyAiGenerateImage('Fixture',"+options+"));"))
        assert server.submissions == 1 and server.polls == 2 and (root / result['path'].lstrip('/')).is_file()
        # Re-entering after a successful provider response reuses saved local outputs.
        again = json.loads(php("echo json_encode(nibblyAiGenerateImage('Fixture',"+options+"));"))
        assert again['path'] == result['path'] and server.submissions == 1
        usage = json.loads((root / 'content/ai-usage.json').read_text())
        assert next(iter(usage['days'].values()))['requests'] == 1
        print('PASS persistent Kie task: failed polling, fresh-process resume, stale worker recovery, one provider submission and one usage settlement')
    finally:
        server.shutdown(); server.server_close(); thread.join()
