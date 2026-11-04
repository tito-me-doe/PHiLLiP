/* Forms are PHP-rendered. These handlers submit changes and fetch new PHP HTML. */
(() => {
  const A = window.PHiLLiP;
  const bind = (id, success, handler, reload = true) => {
    const form = document.querySelector(id);
    if (!form) return;
    form.onsubmit = async (e) => {
      e.preventDefault();
      const b = e.submitter || form.querySelector("button.primary,button");
      if (b) b.disabled = true;
      try {
        await A.action(success, () => handler(new FormData(form), form, e), {
          form,
          success,
          reload,
        });
      } finally {
        if (b) b.disabled = false;
      }
    };
  };
  const close = (form) => A.closeDialog(form);
  A.bindSettings = () => {
    bind(
      "[data-profile-defaults]",
      "Profile defaults saved",
      async (fields) => {
        await A.api("/api/admin/profile-defaults", {
          profileid: fields.get("profileid"),
          tasks: fields.getAll("tasks[]"),
        });
      },
    );
    document.querySelectorAll("[data-forget]").forEach(
      (b) =>
        (b.onclick = () => {
          if (confirm("Forget this claim? Its source conversations are kept."))
            A.action(
              "Forget memory",
              () => A.api("/api/knowledge/" + b.dataset.forget + "/delete", {}),
              { success: "Memory forgotten", reload: true },
            );
        }),
    );
    bind("#profile-form", "Profile saved", async (f, form) => {
      const b = form.querySelector("button.primary");
      b.textContent = f.has("enabled") ? "Testing configuration…" : "Saving…";
      try {
        await A.api("/api/admin/profile", {
          name: f.get("name"),
          config: f.get("config"),
          enabled: f.has("enabled"),
          overrides: f.get("overrides"),
        });
      } finally {
        b.textContent = "Save profile";
      }
    });
    // The dressing room: step through every body and face, with the avatar (composed on the server) shown live.
    const personaForm = document.querySelector("#persona-form");
    const room = personaForm?.querySelector("[data-dressing-room]");
    if (room) {
      const bodies = JSON.parse(room.dataset.bodies),
        faces = JSON.parse(room.dataset.faces),
        input = room.querySelector("input[name=avatar]"),
        slot = room.querySelector(".avatar"),
        label = room.querySelector(".stage-label"),
        faceName = room.querySelector(".face-name"),
        status = room.querySelector("[data-look-status]"),
        cache = new Map();
      const [first, second] = input.value.split(".");
      let body = Math.max(0, bodies.findIndex((b) => b.id === first)),
        face = Math.max(0, faces.findIndex((f) => f.id === (second || first)));
      const idOf = (b, f) => (bodies[b].own && bodies[b].id === faces[f].id ? bodies[b].id : `${bodies[b].id}.${faces[f].id}`);
      const wrap = (n, size) => (n + size) % size;
      const load = (id) => {
        if (!cache.has(id)) cache.set(id, fetch(`${A.base || ""}/avatar/${id}.svg`, { credentials: "same-origin" }).then((r) => (r.ok ? r.text() : "")));
        return cache.get(id);
      };
      let shown = "";
      const show = async (pop) => {
        const id = idOf(body, face);
        input.value = id;
        label.textContent = `${bodies[body].label} · ${body + 1} / ${bodies.length}`;
        faceName.textContent = faces[face].label;
        status.textContent = `Body: ${bodies[body].label}. Face: ${faces[face].label}.`;
        const svg = await load(id);
        if (!svg || input.value !== id || shown === id) return;
        shown = id;
        slot.innerHTML = svg;
        slot.dataset.avatar = id;
        slot.dataset.face = faces[face].id;
        if (pop) {
          slot.classList.remove("swap");
          void slot.offsetWidth;
          slot.classList.add("swap");
        }
        // Warm the neighbours so stepping stays instant.
        [idOf(wrap(body + 1, bodies.length), face), idOf(wrap(body - 1, bodies.length), face), idOf(body, wrap(face + 1, faces.length)), idOf(body, wrap(face - 1, faces.length))].forEach(load);
      };
      const step = (kind, by) => {
        if (kind === "body") body = wrap(body + by, bodies.length);
        else face = wrap(face + by, faces.length);
        personaForm.dispatchEvent(new Event("input", { bubbles: true }));
        show(true);
      };
      room.addEventListener("click", (e) => {
        const button = e.target.closest("[data-step]");
        if (button) step(button.dataset.step, Number(button.dataset.by));
      });
      const stage = room.querySelector(".stage");
      stage.addEventListener("keydown", (e) => {
        const move = { ArrowLeft: ["body", -1], ArrowRight: ["body", 1], ArrowUp: ["face", -1], ArrowDown: ["face", 1] }[e.key];
        if (!move) return;
        e.preventDefault();
        step(...move);
      });
      // Swipe the stage sideways to change bodies.
      let start = null;
      stage.addEventListener("pointerdown", (e) => {
        if (!e.target.closest("button")) start = { x: e.clientX, y: e.clientY };
      });
      stage.addEventListener("pointerup", (e) => {
        if (!start) return;
        const dx = e.clientX - start.x,
          dy = e.clientY - start.y;
        start = null;
        if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy)) step("body", dx < 0 ? 1 : -1);
      });
      stage.addEventListener("pointercancel", () => (start = null));
      show(false);
    }
    // A live preview of the card they are making, as it will appear on the welcome screen.
    const preview = personaForm?.querySelector(".persona-preview");
    if (preview) {
      const refresh = () => {
        const get = (name) => (personaForm.elements[name]?.value || "").trim();
        preview.querySelector(".pv-name").textContent = get("name") || "Their name";
        preview.querySelector(".pv-pronouns").textContent = get("pronouns");
        preview.querySelector(".pv-tagline").textContent = get("tagline") || "Their tagline";
      };
      personaForm.addEventListener("input", refresh);
      refresh();
    }
    bind("#persona-adult-form", "Saved", async (f) => {
      await A.api("/api/personas/adult", { enabled: f.get("adult") === "yes" });
    });
    document.querySelector("#persona-adult-form input[type=checkbox]")?.addEventListener("change", (e) => {
      document.querySelectorAll("#persona-table tr[data-adult]").forEach((row) => {
        const own = row.querySelector("form[data-persona-enable] input");
        row.classList.toggle("is-off", !e.target.checked || (own && !own.checked));
      });
      e.target.form.requestSubmit();
    });
    document.querySelectorAll("form[data-persona-enable] input[type=checkbox]").forEach((box) =>
      box.addEventListener("change", () =>
        A.action(
          "Switch persona",
          () => A.api(`/api/personas/${box.form.dataset.personaEnable}/enabled`, { enabled: box.checked }),
          { success: "Saved", reload: true },
        ),
      ),
    );
    document.querySelectorAll("form[data-profile-enable] input[type=checkbox]").forEach((box) =>
      box.addEventListener("change", () =>
        A.action(
          "Switch profile",
          async () => {
            try {
              await A.api("/api/admin/profile-enabled", { name: box.form.dataset.profileEnable, enabled: box.checked });
            } catch (error) {
              box.checked = !box.checked;
              throw error;
            }
          },
          { success: "Saved", reload: true },
        ),
      ),
    );
    document.querySelectorAll("form[data-user-active] input[type=checkbox]").forEach((box) =>
      box.addEventListener("change", () => {
        const f = box.form.dataset;
        A.action(
          "Switch user",
          () => A.api("/api/admin/user", { id: f.userActive, username: f.username, display_name: f.displayName, active: box.checked }),
          { success: "Saved", reload: true },
        );
      }),
    );
    document.querySelectorAll("[data-persona-copy]").forEach(
      (b) =>
        (b.onclick = () =>
          A.action(
            "Customize persona",
            () => A.api(`/api/personas/${b.dataset.personaCopy}/duplicate`, {}),
            { success: "Your copy is ready to edit", reload: true },
          )),
    );
    bind(
      "#persona-form",
      "Persona saved",
      async (f, form) => {
        await A.api("/api/personas", {
          ...Object.fromEntries(f),
          ...(form.dataset.id ? { id: form.dataset.id } : {}),
        });
        close(form);
      },
      Boolean(document.querySelector("[data-settings-page=personas], #persona-start")),
    );
    document
      .querySelector("#delete-persona")
      ?.addEventListener("click", (e) => {
        const id = e.currentTarget.dataset.id;
        if (
          confirm(
            "Delete this persona? Existing conversations keep their prompts.",
          )
        )
          A.action(
            "Delete persona",
            () => A.api("/api/personas/" + id + "/delete", {}),
            { success: "Persona deleted", reload: true },
          );
      });
    bind("#claim-edit", "Memory saved", async (f, form) => {
      await A.api("/api/knowledge/" + form.dataset.id, {
        content: f.get("content"),
      });
    });
    bind(
      "#service-form",
      "Service configuration saved",
      async (f) => {
        await A.api("/api/admin/services", {
          name: f.get("name"),
          url: f.get("url"),
          url_env: f.get("url_env"),
          token_env: f.get("token_env"),
        });
      },
    );
    bind(
      "#attachment-parsers-form",
      "Attachment parsing profiles saved",
      async (f) => {
        await A.api("/api/admin/attachment-parsers", {
          type: f.get("type"),
          profile: f.get("profile"),
        });
      },
    );
    bind(
      "#scan-form",
      "Discovery queued",
      async (f) => {
        const job = await A.api("/api/admin/scan", {
          cidr: f.get("cidr"),
          ports: f.get("ports"),
          all: f.has("all"),
        });
        A.watchScan(job.id);
      },
      false,
    );
    const active = document.querySelector("#scan-status[data-scan-active]");
    if (active) A.watchScan(active.dataset.scanActive);
  };
  A.watchScan = async (id) => {
    clearTimeout(A.scanTimer);
    const container = document.querySelector("#scan-status");
    if (!container) return;
    if (!container.dataset.operation)
      A.operationProgress(container, { id, status: "queued" });
    try {
      const { operations } = await A.api(
          "/api/admin/operations?id=" + encodeURIComponent(id),
        ),
        job = operations.find((j) => j.id == id);
      if (!container.isConnected) return;
      A.Log.writeDebug({
        action: "Watch discovery",
        operation_id: id,
        status: job?.status || "queued",
      });
      // Scanner progress is transient interactive output, not conversation HTML.
      A.operationProgress(container, job || { id, status: "queued" });
      if (job?.output) {
        let details = container.querySelector("details");
        if (!details) {
          details = document.createElement("details");
          details.className = "activity";
          const summary = document.createElement("summary");
          summary.textContent = "Discovery log";
          const pre = document.createElement("pre");
          pre.className = "scan-output";
          details.append(summary, pre);
          container.append(details);
        }
        details.querySelector("pre").textContent = job.output;
      }
      if (!job || ["queued", "running"].includes(job.status))
        A.scanTimer = setTimeout(() => A.watchScan(id), 1500);
      else
        await A.action(
          "Discovery result",
          async () => {
            if (job.status !== "completed")
              throw new Error("Discovery failed; inspect the output.");
            const html = await A.html("/fragments/settings?tab=models");
            if (!container.isConnected) return;
            document.querySelector("#workspace").innerHTML = html;
            A.bindSettings();
            A.enhance();
            A.operationProgress(document.querySelector("#scan-status"), job);
          },
          { success: "Discovery complete. The model registry is up to date." },
        );
    } catch (e) {
      A.Log.writeDebug({
        action: "Watch discovery",
        operation_id: id,
        status: "error",
        http_status: e.status,
      });
      const record = A.Log.writeError(e.message);
      if (!record?.visible) A.toast(e.message);
    }
  };
})();
