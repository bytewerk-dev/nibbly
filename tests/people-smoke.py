#!/usr/bin/env python3
"""People CRUD, revision conflicts, sanitization, module flag and public block visibility. Disposable site only."""
import json
import sys
import tempfile
import time
from pathlib import Path
from support import Site, Client, write_json

with tempfile.TemporaryDirectory(prefix='nibbly-people-test-') as directory:
    site=Site(directory)
    root=site.root
    # Test renderers through the regular public page route; published profiles only.
    write_json(root/'content/pages/en_people-test.json', {'page':'en_people-test','title':'People test','sections':[
        {'id':'team','type':'team','group':'Team','columns':'3','details':'full'}]})
    site.start()
    try:
        guest=Client(site)
        assert guest.request('/admin/api.php?action=load-people')[0]==401
        admin=Client(site).login()
        editor=Client(site).login('editor')
        def load(client=admin):
            code,_,body=client.request('/admin/api.php?action=load-people');assert code==200,body
            return json.loads(body)
        initial=load();assert initial['revision']=='missing'
        profile={'name':'Ada Example','status':'draft','order':1,'groups':['Team'], 'photo':'','alt':'','credit':'','website':'https://example.org','email':'ada@example.org','phone':'+43 1 234',
                 'translations':{'en':{'role':'Engineer','organization':'Example','summary':'Short profile','biography':'<p><strong>Biography</strong><script>alert(1)</script></p>','quote':'A quote'},'de':{'role':'Entwicklerin','biography':'<h3>Profil</h3>'}}}
        assert admin.api('save-person',id='',person=json.dumps(profile))[0]==428
        assert admin.request('/admin/api.php?action=save-person')[0]==405
        assert admin.request('/admin/api.php',{'action':'save-person','person':json.dumps(profile),'revision':'missing','csrf_token':'wrong'})[0]==403
        code,res=editor.api('save-person',id='',person=json.dumps(profile),revision='missing');assert code==200,res
        p=res['data']['person'];pid=p['id'];assert p['status']=='draft' and '<script' not in p['translations']['en']['biography']
        assert p['slug']=='ada-example' and guest.request('/people/ada-example')[0]==404  # drafts have no public page
        assert 'Ada Example' not in guest.request('/people-test')[2].decode()
        assert admin.api('save-person',id=pid,person=json.dumps(profile),revision='missing')[0]==409
        profile['status']='published'
        code,res=admin.api('save-person',id=pid,person=json.dumps(profile),revision=load()['revision']);assert code==200,res
        html=guest.request('/people-test')[2].decode();assert 'Ada Example' in html and '<strong>Biography</strong>' in html and 'alert(1)' not in html
        # Per-language fallback, sanitized rich text and references instead of copied profiles.
        write_json(root/'content/pages/de_person-test.json',{'page':'de_person-test','title':'Person test','sections':[{'id':'p','type':'person','personId':pid}]})
        html=guest.request('/de/person-test')[2].decode();assert 'Entwicklerin' in html and '<h3>Profil</h3>' in html,html[-1000:]
        profile['name']='Ada Updated'
        code,res=admin.api('save-person',id=pid,person=json.dumps(profile),revision=load()['revision']);assert code==200,res
        assert 'Ada Updated' in guest.request('/people-test')[2].decode()
        assert 'Ada Updated' in guest.request('/de/person-test')[2].decode()
        # The profile's own page keeps its URL after the rename, in every language, and the cards link to it.
        code,_,html=guest.request('/people/ada-example');assert code==200 and 'Ada Updated' in html.decode() and '<strong>Biography</strong>' in html.decode()
        code,_,html=guest.request('/de/people/ada-example');assert code==200 and 'Entwicklerin' in html.decode()
        assert 'href="people/ada-example"' in guest.request('/people-test')[2].decode()
        assert b'/people/ada-example' in guest.request('/sitemap.xml')[2]
        # Bad URLs and field types reject the entire change.
        before=load()['revision']
        for key,value in [('website','javascript:alert(1)'),('photo','//evil.example/a'),('email','not-an-email'),('photo','../private/a.png'),('translations',{'en':{'biography':[]}})]:
            bad=dict(profile,**{key:value});code,res=admin.api('save-person',id=pid,person=json.dumps(bad),revision=before);assert code==422,(key,res)
            assert load()['revision']==before
        assert admin.api('trash-person',id='../../config',revision=before)[0]==422
        # A group block excludes people from other groups and sorts the remaining profiles.
        second=dict(profile,name='Other Team',groups=['Other'])
        code,res=admin.api('save-person',id='',person=json.dumps(second),revision=load()['revision']);assert code==200,res
        assert 'Other Team' not in guest.request('/people-test')[2].decode()
        code,res=admin.api('trash-person',id=pid,revision=load()['revision']);assert code==200,res
        assert 'Ada Updated' not in guest.request('/people-test')[2].decode()
        assert 'Ada Updated' not in guest.request('/de/person-test')[2].decode()
        assert guest.request('/people/ada-example')[0]==404
        code,res=admin.api('restore-person',id=pid,revision=load()['revision']);assert code==200,res
        assert next(p for p in res['data']['people'] if p['id']==pid)['status']=='draft'
        assert 'Ada Updated' not in guest.request('/people-test')[2].decode()
        assert list((root/'backups').glob('people_*.json'))
        # Permanent deletion only works from the trash; emptying the trash keeps all other profiles.
        other=next(p for p in load()['data']['people'] if p['name']=='Other Team')['id']
        assert admin.api('delete-person',id=other,revision=load()['revision'])[0]==409
        code,res=admin.api('trash-person',id=other,revision=load()['revision']);assert code==200,res
        code,res=admin.api('delete-person',id=other,revision=load()['revision']);assert code==200,res
        assert all(p['id']!=other for p in res['data']['people'])
        # An empty order field falls back to position 0.
        code,res=admin.api('save-person',id='',person=json.dumps(dict(profile,name='Empty Order',order='')),revision=load()['revision']);assert code==200,res
        assert res['data']['person']['order']==0
        code,res=admin.api('trash-person',id=res['data']['person']['id'],revision=load()['revision']);assert code==200,res
        code,res=admin.api('empty-people-trash',revision=load()['revision']);assert code==200,res
        assert [p['name'] for p in res['data']['people']]==['Ada Updated'],res
        code,res=admin.api('save-person',id=pid,person=json.dumps(profile),revision=load()['revision']);assert code==200,res
        # The dashboard area follows the People module alone, not the role or the Menus module.
        write_json(root/'content/settings.json',{'modules':{'menus':False,'ai':False}})
        for client in (admin,editor):
            assert b'id="peopleTab"' in client.request('/admin/dashboard.php')[2]
        write_json(root/'content/settings.json',{'modules':{'people':False,'ai':False}})
        assert b'id="peopleTab"' not in admin.request('/admin/dashboard.php')[2]
        assert admin.request('/admin/api.php?action=load-people')[0]==403
        assert editor.api('trash-person',id=pid,revision=res['revision'])[0]==403
        # Disabling management preserves already published website content.
        assert 'Ada Updated' in guest.request('/people-test')[2].decode()
        write_json(root/'content/settings.json',{'modules':{'people':True,'ai':False},'general':{'adminLanguage':'de'}})
        raw=(root/'content/people.json').read_text();(root/'content/people.json').write_text('{broken')
        assert admin.request('/admin/api.php?action=load-people')[0]==503
        assert (root/'content/people.json').read_text()=='{broken'
        (root/'content/people.json').write_text(raw)
        print('PASS: people CRUD, editor role, CSRF, methods, revisions, sanitization, languages, references, group filtering, trash/restore/permanent delete/empty trash, backups, module flag, dashboard area for editors and damaged storage.',flush=True)
        if '--serve' in sys.argv:
            Path('/tmp/nibbly-people-browser.json').write_text(json.dumps({'root':str(root),'port':site.port,'password':site.password,'id':pid}))
            print('Browser fixture ready on port '+str(site.port),flush=True)
            try:
                while True:time.sleep(1)
            except KeyboardInterrupt:pass
    finally:site.close()
