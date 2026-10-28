"""Real text upload, PHP rendering, follow-ups, visibility changes, edit and cleanup."""
import os
import time
from pathlib import Path
from playwright.sync_api import sync_playwright
BASE=os.environ.get('PHILLIP_URL','http://127.0.0.1:8088')

def wait(page, expr, seconds=150):
    until=time.monotonic()+seconds
    while time.monotonic()<until:
        if page.evaluate('!!window.PHiLLiP && ('+expr+')'):return
        page.wait_for_timeout(150)
    raise AssertionError('Timed out: '+expr)

def expect_answer(page, text):
    # Typography changes such as a non-breaking hyphen are not an attachment/event failure.
    page.wait_for_function("""expected => {
      const body=[...document.querySelectorAll('.message.assistant[data-id] .message-body')].at(-1);
      return body && body.textContent.replace(/[‐‑‒–—―−]/g,'-').includes(expected);
    }""",arg=text)

with sync_playwright() as p:
    browser=p.chromium.launch();ctx=browser.new_context(viewport={'width':1280,'height':900});page=ctx.new_page();errors=[]
    page.on('pageerror',lambda e:errors.append(str(e)))
    page.goto(BASE+'/?new=1');page.locator('[name=username]').fill('alice');page.locator('[name=password]').fill('phillip-demo');page.locator('#login-form button').click();page.locator('#message-form').wait_for()
    uploads=[]
    def lose_upload_ack(route):
        response=route.fetch();uploads.append(response.json()['attachment']['id'])
        if len(uploads)==1:route.abort('failed')
        else:route.fulfill(response=response)
    page.route('**/api/attachments',lose_upload_ack)
    page.locator('#files').set_input_files({'name':'delivery.php','mimeType':'text/plain','buffer':b'<?php\n// The delivery code is COPPER-482. Destination: Boise.\necho "hello";\n'})
    wait(page,'PHiLLiP.attachments.length===1 && !PHiLLiP.uploading')
    attachment=page.evaluate('PHiLLiP.attachments[0]')
    assert len(uploads)==2 and len(set(uploads))==1,uploads
    page.unroute('**/api/attachments',lose_upload_ack)
    assert ctx.request.post(BASE+'/api/admin/attachment-parsers',data={'parsers':{}},headers={'X-CSRF-Token':page.evaluate('PHiLLiP.state.csrf')}).status==403
    print('PASS: Lost upload acknowledgement retries one stored file; parser configuration is admin-only',flush=True)
    assert page.locator('#attachment-chips').inner_text().count('delivery.php')==1
    assert ctx.request.get(BASE+'/api/attachments/'+attachment['id']).body().startswith(b'<?php')
    print('PASS: Multipart upload produces a PHP-rendered file chip and protected original download',flush=True)
    page.reload();page.locator('#message-form').wait_for();wait(page,'PHiLLiP.attachments.length===1')
    page.locator('#attachment-chips a').wait_for()
    page.locator('#prompt').fill('Read the attached file. State its delivery code and destination.')
    page.get_by_role('button',name='Send message',exact=True).click()
    wait(page,"PHiLLiP.current?.runs.some(r=>r.status==='completed')")
    expect_answer(page,'COPPER-482')
    thread=page.evaluate('PHiLLiP.current.thread.id');csrf=page.evaluate('PHiLLiP.state.csrf')
    assert page.locator('.message.user .attachment-chips a').count()==1
    page.reload();page.locator('#message-form').wait_for()
    assert page.locator('#attachment-chips a').count()==0
    print('PASS: Reload preserves pending uploads and completed attachments without reattaching sent files',flush=True)
    page.locator('#files').set_input_files({'name':'reference.txt','mimeType':'text/plain','buffer':b'Additional source: delivery destination Boise.'})
    wait(page,'PHiLLiP.attachments.length===1 && !PHiLLiP.uploading')
    draft=page.evaluate('PHiLLiP.attachments[0].id')
    page.get_by_role('button',name='Chat settings',exact=True).click()
    page.get_by_role('button',name='Save settings',exact=True).click();page.locator('#dialog').wait_for(state='hidden')
    page.locator('#attachment-chips a').wait_for()
    assert page.evaluate('PHiLLiP.attachments[0].id')==draft
    # Replacing the whole workspace must restore the correct conversation's draft, not leak it to another.
    empty=ctx.request.post(BASE+'/api/threads',data={'title':'Draft isolation fixture','settings':{'profile':'default','tools':[],'parameters':{}}},headers={'X-CSRF-Token':csrf}).json()['thread']['id']
    page.evaluate('id=>PHiLLiP.openThread(id)',empty)
    assert page.locator('#attachment-chips a').count()==0
    assert page.evaluate('PHiLLiP.attachments.length')==0
    page.evaluate('id=>PHiLLiP.openThread(id)',thread);page.locator('#attachment-chips a').wait_for()
    assert page.evaluate('PHiLLiP.attachments[0].id')==draft
    assert ctx.request.post(BASE+'/api/threads/'+str(empty)+'/delete',data={},headers={'X-CSRF-Token':csrf}).ok
    print('PASS: Composer replacement preserves visible drafts and keeps them isolated by conversation',flush=True)
    for prompt,answer in [('What destination was named in the file? Reply briefly.','Boise'),('Use the attachment tool to read delivery.php and state its delivery code exactly as written.','COPPER-482')]:
        old=page.evaluate('PHiLLiP.current.runs.length');page.locator('#prompt').fill(prompt);page.locator('#prompt').press('Enter')
        wait(page,f"PHiLLiP.current.runs.length==={old+1} && PHiLLiP.current.runs.at(-1).status==='completed'")
        expect_answer(page,answer)
    print('PASS: Multiple follow-ups and reflected attachment retrieval work after PHP fragment replacement',flush=True)
    other=browser.new_context();q=other.new_page();q.goto(BASE);q.locator('[name=username]').fill('bob');q.locator('[name=password]').fill('phillip-demo');q.locator('#login-form button').click();q.locator('#message-form').wait_for()
    assert other.request.get(BASE+'/api/attachments/'+attachment['id']).status==403
    assert ctx.request.post(BASE+'/api/threads/'+str(thread)+'/share',data={'visibility':'team','team_id':'engineering'},headers={'X-CSRF-Token':csrf}).ok
    assert other.request.get(BASE+'/api/attachments/'+attachment['id']).ok
    assert ctx.request.post(BASE+'/api/threads/'+str(thread)+'/share',data={'visibility':'private'},headers={'X-CSRF-Token':csrf}).ok
    assert other.request.get(BASE+'/api/attachments/'+attachment['id']).status==403
    print('PASS: File access follows private/team visibility changes immediately',flush=True)
    page.locator('.message.user').first.get_by_role('button',name='Edit your message').click()
    page.locator('#edit-message [name=content]').fill('Read the attached file and reply with the destination only.')
    page.get_by_role('button',name='Replace and send',exact=True).click();page.locator('#dialog').wait_for(state='hidden')
    wait(page,"PHiLLiP.current.runs.length===1 && PHiLLiP.current.runs[0].status==='completed'")
    page.locator('.message.assistant .message-body').filter(has_text='Boise').wait_for()
    assert ctx.request.get(BASE+'/api/attachments/'+attachment['id']).ok
    page.screenshot(path=str(Path(__file__).resolve().parents[1]/'storage/ui-attachments.png'),full_page=True)
    assert not errors,errors
    assert ctx.request.post(BASE+'/api/threads/'+str(thread)+'/delete',data={},headers={'X-CSRF-Token':csrf}).ok
    assert ctx.request.get(BASE+'/api/attachments/'+attachment['id']).status==403
    print('PASS: Edit retains files, deletes later turns, and thread deletion removes the last file reference',flush=True)
    other.close();browser.close()
