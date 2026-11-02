"""Browser coverage for focused service and parser editors, with and without JS."""
import sys
from playwright.sync_api import sync_playwright, expect

base = sys.argv[1]
raw = 'http://127.0.0.1:9713/browser-fallback'
resolved = 'http://127.0.0.1:9714/env'


def login(page):
    page.goto(base)
    page.locator('[name=username]').fill('alice')
    page.locator('[name=password]').fill('phillip-demo')
    page.locator('#login-form button').click()
    expect(page.locator('.shell')).to_be_visible()
    page.goto(base + '/models')
    expect(page.locator('#services')).to_be_visible()


def service(page):
    page.locator('[data-dialog=services][data-name=searxng]').click()
    form = page.locator('#service-form')
    expect(form).to_be_visible()
    assert form.locator('[name=url]').input_value() == raw
    assert form.locator('[name=url]').input_value() != resolved
    return form


def parser(page, key):
    page.locator('[data-dialog=parsers][data-name="' + key + '"]').click()
    form = page.locator('#attachment-parsers-form')
    expect(form).to_be_visible()
    assert form.locator('[name=type]').input_value() == key
    return form


with sync_playwright() as p:
    browser = p.chromium.launch(headless=True)
    # Native links and forms work without JavaScript.
    ctx = browser.new_context(java_script_enabled=False)
    page = ctx.new_page()
    login(page)
    form = service(page)
    form.locator('button.primary').click()
    page.wait_for_url(base + '/models')
    form = parser(page, '.pdf')
    form.locator('[name=profile]').select_option('fix13-parser-alt')
    form.locator('button.primary').click()
    page.wait_for_url(base + '/models')
    expect(page.locator('#attachment-parsing tr').filter(has_text='.pdf')).to_contain_text('fix13-parser-alt')
    expect(page.locator('#attachment-parsing tr').filter(has_text='image/*')).to_contain_text('fix13-parser')
    ctx.close()
    print('PASS: JS-off service raw fallback and focused parser edit', flush=True)

    # Dialogs submit through settings.js and reload the registry.
    ctx = browser.new_context()
    page = ctx.new_page()
    errors = []
    page.on('pageerror', lambda error: errors.append(str(error)))
    login(page)
    form = service(page)
    expect(page.locator('#dialog')).to_be_visible()
    form.locator('button.primary').click()
    expect(page.locator('#services [data-dialog=services][data-name=searxng]')).to_be_visible()
    form = service(page)
    assert form.locator('[name=url]').input_value() == raw
    page.locator('#dialog [data-action=close-dialog]').click()
    expect(page.locator('#dialog')).to_be_hidden()

    form = parser(page, '.pdf')
    expect(page.locator('#dialog')).to_be_visible()
    form.locator('[name=profile]').select_option('')
    form.locator('button.primary').click()
    expect(page.locator('#attachment-parsing tr').filter(has_text='.pdf')).to_have_count(0)
    expect(page.locator('#attachment-parsing tr').filter(has_text='image/*')).to_contain_text('fix13-parser')

    page.locator('[data-dialog=parsers]:not([data-name])').click()
    form = page.locator('#dialog #attachment-parsers-form')
    expect(form).to_be_visible()
    form.locator('[name=type]').fill('INVALID TYPE')
    form.locator('[name=profile]').select_option('fix13-parser')
    form.locator('button.primary').click()
    expect(form.locator('.form-error')).not_to_be_empty()
    expect(page.locator('#dialog')).to_be_visible()
    form.locator('[name=type]').fill('.txt')
    form.locator('button.primary').click()
    expect(page.locator('#attachment-parsing tr').filter(has_text='.txt')).to_contain_text('fix13-parser')
    expect(page.locator('#attachment-parsing tr').filter(has_text='image/*')).to_contain_text('fix13-parser')
    assert not errors, errors
    ctx.close()
    browser.close()
    print('PASS: JS dialogs save raw service, remove and validate focused parser mappings', flush=True)
