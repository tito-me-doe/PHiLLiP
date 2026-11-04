(() => {
  // The same navigation is a modal drawer only at the mobile breakpoint.
  const mobileNav = window.matchMedia("(max-width: 720px)");
  const nav = document.querySelector("#primary-navigation");
  const backdrop = document.querySelector(".sidebar-backdrop");
  const routeMenu = nav?.querySelector(".route-menu");
  let drawerLastTrigger = null;
  let routeWasOpen = false;
  const drawerOpen = () => Boolean(nav?.classList.contains("menu-open"));
  const triggers = () => document.querySelectorAll('[data-action="menu"][aria-controls="primary-navigation"]');

  function closeDrawer(restoreFocus = true) {
    if (!drawerOpen()) return;
    nav.classList.remove("menu-open");
    nav.removeAttribute("role");
    nav.removeAttribute("aria-modal");
    if (routeMenu) routeMenu.open = routeWasOpen;
    triggers().forEach((button) => button.setAttribute("aria-expanded", "false"));
    if (mobileNav.matches) nav.inert = true;
    const trigger = drawerLastTrigger;
    drawerLastTrigger = null;
    if (restoreFocus && trigger?.isConnected && trigger.getClientRects().length) trigger.focus();
  }

  function openDrawer(trigger) {
    if (!nav || !mobileNav.matches || drawerOpen()) return;
    drawerLastTrigger = trigger;
    routeWasOpen = Boolean(routeMenu?.open);
    nav.inert = false;
    nav.classList.add("menu-open");
    nav.setAttribute("role", "dialog");
    nav.setAttribute("aria-modal", "true");
    triggers().forEach((button) => button.setAttribute("aria-expanded", "true"));
    if (routeMenu) routeMenu.open = true;
    nav.querySelector(".mobile-nav-close")?.focus();
  }

  // Choosing to close the pages menu is remembered for the session; otherwise settings pages render it open.
  routeMenu?.querySelector("summary")?.addEventListener("click", () => {
    if (mobileNav.matches) return;
    if (routeMenu.open) sessionStorage.setItem("phillip.pagesMenu", "closed");
    else sessionStorage.removeItem("phillip.pagesMenu");
  });
  if (routeMenu?.open && !mobileNav.matches && sessionStorage.getItem("phillip.pagesMenu") === "closed") routeMenu.open = false;

  function toggleDrawer(trigger) {
    if (drawerOpen()) closeDrawer(); else openDrawer(trigger);
  }

  function syncNavigation() {
    if (!nav) return;
    if (!mobileNav.matches) closeDrawer(false);
    nav.inert = mobileNav.matches && !drawerOpen();
  }
  syncNavigation();
  mobileNav.addEventListener("change", syncNavigation);
  document.addEventListener("keydown", (event) => {
    if (!drawerOpen()) return;
    if (event.key === "Escape") {
      event.preventDefault();
      event.stopImmediatePropagation();
      closeDrawer();
    } else if (event.key === "Tab") {
      const items = Array.from(nav.querySelectorAll('a[href], button:not([disabled]), summary, input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'))
        .filter((item) => item.getClientRects().length && !item.closest('[inert]'));
      if (!items.length) { event.preventDefault(); return; }
      const first = items[0], last = items[items.length - 1];
      if (event.shiftKey && (document.activeElement === first || !nav.contains(document.activeElement))) {
        event.preventDefault(); last.focus();
      } else if (!event.shiftKey && (document.activeElement === last || !nav.contains(document.activeElement))) {
        event.preventDefault(); first.focus();
      }
    }
  }, true);
  document.addEventListener("click", (event) => {
    if (!drawerOpen()) return;
    if (event.target === backdrop || event.target.closest(".mobile-nav-close")) {
      event.preventDefault(); closeDrawer();
    } else if (nav.contains(event.target) && event.target.closest("a[href], [data-thread]")) {
      closeDrawer(Boolean(event.target.closest("[data-thread]")));
    }
  });
  const A = (window.PHiLLiP = {
    Log: window.PHiLLiPLog,
    base: document.body.dataset.base || "",
    state: JSON.parse(document.querySelector("#initial-state").textContent),
    current: null,
    cursor: 0,
    workflowCursor: 0,
    panels: {},
    epoch: 0,
    polling: false,
    pollRequested: false,
    liveRunId: null,
    live: "",
    reasoning: "",
    progress: "",
    attachments: [],
    newSettings: null,
    newPrompt: null,
    persistUI(patch) {
      Object.assign(A.ui, patch);
      try {
        localStorage.setItem(A.storageKey, JSON.stringify(A.ui));
      } catch {
        A.toast(
          "Browser storage is unavailable; UI preferences will last for this page only.",
        );
      }
    },
    // User-scoped per-thread composer drafts live in localStorage (UI state, not the database).
    // A.storageKey already isolates by user id, so logout/user switching gives a fresh key.
    // Draft text is preserved in full; only the number of stored threads is bounded.
    draftMaxThreads: 50,
    draftKey(threadId) {
      return threadId ? String(threadId) : "new";
    },
    saveDraft(text) {
      if (!A.storageKey) return;
      const threadId = A.current ? A.current.thread.id : null,
        key = A.draftKey(threadId),
        drafts = { ...(A.ui.drafts || {}) };
      if (text === "" || text == null) {
        delete drafts[key];
      } else {
        drafts[key] = String(text);
      }
      // Bound the number of stored thread drafts: drop the first non-active entry
      // whenever we exceed the limit.
      let savedKeys = Object.keys(drafts);
      while (savedKeys.length > A.draftMaxThreads) {
        let removed = false;
        for (const k of savedKeys) {
          if (k !== key) {
            delete drafts[k];
            removed = true;
            break;
          }
        }
        if (!removed) break;
        savedKeys = Object.keys(drafts);
      }
      A.persistUI({ drafts });
    },
    restoreDraft() {
      if (!A.storageKey || !document.querySelector("#prompt")) return "";
      const threadId = A.current ? A.current.thread.id : null,
        key = A.draftKey(threadId),
        saved = (A.ui.drafts && A.ui.drafts[key]) || "";
      document.querySelector("#prompt").value = saved;
      return saved;
    },
    newKey() {
      return crypto.randomUUID
        ? crypto.randomUUID()
        : Array.from(crypto.getRandomValues(new Uint8Array(16)), (b) =>
            b.toString(16).padStart(2, "0"),
          ).join("");
    },
    page(id, query = {}) {
      const path = A.state.pages?.[id];
      if (!path) throw new Error("This page is not available to your account.");
      return (
        path +
        (Object.keys(query).length ? "?" + new URLSearchParams(query) : "")
      );
    },
    threadURL(id) {
      const project = A.current?.thread.project_id;
      return project
        ? A.page("projects", { project, thread: id })
        : A.page("conversations", { thread: id });
    },
    setTheme(theme) {
      theme = theme === "dark" ? "dark" : "light";
      A.persistUI({ theme });
      document.documentElement.dataset.theme = theme;
      if (A.state.theme_cookie)
        document.cookie =
          A.state.theme_cookie +
          "=" +
          theme +
          "; Path=" +
          (A.base || "") +
          "/; SameSite=Lax; Max-Age=31536000" +
          (location.protocol === "https:" ? "; Secure" : "");
    },
    operationProgress(container, job, label = "Discovery") {
      const status = job?.status || "queued",
        active = ["queued", "running"].includes(status);
      container.dataset.status = status;
      container.setAttribute("aria-busy", String(active));
      if (container.dataset.operation !== String(job?.id) + status) {
        container.dataset.operation = String(job?.id) + status;
        container.replaceChildren();
        const line = document.createElement("div");
        line.className = "operation-line";
        const icon = document.createElement("span");
        icon.className = active ? "spinner" : "operation-check";
        icon.textContent = active ? "" : status === "completed" ? "✓" : "!";
        icon.setAttribute("aria-hidden", "true");
        const text = document.createElement("span");
        text.textContent =
          label +
          " · " +
          (status === "queued"
            ? "waiting to start"
            : status === "running"
              ? "in progress…"
              : status === "completed"
                ? "complete"
                : "needs attention");
        line.append(icon, text);
        const meter = document.createElement("progress");
        meter.max = 1;
        meter.setAttribute("aria-label", label + " progress");
        if (!active) meter.value = status === "completed" ? 1 : 0;
        container.append(line, meter);
      }
    },
    async action(
      action,
      callback,
      { form, success = action + " completed", reload = false, redirect } = {},
    ) {
      const started = performance.now(),
        previous = new Set(A.Log.records.map((record) => record.id));
      A.Log.writeDebug({ action, status: "started" });
      try {
        const result = await callback();
        const outcome = A.Log.records.find(
          (record) =>
            !previous.has(record.id) && record.priority === 0 && record.visible,
        );
        A.Log.writeDebug({
          action,
          status: result === false ? "skipped" : "success",
          elapsed_ms: Math.round(performance.now() - started),
          thread_id: A.current?.thread.id,
        });
        if (result === false) return;
        if (reload || redirect) {
          try {
            sessionStorage.setItem(
              "phillip.action-outcome",
              outcome?.message || success,
            );
          } catch {}
          if (redirect) location.href = redirect;
          else location.reload();
        } else if (!outcome) {
          const record = A.Log.writeSuccess(success);
          if (!record?.visible || !document.querySelector("#phillip-messages"))
            A.toast(success);
        }
        return result;
      } catch (error) {
        A.Log.writeDebug({
          action,
          status: "error",
          http_status: error.status,
          elapsed_ms: Math.round(performance.now() - started),
          thread_id: A.current?.thread.id,
        });
        A.Log.trace({ action, status: "error" }, error);
        const reported = A.Log.records.some(
          (record) =>
            !previous.has(record.id) && record.priority >= 1 && record.visible,
        );
        const record = reported ? null : A.Log.writeError(error.message);
        if (form) A.error(form, error);
        else if (
          !reported &&
          (!record?.visible || !document.querySelector("#phillip-messages"))
        )
          A.toast(error.message);
      }
    },
    async request(path, body) {
      const started = performance.now(),
        route = path.split("?")[0],
        method = body === undefined ? "GET" : "POST";
      A.Log.writeDebug({ action: "request", route, method, status: "started" });
      try {
        const r = await fetch(A.base + path, {
          credentials: "same-origin",
          ...(body !== undefined
            ? {
                method: "POST",
                headers: {
                  "Content-Type": "application/json",
                  "X-CSRF-Token": A.state.csrf,
                },
                body: JSON.stringify(body),
              }
            : {}),
        });
        A.Log.fromResponse(r);
        A.Log.writeDebug({
          action: "request",
          route,
          method,
          http_status: r.status,
          elapsed_ms: Math.round(performance.now() - started),
        });
        return r;
      } catch (error) {
        A.Log.writeDebug({
          action: "request",
          route,
          method,
          status: "connection_error",
          elapsed_ms: Math.round(performance.now() - started),
        });
        throw error;
      }
    },
    async api(path, body) {
      const r = await A.request(path, body);
      let data;
      try {
        data = await r.json();
      } catch {
        throw new Error(
          "The server returned an unreadable response. Check its logs.",
        );
      }
      if (
        !/^\/api\/(?:login|logout|state|setup)(?:\/|$)/.test(path.split("?")[0])
      )
        A.Log.trace({ action: "api", path, request: body, response: data });
      if (!r.ok || data.error)
        throw Object.assign(new Error(data.error || r.statusText), {
          status: r.status,
        });
      return data;
    },
    async html(path, body) {
      const r = await A.request(path, body);
      const html = await r.text();
      A.Log.writeDebug({
        action: "fragment",
        route: path.split("?")[0],
        response_bytes: html.length,
      });
      if (!r.ok) {
        const d = new DOMParser().parseFromString(html, "text/html");
        throw Object.assign(new Error(d.body.textContent), {
          status: r.status,
        });
      }
      return html;
    },
    toast(message) {
      const el = document.querySelector("#toast");
      el.textContent = message;
      el.classList.add("visible");
      clearTimeout(A.toastTimer);
      A.toastTimer = setTimeout(() => el.classList.remove("visible"), 5000);
    },
    error(form, e) {
      const node =
        form.querySelector(".form-error") ||
        document.querySelector("#" + form.id.replace("-form", "") + "-error");
      if (node) node.textContent = e.message;
      else A.toast(e.message);
    },
    enhance(root = document) {
      root
        .querySelectorAll("pre code:not([data-highlighted])")
        .forEach((code) => {
          const lang = (code.className.match(/language-(\S+)/) || [])[1];
          if (!lang || PHiLLiPLibs.hljs.getLanguage(lang))
            PHiLLiPLibs.hljs.highlightElement(code);
        });
      root.querySelectorAll(".message-body a").forEach((a) => {
        a.target = "_blank";
        a.rel = "noopener noreferrer";
      });
    },
    readChat() {
      const node = document.querySelector("[data-chat-state]");
      if (node) {
        const data = JSON.parse(node.textContent);
        A.current = data.thread ? { thread: data.thread, runs: [] } : null;
        A.selectedProfile = data.selectedProfile;
        A.setCompanion?.(A.current ? A.current.thread.settings : {});
        A.workflowCursor = 0;
        A.adoptWorkflow(data.workflow);
        A.cursor = data.workflow?.event_cursor || 0;
        node.remove();
      }
      const runs = document.querySelector("[data-runs-state]");
      if (runs && A.current) {
        const data = JSON.parse(runs.textContent);
        A.adoptWorkflow(data.workflow);
        if (
          typeof data.title === "string" &&
          data.title !== A.current.thread.title
        ) {
          A.current.thread.title = data.title;
          A.titleChanged = true;
          const title = document.querySelector("#chat-title");
          if (title) title.textContent = data.title || "New conversation";
        }
        runs.remove();
      }
      A.panels = A.current ? A.ui.panels?.[A.current.thread.id] || {} : {};
      if (A.current) {
        const keys = new Set(
          [...document.querySelectorAll("#messages details[data-key]")].map(
            (d) => d.dataset.key,
          ),
        );
        const valid = Object.fromEntries(
          Object.entries(A.panels).filter(([key]) => keys.has(key)),
        );
        if (Object.keys(valid).length !== Object.keys(A.panels).length) {
          A.panels = valid;
          A.persistUI({
            panels: { ...A.ui.panels, [A.current.thread.id]: valid },
          });
        }
      }
      document
        .querySelectorAll("#messages details[data-key]")
        .forEach((d) => (d.open = Boolean(A.panels[d.dataset.key])));
    },
    adoptWorkflow(workflow) {
      if (!workflow || !A.current || workflow.event_cursor < A.workflowCursor)
        return;
      A.current.runs = workflow.runs;
      A.workflowCursor = workflow.event_cursor;
      A.Log.reconcile(
        A.current.thread.id,
        workflow.runs.map((run) => run.id),
      );
      const active = workflow.runs.find((r) => r.status === "running");
      A.liveRunId = active ? String(active.id) : null;
      A.live = active?.state.content || "";
      A.reasoning = active?.state.reasoning || "";
      A.progress = active?.state.progress || "";
    },
    async refreshMessages() {
      if (!A.current) return;
      const id = A.current.thread.id,
        epoch = A.epoch,
        revision = (A.messageRevision = (A.messageRevision || 0) + 1),
        container = document.querySelector("#messages");
      if (!container) return;
      const scroll = document.querySelector("#chat-scroll"),
        bottom =
          scroll.scrollHeight - scroll.scrollTop - scroll.clientHeight < 150;
      const opened = [
        ...container.querySelectorAll("details[open][data-key]"),
      ].map((d) => d.dataset.key);
      const html = await A.html("/fragments/messages?thread=" + id);
      if (
        A.current?.thread.id !== id ||
        A.epoch !== epoch ||
        revision !== A.messageRevision ||
        !container.isConnected
      )
        return;
      const meta = new DOMParser()
        .parseFromString(html, "text/html")
        .querySelector("[data-runs-state]");
      if (
        meta &&
        JSON.parse(meta.textContent).workflow.event_cursor < A.workflowCursor
      )
        return;
      container.innerHTML = html;
      A.readChat();
      container.querySelectorAll("details[data-key]").forEach((d) => {
        if (opened.includes(d.dataset.key)) d.open = true;
      });
      A.enhance(container);
      A.updateLive();
      if (bottom) scroll.scrollTop = scroll.scrollHeight;
      if (A.titleChanged) {
        A.titleChanged = false;
        await A.refreshThreads();
      }
    },
    async refreshThreads() {
      const list = document.querySelector("#thread-list");
      if (!list) return;
      const epoch = A.epoch,
        revision = (A.historyRevision = (A.historyRevision || 0) + 1);
      const tab =
        A.current?.thread.visibility ||
        new URL(location.href).searchParams.get("tab") ||
        "private";
      const html = await A.html(
        "/fragments/threads?tab=" +
          encodeURIComponent(tab) +
          (A.current ? "&thread=" + A.current.thread.id : ""),
      );
      if (
        !list.isConnected ||
        epoch !== A.epoch ||
        revision !== A.historyRevision
      )
        return;
      list.innerHTML = html;
      document
        .querySelectorAll(".tabs a")
        .forEach((link) =>
          link.classList.toggle(
            "active",
            new URL(link.href).searchParams.get("tab") === tab,
          ),
        );
    },
    async openThread(id) {
      if (A.uploading) {
        A.toast("Wait for the current upload before changing conversations.");
        return;
      }
      clearTimeout(A.pollTimer);
      const epoch = ++A.epoch;
      A.cursor = 0;
      A.liveRunId = null;
      A.live = "";
      A.reasoning = "";
      A.progress = "";
      A.resetAvatar?.();
      let html;
      try {
        html = await A.html("/fragments/thread?thread=" + id);
      } catch (error) {
        // A failed or superseded navigation must not leave the old view unpollled.
        if (A.epoch !== epoch) return;
        A.toast(error.message || "Failed to open conversation.");
        if (A.current) A.poll();
        return;
      }
      if (A.epoch !== epoch) return;
      const restoreMenuFocus = document.activeElement?.matches('[data-action="menu"][aria-controls="primary-navigation"]');
      document.querySelector("#workspace").innerHTML = html;
      if (restoreMenuFocus) document.querySelector('[data-action="menu"][aria-controls="primary-navigation"]')?.focus();
      A.readChat();
      A.restoreDraft();
      history.pushState({}, "", A.threadURL(id));
      const page = A.current.thread.project_id ? "projects" : "conversations";
      document.querySelector("#workspace").dataset.page = page;
      document.querySelectorAll(".page-link").forEach((link) => {
        link.classList.toggle("active", link.dataset.page === page);
        if (link.dataset.page === page)
          link.setAttribute("aria-current", "page");
        else link.removeAttribute("aria-current");
      });
      A.bindChat();
      A.enhance();
      await A.refreshThreads();
      A.poll();
      A.persistUI({
        lastThread: Number(id),
        chatTab: A.current.thread.visibility,
      });
    },
    // Snapshot of an editable dialog form's values, captured after PHP hydration.
    // File controls compare by stable name+size (File instances are not reusable
    // across parses); disabled/submitting controls never reach FormData.
    dialogSnapshot(form) {
      const fd = new FormData(form),
        out = [];
      for (const [name, value] of fd)
        out.push(
          value instanceof File
            ? [name, "file", value.name, value.size]
            : [name, String(value)],
        );
      return out;
    },
    // True when the edited form differs from its hydrated baseline.
    dialogChanged(form) {
      if (!A._dialogBaseline) return false;
      return (
        JSON.stringify(A.dialogSnapshot(form)) !==
        JSON.stringify(A._dialogBaseline)
      );
    },
    // Shared dismissal guard for Escape, close buttons, and dialog replacement.
    // savedForm: the form whose save succeeded. Supplied saves close only when
    // they match the dialog's current form and skip the discard prompt.
    // Returns false when the user cancels, leaving the edited form untouched.
    closeDialog(savedForm = null) {
      const dialog = document.querySelector("#dialog");
      if (!dialog) return true;
      const form = dialog.querySelector("form");
      if (savedForm && savedForm !== form) return true;
      if (
        form &&
        A._dialogBaseline &&
        !savedForm &&
        A.dialogChanged(form)
      ) {
        if (
          !confirm(
            "You have unsaved changes. Discard them and close this dialog?",
          )
        )
          return false;
      }
      A._dialogBaseline = null;
      const trigger = A._dialogTrigger;
      const descriptor = A._dialogDescriptor;
      A._dialogTrigger = null;
      A._dialogDescriptor = null;
      dialog.close();
      A.restoreDialogFocus(trigger, descriptor);
      return true;
    },
    // True only when node is connected, rendered, enabled, and not a hidden input.
    // Truly visible: connected, enabled, not hidden-input, computed visibility
    // shown, and either offsetParent-placed or having client rects.
    visibleNode(node) {
      if (!node || !node.isConnected || node.hidden) return false;
      if (node.matches("input[type=hidden]")) return false;
      if (node.disabled) return false;
      const cs =
        node.ownerDocument && node.ownerDocument.defaultView
          ? node.ownerDocument.defaultView.getComputedStyle(node)
          : undefined;
      if (cs && (cs.visibility === "hidden" || cs.visibility === "collapse"))
        return false;
      if (node.offsetParent !== null) return true;
      return Boolean(node.getClientRects && node.getClientRects().length);
    },
    // First *visibly* focusable interactive element within root, or null.
    focusableWithin(root) {
      if (!root) return null;
      const nodes = root.querySelectorAll(
        "button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex='-1']):not([disabled])"
      );
      for (const node of nodes) {
        if (this.visibleNode(node)) return node;
      }
      return null;
    },
    // Move focus to a meaningful element inside the open dialog.
    focusDialog() {
      const dialog = document.querySelector("#dialog");
      if (!dialog || !dialog.open) return;
      const content = document.querySelector("#dialog-content");
      let target = this.focusableWithin(content);
      if (!target) {
        const title = document.querySelector("#dialog-title");
        if (title) {
          // Persistent heading fallback must be focusable before focusing it.
          if (!title.hasAttribute("tabindex")) title.setAttribute("tabindex", "-1");
          target = title;
        }
      }
      if (!target) target = dialog;
      target.focus({ preventScroll: true });
    },

    // Stable descriptor for an opener trigger: id plus the benchmark
    // data/href attributes already used by benchmarks' focusKey, so focus can
    // be restored to the same logical element after a root refresh. Keys are
    // the live DOM attribute names themselves so a single entries-loop does
    // the matching without duplicating manual getAttribute checks.
    dialogDescriptorFor(element) {
      if (!element) return null;
      const attrs = [
        "id",
        "data-benchmark-control",
        "data-benchmark-tab",
        "data-benchmark-result",
        "data-benchmark-profile-action",
        "data-benchmark-profile-id",
        "href",
      ];
      const desc = {};
      for (const name of attrs) {
        const val = element.getAttribute(name);
        if (val !== null) desc[name] = val;
      }
      return Object.keys(desc).length ? desc : null;
    },
    // True when element matches every defined part of a stored descriptor.
    dialogElementMatches(element, desc) {
      if (!element || !desc) return false;
      return Object.entries(desc).every(
        ([name, val]) => element.getAttribute(name) === val,
      );
    },
    // Restore focus after the dialog closes: preferred trigger if still
    // visible, otherwise the same logical element matched by descriptor
    // (survives a root refresh), otherwise the first visible focusable page
    // element. Never the detached close button or BODY.
    restoreDialogFocus(preferred, descriptor) {
      if (preferred && this.visibleNode(preferred)) {
        preferred.focus({ preventScroll: true });
        return;
      }
      if (descriptor) {
        const candidates = document.querySelectorAll(
          "[id], [data-benchmark-control], [data-benchmark-tab], [data-benchmark-result], [data-benchmark-profile-action], a[href]"
        );
        for (const node of candidates) {
          if (
            this.dialogElementMatches(node, descriptor) &&
            !node.disabled &&
            this.visibleNode(node)
          ) {
            node.focus({ preventScroll: true });
            return;
          }
        }
      }
      const fallback = this.focusableWithin(document.documentElement);
      if (fallback) fallback.focus({ preventScroll: true });
    },
    async dialog(kind, params = {}, opener = null) {
      // Capture the external opener on the first open. An explicit opener
      // wins (data-dialog delegates pass the clicked target, important for
      // SVG graph links); direct API callers fall back to activeElement.
      // Always capture opener + descriptor on a genuine first open; nested
      // opens retain the existing original trigger. Duplicate capture
      // removed from the data-dialog delegate.
      if (!document.querySelector("#dialog")?.open && !A._dialogTrigger) {
        A._dialogTrigger = opener ?? (document.activeElement || null);
        A._dialogDescriptor = A.dialogDescriptorFor(A._dialogTrigger);
      }
      const openDialog = document.querySelector("#dialog");
      if (openDialog && openDialog.open) {
        const openForm = openDialog.querySelector("form");
        if (openForm && A._dialogBaseline && A.dialogChanged(openForm)) {
          if (
            !confirm(
              "You have unsaved changes. Discard them and open another dialog?",
            )
          )
            return false;
        }
      }
      const query = new URLSearchParams({
        kind,
        ...(A.current ? { thread: A.current.thread.id } : {}),
        ...params,
      });
      const html = await A.html("/fragments/dialog?" + query),
        dialog = document.querySelector("#dialog");
      document.querySelector("#dialog-content").innerHTML = html;
      // Expose the PHP-rendered first heading as the dialog's accessible name
      // on every replacement, so it announces 'Edit question',
      // 'Conversation settings', etc. Generic 'Dialog' only if there is no
      // heading.
      const dlgTitle = document.querySelector("#dialog-title");
      const heading = dialog.querySelector("h1, h2, h3, h4, h5, h6");
      if (dlgTitle) {
        dlgTitle.textContent = heading ? heading.textContent.trim() : "Dialog";
        dialog.setAttribute("aria-labelledby", "dialog-title");
        dialog.setAttribute("role", "dialog");
      }
      if (kind === "chat" && !A.current) {
        const f = document.querySelector("#chat-settings-form");
        if (A.newSettings) {
          f.elements.profile.value = A.newSettings.profile;
          f.querySelectorAll("[name=tools]").forEach(
            (n) => (n.checked = A.newSettings.tools.includes(n.value)),
          );
          f.elements.temperature.value =
            A.newSettings.parameters.temperature ?? 0.65;
          f.elements.max_tokens.value =
            A.newSettings.parameters.max_tokens ?? 4096;
          f.elements.assistant_name.value =
            A.newSettings.assistant_name || "PHiLLiP";
          f.elements.contract.value =
            typeof A.newSettings.contract === "string"
              ? A.newSettings.contract
              : A.newSettings.contract
                ? JSON.stringify(A.newSettings.contract, null, 2)
                : "";
        }
        if (A.newPrompt !== null) f.elements.system_prompt.value = A.newPrompt;
      }
      dialog.dataset.kind = kind;
      if (!dialog.open) dialog.showModal();
      if (!dialog.dataset.cancelBound) {
        dialog.oncancel = (e) => {
          e.preventDefault();
          A.closeDialog();
        };
        dialog.dataset.cancelBound = "1";
      }
      A.bindSettings?.();
      A.bindDialog();
      const dlgForm = dialog.querySelector("form");
      A._dialogBaseline = dlgForm ? A.dialogSnapshot(dlgForm) : null;
      // Focus a meaningful element in the dialog on every replacement,
      // including nested replacements while the dialog is already open.
      A.focusDialog();
    },
    bindDialog() {
      const edit = document.querySelector("#edit-message");
      if (edit)
        edit.onsubmit = (e) => {
          e.preventDefault();
          A.action(
            "Edit message",
            async () => {
              await A.api("/api/messages/" + edit.dataset.id + "/edit", {
                content: edit.elements.content.value,
              });
              A.closeDialog(edit);
              A.live = "";
              A.reasoning = "";
              await A.refreshMessages();
              A.poll();
            },
            { form: edit, success: "Edited message queued" },
          );
        };
      const cfg = document.querySelector("#chat-settings-form");
      if (cfg)
        cfg.onsubmit = async (e) => {
          e.preventDefault();
          const f = new FormData(cfg),
            settings = {
              profile: f.get("profile"),
              tools: f.getAll("tools"),
              assistant_name: f.get("assistant_name"),
              contract: f.get("contract"),
              parameters: {
                temperature: Number(f.get("temperature")),
                max_tokens: Number(f.get("max_tokens")),
                stream: true,
              },
            };
          await A.action(
            "Save conversation settings",
            async () => {
              if (A.current) {
                const id = A.current.thread.id;
                await A.api(`/api/threads/${id}/settings`, {
                  settings,
                  system_prompt: f.get("system_prompt"),
                });
                const vis = f.get("visibility");
                await A.api(`/api/threads/${id}/share`, {
                  visibility: vis,
                  team_id: vis === "team" ? f.get("team_id") : null,
                });
                await A.openThread(id);
              } else {
                A.newSettings = settings;
                A.selectedProfile = settings.profile;
                A.newPrompt = f.get("system_prompt");
                A.persistUI({
                  draft: { settings, system_prompt: A.newPrompt },
                });
              }
              A.closeDialog(cfg);
            },
            { form: cfg, success: "Conversation settings saved" },
          );
        };
      document
        .querySelector("#save-as-persona")
        ?.addEventListener("click", async () => {
          try {
            const values = {
              prompt: cfg.elements.system_prompt.value,
              contract: cfg.elements.contract.value,
              tools: [...cfg.querySelectorAll("[name=tools]:checked")]
                .map((n) => n.value)
                .join(", "),
            };
            if ((await A.dialog("persona")) === false) return;
            const personaForm = document.querySelector("#persona-form");
            for (const [key, value] of Object.entries(values)) {
              // A character has no raw prompt field: the saved prompt becomes the description of who they are.
              const field =
                personaForm.elements[key] ||
                (key === "prompt" ? personaForm.elements.bio : null);
              if (field) field.value = value;
            }
          } catch (error) {
            A.toast(error.message);
          }
        });
    },
    bindChat() {
      const form = document.querySelector("#message-form");
      if (!form) return;
      form.onsubmit = (e) => {
        e.preventDefault();
        A.action("Send message", () => A.send(), { success: "Message queued" });
      };
      document.querySelector("#prompt").onkeydown = (e) => {
        if (e.key === "Enter" && !e.shiftKey && !e.isComposing) {
          e.preventDefault();
          form.requestSubmit();
        }
      };
      document.querySelector("#prompt").oninput = () => {
        A.saveDraft(document.querySelector("#prompt").value);
      };
      const personas = document.querySelector("#persona-start");
      if (personas)
        personas.onsubmit = async (e) => {
          e.preventDefault();
          if (A.choosingPersona || !e.submitter) return;
          A.choosingPersona = true;
          const id = e.submitter.value,
            enabled = [...personas.querySelectorAll("button:not(:disabled)")];
          enabled.forEach((b) => (b.disabled = true));
          try {
            await A.action(
              "Choose persona",
              async () => {
                const { thread } = await A.api("/api/threads", {
                  title: "",
                  creation_key: personas.elements.creation_key.value,
                  settings: {
                    ...(A.newSettings || {
                      profile: A.selectedProfile,
                      parameters: { stream: true },
                    }),
                    persona: id,
                  },
                });
                A.newSettings = null;
                A.newPrompt = null;
                A.persistUI({ draft: null });
                const open = () => A.openThread(thread.id),
                  avatar = e.submitter.querySelector(".avatar");
                // The chosen card's avatar glides to the companion's place; browsers without view transitions just swap.
                if (avatar && document.startViewTransition && !matchMedia("(prefers-reduced-motion: reduce)").matches) {
                  avatar.style.viewTransitionName = "persona";
                  await document.startViewTransition(open).updateCallbackDone;
                } else await open();
                document.querySelector("#prompt").focus();
              },
              { form: personas, success: "Persona selected" },
            );
          } finally {
            A.choosingPersona = false;
            if (personas.isConnected)
              enabled.forEach((b) => (b.disabled = false));
          }
        };
      const rename = document.querySelector("#rename-form");
      if (rename)
        rename.onsubmit = (e) => {
          e.preventDefault();
          const id = A.current.thread.id;
          A.action(
            "Rename conversation",
            async () => {
              const { thread } = await A.api(`/api/threads/${id}/title`, {
                title: rename.elements.title.value,
              });
              if (A.current?.thread.id === id) {
                A.current.thread.title = thread.title;
                document.querySelector("#chat-title").textContent =
                  thread.title;
                A.showRename(false);
              }
              await A.refreshThreads();
            },
            { form: rename, success: "Conversation renamed" },
          );
        };
      document.querySelector("#files").onchange = (e) =>
        A.attachFiles?.(e.target.files);
      A.restoreAttachments?.();
      A.syncTools();
      A.updateLive();
    },
    showRename(show) {
      const form = document.querySelector("#rename-form");
      if (!form) return;
      form.hidden = !show;
      document.querySelector("#chat-title").hidden = show;
      document.querySelector(".title-edit").hidden = show;
      if (show) {
        form.elements.title.value = A.current.thread.title;
        form.elements.title.focus();
        form.elements.title.select();
      }
    },
    syncTools() {
      const tools = (
        A.current?.thread.settings.tools ||
        A.newSettings?.tools ||
        []
      ).map((name) => name.split("::")[0]);
      document
        .querySelectorAll("[data-chat-tool],#chat-settings-form [name=tools]")
        .forEach((n) => (n.checked = tools.includes(n.value)));
    },
    async send() {
      const input = document.querySelector("#prompt"),
        text = input.value.trim();
      if (A.sending) return false;
      if (!text && !A.attachments.length)
        throw new Error("Enter a message or attach a file.");
      if (A.uploading)
        throw new Error("Wait for the selected files to finish uploading.");
      if (A.restoringAttachments)
        throw new Error("Wait for the saved attachments to finish loading.");
      A.sending = true;
      document.querySelector("#send-button").disabled = true;
      try {
        if (A.toolsSaving) await A.toolsSaving;
        const files = A.attachments.map((f) => ({ id: f.id, name: f.name }));
        let pending = A.ui.pending;
        if (
          !pending ||
          pending.text !== text ||
          JSON.stringify(pending.attachments.map((f) => f.id)) !==
            JSON.stringify(files.map((f) => f.id)) ||
          (A.current && pending.threadId !== A.current.thread.id)
        ) {
          pending = {
            key: pending
              ? A.newKey()
              : document.querySelector("[name=submission_key]").value ||
                A.newKey(),
            threadId: A.current?.thread.id || null,
            text,
            attachments: files,
            settings: A.newSettings || {
              profile: A.selectedProfile,
              tools: [],
              parameters: { stream: true },
            },
            systemPrompt: A.newPrompt,
            creating: !A.current,
            // Composition-slot origin, frozen at compose time so retries after
            // thread creation clear the slot the text was actually written in
            // (e.g. "new"), not the live A.current thread that only exists now.
            originKey: A.draftKey(A.current?.thread.id || null),
            setupApplied: false,
          };
          A.persistUI({ pending });
        }
        let created = false;
        // Slot that held the composed text before any thread is created; the
        // clear below must target this slot (e.g. "new"), not the real thread
        // that only exists after creation succeeds.
        // Origin of the composition slot, derived from the durable pending
        // record, not live A.current: between attempts A.current may already
        // point at a newly created thread while the draft still sits in the
        // slot it was composed in (e.g. "new").
        const originatingDraftKey =
          pending.originKey ??
          (pending.creating
            ? A.draftKey(null)
            : A.draftKey(pending.threadId ?? null));
        if (!A.current) {
          if (!A.selectedProfile)
            throw new Error(
              "Ask an administrator to enable a model profile first.",
            );
          const result = await A.api("/api/threads", {
            title: "",
            settings: pending.settings,
            creation_key: pending.key,
          });
          A.current = { thread: result.thread, runs: [] };
          created = true;
          pending.threadId = result.thread.id;
          A.persistUI({ pending });
        }
        const id = A.current.thread.id;
        if (pending.creating && !pending.setupApplied) {
          if (pending.systemPrompt !== null)
            await A.api(`/api/threads/${id}/settings`, {
              settings: {},
              system_prompt: pending.systemPrompt,
            });
          pending.setupApplied = true;
          A.persistUI({ pending });
        }
        const { run } = await A.api(`/api/threads/${id}/messages`, {
          content: text,
          attachments: files,
          submission_key: pending.key,
        });
        A.Log.writeDebug({
          action: "Send message",
          status: "accepted",
          thread_id: id,
          run_id: run.id,
          attachment_count: files.length,
        });
        // View-epoch guard: the user may switch threads while the request is
        // held. Only mutate the still-visible view when A.current is the same
        // thread the submission was addressed to.
        const stillSame = A.current?.thread.id === id;
        if (stillSame && !A.current.runs.some((r) => r.id === run.id))
          A.current.runs.push(run);
        // Only clear text that is still the submitted text: anything typed
        // while the request was awaiting API acceptance is newer and kept.
        // Re-query #prompt: the captured node may be detached after a thread
        // switch (navigated away and back to the same id). Use the live node.
        if (stillSame) {
          const currentInput = document.querySelector("#prompt");
          if (currentInput.value.trim() === text) currentInput.value = "";
        }
        if (stillSame) A.clearAttachments?.();
        // Clear the composer draft only after successful acceptance. Remove only
        // the slot the text was composed in (e.g. "new" for a fresh chat), never
        // the slot keyed by the thread created moments ago, and never another
        // thread's draft. The durable pending/draft-origin is cleared exactly
        // for the accepted submission regardless of which thread is visible.
        const drafts = { ...(A.ui.drafts || {}) };
        // Drop the submitted draft only if it still holds the submitted text;
        // newer text typed while the request awaited acceptance is preserved.
        const prior = drafts[originatingDraftKey];
        if (prior !== undefined && String(prior).trim() === text)
          delete drafts[originatingDraftKey];
        A.persistUI({ drafts });
        A.persistUI({ pending: null });
        // Only rotate the global submission_key for the thread the submission
        // was addressed to when that thread is still visible. Rotating it while
        // another thread is visible would clobber that thread's upcoming key.
        if (stillSame)
          document.querySelector("[name=submission_key]").value = A.newKey();
        if (created) {
          if (stillSame) await A.openThread(id);
        } else if (stillSame) {
          await A.refreshMessages();
          await A.refreshThreads();
          A.poll();
        }
      } finally {
        A.sending = false;
        const b = document.querySelector("#send-button");
        // Restore the disabled state of the now-visible thread: a read_only
        // thread must stay disabled even though this send targeted a different
        // (or no longer visible) thread.
        if (b) b.disabled = !!(A.current?.thread.read_only);
      }
    },
    async poll() {
      clearTimeout(A.pollTimer);
      if (!A.current || !document.querySelector("#message-form")) return;
      // Sending a queued follow-up must not create a second event-consumer loop.
      if (A.polling) {
        A.pollRequested = true;
        return;
      }
      A.polling = true;
      const id = A.current.thread.id,
        epoch = A.epoch;
      try {
        const { events, state } = await A.api(
          `/api/threads/${id}/events?after=${A.cursor}`,
        );
        if (A.current?.thread.id !== id || A.epoch !== epoch) return;
        A.Log.writeDebug({
          action: "Poll workflow",
          thread_id: id,
          event_count: events.length,
          run_count: state.runs.length,
          after: A.cursor,
        });
        const active = new Set(
          A.current.runs
            .filter((r) => ["running", "queued"].includes(r.status))
            .map((r) => String(r.id)),
        );
        let refresh = false;
        for (const event of events) {
          if (Number(event.id) <= A.cursor) continue;
          A.cursor = Number(event.id);
          if (event.type === "log.message") A.Log.accept(event.data);
          if (!active.has(String(event.run_id))) continue;
          if (event.type === "text.delta") {
            A.emote(event.data.content, "");
            A.setTalking?.(true);
          }
          if (event.type === "reasoning.delta") A.emote("", "thinking");
          if (event.type === "tool.start" || event.type === "tool.progress")
            A.emote("", "working");
          // A finished answer may arrive whole, between two polls: the avatar still gets to say it.
          if (event.type === "message" && event.data?.role === "assistant" && event.data.content)
            A.setTalking?.(true, 3200);
          if (event.type === "message") refresh = true;
          if (event.type === "tool.result" || event.type.startsWith("run."))
            refresh = true;
        }
        A.adoptWorkflow(state);
        A.updateLive();
        if (refresh || !A.lastRefresh || Date.now() - A.lastRefresh > 2000) {
          await A.refreshMessages();
          A.lastRefresh = Date.now();
        }
        if (A.epoch !== epoch) return;
        const running = A.current.runs.some((r) =>
          ["queued", "running"].includes(r.status),
        );
        A.pollTimer = setTimeout(
          A.poll,
          events.length === 300 ? 50 : running ? 300 : 2000,
        );
      } catch (e) {
        if (A.epoch !== epoch) return;
        A.setTalking?.(false, true);
        A.toast(e.message);
        if ([401, 403].includes(e.status)) {
          clearTimeout(A.pollTimer);
          return;
        }
        A.pollTimer = setTimeout(A.poll, 3000);
      } finally {
        A.polling = false;
        if (A.pollRequested) {
          A.pollRequested = false;
          clearTimeout(A.pollTimer);
          A.pollTimer = setTimeout(A.poll, 0);
        }
      }
    },
    updateLive() {
      const running = A.current?.runs.find((r) => r.status === "running"),
        queued =
          A.current?.runs.filter((r) => r.status === "queued").length || 0;
      const container = document.querySelector("#messages");
      if (!container) return;
      let live = document.querySelector("#live-message");
      // This transient DOM exists only during generation. Persisted HTML always comes from PHP.
      if (running && !live) {
        live = document.createElement("article");
        live.id = "live-message";
        live.className = "message assistant";
        live.innerHTML =
          '<div class="message-head"><span data-assistant-name></span> <span class="meta">working</span></div><details class="activity" id="live-reasoning"><summary>Reasoning</summary><div class="reasoning"></div></details><div id="live-body" class="message-body"></div>';
        container.append(live);
      }
      const assistant = live?.querySelector("[data-assistant-name]");
      if (assistant)
        assistant.textContent = running?.assistant_name || "PHiLLiP";
      if (!running) {
        live?.remove();
        A.emote("", "");
      }
      const thisRun = running && String(running.id) === A.liveRunId;
      // The server's phase is the source of truth for what the avatar is doing; talking is extended only as text grows.
      if (running) {
        const phase = running.state?.phase;
        A.emote("", phase === "tool" ? "working" : phase === "writing" ? "" : "thinking");
      }
      const spoken = thisRun ? A.live.length : 0;
      if (spoken > (A.spokenLength || 0)) A.setTalking?.(true);
      A.spokenLength = spoken;
      const body = document.querySelector("#live-body");
      if (body) body.textContent = thisRun ? A.live : "";
      const reason = document.querySelector("#live-reasoning");
      if (reason) {
        reason.hidden = !thisRun || !A.reasoning;
        reason.querySelector(".reasoning").textContent = thisRun
          ? A.reasoning
          : "";
      }
      const progress = document.querySelector("#progress");
      if (progress) {
        progress.replaceChildren();
        if (running || queued) {
          const spin = document.createElement("span");
          spin.className = "spinner";
          progress.append(
            spin,
            document.createTextNode(
              running
                ? A.progress || "Thinking…"
                : "Queued · ready when the current work is done",
            ),
          );
        }
      }
      const stop = document.querySelector("#stop-button");
      if (stop) {
        stop.hidden = !running;
        stop.title =
          running?.stop_level === 1
            ? "Cancel generation"
            : running?.stop_level >= 2
              ? "Force stop"
              : "Ask PHiLLiP to wrap up";
      }
      const note = document.querySelector("#queue-note");
      if (note)
        note.textContent = queued
          ? `${queued} message${queued === 1 ? "" : "s"} queued`
          : running
            ? "New messages will join the queue"
            : "Enter to send · Shift + Enter for a new line";
    },
    emote() {}, // The shared SVG animator is installed by avatar.js before DOMContentLoaded.
  });
  document.addEventListener("submit", (e) => {
    const form = e.target.closest(".delete-thread-form");
    if (!form) return;
    e.preventDefault();
    if (
      !confirm(
        "Delete this conversation from the list? Its history will be retained.",
      )
    )
      return;
    const id = Number(form.elements.thread_id.value);
    A.action(
      "Delete conversation",
      async () => {
        await A.api(`/api/threads/${id}/delete`, {});
        if (A.current?.thread.id === id) {
          clearTimeout(A.pollTimer);
          A.epoch++;
          A.current = null;
          A.persistUI({ lastThread: null });
          location.assign(A.page("conversations", { new: 1 }));
        } else await A.refreshThreads();
      },
      { form, success: "Conversation deleted" },
    );
  });
  document.addEventListener("change", (e) => {
    if (!e.target.matches("[data-chat-tool]")) return;
    const tools = [
        ...document.querySelectorAll("[data-chat-tool]:checked"),
      ].map((n) => n.value),
      id = A.current?.thread.id;
    A.toolsSaving = (A.toolsSaving || Promise.resolve())
      .then(() =>
        A.action(
          "Select tools",
          async () => {
            if (id) {
              await A.api(`/api/threads/${id}/settings`, {
                settings: { tools },
              });
              if (A.current?.thread.id === id)
                A.current.thread.settings.tools = tools;
            } else {
              A.newSettings = {
                ...(A.newSettings || {
                  profile: A.selectedProfile,
                  parameters: { stream: true },
                }),
                tools,
              };
              A.persistUI({
                draft: { settings: A.newSettings, system_prompt: A.newPrompt },
              });
            }
          },
          { success: "Tools selected" },
        ),
      )
      .finally(() => A.syncTools());
  });
  document.addEventListener("keydown", (e) => {
    if (e.key !== "Escape") return;
    const escDialog = document.querySelector("#dialog");
    if (escDialog && escDialog.open) return;
    A.showRename(false);
    document
      .querySelectorAll(".tools-menu[open]")
      .forEach((n) => (n.open = false));
  });
  document.addEventListener("pointerdown", (e) => {
    document.querySelectorAll(".tools-menu[open]").forEach((n) => {
      if (!n.contains(e.target)) n.open = false;
    });
  });
  document.addEventListener("click", async (e) => {
    const target = e.target.closest("button,[data-thread],[data-dialog]");
    if (!target) return;
    try {
      if (target.dataset.thread) {
        e.preventDefault();
        await A.openThread(target.dataset.thread);
        return;
      }
      if (target.dataset.dialog) {
        e.preventDefault();
        await A.dialog(target.dataset.dialog, {
          ...(target.dataset.id ? { id: target.dataset.id } : {}),
          ...(target.dataset.name ? { name: target.dataset.name } : {}),
          ...(target.dataset.task ? { task: target.dataset.task } : {}),
          ...(target.dataset.test ? { test: target.dataset.test } : {}),
          ...(target.dataset.question
            ? { question: target.dataset.question }
            : {}),
          ...(target.dataset.remove ? { remove: target.dataset.remove } : {}),
          ...(target.dataset.project
            ? { project: target.dataset.project }
            : {}),
          ...(target.dataset.todo ? { todo: target.dataset.todo } : {}),
          ...(target.dataset.parent ? { parent: target.dataset.parent } : {}),
          ...(target.dataset.decision
            ? { decision: target.dataset.decision }
            : {}),
        }, target);
        return;
      }
      switch (target.dataset.action) {
        case "theme":
          A.setTheme(
            document.documentElement.dataset.theme === "dark"
              ? "light"
              : "dark",
          );
          A.Log.writeSuccess("Appearance saved");
          break;
        case "rename":
          A.showRename(true);
          break;
        case "cancel-rename":
          A.showRename(false);
          break;
        case "avatar-react":
          A.emote("😊 Wonderful to see you!");
          break;
        case "menu":
          toggleDrawer(target);
          break;
        case "close-dialog":
          if (!A.closeDialog()) return;
          break;
        case "attach":
          document.querySelector("#files").click();
          break;
        case "logout":
          await A.action("Sign out", () => A.api("/api/logout", {}), {
            success: "Signed out",
            redirect: A.base + "/",
          });
          break;
        case "stop": {
          const run = A.current?.runs.find((r) => r.status === "running");
          if (run)
            await A.action(
              "Stop agent",
              async () => {
                const { level } = await A.api(`/api/runs/${run.id}/stop`, {});
                run.stop_level = level;
                A.updateLive();
              },
              { success: "Stop requested" },
            );
          break;
        }
      }
    } catch (err) {
      A.toast(err.message);
    }
  });
  document.addEventListener(
    "toggle",
    async (e) => {
      const el = e.target;
      if (
        !el.matches?.("details[data-key]") ||
        !A.current ||
        Boolean(A.panels[el.dataset.key]) === el.open
      )
        return;
      A.panels[el.dataset.key] = el.open;
      A.persistUI({
        panels: { ...A.ui.panels, [A.current.thread.id]: A.panels },
      });
    },
    true,
  );
  window.addEventListener("popstate", () => location.reload());
  document.addEventListener("DOMContentLoaded", () => {
    document.querySelector('.page-link[aria-current="page"]')?.scrollIntoView({
      block: "nearest",
      inline: "nearest",
    });
    try {
      const outcome = sessionStorage.getItem("phillip.action-outcome");
      sessionStorage.removeItem("phillip.action-outcome");
      if (outcome) {
        const record = A.Log.writeSuccess(outcome);
        if (!record?.visible || !document.querySelector("#phillip-messages"))
          A.toast(outcome);
      }
    } catch {}
    A.storageKey = "phillip.ui." + (A.state.user?.id || "anonymous");
    try {
      A.ui = JSON.parse(localStorage.getItem(A.storageKey) || "{}");
    } catch {
      A.ui = {};
    }
    const draft = A.ui.draft;
    if (draft) {
      A.newSettings = draft.settings || null;
      A.newPrompt = draft.system_prompt ?? null;
    }
    A.setTheme(A.ui.theme || A.state.preferences?.theme || "light");
    A.readChat();
    A.bindChat();
    A.enhance();
    A.bindSettings?.();
    A.bindSetup?.();
    if (A.current) A.poll();
    if (
      A.ui.pending &&
      document.querySelector("#prompt") &&
      (!A.current || A.ui.pending.threadId === A.current.thread.id)
    ) {
      document.querySelector("#prompt").value = A.ui.pending.text;
      document.querySelector("[name=submission_key]").value = A.ui.pending.key;
    }
    // Restore the unsent composer draft for the current thread (or new-chat draft),
    // but only if an in-flight pending submission did not already fill the input.
    if (
      document.querySelector("#prompt") &&
      document.querySelector("#prompt").value === ""
    ) {
      A.restoreDraft();
    }
    const tab = document.querySelector("[data-settings-page]")?.dataset
      .settingsPage;
    if (tab) A.persistUI({ settingsTab: tab });
    const login = document.querySelector("#login-form");
    if (login)
      login.onsubmit = async (e) => {
        e.preventDefault();
        const b = login.querySelector("button");
        b.disabled = true;
        try {
          await A.action(
            "Sign in",
            () => A.api("/api/login", Object.fromEntries(new FormData(login))),
            { form: login, success: "Signed in", reload: true },
          );
        } finally {
          b.disabled = false;
        }
      };
  });
})();
