/* One progressive enhancement for all PHP-rendered management forms. */
(() => {
  document.addEventListener("click", async (event) => {
    const link = event.target.closest("a[data-editor],a[data-close-editor]");
    if (
      !link ||
      !window.PHiLLiP ||
      event.ctrlKey ||
      event.metaKey ||
      event.shiftKey ||
      event.altKey
    )
      return;
    if (link.hasAttribute("data-close-editor")) {
      const dialog = link.closest("dialog");
      if (!dialog) return;
      event.preventDefault();
      dialog.close();
      return;
    }
    event.preventDefault();
    const query = new URL(link.href).searchParams;
    const entity = query.get("edit");
    query.delete("edit");
    try {
      await PHiLLiP.dialog("manage", { entity, ...Object.fromEntries(query) });
    } catch (error) {
      PHiLLiP.Log.writeError(error.message);
    }
  });
  document.addEventListener("submit", async (event) => {
    const form = event.target.closest("form[data-manage]");
    const app = window.PHiLLiP;
    if (!form || !app) return;
    event.preventDefault();
    if (form.dataset.submitting === "true") return;
    form.dataset.submitting = "true";
    const controls = [...form.querySelectorAll("button:not(:disabled)")];
    controls.forEach((button) => (button.disabled = true));
    const fields = Object.fromEntries(new FormData(form));
    for (const name of [
      "groups",
      "members",
      "allowed_users",
      "allowed_groups",
    ]) {
      if (form.querySelector('[name="' + name + '_present"]')) {
        fields[name] = new FormData(form).getAll(name + "[]");
        delete fields[name + "[]"];
      }
    }
    delete fields.csrf;
    delete fields.action;
    const message = form.querySelector(".form-error");
    if (message) message.textContent = "";
    try {
      await app.action(
        "Save " + form.dataset.manage,
        () => app.api("/api/manage/" + form.dataset.manage, fields),
        { form, success: "Changes saved", reload: true },
      );
    } finally {
      delete form.dataset.submitting;
      controls.forEach((button) => (button.disabled = false));
    }
  });
})();
