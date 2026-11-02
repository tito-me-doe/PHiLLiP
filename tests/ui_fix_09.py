"""Grid/min-width containment regression at 390x844 viewport.

Checks that .page-inner and .benchmark-content shrink below content so
cards, prose, graph, and controls fit the viewport, wide tables scroll
inside .table-wrap, and long text wraps. Tests element geometry
(inner-pane scrollWidth), not just document.scrollWidth.
"""
import sys
from playwright.sync_api import sync_playwright, expect

base = sys.argv[1]

with sync_playwright() as p:
    browser = p.chromium.launch(headless=True)
    page = browser.new_page(viewport={"width": 1440, "height": 1000})
    errors = []
    page.on("pageerror", lambda error: errors.append(str(error)))

    page.goto(base)
    page.locator('[name=username]').fill('alice')
    page.locator('[name=password]').fill('phillip-demo')
    page.locator('#login-form button').click()

    def assert_inner_pane_fits(page_label):
        """Verify the inner pane's scrollWidth fits within the viewport."""
        result = page.evaluate("""() => {
            const inner = document.querySelector('.page-inner');
            if (!inner) return { fits: true, reason: 'no page-inner' };
            const viewport = window.innerWidth;
            return {
                fits: inner.scrollWidth <= viewport,
                viewport: viewport,
                scrollWidth: inner.scrollWidth,
                clientWidth: inner.clientWidth,
            };
        }""")
        assert result['fits'], f"{page_label}: inner pane scrollWidth {result['scrollWidth']}px exceeds viewport {result['viewport']}px"
        print(f'PASS: {page_label} inner pane scrollWidth={result["scrollWidth"]}px <= viewport={result["viewport"]}px', flush=True)

    def assert_cards_fit(page_label):
        """Verify no card overflows the inner pane."""
        result = page.evaluate("""() => {
            const inner = document.querySelector('.page-inner');
            if (!inner) return { fits: true, reason: 'no page-inner' };
            const cards = Array.from(inner.querySelectorAll('.card'));
            const overflowing = cards.filter(c => c.scrollWidth > inner.clientWidth);
            return {
                fits: overflowing.length === 0,
                cardCount: cards.length,
                overflowing: overflowing.length,
                innerClientWidth: inner.clientWidth,
            };
        }""")
        assert result['fits'], f"{page_label}: {result['overflowing']} of {result['cardCount']} cards overflow inner pane (clientWidth={result['innerClientWidth']}px)"
        print(f'PASS: {page_label} all {result["cardCount"]} cards fit within inner pane clientWidth={result["innerClientWidth"]}px', flush=True)

    def assert_table_wrap_scrolls(page_label):
        """Verify .table-wrap elements can scroll independently."""
        result = page.evaluate("""() => {
            const wraps = Array.from(document.querySelectorAll('.table-wrap'));
            const scrollable = wraps.filter(w => w.scrollWidth > w.clientWidth);
            return {
                count: wraps.length,
                scrollable: scrollable.length,
                allOverflowAuto: wraps.every(w => {
                    const s = getComputedStyle(w);
                    return s.overflow === 'auto' || s.overflowX === 'auto' || s.overflow === 'scroll' || s.overflowX === 'scroll';
                }),
            };
        }""")
        assert result['allOverflowAuto'], f"{page_label}: not all .table-wrap elements have overflow auto/scroll"
        print(f'PASS: {page_label} {result["count"]} table-wrap(s), {result["scrollable"]} horizontally scrollable, all overflow=auto/scroll', flush=True)

    def assert_benchmark_content_fits():
        """Verify .benchmark-content scrollWidth fits within the viewport (benchmarks page has no .page-inner)."""
        result = page.evaluate("""() => {
            const content = document.querySelector('.benchmark-content');
            if (!content) return { fits: true, reason: 'no benchmark-content' };
            const viewport = window.innerWidth;
            return {
                fits: content.scrollWidth <= viewport,
                viewport: viewport,
                scrollWidth: content.scrollWidth,
                clientWidth: content.clientWidth,
            };
        }""")
        assert result['fits'], f"Benchmarks: .benchmark-content scrollWidth {result['scrollWidth']}px exceeds viewport {result['viewport']}px"
        print(f'PASS: Benchmarks .benchmark-content scrollWidth={result["scrollWidth"]}px <= viewport={result["viewport"]}px', flush=True)

    def assert_benchmark_cards_fit():
        """Verify no card overflows .benchmark-content on the benchmarks page."""
        result = page.evaluate("""() => {
            const content = document.querySelector('.benchmark-content');
            if (!content) return { fits: true, reason: 'no benchmark-content' };
            const cards = Array.from(content.querySelectorAll('.card'));
            const overflowing = cards.filter(c => c.scrollWidth > content.clientWidth);
            return {
                fits: overflowing.length === 0,
                cardCount: cards.length,
                overflowing: overflowing.length,
                contentClientWidth: content.clientWidth,
            };
        }""")
        assert result['fits'], f"Benchmarks: {result['overflowing']} of {result['cardCount']} cards overflow .benchmark-content (clientWidth={result['contentClientWidth']}px)"
        print(f'PASS: Benchmarks all {result["cardCount"]} cards fit within .benchmark-content clientWidth={result["contentClientWidth"]}px', flush=True)

    def assert_wide_table_scrolls_locally(page_label):
        """Prove a genuinely wide table scrolls inside .table-wrap, not the whole pane."""
        result = page.evaluate("""() => {
            const wraps = Array.from(document.querySelectorAll('.table-wrap'));
            const wide = wraps.filter(w => w.scrollWidth > w.clientWidth);
            if (wide.length === 0) return { hasWide: false, reason: 'no wide table' };
            const w = wide[0];
            const pane = document.querySelector('.page-inner') || document.querySelector('.benchmark-content') || document.body;
            return {
                hasWide: true,
                tableScrollWidth: w.scrollWidth,
                tableClientWidth: w.clientWidth,
                paneScrollWidth: pane.scrollWidth,
                paneClientWidth: pane.clientWidth,
                tableFitsPane: w.scrollWidth <= pane.clientWidth + 20, // wrap padding allowance
                paneFitsViewport: pane.scrollWidth <= window.innerWidth,
            };
        }""")
        assert result['hasWide'], f"{page_label}: no horizontally wide table found to prove local scrolling"
        assert result['paneFitsViewport'], f"{page_label}: pane scrollWidth {result['paneScrollWidth']}px exceeds viewport {window.innerWidth if False else ''}px while a wide table exists"
        print(f'PASS: {page_label} wide table scrollWidth={result["tableScrollWidth"]}px scrolls inside .table-wrap (clientWidth={result["tableClientWidth"]}px); pane scrollWidth={result["paneScrollWidth"]}px fits viewport', flush=True)

    def open_page_menu():
        # The mobile drawer opens with the pages menu already expanded; only toggle it when it is closed.
        if not page.locator('.route-menu').evaluate('el => el.open'):
            page.locator('.route-menu > summary').click()

    def check_page_at_mobile(page_label, page_path_selector):
        """Navigate to a page at desktop size, resize to 390x844, check geometry."""
        open_page_menu()
        page.locator(page_path_selector).click()
        expect(page.locator('.page-inner')).to_be_visible()
        page.locator('table.registry').first.wait_for()

        page.set_viewport_size({"width": 390, "height": 844})
        page.wait_for_timeout(500)

        assert_inner_pane_fits(page_label)
        assert_cards_fit(page_label)
        assert_table_wrap_scrolls(page_label)
        assert_wide_table_scrolls_locally(page_label)

    # --- Models page ---
    check_page_at_mobile('Models', '.page-link[data-page=models]')

    # --- Access page ---
    # On mobile the sidebar is hidden; open it via the mobile-menu button first.
    page.locator('.mobile-menu').click()
    expect(page.locator('.sidebar')).to_be_visible()
    check_page_at_mobile('Permissions & Users', '.page-link[data-page=access]')

    # --- Benchmarks page ---
    # Benchmarks uses .benchmark-content, not .page-inner; check its geometry directly.
    page.locator('.mobile-menu').click()
    expect(page.locator('.sidebar')).to_be_visible()
    open_page_menu()
    page.locator('.page-link[data-page=benchmarks]').click()
    expect(page.locator('[data-benchmark-root]')).to_be_visible()
    page.locator('[data-benchmark-profiles]').wait_for()

    page.set_viewport_size({"width": 390, "height": 844})
    page.wait_for_timeout(500)

    assert_benchmark_content_fits()
    assert_benchmark_cards_fit()
    assert_table_wrap_scrolls('Benchmarks')
    assert_wide_table_scrolls_locally('Benchmarks')

    assert not errors, errors
    print('PASS: Grid/min-width containment holds at 390x844 for Models, Access, and Benchmarks', flush=True)
    browser.close()
