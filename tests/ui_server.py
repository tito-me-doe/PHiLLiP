"""No-JavaScript sign-in, message submission, and server-rendered result."""
import os
import subprocess
import time
from pathlib import Path
from playwright.sync_api import sync_playwright
BASE=os.environ.get('PHILLIP_URL','http://127.0.0.1:8088')
with sync_playwright() as p:
    browser=p.chromium.launch()
    context=browser.new_context(java_script_enabled=False)
    page=context.new_page();page.goto(BASE)
    page.locator('[name=username]').fill('admin');page.locator('[name=password]').fill('phillip-demo')
    page.locator('#login-form button').click();page.locator('#message-form').wait_for()
    assert 'password=' not in page.url
    print('PASS: Sign in using a CSRF-protected PHP form with JavaScript disabled',flush=True)
    page.locator('#persona').select_option(label='Thought partner')
    fixture=subprocess.check_output(['php','-r','require "tests/fixtures/media.php"; echo fixturePdf("INVOICE 8127 - Total 49 dollars");'],cwd=Path(__file__).resolve().parents[1])
    page.locator('#files').set_input_files({'name':'invoice.pdf','mimeType':'application/pdf','buffer':fixture})
    page.locator('#prompt').fill('Read the attached invoice. Reply SERVER_FORM_READY followed by the invoice number.')
    page.get_by_role('button',name='Send message',exact=True).click()
    page.wait_for_url('**/?thread=*');url=page.url
    assert page.locator('.message.user').count()==1
    deadline=time.monotonic()+120
    while time.monotonic()<deadline:
        if page.locator('.message.assistant .message-body').filter(has_text='SERVER_FORM_READY').count() and page.locator('#live-message').count()==0:break
        page.wait_for_timeout(500);page.reload()
    else:raise AssertionError('Server-only chat did not finish')
    assert '8127' in page.locator('.message.assistant .message-body').last.inner_text()
    assert 'Thought partner' in page.locator('.message.assistant .message-head').last.inner_text()
    assert page.locator('.message.user .attachment-chips a').count()==1
    print('PASS: PDF upload and local text conversion work without JavaScript',flush=True)
    page.locator('#prompt').fill('Reply SERVER_FOLLOWUP_READY followed by the invoice number from the previous file.')
    page.get_by_role('button',name='Send message',exact=True).click()
    assert page.url==url
    deadline=time.monotonic()+120
    while time.monotonic()<deadline:
        if page.locator('.message.assistant .message-body').filter(has_text='SERVER_FOLLOWUP_READY').count() and page.locator('#live-message').count()==0:break
        page.wait_for_timeout(500);page.reload()
    else:raise AssertionError('Server-only follow-up did not finish')
    assert '8127' in page.locator('.message.assistant .message-body').last.inner_text()
    assert page.locator('.message.user').count()==2
    assert page.locator('.message.assistant[data-id]').count()>=2
    assert 'Thought partner' in page.locator('.message.assistant .message-head').last.inner_text()
    print('PASS: Persona selection and assistant naming work on initial and follow-up PHP forms without JavaScript',flush=True)
    print('PASS: Initial message and follow-up save and render entirely through PHP forms and pages',flush=True)
    # API cleanup from the test harness; JavaScript remains disabled in the page.
    csrf=page.locator('[name=csrf]').input_value();thread=url.split('thread=')[1]
    response=context.request.post(BASE+'/api/threads/'+thread+'/delete',data={},headers={'X-CSRF-Token':csrf,'Content-Type':'application/json'})
    assert response.ok
    browser.close()
