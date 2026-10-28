# Engineering Team — Personas and Workflows

Reusable engineering personas and workflows for the durable agent service. CLI only; there is no frontend bypass route.

## Catalog

`resources/engineering/personas.json` is a flat JSON catalog keyed by stable role ID. Schema is flat: only `name`, `prompt`, `tools`, and optional `contract`. `Engineering::personas()` loads it (`classes/Agent/Engineering.php`); `WorkflowCli` list/show resolve roles by these stable slugs.

Roles and their tool grants:

- `engineering-coordinator` — `Agent\\Tools\\Delegate`, `Agent\\Tools\\Source`, `Agent\\Tools\\SearchWeb`, `Agent\\Tools\\Fetch`, `Agent\\Tools\\BrowseWeb`, `Agent\\Tools\\SQL`, `Agent\\Tools\\Workspace`, `Agent\\Tools\\Diagnostics`. When selected standalone this is the full child-role union; individual workflows override with a tighter union of only their roles' tools. Coordinator creates one child per role (exact persona name + tool grant), gives each the full isolated brief with exact source paths/URLs, reuses children on follow-ups, bounds every wait, transfers clear brief results between children (not raw history), and stops for approval: returns child IDs, exact operator CLI approval commands, and resumable follow-up instructions. Never self-approves, never retries a denial, never busy-loops; approvals are not inherited.
- `web-researcher` — `Agent\\Tools\\SearchWeb`, `Agent\\Tools\\Fetch`, `Agent\\Tools\\BrowseWeb`. Cite primary fetched URLs, not search snippets; distinguish evidence from inference.
- `code-researcher` — `Agent\\Tools\\Source`. Reads actual source, tests, migrations and caller constraints before claiming bugs; may report `no_verified_findings`; does not invent issues.
- `database-researcher` — `Agent\\Tools\\Source`, `Agent\\Tools\\SQL`, `Agent\\Tools\\Diagnostics`, `Agent\\Tools\\Fetch`. Read-only low-privilege research connection; readonly health.
- `architect` — `Agent\\Tools\\Source`, `Agent\\Tools\\Fetch`.
- `implementer` — `Agent\\Tools\\Source`, `Agent\\Tools\\Workspace`. Prefer readable, older-version-friendly PHP where practical; validation targets PHP 8.x, not PHP 7.x. Use `Database::row`/`execute` helpers; `PDOStatement::execute` returns bool so never chain `fetch` from it. Claims edits only with tool evidence; stops for file/command approval.
- `test-engineer` — `Agent\\Tools\\Source`, `Agent\\Tools\\Workspace`. Asserts observable tool events, never answer mentions; never weakens assertions to green; never claims unrun tests pass.
- `adversarial-reviewer` — `Agent\\Tools\\Source`, `Agent\\Tools\\Fetch`. May report `no_verified_findings`.
- `security-reviewer` — `Agent\\Tools\\Source`, `Agent\\Tools\\Fetch`. May report `no_verified_findings`; never claims a system is secure.
- `classifier` — no tools; required JSON contract `{category, confidence, reason}`. Returns only the contract object.
- `documentation-writer` — `Agent\\Tools\\Source`, `Agent\\Tools\\Workspace`, `Agent\\Tools\\Fetch`. Grounds every claim in exact paths/URLs/evidence; reports what was documented and what remains uncertain. Not used for plain web-research reports.
- `ui-tester` — `Agent\\Tools\\Source`, `Agent\\Tools\\BrowseWeb`, `Agent\\Tools\\Workspace`.
- `operations-researcher` — `Agent\\Tools\\Source`, `Agent\\Tools\\SQL`, `Agent\\Tools\\Diagnostics`, `Agent\\Tools\\Fetch`. Read-only; counts are test/demo, not production SLO.

The classifier contract is distinct from coordinator freeform output: a classifier child returns the strict JSON contract, while the workflow `triage` final result is the coordinator's assembled output and is not guaranteed to be JSON.

## Security boundary

Engineering personas and workflows are separate from Project runtime authority. Projects store structured PostgreSQL task/decision data interpreted by fixed developer-written PHP; agents never generate executable workflow programs, and PHP/shell text stored in a task remains inert. The Project runtime tool ceiling excludes Workspace, Source, SQL, and Delegate. Standalone engineering-development agents may edit source only under explicit operator approval (file/command PATCH approval); that approval is development authority, not Project workflow authority, and is never inherited or self-granted.

## Workflows

`resources/engineering/workflows.json` defines `web-research`, `code-research`, `database-research`, `implement`, `review`, `triage`. Each has stable ID, name, roles, phases, and coordinator instructions.

- `web-research`: roles `web-researcher`, `adversarial-reviewer`; phases `gather-evidence -> independent-review -> synthesize`. Classifier is not used for independent review; DocumentationWriter Workspace is not used for a plain report.
- `code-research`: `code-researcher`, `architect`, `security-reviewer`; `gather-evidence -> independent-review -> synthesize`.
- `database-research`: `database-researcher` primary, with `code-researcher` and `architect` consulted as needed for readonly query evidence; `gather-evidence -> independent-review -> synthesize`; never mutates data.
- `implement`: `implementer`, `test-engineer`, `adversarial-reviewer`, `security-reviewer`; `plan -> code -> test -> adversarialreview`.
- `review`: `adversarial-reviewer`, `security-reviewer`, `classifier`; `gather-evidence -> independent-review -> synthesize`.
- `triage`: `classifier`; `triage`.

