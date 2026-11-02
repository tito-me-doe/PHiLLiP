# Personas

A persona is a character a person chooses to talk to: a name, a personality and a look. It is not a job title (those are experts, a separate concept). The new-conversation screen is the persona list: one card per character, plus a **New Persona** card.

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

There is no per-user age gate. The setup wizard (step "Installation") asks once whether adult-oriented personas are available here; it defaults to **off**. An administrator can change it later on the Personas page, or with `ok-phill personas adult on|off|status`. While it is off, adult personas are not offered, cannot be started or customized, and nobody can mark a persona adult; conversations already started keep working. Turning it on or off applies to everyone immediately.

## Character sheets

Each shipped persona is one file, `resources/personas/<slug>.json`, loaded by `Agent\PersonaSheet`. Required: `slug`, `name`, `pronouns`, `tagline`, `archetype`, `for`, `bio`, `approach`, `maturity` (`everyone` or `adult`), `voice` (`summary`, `diction`, `rhythm`, `humor`, at least three `signature` phrases and two `avoid` entries), at least three `values`, `boundaries` (including that the character admits being an AI when sincerely asked), `greetings`, `signoffs`, **at least three example exchanges**, `tools` (may be empty), `reactions` (see below) and `avatar` (`file`, `object`).

Models imitate examples far more than adjectives, so the examples define the voice. `tests/persona_sheets.php` enforces the standard and fails when two characters share too much vocabulary or a signature phrase, when a name looks like a role ("PHP Developer"), when an adult persona lacks under-18 behavior, or when an avatar file is missing.

## The companion's voice

The chat companion (the avatar in the corner) speaks in the persona's own words. A sheet's `reactions` has one short line (at most 60 characters) for each of: `resting`, `thinking`, `talking`, and the expressions `happy`, `sad`, `angry`, `surprised`, `love`, `wink`, `eyeroll`, `cool`, `party`, `sleepy`, `confused`, `bsod`. Shipped characters must define all of them; a persona someone creates themselves falls back to neutral lines. The lines travel with the conversation, so an existing conversation keeps its persona's voice.

## How the prompt is built

The database stores the sheet (`personas.sheet`) plus the fields the UI needs (`pronouns`, `tagline`, `maturity`, `avatar`). The system prompt is **rebuilt from the sheet every time a conversation starts**, so improving the shared rules reaches every character. The shared rules (in `PersonaSheet::SHARED_*`, not overridable by a sheet) say: you are an AI, admit it when sincerely asked, safety overrides personality, stop at any limit the person states, and either describe the tools you have (and keep tool results exact) or say you have none and must not guess.

A character always starts. If the account may not use one of its tools, the conversation simply starts without it and the prompt says so.

## Creating your own

The **New Persona** card (also on the Personas page) opens a form with a live preview of the card. Only a name, a tagline and a description are required; the rest sharpens the voice. A new persona is private to its creator and is marked **Yours** on the welcome screen, with an edit shortcut. **Customize** on the Personas page makes your own private copy of any persona you may see, which is the way to adjust a shipped one. Sharing it with a team or the organization uses the same visibility rules as conversations and projects. Only the creator edits or deletes a persona; administrators manage shared and shipped ones. Editing changes only the fields that were submitted, so nothing else in a sheet is lost.

The page is **Personas & Experts**: characters in one table, experts (instruction-only personas) in another. Every row has a slide switch for whoever may edit it (administrators for shared and shipped ones, creators for their own); a switched-off persona is dimmed and cannot be chosen. Administrators also see the installation-wide **18+** switch in the Personas header. Everyone may open the page (`page.personas` is granted to the Everyone group by migration `035`).

## Avatars

An avatar belongs to its persona. It is an SVG in `public/assets/avatars/` that follows the shared contract, so every expression and the talking animation work on every character. Keep the face at the standard coordinates (eyes at 87,80 and 151,80; mouth near 120,118) and use these part classes: `avatar-body`, `avatar-face`, `avatar-eyes` (`eye-left`, `eye-right`, `avatar-pupil`), `avatar-mouth`, `avatar-speaking-mouth`, `avatar-brow`, and the optional expression parts `avatar-heart-eyes`, `avatar-shades`, `avatar-tears`, `avatar-confetti`, `avatar-sleep`, `avatar-crash`. Missing optional parts are fine. The chat companion follows the conversation: it shows the persona's avatar and name.

## Installing and updating

`bin/phill-bootstrap` installs the shipped cast with the engineering catalog; `ok-phill personas install [--replace]` does it on demand. `Agent\PersonaSheet::install()` is idempotent and leaves administrator edits alone; `install(true)` (`--replace`) restores the shipped sheets. A legacy seeded row with the same name (for example the old PHiLLiP) is adopted in place.

## What comes next

Personas currently carry a small set of tools directly. The plan is for personas to talk and delegate while curated experts, each with its own tools and recipe, do the work. Persona tools will then be dropped.
