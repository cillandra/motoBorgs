const initializedSettings=new WeakSet();
let currentSettings=null;
function feedback(message,tone='success'){
    const toast=document.createElement('div');toast.className='toast';toast.setAttribute('role',tone==='error'?'alert':'status');toast.textContent=message;document.body.append(toast);setTimeout(()=>toast.remove(),7000);
    document.dispatchEvent(new CustomEvent('moto:feedback',{detail:{tone}}));
}
function applyPreferences(values){
    const root=document.documentElement;root.dataset.theme=values.theme;root.dataset.density=values.density;root.dataset.reducedMotion=String(Boolean(values.reduced_motion));root.dataset.uiSound=String(Boolean(values.sound));root.dataset.preferences=JSON.stringify(values);
}
function formPreferences(form){return {theme:form.querySelector('[name="values[theme]"]').value,density:form.querySelector('[name="values[density]"]').value,rows:Number(form.querySelector('[name="values[rows]"]').value),reduced_motion:form.querySelector('[name="values[reduced_motion]"][type=checkbox]').checked,sound:form.querySelector('[name="values[sound]"][type=checkbox]').checked};}
function rememberDefaults(form){for(const input of form.elements){if(input.type==='checkbox'||input.type==='radio')input.defaultChecked=input.checked;else if(input.tagName==='SELECT')for(const option of input.options)option.defaultSelected=option.selected;else if(input.tagName==='INPUT'||input.tagName==='TEXTAREA')input.defaultValue=input.type==='password'?'':input.value;}}
function initializeSettings(root=document){
    const wrapper=root.querySelector('[data-settings-root]');if(!wrapper)return;
    if(initializedSettings.has(wrapper))return;initializedSettings.add(wrapper);
    const form=wrapper.querySelector('[data-settings-form]');
    if(!form){currentSettings=null;return;}
    const signature=()=>JSON.stringify([...new FormData(form)].filter(([name])=>name.startsWith('values[')));
    const savedPreferences=()=>{try{return JSON.parse(document.documentElement.dataset.preferences);}catch{return null;}};
    const state={form,wrapper,baseline:signature(),dirty:false,busy:false,savedPreferences:savedPreferences(),update:null,reset:null};currentSettings=state;
    const save=form.querySelector('[data-settings-save]'),discard=form.querySelector('[data-settings-discard]'),status=form.querySelector('[data-settings-status]');
    function update(){state.dirty=signature()!==state.baseline;save.disabled=state.busy||!state.dirty;discard.hidden=!state.dirty;discard.disabled=state.busy;status.textContent=state.busy?'Saving…':state.dirty?'You have unsaved changes.':'No unsaved changes.';form.querySelectorAll('.setting-switch').forEach(row=>{row.querySelector('[data-switch-label]').textContent=row.querySelector('[type=checkbox]').checked?'Enabled':'Disabled';});}
    state.update=update;state.reset=()=>{form.reset();if(wrapper.dataset.section==='preferences'&&state.savedPreferences)applyPreferences(state.savedPreferences);};
    form.addEventListener('input',update);form.addEventListener('change',()=>{if(wrapper.dataset.section==='preferences')applyPreferences(formPreferences(form));update();});
    form.addEventListener('reset',()=>queueMicrotask(()=>{form.querySelectorAll('[data-settings-error]').forEach(error=>error.remove());if(wrapper.dataset.section==='preferences'&&state.savedPreferences)applyPreferences(state.savedPreferences);update();}));
    async function saveChanges(password=null){
        if(state.busy)return;
        let invalidField=null;
        form.querySelectorAll('[aria-invalid]').forEach(field=>{field.removeAttribute('aria-invalid');field.removeAttribute('aria-describedby');});
        const payload=new FormData(form);if(password!==null)payload.set('manager_password',password);
        const fields=[...form.elements],disabled=fields.map(field=>field.disabled);state.busy=true;update();fields.forEach(field=>field.disabled=true);
        form.querySelectorAll('[data-settings-error]').forEach(error=>error.remove());
        const confirmation=wrapper.querySelector('#settings-reauth');confirmation.dataset.processing='true';confirmation.querySelectorAll('button').forEach(button=>button.disabled=true);
        try{
            const response=await fetch(form.getAttribute('action'),{method:'POST',headers:{Accept:'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},body:payload});
            const data=await response.json().catch(()=>({}));if(!response.ok){for(const key of Object.keys(data.errors||{})){const parts=key.split('.');const name=parts.shift()+parts.map(part=>'['+part+']').join('');const field=[...form.elements].find(input=>input.name===name&&input.type!=='hidden');if(field){field.setAttribute('aria-invalid','true');field.setAttribute('aria-describedby','settings-validation-summary');invalidField ||= field;}}throw new Error(Object.values(data.errors||{}).flat().join(' ')||data.message||'The save could not be confirmed. Keep your changes and retry.');}
            if(data.version!==undefined)form.querySelector('[name=version]').value=data.version;
            if(wrapper.dataset.section==='preferences'){applyPreferences(data.values);state.savedPreferences=data.values;}
            fields.forEach((field,index)=>field.disabled=disabled[index]);rememberDefaults(form);state.baseline=signature();state.dirty=false;confirmation.close();feedback(data.message||'Settings saved.');
        }catch(error){
            const alert=document.createElement('div');alert.className='inline-alert error';alert.dataset.settingsError='';alert.id='settings-validation-summary';alert.setAttribute('role','alert');alert.tabIndex=-1;alert.textContent=error.message;form.querySelector('.settings-scroll').prepend(alert);alert.focus();confirmation.close();document.dispatchEvent(new CustomEvent('moto:feedback',{detail:{tone:'error'}}));
        }finally{state.busy=false;fields.forEach((field,index)=>field.disabled=disabled[index]);delete confirmation.dataset.processing;confirmation.querySelectorAll('button').forEach(button=>button.disabled=false);update();if(invalidField?.isConnected)invalidField.focus();}
    }
    form.addEventListener('submit',event=>{event.preventDefault();if(!form.checkValidity()||!state.dirty||state.busy)return;if(form.dataset.sensitive==='1')wrapper.querySelector('#settings-reauth').showModal();else saveChanges();});
    wrapper.querySelector('[data-settings-reauth]').addEventListener('submit',event=>{event.preventDefault();const input=event.target.querySelector('[name=manager_password]');const password=input.value;input.value='';saveChanges(password);});
    wrapper.querySelector('#settings-reauth').addEventListener('close',event=>event.target.querySelectorAll('[type=password]').forEach(input=>input.value=''));
    wrapper.querySelector('[data-test-sound]')?.addEventListener('click',()=>document.dispatchEvent(new CustomEvent('moto:feedback',{detail:{tone:'success',test:true}})));
    update();
}
async function mayLeaveSettings(){
    const state=currentSettings;if(!state?.form.isConnected||!state.dirty)return true;if(state.busy)return false;
    const dialog=state.wrapper.querySelector('#settings-discard');dialog.returnValue='';
    const allowed=await new Promise(resolve=>{dialog.addEventListener('close',()=>resolve(dialog.returnValue==='discard'),{once:true});dialog.showModal();});
    if(allowed){state.reset();state.dirty=false;}return allowed;
}
document.addEventListener('click',event=>{
    const state=currentSettings;if(!state?.form.isConnected)return;
    const link=event.target.closest('a[href]');if(!link||event.defaultPrevented||event.button!==0||event.ctrlKey||event.metaKey||event.shiftKey||event.altKey||link.hasAttribute('download')||link.target==='_blank')return;
    const next=new URL(link.href,window.location.href);if(next.origin!==window.location.origin)return;
    const category=link.matches('.settings-category');if(!state.dirty&&!category)return;
    event.preventDefault();event.stopImmediatePropagation();
    (async()=>{if(!(await mayLeaveSettings()))return;if(link.hasAttribute('data-modal-link'))window.motoModal?.open(next.href);else if(category&&window.motoModal)await window.motoModal.refresh(next.href,true);else window.location.assign(next.href);})();
},true);
document.addEventListener('submit',event=>{
    const state=currentSettings;const form=event.target;if(!state?.dirty||!state.form.isConnected||!form.getAttribute('action')?.endsWith('/logout'))return;
    event.preventDefault();(async()=>{if(await mayLeaveSettings())form.requestSubmit();})();
},true);
window.addEventListener('beforeunload',event=>{if(currentSettings?.form.isConnected&&currentSettings.dirty&&!currentSettings.busy){event.preventDefault();event.returnValue='';}});
document.addEventListener('submit',async event=>{
    const form=event.target;if(!form.matches('[data-account-form]')||event.defaultPrevented)return;event.preventDefault();if(form.dataset.saving)return;
    const payload=new FormData(form);form.querySelectorAll('[type=password]').forEach(input=>input.value='');form.dataset.saving='true';
    const dialog=form.closest('dialog');dialog.dataset.processing='true';const buttons=[...form.querySelectorAll('button')];buttons.forEach(button=>button.disabled=true);form.querySelector('[data-account-error]')?.remove();
    try{
        const response=await fetch(form.getAttribute('action'),{method:'POST',headers:{Accept:'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},body:payload});const data=await response.json().catch(()=>({}));
        if(!response.ok)throw new Error(Object.values(data.errors||{}).flat().join(' ')||data.message||'The result could not be confirmed.');
        if(data.redirect){window.location.assign(data.redirect);return;}
        dialog.close();await window.motoModal?.refresh();feedback(data.message||'Account updated.');
    }catch(error){const alert=document.createElement('p');alert.className='inline-alert error';alert.setAttribute('role','alert');alert.dataset.accountError='';alert.textContent=error.message;form.prepend(alert);}
    finally{delete form.dataset.saving;delete dialog.dataset.processing;buttons.forEach(button=>button.disabled=false);}
});
document.addEventListener('click',event=>{if(event.target.closest('[data-print-sample]')){try{window.print();}catch{feedback('The print dialog could not open. No sale was created.','error');}}});
document.addEventListener('moto:toggle-theme',async()=>{
    let previous;try{previous=JSON.parse(document.documentElement.dataset.preferences);}catch{previous={theme:document.documentElement.dataset.theme||'dark',density:'comfortable',rows:20,reduced_motion:false,sound:false};}
    if(currentSettings?.dirty){feedback('Save or discard your current Settings changes before switching appearance.','error');return;}
    const values={...previous,theme:previous.theme==='dark'?'light':'dark'};applyPreferences(values);
    try{const response=await fetch('/settings/preferences',{method:'PUT',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},body:JSON.stringify({values})});if(!response.ok)throw new Error('Appearance could not be saved.');if(currentSettings?.wrapper.dataset.section==='preferences')await window.motoModal?.refresh();}
    catch(error){applyPreferences(previous);feedback(error.message,'error');}
});
initializeSettings();document.addEventListener('moto:content-ready',event=>initializeSettings(event.detail.root));
