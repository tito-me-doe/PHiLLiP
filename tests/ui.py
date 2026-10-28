"""Real browser smoke test; requires Playwright and a running demo installation."""
import os
import json
import time
from pathlib import Path
from playwright.sync_api import sync_playwright

BASE = os.environ.get('PHILLIP_URL', 'http://127.0.0.1:8088')
ROOT = Path(__file__).resolve().parents[1]
PERSONA = 'Browser smoke persona '+str(time.time_ns())

def wait_js(page, expression, timeout=180):
    deadline=time.monotonic()+timeout
    while time.monotonic()<deadline:
        if page.evaluate('!!window.PHiLLiP && ('+expression+')'):
            return
        page.wait_for_timeout(200)
    raise AssertionError('Timed out: '+expression)

with sync_playwright() as p:
    browser = p.chromium.launch(headless=True)
    context = browser.new_context(viewport={'width':1440,'height':1000})
    page = context.new_page()
    errors=[]
    page.on('pageerror', lambda e: errors.append(str(e)))
    page.goto(BASE)
    page.locator('[name=username]').fill('admin')
    page.locator('[name=password]').fill('phillip-demo')
    page.locator('#login-form button').click()
    page.get_by_role('heading',name='What’s on your mind?').wait_for()
    page.get_by_role('link',name='Settings',exact=True).click()
    page.get_by_role('link',name='Models',exact=True).click()
    page.locator('table.registry').wait_for()
    assert page.locator('[data-profile-edit]').count() >= 17
    print('PASS: Browser registry lists discovered disabled profiles', flush=True)
    # Server-rendered content may be visible before deferred enhancement scripts finish.
    page.wait_for_function('!!window.PHiLLiP')
    profiles=page.evaluate('PHiLLiP.state.profiles')
    if not profiles:
        page.get_by_role('button',name='Custom profile').click()
        page.locator('#profile-form [name=name]').fill('default')
        page.locator('#profile-form [name=config]').fill('{"url":"http://10.0.2.99:8000/v1/chat/completions","model":"nvidia/nemotron-3-super","temperature":0.65,"top_p":0.95,"min_p":0,"reasoning_effort":"high","context_window":262144,"stream":true}')
        page.locator('#profile-form [name=enabled]').check()
        page.get_by_role('button',name='Save profile',exact=True).click()
        page.wait_for_url('**/?view=settings&tab=models',wait_until='load',timeout=240000)
        page.locator('#dialog').wait_for(state='hidden',timeout=240000)
        assert page.evaluate('PHiLLiP.state.profiles.length') > 0
        print('PASS: Browser Save verifies and enables the real local model',flush=True)
    page.get_by_role('link',name='Appearance',exact=True).click()
    page.locator('[data-avatar-choice=sprout]').click()
    wait_js(page,"PHiLLiP.state.preferences.avatar === 'sprout'")
    page.locator('[data-avatar-choice=terminal]').click()
    wait_js(page,"PHiLLiP.state.preferences.avatar === 'terminal'")
    page.get_by_role('link',name='Personas',exact=True).click()
    page.get_by_role('button',name='New persona',exact=True).click()
    page.locator('#persona-form [name=name]').fill(PERSONA)
    page.locator('#persona-form [name=prompt]').fill('Respond warmly and concisely.')
    page.get_by_role('button',name='Save persona',exact=True).click()
    page.locator('#dialog').wait_for(state='hidden')
    page.get_by_role('heading',name=PERSONA,exact=True).wait_for()
    print('PASS: User avatar and private persona saved',flush=True)
    page.get_by_role('link',name='People & tools',exact=True).click()
    page.locator('#policy-form').wait_for()
    assert page.locator('#membership-form').is_visible()
    page.get_by_role('link',name='Services',exact=True).click()
    page.locator('#services-form').wait_for()
    parsers=context.request.get(BASE+'/api/admin/attachment-parsers').json()['parsers']
    page.locator('#attachment-parsers-form [name=parsers]').fill(json.dumps(parsers))
    page.locator('#attachment-parsers-form button.primary').click()
    page.locator('#toast').filter(has_text='Attachment parsing profiles saved').wait_for()
    print('PASS: PHP-rendered attachment parser settings save through the administrator API',flush=True)
    page.get_by_role('link',name='Back to chat',exact=True).click()
    page.screenshot(path=str(ROOT/'storage/ui-welcome.png'),full_page=True)
    page.locator('#prompt').fill('Reply with exactly BROWSER_READY. Then show a PHP fenced code block containing echo "hello";')
    page.get_by_role('button',name='Send message',exact=True).click()
    wait_js(page,"PHiLLiP.current?.runs.some(r=>['queued','running'].includes(r.status))",15)
    thread_id=page.evaluate('PHiLLiP.current.thread.id')
    page.reload()
    wait_js(page,"PHiLLiP.current?.runs.some(r=>r.status==='completed')")
    page.locator('.message.assistant .message-body').filter(has_text='BROWSER_READY').wait_for(timeout=30000)
    assert page.locator('pre code.hljs').count() >= 1
    assert page.locator('.activity').count() >= 1
    page.screenshot(path=str(ROOT/'storage/ui-chat.png'),full_page=True)
    print('PASS: Streaming turn survives reload, saves reasoning, renders Markdown and highlights PHP',flush=True)
    nojs=browser.new_context(java_script_enabled=False,storage_state=context.storage_state())
    saved=nojs.new_page()
    saved.goto(BASE+'/?thread='+str(thread_id))
    assert saved.locator('.message.assistant .message-body').filter(has_text='BROWSER_READY').is_visible()
    assert saved.locator('pre code.language-php').count()>=1
    saved.goto(BASE+'/?view=settings&tab=models')
    assert saved.locator('[data-profile-edit]').count()>=17
    nojs.close()
    print('PASS: With JavaScript disabled, PHP delivers complete messages, Markdown, avatars and settings on first load',flush=True)
    assert not errors, errors
    page.set_viewport_size({'width':390,'height':844})
    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
    page.screenshot(path=str(ROOT/'storage/ui-mobile.png'),full_page=True)
    print('PASS: Mobile layout fits viewport; no browser JavaScript errors',flush=True)
    # Remove only this test's own conversation and persona.
    page.evaluate("""async data => {await PHiLLiP.api('/api/threads/'+data.id+'/delete',{});const s=await PHiLLiP.api('/api/state');for(const p of s.personas.filter(p=>p.name===data.name))await PHiLLiP.api('/api/personas/'+p.id+'/delete',{});} """,{'id':thread_id,'name':PERSONA})
    context.close()
    browser.close()
