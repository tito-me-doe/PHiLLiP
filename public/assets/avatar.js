/* CSS owns eyelids and generic talking; JavaScript only selects states and expressions. */
(() => {
  const A = window.PHiLLiP,
    classes = [
      "thinking",
      "happy",
      "sad",
      "angry",
      "surprised",
      "bsod",
      "love",
      "wink",
      "eyeroll",
      "cool",
      "party",
      "sleepy",
      "confused",
      "curious",
      "working",
    ];
  // Talking has a cooldown rather than a length: each burst of text extends it, then the mouth winds down before stopping.
  const COOLDOWN = 2200,
    WIND_DOWN = 900;
  let buffer = "",
    emotion = "",
    lastText = 0,
    timer,
    activity = "", // "thinking" while reasoning or waiting, "working" while a tool runs
    activityTimer,
    coolTimer,
    windTimer,
    typingUntil = 0,
    lastMove = 0;
  // Neutral lines for a persona that defines none of its own; shipped characters speak in their own voices.
  const defaults = {
    resting: "Right here with you.",
    thinking: "Mulling it over…",
    talking: "Thinking out loud…",
    happy: "Oh, I like this!",
    sad: "I’m here with you.",
    angry: "Let’s sort this out.",
    surprised: "Well, would you look at that!",
    love: "A little warmth goes a long way.",
    wink: "Between you and me…",
    eyeroll: "Well… that happened.",
    cool: "All in a day’s work.",
    party: "A small victory deserves a celebration!",
    sleepy: "Just resting for a moment.",
    confused: "Let me think about that.",
    curious: "Hm, tell me more.",
    bsod: "Something broke. Rebooting…",
  };
  const lines = () => {
    let own = {};
    try {
      own = JSON.parse(document.querySelector(".chat-companion")?.dataset.reactions || "{}");
    } catch (error) {
      own = {};
    }
    return { ...defaults, ...own };
  };
  // A background tab shows what the avatar is up to: "…" while it works, "✓" once it has finished.
  let wasBusy = false,
    finished = false;
  const tabHint = () => {
    const busy = Boolean(activity) || Boolean(A.avatarTalking),
      base = document.title.replace(/^(… |✓ )/, "");
    if (busy) wasBusy = true;
    else if (wasBusy) {
      wasBusy = false;
      finished = true;
    }
    if (!document.hidden) finished = false;
    document.title = (document.hidden ? (busy ? "… " : finished ? "✓ " : "") : "") + base;
  };
  document.addEventListener("visibilitychange", tabHint);
  const companionAvatar = () => document.querySelector(".chat-companion .avatar");
  // Whoever is on stage (the companion, or the avatar in the dressing room) watches the pointer.
  const lookers = () => document.querySelectorAll(".chat-companion .avatar, .dressing-room .stage .avatar");
  const paint = () => {
    const shown = emotion || activity;
    tabHint();
    document.querySelectorAll(".avatar").forEach((node) => {
      classes.forEach((name) => node.classList.toggle(name, name === shown));
    });
    // An expression owns the gaze; otherwise it follows the pointer or the text being typed.
    if (shown) ["--gx", "--gy"].forEach((v) => companionAvatar()?.style.removeProperty(v));
    const companion = document.querySelector(".chat-companion");
    if (!companion) return;
    companion.classList.toggle("reacting", Boolean(emotion));
    const say = lines();
    companion.querySelector(".companion-bubble").textContent =
      say[emotion] || say.resting;
    companion.querySelector(".companion-status").textContent = A.avatarTalking
      ? say.talking
      : activity === "thinking"
        ? say.thinking
        : companion.dataset.name || "PHiLLiP";
  };
  // The companion is whoever the person is talking to: their avatar and name follow the conversation's persona.
  A.setCompanion = async (settings = {}) => {
    const companion = document.querySelector(".chat-companion");
    if (!companion) return;
    const name = (settings && settings.assistant_name) || "PHiLLiP";
    const avatar = /^[a-z][a-z0-9-]*(\.[a-z][a-z0-9-]*)?$/.test((settings && settings.avatar) || "")
      ? settings.avatar
      : "terminal";
    companion.dataset.name = name;
    companion.dataset.reactions = JSON.stringify((settings && settings.reactions) || {});
    companion.setAttribute("aria-label", name + " companion");
    companion
      .querySelector(".companion-character")
      ?.setAttribute("aria-label", "Say hello to " + name);
    const node = companion.querySelector(".avatar");
    if (node && node.dataset.avatar !== avatar) {
      try {
        const response = await fetch(`${A.base || ""}/avatar/${avatar}.svg`, {
          credentials: "same-origin",
        });
        if (response.ok) {
          node.innerHTML = await response.text();
          node.dataset.avatar = avatar;
          node.dataset.face = avatar.split(".").pop(); // a shipped character wears its own face; body.face names it
        }
      } catch (error) {
        /* keep the current avatar */
      }
    }
    paint();
  };
  const stopTalking = () => {
    clearTimeout(coolTimer);
    clearTimeout(windTimer);
    A.avatarTalking = false;
    document.querySelectorAll(".avatar").forEach((node) => node.classList.remove("is-talking", "is-winding"));
    paint();
  };
  // setTalking(true[, ms]) starts or extends the cooldown; setTalking(false) lets it run out; setTalking(false, true) stops it now.
  A.setTalking = (active, hold = COOLDOWN) => {
    if (active === false && hold === true) return stopTalking();
    if (!active) return;
    clearTimeout(coolTimer);
    clearTimeout(windTimer);
    A.avatarTalking = true;
    document.querySelectorAll(".avatar").forEach((node) => {
      node.classList.add("is-talking");
      node.classList.remove("is-winding");
    });
    coolTimer = setTimeout(() => {
      document.querySelectorAll(".avatar.is-talking").forEach((node) => node.classList.add("is-winding"));
      windTimer = setTimeout(stopTalking, WIND_DOWN);
    }, hold);
    paint();
  };
  // Look somewhere for a moment (x, y in -1..1), e.g. down at the composer while someone types.
  A.lookAt = (x, y, node = companionAvatar()) => {
    if (!node || matchMedia("(prefers-reduced-motion: reduce)").matches) return;
    if (node === companionAvatar() && (emotion || activity)) return;
    node.style.setProperty("--gx", (x * 4.5).toFixed(2));
    node.style.setProperty("--gy", (y * 5).toFixed(2));
  };
  A.nod = () => {
    const node = companionAvatar();
    if (!node) return;
    node.classList.remove("nod");
    void node.offsetWidth;
    node.classList.add("nod");
    setTimeout(() => node.classList.remove("nod"), 600);
  };
  document.addEventListener("pointermove", (e) => {
    const now = performance.now();
    if (now - lastMove < 60 || now < typingUntil) return;
    lastMove = now;
    lookers().forEach((node) => {
      const box = node.getBoundingClientRect(),
        dx = e.clientX - (box.left + box.width / 2),
        dy = e.clientY - (box.top + box.height * 0.35),
        distance = Math.hypot(dx, dy) || 1,
        reach = Math.min(1, distance / 260);
      A.lookAt((dx / distance) * reach, (dy / distance) * reach, node);
    });
  });
  document.addEventListener("input", (e) => {
    if (e.target?.id !== "prompt") return;
    typingUntil = performance.now() + 1600;
    A.lookAt(-0.7, 1);
    setTimeout(() => {
      if (performance.now() >= typingUntil) A.lookAt(0, 0);
    }, 1700);
  });
  document.addEventListener("submit", (e) => {
    if (e.target?.id === "message-form") A.nod();
  });
  // emote(text, activity): activity "thinking" | "working" | "" sets what the avatar is doing; undefined leaves it alone.
  A.emote = (text = "", next) => {
    if (next !== undefined) {
      activity = next;
      clearTimeout(activityTimer);
      if (activity) activityTimer = setTimeout(() => { activity = ""; paint(); }, 4000);
    }
    if (text) {
      const now = Date.now();
      if (now - lastText > 3000) buffer = "";
      lastText = now;
      buffer = (buffer + text).slice(-240);
      const matches = [
          ...buffer.matchAll(
            /mind[- ]blowing|mind.{0,12}blown|🤯|❤️|♥|🥰|😍|love|😉|wink|😊|😄|happy|wonderful|delighted|😢|😔|sorry|sad|disappoint|😠|😡|angry|frustrat|😮|😲|surpris|wow|🙄|😎|🎉|🥳|😴|🥱|🤔|😕|thank|great|awesome|congrat|apolog|unfortunately|interesting|curious|\?(?=\s*$)/gi,
          ),
        ],
        last = matches.at(-1)?.[0] || "";
      const mood = /\?|interesting|curious/i.test(last)
        ? "curious"
        : /mind|🤯/i.test(last)
        ? "bsod"
        : /❤️|♥|🥰|😍|love/i.test(last)
          ? "love"
          : /😉|wink/i.test(last)
            ? "wink"
            : /😊|😄|happy|wonderful|delighted|thank|great|awesome|congrat/i.test(last)
              ? "happy"
              : /😢|😔|sorry|sad|disappoint|apolog|unfortunately/i.test(last)
                ? "sad"
                : /😠|😡|angry|frustrat/i.test(last)
                  ? "angry"
                  : /😮|😲|surpris|wow/i.test(last)
                    ? "surprised"
                    : {
                        "🙄": "eyeroll",
                        "😎": "cool",
                        "🎉": "party",
                        "🥳": "party",
                        "😴": "sleepy",
                        "🥱": "sleepy",
                        "🤔": "confused",
                        "😕": "confused",
                      }[last] || "";
      if (mood) {
        emotion = mood;
        clearTimeout(timer);
        timer = setTimeout(
          () => {
            emotion = "";
            paint();
          },
          mood === "bsod" ? 3200 : 2200,
        );
      }
    }
    paint();
  };
  A.resetAvatar = () => {
    clearTimeout(timer);
    buffer = "";
    emotion = "";
    clearTimeout(activityTimer);
    activity = "";
    A.setTalking(false, true);
  };
  window.addEventListener("pagehide", A.resetAvatar);
})();
