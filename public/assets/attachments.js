/* PHP renders file chips and saved messages. JS only handles upload interaction. */
(() => {
  const A = window.PHiLLiP;
  const scope = () => String(A.current?.thread.id || "new");
  const status = (text) => {
    const node = document.querySelector("#attachment-status");
    if (node) node.textContent = text;
  };
  const save = () =>
    A.persistUI({
      attachmentDrafts: {
        ...A.ui.attachmentDrafts,
        [A.attachmentScope || scope()]: A.attachments,
      },
      attachments: [],
    });
  A.clearAttachments = () => {
    const ids = A.attachments.map((f) => f.id).join(","),
      drafts = { ...A.ui.attachmentDrafts };
    // A lost acknowledgement may have recovered the same submission under its newly created thread URL.
    for (const [key, files] of Object.entries(drafts))
      if (files.map((f) => f.id).join(",") === ids) drafts[key] = [];
    A.persistUI({ attachmentDrafts: drafts });
    A.attachments = [];
    save();
    document.querySelector("#attachment-chips")?.replaceChildren();
  };
  A.restoreAttachments = async () => {
    const key = scope(),
      revision = (A.attachmentRestoreRevision =
        (A.attachmentRestoreRevision || 0) + 1),
      container = document.querySelector("#attachment-chips");
    A.attachmentScope = key;
    if (!A.ui.attachmentDrafts) {
      A.ui.attachmentDrafts = {
        [key]: A.ui.attachments || A.ui.pending?.attachments || [],
      };
    }
    const pending =
      A.ui.pending && (!A.current || String(A.ui.pending.threadId) === key)
        ? A.ui.pending.attachments
        : [];
    A.attachments = A.ui.attachmentDrafts[key] || pending || [];
    A.restoringAttachments = false;
    if (!A.attachments.length) return;
    const ids = A.attachments.map((f) => f.id).join(",");
    A.restoringAttachments = true;
    try {
      const html = await A.html(
        "/fragments/attachments?ids=" + encodeURIComponent(ids),
      );
      if (
        revision === A.attachmentRestoreRevision &&
        container.isConnected &&
        ids === A.attachments.map((f) => f.id).join(",")
      )
        container.innerHTML = html;
    } catch (e) {
      if (revision === A.attachmentRestoreRevision) {
        A.Log.writeDebug({
          action: "Restore attachments",
          status: "error",
          count: A.attachments.length,
          http_status: e.status,
        });
        status("An attachment is no longer available. Select it again.");
        A.clearAttachments();
      }
    } finally {
      if (revision === A.attachmentRestoreRevision)
        A.restoringAttachments = false;
    }
  };
  const upload = (file, key) =>
    new Promise((resolve, reject) => {
      const started = performance.now();
      A.Log.writeDebug({
        action: "Upload attachment",
        status: "started",
        bytes: file.size,
      });
      const xhr = new XMLHttpRequest();
      xhr.open("POST", A.base + "/api/attachments");
      xhr.setRequestHeader("X-CSRF-Token", A.state.csrf);
      xhr.upload.onprogress = (e) => {
        if (e.lengthComputable)
          status(
            "Uploading " +
              file.name +
              " · " +
              Math.round((e.loaded / e.total) * 100) +
              "%",
          );
      };
      xhr.onload = () => {
        A.Log.fromResponse(xhr);
        A.Log.writeDebug({
          action: "Upload attachment",
          http_status: xhr.status,
          elapsed_ms: Math.round(performance.now() - started),
          bytes: file.size,
        });
        let result;
        try {
          result = JSON.parse(xhr.responseText);
        } catch {
          reject(
            new Error(
              "Upload returned an unreadable response. Check PHP upload limits.",
            ),
          );
          return;
        }
        A.Log.trace({ action: "Upload attachment", response: result });
        if (xhr.status >= 400 || result.error)
          reject(
            Object.assign(new Error(result.error || "Upload failed"), {
              status: xhr.status,
            }),
          );
        else resolve(result);
      };
      xhr.onerror = () => {
        A.Log.writeDebug({
          action: "Upload attachment",
          status: "connection_error",
          elapsed_ms: Math.round(performance.now() - started),
        });
        reject(
          Object.assign(new Error("Upload connection interrupted"), {
            retryable: true,
          }),
        );
      };
      const body = new FormData();
      body.append("file", file);
      body.append("upload_key", key);
      xhr.send(body);
    });
  A.attachFiles = (files) =>
    A.action(
      "Attach files",
      async () => {
        if (!files.length) return false;
        if (A.uploading) throw new Error("Wait for the current upload.");
        if (A.restoringAttachments)
          throw new Error("Wait for the saved attachments to finish loading.");
        if (files.length + A.attachments.length > 8)
          throw new Error("Attach at most eight files per message.");
        if (
          [...files, ...A.attachments].reduce(
            (sum, file) => sum + Number(file.size),
            0,
          ) >
          A.state.attachment_max_bytes * 2
        )
          throw new Error(
            "Combined attachments exceed twice the per-file limit.",
          );
        A.uploading = true;
        try {
          for (const file of files) {
            if (file.size > A.state.attachment_max_bytes)
              throw new Error(file.name + " exceeds the upload limit.");
            const key = A.newKey();
            let result;
            try {
              result = await upload(file, key);
            } catch (e) {
              if (!e.retryable) throw e;
              result = await upload(file, key);
            }
            A.attachments.push(result.attachment);
            save();
            document
              .querySelector("#attachment-chips")
              .insertAdjacentHTML("beforeend", result.html);
          }
          status("Files ready.");
        } catch (e) {
          status(e.message);
          throw e;
        } finally {
          A.uploading = false;
          const input = document.querySelector("#files");
          if (input) input.value = "";
        }
      },
      { success: "Files ready" },
    );
  document.addEventListener("click", async (e) => {
    const b = e.target.closest("[data-remove-attachment]");
    if (!b) return;
    const id = b.dataset.removeAttachment;
    await A.action(
      "Remove attachment",
      async () => {
        await A.api("/api/attachments/" + id + "/delete", {});
        A.attachments = A.attachments.filter((f) => f.id !== id);
        save();
        b.closest("[data-attachment]").remove();
        status("");
      },
      { success: "Attachment removed" },
    );
  });
})();
