document.addEventListener('change',event=>{if(event.target.matches('[data-report-sort]'))event.target.form.requestSubmit();});
document.addEventListener('click',event=>{if(event.target.closest('[data-print-report]')){document.querySelectorAll('dialog[open]').forEach(dialog=>{if(!dialog.matches('[data-workspace-modal]'))dialog.close();});window.print();}});
