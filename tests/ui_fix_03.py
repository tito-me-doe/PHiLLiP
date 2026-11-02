"""Benchmark automatic refresh preserves focus on buttons, tabs, and result links."""
import sys
from playwright.sync_api import sync_playwright, expect

base = sys.argv[1]

with sync_playwright() as p:
    browser = p.chromium.launch(headless=True)
    page = browser.new_page(viewport={"width": 1440, "height": 1000})
    errors = []
    page.on("pageerror", lambda error: errors.append(str(error)))

    page.goto(base)
    page.locator('[name=username]').fill('bench_admin')
    page.locator('[name=password]').fill('benchmark-browser-password')
    page.locator('#login-form button').click()

    page.locator('.route-menu > summary').click()
    page.locator('.page-link[data-page=benchmarks]').click()
    expect(page.locator('[data-benchmark-root]')).to_be_visible()
    page.locator('[data-benchmark-profiles]').wait_for()

    def assert_focus_preserved(selector, label):
        """Focus an element, wait for multiple refresh intervals, verify root is replaced and focus is preserved."""
        element = page.locator(selector).first
        element.focus()
        expect(element).to_be_focused()

        # Store the original root DOM node before the refresh cycle
        page.evaluate("""() => {
            window.__originalRoot = document.querySelector('[data-benchmark-root]');
        }""")

        # Wait for more than one refresh interval (2.5s each)
        page.wait_for_timeout(4000)

        # Verify root was actually replaced: original disconnected, current is a different node
        root_replaced = page.evaluate("""() => {
            const original = window.__originalRoot;
            const current = document.querySelector('[data-benchmark-root]');
            return original && !original.isConnected && current && current !== original;
        }""")
        assert root_replaced, f"Root was not replaced for {label}"

        # Verify focus is preserved on the same logical control (first match in document order)
        still_focused = page.evaluate(
            """(selector) => {
                const active = document.activeElement;
                const target = document.querySelector(selector);
                return active && active === target;
            }""",
            selector,
        )
        assert still_focused, f"Focus lost on {label} after automatic refresh"
        print(f'PASS: Root replaced and focus preserved on {label} across refresh intervals', flush=True)

    # Test 1: Play button focus
    play_button = page.locator('button[data-benchmark-control="play"]')
    expect(play_button).to_be_visible()
    assert_focus_preserved('button[data-benchmark-control="play"]', 'Play button')

    # Test 2: Tab link focus
    tabs = page.locator('.benchmark-tabs a')
    assert tabs.count() > 1, 'Expected multiple benchmark tabs'
    assert_focus_preserved('.benchmark-tabs a[data-benchmark-tab="all"]', 'tab link')

    # Test 3: Results link focus (profile score link in the profiles table)
    result_links = page.locator('[data-benchmark-profiles] a[data-benchmark-result]')
    expect(result_links.first).to_be_visible()
    assert_focus_preserved('[data-benchmark-profiles] a[data-benchmark-result]', 'results link')

    # Test 4: Metadata link focus
    metadata_links = page.locator('[data-benchmark-profiles] a[data-dialog="benchmark-metadata"]')
    expect(metadata_links.first).to_be_visible()
    assert_focus_preserved('[data-benchmark-profiles] a[data-dialog="benchmark-metadata"]', 'metadata link')

    # Test 5: New Test tab link focus
    new_test = page.locator('.new-test-tab[data-benchmark-tab="new"]')
    expect(new_test).to_be_visible()
    assert_focus_preserved('.new-test-tab[data-benchmark-tab="new"]', 'New Test link')

    # Test 6: Per-profile control button focus (Play/Pause/Stop in profiles table)
    profile_controls = page.locator('button[data-benchmark-profile-action]')
    expect(profile_controls.first).to_be_visible()
    assert_focus_preserved('button[data-benchmark-profile-action]', 'per-profile control')

    # Test 7: Result status link focus (Idle/Running status link in profiles table)
    status_links = page.locator('[data-benchmark-profiles] a[data-dialog="benchmark-run"]')
    expect(status_links.first).to_be_visible()
    assert_focus_preserved('[data-benchmark-profiles] a[data-dialog="benchmark-run"]', 'result status link')

    assert not errors, errors
    print('PASS: Benchmark refresh preserves focus on buttons, tabs, results, metadata, New Test, per-profile controls, and status links across multiple refresh intervals', flush=True)
    browser.close()
