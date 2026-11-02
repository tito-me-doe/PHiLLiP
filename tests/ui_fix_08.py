"""Issue08: shared profile form without JavaScript (native POST CRUD) and shared modal with JavaScript.

JS-off: New opens the native shared form, creates a disabled profile, Edit opens the same
form and updates it. Navigation after each native POST save is awaited and the shared
form must disappear. JS-on: New/Edit open the shared #dialog #profile-form modal; the
real close button closes both modals before the hidden wait. No production or routing
changes; the canonical PHP DB check verifies the browser-created fix-chat-v2 row.
"""
import json
import sys
from playwright.sync_api import sync_playwright, expect

base = sys.argv[1]
NAME = 'fix08-browser-disabled'
CONFIG = json.dumps(
    {'url': 'http://127.0.0.1:1/v1/chat/completions', 'model': 'fix-chat', 'stream': True}
)
CONFIG2 = json.dumps(
    {
        'url': 'http://127.0.0.1:1/v1/chat/completions',
        'model': 'fix-chat-v2',
        'stream': True,
    }
)

# The profiles registry table is uniquely headed "Profile / model".
PROFILES = '#profiles table.registry'


def login(page):
    page.goto(base)
    page.locator('[name=username]').fill('alice')
    page.locator('[name=password]').fill('phillip-demo')
    page.locator('#login-form button').click()
    # Wait for the logged-in shell so the post-login redirect cannot race ahead
    # of the browser and surface ERR_ABORTED navigations.
    expect(page.locator('.shell')).to_be_visible()


def open_models(page):
    page.goto(base + '/models')
    page.wait_for_url(base + '/models')
    expect(page.locator('#profiles table.registry')).to_be_visible()


with sync_playwright() as p:
    browser = p.chromium.launch(headless=True)
    errors = []

    # ---- JavaScript disabled: native shared form CRUD (real browser, no JS) ----
    ctx = browser.new_context(java_script_enabled=False)
    page = ctx.new_page()
    page.on("pageerror", lambda e: errors.append(str(e)))

    login(page)
    open_models(page)

    # New profile -> native shared form (anchor navigates with JS off)
    page.locator('[data-dialog=profile]:not([data-name])').click()
    expect(page.locator('#profile-form')).to_be_visible()
    form = page.locator('#profile-form')
    assert form.locator('[name=action]').count() >= 1, 'form carries action field'
    assert form.locator('[name=csrf]').count() >= 1, 'form carries CSRF field'
    for field in ['name', 'config', 'enabled', 'overrides']:
        assert (
            form.locator('[name=' + field + ']').count() >= 1
        ), 'form exposes ' + field
    page.locator('#profile-form [name=name]').fill(NAME)
    page.locator('#profile-form [name=config]').fill(CONFIG)
    # Enabled deliberately left unchecked -> disabled profile, no capability probe.
    page.locator('#profile-form button.primary').click()
    page.wait_for_url(base + '/models')
    expect(page.locator('#profile-form')).not_to_be_visible()
    row = page.locator(PROFILES + ' tr:has-text("' + NAME + '")')
    assert row.count() >= 1, 'created profile appears in the registry'

    # Edit -> same native shared form, prefilled, then update
    page.locator('[data-dialog=profile][data-name="' + NAME + '"]').click()
    expect(page.locator('#profile-form')).to_be_visible()
    name_input = page.locator('#profile-form [name=name]')
    assert name_input.input_value() == NAME, 'edit form prefills the profile name'
    assert (
        name_input.get_attribute('readonly') is not None
    ), 'edit name is read-only'
    page.locator('#profile-form [name=config]').fill(CONFIG2)
    page.locator('#profile-form button.primary').click()
    page.wait_for_url(base + '/models')
    expect(page.locator('#profile-form')).not_to_be_visible()
    assert (
        page.locator(PROFILES + ' tr:has-text("' + NAME + '")').count() >= 1
    ), 'edited profile still present'
    assert (
        page.locator(PROFILES + ' tr:has-text("fix-chat-v2")').count() >= 1
    ), 'registry reflects the updated model fix-chat-v2'
    assert not errors, errors
    print('PASS: JS-disabled native New+Edit profile CRUD via the shared form', flush=True)
    ctx.close()

    # ---- JavaScript enabled: New and Edit open the shared #dialog modal ----
    ctx2 = browser.new_context()
    page2 = ctx2.new_page()
    page2.on("pageerror", lambda e: errors.append(str(e)))

    login(page2)
    open_models(page2)
    page2.locator('[data-dialog=profile]:not([data-name])').click()
    expect(page2.locator('#dialog')).to_be_visible()
    expect(page2.locator('#dialog #profile-form')).to_be_visible()
    page2.locator('#dialog [data-action=close-dialog]').click()
    expect(page2.locator('#dialog')).to_be_hidden()

    page2.locator('[data-dialog=profile][data-name="' + NAME + '"]').click()
    expect(page2.locator('#dialog')).to_be_visible()
    assert (
        page2.locator('#dialog #profile-form [name=name]').input_value() == NAME
    ), 'edit modal prefills the profile name'
    page2.locator('#dialog [data-action=close-dialog]').click()
    expect(page2.locator('#dialog')).to_be_hidden()
    assert not errors, errors
    print('PASS: JS-enabled New/Edit open the shared #dialog #profile-form modal', flush=True)
    ctx2.close()
    browser.close()

print('ALL PASS', flush=True)
