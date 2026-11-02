/* PHP renders report HTML; delegated controls and poll state survive replacements. */
(() => {
  let busy = false;
  document.addEventListener("submit", async (event) => {
    const form = event.target.closest("form[data-benchmark-form]");
    const A = window.PHiLLiP;
    if (!form || !A) return;
    event.preventDefault();
    if (form.dataset.submitting) return;
    form.dataset.submitting = "true";
    const fields = Object.fromEntries(new FormData(form));
    const action = fields.action.replace(/^benchmark-/, "");
    delete fields.csrf;
    await A.action(
      "Save benchmark definition",
      async () => {
        const result = await A.api("/api/admin/benchmarks", {
          ...fields,
          action,
        });
        if (action === "save-test") {
          const target = new URL(form.getAttribute("action"), location.href);
          target.searchParams.set("test", result.testid);
          location.href = target.href;
        } else {
          await A.dialog("benchmark-questions", { test: result.testid });
        }
      },
      { form, success: "Benchmark saved" },
    );
    delete form.dataset.submitting;
  });
  const focusKey = (element) => {
    if (!element) return null;
    if (element.matches("[data-benchmark-control]")) {
      return "control:" + element.getAttribute("data-benchmark-control");
    }
    if (element.matches("[data-benchmark-tab]")) {
      return "tab:" + element.getAttribute("data-benchmark-tab");
    }
    if (element.matches("[data-benchmark-result]")) {
      return "result:" + element.getAttribute("data-benchmark-result");
    }
    if (element.matches("[data-benchmark-profile-action]")) {
      return (
        "profile-action:" +
        element.getAttribute("data-benchmark-profile-action") +
        ":" +
        element.getAttribute("data-benchmark-profile-id")
      );
    }
    if (element.matches("a[href]")) {
      return "href:" + element.getAttribute("href");
    }
    return null;
  };

  const restoreFocus = (root, key) => {
    if (!key) return;
    const candidates = [
      ...root.querySelectorAll(
        "[data-benchmark-control], [data-benchmark-tab], [data-benchmark-result], [data-benchmark-profile-action], a[href]"
      ),
    ];
    for (const node of candidates) {
      if (focusKey(node) === key) {
        node.focus({ preventScroll: true });
        return;
      }
    }
  };

  const tick = async () => {
    const root = document.querySelector("[data-benchmark-root]"),
      A = window.PHiLLiP;
    if (!root || !A || busy || document.hidden || root.querySelector("[data-benchmark-editor]"))
      return;
    busy = true;
    try {
      const open = [...root.querySelectorAll("details")]
        .map((node, i) => (node.open ? i : -1))
        .filter((i) => i >= 0);
      const query = new URLSearchParams({
        test: root.dataset.test,
      });
      const html = await A.html("/fragments/benchmarks?" + query);
      if (!root.isConnected) return;
      const next = new DOMParser()
        .parseFromString(html, "text/html")
        .querySelector("[data-benchmark-root]");
      if (!next) throw new Error("Unreadable benchmark report");
      const scroll = root.querySelector(".benchmark-content").scrollTop;
      const focused = document.activeElement;
      const key = focusKey(focused && root.contains(focused) ? focused : null);
      root.replaceWith(next);
      next.querySelector(".benchmark-content").scrollTop = scroll;
      next
        .querySelectorAll("details")
        .forEach((node, i) => (node.open = open.includes(i)));
      restoreFocus(next, key);
    } catch (error) {
      A.Log.writeDebug("Benchmark refresh failed", error.message);
    } finally {
      busy = false;
    }
  };
  setInterval(tick, 2500);
})();
