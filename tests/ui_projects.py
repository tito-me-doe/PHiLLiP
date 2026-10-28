"""Real-model Projects regression: browser forms, durable orchestration, and gates."""
import os
import time
from pathlib import Path
from urllib.parse import parse_qs, urlparse
from playwright.sync_api import sync_playwright

BASE = os.environ.get('PHILLIP_URL', 'http://127.0.0.1:8088')
ROOT = Path(__file__).resolve().parents[1]
MARKER = 'Browser project ' + str(time.time_ns())


def wait_until(page, callback, description, timeout=120):
    deadline = time.monotonic() + timeout
    last = None
    while time.monotonic() < deadline:
        last = callback()
        if last:
            return last
        page.wait_for_timeout(200)
    raise AssertionError('Timed out: ' + description + '; last observation: ' + str(last))


with sync_playwright() as playwright:
    browser = playwright.chromium.launch()
    context = browser.new_context(viewport={'width': 1440, 'height': 1100})
    page = context.new_page()
    errors = []
    context.on('page', lambda new_page: new_page.on('pageerror', lambda error: errors.append(str(error))))
    page.on('pageerror', lambda error: errors.append(str(error)))
    project_id, personas, csrf = None, [], None
    page.goto(BASE)
    page.locator('[name=username]').fill('admin')
    page.locator('[name=password]').fill('phillip-demo')
    page.locator('#login-form button').click()
    page.locator('#message-form').wait_for()
    page.wait_for_function('() => !!window.PHiLLiP')
    csrf = page.evaluate('PHiLLiP.state.csrf')

    def api(path, body=None):
        response = context.request.get(BASE + path) if body is None else context.request.post(BASE + path, data=body, headers={'X-CSRF-Token': csrf})
        data = response.json()
        assert response.ok and 'error' not in data, str(response.status) + ': ' + str(data)
        return data

    def snapshot():
        return api('/api/projects/' + str(project_id))['snapshot']

    def submit(form, button_name=None):
        route = form.get_attribute('data-project-api')
        page.evaluate('window.beforeProjectRoot=document.querySelector("[data-project-root]")')
        with page.expect_response(lambda response: response.request.method == 'POST' and response.url.split('?')[0] == BASE + route) as pending:
            if button_name:
                form.get_by_role('button', name=button_name, exact=True).click()
            else:
                form.locator('button[type=submit]').click()
        response = pending.value
        if route == '/api/projects':
            page.wait_for_url('**/*project=*')
            created_id = int(parse_qs(urlparse(page.url).query)['project'][0])
            result = api('/api/projects/' + str(created_id))
        else:
            result = response.json()
            assert response.ok and 'error' not in result, str(result)
            page.wait_for_function('() => document.querySelector("[data-project-root]")!==window.beforeProjectRoot')
        return result

    def edit(todo_id, changes):
        panel = page.locator('[data-project-panel="edit:' + str(todo_id) + '"]')
        if not panel.get_attribute('open') == '':
            panel.locator('summary').first.click()
        form = page.locator('form[data-project-form="todo:' + str(todo_id) + '"]')
        for name, value in changes.items():
            if name == 'persona_id':
                form.locator('[name=' + name + ']').select_option(str(value))
            else:
                form.locator('[name=' + name + ']').fill(value)
        submit(form)

    try:
        for role in ['Architect', 'Code Researcher']:
            persona = api('/api/personas', {'name': MARKER + ' ' + role, 'prompt': 'You are a concise ' + role + '. Solve the supplied elementary arithmetic task precisely. Return the requested answer tokens and one short calculation. No external tools or research are needed.', 'tools': [], 'visibility': 'private'})['persona']
            personas.append(persona['id'])
        page.get_by_role('link', name='Projects', exact=False).first.click()
        page.locator('form[data-project-form=create]').wait_for()
        profiles = page.locator('form[data-project-form=create] [name=profile] option').evaluate_all('(nodes)=>nodes.map(node=>node.value)')
        profile = os.environ.get('PHILLIP_PROFILE') or next((candidate for candidate in ['nemotron-vl-fast', 'nemotron-vl-work'] if candidate in profiles), None)
        assert profile in profiles, 'Enable nemotron-vl-fast/work or set PHILLIP_PROFILE before this real-model test'
        create = page.locator('form[data-project-form=create]')
        create.locator('[name=title]').fill(MARKER)
        create.locator('[name=profile]').select_option(profile)
        result = submit(create)
        project_id = int(result['project']['id'])
        page.locator('[data-project-id="' + str(project_id) + '"]').wait_for()
        # Bound actual task replies while keeping all model calls real.
        api('/api/projects/' + str(project_id) + '/settings', {'settings': {'parameters': {'temperature': 0, 'max_tokens': 1024, 'stream': True}}})
        page.reload()
        parent_form = page.locator('form[data-project-form="add:root"]')
        parent_form.locator('[name=title]').fill('Architect: double the child result')
        submit(parent_form)
        parent_id = int(snapshot()['todos'][0]['id'])
        submit(page.locator('form[data-project-form="preflight:"]'))
        assert snapshot()['project']['preflight_status'] == 'gaps'
        assert page.get_by_role('button', name='Start Project', exact=True).is_disabled()
        assert snapshot()['project']['status'] == 'planning'
        print('PASS: Browser creates a project; incomplete drafts show gaps and disabled Start', flush=True)

        # A focused unsaved editor must survive at least two server-fragment replacements.
        page.locator('[data-project-panel="edit:' + str(parent_id) + '"] > summary').click()
        draft = page.locator('form[data-project-form="todo:' + str(parent_id) + '"] [name=goal]')
        draft.fill('Unsaved goal survives polling')
        draft.focus()
        draft.evaluate('(node)=>{window.projectDraft=node;window.projectRefreshes=0;new MutationObserver(()=>{window.projectRefreshes++;}).observe(document.querySelector("#workspace"),{childList:true});}')
        wait_until(page, lambda: page.evaluate('window.projectRefreshes>=2'), 'two PHP project refreshes', 15)
        assert page.evaluate('document.activeElement===window.projectDraft && window.projectDraft.isConnected')
        assert draft.input_value() == 'Unsaved goal survives polling'
        edit(parent_id, {'goal': 'Double the completed child sum to get 24.', 'requirements': 'Use the child result 12. Compute 2 * 12 = 24. Include the exact token PROJECT_TOTAL=24 in your final answer.', 'context': 'This is a self-contained arithmetic exercise. A child ToDo computes 7 + 5 = 12 first. The parent uses that completed result; no external inputs or tools are required.', 'gates': 'The final answer contains PROJECT_TOTAL=24.\nThe final answer states the calculation 2 * 12 = 24 or an equivalent doubling calculation.', 'persona_id': personas[0]})
        # Add a nested child through a handler installed before the previous replacement.
        page.locator('[data-project-panel="add:' + str(parent_id) + '"] > summary').click()
        child_form = page.locator('form[data-project-form="add:' + str(parent_id) + '"]')
        for name, value in {'title': 'Code Researcher: add seven and five', 'goal': 'Compute the elementary sum 7 + 5.', 'requirements': 'Compute 7 + 5 = 12. Include the exact token PROJECT_CHILD=12 in the final answer. State the arithmetic briefly.', 'context': 'All input is given: the integers 7 and 5. Their sum supplies the parent task. No tools, files, networking, or external facts are needed.', 'gates': 'The final answer contains PROJECT_CHILD=12.\nThe final answer correctly gives 7 + 5 = 12.'}.items():
            child_form.locator('[name=' + name + ']').fill(value)
        child_form.locator('[name=persona_id]').select_option(str(personas[1]))
        submit(child_form)
        child_id = int(next(todo['id'] for todo in snapshot()['todos'] if todo['parent_id']))
        edit(child_id, {'context': 'Inputs are precisely the decimal integers 7 and 5. Add them to obtain 12 for the parent task. No tools or outside context are needed.'})
        assert page.locator('#todo-' + str(parent_id) + ' .project-children #todo-' + str(child_id)).count() == 1
        assert not snapshot()['issues']
        print('PASS: Dirty draft and focus survive two polls; multiple edited PHP forms and nested child remain interactive', flush=True)

        deadline = time.monotonic() + 180

        def ready():
            state = snapshot()
            status = state['project']['preflight_status']
            assert status not in ['gaps', 'error'], str(state['project']['preflight_result'])
            assert state['project']['status'] == 'planning', 'Preflight started execution without a user action'
            assert all(not todo['run_id'] for todo in state['todos']), 'Preflight dispatched a ToDo'
            return state if status == 'ready' else None

        submit(page.locator('form[data-project-form="preflight:"]'))
        wait_until(page, ready, 'real model preflight ready', min(120, max(1, deadline - time.monotonic())))
        wait_until(page, lambda: page.get_by_role('button', name='Start Project', exact=True).is_enabled(), 'Start enabled after ready', 10)
        edit(child_id, {'requirements': 'Compute 7 + 5 = 12 using ordinary integer addition. Include PROJECT_CHILD=12 and show that addition in the final answer.'})
        assert snapshot()['project']['preflight_status'] == 'unchecked'
        assert page.get_by_role('button', name='Start Project', exact=True).is_disabled()
        submit(page.locator('form[data-project-form="preflight:"]'))
        wait_until(page, ready, 'second real model preflight ready', min(120, max(1, deadline - time.monotonic())))
        wait_until(page, lambda: page.get_by_role('button', name='Start Project', exact=True).is_enabled(), 'Start enabled again', 10)
        print('PASS: Real readiness check stays planning; editing invalidates readiness and a fresh check still requires Start', flush=True)
        submit(page.locator('form[data-project-form="start:"]'))
        assert snapshot()['project']['status'] in ['running', 'completed']
        saved_url = page.url
        page.close()
        page = context.new_page()
        page.goto(saved_url)
        page.locator('[data-project-id="' + str(project_id) + '"]').wait_for()
        print('PASS: Explicit Start survives closing and reopening the browser page', flush=True)

        def finished():
            state = snapshot()
            assert state['project']['status'] != 'blocked', str(state)
            parent = next(todo for todo in state['todos'] if int(todo['id']) == parent_id)
            child = next(todo for todo in state['todos'] if int(todo['id']) == child_id)
            if parent['status'] in ['working', 'review', 'done']:
                assert child['status'] == 'done', 'Parent executed before the child passed all gates'
            return state if state['project']['status'] == 'completed' else None

        final = wait_until(page, finished, 'both real ToDos and independent gate reviews complete', min(120, max(1, deadline - time.monotonic())))
        assert final['progress']['done'] == final['progress']['total'] == 2
        for todo in final['todos']:
            assert todo['status'] == 'done' and todo['result'].strip()
            assert len(todo['review']['gates']) == len(todo['gates'])
            assert all(gate['passed'] is True and gate['evidence'].strip() for gate in todo['review']['gates']), str(todo)
        events = api('/api/projects/' + str(project_id) + '/history')['events']
        child_done = next(int(event['id']) for event in events if event['type'] == 'todo.done' and int(event['todo_id']) == child_id)
        parent_dispatch = next(int(event['id']) for event in events if event['type'] == 'todo.dispatched' and int(event['todo_id']) == parent_id)
        assert child_done < parent_dispatch
        wait_until(page, lambda: page.locator('.project-summary').inner_text().find('2 of 2 ToDos done') >= 0, 'PHP completed progress display', 10)
        assert page.get_by_text('PROJECT_TOTAL=24', exact=False).count() > 0
        assert page.get_by_text('Result and gate evidence', exact=True).count() == 2
        assert not errors, errors
        page.screenshot(path=str(ROOT / 'storage/ui-projects.png'), full_page=True)
        print('PASS: Child completes before parent; both real outputs pass every gate and PHP shows completed progress/evidence', flush=True)
        print('Observed profile ' + profile + ', two distinct personas, two independent reviews, and no browser JavaScript errors', flush=True)
    finally:
        if not project_id:
            created = next((project for project in api('/api/projects')['projects'] if project['title'] == MARKER), None)
            if created:
                project_id = int(created['id'])
        if project_id:
            state = api('/api/projects/' + str(project_id))['snapshot']
            if state['project']['status'] != 'completed':
                api('/api/projects/' + str(project_id) + '/pause', {})
            cleanup_deadline = time.monotonic() + 30
            while time.monotonic() < cleanup_deadline:
                state = snapshot()
                active = []
                for todo in state['todos']:
                    if todo['thread_id']:
                        active.extend(run for run in api('/api/threads/' + str(todo['thread_id']))['runs'] if run['status'] in ['queued', 'running'])
                if not active and state['project']['preflight_status'] != 'checking':
                    break
                time.sleep(0.25)
            api('/api/projects/' + str(project_id) + '/delete', {})
        for persona_id in personas:
            api('/api/personas/' + str(persona_id) + '/delete', {})
        browser.close()
