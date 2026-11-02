"""Chat settings workflow: escape reject/accept, close without confirm,
save persistence, and save-as-persona reject/accept."""
import os
import sys
from playwright.sync_api import sync_playwright, expect

base = sys.argv[1]
thread_id = os.environ.get("FIX05_THREAD_ID", "")
project_id = os.environ.get("FIX05_PROJECT_ID", "")
todo_id = os.environ.get("FIX05_TODO_ID", "")

with sync_playwright() as p:
    browser = p.chromium.launch()
    context = browser.new_context(viewport={"width": 1440, "height": 1000})
    page = context.new_page()
    errors = []
    page.on("pageerror", lambda error: errors.append(str(error)))
    dialog_action = {"action": "dismiss"}
    state = {"confirms": 0}

    def handle_dialog(dialog):
        state["confirms"] += 1
        if dialog_action["action"] == "accept":
            dialog.accept()
        else:
            dialog.dismiss()

    page.on("dialog", handle_dialog)

    def login(name):
        page.goto(base)
        page.locator("[name=username]").fill(name)
        page.locator("[name=password]").fill("phillip-demo")
        page.locator("#login-form button").click()
        expect(page.locator(".shell")).to_be_visible()

    def open_settings():
        page.locator('button[data-dialog=chat]').click()
        expect(page.locator("#chat-settings-form")).to_be_visible()
        expect(page.locator('textarea[name="system_prompt"]')).to_be_visible()

    def system_value():
        return page.locator('textarea[name="system_prompt"]').input_value()

    def fillsystem(text):
        page.locator('textarea[name="system_prompt"]').fill(text)

    login("alice")

    # Open the target thread.
    page.locator(f'[data-thread="{thread_id}"]').click()
    expect(page.locator("#message-form")).to_be_visible()

    # --- Unmodified close: no confirm ---
    dialog_action["action"] = "dismiss"
    before = state["confirms"]
    open_settings()
    page.keyboard.press("Escape")
    expect(page.locator("#chat-settings-form")).not_to_be_visible()
    assert state["confirms"] == before, f"unmodified close raised {state['confirms'] - before} confirm(s)"
    print("PASS: unmodified close produces no confirm", flush=True)

    # --- Escape reject twice: form/value stay intact ---
    open_settings()
    fillsystem("Edited prompt one")
    dialog_action["action"] = "dismiss"
    before = state["confirms"]
    page.keyboard.press("Escape")  # reject #1
    assert state["confirms"] == before + 1, f"reject #1 raised {state['confirms'] - before} confirm(s)"
    expect(page.locator("#chat-settings-form")).to_be_visible()
    expect(page.locator('textarea[name="system_prompt"]')).to_have_value("Edited prompt one")

    fillsystem("Edited prompt two")
    dialog_action["action"] = "dismiss"
    before = state["confirms"]
    page.keyboard.press("Escape")  # reject #2
    assert state["confirms"] == before + 1, f"reject #2 raised {state['confirms'] - before} confirm(s)"
    expect(page.locator("#chat-settings-form")).to_be_visible()
    expect(page.locator('textarea[name="system_prompt"]')).to_have_value("Edited prompt two")
    print("PASS: escape reject twice each raises exactly 1 confirm and keeps form/value intact", flush=True)

    # --- Escape accept closes (discards) ---
    dialog_action["action"] = "accept"
    before = state["confirms"]
    page.keyboard.press("Escape")
    expect(page.locator("#chat-settings-form")).not_to_be_visible()
    assert state["confirms"] == before + 1, f"escape accept raised {state['confirms'] - before} confirm(s)"
    print("PASS: escape accept closes dialog with exactly 1 confirm", flush=True)

    # --- Save changed prompt: no discard confirm; reopen persists ---
    dialog_action["action"] = "dismiss"
    open_settings()
    fillsystem("Persisted prompt")
    page.locator("#chat-settings-form button.primary").click()
    expect(page.locator("#chat-settings-form")).not_to_be_visible()
    open_settings()
    expect(page.locator('textarea[name="system_prompt"]')).to_have_value("Persisted prompt")
    page.keyboard.press("Escape")
    print("PASS: save changed prompt persists across reopen with no confirm", flush=True)

    # --- Save-as-persona: reject leaves current form, exactly 1 confirm ---
    open_settings()
    fillsystem("Persona candidate")
    dialog_action["action"] = "dismiss"
    before = state["confirms"]
    page.locator("#save-as-persona").click()
    assert state["confirms"] == before + 1, f"save-as-persona reject raised {state['confirms'] - before} confirm(s)"
    expect(page.locator("#chat-settings-form")).to_be_visible()
    expect(page.locator('textarea[name="system_prompt"]')).to_have_value("Persona candidate")
    print("PASS: save-as-persona reject leaves current form, exactly 1 confirm", flush=True)

    # --- Save-as-persona: accept copies prompt into persona form ---
    dialog_action["action"] = "accept"
    before = state["confirms"]
    page.locator("#save-as-persona").click()
    assert state["confirms"] == before + 1, f"save-as-persona accept raised {state['confirms'] - before} confirm(s)"
    expect(page.locator("#chat-settings-form")).not_to_be_visible()
    expect(page.locator("#persona-form")).to_be_visible()
    expect(page.locator("#persona-form [name=bio]")).to_have_value("Persona candidate")  # the prompt becomes the new character's description
    print("PASS: save-as-persona accept opens persona form, exactly 1 confirm", flush=True)

    # --- Real ToDo dialog: discard does not persist; save persists ---
    assert project_id and todo_id, "Missing FIX05 fixture env (project_id/todo_id); required ToDo tests cannot proceed"
    if project_id and todo_id:
        page.goto(f"{base}/projects?project={project_id}")
        page.wait_for_selector(f"#todo-{todo_id}", timeout=10000)
        # No error toasts / uncaught page errors on the projects page.
        assert not errors, errors
        assert page.locator(".phillip-message.log-error").count() == 0

        edit = f'[data-dialog="project-todo"][data-todo="{todo_id}"]:not([data-remove])'
        page.locator(edit).click()
        page.wait_for_selector("#dialog [name=title]", timeout=10000)
        expect(page.locator("#dialog")).to_have_attribute("open", "")

        def todo_title():
            return page.locator("#dialog [name=title]").input_value()

        def todo_context():
            return page.locator("#dialog [name=context]").input_value()

        # Change title + context (dirty).
        page.locator("#dialog [name=title]").fill("Edited title")
        page.locator("#dialog [name=context]").fill("Edited context")

        # Close/X reject: exactly 1 confirm, same form + edited values retained.
        dialog_action["action"] = "dismiss"
        before = state["confirms"]
        page.locator("#dialog [data-action=close-dialog]").click()
        assert state["confirms"] == before + 1, f"X reject raised {state['confirms'] - before} confirm(s)"
        expect(page.locator("#dialog")).to_have_attribute("open", "")
        assert todo_title() == "Edited title"
        assert todo_context() == "Edited context"
        print("PASS: ToDo X reject raises exactly 1 confirm and keeps edited values", flush=True)

        # Escape accept closes (discard) with exactly 1 confirm.
        dialog_action["action"] = "accept"
        before = state["confirms"]
        page.keyboard.press("Escape")
        assert state["confirms"] == before + 1, f"Escape accept raised {state['confirms'] - before} confirm(s)"
        expect(page.locator("#dialog")).not_to_have_attribute("open", "")
        print("PASS: ToDo Escape accept closes with exactly 1 confirm", flush=True)

        # Reopen: original DB values prove discard did not persist.
        page.locator(edit).click()
        page.wait_for_selector("#dialog [name=title]", timeout=10000)
        assert todo_title() == "Dirty ToDo", f"discarded title leaked: {todo_title()!r}"
        assert todo_context() == "Original context.", f"discarded context leaked: {todo_context()!r}"
        page.keyboard.press("Escape")
        print("PASS: ToDo reopen shows original DB values; discard did not persist", flush=True)

        # Guard: closeDialog(nonmodalForm) while dirty ToDo modal open must NOT close/prompt.
        page.locator(edit).click()
        page.wait_for_selector("#dialog [name=title]", timeout=10000)
        page.locator("#dialog [name=context]").fill("Guarded context")
        nonmodal = page.locator("form:not(#dialog form)").first
        before = state["confirms"]
        closed = page.evaluate("(form) => !!PHiLLiP.closeDialog(form)", nonmodal.element_handle())
        assert closed is True, "closeDialog(nonmodalForm) should return true without closing"
        assert state["confirms"] == before, f"guard raised {state['confirms'] - before} confirm(s)"
        expect(page.locator("#dialog")).to_have_attribute("open", "")
        assert page.locator("#dialog [name=context]").input_value() == "Guarded context"
        print("PASS: closeDialog(nonmodalForm) preserves dirty ToDo modal, no prompt", flush=True)
        page.keyboard.press("Escape")

        # Edit/save actual ToDo: zero discard confirm, closes, reopen sees persisted context.
        dialog_action["action"] = "dismiss"
        page.locator(edit).click()
        page.wait_for_selector("#dialog [name=title]", timeout=10000)
        page.locator("#dialog [name=context]").fill("Persisted context")
        before = state["confirms"]
        page.locator("#dialog button.primary").click()
        assert state["confirms"] == before, f"save raised {state['confirms'] - before} discard confirm(s)"
        expect(page.locator("#dialog")).not_to_have_attribute("open", "")
        page.locator(edit).click()
        page.wait_for_selector("#dialog [name=title]", timeout=10000)
        assert todo_context() == "Persisted context", f"context not persisted: {todo_context()!r}"
        page.keyboard.press("Escape")
        assert not errors, errors
        assert page.locator(".phillip-message.log-error").count() == 0
        print("PASS: ToDo edit/save persists context with zero discard confirms", flush=True)

    assert not errors, errors
    print("PASS: All chat settings checks passed", flush=True)
    browser.close()
