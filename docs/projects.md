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

Replace ToDo IDs too. `add`/`update` also accept piped JSON. Pause before editing running work, and wait for active runs to settle. Plan, model, tool, prompt, and visibility changes invalidate readiness; run `prepare` again and explicitly Start to resume. `retry` resets a blocked ToDo for another attempt, invalidates readiness, and does not start it. Removing a parent requires explicit `--descendants`. Delete removes the project and its associated task histories and requires inactive work. Completed projects can be inspected or deleted; reopening a completed project is not implemented.

Each ToDo has a separate execution thread and may use a different persona; it receives its own conversation history, task specification, minimal plan metadata, and completed child summaries rather than unrelated task conversations. Persona selection does not widen the project's tool grants. Scoped Project tools let the agent report findings, evidence, and blockers, but cannot grant Start or mark a task done.

An independent model reviews every acceptance gate against saved output, reported evidence, and actual tool results. Every gate needs a passing verdict with supporting evidence. This is an LLM assessment, not objective proof or a substitute for offline checks. Unmet gates may receive another attempt within the configured limit. Interrupted or uncertain execution, including potentially unknown side effects, stops for explicit investigation and manual retry instead of silently replaying work.

## Security boundary

Projects store structured task and decision data in PostgreSQL and interpret it with fixed, developer-written PHP (`Project`, `ProjectWorkflow`, `ProjectRunner`). Agents never generate executable workflow programs, and PHP or shell text stored inside a task remains inert data — it is never evaluated or executed by the Project runtime. The runtime Project tool ceiling is SearchWeb, Fetch, BrowseWeb, Memory, and the durable Project tool only; Workspace, Source, SQL, and Delegate are excluded. Separately approved engineering-development agents (standalone, outside Projects) may edit source under explicit operator approval, but that is development authority, not Project runtime workflow authority.

## Browser and APIs

Open Projects in the sidebar, create a project with an enabled model profile, and add/edit ToDos or use Open planning chat. The page shows readiness findings, nested tasks, attempts, progress, results, gate evidence, and recent events. Check readiness never starts work. Start Project or Resume project is enabled only when validation and current readiness permit it. Pause and retry are explicit controls.

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

On this development machine, `ok-phill` and `phill-dev` are installed in `~/.local/bin`, outside the repository. They work from other directories; these wrappers are local conveniences rather than a portable installation requirement.

```sh
phill-dev status
phill-dev project show 42 --compact
phill-dev agent list
phill-dev ask -p nemotron-vl-fast 'Summarize the supplied text.'
phill-dev research web-research 'Compare the supplied public documentation.'
phill-dev test projects project_runner project_integration project_frontend project_cli --docker
phill-dev check
PHILLIP_PROFILE=nemotron-vl-fast python3 tests/ui_projects.py
```

`status` reports Compose health and your active agents/projects without calling a model. `test` selects the shared test runner; `check` combines current-runtime PHP syntax checks with curated regression smoke tests. Functional checks use native PHP 8.3 and Docker PHP 8.5; PHP 7.x is unsupported. PostgreSQL is the current Project storage target; SQLite Project support is not this release's focus.

The Project suites cover core state, orchestration, integration/policy boundaries, PHP views/forms, and CLI contracts:

- `tests/project_workflow.php` — ownership, revision barriers, yields, escalations, and decisions.
- `tests/project_recursive.php` — recursive child creation, refinement, and postorder execution.
- `tests/project_recursive_security.php` — ancestry, revision, and subtree revision boundaries.
- `tests/project_recursive_interface.php` — workflow interface and context validation.
- `tests/project_tui.php` — read-only monitor rendering and access.
- `tests/agent_messages.php` — agent message feedback during active assignment.

`tests/project_recursive_live.php` is an opt-in real-model smoke test: `php tests/project_recursive_live.php --profile nemotron-vl-fast` calls an explicitly real Nemotron model and retains the created project. `--help` or no args prints usage and never calls the model.

`ui_projects.py` uses the real enabled model: it checks incomplete plans, preserved drafts/focus, nested edits, readiness invalidation, explicit Start, reopening the page, child-before-parent execution, and passing evidence for every gate. It requires Python Playwright/Chromium, the running demo installation/workers, and configured model profiles; it removes its own fixtures.
