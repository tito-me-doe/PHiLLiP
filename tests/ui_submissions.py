"""Lose real HTTP acknowledgements after commit; retries must not duplicate work."""
import os
import time
from pathlib import Path
from playwright.sync_api import sync_playwright
BASE=os.environ.get('PHILLIP_URL','http://127.0.0.1:8088')

def wait(page, expression, timeout=150):
    deadline=time.monotonic()+timeout
    while time.monotonic()<deadline:
        if page.evaluate('!!window.PHiLLiP && ('+expression+')'):return
        page.wait_for_timeout(150)
    raise AssertionError('Timed out: '+expression)

with sync_playwright() as p:
    browser=p.chromium.launch();context=browser.new_context();page=context.new_page();errors=[]
    page.on('pageerror',lambda e:errors.append(str(e)))
    page.goto(BASE+'/?new=1');page.locator('[name=username]').fill('alice');page.locator('[name=password]').fill('phillip-demo');page.locator('#login-form button').click();page.locator('#message-form').wait_for()
    page.get_by_role('button',name='Chat settings',exact=True).click()
    page.locator('#chat-settings-form [name=max_tokens]').fill('1024')
    page.locator('#chat-settings-form [name=system_prompt]').fill('Reply concisely and follow instructions.')
    page.get_by_role('button',name='Save settings',exact=True).click();page.locator('#dialog').wait_for(state='hidden')
    # Settings for an unsent conversation are local UI state, not server workflow state.
    assert page.evaluate('JSON.parse(localStorage.getItem(PHiLLiP.storageKey)).draft.settings.parameters.max_tokens')==1024
    page.locator('#files').set_input_files({'name':'retry.txt','mimeType':'text/plain','buffer':b'Retry fixture attachment. No actions required.'})
    wait(page,'PHiLLiP.attachments.length===1 && !PHiLLiP.uploading')
    file_id=page.evaluate('PHiLLiP.attachments[0].id')
    captured={}
    def lose_creation(route):
        response=route.fetch();assert response.ok
        captured['thread']=response.json()['thread']['id'];route.abort()
    page.route('**/api/threads',lose_creation)
    page.locator('#prompt').fill('Reply exactly RETRY_READY.')
    page.get_by_role('button',name='Send message',exact=True).click()
    wait(page,"!PHiLLiP.sending && !!PHiLLiP.ui.pending")
    first_key=page.evaluate('PHiLLiP.ui.pending.key')
    page.unroute('**/api/threads',lose_creation)
    assert page.locator('#prompt').input_value()=='Reply exactly RETRY_READY.'
    def lose_message(route):
        response=route.fetch();assert response.ok
        captured['run']=response.json()['run']['id'];route.abort()
    page.route('**/api/threads/*/messages',lose_message)
    page.get_by_role('button',name='Send message',exact=True).click()
    wait(page,"!PHiLLiP.sending && PHiLLiP.ui.pending.threadId !== null")
    assert page.evaluate('PHiLLiP.ui.pending.key')==first_key
    page.unroute('**/api/threads/*/messages',lose_message)
    page.reload();page.locator('#message-form').wait_for()
    wait(page,'!PHiLLiP.restoringAttachments')
    assert page.locator('#prompt').input_value()=='Reply exactly RETRY_READY.'
    assert page.locator('[name=submission_key]').input_value()==first_key
    assert page.evaluate('PHiLLiP.attachments[0].id')==file_id
    # The same pending submission must recover from its actual thread URL, not just the unsent-page URL.
    page.goto(BASE+'/?thread='+str(captured['thread']));page.locator('#message-form').wait_for()
    page.locator('#attachment-chips a').wait_for()
    assert page.evaluate('PHiLLiP.attachments[0].id')==file_id
    page.get_by_role('button',name='Send message',exact=True).click()
    wait(page,"PHiLLiP.current?.runs.some(r=>r.status==='completed')")
    page.locator('.message.assistant .message-body').filter(has_text='RETRY_READY').wait_for()
    assert page.evaluate('PHiLLiP.current.thread.id')==captured['thread']
    assert page.evaluate('PHiLLiP.current.runs.length')==1
    assert page.evaluate('PHiLLiP.current.runs[0].id')==captured['run']
    assert page.locator('.message.user').count()==1
    assert page.locator('.message.user .attachment-chips a').count()==1
    assert page.locator('.message.assistant .message-body').filter(has_text='RETRY_READY').count()==1
    print('PASS: Lost creation and message acknowledgements, reload, and retry still create exactly one thread and one run',flush=True)
    result=page.evaluate("""async()=>{
      const id=PHiLLiP.current.thread.id,key=crypto.randomUUID(),payload={content:'Reply exactly CONCURRENT_READY.',submission_key:key};
      const replies=await Promise.all([PHiLLiP.api('/api/threads/'+id+'/messages',payload),PHiLLiP.api('/api/threads/'+id+'/messages',payload)]);
      return replies.map(r=>r.run.id);
    }""")
    assert result[0]==result[1]
    wait(page,"PHiLLiP.current.runs.length===2 && PHiLLiP.current.runs.every(r=>r.status==='completed')")
    page.locator('.message.assistant .message-body').filter(has_text='CONCURRENT_READY').wait_for()
    assert page.locator('.message.user').count()==2
    assert page.locator('.message.assistant .message-body').filter(has_text='CONCURRENT_READY').count()==1
    print('PASS: Simultaneous HTTP retries return the same run and execute one follow-up',flush=True)
    panel=page.locator('details[data-key]').first
    panel.locator('summary').click();wait(page,"Object.values(PHiLLiP.ui.panels[PHiLLiP.current.thread.id]).some(Boolean)")
    key=panel.get_attribute('data-key');page.reload();page.locator('#message-form').wait_for()
    assert page.locator('details[data-key="'+key+'"]').get_attribute('open') is not None
    print('PASS: Expanded panels persist in local storage across a server-rendered page reload',flush=True)
    page.get_by_role('link',name='Settings',exact=True).click();page.get_by_role('link',name='Appearance',exact=True).click()
    page.locator('[data-avatar-choice=sprout]').click()
    wait(page,"PHiLLiP.state.preferences.avatar==='sprout'")
    assert page.evaluate('JSON.parse(localStorage.getItem(PHiLLiP.storageKey)).avatar')=='sprout'
    page.locator('[data-avatar-choice=terminal]').click();wait(page,"PHiLLiP.state.preferences.avatar==='terminal'")
    assert not errors,errors
    page.evaluate("async id=>await PHiLLiP.api('/api/threads/'+id+'/delete',{})",captured['thread'])
    print('PASS: Avatar selection is browser-local; no JavaScript errors',flush=True)
    browser.close()
