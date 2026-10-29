/* Persisted project content is rendered by PHP. This client only submits and refreshes HTML. */
(() => {
  let timer = null,
    refreshing = false,
    submitting = false,
    stopped = false;
  const root = () => document.querySelector("[data-project-root]");
  const key = () =>
    "phillip.project.panels." + (window.PHiLLiP?.state.user?.id || "anonymous");
  const panels = () => {
    try {
      return JSON.parse(localStorage.getItem(key()) || "{}");
    } catch {
      return {};
    }
  };
  function restorePanels(node) {
    const saved = panels();
    node.querySelectorAll("[data-project-panel]").forEach((panel) => {
      const name = node.dataset.projectId + ":" + panel.dataset.projectPanel;
      if (Object.prototype.hasOwnProperty.call(saved, name))
        panel.open = saved[name];
    });
  }
  async function refresh() {
    const old = root(),
      A = window.PHiLLiP;
    if (!old || !old.dataset.projectId || refreshing || submitting || !A)
      return;
    refreshing = true;
    try {
      const id = old.dataset.projectId;
      const html = await A.html(
        "/fragments/project?project=" + encodeURIComponent(id),
      );
      if (!old.isConnected || root() !== old || submitting) return;
      const replacement = new DOMParser()
        .parseFromString(html, "text/html")
        .querySelector("[data-project-root]");
      if (!replacement)
        throw new Error("The server returned an unreadable project page.");
      const active = document.activeElement;
      const focus =
        active &&
        old.contains(active) &&
        active.matches("input,textarea,select")
          ? active
          : null;
      const selection =
        focus && typeof focus.selectionStart === "number"
          ? [focus.selectionStart, focus.selectionEnd]
          : null;
      // Move unfinished forms into the fresh server page, preserving typed drafts and focus.
      const existing = [...old.querySelectorAll("form[data-project-form]")];
      replacement
        .querySelectorAll("form[data-project-form]")
        .forEach((form) => {
          const previous = existing.find(
            (candidate) =>
              candidate.dataset.projectForm === form.dataset.projectForm,
          );
          if (
            previous &&
            (previous.dataset.projectDirty === "true" ||
              (focus && previous.contains(focus)))
          )
            form.replaceWith(previous);
        });
      // A running-state update may hide an editor; retain unsaved drafts visibly until saved.
      existing
        .filter(
          (form) => form.dataset.projectDirty === "true" && old.contains(form),
        )
        .forEach((form) =>
          replacement.querySelector(".projects-content").append(form),
        );
      restorePanels(replacement);
      const scroll = old.querySelector(".projects-content")?.scrollTop || 0;
      old.replaceWith(replacement);
      replacement.querySelector(".projects-content").scrollTop = scroll;
      A.enhance(replacement);
      if (focus?.isConnected) {
        focus.focus({ preventScroll: true });
        if (selection) focus.setSelectionRange(...selection);
      }
    } catch (error) {
      if ([401, 403].includes(error.status)) {
        stopped = true;
        clearTimeout(timer);
      }
      window.PHiLLiP?.Log.writeDebug({
        action: "Refresh project",
        status: "error",
        message: error.message,
      });
    } finally {
      refreshing = false;
    }
  }
  function schedule() {
    clearTimeout(timer);
    if (stopped) return;
    timer = setTimeout(async () => {
      if (!document.hidden) await refresh();
      if (root()?.dataset.projectId) schedule();
    }, 2500);
  }
  document.addEventListener("input", (event) => {
    const form = event.target.closest("[data-project-form]");
    if (
      form &&
      event.target.matches("input:not([type=hidden]),textarea,select")
    )
      form.dataset.projectDirty = "true";
  });
  document.addEventListener("change", (event) => {
    const form = event.target.closest("[data-project-form]");
    if (
      form &&
      event.target.matches("input:not([type=hidden]),textarea,select")
    )
      form.dataset.projectDirty = "true";
  });
  document.addEventListener(
    "toggle",
    (event) => {
      const panel = event.target;
      if (
        !panel.matches?.("[data-project-panel]") ||
        !panel.closest("[data-project-root]")
      )
        return;
      const saved = panels(),
        name =
          panel.closest("[data-project-root]").dataset.projectId +
          ":" +
          panel.dataset.projectPanel;
      saved[name] = panel.open;
      try {
        localStorage.setItem(key(), JSON.stringify(saved));
      } catch {}
    },
    true,
  );
  document.addEventListener("submit", async (event) => {
    const form = event.target.closest("form[data-project-form]"),
      A = window.PHiLLiP;
    if (!form || !A) return;
    event.preventDefault();
    if (submitting) return;
    const fields = new FormData(form),
      body = {};
    for (const [name, value] of fields) {
      if (name.endsWith("[]")) {
        const field = name.slice(0, -2);
        (body[field] ||= []).push(value);
      } else if (!["csrf", "action", "project_id"].includes(name))
        body[name] = value;
    }
    submitting = true;
    const buttons = [...form.querySelectorAll("button[type=submit]")];
    buttons.forEach((button) => (button.disabled = true));
    const error = form.querySelector(".form-error");
    if (error) error.textContent = "";
    let result;
    try {
      await A.action(
        "Save project",
        async () => {
          result = await A.api(form.dataset.projectApi, body);
          delete form.dataset.projectDirty;
          form.closest("dialog")?.close();
          if (form.dataset.projectForm === "create")
            location.href = A.page("projects", { project: result.project.id });
          if (form.dataset.projectForm === "delete")
            location.href = A.page("conversations");
          if (!root() && result.project)
            location.href = A.page("projects", { project: result.project.id });
        },
        { form, success: "Project saved" },
      );
    } finally {
      submitting = false;
      buttons.forEach((button) => {
        if (button.isConnected) button.disabled = false;
      });
      if (result && !["create", "delete"].includes(form.dataset.projectForm))
        await refresh();
      schedule();
    }
  });
  document.addEventListener("DOMContentLoaded", () => {
    if (root()) {
      restorePanels(root());
      schedule();
    }
  });
})();
