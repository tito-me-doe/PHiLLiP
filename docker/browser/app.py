"""Small HTTP bridge for PHiLLiP's remote Camoufox tool."""
import base64
import hmac
import os
import time
import uuid
from contextlib import asynccontextmanager
from urllib.parse import urlsplit

from camoufox.async_api import AsyncCamoufox
from fastapi import FastAPI, Request
from fastapi.responses import JSONResponse

sessions = {}
browser = None


@asynccontextmanager
async def lifespan(app):
    global browser
    async with AsyncCamoufox(headless=True, firefox_user_prefs={"dom.security.https_first": False, "dom.security.https_only_mode": False}) as launched:
        browser = launched
        yield
        for session in sessions.values():
            await session["context"].close()


app = FastAPI(lifespan=lifespan)


@app.get("/health")
async def health():
    return {"status": "ok", "engine": "camoufox", "sessions": len(sessions)}


@app.post("/browse")
async def browse(request: Request):
    expected = "Bearer " + os.environ["BROWSER_TOKEN"]
    if not hmac.compare_digest(request.headers.get("authorization", ""), expected):
        return JSONResponse({"error": "Browser service authentication required"}, 401)
    data = await request.json()
    scope = data.get("scope", "application")
    action = data.get("action", "read")
    session_id = data.get("session", "")
    try:
        for key, item in list(sessions.items()):
            if time.time() - item["used"] > 900:
                await item["context"].close()
                del sessions[key]
        if action == "open":
            if urlsplit(data.get("url", "")).scheme not in ("http", "https"):
                raise ValueError("Browser requires an HTTP or HTTPS URL")
            if len(sessions) >= 24:
                raise ValueError("Browser session limit reached; close an existing session")
            context = await browser.new_context()
            page = await context.new_page()
            session_id = uuid.uuid4().hex
            sessions[session_id] = {"page": page, "context": context, "scope": scope, "used": time.time()}
            try:
                await page.goto(data["url"], wait_until="domcontentloaded", timeout=25000)
            except Exception:
                await context.close()
                del sessions[session_id]
                raise
        item = sessions.get(session_id)
        if item is None or item["scope"] != scope:
            raise ValueError("Unknown browser session in this thread")
        item["used"] = time.time()
        page = item["page"]
        if action == "close":
            await item["context"].close()
            del sessions[session_id]
            return {"session": session_id, "status": "closed"}
        if action == "screenshot":
            png = await page.screenshot(full_page=False, type="png", timeout=10000)
            if len(png) > 2 * 1024 * 1024:
                raise ValueError("Screenshot exceeds 2 MiB")
            return {"session": session_id, "url": page.url, "title": await page.title(), "image": "data:image/png;base64," + base64.b64encode(png).decode("ascii")}
        if action == "navigate":
            if urlsplit(data.get("url", "")).scheme not in ("http", "https"):
                raise ValueError("Browser requires an HTTP or HTTPS URL")
            await page.goto(data["url"], wait_until="domcontentloaded", timeout=25000)
        elif action == "select":
            await page.locator(data["selector"]).first.select_option(value=data["value"], timeout=10000)
        elif action == "click":
            await page.locator(data["selector"]).first.click(timeout=10000)
        elif action == "type":
            await page.locator(data["selector"]).first.fill(data["value"], timeout=10000)
        elif action == "scroll":
            await page.mouse.wheel(0, int(data.get("distance", 800)))
        elif action not in ("open", "read"):
            raise ValueError("Unknown browser action")
        await page.wait_for_timeout(200)
        text = await page.locator(data.get("selector", "body") if action == "read" else "body").first.inner_text(timeout=10000)
        links = await page.locator("a[href]").evaluate_all("nodes => nodes.filter(a => a.getClientRects().length).slice(0, 50).map(a => ({text:a.innerText, url:a.href, selector:a.id ? '#' + CSS.escape(a.id) : 'a[href=' + JSON.stringify(a.getAttribute('href')) + ']'}))")
        inputs = await page.locator("input,textarea,select,button").evaluate_all("""nodes => {
            const dialog = document.querySelector('dialog[open]');
            const selector = node => {
                const parts = [];
                for (let el = node; el; el = el.parentElement) {
                    if (el.id) { parts.unshift('#' + CSS.escape(el.id)); break; }
                    let part = el.tagName.toLowerCase();
                    if (el.parentElement) {
                        const siblings = [...el.parentElement.children].filter(n => n.tagName === el.tagName);
                        if (siblings.length > 1) part += ':nth-of-type(' + (siblings.indexOf(el) + 1) + ')';
                    }
                    parts.unshift(part);
                }
                return parts.join(' > ');
            };
            return nodes.filter(a => (!dialog || dialog.contains(a)) && a.type !== 'hidden' && a.getClientRects().length && getComputedStyle(a).visibility !== 'hidden')
                .sort((a, b) => Number(a.tagName === 'BUTTON') - Number(b.tagName === 'BUTTON')).slice(0, 40)
                .map(a => ({tag:a.tagName, id:a.id, name:a.name, type:a.type, text:(a.innerText || '').slice(0, 500), placeholder:a.placeholder,
                    selector:selector(a), enabled:!a.disabled, value:a.type === 'password' ? '[hidden]' : typeof a.value === 'string' ? a.value.slice(0, 2000) : '', label:[...(a.labels || [])].map(n => n.textContent.trim()).join(' ').slice(0, 160),
                    ...(a.tagName === 'SELECT' ? {options:[...a.options].slice(0, 40).map(o => ({value:o.value, label:o.label, selected:o.selected}))} : {})}));
        }""")
        return {"session": session_id, "url": page.url, "title": await page.title(), "text": text[:20000], "links": links, "controls": inputs}
    except Exception as error:
        return JSONResponse({"error": str(error)}, 400)
