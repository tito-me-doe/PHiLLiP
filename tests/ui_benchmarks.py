"""Isolated tabbed, profile-centric benchmark controls and modal editor checks."""
import sys
import tempfile
from pathlib import Path
from playwright.sync_api import sync_playwright, expect

base=sys.argv[1]
with sync_playwright() as p:
    browser=p.chromium.launch(headless=True)
    page=browser.new_page(viewport={"width":1440,"height":1000})
    errors=[]
    page.on("pageerror",lambda error: errors.append(str(error)))
    page.goto(base)
    page.locator('[name=username]').fill('bench_admin')
    page.locator('[name=password]').fill('benchmark-browser-password')
    page.locator('#login-form button').click()
    page.locator('.route-menu > summary').click()
    assert page.locator('.page-link[data-page=test-suites]').count()==0
    page.locator('.page-link[data-page=benchmarks]').click()
    expect(page.locator('[data-benchmark-root]')).to_be_visible()
    expect(page.locator('.benchmark-tabs a.active')).to_have_text('All')
    expect(page.locator('.benchmark-chart')).to_have_attribute('aria-label','Wall time versus score, with Pareto frontier')
    assert page.get_by_role('link',name='Edit tests',exact=True).count()==0
    assert page.get_by_role('heading',name='Recent runs',exact=True).count()==0
    page.get_by_role('button',name='Ⅱ Pause',exact=True).click()
    expect(page.locator('[data-benchmark-status]')).to_have_text('Paused')
    page.get_by_role('button',name='▶ Play / resume',exact=True).click()
    expect(page.locator('[data-benchmark-profiles]').get_by_text('100.0%',exact=True).first).to_be_visible(timeout=15000)

    report_url=page.url
    page.locator('[data-benchmark-profiles] a[data-dialog=benchmark-metadata]').first.click()
    expect(page.locator('#dialog .inspection-json')).to_be_visible()
    assert page.url==report_url
    page.wait_for_timeout(3000)
    expect(page.locator('#dialog')).to_be_visible()
    page.locator('#dialog [aria-label=Close]').click()
    page.locator('[data-benchmark-profiles] a[data-dialog=benchmark-profile]').first.click()
    expect(page.locator('#dialog')).to_contain_text('Latest completed result per test')
    page.locator('#dialog').get_by_role('link',name='Results',exact=True).first.click()
    expect(page.locator('#dialog #benchmark-details')).to_be_visible()
    assert page.url==report_url
    page.locator('#dialog [aria-label=Close]').click()

    page.locator('.benchmark-tabs a').nth(1).click()
    expect(page.get_by_role('link',name='Edit Test',exact=True)).to_be_visible()
    page.locator('[data-benchmark-profiles] a[data-dialog=benchmark-run]').first.click()
    expect(page.locator('#dialog #benchmark-details')).to_be_visible()
    page.locator('#dialog [aria-label=Close]').click()
    print('PASS: All/test tabs, retained scores, wall-time graph, and metadata/profile/run modals without navigation',flush=True)

    page.get_by_role('link',name='+ New Test',exact=True).click()
    modal=page.locator('#dialog')
    modal.locator('input[name=name]').fill('Browser-created test')
    modal.locator('textarea[name=description]').fill('Created through the benchmark tab')
    modal.get_by_role('button',name='Save test',exact=True).click()
    expect(page.locator('.benchmark-tabs a.active')).to_have_text('Browser-created test')
    test_id=page.locator('[data-benchmark-root]').get_attribute('data-test')
    expect(page.get_by_role('link',name='Edit Questions',exact=True)).to_be_visible()
    page.get_by_role('link',name='Edit Questions',exact=True).click()
    expect(modal.locator('[data-question-list]')).to_be_visible()
    modal.get_by_role('link',name='New question',exact=True).click()
    modal.locator('input[name=name]').fill('Browser question')
    modal.locator('textarea[name=input]').fill('{"prompt":"Return FIXTURE_PASS"}')
    modal.locator('textarea[name=criteria_json]').fill('"FIXTURE_PASS"')
    modal.get_by_role('button',name='Save question',exact=True).click()
    expect(modal.get_by_text('1. Browser question',exact=True)).to_be_visible()
    page.wait_for_timeout(3000)
    expect(modal.locator('[data-question-list]')).to_be_visible()
    modal.locator('[aria-label=Close]').click()

    page.get_by_role('link',name='Edit Test',exact=True).click()
    modal.locator('textarea[name=description]').fill('Refined through the edit modal')
    modal.get_by_role('button',name='Save test',exact=True).click()
    expect(page.locator('.benchmark-tabs a.active')).to_have_text('Browser-created test')
    page.get_by_role('button',name='▶ Play / resume',exact=True).click()
    expect(page.locator('[data-benchmark-profiles]').get_by_text('100.0%',exact=True)).to_be_visible(timeout=15000)
    result=page.evaluate("async()=>PHiLLiP.api('/api/admin/benchmarks?test='+document.querySelector('[data-benchmark-root]').dataset.test)")
    assert all(str(run['testid'])==test_id for run in result['latest'])
    for _ in range(3):
        page.get_by_role('button',name='Ⅱ Pause',exact=True).click()
        expect(page.locator('[data-benchmark-status]')).to_have_text('Paused')
        page.get_by_role('button',name='play default',exact=True).click()
        page.get_by_role('button',name='stop default',exact=True).click()
    page.get_by_role('button',name='■ Stop',exact=True).click()
    expect(page.locator('[data-benchmark-status]')).to_have_text('Paused')

    captures=Path(tempfile.mkdtemp(prefix='phillip-benchmark-ui-'))
    page.screenshot(path=str(captures/'test.png'),full_page=True)
    page.locator('.benchmark-tabs').get_by_role('link',name='All',exact=True).click()
    page.screenshot(path=str(captures/'all.png'),full_page=True)
    page.set_viewport_size({"width":390,"height":844})
    expect(page.locator('[data-benchmark-root]')).to_be_visible()
    assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1')
    assert not errors,errors
    print('Screenshots:',captures,flush=True)
    print('PASS: Tab-created tests, modal questions/revision edits, scoped controls, repeated row actions and mobile layout',flush=True)
    browser.close()
