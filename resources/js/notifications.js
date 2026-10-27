const notificationsButton=document.querySelector('[data-notifications-open]');
if(notificationsButton){
    const dialog=document.getElementById('notifications-panel'),list=dialog.querySelector('[data-notification-list]'),status=dialog.querySelector('[data-notification-status]'),badge=notificationsButton.querySelector('[data-notification-count]');let busy=false,timer=null,expired=false;
    async function load(){
        if(busy||document.hidden||expired)return;busy=true;
        try{
            const response=await fetch('/notifications',{headers:{Accept:'application/json'},signal:AbortSignal.timeout(8000)});if(response.status===401){expired=true;throw new Error('Sign in again to view notifications.');}if(!response.ok)throw new Error('Notifications are unavailable. Retry when the local server is available.');const data=await response.json();
            badge.textContent=data.unread_count>99?'99+':String(data.unread_count);badge.hidden=data.unread_count===0;notificationsButton.setAttribute('aria-label','Notifications · '+data.unread_count+' unread');
            list.replaceChildren();status.textContent=data.items.length?'Latest '+data.items.length+' notifications.':'No notifications yet.';
            for(const item of data.items){
                const row=document.createElement('article');row.className='notification-row';if(!item.read)row.classList.add('unread');
                const link=document.createElement('a');const title=document.createElement('strong');title.textContent=item.title;const message=document.createElement('p');message.textContent=item.message;const when=document.createElement('small');when.textContent=item.when;link.append(title,message,when);
                if(item.href){const url=new URL(item.href,window.location.href);if(url.origin===window.location.origin){link.href=url.href;if(/^\/(inventory|returns|activity)\/\d+/.test(url.pathname))link.dataset.modalLink='';}}
                link.addEventListener('click',()=>{dialog.close();fetch('/notifications/'+item.id+'/read',{method:'POST',headers:{Accept:'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content}}).then(response=>{if(response.ok)load();}).catch(()=>{});});row.append(link);list.append(row);
            }
        }catch(error){status.textContent=error.message||'Notifications are unavailable. Retry.';}
        finally{busy=false;}
    }
    function schedule(){clearInterval(timer);if(!document.hidden&&!expired)timer=setInterval(load,30000);}
    notificationsButton.addEventListener('click',()=>{dialog.showModal();status.textContent='Loading notifications…';load();});dialog.querySelector('[data-notification-retry]').addEventListener('click',load);
    dialog.querySelector('[data-notifications-read-all]').addEventListener('click',async()=>{try{const response=await fetch('/notifications/read-all',{method:'POST',headers:{Accept:'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content}});if(!response.ok)throw new Error();await load();}catch{status.textContent='Read status could not be saved. Retry.';}});
    document.addEventListener('visibilitychange',()=>{schedule();if(!document.hidden)load();});load();schedule();
}
