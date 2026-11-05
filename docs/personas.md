# Personas

A persona is a character a person chooses to talk to: a name, a personality and a look. It is not a job title (those are experts, a separate concept). The new-conversation screen is the persona list: one card per enabled character, plus a **New Persona** card. It has no composer and no companion until a card is chosen.

## The shipped cast

| Persona | Pronouns | Vibe |
| --- | --- | --- |
| PHiLLiP (default) | they/them | A small, bright, wholesome terminal who loves to help |
| Dave | he/him | The dad: practical, patient, dad jokes |
| Vesper | she/her | Velvet voice, flirtatious, never explicit (18+) |
| Mika | she/her | The gamer: teasing, competitive, game-master (18+) |
| Wren | they/them | The listener: calm, reflective (not therapy) |
| Rafe | he/him | The cool guy: unbothered, decisive |
| Priya | she/her | The nerd: precise, enthusiastic, tangent-prone |
| Imani | she/her | Focused and productive: clear next steps |
| Arthur | he/him | The careful advisor: taxes, contracts, fine print |
| Roz | she/her | The comic: bawdy and big-hearted (18+) |
| Bram | he/him | The storyteller: roleplay and worldbuilding |

Adult personas carry an **18+** badge. They are flirtatious or bawdy but never sexually explicit, and each defines how it behaves if the user says they are under 18.

### Adult-oriented personas are one installation-wide decision

There is no per-user age gate. The setup wizard (step "Installation") asks once whether adult-oriented personas are available here; it defaults to **off**. An administrator can change it later with the **18+** switch on the Personas & Experts page, or with `ok-phill personas adult on|off|status`. While it is off, adult personas are not offered on the new-conversation screen, cannot be started or copied, and nobody can mark a persona adult; on the management page they stay listed, dimmed and sorted last, and conversations already started keep working. Turning it on or off applies to everyone immediately.

## Character sheets

