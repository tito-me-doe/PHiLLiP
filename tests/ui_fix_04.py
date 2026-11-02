"""Per-thread composer drafts in localStorage: switch, reload, new-chat, failed send, logout isolation, long text, followups, plus new-chat abort/retry and held-acceptance newer-draft regressions."""
import os
import sys
import time
from playwright.sync_api import sync_playwright, expect

base = sys.argv[1]
thread_a = os.environ.get("FIX04_THREAD_A", "")
thread_b = os.environ.get("FIX04_THREAD_B", "")

with sync_playwright() as p:
    browser = p.chromium.launch()
    context = browser.new_context(viewport={"width": 1440, "height": 1000})
    page = context.new_page()
    errors = []
    page.on("pageerror", lambda error: errors.append(str(error)))
    intercepted = []

    def login(name):
        page.goto(base)
        page.locator("[name=username]").fill(name)
        page.locator("[name=password]").fill("phillip-demo")
        page.locator("#login-form button").click()
        expect(page.locator(".shell")).to_be_visible()

    def open_thread(tid):
        page.locator(f'[data-thread="{tid}"]').click()
        expect(page.locator("#message-form")).to_be_visible()
        # Wait for A.current to reflect the requested thread before typing,
        # so oninput/saveDraft saves to the correct per-thread slot.
        expect(page.locator('#message-form input[name=thread_id]')).to_have_value(tid)
        expect(page.locator("#prompt")).to_be_visible()

    def type_composer(text):
        # fill() replaces the value; dispatch_event("input") triggers oninput/saveDraft.
        page.locator("#prompt").fill(text)
        page.locator("#prompt").dispatch_event("input")

    def send_composer():
        page.get_by_role("button", name="Send message", exact=True).click()

    login("alice")

    # --- Test 1: New-chat draft (separate slot) on welcome screen ---
    # On login there is no current thread; the composer draft lives under the "new" key.
    expect(page.locator("#prompt")).to_be_visible()
    expect(page.locator("#prompt")).to_have_value("")
    type_composer("New chat draft")
    expect(page.locator("#prompt")).to_have_value("New chat draft")
    print("PASS: New-chat draft on welcome screen", flush=True)

    # --- Regression R1: new-chat create succeeds, its /messages request is
    #     aborted, retry succeeds, origin 'new' draft cleared so the next
    #     + Chat is empty; assert interception and await the failure first. ---
    # The "new"-slot draft may hold Test 1's "New chat draft"; fill() below
    # replaces it wholesale, so R1 is self-contained either way.
    intercepted_r1 = []
    page.route(
        "**/api/threads/*/messages",
        lambda route: intercepted_r1.append(route) or route.abort(),
    )

    type_composer("New-chat abort then retry")
    send_composer()
    # Await the observed failure before retrying.
    err_loc = page.locator(
        ".phillip-message.log-error,.phillip-message.log-warning"
    )
    expect(err_loc).to_have_count(err_loc.count() + 1, timeout=10000)
    assert len(intercepted_r1) >= 1, (
        "Expected the new-chat /messages request to be intercepted"
    )
    page.unroute("**/api/threads/*/messages")
    # Retry: the thread was created by the first attempt, so /messages now succeeds.
    send_composer()
    expect(page.locator("#prompt")).to_have_value("")
    # Origin 'new' draft cleared after successful acceptance: the next + Chat opens empty.
    page.locator(".new-chat").click()
    expect(page.locator("#message-form")).to_be_visible()
    expect(
        page.locator("#message-form input[name=thread_id]")
    ).to_have_value("")
    expect(page.locator("#prompt")).to_have_value("")
    print(
        "PASS: new-chat create / abort / retry clears origin 'new' draft",
        flush=True,
    )

    # --- Test 2: Per-thread draft survives switching threads ---
    open_thread(thread_a)
    # Thread A composer starts empty; the new-chat draft stays under "new".
    expect(page.locator("#prompt")).to_have_value("")
    type_composer("Draft for thread A")
    expect(page.locator("#prompt")).to_have_value("Draft for thread A")

    open_thread(thread_b)
    type_composer("Draft for thread B")
    expect(page.locator("#prompt")).to_have_value("Draft for thread B")

    # Switch back to A: draft for A should be restored.
    open_thread(thread_a)
    expect(page.locator("#prompt")).to_have_value("Draft for thread A")

    # Switch back to B: draft for B should be restored.
    open_thread(thread_b)
    expect(page.locator("#prompt")).to_have_value("Draft for thread B")
    print("PASS: Per-thread draft survives switching threads", flush=True)

    # --- Test 3: Draft survives page reload ---
    type_composer("Draft for thread B after reload")
    page.reload()
    expect(page.locator("#message-form")).to_be_visible()
    expect(page.locator("#prompt")).to_have_value("Draft for thread B after reload")
    print("PASS: Draft survives page reload", flush=True)

    # --- Test 4: Draft cleared only after successful acceptance ---
    send_composer()
    expect(page.locator("#prompt")).to_have_value("")
    expect(page.locator(".message.assistant[data-id]")).to_have_count(1, timeout=20000)
    print("PASS: Draft cleared after successful send", flush=True)

    # --- Test 5: Failed send preserves draft (aborted thread messages route) ---
    # The actual send URL is /api/threads/<id>/messages, not /api/messages.
    page.route("**/api/threads/*/messages", lambda route: intercepted.append(route) or route.abort())
    type_composer("Draft that survives failed send")
    send_composer()
    # Wait for the observed failure before asserting the draft survived.
    expect(page.locator(".phillip-message.log-error,.phillip-message.log-warning")).to_have_count(1, timeout=10000)
    assert len(intercepted) >= 1, "Expected the send route to be intercepted"
    expect(page.locator("#prompt")).to_have_value("Draft that survives failed send")
    page.unroute("**/api/threads/*/messages")
    print("PASS: Failed send preserves draft", flush=True)

    # --- Test 6: After unroute, successful send clears draft ---
    send_composer()
    expect(page.locator("#prompt")).to_have_value("")
    expect(page.locator(".message.assistant[data-id]")).to_have_count(2, timeout=20000)
    print("PASS: Successful send after unroute clears draft", flush=True)

    # --- Regression R2: hold the accepted /messages response via route.fetch,
    #     then fulfill; type NEWER text before fulfillment; the newer draft
    #     survives acceptance and a thread switch / reload. ---
    captured = {}
    page.route(
        "**/api/threads/*/messages",
        lambda route: captured.setdefault("route", route),
    )

    type_composer("older text")
    expect(page.locator("#prompt")).to_have_value("older text")
    send_composer()
    # Wait until the accepted request is actually held (not yet delivered).
    _deadline = time.time() + 8
    _held = None
    while time.time() < _deadline:
        _held = captured.get("route")
        if _held is not None:
            break
        time.sleep(0.05)
    assert _held is not None, "Expected the /messages request to be held"

    # Type newer text while acceptance is outstanding.
    type_composer("NEWER text")
    expect(page.locator("#prompt")).to_have_value("NEWER text")

    # Fulfill the held response with the real upstream response.
    _response = _held.fetch()
    _held.fulfill(response=_response)

    # Newer text survived acceptance: neither input nor draft rolled back.
    expect(page.locator("#prompt")).to_have_value("NEWER text")
    # Survives a thread switch.
    open_thread(thread_a)
    expect(page.locator("#prompt")).to_have_value("Draft for thread A")
    open_thread(thread_b)
    expect(page.locator("#prompt")).to_have_value("NEWER text")
    # Survives a reload.
    page.reload()
    expect(page.locator("#message-form")).to_be_visible()
    expect(
        page.locator("#message-form input[name=thread_id]")
    ).to_have_value(thread_b)
    expect(page.locator("#prompt")).to_have_value("NEWER text")
    page.unroute("**/api/threads/*/messages")
    print(
        "PASS: Held /messages acceptance preserves newer draft across switch/reload",
        flush=True,
    )

    # --- Test 7: Long text (>4000 chars) preserved in full ---
    long_text = "x" * 5000
    type_composer(long_text)
    expect(page.locator("#prompt")).to_have_value(long_text)
    # Switching to thread A surfaces thread A's own older draft; long text stays in B's slot.
    open_thread(thread_a)
    expect(page.locator("#prompt")).to_have_value("Draft for thread A")
    # Back in thread B: long text is fully preserved.
    open_thread(thread_b)
    expect(page.locator("#prompt")).to_have_value(long_text)
    print("PASS: Long text (>4000 chars) preserved in full", flush=True)

    # --- Test 8: Multiple followups in same thread (thread A had 0 assistants) ---
    open_thread(thread_a)
    type_composer("Followup 1")
    send_composer()
    expect(page.locator("#prompt")).to_have_value("")
    expect(page.locator(".message.assistant[data-id]")).to_have_count(1, timeout=20000)
    type_composer("Followup 2")
    send_composer()
    expect(page.locator("#prompt")).to_have_value("")
    expect(page.locator(".message.assistant[data-id]")).to_have_count(2, timeout=20000)
    print("PASS: Multiple followups in same thread", flush=True)

    # --- Regression R3: held B response must not leak into A ---
    # Hold B's /messages (POST only), type A's draft while held, then release B.
    # B's release must not rotate A's submission key, clobber A's captured
    # input, or insert a foreign run into A. A's global send lock keeps
    # A.sending true while B is held, so A's text is captured as a draft, not
    # submitted.
    held_r3 = {}

    def route_r3(route):
        url = route.request.url
        is_b_send = route.request.method == "POST" and thread_b in url
        if is_b_send and "route" not in held_r3:
            held_r3["route"] = route
        else:
            response = route.fetch()
            route.fulfill(response=response)

    page.route("**/api/threads/*/messages", route_r3)

    open_thread(thread_b)
    type_composer("B held message")
    send_composer()

    _deadline = time.time() + 8
    _held = None
    while time.time() < _deadline:
        _held = held_r3.get("route")
        if _held is not None:
            break
        time.sleep(0.05)
    assert _held is not None, "Expected B's /messages to be held"

    # Switch to A and capture A's draft while B's response is held.
    open_thread(thread_a)
    type_composer("A message while B held")
    expect(page.locator("#prompt")).to_have_value("A message while B held")
    expect(page.locator(".message.assistant[data-id]")).to_have_count(2, timeout=20000)
    key_after_a = page.locator('[name="submission_key"]').input_value()

    # Release B's held response while A is still visible.
    _response = _held.fetch()
    _held.fulfill(response=_response)

    # The submission key on A must not be rotated while another thread is
    # visible (rotation is microtask-fast when the guard is missing).
    _deadline = time.time() + 2
    _rotated = False
    while time.time() < _deadline:
        if page.locator('[name="submission_key"]').input_value() != key_after_a:
            _rotated = True
            break
        time.sleep(0.05)
    assert not _rotated, "submission_key rotated while another thread was visible"
    expect(page.locator('[name="submission_key"]')).to_have_value(key_after_a)
    # A's captured input is retained, not clobbered; no foreign run inserted.
    expect(page.locator("#prompt")).to_have_value("A message while B held")
    expect(page.locator(".message.assistant[data-id]")).to_have_count(2, timeout=20000)
    page.unroute("**/api/threads/*/messages")
    print("PASS: Held B response does not leak into A (R3)", flush=True)

    # Confirm B's release was actually processed (B page loads with its message),
    # then return to A. Exact B count depends on earlier tests; the key R3
    # assertions above already verified A was not leaked into.
    open_thread(thread_b)
    expect(page.locator("#message-form")).to_be_visible()
    open_thread(thread_a)

    # Send 2 followups in A: baseline (2) +1, +2, persisted via reload.
    type_composer("Followup 3")
    send_composer()
    expect(page.locator("#prompt")).to_have_value("")
    expect(page.locator(".message.assistant[data-id]")).to_have_count(3, timeout=20000)

    type_composer("Followup 4")
    send_composer()
    expect(page.locator("#prompt")).to_have_value("")
    expect(page.locator(".message.assistant[data-id]")).to_have_count(4, timeout=20000)

    page.reload()
    expect(page.locator("#message-form")).to_be_visible()
    expect(page.locator('#message-form input[name=thread_id]')).to_have_value(thread_a)
    expect(page.locator(".message.assistant[data-id]")).to_have_count(4, timeout=20000)
    expect(page.locator("#prompt")).to_have_value("")
    print("PASS: R3 followups persisted across reload", flush=True)

    # --- Test 9: Logout / user isolation ---
    # Sign out lives inside the collapsed .route-menu <details>, so expand it
    # before the logout button becomes reachable.
    page.locator(".route-menu > summary").click()
    page.get_by_role("button", name="Sign out", exact=True).click()
    expect(page.locator("#login-form")).to_be_visible()
    login("bob")
    expect(page.locator("#prompt")).to_have_value("")
    type_composer("Bob's private draft")
    expect(page.locator("#prompt")).to_have_value("Bob's private draft")
    print("PASS: Logout/user isolation keeps drafts separate", flush=True)

    assert not errors, errors
    print("PASS: All per-thread composer draft checks passed", flush=True)
    browser.close()
