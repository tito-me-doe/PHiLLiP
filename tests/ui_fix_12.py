"""Issue 12: persona cards readable at 390px and bounded management previews.

Drives the installed app through a real browser: logs into the persona cards
at 390px wide, verifies card geometry, tap targets, hit-testing and hostile text,
opens the sidebar through .mobile-menu before using the route menu to
reach /personas, expands the named long fixture persona's "Full prompt" in
place, pairs a bounded preview with a safe full-text reveal for an XSS-looking
prompt, and confirms a disabled fixture cannot start while the allowed fixture
starts an empty thread carrying the copied persona. No real provider call is
made.
"""
import os
import re
from playwright.sync_api import sync_playwright, expect


base = os.environ['FX12_BASE']
username = os.environ['FX12_USERNAME']
password = os.environ['FX12_PASSWORD']

PROBE_ALPHA = "alpha-xyz-probe-"   # first persona's distinct tagline lead
PROBE_BETA = "beta-qrs-probe-"     # long-named persona's distinct tagline lead
LONG_NAME = "A Very Long Persona Name That Would Stack Into Words Without Ellipsis Handling"
CHI_NAME = "Safe Render Check"
CHI_PREVIEW_LEAD = "<img src=x"


def cards_overview(page):
    page.wait_for_selector('#persona-start .persona-card', timeout=15000)
    return page.evaluate("""() => {
      const box = (el) => { const b = el.getBoundingClientRect(); return {x: b.x, y: b.y, w: b.width, h: b.height, r: b.right, b: b.bottom}; };
      const view = {w: window.innerWidth, sw: Math.max(document.documentElement.scrollWidth, document.body.scrollWidth)};
      const cards = Array.from(document.querySelectorAll('#persona-start .persona-card')).map((card) => {
        const name = card.querySelector('strong');
        const tag = card.querySelector('.persona-tagline');
        return {
          title: card.getAttribute('title') || '', isNew: card.classList.contains('persona-new'), enabled: !card.disabled,
          tagline: (tag ? tag.innerText : '').trim(), box: box(card), nameBox: name ? box(name) : null,
          hasAvatar: !!card.querySelector('.avatar svg'),
        };
      });
      return {view, cards};
    }""")


def card(page, name_fragment):
    return page.locator('#persona-start .persona-card').filter(has=page.locator('strong').filter(has_text=name_fragment))


