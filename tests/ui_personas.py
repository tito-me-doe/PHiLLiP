"""Persona cards in a real browser: the installation-wide adult setting, live preview, ownership, Customize and the companion."""
import os
import re
from playwright.sync_api import sync_playwright, expect

base = os.environ['UP_BASE']
ADULT = {'Mika', 'Roz', 'Vesper'}


def login(page, username):
    page.goto(base + '/')
    page.locator('input[name="username"]').fill(username)
    page.locator('input[name="password"]').fill('phillip-demo')
    page.locator('#login-form button').click()
    page.wait_for_selector('#persona-start .persona-card', timeout=20000)


def names(page):
    return page.eval_on_selector_all('#persona-start .persona-card strong', 'els => els.map(e => e.textContent.trim())')


def main():
    errors = []
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        alice_page = browser.new_context(viewport={'width': 1280, 'height': 1400}).new_page()
        admin_page = browser.new_context(viewport={'width': 1280, 'height': 1000}).new_page()
        for page in (alice_page, admin_page):
            page.on('pageerror', lambda error: errors.append(str(error)))
            page.route(re.compile(r'https?://127\.0\.0\.1:1/'), lambda route: route.abort())
        try:
            # Adult-oriented personas are off on a new installation; New Persona is last.
            login(alice_page, 'alice')
            shown = names(alice_page)
            assert not (ADULT & set(shown)), shown
            assert shown[0] == 'PHiLLiP' and shown[-1] == 'New Persona' and len(shown) == 9, shown
            assert alice_page.locator('.persona-badge').count() == 0, 'no 18+ badge while they are off'

            # Only an administrator sees the setting; saving it applies to everyone at once.
            alice_page.goto(base + '/personas')
            alice_page.wait_for_selector('table.persona-table.registry')
            assert alice_page.locator('#persona-adult-form').count() == 0, 'an ordinary user must not see the adult setting'
            login(admin_page, 'admin')
            admin_page.goto(base + '/personas')
            admin_page.wait_for_selector('#persona-adult-form')
            admin_page.locator('#persona-adult-form input[role=switch]').check()
            admin_page.wait_for_timeout(1500)
            alice_page.goto(base + '/')
            alice_page.wait_for_selector('#persona-start .persona-card')
            shown = names(alice_page)
            assert ADULT <= set(shown) and len(shown) == 12, shown
            assert alice_page.locator('.persona-badge').count() == 3, 'exactly the three adult personas carry a badge'

            # Switches: administrators switch shipped personas on and off; an ordinary person sees none for them.
            alice_page.goto(base + '/personas')
            alice_page.wait_for_selector('table.persona-table.registry')
            assert alice_page.locator('#personas form[data-persona-enable]').count() == 0, 'no switches for personas a person cannot edit'
            admin_page.goto(base + '/personas')
            admin_page.wait_for_selector('#personas form[data-persona-enable]')
            assert admin_page.locator('#experts').count() == 1, 'experts have their own section'
            admin_page.locator('#personas tr', has=admin_page.locator('strong', has_text='Wren')).locator('input[role=switch]').uncheck()
            admin_page.wait_for_timeout(1500)
            admin_page.goto(base + '/personas')
            admin_page.wait_for_selector('#personas tr.is-off')
            assert admin_page.locator('#personas tr.is-off strong').all_inner_texts() == ['Wren'], 'a switched-off persona is dimmed'
            alice_page.goto(base + '/')
            alice_page.wait_for_selector('#persona-start .persona-card')
            assert 'Wren' not in names(alice_page), 'a switched-off persona cannot be chosen'
            admin_page.locator('#personas tr', has=admin_page.locator('strong', has_text='Wren')).locator('input[role=switch]').check()
            admin_page.wait_for_timeout(1500)

            # New Persona: a live preview follows what is typed and chosen.
            alice_page.locator('.persona-new').click()
            alice_page.wait_for_selector('#persona-form')
            alice_page.locator('#persona-form [name=name]').fill('Juniper')
            alice_page.locator('#persona-form [name=pronouns]').fill('she/her')
            alice_page.locator('#persona-form [name=tagline]').fill('A gentle gardener.')
            # The dressing room: arrows change the body, the pill changes the face, keys and swipes work too.
            stage = alice_page.locator('.dressing-room .stage')
            def step(kind, by, until):
                for _ in range(400):
                    if until():
                        return
                    stage.locator(f'[data-step={kind}][data-by="{by}"]').first.click()
                raise AssertionError('never reached ' + kind)
            body_label = lambda: alice_page.locator('.stage-label').inner_text().split(' ·')[0]
            step('body', 1, lambda: body_label().lower() == 'a cheerful leafy companion')
            step('face', 1, lambda: alice_page.locator('.face-name').inner_text() == 'Sprout')
            alice_page.wait_for_function("() => document.querySelector('.dressing-room input[name=avatar]').value === 'sprout' && document.querySelector('.dressing-room .avatar').dataset.avatar === 'sprout'")
            assert alice_page.locator('.dressing-room .avatar svg .avatar-body').count() == 1, 'the avatar is composed on the server'
            stage.focus(); alice_page.keyboard.press('ArrowRight')
            alice_page.wait_for_function("() => document.querySelector('.dressing-room input[name=avatar]').value.includes('.sprout')")
            stage.focus(); alice_page.keyboard.press('ArrowLeft')
            alice_page.wait_for_function("() => document.querySelector('.dressing-room input[name=avatar]').value === 'sprout'")
            preview = alice_page.locator('.persona-preview')
            expect(preview).to_contain_text('Juniper')
            expect(preview).to_contain_text('she/her')
            expect(preview).to_contain_text('A gentle gardener.')
            assert alice_page.locator('.persona-preview .avatar').get_attribute('data-avatar') == 'sprout'
            assert alice_page.locator('#persona-form input[type=checkbox][name=maturity]').count() == 1, 'the adult option is offered when the installation allows it'
            alice_page.locator('#persona-form [name=bio]').fill('Tends a rooftop garden and treats problems like seedlings.')
            alice_page.locator('#persona-form button.primary').click()
            alice_page.wait_for_selector('#persona-start .persona-card')
            slot = alice_page.locator('.persona-slot', has=alice_page.locator('strong', has_text='Juniper'))
            expect(slot.locator('.persona-owner')).to_have_text('Yours')
            assert slot.locator('.row-action').count() == 1, 'a persona you made has an edit shortcut'
            assert alice_page.locator('.persona-slot', has=alice_page.locator('strong', has_text='Dave')).locator('.row-action').count() == 0, 'a shipped persona has none for an ordinary user'
            slot.locator('.row-action').click()
            alice_page.wait_for_selector('#persona-form')
            assert alice_page.locator('#persona-form [name=name]').input_value() == 'Juniper'
            alice_page.keyboard.press('Escape')

            # A person's own persona has a switch for them.
            alice_page.goto(base + '/personas')
            alice_page.wait_for_selector('table.persona-table.registry')
            assert alice_page.locator('#personas form[data-persona-enable]').count() == 1, 'only their own persona carries a switch'

            # Customize: your own copy of a persona you may not edit.
            alice_page.goto(base + '/personas')
            alice_page.wait_for_selector('table.persona-table.registry')
            alice_page.locator('tr', has=alice_page.locator('strong', has_text='Dave')).locator('[data-persona-copy]').click()
            alice_page.wait_for_timeout(1500)
            alice_page.goto(base + '/personas')
            alice_page.wait_for_selector('table.persona-table.registry')
            assert alice_page.locator('tr', has=alice_page.locator('strong', has_text='Dave (my version)')).count() == 1

            # The companion is whoever the conversation is with, in their own voice.
            alice_page.goto(base + '/')
            alice_page.wait_for_selector('#persona-start .persona-card')
            alice_page.locator('#persona-start .persona-card[aria-label^="Start a conversation with Roz"]').click()
            alice_page.wait_for_selector('#message-form', timeout=15000)
            alice_page.wait_for_timeout(1000)
            companion = alice_page.locator('.chat-companion')
            assert companion.get_attribute('data-name') == 'Roz'
            assert companion.locator('.avatar').get_attribute('data-avatar') == 'roz'
            expect(alice_page.locator('.companion-bubble')).to_have_text('Whaddaya need, honey?')
            assert 'Now THAT' in companion.get_attribute('data-reactions'), 'her own reactions travel with the conversation'
            assert not errors, errors
            print('PASS: adult setting, live preview, ownership, Customize and the persona companion', flush=True)
        finally:
            browser.close()


main()
