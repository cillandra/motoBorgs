const initializedEmployees=new WeakSet();
function initializeEmployees(root=document){
    const form=root.querySelector('[data-employee-form]');
    if(!form || initializedEmployees.has(form))return;
    initializedEmployees.add(form);
    const role=form.querySelector('[data-employee-role]');
    const confirmation=form.querySelector('[data-role-confirmation]');
    const update=()=>{
        const manager=role.value==='manager';
        form.querySelector('[data-role-label]').textContent=manager?'Manager':'Staff';
        form.querySelector('[data-role-description]').textContent=manager?'Manage shop inventory, reports, employee access, global settings and sensitive approvals. Each approval still requires a current password.':'Access own point-of-sale transactions, saved orders and sale history, and view available products.';
        const needed=role.value!==form.dataset.originalRole || (!form.querySelector('[name="version"]') && manager);
        confirmation.hidden=!needed;
        confirmation.querySelector('input').required=needed;
    };
    role.addEventListener('change',update);update();
}
initializeEmployees();
document.addEventListener('moto:content-ready',event=>initializeEmployees(event.detail.root));
document.addEventListener('click',async event=>{
    if(!event.target.closest('[data-copy-recovery]'))return;
    const scope=event.target.closest('.identity-panel');const code=scope.querySelector('.recovery-code');const status=scope.querySelector('[data-copy-status]');
    try{await navigator.clipboard.writeText(code.value);status.textContent='Recovery code copied.';}catch{code.select();status.textContent='Select and copy the code manually.';}
});
