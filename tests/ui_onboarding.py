"""Invoked by tests/onboarding.php --browser inside its disposable database schema."""
import os
from pathlib import Path
from playwright.sync_api import sync_playwright

base = os.environ['ONBOARDING_URL']
password = os.environ['ONBOARDING_PASSWORD']

def assert_blinking(avatars, soft=False):
    for avatar in avatars.all():
        result=avatar.evaluate("""(node,soft) => {
          const eyes=['.eye-left','.eye-right'].map(s=>node.querySelector(s));
          const name=soft?'avatar-soft-blink':'avatar-blink-eye';
          const animations=eyes.map(n=>n.getAnimations().find(a=>a.animationName===name));
          if(animations.some(a=>!a))return {running:false};
          const running=animations.every(a=>a.playState==='running'&&a.effect.getTiming().iterations===Infinity);
          const times=animations.map(a=>a.currentTime);
          animations.forEach(a=>{a.pause();a.currentTime=7300*.29;});
          const phases=eyes.map(n=>getComputedStyle(n)[soft?'opacity':'transform']);
          const delay=getComputedStyle(eyes[1]).animationDelay;
          animations.forEach((a,i)=>{a.currentTime=times[i];a.play();});
          return {running,phases,delay};
        }""",soft)
        assert result['running'] and result['delay']=='0.045s' and result['phases'][0]!=result['phases'][1],result

