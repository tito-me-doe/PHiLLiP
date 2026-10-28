"""Real model/browser regression: flat personas, copied defaults, and repeated PHP refreshes."""
import json
import os
import time
from playwright.sync_api import sync_playwright

BASE = os.environ.get('PHILLIP_URL', 'http://127.0.0.1:8088')
NAME = 'Orion persona fixture ' + str(time.time_ns())

with sync_playwright() as p:
    browser = p.chromium.launch()
    page = browser.new_page()
    errors = []
    page.on('pageerror', lambda error: errors.append(str(error)))
    page.goto(BASE)
    page.locator('[name=username]').fill('admin')
    page.locator('[name=password]').fill('phillip-demo')
    page.locator('#login-form button').click()
    page.locator('#message-form').wait_for()
    persona_id = None
    thread_id = None
    try:
        page.goto(BASE + '/?view=settings&tab=personas')
        page.get_by_role('button', name='New persona', exact=True).click()
        form = page.locator('#persona-form')
        form.locator('[name=name]').fill(NAME)
        form.locator('[name=prompt]').fill('Be concise. The remembered word is COBALT. Use Fetch when the user asks for the health endpoint. Return the requested answer field.')
        form.locator('[name=tools]').fill('Fetch')
        form.locator('[name=contract]').fill('{"answer":{"type":"string"}}')
        form.locator('button.primary').click()
        page.wait_for_function('name => window.PHiLLiP?.state.personas.some(p=>p.name===name)', arg=NAME)
        persona = page.evaluate('name => PHiLLiP.state.personas.find(p=>p.name===name)', NAME)
        persona_id = persona['id']
        assert persona['tools'] == ['Agent\\Tools\\Fetch']
        assert persona['contract'] == {'answer': {'type': 'string'}}
        print('PASS: PHP persona editor saves name, freeform prompt, named tools and contract', flush=True)

        page.goto(BASE)
        page.get_by_role('button', name='Chat settings', exact=True).click()
        page.locator('#dialog').wait_for(state='visible')
        page.locator('#chat-settings-form [name=profile]').select_option(os.environ.get('PHILLIP_PROFILE', 'nemotron-vl-work'))
        page.locator('#chat-settings-form [name=max_tokens]').fill('32768')
        page.get_by_role('button', name='Save settings', exact=True).click()
        page.locator('#dialog').wait_for(state='hidden')
        page.locator('#persona').select_option(str(persona_id))
        page.wait_for_function('name => PHiLLiP.newSettings?.assistant_name===name', arg=NAME)
        assert page.evaluate('PHiLLiP.newSettings.tools') == ['Agent\\Tools\\Fetch']
        assert page.evaluate('PHiLLiP.newSettings.contract') == {'answer': {'type': 'string'}}
        page.get_by_role('button', name='Chat settings', exact=True).click()
        page.locator('#dialog').wait_for(state='visible')
        assert page.locator('#chat-settings-form [name=assistant_name]').input_value() == NAME
        assert json.loads(page.locator('#chat-settings-form [name=contract]').input_value()) == persona['contract']
        page.locator('#dialog [aria-label=Close]').click()

        def send(text, marker):
            page.locator('#prompt').fill(text)
            page.locator('#prompt').press('Enter')
            page.wait_for_function("marker => PHiLLiP.current?.runs.at(-1)?.status==='completed' && [...document.querySelectorAll('.message.assistant .message-body')].some(n=>n.textContent.includes(marker))", arg=marker, timeout=240000)
            message = page.locator('.message.assistant').last
            answer = json.loads(message.locator('.message-body').inner_text())['answer']
            assert marker in answer
            assert NAME in message.locator('.message-head').inner_text()
            assert page.locator('#send-button').is_enabled()
            return answer

        first = send('Return TURN_ONE and your name in the answer.', 'TURN_ONE')
        thread_id = page.evaluate('PHiLLiP.current.thread.id')
        assert NAME in first
        second = send('Use Fetch to get http://phillip.internal:8080/health. Return TURN_TWO and its PHP version in the answer.', 'TURN_TWO')
        assert '8.5' in second, second
        assert page.locator('details.activity summary').filter(has_text='Fetch').count() > 0
        third = send('Return TURN_THREE and the remembered word from your system instructions.', 'TURN_THREE')
        assert 'COBALT' in third
        print('PASS: Three real-model follow-ups retain persona name, tools, contract and system prompt after PHP replacements', flush=True)

        before = page.evaluate("async()=>{const t=await PHiLLiP.api('/api/threads/'+PHiLLiP.current.thread.id);return t.messages.find(m=>m.role==='system').raw.content}")
        page.evaluate("async id=>await PHiLLiP.api('/api/personas',{id,name:'Edited persona fixture',prompt:'Different prompt',tools:[],contract:null})", persona_id)
        after = page.evaluate("async()=>{const t=await PHiLLiP.api('/api/threads/'+PHiLLiP.current.thread.id);return t.messages.find(m=>m.role==='system').raw.content}")
        assert before == after
        fourth = send('Return TURN_FOUR and the remembered word again.', 'TURN_FOUR')
        assert 'COBALT' in fourth
        page.get_by_role('button', name='Chat settings', exact=True).click()
        page.locator('#dialog').wait_for(state='visible')
        assert json.loads(page.locator('#chat-settings-form [name=contract]').input_value()) == persona['contract']
        page.locator('#chat-settings-form [name=contract]').fill('')
        page.get_by_role('button', name='Save settings', exact=True).click()
        page.locator('#dialog').wait_for(state='hidden')
        assert page.evaluate('PHiLLiP.current.thread.settings.contract') is None
        assert page.evaluate('PHiLLiP.current.thread.settings.persona_defaults.contract') is None
        assert not errors, errors
        print('PASS: Editing the source persona leaves copied conversations intact; thread contracts remain editable; no JavaScript errors', flush=True)
    finally:
        if page.evaluate('!!window.PHiLLiP'):
            if thread_id:
                page.evaluate("async id=>await PHiLLiP.api('/api/threads/'+id+'/delete',{})", thread_id)
            if persona_id:
                page.evaluate("async id=>await PHiLLiP.api('/api/personas/'+id+'/delete',{})", persona_id)
        browser.close()
