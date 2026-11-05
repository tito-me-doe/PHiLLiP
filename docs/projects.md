# Projects

Projects turn a planning conversation into nested ToDos, check readiness, and execute the agreed plan only after a person selects Start. They use PostgreSQL, the shared Thread/Run engine, the ordinary run worker, and the Compose `projects` scheduler. Closing a tab or terminal does not cancel accepted work.

This first layer supports research, comparisons, analysis, and written deliverables using explicitly selected SearchWeb, Fetch, BrowseWeb, and Memory tools. It supplies no local filesystem workspace or remote workspace. Source, Workspace, SQL, and Delegate are unavailable inside Projects, including when a selected persona normally uses them. Existing standalone engineering agents and their explicit grants are unaffected.

## Recursive ToDo workflow

A project is a tree of ToDos, and every ToDo has one fixed assignment:

- A node creates fully specified direct children and refines only direct children it created. It never edits its own assignment, a sibling, or another node's task. Parent and provenance (`parent_id`, `creator_todo_id`) are forced by the current task and cannot be supplied by a caller.
- A parent yields to its children with `waitForChildren` and resumes on the same thread when answered; the current run is not stopped mid-protocol.
- Decisions go to the immediate parent (`target_todo_id`); a `null` target requires a human answer. Each decision has its own ID, the asking ToDo's `todo_revision`, a question, and optional evidence. Only the target parent may answer during execution, and only for the asking ToDo's current revision.
- Revising a direct child stops its subtree runs per node, resets the subtree to pending at a new revision, and invalidates ancestor evidence, review, and readiness.
- An independent model reviews every acceptance gate against durable tool results and evidence. Children passing is not enough: every gate needs specific supporting evidence, and all children must be done.
- Continuations (waking a completed run) are bounded at 64 and do not consume the failed-attempt budget; only fresh dispatches increment attempts.
- Tools are scoped to SearchWeb, Fetch, BrowseWeb, Memory plus the durable Project tool. There is no Workspace grant expansion inside Projects.

Depth is limited to 32 levels, 256 nodes per project, and 64 continuations per ToDo. Execution uses preplanned postorder plus a runtime coordinator: a node dispatches only after its children are done and ready, and the runner schedules one bounded pass at a time.

## Everyday CLI workflow

Use the installed `ok-phill` from any working directory, or the repository's `bin/ok-phill`. Mutations require an unprivileged Unix identity; local project management is owner-only. Names must be unique; prefer the numeric project ID returned by creation.

```sh
ok-phill projects create 'Release research' -p nemotron-vl-fast -t SearchWeb,Fetch
ok-phill projects plan 42 'Build nested ToDos to compare three primary migration guides, summarize compatibility changes, and check citations. Give every ToDo a goal, requirements, context, and explicit acceptance gates. Do not execute the plan yet.'
ok-phill projects result 42
ok-phill projects show 42 --compact
ok-phill projects prepare 42 --compact
```

Replace `42` with the returned ID and choose a profile configured on your installation. `plan` queues an ordinary planning-agent turn; its response can flesh out ToDos through scoped Project tools. `result` reads the latest planning/check-in answer, not the final project result. It exits `3` while pending. Recheck it after the queued planning turn finishes, inspect `show`, and resolve missing fields or readiness gaps.

`prepare` requests preflight and waits up to 30 seconds. It never starts tasks. If still queued/checking, wait for that same readiness check rather than enqueueing planning work:

```sh
ok-phill projects wait 42 --until ready --compact --timeout 30
ok-phill projects start 42
ok-phill projects wait 42 --compact --timeout 30
ok-phill projects show 42
ok-phill projects events 42 --after 0
```

`start` is an explicit human action and requires a ready preflight for the current revision and no unfinished planning runs. `wait` defaults to waiting for `completed`, while `--until ready` waits for current readiness. Both waits are bounded to `0..30` seconds per invocation. Exit `3` means the requested condition has not been reached, including timeout, gaps, or a blocked project; inspect the returned state before waiting again. Exit `0` means success, `1` an execution error, and `2` invalid usage. JSON goes to stdout; diagnostics go to stderr.

`show` returns the full snapshot: project, ToDos, progress, validation issues, and history. `show --compact` and `wait --compact` report status/readiness, progress, active or blocked ToDos, a bounded planning answer, and the latest event. `list --compact` summarizes your projects. For incremental history, pass the last observed event ID as `events --after ID`; events contain IDs, types, timestamps, and recorded data.

