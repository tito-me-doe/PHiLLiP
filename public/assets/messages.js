/* Browser counterpart of Agent\Log. Client messages never leave this browser. */
(() => {
  const labels = {
    [-4]: "Verbose",
    [-3]: "Debug",
    [-2]: "Info",
    [-1]: "Warning",
    0: "Success",
    1: "Error",
    2: "Fatal",
  };
  const methods = {
    [-4]: "debug",
    [-3]: "debug",
    [-2]: "info",
    [-1]: "warn",
    0: "info",
    1: "error",
    2: "error",
  };
  const sensitive =
    /password|passwd|secret|token|authorization|cookie|session.?id|phpsessid|api.?key|csrf/i;
  const isSensitiveField = (key, value) => {
    const harmless = [
      "max_tokens",
      "max_completion_tokens",
      "prompt_tokens",
      "completion_tokens",
      "total_tokens",
      "cached_tokens",
      "reasoning_tokens",
      "token_count",
    ];
    if (!harmless.includes(key)) return sensitive.test(key);
    if (typeof value === "number")
      return (
        !Number.isFinite(value) || value < 0 || Math.floor(value) !== value
      );
    if (typeof value === "string")
      return !/^[0-9]+$/.test(value) || !Number.isFinite(Number(value));
    return true;
  };
  const seen = new Set();
  let sequence = 0;
  const clean = (value, depth = 0, seenObjects = new WeakSet()) => {
    if (depth > 8) return "[depth limit]";
    if (value instanceof Error)
      return {
        exception: value.name,
        message: clean(value.message, depth + 1, seenObjects),
      };
    if (typeof value === "string") {
      if (/^[\s]*[\[{]/.test(value)) {
        try {
          if (value.length > 1048576) throw new Error("trace budget");
          const tmp = JSON.parse(value);
          if (tmp && typeof tmp === "object" && Object.keys(tmp).length)
            value = JSON.stringify(clean(tmp, depth + 1, seenObjects));
        } catch {
          if (value.length > 1048576 || sensitive.test(value))
            value = "[unparsed or oversized JSON trace]";
        }
      }
      return value
        .replace(
          /^([ \t]*([A-Za-z0-9_-]+)[ \t]*:[ \t]*).*$/gm,
          (match, prefix, key) =>
            isSensitiveField(key, null) ? prefix + "[redacted]" : match,
        )
        .replace(/(https?:\/\/)[^/\s@]+@/gi, "$1[redacted]@")
        .replace(/([?&])([^\s=&#]+)=([^\s&#"']*)/g, (match, separator, key) => {
          let decoded = key;
          try {
            decoded = decodeURIComponent(key);
          } catch {}
          return isSensitiveField(decoded, null)
            ? separator + key + "=[redacted]"
            : match;
        });
    }
    if (value === undefined) return "undefined";
    if (
      typeof value === "bigint" ||
      typeof value === "symbol" ||
      typeof value === "function"
    )
      return String(value);
    if (typeof value === "number" && !Number.isFinite(value))
      return String(value);
    if (value && typeof value === "object") {
      if (seenObjects.has(value)) return "[circular]";
      seenObjects.add(value);
      if (value instanceof Date) return value.toISOString();
      const entries = Object.entries(value)
        .slice(0, 200)
        .map(([key, item]) => [
          key,
          isSensitiveField(key, item)
            ? "[redacted]"
            : clean(item, depth + 1, seenObjects),
        ]);
      return Array.isArray(value)
        ? entries.map(([, item]) => item)
        : Object.fromEntries(entries);
    }
    return value;
  };
  const L = (window.PHiLLiPLog = {
    VERBOSE: -4,
    DEBUG: -3,
    INFO: -2,
    WARNING: -1,
    SUCCESS: 0,
    ERROR: 1,
    FATAL: 2,
    OFF: 3,
    minPriority: -1,
    minLogPriority: 3,
    records: [],
    format(...args) {
      try {
        const normalized = args.map((value) => clean(value)),
          strings = normalized.map((value) =>
            typeof value === "string" ? value : JSON.stringify(value),
          );
        if (
          strings.length > 1 &&
          /%(?:\d+\$)?[-+0 #]*\d*(?:\.\d+)?[sdfioxX]/.test(strings[0])
        ) {
          let index = 1,
            valid = true;
          const used = new Set();
          const text = strings[0].replace(
            /%%|%(?:(\d+)\$)?([-+0 #]*)(\d*)(?:\.(\d+))?([sdfioxX])/g,
            (match, position, flags, width, precision, type) => {
              if (match === "%%") return "%";
              const argument = position ? Number(position) : index++;
              used.add(argument);
              const value = normalized[argument];
              if (value === undefined) {
                valid = false;
                return match;
              }
              let result =
                type === "s"
                  ? typeof value === "string"
                    ? value
                    : JSON.stringify(value)
                  : type === "f"
                    ? Number(value).toFixed(
                        precision === undefined ? 6 : Number(precision),
                      )
                    : type === "x" || type === "X"
                      ? Math.trunc(Number(value)).toString(16)
                      : type === "o"
                        ? Math.trunc(Number(value)).toString(8)
                        : String(Math.trunc(Number(value)));
              if (type === "X") result = result.toUpperCase();
              if (width)
                result = flags.includes("-")
                  ? result.padEnd(Number(width), " ")
                  : result.padStart(
                      Number(width),
                      flags.includes("0") ? "0" : " ",
                    );
              return result;
            },
          );
          if (valid)
            return (
              text +
              strings
                .slice(1)
                .map((value, position) =>
                  used.has(position + 1) ? "" : " " + value,
                )
                .join("")
            ).slice(0, 32768);
        }
        return strings.join(" ").slice(0, 32768);
      } catch {
        return args
          .map((value) => {
            try {
              return String(value);
            } catch {
              return "[unprintable]";
            }
          })
          .join(" ")
          .slice(0, 32768);
      }
    },
    write(priority, ...args) {
      try {
        if (!Object.hasOwn(labels, priority))
          priority =
            Object.keys(labels).find(
              (key) =>
                labels[key].toLowerCase() === String(priority).toLowerCase(),
            ) ?? 1;
        return L.accept({
          id: "client-" + ++sequence,
          priority: Number(priority),
          label: labels[priority],
          message: L.format(...args),
          visible: Number(priority) >= L.minPriority,
          console: Number(priority) >= L.minLogPriority,
        });
      } catch {
        return null;
      }
    },
    writeVerbose(...args) {
      return L.write(-4, ...args);
    },
    writeVerbos(...args) {
      return L.write(-4, ...args);
    },
    writeDebug(...args) {
      return L.write(-3, ...args);
    },
    writeInfo(...args) {
      return L.write(-2, ...args);
    },
    writeWarning(...args) {
      return L.write(-1, ...args);
    },
    writeSuccess(...args) {
      return L.write(0, ...args);
    },
    writeError(...args) {
      return L.write(1, ...args);
    },
    writeFatal(...args) {
      return L.write(2, ...args);
    },
    trace(...args) {
      if (L.minLogPriority > L.VERBOSE) return null;
      return L.with({ visible: L.OFF }, () =>
        L.writeVerbose(
          ...args.map((value) =>
            value instanceof Error
              ? {
                  exception: value.name,
                  message: value.message,
                  stack: value.stack,
                }
              : value,
          ),
        ),
      );
    },
    with(settings, callback) {
      const saved = [L.minPriority, L.minLogPriority];
      try {
        if (settings.visible !== undefined) L.minPriority = settings.visible;
        if (settings.console !== undefined) L.minLogPriority = settings.console;
        return callback();
      } finally {
        [L.minPriority, L.minLogPriority] = saved;
      }
    },
    accept(record) {
      if (seen.has(record.id) || !Object.hasOwn(labels, record.priority))
        return record;
      seen.add(record.id);
      L.records.push(record);
      if (L.records.length > 500) L.records.shift();
      if (seen.size > 2000) seen.delete(seen.values().next().value);
      if (record.console)
        console[methods[record.priority]](
          "[" + labels[record.priority] + "] " + record.message,
        );
      const sameThread =
        !record.scope ||
        !window.PHiLLiP?.current ||
        String(record.scope.thread_id) ===
          String(window.PHiLLiP.current.thread.id);
      const temporary =
          record.priority === L.SUCCESS || record.priority === L.INFO,
        age = record.timestamp ? Date.now() - Date.parse(record.timestamp) : 0;
      if (
        record.visible &&
        sameThread &&
        (!temporary || !Number.isFinite(age) || age < 5000) &&
        !document.querySelector('[data-log-id="' + CSS.escape(record.id) + '"]')
      ) {
        const container = document.querySelector("#phillip-messages");
        if (container) {
          const item = document.createElement("div");
          item.className =
            "phillip-message log-" + labels[record.priority].toLowerCase();
          item.dataset.logId = record.id;
          item.setAttribute("role", record.priority >= 1 ? "alert" : "status");
          const label = document.createElement("strong"),
            text = document.createElement("span"),
            close = document.createElement("button");
          label.textContent = labels[record.priority];
          text.textContent = record.message;
          close.type = "button";
          close.dataset.dismissLog = "";
          close.setAttribute("aria-label", "Dismiss message");
          close.textContent = "×";
          item.append(label, text, close);
          container.append(item);
          while (
            container.querySelectorAll(".log-success,.log-info").length > 3
          )
            container.querySelector(".log-success,.log-info").remove();
          while (container.children.length > 8)
            container.firstElementChild.remove();
        }
      }
      if (record.visible && temporary)
        setTimeout(
          () =>
            document
              .querySelector('[data-log-id="' + CSS.escape(record.id) + '"]')
              ?.remove(),
          record.priority === L.SUCCESS ? 3000 : 5000,
        );
      return record;
    },
    reconcile(threadId, runIds) {
      const allowed = new Set(runIds.map(String));
      for (const record of L.records)
        if (
          record.scope &&
          (String(record.scope.thread_id) !== String(threadId) ||
            !allowed.has(String(record.scope.run_id)))
        )
          document
            .querySelector('[data-log-id="' + CSS.escape(record.id) + '"]')
            ?.remove();
    },
    fromResponse(response) {
      const encoded = response.headers
        ? response.headers.get("X-PHiLLiP-Messages")
        : response.getResponseHeader("X-PHiLLiP-Messages");
      if (!encoded) return;
      try {
        const bytes = Uint8Array.from(atob(encoded), (char) =>
          char.charCodeAt(0),
        );
        for (const record of JSON.parse(new TextDecoder().decode(bytes)))
          L.accept(record);
      } catch (e) {
        if (L.minLogPriority <= 1)
          console.error("Invalid server message envelope");
      }
    },
  });
  document.addEventListener("click", (event) => {
    const button = event.target.closest("[data-dismiss-log]");
    if (button) button.closest(".phillip-message").remove();
  });
  document.addEventListener("DOMContentLoaded", () => {
    const data = document.querySelector("#phillip-log-data");
    if (data) {
      const payload = JSON.parse(data.textContent);
      L.minPriority = payload.thresholds.visible;
      L.minLogPriority = payload.thresholds.console;
      for (const record of payload.messages) L.accept(record);
    }
  });
})();
