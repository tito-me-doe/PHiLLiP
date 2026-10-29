"""Isolated benchmark browser checks; launched by benchmark_frontend.php --browser."""
import sys
import tempfile
from pathlib import Path
from playwright.sync_api import sync_playwright, expect

base=sys.argv[1]
with sync_playwright() as p:
    browser=p.chromium.launch(headless=True)
    page=browser.new_page(viewport={"width":1440,"height":1000})
    errors=[]
    page.on("pageerror", lambda error: errors.append(str(error)))
    page.goto(base)
    page.locator('[name=username]').fill('bench_admin')
    page.locator('[name=password]').fill('benchmark-browser-password')
    page.locator('#login-form button').click()
    page.locator('.route-menu > summary').click()
    page.locator('.page-link[data-page=benchmarks]').click()
    expect(page.locator('[data-benchmark-root]')).to_be_visible()
    expect(page.locator('.benchmark-chart')).to_be_visible()
    page.get_by_role('button',name='Ⅱ Pause',exact=True).click()
    expect(page.get_by_text('Queue paused',exact=True)).to_be_visible()
    page.get_by_role('button',name='▶ Play / resume',exact=True).click()
    expect(page.get_by_text('Queue enabled',exact=True)).to_be_visible()
    page.wait_for_timeout(3500)
    expect(page.locator('table').get_by_text('100.0%',exact=True).first).to_be_visible(timeout=15000)
    capture=Path(tempfile.mkdtemp(prefix='phillip-benchmark-ui-'))/'report.png'
    page.screenshot(path=str(capture),full_page=True)
    print('Screenshot:',capture,flush=True)
    page.get_by_role('link',name='Edit tests',exact=True).click()
    page.get_by_role('link',name='New test',exact=True).click()
    editor=page.locator('#dialog')
    editor.locator('input[name=name]').fill('Browser-created test')
    editor.locator('textarea[name=description]').fill('Created through the real editor')
    editor.get_by_role('button',name='Save test',exact=True).click()
    editor=page.locator('[data-benchmark-editor]')
    editor.get_by_role('link',name='New question',exact=True).click()
    add=page.locator('#dialog form')
    add.locator('input[name=name]').fill('Browser question')
    add.locator('textarea[name=input]').fill('{"prompt":"Return FIXTURE_PASS"}')
    add.locator('textarea[name=criteria_json]').fill('"FIXTURE_PASS"')
    add.get_by_role('button',name='Save question',exact=True).click()
    expect(page.get_by_text('1. Browser question',exact=True)).to_be_visible()
    page.wait_for_timeout(3000)
    expect(page.locator('[data-benchmark-editor]')).to_be_visible()
    page.get_by_role('link',name='Back to report',exact=True).click()
    page.get_by_role('button',name='■ Stop',exact=True).click()
    expect(page.get_by_text('Queue paused',exact=True)).to_be_visible()
    for _ in range(3):
        page.get_by_role('button',name='▶ Play / resume',exact=True).click()
        expect(page.get_by_text('Queue enabled',exact=True)).to_be_visible()
        page.get_by_role('button',name='Ⅱ Pause',exact=True).click()
        expect(page.get_by_text('Queue paused',exact=True)).to_be_visible()
    page.set_viewport_size({"width":390,"height":844})
    expect(page.locator('[data-benchmark-root]')).to_be_visible()
    assert not errors, errors
    print('PASS: browser report, refreshes, repeated controls, test/question editor, and mobile layout')
    browser.close()
