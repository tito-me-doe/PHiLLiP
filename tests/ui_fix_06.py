import os
from playwright.sync_api import sync_playwright, expect

base = os.environ['FIX06_BASE']
username = os.environ['FIX06_USERNAME']
password = os.environ['FIX06_PASSWORD']
childThread = os.environ['FIX06_CHILD_THREAD']
planningThread = os.environ['FIX06_PLANNING_THREAD']


def main():
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        page = browser.new_page(viewport={"width": 1440, "height": 1000})
        errors = []
        page.on("pageerror", lambda error: errors.append(str(error)))

        # Log in via the browser.
        page.goto(base + "/")
        page.locator('input[name="username"]').fill(username)
        page.locator('input[name="password"]').fill(password)
        page.locator('#login-form button').click()
        page.wait_for_selector('#message-form', timeout=10000)

        # --- Child execution thread: read-only ---
        page.goto(f"{base}/?thread={childThread}")
        page.wait_for_selector('#message-form', timeout=10000)

        # Read-only banner is visible with a link to the project plan.
        expect(page.locator('.read-only-banner')).to_be_visible()
        expect(page.locator('.read-only-banner a.button')).to_have_text('Open project plan')

        # Composer controls are disabled.
        expect(page.locator('#prompt')).to_be_disabled()
        expect(page.locator('[data-action="attach"]')).to_be_disabled()
        expect(page.locator('#files')).to_be_disabled()
        expect(page.locator('#send-button')).to_be_disabled()
        # No interactive tools menu on read-only history.
        expect(page.locator('details.tools-menu')).to_have_count(0)

        # --- Planning thread: writable ---
        page.goto(f"{base}/?thread={planningThread}")
        page.wait_for_selector('#message-form', timeout=10000)

        # No read-only banner on the planning thread.
        expect(page.locator('.read-only-banner')).to_have_count(0)

        # Composer controls are enabled.
        expect(page.locator('#prompt')).not_to_be_disabled()
        expect(page.locator('[data-action="attach"]')).not_to_be_disabled()
        expect(page.locator('#files')).not_to_be_disabled()
        # Interactive tools menu is present on writable planning thread.
        tools = page.locator('details.tools-menu')
        expect(tools).to_have_count(1)
        expect(tools.locator('summary')).to_be_visible()
        expect(page.locator('#send-button')).not_to_be_disabled()

        assert not errors, errors
        print('PASS: Read-only execution thread and writable planning thread verified', flush=True)
        browser.close()


main()
