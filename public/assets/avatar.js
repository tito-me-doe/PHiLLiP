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
    ];
  let buffer = "",
    emotion = "",
    lastText = 0,
    timer,
    thinking = false;
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
  const paint = () => {
    document.querySelectorAll(".avatar").forEach((node) => {
      classes.forEach((name) =>
        node.classList.toggle(
          name,
          name === (emotion || (thinking ? "thinking" : "")),
        ),
      );
    });
    const companion = document.querySelector(".chat-companion");
    if (!companion) return;
    companion.classList.toggle("reacting", Boolean(emotion));
    const say = lines();
    companion.querySelector(".companion-bubble").textContent =
      say[emotion] || say.resting;
    companion.querySelector(".companion-status").textContent = A.avatarTalking
      ? say.talking
      : thinking
        ? say.thinking
        : companion.dataset.name || "PHiLLiP";
  };
  // The companion is whoever the person is talking to: their avatar and name follow the conversation's persona.
  A.setCompanion = async (settings = {}) => {
    const companion = document.querySelector(".chat-companion");
    if (!companion) return;
    const name = (settings && settings.assistant_name) || "PHiLLiP";
    const avatar = /^[a-z][a-z0-9-]*$/.test((settings && settings.avatar) || "")
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
        const response = await fetch(`${A.base || ""}/assets/avatars/${avatar}.svg`, {
          credentials: "same-origin",
        });
        if (response.ok) {
          node.innerHTML = await response.text();
          node.dataset.avatar = avatar;
        }
      } catch (error) {
        /* keep the current avatar */
      }
    }
    paint();
  };
  A.setTalking = (active) => {
    A.avatarTalking = Boolean(active);
    document
      .querySelectorAll(".avatar")
      .forEach((node) => node.classList.toggle("is-talking", A.avatarTalking));
    paint();
  };
  A.emote = (text = "", activity = "") => {
    thinking = activity === "thinking";
    if (text) {
      const now = Date.now();
      if (now - lastText > 3000) buffer = "";
      lastText = now;
      buffer = (buffer + text).slice(-240);
      const matches = [
          ...buffer.matchAll(
            /mind[- ]blowing|mind.{0,12}blown|🤯|❤️|♥|🥰|😍|love|😉|wink|😊|😄|happy|wonderful|delighted|😢|😔|sorry|sad|disappoint|😠|😡|angry|frustrat|😮|😲|surpris|wow|🙄|😎|🎉|🥳|😴|🥱|🤔|😕/gi,
          ),
        ],
        last = matches.at(-1)?.[0] || "";
      const mood = /mind|🤯/i.test(last)
        ? "bsod"
        : /❤️|♥|🥰|😍|love/i.test(last)
          ? "love"
          : /😉|wink/i.test(last)
            ? "wink"
            : /😊|😄|happy|wonderful|delighted/i.test(last)
              ? "happy"
              : /😢|😔|sorry|sad|disappoint/i.test(last)
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
    thinking = false;
    A.setTalking(false);
  };
  window.addEventListener("pagehide", A.resetAvatar);
})();
