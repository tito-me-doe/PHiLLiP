"""Issue #07 — benchmark EditQuestions dialog lifecycle + Users & Groups editor focus restore."""
import sys
from playwright.sync_api import sync_playwright, expect

base = sys.argv[1]

with sync_playwright() as p:
    browser = p.chromium.launch(headless=True)
    page = browser.new_page(viewport={"width": 1440, "height": 1000})
    errors = []
    page.on("pageerror", lambda error: errors.append(str(error)))

    # --- login recipe (same as ui_fix_03) ---
    page.goto(base)
    page.locator('[name=username]').fill('bench_admin')
    page.locator('[name=password]').fill('benchmark-browser-password')
    page.locator('#login-form button').click()
    expect(page.locator('.shell')).to_be_visible()

    # --- benchmarks: open focus-suite test tab, then Edit Questions dialog ---
    page.goto(base + '/benchmarks')
    page.locator('[data-benchmark-tab]').filter(has_text='focus-suite').click()
    expect(page.locator('[data-benchmark-root]')).to_be_visible()

    page.locator('[data-dialog="benchmark-questions"]').click()
    dialog = page.locator('#dialog')
    expect(dialog).to_be_visible()

    # accessible name equals PHP first .dialog-head h2 text, nonempty
    heading = dialog.locator('.dialog-head h2').first
    expect(heading).to_be_visible()
    heading_text = heading.inner_text().strip()
    assert heading_text, 'dialog heading must be nonempty'
    expect(page.get_by_role('dialog', name=heading_text)).to_be_visible()

    # focus inside #dialog (not BODY)
    assert page.evaluate("""() => {
        const d = document.querySelector('#dialog');
        const ae = document.activeElement;
        return Boolean(d) && Boolean(ae) && d.contains(ae);
    }"""), 'focus must be inside dialog'

    # click New question link inside the dialog
    new_q = dialog.locator('[data-dialog="benchmark-question"][data-question="new"]').first
    expect(new_q).to_be_visible()
    new_q.click()

    # PHP form visible, activeElement inside dialog (not BODY)
    expect(dialog.locator('form')).to_be_visible()
    assert page.evaluate("""() => {
        const d = document.querySelector('#dialog');
        const ae = document.activeElement;
        return Boolean(d) && Boolean(ae) && d.contains(ae) && ae !== document.body;
    }"""), 'activeElement must be inside dialog, not BODY'

    # unmodified close via native close button (no fill)
    close_btn = dialog.locator('[data-action="close-dialog"]').first
    expect(close_btn).to_be_visible()
    close_btn.click()

    # focus returns to original external EditQuestions trigger (re-query if root replaced)
    trigger = page.locator('[data-dialog="benchmark-questions"]').first
    expect(trigger).to_be_visible()
    assert page.evaluate("""() => {
        const ae = document.activeElement;
        const tr = document.querySelector('[data-dialog="benchmark-questions"]');
        return Boolean(ae) && ae === tr && ae !== document.body && ae.isConnected;
    }"""), 'focus must return to EditQuestions trigger'

    # --- second open: capture root, wait for poll replacement while dialog open ---
    page.evaluate("""() => { window.__root = document.querySelector('[data-benchmark-root]'); }""")
    page.locator('[data-dialog="benchmark-questions"]').click()
    expect(dialog).to_be_visible()

    page.wait_for_function("""() => {
        const original = window.__root;
        const current = document.querySelector('[data-benchmark-root]');
        return Boolean(original) && !original.isConnected && Boolean(current) && current !== original;
    }""")

    close_btn = dialog.locator('[data-action="close-dialog"]').first
    expect(close_btn).to_be_visible()
    close_btn.click()

    # replacement EditQuestions focused, not BODY or Close
    assert page.evaluate("""() => {
        const ae = document.activeElement;
        const tr = document.querySelector('[data-dialog="benchmark-questions"]');
        return Boolean(ae) && ae !== document.body && ae.isConnected
            && ae === tr && !ae.matches('[data-close-editor]');
    }"""), 'replacement EditQuestions trigger must be focused'

    # --- /access: first visible a[data-editor], native close, same opener focused ---
    page.goto(base + '/access')
    ug = page.locator('a[data-editor]').first
    expect(ug).to_be_visible()
    ug.click()

    expect(dialog).to_be_visible()
    close_ug = dialog.locator('a[data-close-editor]').first
    expect(close_ug).to_be_visible()
    close_ug.click()

    assert page.evaluate("""() => {
        const ae = document.activeElement;
        const ug = document.querySelector('a[data-editor]');
        return Boolean(ae) && ae === ug && ae !== document.body && ae.isConnected;
    }"""), 'same opener must be focused after close'

    # --- zero errors ---
    assert not errors, errors
    assert page.locator('.phillip-message.log-error').count() == 0, 'log errors present'

    print('PASS: Issue #07 dialog lifecycle and editor focus restore verified', flush=True)
    browser.close()
