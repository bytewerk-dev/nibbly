<?php if (!defined('NIBBLY_DASHBOARD')) { http_response_code(404); exit; } ?>
window.NbPeople = (() => {
    let people = [], languages = [], revision = '', current = null, language = '', dirty = false, busy = false;
    let status = 'all', search = '', group = '';
    const tr = key => t('people.' + key);
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const mount = () => document.getElementById('peopleMount');
    const leave = () => !busy && (!dirty || confirm(tr('unsaved')));
    const localFields = ['role','organization','summary','biography','quote'];
    function remember() {
        if (!current || !document.getElementById('personForm')) return;
        for (const key of ['name','photo','alt','credit','website','email','phone','order']) current[key] = document.getElementById('person-'+key).value;
        current.groups = document.getElementById('person-groups').value.split(',').map(s=>s.trim()).filter(Boolean);
        current.translations[language] = Object.fromEntries(localFields.map(key=>[key,key === 'biography' ? (document.querySelector('#personBioField textarea.section-field')?.value || '') : document.getElementById('person-'+key).value]));
    }
    function message(text) { const el=document.getElementById('peopleMessage'); if(el) el.textContent=text; }
    async function request(action, fields) {
        busy=true;
        mount().querySelectorAll('button,input,select,textarea').forEach(b=>b.disabled=true);
        const biography=document.querySelector('#personBioField .nb-page-rich-editor');if(biography)biography.contentEditable='false';
        try {
            const body = fields ? new URLSearchParams({action,csrf_token:CSRF_TOKEN,revision,...fields}) : null;
            const response = await fetch(fields ? 'api.php' : 'api.php?action='+action,{method:fields?'POST':'GET',body});
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.message || tr('error'));
            if (result.revision) revision=result.revision;
            return result.data;
        } finally {busy=false; mount().querySelectorAll('button,input,select,textarea').forEach(b=>b.disabled=false);if(biography)biography.contentEditable='true';}
    }
    function updateOptions() {
        if (!window.BlockTypeRegistry?.person) return;
        window.BlockTypeRegistry.person.fields[0].options = [{value:'',label:tr('choose')},...people.filter(p=>p.status!=='trash').map(p=>({value:p.id,label:p.name+(p.status==='draft'?' ('+tr('draft')+')':'')}))];
    }
    async function open() {
        if (!leave()) return;
        current=null;dirty=false;
        mount().innerHTML='<p role="status" id="peopleMessage">'+esc(tr('loading'))+'</p>';
        try { const data=await request('load-people'); people=data.people;languages=data.languages;language=language||(languages.includes(DEFAULT_LANG)?DEFAULT_LANG:languages[0]); updateOptions(); list(); }
        catch(error) {message(error.message);}
    }
    // Language names for the content-language select (falls back to the code)
    const langName = code => (typeof SITE_LANGUAGES !== 'undefined' && SITE_LANGUAGES[code]) || code;
    function list() {
        current=null;dirty=false;
        // Filters as WordPress does it: text links with counts above the table, shown only when they have entries
        const live=people.filter(p=>p.status!=='trash');
        const counts={all:live.length,published:live.filter(p=>p.status==='published').length,draft:live.filter(p=>p.status==='draft').length,trash:people.length-live.length};
        if(status!=='all'&&!counts[status])status='all';
        const groups=[...new Set(live.flatMap(p=>p.groups||[]))].sort();
        if(group&&!groups.includes(group))group='';
        mount().innerHTML=`<div class="page-list-container people-list">
        <div class="page-list-header">
            <div class="page-list-header-left"><div class="page-list-title-stack"><h2>${esc(tr('title'))}</h2><p>${esc(tr('intro'))}</p></div></div>
            <div class="page-list-header-actions">
                <button type="button" class="btn btn-primary btn-sm" data-people="new">${esc(tr('new_person'))}</button>
            </div>
        </div>
        <div class="nb-filter-bar">
            <div class="nb-filter-bar__links" id="peopleFilterLinks"></div>
            <div class="nb-filter-bar__actions">
                ${status==='trash'?`<button type="button" class="btn btn-danger btn-sm" data-people="empty-trash">${esc(t('trash.empty_trash'))}</button>`:''}
                <label class="page-list-search" for="peopleSearch"><span class="sr-only">${esc(tr('search'))}</span>
                    <input type="search" id="peopleSearch" class="page-list-search-input" placeholder="${esc(tr('search'))}…" value="${esc(search)}" autocomplete="off"></label>
            </div>
        </div>
        <p id="peopleMessage" role="status" class="people-message"></p>
        <div class="page-list-table-wrap"><table class="page-list-table people-table"><thead><tr><th class="page-list-col-title">${esc(tr('name'))}</th><th>${esc(tr('role'))} / ${esc(tr('organization'))}</th><th>${esc(tr('groups'))}</th><th>${esc(tr('status'))}</th></tr></thead><tbody id="peopleRows"></tbody></table></div>
        </div>`;
        document.getElementById('peopleSearch').oninput=e=>{search=e.target.value;rows();};
        renderFilterLinks(document.getElementById('peopleFilterLinks'),[
            {items:['all','published','draft','trash'].map(s=>({value:s,label:s==='trash'?t('pages.trash'):tr(s),count:counts[s],active:status===s,onSelect(){status=s;list();}}))},
            status!=='trash'&&languages.length>1&&{label:t('filter.language'),icon:'language',items:languages.map(l=>({value:l,label:langName(l),count:live.filter(p=>translated(p,l)).length,active:language===l,onSelect(){language=l;list();}}))},
            status!=='trash'&&groups.length&&{label:tr('groups'),icon:'users',items:[{value:'',label:tr('all'),count:live.length,active:!group,onSelect(){group='';list();}},
                ...groups.map(g=>({value:g,label:g,count:live.filter(p=>(p.groups||[]).includes(g)).length,active:group===g,onSelect(){group=g;list();}}))]}
        ]);
        rows();
    }
    // Portrait thumbnail: the profile photo, else the initials
    function thumb(p) {
        const photo=(p.photo||'').trim();
        if (photo && !/^(javascript|data|vbscript):/i.test(photo)) {
            const src=/^(https?:|\/)/i.test(photo)?(photo.startsWith('/')?'..'+photo:photo):'../'+photo;
            return `<img class="people-thumb" src="${esc(src)}" alt="" loading="lazy">`;
        }
        const initials=(p.name||'?').split(/\s+/).map(w=>w[0]||'').join('').slice(0,2).toUpperCase();
        return `<span class="people-thumb people-thumb--initials" aria-hidden="true">${esc(initials)}</span>`;
    }
    function rowActions(p) {
        const link=(action,iconName,label,danger)=>`<a href="#" class="page-list-row-action${danger?' page-list-row-action--danger':''}" data-people="${action}" data-id="${p.id}">${icon(iconName,12,'2')} ${esc(label)}</a>`;
        const sep='<span class="page-list-row-action-sep">|</span>';
        return '<div class="page-list-row-actions">'+(p.status==='trash'
            ? link('restore','back',t('btn.restore'))+sep+link('delete','trash',tr('delete_permanent'),true)
            : link('edit','edit',t('pages.edit'))+(p.status==='published'?sep+`<a href="../people/${esc(p.slug)}" target="_blank" rel="noopener" class="page-list-row-action">${icon('eye',12,'2')} ${esc(t('pages.view'))}</a>`:'')+sep+link('trash','trash',t('pages.trash'),true))+'</div>';
    }
    function text(p,key) {return p.translations?.[language]?.[key] || p.translations?.[DEFAULT_LANG]?.[key] || '';}
    // A profile counts as translated into a language once one of its texts is filled in there
    const translated=(p,l)=>localFields.some(key=>String(p.translations?.[l]?.[key]||'').trim()!=='');
    function rows() {
        const filtered=people.filter(p=>(status==='all'?p.status!=='trash':p.status===status)&&(status==='trash'||!group||(p.groups||[]).includes(group))&&[p.name,...Object.values(p.translations||{}).flatMap(v=>[v.role,v.organization]),...(p.groups||[])].join(' ').toLocaleLowerCase().includes(search.toLocaleLowerCase()));
        filtered.sort((a,b)=>(Number(a.order)-Number(b.order))||a.name.localeCompare(b.name));
        document.getElementById('peopleRows').innerHTML=filtered.map(p=>`<tr class="page-list-row"><td class="page-list-col-title"><div class="people-name-cell">${thumb(p)}<div><a href="#" class="page-list-title-link" data-people="${p.status==='trash'?'restore':'edit'}" data-id="${p.id}">${esc(p.name)}</a>${translated(p,language)?'':`<span class="nb-untranslated">${esc(t('filter.not_translated'))}</span>`}${rowActions(p)}</div></div></td><td>${esc([text(p,'role'),text(p,'organization')].filter(Boolean).join(' · '))}</td><td>${esc((p.groups||[]).join(', '))}</td><td><span class="people-status people-status--${esc(p.status)}">${esc(tr(p.status))}</span></td></tr>`).join('')||`<tr><td colspan="4">${esc(tr('empty'))}</td></tr>`;
    }
    function field(key, type='text', localized=false) {
        const value=localized ? current.translations[language]?.[key]||'' : (key==='groups'?(current.groups||[]).join(', '):current[key]??'');
        return `<div class="form-group"><label for="person-${key}">${esc(tr(key))}</label>${type==='textarea'?`<textarea id="person-${key}" rows="3">${esc(value)}</textarea>`:`<input id="person-${key}" type="${type}" value="${esc(value)}" ${key==='name'?'required maxlength="200"':''} ${key==='order'?'min="-10000" max="10000"':''}>`}</div>`;
    }
    function edit(id) {
        if(!leave())return;
        current=structuredClone(people.find(p=>p.id===id)||{id:'',name:'',status:'draft',order:0,groups:[],translations:{}});dirty=false;
        form();
    }
    function form() {
        // Writing view like pages, news and events: back link, title-sized name, main column
        // without boxes (sections divided by lines), save and photo in the sidebar
        mount().innerHTML=`<div class="nb-writing-view nb-people-writing">
        <div class="editor-back"><a href="#" class="editor-back-link" data-people="back">${icon('back',16)} ${esc(t('people.all_people'))}</a></div>
        <div class="editor-header"><div class="editor-header-left"><h2>${esc(current.id?(current.name||tr('edit')):tr('add'))}</h2></div></div>
        <p id="peopleMessage" role="status" class="people-message"></p>
        <form id="personForm" class="nb-writing-layout people-editor">
          <div class="nb-news-main people-editor-main">
            <div class="nb-page-title-field"><label for="person-name" class="ce-field-label">${esc(tr('name'))}</label><input id="person-name" class="editor-input" type="text" value="${esc(current.name||'')}" required maxlength="200" autocomplete="off">${languages.length>1?`<small class="form-hint">${esc(tr('language_hint'))}</small>`:''}</div>
            <section class="nb-writing-section">
              <div class="people-field-grid">${field('role','text',true)}${field('organization','text',true)}</div>
              <div class="html-editor" id="personBioField"><label for="person-biography-source">${esc(tr('biography'))}</label><textarea id="person-biography-source" class="editor-textarea section-field" data-key="biography" rows="12"></textarea></div>
            </section>
            <details class="nb-writing-section people-more" open><summary>${esc(tr('more'))}</summary>${field('summary','textarea',true)}${field('quote','textarea',true)}${field('groups')}<p class="form-hint">${esc(tr('groups_hint'))}</p>${field('order','number')}<div class="people-field-grid">${field('website')}${field('email','email')}${field('phone','tel')}</div><p class="form-hint">${esc(tr('public_contacts'))}</p></details>
          </div>
          <aside class="nb-page-sidebar" aria-label="${esc(tr('save'))}">
            <section class="nb-page-save"><h3>${esc(tr('save'))}</h3>
              <p class="people-status-line">${esc(tr('status'))}: <span class="people-status people-status--${esc(current.status||'draft')}">${esc(tr(current.status||'draft'))}</span></p>
              <div class="editor-header-right">
                <button type="submit" class="btn btn-primary btn-sm" name="publish">${esc(tr('publish'))}</button>
                <button type="button" class="btn btn-secondary btn-sm" data-people="draft">${esc(tr('save_draft'))}</button>
                ${current.id?`<button type="button" class="btn btn-secondary btn-sm editor-trash-btn" data-people="trash" data-id="${current.id}">${icon('trash',14)} <span>${esc(t('editor.move_to_trash'))}</span></button>`:''}
              </div>
              <p class="form-hint">${esc(tr('reuse_hint'))}</p></section>
            <section class="nb-page-panel"><h3>${esc(tr('photo'))}</h3><div class="ce-image-preview"><img id="personPhotoPreview" alt=""></div><button type="button" class="btn btn-secondary btn-sm" data-people="photo">${esc(tr('choose_photo'))}</button>${field('photo')}${field('alt')}${field('credit')}</section>
          </aside>
        </form></div>`;
        // Biography: the shared Visual / HTML editor (scripts/page-writing.php); the server sanitises
        document.getElementById('person-biography-source').value=current.translations[language]?.biography||'';
        window.NbPageEditor?.richEditor(document.getElementById('personBioField'),'person',{markDirty(){dirty=true;message(tr('unsaved_label'));},pushUndoSnapshot(){}});
        // Language choice top right in the header, as in every editor; name, photo, groups and contacts are shared
        placeLanguageSwitch(mount().querySelector('.nb-people-writing'),languages.length>1&&renderLanguageSwitch({name:'person',current:language,languages:languages.map(l=>({code:l,label:langName(l),exists:translated(current,l)})),
            onSelect(code){remember();language=code;form();},
            onTranslate(button){translatePerson(button);}}));
        document.getElementById('personForm').onsubmit=e=>{e.preventDefault();save('published');};
        document.getElementById('personForm').oninput=()=>{dirty=true;message(tr('unsaved_label'));photoPreview();};
        photoPreview();
    }
    // AI translation of the profile's texts from the chosen language into the others (applied, not saved)
    async function translatePerson(button) {
        remember();
        const source=current.translations[language]||{};
        const fields=Object.fromEntries(localFields.filter(key=>String(source[key]||'').trim()!=='').map(key=>[key,source[key]]));
        const targets=languages.filter(l=>l!==language);
        const translations=await aiTranslateFields(language,targets,fields,button,3000);
        if(!translations)return;
        targets.forEach(l=>{current.translations[l]={...(current.translations[l]||{}),...Object.fromEntries(Object.entries(translations[l]||{}).filter(([key])=>localFields.includes(key)))};});
        dirty=true;form();message(t('editor.translate_done'));
    }
    function photoPreview() {
        const value=document.getElementById('person-photo')?.value||'';
        const image=document.getElementById('personPhotoPreview');if(!image)return;
        image.hidden=!value;
        if(value && !/^(javascript|data|vbscript):/i.test(value.trim())) image.src=/^(https?:|\/)/i.test(value)?value:'../'+value;
    }
    async function save(nextStatus) {
        const formEl=document.getElementById('personForm'); if(!formEl.reportValidity())return;
        remember();const pending={...current,status:nextStatus};
        try {const data=await request('save-person',{id:current.id,person:JSON.stringify(pending)});people=data.people;current=data.person;dirty=false;updateOptions();form();message(tr('saved'));}
        catch(error){message(error.message);}
    }
    mount().addEventListener('click', async event=>{
        const button=event.target.closest('[data-people]');if(!button||busy)return;
        if(button.tagName==='A')event.preventDefault();
        const action=button.dataset.people,id=button.dataset.id;
        if(action==='new'||action==='edit')return edit(id);
        if(action==='back'){if(leave())list();return;}
        if(action==='draft')return save('draft');
        if(action==='photo')return window.NbImageManager.open(path=>{document.getElementById('person-photo').value=path;dirty=true;photoPreview();},document.getElementById('person-photo').value,{types:['image']});
        if(action==='delete'||action==='empty-trash'){
            const person=people.find(p=>p.id===id);
            const [title,text]=action==='delete'?[t('modal.delete_permanently'),tr('confirm_delete_permanent').replace('{name}',person?.name||'')]:[t('modal.empty_trash'),t('modal.empty_trash_confirm')];
            return showModal(title,text,async()=>{
                closeModal();
                try {const data=await request(action==='delete'?'delete-person':'empty-people-trash',action==='delete'?{id}:{});people=data.people;updateOptions();list();}
                catch(error){message(error.message);}
            });
        }
        if(action==='trash'||action==='restore'){
            if(!leave())return;
            try {const data=await request(action==='trash'?'trash-person':'restore-person',{id});people=data.people;updateOptions();list();}
            catch(error){message(error.message);}
        }
    });
    window.addEventListener('beforeunload',event=>{if(dirty){event.preventDefault();event.returnValue='';}});
    return {open,canLeave:leave};
})();