Installed workflow start resolves roles to actual numeric persona IDs (organization defaults installed by `install`), while `list`/`show` use the stable catalog slugs.

## CLI

```
ok-phill workflows list
ok-phill workflows show web-research
ok-phill workflows install [--replace]
ok-phill workflows run web-research "Investigate X" -p nemotron-vl-work --name MyCoordinator --workspace /path
```

`install` is CLI-only, fixed to the shipped catalog, requires root or the catalog-owner Unix UID, and never clobbers admin edits unless `--replace`. `run` requires the catalog installed and returns `coordinator` + `run` handles (create + assign); handles are not a completion claim. Unsupported flags are rejected; results are JSON on stdout, diagnostics on stderr; exit codes 0/1/2.

### Feedback during active assignment

`ok-phill agents message NAME [TEXT]` sends a note between rounds; piped input is appended. It is the lightweight feedback channel for an active assignment: the running worker can read it during its current turn and incorporate it without creating a new assignment. It does not start work, change a grant, or replace `assign`. Results are JSON on stdout, diagnostics on stderr; exit codes 0/1/2.

Direct classifier (guaranteed JSON contract, `-p nemotron-vl-fast` is the fast profile on this machine):

```
ok-phill --persona Classifier -p nemotron-vl-fast "Assign one category: ..."
```

Inspect the returned coordinator ID and approve only the requested child operation. For example (replace numeric IDs and paths):

```
ok-phill agents result 108
ok-phill agents approve 126 --file tests/example.php --patch
ok-phill agents approve 127 --command '["php","tests/example.php"]'
ok-phill agents assign 108 'The requested child approvals are granted; continue using the existing children.'
ok-phill agents wait 108 --timeout 30
ok-phill agents result 108
```

## Database scope and provision

SQL research is implemented in `classes/Agent/DatabaseResearch.php` (schema `engineering_research`): curated relations `run_daily`, `agent_summary`, `model_summary`, `schema_catalog`; single-relation SELECT only, safe aggregate functions (`count`, `sum`, `avg`, `min`, `max`, …), LIMIT-bounded, read-only transaction, 1000ms statement timeout. Metadata and aggregates only — no production records; counts are test/demo, not production SLO.

Provision the dedicated research role with the already-configured command:

```
php bin/phill-research-provision
```

(`bin/phill-research-provision` creates the `phillip_research` role, applies `database/016_engineering_research.sql`, repairs only that role's grants, and stores credentials in private settings.) The agent SQL tools never invoke this command; it is operator-only.

## Workspace writes (CAS)

`Agent\\Tools\\Workspace::write` is hash-guarded: for an **existing** file, the current whole-file SHA-256 (from `Source::read` of the whole original file) must be supplied via `expectedSha256`; a missing or wrong hash refuses without modifying the file. For an **absent** file, no hash is given and the file is created exclusively (`'x'` mode). PHP files and PHP shebang scripts are parsed before publication, without executing them; syntax failures leave existing files intact. `replace` remains exact single-occurrence match support; no search/replace loops; loop guard `max_tool_repeats: 3`. All writes require PATCH approval for the exact path.

`Workspace.replaceLines` requires the fresh whole-file SHA-256 from `Source::read(numbered=true)` and uses inclusive 1-based line numbers; it never does fuzzy search. A missing final LF is preserved with a warning, and a stale or wrong hash refuses the edit.

## Existing tests

```sh
php tests/run.php engineering diagnostics database_research request_failures source_tools workspace
docker compose exec -T app php tests/browser.php
```

Browser fixtures run inside the app container. Camoufox has its own network namespace: use `http://phillip.internal:8080/` for this installation's UI, not the PHP host's `127.0.0.1:8088`. The UI Tester can inspect screenshots with the vision-enabled work profile.

## One-step operator workflow

`ok-phill agents work NAME TASK --persona Implementer -p nemotron-vl-engineering --file tests/example.php --command '["php","tests/example.php"]'` creates or reuses a worker, applies explicit repeatable `--file` and `--command` JSON argv grants, and assigns the task asynchronously. `--command` authorizes only the program's first three argv words as the Unix user; it is not a shell prefix and not an OS sandbox.

Follow up with `ok-phill agents peek NAME` to read bounded real tool evidence for the active or latest run: run status/error, an answer excerpt, file changes (path/sha256/bytes), command checks, and tool errors. Evidence is not proof of correctness; inspect results and run tests.

```sh
ok-phill profiles list
ok-phill profiles show nemotron-vl-engineering
ok-phill profiles save my-engineering '{"preset":"nemotron-vl-work","max_rounds":24}'
```

`profiles save` is restricted to root/source-owner; it disables the changed profile for frontend unless an explicit `--enable` runs probes, while code can still select disabled profiles.