## Decisions and answers

A node that needs a parent's choice calls `escalate` with a bounded `question` and optional `evidence`, addressed to its immediate parent (`null` = root/human). The runtime records a decision identified by its decision ID, stamped with the current ToDo `todo_revision`. Only the target parent may answer, and only for the current `todo_revision`; root decisions require a human actor.

```sh
ok-phill projects decisions 42
ok-phill projects answer 42 9 "Use a 1 second refresh cadence."
```

Replace `42` with the current project ID and `9` with the decision ID returned by `decisions`. Answering a stale `todo_revision` is rejected.

## Trace evidence

`ok-phill projects trace PROJECT [TODO] [--after ID]` renders concise project activity evidence: event id, run id, todo id, type, name, tool error flag, and bounded content. It takes only the project ID, an optional ToDo id, and an optional `--after` event cursor; it passes no raw task arguments to a model and makes no model calls. Use `--after ID` for incremental history.

## Owner evidence

`ok-phill projects evidence PROJECT TODO --evidence JSON` records an owner attestation against the latest worker link for the current ToDo revision. It requires a local Git workspace root and a current link, and records the project and ToDo revisions, link and run IDs, Git HEAD, tracked dirty state, and observation time in project events.

Required string fields are `lint`, `test`, and `review`. Optional `remote_identity` is owner-supplied metadata; no remote transport is implemented. Git observations exclude untracked and ignored files and are a snapshot, not a lock on the workspace. The attestation does not execute or verify the named checks. Evidence does not change ToDo runtime state or add project runtime tools.

```sh
ok-phill projects evidence 42 7 --evidence '{"lint":"php -l passed","test":"suite passed","review":"owner reviewed","remote_identity":"ci:worker-3"}'
```

## Plan details and recovery

Every ToDo needs a title, goal, requirements, context, and at least one acceptance gate before Start. Drafts may be incomplete. Requirements and context are text; gates are a list of nonempty strings. Optional `persona_id` selects a saved persona, and `max_attempts` defaults to `3` with an allowed range of `1..10`.

```sh
ok-phill projects add 42 '{"title":"Check arithmetic","goal":"Compute a reproducible result","requirements":"Add 7 and 5 and show the calculation","context":"All inputs are supplied; no tools needed","gates":["The answer shows 7 + 5 = 12"],"max_attempts":3}'
ok-phill projects update 42 7 '{"context":"Use decimal integers 7 and 5 only"}'
ok-phill projects pause 42
ok-phill projects retry 42 7
ok-phill projects remove-todo 42 8 --descendants
ok-phill projects delete 42 --confirm
```

Replace ToDo IDs too. `add`/`update` also accept piped JSON. Pause before editing running work, and wait for active runs to settle. Plan, model, tool, prompt, and visibility changes invalidate readiness; run `prepare` again and explicitly Start to resume. `retry` resets a blocked ToDo for another attempt, invalidates readiness, and does not start it. Removing a parent requires explicit `--descendants`. Delete removes the project and its associated task histories and requires inactive work. Completed projects can be inspected, deleted, or reopened by the local owner. `ok-phill projects reopen PROJECT` returns a completed project to `planning`, archives prior ToDo state in the event history, resets ToDos to pending with new revisions, and invalidates readiness. Previous worker links and verdicts remain in history but become stale. A fresh `prepare` and explicit `start` are required before work resumes. Reopen is rejected while project execution or planning runs are queued or running.

Each ToDo has a separate execution thread and may use a different persona; it receives its own conversation history, task specification, minimal plan metadata, and completed child summaries rather than unrelated task conversations. Persona selection does not widen the project's tool grants. Scoped Project tools let the agent report findings, evidence, and blockers, but cannot grant Start or mark a task done.

An independent model reviews every acceptance gate against saved output, reported evidence, and actual tool results. Every gate needs a passing verdict with supporting evidence. This is an LLM assessment, not objective proof or a substitute for offline checks. Unmet gates may receive another attempt within the configured limit. Interrupted or uncertain execution, including potentially unknown side effects, stops for explicit investigation and manual retry instead of silently replaying work.

## Security boundary

