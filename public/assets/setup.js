(() => {
  const A=window.PHiLLiP;
  A.bindSetup=()=>{
    const wizard=document.querySelector('.setup-wizard');if(!wizard)return;
    const bubble=document.querySelector('#setup-help');let speech;
    const speak=text=>{
      clearInterval(speech);A.setTalking(false);bubble.textContent=text;
      if(matchMedia('(prefers-reduced-motion: reduce)').matches)return;
      A.setTalking(true);let count=0;speech=setInterval(()=>{const chunk=text.slice(count,count+5);count+=5;A.emote(chunk);if(count>=text.length){clearInterval(speech);A.setTalking(false);A.emote();}},70);
    };
    wizard.addEventListener('focusin',e=>{const label=e.target.closest('[data-help]');if(label){e.target.setAttribute('aria-describedby','setup-help');speak(label.dataset.help);if(innerWidth<800&&e.target.getBoundingClientRect().bottom>innerHeight-180)e.target.scrollIntoView({block:'center'});}});
    const initial=document.activeElement?.closest('[data-help]');if(initial)speak(initial.dataset.help);
    const network=document.querySelector('#setup-network'),range=document.querySelector('[name=cidr]');
    if(network){const sync=()=>{document.querySelector('#setup-cidr-label').hidden=network.value!=='custom';if(network.value!=='custom')range.value=network.value;};network.addEventListener('change',sync);sync();}
    let previousReady=false;
    const refresh=async()=>{
      if(!document.querySelector('#setup-model-select'))return;
      const data=await A.api('/api/setup/status'),select=document.querySelector('#setup-model-select'),value=select.value;
      select.replaceChildren(new Option('Choose a discovered model',''),...data.profiles.map(p=>new Option(p.model+' · '+p.url,p.id)));select.value=value;
      const progress=document.querySelector('#setup-progress');
      const signature=JSON.stringify(data.operations.map(o=>[o.id,o.status]));
      if(progress.dataset.signature!==signature){progress.dataset.signature=signature;progress.replaceChildren(...data.operations.map(o=>{const p=document.createElement('div');p.className='operation-progress';A.operationProgress(p,o,o.type==='scan'?'Discovery':'Model verification');return p;}));}
      const busy=data.operations.some(o=>o.status==='queued'||o.status==='running');
      A.setupBusy=busy;
      document.querySelector('[data-setup-api=scan] button[type=submit]').disabled=busy;
      document.querySelector('#setup-finish').disabled=!data.ready;
      document.querySelector('#setup-ready-label').textContent=data.ready?'Ready to chat':'One working model to go';
      if(data.ready&&!previousReady)speak('Wonderful. Your model is ready, and so am I. Open your first conversation whenever you like.');previousReady=data.ready;
    };
    document.querySelectorAll('[data-setup-api]').forEach(form=>form.addEventListener('submit',async e=>{
      e.preventDefault();const button=form.querySelector('button[type=submit]'),action=form.dataset.setupApi;
      button.disabled=true;
      try{await A.action('Setup '+action,async()=>{const result=await A.api('/api/setup/'+action,Object.fromEntries(new FormData(form)));if(action==='password'||action==='finish')location.assign(A.base+'/');else{await refresh();speak(action==='model'?'I’m checking that model in the background. I’ll update this screen as soon as it’s ready.':'Got it. I’m looking for your models; the list will update automatically.');}return result;},{form,success:action==='model'?'Model test queued':'Saved'});}finally{if(action!=='finish')button.disabled=action==='scan'&&Boolean(A.setupBusy);}
    }));
    if(document.querySelector('#setup-model-select')){
      let timer;const poll=async()=>{try{await refresh();}catch(error){A.Log.writeDebug('Setup status unavailable',error.message);}finally{if(document.querySelector('.setup-wizard'))timer=setTimeout(poll,2500);}};
      timer=setTimeout(poll,1000);window.addEventListener('pagehide',()=>clearTimeout(timer),{once:true});
    }
    const form=document.querySelector('#setup-form');if(!form)return;
    form.elements.existing.onchange=()=>document.querySelector('#existing-db').hidden=form.elements.existing.value!=='yes';
    form.elements.auth.onchange=()=>document.querySelector('#erp-fields').hidden=form.elements.auth.value!=='erp';
    const connection=()=>({dsn:form.elements.dsn.value,username:form.elements.db_username.value,password:form.elements.db_password.value});
    document.querySelector('#inspect-db').onclick=()=>A.action('Inspect database',async()=>{const {items}=await A.api('/api/setup/inspect',{connection:connection()});document.querySelectorAll('[data-map]').forEach(select=>select.replaceChildren(new Option('Create PHiLLiP table',''),...items.map(t=>new Option(t,t))));},{form,success:'Tables loaded'});
    document.querySelectorAll('[data-map]').forEach(select=>select.onchange=()=>A.action('Load table mapping',async()=>{const entity=select.dataset.map,node=document.querySelector('#columns-'+entity);if(!select.value){node.replaceChildren();return;}node.innerHTML=await A.html('/fragments/mapping',{entity,table:select.value,connection:connection()});},{form,success:'Mapping loaded'}));
    form.onsubmit=async e=>{
      e.preventDefault();const f=new FormData(form),configuration={auth:f.get('auth'),mappings:{}};
      if(f.get('existing')==='yes'){configuration.connection=connection();document.querySelectorAll('[data-map]').forEach(select=>{if(!select.value)return;const entity=select.dataset.map,spec={table:select.value,columns:{},writable:document.querySelector('#writable-'+entity)?.checked||false,auto_id:document.querySelector('#auto-'+entity)?.checked||false};document.querySelectorAll(`[data-column^="${entity}."]`).forEach(c=>{if(c.value)spec.columns[c.dataset.column.split('.')[1]]=c.value;});configuration.mappings[entity]=spec;});}
      if(configuration.auth==='erp')configuration.session={bootstrap:f.get('bootstrap'),name:f.get('session_name'),user_path:f.get('user_path')};
      if(f.get('existing')==='yes')configuration.administrator={username:f.get('mapped_username'),password:f.get('mapped_password')};
      const button=form.querySelector('button[type=submit]');button.disabled=true;
      try{await A.action('Save preferences',()=>A.api('/api/setup',{configuration}),{form,success:'Your space is ready',reload:true});}finally{button.disabled=false;}
    };
  };
})();