Each shipped persona is one file, `resources/personas/<slug>.json`, loaded by `Agent\PersonaSheet`. Required: `slug`, `name`, `pronouns`, `tagline`, `archetype`, `for`, `bio`, `approach`, `maturity` (`everyone` or `adult`), `voice` (`summary`, `diction`, `rhythm`, `humor`, at least three `signature` phrases and two `avoid` entries), at least three `values`, `boundaries` (including that the character admits being an AI when sincerely asked), `greetings`, `signoffs`, **at least three example exchanges**, `tools` (may be empty), `reactions` (see below) and `avatar` (`file`: an avatar id plus `.svg`, see [Avatars](#avatars); `object`: what the character looks like).

Models imitate examples far more than adjectives, so the examples define the voice. `tests/persona_sheets.php` enforces the standard and fails when two characters share too much vocabulary or a signature phrase, when a name looks like a role ("PHP Developer"), when an adult persona lacks under-18 behavior, or when an avatar file is missing.

## The companion's voice

The chat companion (the avatar perched on the composer's top-right edge) speaks in the persona's own words. A sheet's `reactions` has one short line (at most 60 characters) for each of: `resting`, `thinking`, `talking`, and the expressions `happy`, `sad`, `angry`, `surprised`, `love`, `wink`, `eyeroll`, `cool`, `party`, `sleepy`, `confused`, `bsod`. Shipped characters must define all of them; a persona someone creates themselves falls back to neutral lines. The lines travel with the conversation, so an existing conversation keeps its persona's voice.

## How the prompt is built

The database stores the sheet (`personas.sheet`) plus the fields the UI needs (`pronouns`, `tagline`, `maturity`, `avatar`). The system prompt is **rebuilt from the sheet every time a conversation starts**, so improving the shared rules reaches every character. The shared rules (in `PersonaSheet::SHARED_*`, not overridable by a sheet) say: you are an AI, admit it when sincerely asked, safety overrides personality, stop at any limit the person states, and either describe the tools you have (and keep tool results exact) or say you have none and must not guess.

A character always starts. If the account may not use one of its tools, the conversation simply starts without it and the prompt says so.

## Creating your own

The **New Persona** card (and the plus in the Personas header) opens a form with a live preview of the card. Only a name, a tagline and a description are required; the rest sharpens the voice. A new persona is private to its creator and is marked **Yours** on the welcome screen, with an edit shortcut. The copy icon on the Personas & Experts page makes your own private copy of any persona you may see, which is the way to adjust a shipped one. Sharing it with a team or the organization uses the same visibility rules as conversations and projects. Only the creator edits or deletes a persona; administrators manage shared and shipped ones. Editing changes only the fields that were submitted, so nothing else in a sheet is lost.

The page is **Personas & Experts**: characters in one table, experts (instruction-only personas: a name, instructions, optional tools and contract) in another, each with its own plus button. Every row has a slide switch (`personas.enabled`) for whoever may edit it (administrators for shared and shipped ones, creators for their own); a switched-off persona is dimmed, leaves the new-conversation screen and cannot be started. Administrators also see the installation-wide **18+** switch in the Personas header. Everyone may open the page (`page.personas` is granted to the Everyone group by migration `035`).

## Avatars

An avatar is **composed, not stored**: a body (any object) wearing a face. `resources/avatars/` holds both as data and `Agent\Avatar` stitches one inline SVG per request (`Avatar::render($id)`); the same renderer serves server-rendered pages and `/avatar/<id>.svg`, which the browser fetches when the companion changes persona (the avatar id is validated by `Avatar::valid()`; anything unknown is a 404). There are no avatar SVG files in `public/`.

An avatar id is a shipped character's slug (`arthur`: the body and face it was drawn with) or `body.face` for any mix (`noto-1f34e.vesper`). Anything unknown renders the terminal.

- `bodies/<slug>.svg` is the body's markup, with an optional `<!--over-->` section drawn above the face. `faces/<slug>.svg` is a face (`avatar-face`, heart eyes, shades, tears, confetti, sleep) with an `<!--over-->` section for what sits on top (the crash screen, glasses, drooping lids). `{{ink}}` and `{{lid}}` are filled in when composing.
- `avatars.json` carries each body's label, shadow, the face anchor (`x`, `y`, `w`: where a face of that width sits), the colour behind the eyes (`lid`), and its source and license; each face's width, centre, ink and eye colour; and the presets.
- A face keeps its own colours on the body it was drawn for. On any other body it moves to the anchor, scales to fit, and swaps its ink (and outlines its eyes) if it would not read against the body.
- Every face follows the same part vocabulary, so every expression and animation works on every combination: `avatar-face` (`avatar-eyes` with `eye-left`/`eye-right` and `avatar-pupil`, `avatar-mouth`, `avatar-speaking-mouth`, `avatar-brow`), eyelids (`<g class="lids" fill="{{lid}}">` with three `lid` ellipses per eye) and the optional expression parts `avatar-heart-eyes`, `avatar-shades`, `avatar-tears`, `avatar-confetti`, `avatar-sleep`, `avatar-crash`. Eyes sit at 87,80 and 151,80 and the mouth near 120,118 in a face's own coordinates (the sprout's face is its own geometry).
- The 157 library bodies are [Noto Emoji](https://github.com/googlefonts/noto-emoji) SVGs (Apache-2.0; `resources/avatars/NOTICE.md`). `python3 tools/avatars/import-noto.py` (a maintainer tool needing Python Playwright) refreshes them: it strips each to plain shapes, namespaces its ids, fits it into the avatar frame with the same margins, rejects shapes with no room for a face, and measures the colour behind the eyes in a real browser. The application never needs the tool or the network.

The persona editor is a dressing room: arrows over the stage (or a swipe, or the left and right keys) change the body, the pill under it (or up and down) changes the face, and the avatar is composed live by the server.

### How the characters move

`avatar.css` is the whole animation vocabulary and expressions are a handful of custom properties on `.avatar` (`--lid`, `--gx`/`--gy`, `--bo`, `--bly`/`--bry`, `--blr`/`--brr`), so a new expression costs a few lines for every character. Expressions settle with a spring overshoot, and a mood light glows behind the character (indigo while thinking, amber while working, green while talking, and a colour per emotion) and tints the composer's border. Blinks come from three lids per eye on coprime periods (single, double and slow blinks), and each avatar gets its own random phase from PHP so several never blink together. The companion's pupils follow the pointer (so do those on the dressing room's stage, and they look down at the composer while someone types); the companion nods when a message is sent. It thinks while the run is reasoning or waiting, works (reading pupils, typing bob) while a tool runs (the run phase drives both), and talks while text arrives. Talking has a cooldown, not a length: every burst of text extends it, then the mouth slows down for a moment before it stops. In the persona list and cards the characters are still until hovered; reduced motion keeps only opacity changes.