Projects store structured task and decision data in PostgreSQL and interpret it with fixed, developer-written PHP (`Project`, `ProjectWorkflow`, `ProjectRunner`). Agents never generate executable workflow programs, and PHP or shell text stored inside a task remains inert data — it is never evaluated or executed by the Project runtime. The runtime Project tool ceiling is SearchWeb, Fetch, BrowseWeb, Memory, and the durable Project tool only; Workspace, Source, SQL, and Delegate are excluded. Separately approved engineering-development agents (standalone, outside Projects) may edit source under explicit operator approval, but that is development authority, not Project runtime workflow authority.

## Browser and APIs

Select **+ Project** in the sidebar, create a project with an enabled model profile (title, profile, visibility, tools), and add/edit ToDos or use Open planning chat. Projects also appear in the sidebar history beside conversations. The page shows readiness findings, nested tasks, attempts, progress, results, gate evidence, and recent events. ToDo rows use icon buttons (edit, add child, delete; the `+` under the table adds a top-level ToDo) that open PHP-rendered dialogs, and project decisions open the same way as editors (`?view=projects&project=ID&decision=ID`, "Decisions awaiting your answer"); both fall back to ordinary pages without JavaScript. Check readiness never starts work. Start Project or Resume project is enabled only when validation and current readiness permit it. Pause and Retry ToDo are explicit controls. The readiness check sends the model only the fields a project actually has: empty project-level goal, requirements, context, and gates are omitted rather than reported as gaps (`ProjectRunner::plan`).

Private, team, and organization visibility follows the planning thread. Team boundaries apply even to the creator after membership changes. Visible collaborators may edit the plan and start/pause work; settings, sharing, and deletion belong to the owner. Task conversations are readable progress histories managed by the runner. PHP renders initial pages and refreshed content; ordinary POST forms support essential Project actions without JavaScript. Polling preserves dirty forms and focus. Panel preferences remain browser-local; workflow state stays in PostgreSQL. The existing `public/` front-controller and asset layout is unchanged.

Authenticated HTTP callers use the same policy boundary and must provide `X-CSRF-Token` on POST requests:

| Route | Operation |
| --- | --- |
| `GET /api/projects`, `POST /api/projects` | List visible projects or create one |
| `GET /api/projects/ID` | Project and full snapshot |
| `POST /api/projects/ID/todos` | Add a draft ToDo |
| `POST /api/projects/ID/todos/TODO` | Update planning fields; `delete: true` removes it, with optional `descendants: true` |
| `POST /api/projects/ID/preflight`, `/start`, `/pause` | Request readiness, explicitly start/resume, or pause |
| `POST /api/projects/ID/retry/TODO` | Reset a blocked ToDo for manual retry |
| `GET /api/projects/ID/history?after=EVENT` | Incremental project events |
| `GET /api/projects/ID/decisions` | List project decisions |
| `POST /api/projects/ID/decisions/DECISION/answer` | Authorized human collaborator answers a root decision; child decisions belong to their target parent |
| `POST /api/projects/ID/settings`, `/share`, `/delete` | Owner settings, visibility, and deletion |

Planning chat uses the existing thread message endpoint and its stable `submission_key`. Callers cannot set owner, completion, evidence, status, or preflight authority through planning-field payloads.

Trusted PHP integrations use `new Agent\\Project($idOrUniqueNameOrRow)`, `Project::create($ownerId, $options)`, and `snapshot()`, `todos()`, `addTodo()`, `updateTodo()`, `removeTodo()`, `plan()`, `requestPreflight()`, `start($actorId)`, `pause()`, `retryTodo()`, `history($after)`, and `delete()`. Creation options include title, settings, visibility/team, and an optional existing owned `thread_id`. `Project::forThread($threadId)` resolves planning or task threads. Direct application calls remain trusted; `ProjectFrontend` and `ProjectAccess` enforce user-facing policy.

## Read-only TUI monitor

`phill --once --project 42` renders a read-only project monitor and exits. It lists local owner projects, makes no model calls, performs no mutations, and is not a full chat or TUI claim. Interactive TTY mode: `q` quits, `r` refreshes, a project number selects it. Replace `42` with the project ID or exact name.

```sh
phill --once --project 42
```

## Development shortcuts and checks

On this development machine, `ok-phill` and `phill-dev` are installed in `~/.local/bin`, outside the repository. They work from other directories; these wrappers are local conveniences rather than a portable installation requirement. `phill-dev` is also a proxy for everything inside the containers, so you rarely need to type `docker compose`:

```sh
phill-dev status
phill-dev project show 42 --compact
phill-dev agent list
phill-dev ask 'Summarize the supplied text.'        # the `default` profile unless -p is given
phill-dev research web-research 'Compare the supplied public documentation.'
phill-dev test projects project_runner project_integration project_frontend project_cli --docker
phill-dev test --all                                # every deterministic test (tests/run-all.sh); --live adds real-model tests
phill-dev check
phill-dev ui-live ui_projects                       # a real project run in a private installation, using the live model

phill-dev psql -c 'select count(*) from claims'     # the PHiLLiP database
phill-dev bin phill-models list                     # any bin/ command inside the app container (also: phill-dev phill-models list)
phill-dev exec -u 1002:1002 php tests/cli_memory.php
phill-dev logs worker --tail 50 -f
phill-dev ps | up | down | restart | build          # compose lifecycle
```

`status` reports Compose health and your active agents/projects without calling a model. `test` selects the shared test runner; `check` combines current-runtime PHP syntax checks with curated regression smoke tests. Functional checks use native PHP 8.3 and Docker PHP 8.5; PHP 7.x is unsupported. PostgreSQL is the current Project storage target; SQLite Project support is not this release's focus.

The Project suites cover core state, orchestration, integration/policy boundaries, PHP views/forms, and CLI contracts:

- `tests/projects.php`, `tests/project_integration.php`, `tests/project_frontend.php` — access policy, collaboration boundaries, and the PHP page/API.
- `tests/project_runner.php` — scheduling, dispatch, review, and recovery.
- `tests/project_workflow.php` — ownership, revision barriers, yields, escalations, and decisions.
- `tests/project_recursive.php` — recursive child creation, refinement, and postorder execution.
- `tests/project_recursive_security.php` — ancestry, revision, and subtree revision boundaries.
- `tests/project_recursive_interface.php` — workflow interface and context validation.
- `tests/project_cli.php`, `tests/project_payloads.php` — CLI contracts and inert task payloads.
- `tests/project_worker_links.php`, `tests/project_engineering_evidence.php` — passive worker links, verdicts, and owner evidence.
- `tests/project_tui.php` — read-only monitor rendering and access.
- `tests/agent_messages.php` — agent message feedback during active assignment.

Each runs in its own disposable database (see [testing](testing.md)); `tests/run-all.sh` runs them all.

`tests/project_recursive_live.php` is an opt-in real-model smoke test: `php tests/project_recursive_live.php --profile default` reads production's enabled profile (read-only), verifies and enables it in the test's own disposable database, and calls the real model; the created project is dropped with that database (set `PHILLIP_TEST_KEEP=1` to keep it). `--help` or no args prints usage and never calls the model. `tests/run-all.sh --live` runs it.

`tests/live/ui_projects.py` runs through `php tests/live_ui.php ui_projects` (it is skipped by default; ask for it by name, optionally `--profile NAME`). It starts a private web server and workers against the disposable database with the borrowed real model and checks incomplete plans, preserved drafts/focus, nested edits, readiness invalidation, explicit Start, reopening the page, child-before-parent execution, and passing evidence for every gate. It requires Python Playwright/Chromium and an enabled production profile to borrow; nothing is written to production.

### Passive engineering evidence

A local owner can attach an existing standalone local worker run to a ToDo with
`ok-phill projects link-run PROJECT TODO AGENT RUN`. This records a revision-stamped
link; it does not dispatch work, grant tools, or change Project runtime status.
The same run can support multiple ToDos. Repeating the same ToDo, revision, and
run is idempotent. Links from earlier ToDo revisions remain as stale history.

`ok-phill projects assignments PROJECT [TODO] [--compact]` shows engineering
links and aggregate accepted, rejected, pending, stale, and unlinked counts.
These counts are separate from Project runtime progress. Record an owner decision
with `ok-phill projects verdict PROJECT TODO accept|reject --evidence '{"checks":"..."}'`.
Only the latest link for the current ToDo revision may receive a verdict;
acceptance requires a successfully completed run, while rejection permits any
terminal run. Decisions, evidence, actor, and timestamps remain in history.

### Worker and workspace controls

Passive linking uses existing standalone local workers only: no nesting, no dispatch, no runtime tool changes. The existing explicit grant/revoke/stop controls apply unchanged: operator workspace grants (approve/revoke) and run/worker stop controls are independent of Projects and are not expanded by linking. Project runtime tools remain SearchWeb, Fetch, BrowseWeb, Memory, and the durable Project tool only; Workspace, Source, SQL, and Delegate are unchanged and unavailable inside Projects.
