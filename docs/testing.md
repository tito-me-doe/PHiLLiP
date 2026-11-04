# Test-database isolation

PHiLLiP test suites never run against the production database. `tests/helpers.php`
boots `TestDatabase::prepare()`, which creates a per-process throwaway database from
the `phillip_test_template` template and drops it when the process exits.

## How isolation works

- `TestDatabase` reads production settings from `DB_DSN`/`DB_USER`/`DB_PASSWORD`
  and injects `DB_DSN`/`DB_NAME`, `PHILLIP_TESTING=1`, `PHILLIP_TEST_DATABASE`,
  and `PHILLIP_PRODUCTION_DATABASE` into `putenv`, `$_ENV`, and `$_SERVER`. A
  child process inherits `PHILLIP_TEST_DATABASE` and joins the parent's isolated
  database instead of cloning a new one.
- Diagnostics go to a per-process temp test log
  (`sys_get_temp_dir()/phillip-test-<name>.log`), never under `storage/`; the log
  is unlinked on shutdown after the database is dropped.
- On exit the database is dropped with `DROP DATABASE ... WITH (FORCE)`. The drop
  is the final shutdown step (database dropped last). Set `PHILLIP_TEST_KEEP=1` to
  keep it and print its name to stderr for inspection.
- `Agent\Database::connection()` refuses any DSN whose `dbname` equals
  `PHILLIP_PRODUCTION_DATABASE` while `PHILLIP_TESTING=1`, throwing
  `Refusing to connect to the production database from a test process`.
- `tests/isolation.php` asserts the connected database differs from production,
  that the guard throws, and writes/reads back a row in the isolated DB.
- The template rebuilds only when its fingerprint changes. The fingerprint covers
  the migrations, `tests/fixtures/test_seed.sql`, and both seeding scripts
  (`bin/phill-demo` and `tests/fixtures/seed_template.php`); a matching
  `template_meta.fingerprint` row skips the rebuild.
- `bin/phill-demo` and `tests/fixtures/seed_template.php` seed the template with
  demo accounts, teams, memberships, tool grants, and settings; `test_seed.sql`
  adds seed data. The template is the single source of truth for demo accounts.
- `WebFixture` builds an empty private schema per browser/API suite, creates its own
  users in that schema, and serves a disposable HTTP server with cookie jars; the
  schema is dropped on `close()`.

## Rules for writing new tests

1. Never modify production data. Every test process gets its own database, and the production DSN is refused.
2. A test creates every record it needs, with explicit values (unique usernames, grants via `Agent\Identity::grant`,
   model profiles via `Agent\Profiles::save`, service settings through the application's own API). Do not assume
   anything that production happens to contain. The template holds only: the demo accounts from `bin/phill-demo`
   (admin, alice, bob, carol, visitor; password `phillip-demo`), their teams and grants, and the 13-persona engineering
   catalog. It has no model profiles, no service settings, no data-exit policy and no Unix-user mapping.
3. A test that needs an HTTP server starts its own with `tests/fixtures/WebFixture.php` and creates its own users in the
   fixture's private schema (see `tests/navigation.php`, `tests/permissions.php`). Never call a fixed address such as
   `127.0.0.1:8080`: inside the application container that is the live application.
4. Permissions are allow-only and additive, and the built-in Everyone group (team `0`) is granted several tools. To
   assert a denial, delete the allow in the test's own database; a `grant(..., false)` row cannot override an allow.
5. Do not weaken a failing test: no deleted assertions, no skips, no swallowed exceptions. Write file paths as strings
   and keep them inside a temporary directory; never create files in the repository root.
6. Shared demo or edge-case data belongs in `tests/fixtures/test_seed.sql` (or `seed_template.php`); editing either one
   rebuilds the template automatically on the next run.

## Test-run serialization

`Verify::testUnits` serializes test runs under a global file lock (`phillip-testunits.lock` in the
temp directory) because tests use fixed ports, so one test process runs at a time even when several agents call it; run output strips `[Debug]`, `[Info]`, and `[Success]`
lines.

## Running the tests

`tests/run-all.sh` runs everything deterministic and prints only what fails; add `--live` for the tests that call a real
model. A single test is `php tests/NAME.php`. Browser tests need Python Playwright and are skipped by nothing: they launch
their own disposable server.

- **On the host:** almost everything.
- **In the application container** (`docker compose exec -T app php tests/NAME.php`): `browser` (needs the remote browser
  service), `infrastructure` (`pdo_mysql`), `mappings` (`pdo_sqlite`), `cli_memory` (the mapped `www-data` Unix account)
  and `attachment_media` (OCR tools). `run-all.sh` does this for you. Never run a test that calls a fixed address (rule 3)
  inside that container.
- **Opt-in live-model tests** (`--live`): `streaming`, `attachment_vision`, `cli_dogfood`, `agent_team_live`,
  `tests/live_ui.php` and `tests/project_recursive_live.php`. They use `tests/fixtures/live_profile.php`, which *reads* the
  enabled `default` profile from production, verifies it against the live endpoint and enables it in the test's own
  database. Nothing is written to production.

`tests/live_ui.php [--profile NAME] [script ...]` runs the browser scripts in `tests/live/` (`ui_server`, `ui_submissions`,
`ui_attachments`, `ui_frontend_tightening`, `ui_followups`, and `ui_projects` when asked for by name) against a private
installation: this process's database, its own web server and workers, and the borrowed model. They read the model's real
answers, so they are slower and can fail when the model is slow or unavailable; the deterministic suite does not.

`ui_messages` (log delivery in a real browser) is deterministic and runs in the ordinary suite.

## Known noisy

Project preflight is a model's judgment of a plan ("reply exactly `ready`"). With `nemotron-3.5-super-vl` it sometimes
reports gaps that are not there (a "project-level gates array" that does not exist), so `tests/project_recursive_live.php`
asks up to four times, and a live run can still occasionally stop at preflight. Reasoning models also need a generous
`max_tokens` (the live scripts use 8192 and 16384); with 1024 the preflight ends with "token limit reached".

## Audit appendix

With isolation off, 117 test files ran against a copy of production and 28 tests
changed data: agent_detach, agent_work, agents, attachment_conversation,
attachment_fallbacks, attachment_vision, benchmark_frontend, benchmarks, cli_memory,
cli_threads, data_exit_policy, indexer, input_contracts, models, onboarding,
profile_protection, project_cli, project_payloads, project_recursive,
project_recursive_security, project_runner, project_tui, project_workflow,
public_boundary, settings_contracts, submissions, threads, ui_fix_05.

Touched tables: index_jobs, settings, schema_catalog (341 rows from catalog
refreshes), projects, project_todos, message_vectors, memberships. `ui_fix_05`
deleted rows. Approximate losses: ~202 projects, 469 project todos, 512 index
jobs, 18,423 message vectors, 30 settings, 7 messages. With isolation on, all 117
tests wrote nothing to the copy of production.