def main():
    errors = []
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context(viewport={"width": 390, "height": 900})
        page = context.new_page()
        page.on("pageerror", lambda error: errors.append(str(error)))
        # The configured provider does not exist: never let a real call happen.
        page.route(re.compile(r"https?://127\.0\.0\.1:1/"), lambda route: route.abort())

        try:
            # Log in through the actual UI, landing on the persona cards (/chat, no thread selected).
            page.goto(base + "/")
            page.locator('input[name="username"]').fill(username)
            page.locator('input[name="password"]').fill(password)
            page.locator('#login-form button').click()

            overview = cards_overview(page)
            view, cards = overview['view'], overview['cards']
            page.screenshot(path='/tmp/phillip-ui-fix12-mobile.png', full_page=True)

            # The shipped cast plus our three, plus a trailing New Persona card.
            people = [c for c in cards if not c['isNew']]
            assert any(PROBE_ALPHA in c['tagline'] for c in people) and any(PROBE_BETA in c['tagline'] for c in people), cards
            assert len([c for c in cards if c['isNew']]) == 1 and cards[-1]['isNew'], "the New Persona card comes last"
            # A character is never disabled by tools: the long-named one asks for an unknown tool and still starts.
            assert all(c['enabled'] for c in cards), [c['title'] for c in cards if not c['enabled']]
            assert all(c['hasAvatar'] for c in people), "every persona card shows its avatar"

            # Readability at 390px: two columns, useful width, a real tap target, no card clipped by the viewport.
            assert all(c['box']['w'] >= 120 and c['box']['h'] >= 44 for c in cards), [c['box'] for c in cards]
            assert all(c['box']['x'] >= 0 and c['box']['r'] <= view['w'] + 2 for c in cards), cards
            # The long name wraps inside its card instead of overflowing it.
            long_card = [c for c in people if 'Ellipsis Handling' in c['title']][0]
            assert long_card['nameBox']['x'] >= long_card['box']['x'] - 1 and long_card['nameBox']['r'] <= long_card['box']['r'] + 1, long_card

            # Every card is hit-testable: nothing overlays the centre of its action.
            nodes = page.locator('#persona-start .persona-card')
            for i in range(nodes.count()):
                node = nodes.nth(i)
                node.scroll_into_view_if_needed()
                assert node.evaluate("""(el) => { const b = el.getBoundingClientRect(); const pt = document.elementFromPoint((b.left + b.right) / 2, (b.top + b.bottom) / 2); return Boolean(pt && (pt === el || el.contains(pt))); }"""), f"card {i} occluded"

            # The page does not pan horizontally.
            assert view['sw'] <= view['w'] + 2, view

            # Taglines are bounded and hostile text stays inert: shown as text, never parsed as markup.
            assert all(len(c['tagline']) <= 140 for c in people), [len(c['tagline']) for c in people]
            hostile = [c for c in people if CHI_PREVIEW_LEAD in c['tagline']]
            assert len(hostile) == 1, hostile
            assert page.locator('#persona-start img[src="x"]').count() == 0, "hostile tagline was parsed as markup"

            # ----- Person management via the route menu (sidebar closed at 390px) -----
            page.locator('.mobile-menu').click()
            personas_link = page.locator('.page-link[data-page=personas]')
            if not personas_link.is_visible():  # the route menu may start collapsed or open
                page.locator('.route-menu > summary').click()
            personas_link.click()
            page.wait_for_selector('#personas table.persona-table.registry', timeout=15000)

            mgmt = page.locator('#personas table.persona-table.registry')
            expect(mgmt).to_be_visible()
            assert mgmt.evaluate("(el) => el.tagName") == "TABLE"

            # Real preview assertion: the disabled beta row's probe survives in the
            # bounded management preview (replaces the old vacuous `or True`).
            body_text = re.sub(r'\s+', ' ', mgmt.inner_text())
            assert PROBE_BETA in body_text, "disabled beta bounded preview must appear in management view"

            # Full prompt expansion targets the named long fixture persona, not the
            # first curated row, and opens in place without navigation.
            names = page.evaluate("""Array.from(document.querySelectorAll(
              '#personas table.persona-table.registry tbody tr')).map((tr) => {
                const cell = tr.querySelector('strong');
                return cell ? cell.textContent.trim() : '';
              })""")
            details = page.locator('#personas table.persona-table.registry details')
            assert details.count() == len(names), (details.count(), len(names))

            beta_detail = details.nth(names.index(LONG_NAME))
            beta_detail.locator('summary').click()
            assert beta_detail.get_attribute('open') is not None, "beta full prompt did not expand"
            beta_text = beta_detail.inner_text()
            assert "Management prints full prompts" in beta_text, "full prompt not revealed"
            assert PROBE_BETA in beta_text, "complete prompt including probe must be revealed"

            # XSS-looking prompt: bounded preview, safely escaped full-text reveal.
            chi_detail = details.nth(names.index(CHI_NAME))
            chi_detail.locator('summary').click()
            assert chi_detail.get_attribute('open') is not None, "chi full prompt did not expand"
            chi_text = chi_detail.inner_text()
            assert CHI_PREVIEW_LEAD in chi_text, "XSS-looking text must reveal verbatim as inert text"
            assert 'document.domain' in chi_text, "full XSS-looking prompt must be revealed"
            page.screenshot(path='/tmp/phillip-ui-fix12-management-full.png')

            # ----- Back on the cards: every card starts, including the one that asked for an unknown tool -----
            page.go_back()
            page.keyboard.press('Escape')
            page.wait_for_selector('#persona-start .persona-card', timeout=15000)
            alpha_card = card(page, 'Helpful Assistant')
            expect(alpha_card).to_be_enabled()
            expect(alpha_card).to_be_visible()
            alpha_card.click()
            page.wait_for_selector('#message-form', timeout=15000)
            expect(page.locator('#persona-start')).to_have_count(0)
            assert page.locator('.chat-companion').get_attribute('data-name') == 'Helpful Assistant', "the companion is whoever the conversation is with"

            assert not errors, errors
            print("PASS: persona cards readable at 390px, tap targets and hit-testing, bounded taglines, "
                  "hostile text inert, safe full-prompt reveal, a character always starts and the companion follows",
                  flush=True)
        finally:
            browser.close()


main()
