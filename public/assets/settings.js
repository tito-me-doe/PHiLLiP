/* Forms are PHP-rendered. These handlers submit changes and fetch new PHP HTML. */
(() => {
  const A=window.PHiLLiP;
  const bind=(id,success,handler,reload=true)=>{const form=document.querySelector(id);if(!form)return;form.onsubmit=async e=>{e.preventDefault();const b=e.submitter||form.querySelector('button.primary,button');if(b)b.disabled=true;try{await A.action(success,()=>handler(new FormData(form),form,e),{form,success,reload});}finally{if(b)b.disabled=false;}};};
  const close=()=>document.querySelector('#dialog').close();
  A.bindSettings=()=>{
    document.querySelectorAll('[data-theme-choice]').forEach(b=>b.onclick=()=>A.action('Choose appearance',()=>A.setTheme(b.dataset.themeChoice),{success:'Appearance saved'}));
    document.querySelectorAll('[data-avatar-choice]').forEach(b=>b.onclick=()=>A.action('Choose avatar',()=>{A.persistUI({avatar:b.dataset.avatarChoice});document.cookie=A.state.avatar_cookie+'='+b.dataset.avatarChoice+'; Path='+(A.base||'')+'/; SameSite=Lax; Max-Age=31536000'+(location.protocol==='https:'?'; Secure':'');},{success:'Avatar saved',reload:true}));
    document.querySelectorAll('[data-forget]').forEach(b=>b.onclick=()=>{if(confirm('Forget this claim? Its source conversations are kept.'))A.action('Forget memory',()=>A.api('/api/knowledge/'+b.dataset.forget+'/delete',{}),{success:'Memory forgotten',reload:true});});
    bind('#profile-form','Profile saved',async(f,form)=>{const b=form.querySelector('button.primary');b.textContent=f.has('enabled')?'Testing configuration…':'Saving…';try{await A.api('/api/admin/profile',{name:f.get('name'),config:f.get('config'),enabled:f.has('enabled'),overrides:f.get('overrides')});}finally{b.textContent='Save profile';}});
    bind('#persona-form','Persona saved',async(f,form)=>{await A.api('/api/personas',{...Object.fromEntries(f),...(form.dataset.id?{id:form.dataset.id}:{})});close();},Boolean(document.querySelector('[data-settings-page=personas]')));
    document.querySelector('#delete-persona')?.addEventListener('click',e=>{const id=e.currentTarget.dataset.id;if(confirm('Delete this persona? Existing conversations keep their prompts.'))A.action('Delete persona',()=>A.api('/api/personas/'+id+'/delete',{}),{success:'Persona deleted',reload:true});});
    bind('#claim-edit','Memory saved',async(f,form)=>{await A.api('/api/knowledge/'+form.dataset.id,{content:f.get('content')});});
    bind('#services-form','Service configuration saved',async f=>{await A.api('/api/admin/services',{services:f.get('services')});},false);
    bind('#attachment-parsers-form','Attachment parsing profiles saved',async f=>{await A.api('/api/admin/attachment-parsers',{parsers:f.get('parsers')});},false);
    bind('#add-user','User created',async f=>{await A.api('/api/admin/user',Object.fromEntries(f));});
    bind('#add-team','Team created',async f=>{await A.api('/api/admin/team',{name:f.get('name')});});
    bind('#membership-form','Membership saved',async(f,form,e)=>{await A.api('/api/admin/membership',{user_id:f.get('user_id'),team_id:f.get('team_id'),remove:e.submitter?.value==='remove'});});
    bind('#policy-form','Tool permission saved',async f=>{const [scope_type,...id]=f.get('scope').split(':');await A.api('/api/admin/permission',{scope_type,scope_id:id.join(':'),tool:f.get('tool'),allowed:f.get('allowed')});});
    bind('#scan-form','Discovery queued',async f=>{const job=await A.api('/api/admin/scan',{cidr:f.get('cidr'),ports:f.get('ports'),all:f.has('all')});A.watchScan(job.id);},false);
    const active=document.querySelector('#scan-status[data-scan-active]');if(active)A.watchScan(active.dataset.scanActive);
  };
  A.watchScan=async id=>{
    clearTimeout(A.scanTimer);const container=document.querySelector('#scan-status');if(!container)return;if(!container.dataset.operation)A.operationProgress(container,{id,status:'queued'});
    try{const {operations}=await A.api('/api/admin/operations?id='+encodeURIComponent(id)),job=operations.find(j=>j.id==id);
      if(!container.isConnected)return;
      A.Log.writeDebug({action:'Watch discovery',operation_id:id,status:job?.status||'queued'});
      // Scanner progress is transient interactive output, not conversation HTML.
      A.operationProgress(container,job||{id,status:'queued'});
      if(job?.output){let details=container.querySelector('details');if(!details){details=document.createElement('details');details.className='activity';const summary=document.createElement('summary');summary.textContent='Discovery details';const pre=document.createElement('pre');pre.className='scan-output';details.append(summary,pre);container.append(details);}details.querySelector('pre').textContent=job.output;}
      if(!job||['queued','running'].includes(job.status))A.scanTimer=setTimeout(()=>A.watchScan(id),1500);else await A.action('Discovery result',async()=>{
        if(job.status!=='completed')throw new Error('Discovery failed; inspect the output.');
        const html=await A.html('/fragments/settings?tab=models');if(!container.isConnected)return;document.querySelector('#workspace').innerHTML=html;A.bindSettings();A.enhance();A.operationProgress(document.querySelector('#scan-status'),job);
      },{success:'Discovery complete. The model registry is up to date.'});
    }catch(e){A.Log.writeDebug({action:'Watch discovery',operation_id:id,status:'error',http_status:e.status});const record=A.Log.writeError(e.message);if(!record?.visible)A.toast(e.message);}
  };
})();
