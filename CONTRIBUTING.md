# Contributing to PHiLLiP

PHiLLiP is a PHP application and toolkit with one shared runtime for application code, the web frontend, CLI commands, workers, and the developing TUI. Contributions should strengthen that common system rather than create another implementation of it.

## Get a development environment

Follow the [quick start](README.md#quick-start), complete standalone onboarding, and configure a working model profile. Read [REQUIREMENTS.md](REQUIREMENTS.md) for native dependencies, optional services, and validation limits. Docker supplies the reference PHP environment; host PHP is optional.

```sh
docker compose ps
docker compose exec -T app php --version
docker compose exec -T app php tests/run.php
```

Every test process builds its own throwaway PostgreSQL database from a template and refuses to connect to the production database; see [test isolation](docs/testing.md). Even so, run migrations, workers, and the opt-in Compose and live-model tests only on a development installation.

Private `.env`, `config/*.local.json`, runtime storage, credentials, and real conversation data must stay out of commits. Local PATH wrappers and model profile aliases from another developer's machine are not repository dependencies.

## Find the right layer

| Location | Responsibility |
| --- | --- |
| `bootstrap.php`, `classes/Agent/Runtime.php` | Common initialization, environment, identity, and PHP error handling |
| `classes/Agent/` | Shared request, persistence, authorization, workflow, and service contracts |
| `classes/Agent/Tools/` | Reflected tool entry points |
| `bin/` | Thin executable entry points; `install` is the Bash bootstrap exception |
| `views/`, `public/`, `assets/` | PHP views, front controller and browser assets (`public/assets/app.css` is the one tokenized stylesheet), and the vendor bundle input |
| `database/` | Ordered SQL migrations |
| `resources/` | Shipped character sheets (`personas/`), engineering personas and workflow briefs (`engineering/`), and avatar body/face data (`avatars/`) |
| `tools/` | Maintainer scripts, such as `tools/avatars/import-noto.py` |
| `tests/` | PHP regression suites, fixtures, and Python browser tests |
| `docs/` | User, operator, and developer guides |

See [PHP class usage](docs/php-classes.md), [runtime behavior](docs/runtime.md), and [DESIGN.md](DESIGN.md) before changing shared contracts. [ROADMAP.md](ROADMAP.md) distinguishes unfinished work from existing functionality.

## Implementation principles

- Add the smallest useful end-to-end layer, verify it, then build on it. Avoid speculative classes, dependencies, or configuration.
- Remove repeated conversions at call sites by extending the appropriate shared contract. Accept useful, documented shapes; normalize once; keep internals strict. Do not turn every method into a shape-guessing interface.
- Preserve meaning when cleaning input. Validate identifiers and booleans without lossy casts. Do not trim passwords, rewrite message content, or HTML-escape data during normalization. Escape for the actual output sink.
- Hydration must not grant authority. Reload referenced rows, authenticate separately, and use the frontend access adapters for user-facing operations. Never trust supplied privilege flags.
- Reuse expensive handles where useful, not cached permissions or mutable database results. Long-running workers must observe revocations, edits, and rollbacks.
- Prefer straightforward PHP and broad compatibility where practical. PHP 7.x compatibility is not a requirement. Do not assume a feature works on an untested PHP version merely because Composer permits it.
- Keep application scripts and the TUI in PHP. Python is acceptable for development/browser tests and the separate Camoufox service.

### Rendering, tools, and workflow boundaries

PHP owns initial pages, saved messages, Markdown, forms, and refreshed HTML fragments. JavaScript owns streaming and interactivity. Do not introduce a second client-side saved-message renderer or an initial API-hydration requirement.

Tool classes expose simple public static methods with useful signatures/docblocks; put implementation machinery in private or instance methods. Registering a callable is not approval to execute it in every context. Do not evaluate agent-produced PHP, shell, or workflow programs. Project tasks are structured data interpreted by fixed PHP code; standalone engineering workspace approvals are a separate authority boundary.

Use the [shared logger](docs/runtime.md#logging-and-terminal-output), not ad-hoc `echo`, `var_dump`, or custom error envelopes. User mutations should end in an honest success/accepted result or an error. Debug is for checkpoints; Verbose is for traces. Keep diagnostics off result stdout and away from unauthorized users.

## Test a focused change

`tests/run-all.sh` runs every deterministic test, each against its own disposable database, and prints only failures. A few tests run in the application container; the script does that for you. `--live` adds the tests that call a real model:

```sh
tests/run-all.sh
tests/run-all.sh --live
```

Isolation rules, the container-only tests, and the live tests are described in [docs/testing.md](docs/testing.md). While iterating, the PHP runner executes six self-contained suites by default and uses fixture engines, not a live model:

```sh
docker compose exec -T app php tests/run.php
docker compose exec -T app php tests/run.php --list
docker compose exec -T app php tests/run.php messages request_failures scanner
```

Select additional suites for the code you changed. These are starting points, not a claim that every suite has identical setup or isolation:

| Area | Example suites | Additional considerations |
| --- | --- | --- |
| Input contracts | `utilities`, `utilities_adversarial`, `messages`, `row_contracts`, `input_contracts` | Row/hydration suites may need database fixtures |
| Runtime/logging | `logging`, `runtime`, `runtime_cli_options`, `log_events`, `user_actions` | Some exercise HTTP subprocesses or persisted events |
| Onboarding/document root | `install`, `onboarding`, `public_boundary` | `install` stubs dependency commands; the others use isolated PostgreSQL schemas |
| Navigation/management/access | `access_migration`, `navigation`, `permissions` | Disposable databases/identities; legacy upgrade, implicit Everyone, additive grants, roles, real routes/forms/APIs, and project frontend boundaries |
| Queues/state | `submissions`, `state`, `worker`, `workflow_tightening` | Inspect database and identity prerequisites |
| Attachments | `attachments`, `attachment_media`, `attachment_fallbacks` | Converters and database fixtures are needed |
| Projects | `projects`, `project_workflow`, `project_recursive`, `project_runner`, `project_frontend`, `project_cli` | Some project integration suites require demo identities |
| Engineering agents | `engineering`, `source_tools`, `workspace`, `database_research` | Identity, filesystem, and restricted research-role requirements vary |
| Personas/avatars | `personas`, `persona_sheets`, `persona_characters`, `avatars` | `persona_sheets` enforces the character-sheet standard in [docs/personas.md](docs/personas.md) |
| Benchmarks/model operations | `benchmarks`, `models`, `benchmark_frontend` | Isolated PostgreSQL schemas and local fixture engines; no live model required |

For an actual disposable Compose installation, run the opt-in host test after inspecting its setup:

```sh
php tests/install_compose.php --run
```

It creates its own project, ports, and volume, then removes those fixtures. It requires host PHP dependencies and Docker access. It is not a package-manager validation on a clean OS.

Browser tests need Python Playwright and Chromium. Most `tests/ui_*.py` scripts are launched by a PHP test (`ui_personas.php`, `ui_messages.php`, `ui_fix_*.php`, or `onboarding.php`/`navigation.php`/`benchmark_frontend.php` with `--browser`) that supplies a private database, web server, and fixture model. Scripts in `tests/live/` need a real model: run them with `php tests/live_ui.php [--profile NAME] [script ...]`, which starts a private installation and borrows the enabled `default` profile's configuration read-only from production (see [docs/testing.md](docs/testing.md)). Demo credentials are for disposable development environments only.

`php tests/navigation.php --browser` also uses a disposable schema, HTTP server, fixture model, and worker. It walks every menu page, tests mixed history and four chat follow-ups across navigation/content replacement, manages users and overlapping memberships, checks group grants/denied routes, and exercises mobile/dark mode and ordinary no-JavaScript management forms. It does not reset real installation accounts.

`php tests/benchmark_frontend.php --browser` also coordinates its own database, engine, HTTP server, and worker fixtures. It tests report refresh, repeated process controls after fragment replacement, and test/question editing. Restart idle long-running workers after shared PHP changes; mounted files alone do not reload their already-loaded classes.

When changing chat interactivity, explicitly test multiple follow-up messages after PHP fragment replacement. Include Stop, queued messages, tool activity, navigation, and delayed event replies where relevant. One successful first response does not establish that listeners or stream cursors survive subsequent turns.

Live model tests (`--live`, `tests/live_ui.php`) are separate evidence: record the profile, engine/model, relevant capabilities, and actual results. Do not describe fixture success, a queued handle, or an agent's self-report as completed live verification.

### Syntax, assets, and migrations

```sh
docker compose exec -T app php -l classes/Agent/Request.php
git diff --check
```

Change the lint path to each edited PHP file. If vendor asset inputs or dependencies change, use `npm ci && npm run build` (esbuild bundles `assets/vendor.js` into `public/assets/vendor.js`); Node is a development dependency, not a web runtime dependency. Changes to PHP, `app.js`, or `app.css` do not require rebundling. Keep styling in the tokenized `app.css` (with `avatar.css` and `messages.css`) using generic rules rather than per-page stylesheets.

Add schema changes as a new ordered migration under `database/` rather than rewriting already-applied migrations. Exercise both fresh-schema creation and upgrades where applicable. Migrations and worker restarts are real state changes: use a development database and back up anything important first.

## Before submitting a contribution

Work on a focused branch (in a fork if you do not have repository access), then open a pull request with the problem, approach, tests, and known limitations. Keep unrelated changes separate so the diff can be reviewed and verified on its own.

1. Describe the user-visible problem and the smallest scope that solves it. Discuss substantial architectural changes before implementing them.
2. Include a regression test for a bug, or a testable example for a new contract. Preserve assertions rather than weakening them to fit the implementation.
3. Test affected CLI, PHP, and frontend paths when they share the changed logic. Check authorization and failure behavior, not only the happy path.
4. Document commands run, results, and anything not tested. Review actual file/tool evidence from AI-assisted changes.
5. Update the relevant guide and roadmap status without expanding the README into a changelog or implementation reference.
6. Review the diff for secrets, private data, generated runtime artifacts, unrelated changes, and unnecessary abstractions.

For bug reports, provide reproduction steps, expected/actual behavior, sanitized logs, runtime versions, and the relevant engine capabilities. Do not publish credentials, session IDs, sensitive conversations, or exploit details for an unresolved security issue; use a private reporting channel with the maintainer for sensitive findings.

PHiLLiP is [MIT licensed](LICENSE). Keep contributions and included third-party material compatible with that license, and retain required attribution.
