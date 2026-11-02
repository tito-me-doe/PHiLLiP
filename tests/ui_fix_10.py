import os
from playwright.sync_api import sync_playwright, expect

BASE = os.environ['FIX10_BASE']
USERNAME = os.environ['FIX10_USERNAME']
PASSWORD = os.environ['FIX10_PASSWORD']
VIEWPORTS = [(1440, 1000), (390, 844)]


def main():
    with sync_playwright() as p:
        for vw, vh in VIEWPORTS:
            browser = p.chromium.launch(headless=True)
            page = browser.new_context(viewport={'width': vw, 'height': vh}).new_page()
            errors = []
            page.on('pageerror', lambda err: errors.append(str(err)))
            try:
                page.goto(BASE + '/')
                page.wait_for_selector('input[name="username"]', timeout=10000)
                page.locator('input[name="username"]').fill(USERNAME)
                page.locator('input[name="password"]').fill(PASSWORD)
                page.locator('#login-form button').click()

                # A new chat shows only the persona cards: no composer and no companion until someone is chosen.
                page.wait_for_selector('#persona-start .persona-card', timeout=10000)
                assert not page.locator('.composer').is_visible(), 'no composer before a persona is chosen'
                assert not page.locator('.chat-companion').is_visible(), 'no companion before a persona is chosen'
                page.locator('#persona-start .persona-card[name=persona_id]').first.click()

                # The companion lives in Chat, perched on the composer, and nowhere else.
                page.wait_for_selector('.composer > .chat-companion', state='visible', timeout=10000)
                box = page.locator('.chat-companion').bounding_box()
                composer = page.locator('.composer').bounding_box()
                assert box['y'] < composer['y'] and box['y'] + box['height'] > composer['y'], 'the companion sits on the composer edge'
                assert box['width'] >= (100 if vw > 600 else 70), f'the companion is prominent ({box["width"]}px)'
                page.mouse.click(box['x'] + box['width'] / 2, box['y'] + box['height'] / 2)
                assert page.locator('.chat-companion').evaluate("el => el.classList.contains('reacting')"), 'clicking the companion reacts'

                for path in ['/access', '/models', '/personas', '/memory', '/benchmarks']:
                    page.goto(BASE + path)
                    page.wait_for_selector('#workspace')
                    assert page.locator('.chat-companion').count() == 0, f'{path}: no companion outside Chat'
                    assert page.evaluate('document.documentElement.scrollWidth') <= vw + 2, f'{path}: page widened horizontally'

                # Row actions are plain, clickable icons.
                page.goto(BASE + '/access')
                page.wait_for_selector('.table-wrap .registry tbody tr', timeout=10000)
                anchor = page.locator('.table-wrap .registry tbody tr a.button.row-action[data-editor]').last
                anchor.scroll_into_view_if_needed()
                anchor.click()
                expect(page.locator('#dialog[open]')).to_be_visible(timeout=5000)
                page.locator('#dialog a[data-close-editor]').first.click()
                expect(page.locator('#dialog:not([open])')).to_be_hidden(timeout=5000)

                assert not errors, errors
                print(f'PASS: companion stays in Chat at {vw}x{vh}', flush=True)
            finally:
                browser.close()


main()
