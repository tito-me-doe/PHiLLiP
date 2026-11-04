"""Isolated grants-only access, read-only tables/modals, sidebar and follow-up checks."""
import sys
import tempfile
from pathlib import Path
from playwright.sync_api import sync_playwright, expect

base = sys.argv[1]
captures = Path(tempfile.mkdtemp(prefix="phillip-access-ui-"))
with sync_playwright() as p:
    browser = p.chromium.launch()
    context = browser.new_context(viewport={"width":1440,"height":1000})
    page = context.new_page()
    errors = []
    page.on("pageerror",lambda error: errors.append(str(error)))

    def login(target,name):
        target.goto(base)
        target.locator("[name=username]").fill(name)
        target.locator("[name=password]").fill("phillip-demo")
        target.locator("#login-form button").click()
        expect(target.locator(".shell")).to_be_visible()

    def navigate(name):
        menu=page.locator(".route-menu")
        if menu.get_attribute("open") is None:
            menu.locator("summary").click()
        page.locator('.page-link[data-page="'+name+'"]').click()
        expect(page.locator("#workspace")).to_have_attribute("data-page",name)

    def editor(label,entity):
        page.get_by_role("link",name=label,exact=True).click()
        form=page.locator('#dialog form[data-manage="'+entity+'"]')
        expect(form).to_be_visible()
        return form

    login(page,"admin")
    menu=page.locator(".route-menu")
    assert menu.get_attribute("open") is None
    menu.locator("summary").click()
    assert menu.locator("summary").bounding_box()["width"]==36
    paths=page.locator(".page-link[data-page]").evaluate_all("ns=>ns.map(n=>new URL(n.href).pathname)")
    assert paths==sorted(paths,key=str.lower),paths
    assert page.locator(".nav-section,.sidebar-bottom a[aria-label=Settings]").count()==0
    menu.locator("summary").click()
    assert page.locator(".sidebar > .tabs").bounding_box()["y"]<page.locator(".create-actions").bounding_box()["y"]
    chat=page.get_by_role("link",name="Chat",exact=True).bounding_box()
    project=page.get_by_role("link",name="Project",exact=True).bounding_box()
    assert abs(chat["width"]-project["width"])<1 and abs(chat["y"]-project["y"])<1

    for name in ["access","models","personas","knowledge","benchmarks"]:
        navigate(name)
        expect(page.locator("main h1").first).to_be_visible()
        assert page.evaluate("document.documentElement.scrollWidth<=innerWidth+1"),name
        assert page.locator('main table input:not([type=hidden]):not([type=checkbox]),main table select,main table textarea').count()==0,name
        assert "Actions" not in page.locator("main th").all_text_contents(),name
        for button in page.locator("main tfoot .row-action").all():
            assert button.inner_text().strip()=="" and button.get_attribute("aria-label").startswith("New")
    print("PASS: Flat alphabetic top menu, equal creation buttons, readonly tables and isolated pages",flush=True)

    navigate("models")
    expect(page.locator("main h1")).to_have_text("System Settings")
    assert page.locator("#task-defaults").count()==0
    expect(page.locator("#profiles")).to_contain_text("Default for")
    expect(page.locator("#profiles")).to_contain_text("Missing profile: Embeddings")
    page.get_by_role("link",name="Edit defaults for default",exact=True).click()
    defaults=page.locator("#dialog form[data-profile-defaults]")
    defaults.locator('[name="tasks[]"][value=generate_titles]').check()
    defaults.locator('[name="tasks[]"][value=summarize]').check()
    defaults.get_by_role("button",name="Save defaults",exact=True).click()
    expect(page.locator("#profiles tbody tr").filter(has_text="default")).to_contain_text("Conversation titles, Summaries")
    page.get_by_role("link",name="Edit defaults for default",exact=True).click()
    defaults=page.locator("#dialog form[data-profile-defaults]")
    expect(defaults.locator('[name="tasks[]"][value=summarize]')).to_be_checked()
    defaults.locator('[name="tasks[]"][value=summarize]').uncheck()
    defaults.locator('[name="tasks[]"][value=generate_titles]').uncheck()
    defaults.get_by_role("button",name="Save defaults",exact=True).click()
    print("PASS: Per-profile DEFAULT FOR modal replaces task-default tables and reports missing dependencies",flush=True)

    navigate("access")
    assert page.locator("#access-users table,#access-groups table").count()==2
    assert page.locator("#access-grants").count()==0
    expect(page.locator("main h1")).to_have_text("Permissions & Users")
    assert page.locator("main form:not(.switch-form)").count()==0
    page.screenshot(path=str(captures/"access.png"))
    form=editor("New user","users")
    form.locator("[name=username]").fill("ui-member")
    form.locator("[name=display_name]").fill("UI Member")
    form.locator("[name=password]").fill("phillip-demo")
    form.locator('[name="groups[]"][value=engineering]').check()
    form.locator('[name="groups[]"][value=finance]').check()
    form.get_by_role("button",name="Save",exact=True).click()
    expect(page.locator("#access-users tbody tr").filter(has_text="UI Member")).to_contain_text("Engineering, Finance")
    user_id=page.evaluate("async()=>(await PHiLLiP.api('/api/manage/users')).users.find(u=>u.username==='ui-member').id")

    form=editor("New group","groups")
    form.locator("[name=name]").fill("Browser group")
    form.locator('[name="members[]"][value="'+user_id+'"]').check()
    form.get_by_role("button",name="Save",exact=True).click()
    expect(page.locator("#access-groups tbody tr").filter(has_text="Browser group")).to_contain_text("UI Member")
    group_id=page.evaluate("async()=>(await PHiLLiP.api('/api/manage/groups')).groups.find(g=>g.name==='Browser group').id")
    form=editor("Edit user ui-member","users")
    assert form.locator('[name="groups[]"]:checked').count()==3
    form.locator('[name="groups[]"][value=finance]').uncheck()
    form.get_by_role("button",name="Save",exact=True).click()
    row=page.locator("#access-users tbody tr").filter(has_text="UI Member")
    expect(row).to_contain_text("Engineering")
    expect(row).to_contain_text("Browser group")
    expect(row).not_to_contain_text("Finance")

    form=editor("Edit tool access Tasks","tools")
    assert form.locator('[name="allowed_groups[]"][value="1"]').count()==0
    form.locator('[name="allowed_groups[]"][value="'+group_id+'"]').check()
    form.get_by_role("button",name="Save",exact=True).click()
    expect(page.locator("#tools tr").filter(has_text="Tasks")).to_contain_text("Browser group")
    navigate("models")
    page.get_by_role("link",name="New mapping",exact=True).click()
    parser=page.locator('#dialog #attachment-parsers-form')
    parser.locator('[name=type]').fill('text/plain')
    parser.locator('[name=profile]').select_option('default')
    parser.get_by_role('button',name='Save mapping',exact=True).click()
    expect(page.locator('#attachment-parsing tbody')).to_contain_text('text/plain')
    page.get_by_role("link",name="Edit parsing text/plain",exact=True).click()
    parser=page.locator('#dialog #attachment-parsers-form')
    parser.locator('[name=profile]').select_option('')
    parser.get_by_role('button',name='Save mapping',exact=True).click()
    expect(page.locator('#attachment-parsing')).to_contain_text('Built-in conversion')
    page.get_by_role("link",name="Edit service searxng",exact=True).click()
    service=page.locator('#dialog #service-form')
    service.get_by_role('button',name='Save',exact=True).click()
    expect(page.locator('#dialog')).not_to_be_visible()
    navigate("access")
    form=editor("Edit page Benchmarks","navigation")
    form.locator('[name="allowed_groups[]"][value="'+group_id+'"]').check()
    form.get_by_role("button",name="Save",exact=True).click()
    navigate("access")
    expect(page.locator("#access-groups tr").filter(has_text="Everyone")).to_contain_text("Everyone signed in")
    assert page.get_by_role("link",name="Edit group Everyone",exact=True).count()==0

    member_context=browser.new_context()
    member=member_context.new_page()
    member.on("pageerror",lambda error: errors.append(str(error)))
    login(member,"ui-member")
    member.locator(".route-menu > summary").click()
    expect(member.locator('.page-link[data-page=benchmarks]')).to_be_visible()
    expect(member.locator('.page-link[data-page=access]')).to_have_count(0)
    expect(member.locator(".new-project")).to_have_count(0)
    member.locator('.page-link[data-page=benchmarks]').click()
    expect(member.locator("[data-benchmark-root]")).to_be_visible()
    assert member.goto(base+"/access").status==403
    expect(member.get_by_role("link",name="Return to your workspace")).to_be_visible()
    print("PASS: Modal user/group editors synchronize additive memberships; group grants match direct route authorization",flush=True)

    navigate("access")
    for row in page.locator("#access-pages tbody tr").all():
        assert row.locator("td").nth(2).inner_text().strip()  # every page says who may open it
    form=editor("Edit page Memory","navigation")
    assert form.locator("[name=section],[name=position],[name=default_access],[name=enabled],[name=in_navigation]").count()==0
    assert form.locator('[name="allowed_groups[]"][value="1"]').count()==0
    expect(form).to_contain_text("Administrators always have access.")
    form.locator("[name=title]").fill("Memory library")
    form.locator('[name="allowed_users[]"][value="'+user_id+'"]').check()
    form.locator('[name="allowed_groups[]"][value="'+group_id+'"]').check()
    form.get_by_role("button",name="Save",exact=True).click()
    route=page.locator("table tr").filter(has_text="/memory")
    expect(route).to_contain_text("Memory library")
    expect(route).to_contain_text("UI Member")
    expect(route).to_contain_text("Browser group")
    assert member.goto(base+"/memory").status==200
    form=editor("Edit page Memory library","navigation")
    form.locator('[name="allowed_users[]"][value="'+user_id+'"]').uncheck()
    form.locator('[name="allowed_groups[]"][value="'+group_id+'"]').uncheck()
    form.get_by_role("button",name="Save",exact=True).click()
    assert member.goto(base+"/memory").status==403

    page.get_by_role("link",name="Project",exact=True).click()
    create=page.locator('[data-project-form=create]')
    create.locator("[name=title]").fill("Browser organized project")
    create.get_by_role("button",name="Create project",exact=True).click()
    expect(page.locator('[data-work-kind=project]')).to_contain_text("Browser organized project")
    page.get_by_role("link",name="New ToDo",exact=True).click()
    todo=page.locator('#dialog form[data-project-form="add:root"]')
    todo.locator("[name=title]").fill("Read-only parent task")
    todo.get_by_role("button",name="Add ToDo",exact=True).click()
    expect(page.locator(".todo-table")).to_contain_text("Read-only parent task")
    parent=page.locator(".todo-table .project-todo").first
    parent_id=parent.get_attribute("id")
    parent.get_by_role("link",name="Add child ToDo",exact=True).click()
    todo=page.locator("#dialog form.project-editor")
    todo.locator("[name=title]").fill("Nested child task")
    todo.get_by_role("button",name="Add ToDo",exact=True).click()
    expect(page.locator(".todo-table .project-todo")).to_have_count(2)
    assert page.locator('.todo-table input:not([type=hidden]),.todo-table textarea,.todo-table select').count()==0
    page.locator("#"+parent_id).get_by_role("link",name="Edit ToDo",exact=True).click()
    draft=page.locator("#dialog form [name=goal]")
    draft.fill("Draft survives background refresh")
    page.wait_for_timeout(5500)
    expect(draft).to_have_value("Draft survives background refresh")
    page.once("dialog",lambda d: d.accept())  # the unsaved-changes confirmation
    page.locator('#dialog [data-action=close-dialog]').click()
    expect(page.locator('#dialog')).not_to_be_visible()
    page.get_by_role("link",name="Open planning chat",exact=True).click()
    expect(page.locator("#message-form")).to_be_visible()
    assert "/projects?" in page.url

    page.get_by_role("link",name="Chat",exact=True).click()
    page.locator('#persona-start .persona-card[name=persona_id]').first.click()  # a new chat starts by choosing who to talk to
    expect(page.locator("#message-form")).to_be_visible()
    for turn in range(3):
        page.locator("#prompt").fill("Organization follow-up "+str(turn+1))
        page.get_by_role("button",name="Send message",exact=True).click()
        page.wait_for_function("n=>document.querySelectorAll('.message.assistant[data-id]').length>=n",arg=turn+1,timeout=20000)
        expect(page.locator("#live-message")).to_have_count(0,timeout=20000)
    thread=page.evaluate("PHiLLiP.current.thread.id")
    navigate("knowledge")
    page.locator('[data-thread="'+str(thread)+'"]').click()
    page.locator("#prompt").fill("A fourth message after navigation and replacement")
    page.get_by_role("button",name="Send message",exact=True).click()
    page.wait_for_function("n=>document.querySelectorAll('.message.assistant[data-id]').length>=n",arg=4,timeout=20000)
    assert page.locator(".history-table [data-work-kind=project]").count()>0
    page.screenshot(path=str(captures/"chat-history.png"))
    print("PASS: Table-based mixed history and four follow-ups survive navigation/fragment replacement",flush=True)

    page.get_by_role("button",name="Toggle light or dark mode").first.click()
    expect(page.locator("html")).to_have_attribute("data-theme","dark")
    page.set_viewport_size({"width":390,"height":844})
    page.get_by_role("button",name="Open navigation",exact=True).click()
    if page.locator(".route-menu").get_attribute("open") is None:  # settings pages keep the menu open
        page.locator(".route-menu > summary").click()
    expect(page.locator(".page-navigation")).to_be_visible()
    assert page.evaluate("document.documentElement.scrollWidth<=innerWidth+1")
    page.screenshot(path=str(captures/"mobile-dark.png"))

    plain=browser.new_context(java_script_enabled=False)
    form_page=plain.new_page()
    login(form_page,"admin")
    form_page.goto(base+"/access")
    form_page.get_by_role("link",name="New group",exact=True).click()
    form=form_page.locator('[data-manage=groups]')
    form.locator("[name=name]").fill("PHP form group")
    form.get_by_role("button",name="Save",exact=True).click()
    expect(form_page.locator("#access-groups tbody")).to_contain_text("PHP form group")
    assert not errors,errors
    print("PASS: Mobile/dark menu and PHP-only editor fallback; no browser exceptions",flush=True)
    print("Screenshots:",captures,flush=True)
    browser.close()
