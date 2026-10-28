"""Hold the PHP fragment reply to verify terminal state clears transient output."""
import os
import json
from playwright.sync_api import sync_playwright

with sync_playwright() as p:
    browser = p.chromium.launch()
    page = browser.new_page()
    errors = []
    page.on('pageerror', lambda error: errors.append(str(error)))
    page.goto(os.environ.get('PHILLIP_URL', 'http://127.0.0.1:8088'))
    page.locator('[name=username]').fill('alice')
    page.locator('[name=password]').fill('phillip-demo')
    page.locator('#login-form button').click()
    page.locator('#message-form').wait_for()
    success_id = page.locator('.log-success').last.get_attribute('data-log-id')
    page.get_by_role('button', name='Chat settings', exact=True).click()
    page.locator('#chat-settings-form').wait_for()
    assert page.locator('[data-log-id="' + success_id + '"]').count() == 1, 'Success should permit interaction before it expires'
    page.locator('[data-log-id="' + success_id + '"]').wait_for(state='detached', timeout=7000)
    assert page.evaluate("id => PHiLLiPLog.records.some(record=>record.id===id)", success_id), 'Expired success remains in diagnostics'
    page.evaluate("document.querySelector('#dialog').close()")
    print('PASS: Success acknowledgments allow immediate header interaction and expire without losing diagnostic records', flush=True)
    for priority, label in [(-1, 'Warning'), (1, 'Error')]:
        message_id = 'nonmodal-' + label.lower()
        page.evaluate("""({id,priority,label}) => PHiLLiPLog.accept({id,priority,
          message:label+' overlay fixture: this nonmodal message remains visible while the operator interacts with the underlying chat settings.',
          visible:true,console:false})""", {'id': message_id, 'priority': priority, 'label': label})
        overlay = page.locator('[data-log-id="' + message_id + '"]')
        overlay.wait_for(state='visible')
        assert overlay.evaluate("element => getComputedStyle(element).pointerEvents") == 'none'
        page.get_by_role('button', name='Chat settings', exact=True).click()
        page.locator('#chat-settings-form').wait_for()
        assert overlay.count() == 1, label + ' should remain visible without blocking settings'
        page.evaluate("document.querySelector('#dialog').close()")
        overlay.get_by_role('button', name='Dismiss message', exact=True).click()
        overlay.wait_for(state='detached')
    print('PASS: Warning and error message bodies allow underlying header interaction while their dismiss buttons remain clickable', flush=True)
    page.evaluate('PHiLLiPLog.minPriority = -4')
    page.route('**/api/personas', lambda route: route.fulfill(status=503, content_type='application/json', body=json.dumps({'error': 'Fixture service unavailable'})))
    page.evaluate("async () => await PHiLLiP.dialog('persona')")
    page.locator('#persona-form [name=name]').fill('Action fixture')
    page.locator('#persona-form [name=prompt]').fill('PRIVATE_TRACE_FIXTURE')
    page.locator('#persona-form button.primary').click()
    page.locator('#persona-form .form-error').filter(has_text='Fixture service unavailable').wait_for()
    assert page.locator('#persona-form button.primary').is_enabled()
    traces = page.evaluate("PHiLLiPLog.records.filter(record=>record.id.startsWith('client-')).map(record=>({priority:record.priority,message:record.message}))")
    assert any(record['priority'] == -3 and '"status":"error"' in record['message'] and 'Persona saved' in record['message'] for record in traces)
    assert not any(record['priority'] == -3 and 'PRIVATE_TRACE_FIXTURE' in record['message'] for record in traces)
    assert not any('PRIVATE_TRACE_FIXTURE' in record['message'] for record in traces), 'Ordinary users must not receive raw traces'
    page.evaluate('PHiLLiPLog.minPriority = -1')
    assert not errors, errors
    page.unroute('**/api/personas')
    page.evaluate("document.querySelector('#dialog').close()")
    outcomes = page.evaluate("""async () => {
      const A=PHiLLiP,toast=A.toast,visible=A.Log.minPriority,messages=[];A.toast=message=>messages.push(message);A.Log.minPriority=3;
      try{
        await A.action('Server outcome fixture',()=>A.Log.accept({id:'outcome-fixture',priority:0,message:'Server acknowledged fixture',visible:true,console:false}),{success:'Duplicate acknowledgment'});
        await A.action('Local outcome fixture',()=>{}, {success:'Local action saved'});
        await A.action('Connection failure fixture',()=>{throw new Error('Fixture connection failed');});
        return messages;
      }finally{A.toast=toast;A.Log.minPriority=visible;}
    }""")
    assert outcomes == ['Local action saved', 'Fixture connection failed'], outcomes
    print('PASS: Failed form requests report errors and recover controls; ordinary users receive no raw traces; server outcomes are not duplicated', flush=True)
    page.evaluate("() => { PHiLLiP.action('Reload fixture',()=>{}, {success:'Reload fixture saved',reload:true}); }")
    page.wait_for_load_state('load')
    page.wait_for_function("() => PHiLLiPLog.records.some(record=>record.priority===0&&record.message==='Reload fixture saved')")
    assert page.evaluate("sessionStorage.getItem('phillip.action-outcome')") is None
    print('PASS: Successful reload actions retain one acknowledgment on the new page', flush=True)
    admin_page = browser.new_page()
    admin_page.goto(os.environ.get('PHILLIP_URL', 'http://127.0.0.1:8088'))
    admin_page.locator('[name=username]').fill('admin')
    admin_page.locator('[name=password]').fill('phillip-demo')
    admin_page.locator('#login-form button').click()
    admin_page.locator('#message-form').wait_for()
    admin_page.route('**/api/personas', lambda route: route.fulfill(status=503, content_type='application/json', body=json.dumps({'error': 'Fixture service unavailable'})))
    privileged = admin_page.evaluate("""async () => {
      PHiLLiPLog.minLogPriority=-4;
      await PHiLLiP.action('Privileged trace fixture',()=>PHiLLiP.api('/api/personas',{name:'Trace fixture',prompt:'PRIVATE_TRACE_FIXTURE',password:'AUTH_CREDENTIAL_FIXTURE',csrf:'AUTH_CSRF_FIXTURE'}));
      await PHiLLiP.api('/api/state');
      return PHiLLiPLog.records.filter(record=>record.priority===-4);
    }""")
    assert any('PRIVATE_TRACE_FIXTURE' in record['message'] for record in privileged)
    assert not any('AUTH_CREDENTIAL_FIXTURE' in record['message'] or 'AUTH_CSRF_FIXTURE' in record['message'] or '"path":"/api/state"' in record['message'] for record in privileged)
    assert all(not record['visible'] for record in privileged)
    admin_page.close()
    print('PASS: Authorized Verbose traces retain raw action inputs, redact credentials, exclude auth state, and stay out of visible messages', flush=True)
    thread_id = page.evaluate("""async () => {
      const A=PHiLLiP;
      const {thread}=await A.api('/api/threads',{title:'Delayed fragment fixture',settings:{manual:true}});
      A.current={thread,runs:[{id:-1,status:'running'}]};
      A.liveRunId='-1';A.live='Transient fixture';A.updateLive();
      return thread.id;
    }""")
    try:
        assert page.locator('#live-message').count() == 1
        page.evaluate("""() => {
          const A=PHiLLiP,api=A.api,html=A.html;
          window.fragmentReleased=false;
          const fragmentGate=new Promise(resolve=>window.releaseFragment=resolve);
          A.api=async(path,body)=>path.includes('/events?')?{
            events:[{id:1,run_id:-1,type:'run.completed'}],
            state:{event_cursor:1,runs:[{id:-1,status:'completed'}]}
          }:api(path,body);
          A.html=async(path,body)=>{
            if(path.startsWith('/fragments/messages')){window.fragmentBlocked=true;await fragmentGate;}
            return html(path,body);
          };
          window.pollFinished=A.poll().finally(()=>{
            clearTimeout(A.pollTimer);A.api=api;A.html=html;window.fragmentReleased=true;
          });
        }""")
        page.wait_for_function('() => window.fragmentBlocked === true')
        assert page.evaluate("PHiLLiP.current.runs[0].status") == 'completed'
        assert page.locator('#live-message').count() == 0, 'Completed state must clear live output while the PHP reply is blocked'
        assert page.locator('#progress').inner_text() == ''
        assert page.locator('#stop-button').is_hidden()
        page.evaluate('window.releaseFragment()')
        page.wait_for_function('() => window.fragmentReleased === true')
        print('PASS: Completed workflow clears live output and controls before the delayed PHP fragment returns', flush=True)
        assert not errors, errors
    finally:
        page.evaluate("""async id => {
          window.releaseFragment?.();await window.pollFinished;clearTimeout(PHiLLiP.pollTimer);
          await PHiLLiP.api('/api/threads/'+id+'/delete',{});
        }""", thread_id)
        browser.close()
