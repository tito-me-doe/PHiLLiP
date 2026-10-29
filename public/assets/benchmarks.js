/* PHP renders report HTML; delegated controls and poll state survive replacements. */
(() => {
  let busy = false;
  const tick = async () => {
    const root = document.querySelector("[data-benchmark-root]"),
      A = window.PHiLLiP;
    if (
      !root ||
      !A ||
      busy ||
      document.hidden ||
      (root.contains(document.activeElement) &&
        document.activeElement.matches("input,textarea,select")) ||
      root.querySelector("[data-benchmark-editor]")
    )
      return;
    busy = true;
    try {
      const open = [...root.querySelectorAll("details")]
        .map((node, i) => (node.open ? i : -1))
        .filter((i) => i >= 0);
      const query = new URLSearchParams({
        test: root.dataset.test,
        benchmark: root.dataset.run,
      });
      const html = await A.html("/fragments/benchmarks?" + query);
      if (!root.isConnected) return;
      const next = new DOMParser()
        .parseFromString(html, "text/html")
        .querySelector("[data-benchmark-root]");
      if (!next) throw new Error("Unreadable benchmark report");
      const scroll = root.querySelector(".benchmark-content").scrollTop;
      root.replaceWith(next);
      next.querySelector(".benchmark-content").scrollTop = scroll;
      next
        .querySelectorAll("details")
        .forEach((node, i) => (node.open = open.includes(i)));
    } catch (error) {
      A.Log.writeDebug("Benchmark refresh failed", error.message);
    } finally {
      busy = false;
    }
  };
  setInterval(tick, 2500);
})();