with sync_playwright() as p:
    browser = p.chromium.launch(headless=True)
    context = browser.new_context(viewport={'width': 1440, 'height': 1050})
    page = context.new_page()
    errors = []
    page.on('pageerror', lambda error: errors.append(str(error)))
    page.goto(base)
    assert_blinking(page.locator('.login-card .avatar'))
    page.locator('[name=username]').fill('browser_admin')
    page.locator('[name=password]').fill(password)
    page.get_by_role('button', name='Make yourself at home').click()
    page.get_by_role('heading', name='Make it yours, browser_admin.').wait_for()
    page.locator('[name=password]').focus()
    page.wait_for_function("() => document.querySelector('#setup-help').textContent.includes('12 to 72')")
    page.locator('[name=password]').fill('My very own private password 123!')
    page.locator('[name=confirmation]').fill('My very own private password 123!')
    page.get_by_role('button', name='Save my password').click()
    page.get_by_role('heading', name="Just you? That's all we need.").wait_for()
    assert page.locator('[name=existing]').input_value() == 'no'
    assert page.locator('[name=auth]').input_value() == 'standalone'
    page.get_by_role('button', name='Find my models').click()
    page.get_by_role('heading', name='The intelligence is already yours.').wait_for()
    assert page.locator('#setup-finish').is_disabled()
    page.get_by_role('button', name='Discover local models').click()
    page.locator('#setup-progress progress').first.wait_for()
    page.wait_for_function("() => document.querySelector('#setup-model-select').options.length > 1")
    page.wait_for_function("() => document.querySelector('#setup-progress [data-status=completed]')")
    page.reload()
    page.get_by_role('heading', name='The intelligence is already yours.').wait_for()
    choices = page.locator('#setup-model-select option').evaluate_all("nodes => nodes.filter(n=>n.value).map(n=>n.value)")
    page.locator('#setup-model-select').select_option(choices[0])
    page.get_by_role('button', name='Test & use this model').click()
    page.reload()
    page.wait_for_function("() => !document.querySelector('#setup-finish').disabled", timeout=60000)
    assert abs(page.locator('.setup-guide').bounding_box()['y'] + page.locator('.setup-guide').bounding_box()['height'] - (1050 - 20)) < 3
    screenshots = Path(__file__).resolve().parent.parent / 'storage' / 'test-artifacts'
    screenshots.mkdir(parents=True, exist_ok=True)
    page.screenshot(path=str(screenshots / 'onboarding-desktop.png'), full_page=True)
    page.set_viewport_size({'width': 390, 'height': 844})
    page.emulate_media(reduced_motion='reduce')
    assert page.locator('.avatar-body').evaluate("n=>getComputedStyle(n).animationName") == 'none'
    assert_blinking(page.locator('.setup-guide .avatar'),soft=True)
    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
    page.screenshot(path=str(screenshots / 'onboarding-mobile.png'), full_page=True)
    page.set_viewport_size({'width': 1440, 'height': 1050})
    page.get_by_role('button', name='Open my first conversation').click()
    page.locator('#message-form').wait_for()
    assert page.locator('.tabs a[href*="tab=team"]').count() == 0
    assert page.locator('[data-suggestion], #persona').count() == 0
    assert page.locator('.persona-card[title="Thoughtful teammate"]').count() == 1
    assert page.locator('.persona-card[title="Fixture researcher"]').count() == 1
    disabled=page.locator('.persona-card').evaluate_all('nodes=>nodes.map(n=>n.disabled)')
    assert disabled==sorted(disabled) and any(disabled) and not all(disabled)
    assert page.get_by_role('button',name='AAA unavailable persona').is_disabled()
    page.get_by_role('button', name='Thoughtful teammate').click()
    page.wait_for_function("() => !!window.PHiLLiP.current")
    assert page.locator('.persona-card, #persona').count() == 0
    assert page.locator('.chat-companion .avatar').count() == 1
    assert page.locator('#messages .avatar').count() == 0
    notices=page.evaluate("""() => ({success:PHiLLiPLog.writeSuccess('TTL success fixture').id,info:PHiLLiPLog.writeInfo('TTL info fixture').id,warning:PHiLLiPLog.writeWarning('Persistent warning fixture').id,error:PHiLLiPLog.writeError('Persistent error fixture').id})""")
    page.locator('[data-log-id="'+notices['success']+'"]').wait_for(state='visible')
    page.locator('[data-log-id="'+notices['info']+'"]').wait_for(state='visible')
    page.locator('[data-log-id="'+notices['success']+'"]').wait_for(state='detached', timeout=4500)
    page.locator('[data-log-id="'+notices['info']+'"]').wait_for(state='detached', timeout=6500)
    assert page.evaluate("ids => ids.every(id=>PHiLLiPLog.records.some(r=>r.id===id))", [notices['success'],notices['info']])
    warning=page.locator('[data-log-id="'+notices['warning']+'"]')
    assert warning.is_visible()
    warning.get_by_role('button', name='Dismiss message').click()
    error=page.locator('[data-log-id="'+notices['error']+'"]')
    assert error.is_visible()
    error.get_by_role('button', name='Dismiss message').click()
    page.get_by_role('button', name='Toggle light or dark mode').click()
    page.wait_for_function("() => document.documentElement.dataset.theme === 'dark'")
    page.reload()
    assert page.locator('html').get_attribute('data-theme') == 'dark'
    page.get_by_role('button', name='Toggle light or dark mode').click()
    page.emulate_media(reduced_motion='no-preference')
    page.evaluate("() => { PHiLLiP.emote('This is mind-'); PHiLLiP.emote('blowing!'); }")
    page.locator('.chat-companion .avatar.bsod').wait_for()
    page.evaluate("() => PHiLLiP.emote('😍')")
    page.locator('.chat-companion .avatar.love').wait_for()
    assert page.locator('.chat-companion .avatar-heart-eyes').is_visible()
    page.evaluate("() => PHiLLiP.emote('🙄')")
    page.locator('.chat-companion .avatar.eyeroll').wait_for()
    page.evaluate('PHiLLiP.resetAvatar()')
    thread_id=page.evaluate('PHiLLiP.current.thread.id')
    page.goto(base+'/?view=settings&tab=appearance')
    assert_blinking(page.locator('.avatar-option .avatar'))
    page.goto(base+'/?thread='+str(thread_id))
    assert_blinking(page.locator('.chat-companion .avatar'))
    # Observe real computed mouth movement while actual model deltas arrive.
    for mode in ['COMPLETE','STOP','FAIL','REDUCED']:
        page.emulate_media(reduced_motion='reduce' if mode=='REDUCED' else 'no-preference')
        page.locator('#prompt').fill('AVATAR_STREAM '+mode)
        page.get_by_role('button',name='Send message',exact=True).click()
        page.locator('.chat-companion .avatar.is-talking').wait_for(timeout=30000)
        mouth=page.locator('.chat-companion .avatar-speaking-mouth')
        samples=[]
        for _ in range(6 if mode=='REDUCED' else 5):
            samples.append(mouth.evaluate('(n,soft)=>getComputedStyle(n)[soft?"opacity":"transform"]',mode=='REDUCED'))
            page.wait_for_timeout(100 if mode=='REDUCED' else 90)
        assert len(set(samples))>1 and mouth.is_visible(),samples
        await_refresh=page.evaluate("""async()=>{const n=document.querySelector('.chat-companion .avatar');await PHiLLiP.refreshMessages();return n===document.querySelector('.chat-companion .avatar')&&n.classList.contains('is-talking');}""")
        assert await_refresh, {'mode':mode,'runs':page.evaluate('PHiLLiP.current.runs')}
        if mode=='STOP':
            page.get_by_role('button',name='Stop agent',exact=True).click()
            page.get_by_role('button',name='Stop agent',exact=True).click()
        page.wait_for_function("() => !PHiLLiP.current.runs.some(r=>['running','queued'].includes(r.status))",timeout=30000)
        page.wait_for_function("() => !document.querySelector('.chat-companion .avatar').classList.contains('is-talking')")
        assert mouth.evaluate('n=>getComputedStyle(n).display')=='none'
        assert page.evaluate('PHiLLiP.current.runs.at(-1).status')=={'COMPLETE':'completed','STOP':'stopped','FAIL':'failed','REDUCED':'completed'}[mode]
        if mode=='REDUCED':
            assert page.locator('.chat-companion .avatar-body').evaluate('n=>getComputedStyle(n).animationName')=='none'
            assert_blinking(page.locator('.chat-companion .avatar'),soft=True)
    page.emulate_media(reduced_motion='no-preference')
    assistant_count=page.locator('#messages article.assistant').count()
    for number in range(1, 4):
        page.locator('#prompt').fill('Hello, follow-up ' + str(number))
        page.get_by_role('button', name='Send message', exact=True).click()
        page.wait_for_function("n=>document.querySelectorAll('#messages article.assistant').length >= n", arg=assistant_count+number, timeout=30000)
        page.wait_for_function("() => !document.querySelector('#live-message')", timeout=30000)
    page.wait_for_function("() => document.querySelector('#chat-title').textContent === 'A little local conversation'", timeout=15000)
    assert page.locator('.persona-card, #persona, #messages .avatar').count() == 0
    page.locator('.chat-heading').hover()
    page.get_by_role('button', name='Rename conversation', exact=True).click()
    page.get_by_role('textbox', name='Conversation title', exact=True).fill('My curious conversation')
    page.locator('#rename-form').get_by_role('button', name='Save', exact=True).click()
    page.wait_for_function("() => document.querySelector('#chat-title').textContent === 'My curious conversation'")
    page.locator('.tools-menu > summary').click()
    assert page.locator('#dialog').is_visible() is False
    page.locator('[data-chat-tool][value$="Fetch"]').check()
    page.wait_for_function("() => PHiLLiP.current.thread.settings.tools.some(t=>t.endsWith('Fetch'))")
    page.keyboard.press('Escape')
    page.get_by_role('button', name='Chat settings', exact=True).click()
    page.locator('#chat-settings-form').wait_for()
    assert page.locator('#chat-settings-form [name=tools][value$="Fetch"]').is_checked()
    assert page.locator('#dialog').bounding_box()['x'] > 600
    page.get_by_role('button', name='Close', exact=True).click()
    page.locator('#prompt').fill('Fetch a resource for me.')
    page.get_by_role('button', name='Send message', exact=True).click()
    page.locator('#messages .tool-activity').first.wait_for(timeout=30000)
    page.wait_for_function("() => !document.querySelector('#live-message')", timeout=30000)
    page.locator('#messages .tool-activity').first.locator('summary').click()
    assert page.locator('#messages .tool-activity .tool-fields').count() > 0
    assert 'Content type' in page.locator('#messages .tool-activity').first.inner_text()
    assert page.locator('#messages .tool-activity > pre').count() == 0
    page.locator('#messages .tool-activity').first.scroll_into_view_if_needed()
    page.wait_for_function("() => !document.querySelector('.phillip-message.log-success,.phillip-message.log-info')", timeout=7000)
    page.screenshot(path=str(screenshots / 'chat-light.png'), full_page=True)
    page.get_by_role('button', name='Toggle light or dark mode').click()
    page.wait_for_function("() => !document.querySelector('.phillip-message.log-success')", timeout=4500)
    page.screenshot(path=str(screenshots / 'chat-dark.png'), full_page=True)
    page.set_viewport_size({'width': 390, 'height': 844})
    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
    page.locator('.tools-menu > summary').click()
    assert page.locator('.tools-panel').is_visible()
    page.keyboard.press('Escape')
    page.screenshot(path=str(screenshots / 'chat-mobile.png'), full_page=True)
    page.set_viewport_size({'width': 1440, 'height': 1050})
    thread_id = page.evaluate('PHiLLiP.current.thread.id')
    page.locator(f'.thread-row:has([data-thread="{thread_id}"])').hover()
    page.once('dialog', lambda dialog: dialog.accept())
    page.locator(f'.thread-row:has([data-thread="{thread_id}"]) .thread-delete').click()
    page.wait_for_url('**/chat?new=1')
    assert page.locator(f'[data-thread="{thread_id}"]').count() == 0
    page.get_by_role('button', name='Fixture researcher').click()
    page.wait_for_function("() => !!PHiLLiP.current")
    page.locator('.tools-menu > summary').click()
    assert page.locator('[data-chat-tool][value$="Fetch"]').is_checked()
    page.keyboard.press('Escape')
    page.goto(base + '/?thread=' + str(thread_id))
    assert page.locator('#messages article.assistant').count() >= 3
    assert page.locator('#chat-title').inner_text() == 'My curious conversation'
    # The admin registry updates from PHP fragments on scan completion, without navigation.
    page.goto(base + '/?view=settings&tab=models')
    page.locator('#scan-form [name=cidr]').fill('127.0.0.1/32')
    page.locator('#scan-form [name=ports]').fill(os.environ['ONBOARDING_ENGINE'].rsplit(':', 1)[1])
    page.locator('#scan-form').get_by_role('button', name='Scan network').click()
    page.locator('#scan-status progress').wait_for()
    page.wait_for_function("() => document.querySelector('#scan-status')?.dataset.status === 'completed'", timeout=30000)
    assert 'Refresh' not in page.locator('#scan-status').inner_text()
    assert not errors, errors
    # Ordinary PHP forms remain functional without JavaScript.
    plain = browser.new_context(java_script_enabled=False)
    form = plain.new_page()
    form.goto(base)
    form.locator('[name=username]').fill('browser_admin')
    form.locator('[name=password]').fill('My very own private password 123!')
    form.get_by_role('button', name='Make yourself at home').click()
    form.locator('#message-form').wait_for()
    form.get_by_role('button', name='Thoughtful teammate').click()
    form.locator('#message-form').wait_for()
    assert form.locator('.persona-card, #persona').count() == 0
    form.locator('#prompt').fill('No JavaScript needed.')
    form.get_by_role('button', name='Send message', exact=True).click()
    form.locator('#messages').get_by_text('No JavaScript needed.', exact=True).wait_for()
    print('PASS: usable personas first; independent blinks on login/settings/chat; real mouth animation through streaming/refresh/stop/failure/reduced motion; scan/theme/tools/title/delete/mobile/follow-up/no-JS regressions')
    browser.close()
