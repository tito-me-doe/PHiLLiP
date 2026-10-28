# PHiLLiP

A PHP Harness incorporating Large Language in PHP. MIT licensed.

Development targets PHP 8.5 and PostgreSQL, with functional checks on native PHP 8.3 and Docker PHP 8.5. PHP 7.x is unsupported and is no longer a testing requirement. Prefer older-version-friendly code where practical, without requiring legacy compatibility. The Composer minimum is PHP 8.0; that does not imply every PHP 8.x release has been validated.

```sh
git clone https://github.com/tito-me-doe/PHiLLiP
cd PHiLLiP
./bin/install
```

The Bash installer targets modern Ubuntu and Debian, checks Docker/Compose, and asks before installing missing packages or exposing the web interface to your LAN. PHP is supplied by Docker. Other common package managers have best-effort fallbacks. `./bin/install --check` reports prerequisites without changing anything; `--yes` accepts dependency installation and local-only defaults. Docker-group membership is never changed automatically.

Dependency installation follows Docker's signed-repository instructions for [Ubuntu](https://docs.docker.com/engine/install/ubuntu/) and [Debian](https://docs.docker.com/engine/install/debian/). `--debug` and `--verbose` adjust bootstrap diagnostics without enabling unsafe shell tracing of secrets.

On a fresh installation it generates private `.env` secrets and creates one administrator, using your current Unix username (`admin` when run as root). The temporary password is printed once, never stored in plaintext, and must be replaced at first sign-in. Existing credentials, configuration, and database contents are preserved on reruns. The script prints the browser URL as soon as the core web services are ready, before starting optional search/browser services.

Web setup guides you through password replacement, standalone or existing-application preferences, network discovery, and default model verification. A working default chat profile is required before finishing. Discovery and verification run in the background and survive page reloads. The animated avatar explains focused fields without any LLM or speech synthesis. One account is enough; teams appear in normal navigation and sharing controls only when you share that team with another active user. Administration still lets you create teams and memberships.

Model engines are not bundled: run a supported engine separately on a reachable host. Optional capabilities are discovered progressively; embedding and vision models are not prerequisites for ordinary chat. External mappings/session hooks remain available; standalone onboarding is the validated path.

First-run regression checks use isolated database schemas, leaving your installation untouched:

```sh
php tests/install.php                 # Stubbed Ubuntu/Debian dependency paths, usernames, secrets, reruns
php tests/onboarding.php              # Real PHP HTTP routes, workers, model fixture, three follow-ups
php tests/onboarding.php --browser    # Also requires Playwright/Chromium; desktop/mobile and no-JS checks
php tests/install_compose.php --run   # Real Docker first-run/rerun, isolated ports/project/database
```

The database is private to the Compose network. The development HTTP server binds to localhost by default. Model engines run separately on the LAN. Change `SCAN_CIDRS` to match your network.

The HTTP document root is `public/`, containing only `index.php` and browser assets. Classes, configuration, templates, dependencies and storage remain outside it. All requests, including assets, must pass through `public/index.php`; the configured development command is `php -S 127.0.0.1:8080 -t public public/index.php`. Configure the equivalent front-controller dispatch when embedding in an ERP or another web server, and set `APP_BASE_PATH` to its URL prefix. This dispatch validates asset paths and types; serving arbitrary files directly bypasses that validation.

Implementation proceeds one tested layer at a time: infrastructure, model registry, discovery, local profiles, single requests, ephemeral conversations, tools, threads, identities/permissions, and WebUI.

## Registry and discovery

```sh
docker compose exec app php bin/phill-migrate
docker compose exec app php bin/phill-scan
docker compose exec app php bin/phill-scan --cidr 10.0.2.99/32 --ports 8000,11434
docker compose exec app php bin/phill-scan --url http://10.0.2.99:8000 --json
docker compose exec app php bin/phill-models --profiles
docker compose exec -T app ./bin/phill-models --test nemotron-vl-work --json
docker compose exec -T app ./bin/phill-models --save - --json < profile.json
```

`phill-scan -a` checks all TCP ports in the configured IPv4 CIDRs. Default scanning checks common engine ports. Discovery requests metadata only: it never loads/unloads models or generates text. Engine identification covers vLLM, Ollama, llama.cpp, and generic OpenAI-compatible servers. Available and loaded Ollama models are distinguished. Capability values are metadata claims, with `null` meaning unknown; profile enablement will require inference tests. Use `--no-save` for read-only discovery.

Protocol references: [vLLM](https://docs.vllm.ai/en/latest/serving/openai_compatible_server/), [Ollama](https://github.com/ollama/ollama/blob/main/docs/api.md), [llama.cpp](https://github.com/ggml-org/llama.cpp/blob/master/tools/server/README.md).

`phill-models --save` accepts a named profile payload as inline JSON, a JSON file, or `-` for stdin (1 MiB limit). It uses the same `Profiles::save()` contract as settings: `{"name":"example","config":{"preset":"nemotron-vl-work"},"enabled":true}`. Enabling probes capabilities before making the profile selectable. `--test NAME_OR_ID` probes without changing saved state. Save/test result output omits private configuration and raw engine error bodies; diagnostics go to stderr. All CLI scripts accept `--debug` and `--verbose`.

Validation:

```sh
php tests/run.php                         # Focused, self-contained tests
docker compose exec app php tests/run.php --list
docker compose exec app php tests/run.php state workflow_tightening cli
docker compose exec app php tests/infrastructure.php
docker compose exec app php tests/registry.php
docker compose exec app php tests/scanner.php
```

## Chat and development accounts

Open [PHiLLiP](http://127.0.0.1:8088). Fresh installations show onboarding; existing ERP users, teams, memberships and tool permissions can be mapped independently through PDO. Standalone installations use local identity tables.

For local testing only:

```sh
docker compose exec app php bin/phill-demo
```

Demo usernames are `admin`, `alice`, `bob`, `carol`, and `visitor`; password `phillip-demo`. Their teams and tool grants differ. Do not expose demo accounts or the PHP development server publicly. Enable a discovered or custom model under Settings → Models; Save tests its actual capabilities. Discovered, disabled profiles remain usable by application code.

PHP renders initial pages, saved messages, Markdown, forms, and HTML fragments. JavaScript handles streaming, interaction, syntax highlighting, and expressive SVG animation. Completed responses are refreshed from PHP. Basic sign-in and message submission also work through ordinary PHP forms without JavaScript. Model execution and indexing run in separate workers and survive closing a browser tab.

Start a chat by choosing a persona card or sending a message directly. Persona selection happens only at the start; the owner can still edit the copied system prompt in the top-right chat settings. The composer’s tools icon opens a tools-only popover. Hover the conversation title to rename it, or a sidebar conversation to reveal Delete. Deletion marks `threads.deletedon` and hides the conversation without destroying its history. Untitled conversations receive an asynchronous, ephemeral title request using the first answer’s model; manual titles are never overwritten.

Appearance settings and the chat’s moon button switch light/dark mode. The preference stays in browser-local storage, with a validated per-user cookie for immediate PHP rendering. One floating SVG companion uses a generic CSS talking animation while a response streams and reacts to phrases and emoji; it is independent of personas. Each eye blinks separately on every screen, including login and settings. Reduced motion keeps the body still and uses quiet opacity-only eye/mouth changes. Usable persona cards appear before disabled choices, retaining their existing order within each group. Success notices expire after three seconds and information after five; warnings/errors remain dismissible and diagnostic records are retained.

`phill-dev ui-onboarding` exercises isolated setup and chat in desktop/mobile browsers, including automatic discovery updates, persona cards, theme persistence, avatar reactions, tool calls, title edits, soft deletion, alert expiry, repeated streamed follow-ups and no-JavaScript chat. `php tests/run.php onboarding public_boundary` covers the corresponding backend and document-root security checks without demo-account dependencies.

## Projects

Projects add a planning conversation, nested ToDos, readiness checks, and durable execution. Open Projects in the sidebar or use the shared CLI:

```sh
ok-phill projects create 'Documentation research' -p nemotron-vl-fast -t SearchWeb,Fetch
ok-phill projects plan 42 'Develop a nested research plan with clear inputs, requirements, and acceptance gates for every ToDo.'
ok-phill projects result 42
ok-phill projects prepare 42 --compact
ok-phill projects start 42
ok-phill projects wait 42 --compact
```

Replace `42` with the returned project ID and choose an available profile. Planning is queued; inspect its answer and resolve gaps before preparing. `prepare` never starts execution: only explicit Start does. Waits return after at most 30 seconds; repeat when pending or use `wait 42 --until ready --compact` for readiness. Full snapshots, compact summaries, and event cursors support inspection from assistants and terminals.

Each ToDo has required goal/requirements/context/gates, optional nesting and persona, and a separate task conversation. Children complete before parents. Independent model reviews require evidence for every gate; those verdicts do not replace offline verification. Changes invalidate readiness, attempts are bounded, and interrupted work needs explicit investigation/retry. Projects currently offer web/memory tools, without local or remote filesystem workspaces, Source, Workspace, SQL, or Delegate. Standalone engineering agents keep their existing tools.

On this machine, PATH wrappers `ok-phill` and `phill-dev` are installed outside the repository. `phill-dev status`, `test`, and `check` cover everyday inspection and verification; `agent`, `project`, `ask`, and `research` use the shared product interfaces from any working directory. `check` performs current-runtime PHP syntax checks and regression smoke tests; current Docker functional testing runs PHP 8.5. See [Projects](docs/projects.md) for CLI/API usage, ownership, recovery, and focused browser checks.

## PHP integration

```php
require '/path/to/PHiLLiP/bootstrap.php';
$request = new \Agent\Request('default'); // Database profile, then JSON fallback
$request->registerTool('Agent\\Tools\\SearchWeb');
$request->addMessages([
    ['role' => 'system', 'content' => 'You are a helpful assistant.'],
    ['role' => 'user', 'content' => 'Explain this idea using reliable sources.'],
]);
$answer = $request->send(); // Final answer only; execution details: $request->response
```

The one-off `config/profiles.local.json` contains profiles from this machine's discovered endpoints and is intentionally ignored by version control. For another installation, discover its endpoints and configure profiles for that environment. Model engines are not included in Compose; PostgreSQL/pgvector, SearXNG, and remote Camoufox are.

## `ok-phill`

`bin/ok-phill` is an executable PHP shebang script, without an extension. Add the project's `bin` directory to your PATH or invoke it directly:

On this machine the native CLI reaches PostgreSQL at `127.0.0.1:15432`, Camoufox at `127.0.0.1:18082`, and SearXNG at `127.0.0.1:18083`; all are loopback-only. Compose supplies private service URLs to its PHP processes. Default service configurations opt into those runtime URLs with `url_env`; custom database URLs remain authoritative unless they explicitly opt in. Keep `APP_HOSTNAME` stable: saved container CLI identities use the OS hostname. Native and container workers intentionally have separate host identities. The app container uses an init process to reap detached workers.

```sh
./bin/ok-phill 'Explain this PHP error.'
printf '%s\n' 'A document to summarize' | ./bin/ok-phill 'Summarize this input.'
./bin/ok-phill -p default -t Fetch,SearchWeb 'Research this question.'
./bin/ok-phill -c '{"answer":{"type":"string"}}' 'Return your answer as JSON.'
./bin/ok-phill -T new -p default 'Remember cobalt for our next turn.'
./bin/ok-phill -T 123 'What word did I ask you to remember?'
./bin/ok-phill                    # Short, model-generated server-health greeting
docker compose exec -T app ./bin/ok-phill 'Hello.'
docker compose exec -T --user www-data app ./bin/ok-phill -t Memory 'Recall the company onboarding process.'
```

Only the final answer goes to stdout; progress, warnings and errors go to stderr. A plain prompt grants **zero tools**, regardless of defaults inherited from its profile. Piped stdin is used as the prompt, or appended to an explicit prompt. Empty input requests a bounded, read-only system/service health report. Checks describe reachability from that process, not proof a service has stopped elsewhere. If the model is unavailable, the health command produces a deterministic factual greeting instead. The usage hint does not claim `phill` exists before the TUI is implemented.

Options are deliberately small:

- `-p` / `--profile`: database profile name or ID, or a profile-compatible JSON object. Names take precedence over numeric IDs. JSON configuration also works without PostgreSQL.
- `--persona`: saved persona ID or unambiguous name visible to the current identity. Applies its name, system prompt, optional tools, and optional contract. A persona never grants permissions.
- `-t` / `--tool`: comma-separated class or callable **names**, optionally repeated. Built-in short names such as `Fetch` and `Memory`, and qualified static method names, are accepted. PHP expressions/closures are never evaluated.
- `-c` / `--contract`: a JSON object or path to a JSON file, validated through the same request contract mechanism as PHP/WebUI callers.
- `-s` / `--session-id`: an existing authenticated PHP session, resolved using the same authentication/mappings as the frontend. Invalid or inactive sessions fail closed; a session-backed invocation also enforces frontend tool policy.
- `-T` / `--thread-id`: `new` saves a conversation; a positive ID continues one owned by the current identity. The thread ID is always emitted on stderr, even with logging disabled. Continuations reuse the saved profile unless `-p` is supplied. Persona defaults persist; without a persona, tools still require `-t` each time. Busy threads are rejected before enqueueing, not silently run twice. WebUI submissions retain their sequential queue behavior.
- `-h` / `--help`; `--` ends option parsing for dash-prefixed prompts.

The effective Unix UID and passwd entry determine CLI identity; `USER`, `LOGNAME` and `SUDO_USER` cannot grant access. Root (or an unidentified UID) cannot supply tools or sessions. An active mapped Unix username may retrieve its private/team/organization memories. An unmapped or inactive account is restricted to organization-published knowledge, organization-visible conversations, and its current thread. Direct PHP application calls remain trusted, as before. Memory and session-backed invocations use the existing persisted Thread/Run/events pipeline, leaving source-backed private history available for indexing and later frontend continuation. Ordinary one-shot requests remain ephemeral and database-optional.

Session IDs are bearer secrets: avoid shell history, logs, and untrusted local process listings. This option is intended for controlled frontend/backend handoffs, not credential sharing. Root processes must drop privileges before a session handoff. Session authentication never creates a new authenticated identity from an invalid token.

Exit codes: `0` final answer/help/health report produced, `1` execution/authentication failure, `2` invalid arguments, `130` Ctrl+C, `143` termination. Interrupted requests do not print partial answers. Native execution outside Docker needs reachable service/database addresses; Compose-only DNS names are not automatically reachable from the host. Relative configuration paths resolve from the installation directory, not the caller's working directory.

### Local development delegation

This machine has verified `nemotron-vl-work` (reasoning enabled) and `nemotron-vl-fast` (reasoning disabled) profiles for Nemotron 3.5 Super VL on `10.0.2.83:8000`. Both support chat, streaming, reflected tools, structured output and images; neither supplies embeddings or reranking. These are local registry entries, not assumed defaults on another machine.

```sh
docker compose exec -T --user 1002:1002 app ./bin/ok-phill -T new -p nemotron-vl-work -t Source 'Inspect the tool registry and propose one focused regression test.'
docker compose exec -T --user 1002:1002 app ./bin/ok-phill -T 123 -t Source 'Here are the test results; revise your proposal.'
docker compose exec -T --user 1002:1002 app ./bin/ok-phill -p nemotron-vl-work -t BrowseWeb 'Open http://phillip.internal:8080/, inspect a screenshot, and close the browser.'
docker compose exec -T --user 1002:1002 app php tests/cli_dogfood.php
```

Replace `123` with the reported thread ID. `1002:1002` is an unprivileged local test identity, not an application administrator. `Source::files()` and `Source::read()` are opt-in, read-only CLI tools rooted at the current working directory. They reject frontend/session use, traversal, symlinks and private/hidden paths; listings skip unaddressable filenames. Reads are bounded to 2 MiB files, 500 lines and 32 KiB of valid UTF-8 output. This is not an OS sandbox against concurrent privileged filesystem changes. No shell execution or source-writing tool is granted.

`BrowseWeb::screenshot()` returns a bounded viewport PNG as native image content, with page metadata and the existing browser-session scope checks. Tools may return an explicit `['role' => 'tool', 'content' => $nativeParts]` message; the shared message contract validates it, and the registry supplies the real call ID. Ordinary tool arrays still become JSON text. Invalid tool text becomes recoverable tool feedback. PHP renders saved screenshot activity; it never fetches arbitrary remote image URLs for display.

Browser observations include usable selectors, visible form values (passwords redacted), and dropdown options, prioritizing open dialogs. `BrowseWeb::navigate()` retains the session; `BrowseWeb::select()` operates native dropdowns. Use returned selectors instead of guessing element IDs.

Profiles may set `max_rounds` (default 10) to bound agent/model rounds independently of transport retries and contract corrections. For a longer browser task, an explicit profile override can be supplied as `-p '{"preset":"nemotron-vl-work","max_rounds":20}'`. The limit is enforced by PHiLLiP, not forwarded to the engine.

Output-token exhaustion stops immediately with an actionable limit error, preserving the partial assistant response and reasoning rather than wasting contract-correction attempts. Model-generated patches still require review and tests; a successful invocation is not proof its claims are correct.

## `ok-phill agents` — durable agent management

`ok-phill agents ACTION [options]` manages workers. Names or numeric IDs identify agents. Results are JSON on stdout; diagnostics go to stderr.

Create two agents with distinct saved personas (install the shipped catalog with `ok-phill workflows install` first):

```sh
ok-phill agents create researcher --team dev --persona 'Code Researcher' -p nemotron-vl-work
ok-phill agents create coder --team dev --persona 'Implementer' -p nemotron-vl-work
```

Assign before waiting; queued follow-ups stack on the same worker:

```sh
ok-phill agents assign researcher 'Inspect README.md and propose a clearer usage example.'
ok-phill agents assign coder 'Review classes/Agent/Cli.php for one reproducible bug.'
ok-phill agents assign coder 'Now propose a focused regression test for your finding.'
ok-phill agents wait researcher --timeout 60
ok-phill agents wait coder --timeout 60
```

Read results and stream progress:

```sh
ok-phill agents result researcher
ok-phill agents events researcher --after 0
```

`agents show NAME` includes the copied system prompt, current grants, and the thirty most recent tasks. `list` stays concise. For an explicit live end-to-end check, run `php tests/agent_team_live.php nemotron-vl-work` as an unprivileged user; it creates and removes only its own two fixture workers.

Stop a team or one agent:

```sh
ok-phill agents stop --team dev
ok-phill agents stop researcher
```

`remove NAME` deletes an idle worker, its descendants, and their history; it is explicit-action only and never removes a running worker.

Task lifecycle: create/configure → assign (idempotent `--key`) → pause/resume → wait/events → result → stop → remove.

### Work and peek

`agents work NAME [TASK] [--file RELPATH] [--command JSON_ARGV] [--key KEY] [--timeout 0..30]` combines create-or-reuse with explicit, repeatable workspace grants and an async assignment. `--file` (repeatable) grants one workspace-relative file as patch (patch includes read); `--command` (repeatable) grants a command's first three argv words as literal JSON argv — not a shell prefix and not an OS sandbox. Grants are validated before any worker is created or configured. `--key` makes the assignment idempotent: retry with the same name/task/key while busy to get the same run; follow up with name/task/key only while busy, never settings or grants. `work` returns a `run_id` with a queued/running status, not a completion claim; `--timeout` waits up to 30 seconds and pending returns exit 3.

`agents peek NAME` reports bounded real tool evidence for the active run, or the latest assignment when idle: run status/error, answer excerpt, file changes (path/sha256/bytes), command checks (command/exit_code/timed_out/truncated/stderr), and tool errors. It is evidence, not proof the task's claims are correct.

```sh
ok-phill agents work coder --persona Implementer -p nemotron-vl-engineering 'Add a focused regression test for the Cli.php finding.' --file tests/regression.php --command '["php","tests/regression.php"]' --key regression-1 --timeout 30
ok-phill agents peek coder
```
The unattended project path is `projects prepare` → `projects start` → `projects wait`; readiness never auto starts.

Permissions: opt-in `Workspace` provides exact-text replacements, exclusive file creation, and literal-argv commands. `approve NAME --file RELPATH [--read|--patch]` grants one file (patch includes read); `approve NAME --command JSON_ARGV` approves the first three argv words. `revoke` removes a grant for future operations, not an already accepted command. Approvals are per-thread, per-workspace and not inherited. `Source` is a separate explicit read-only workspace grant and needs no individual file-patch approval. Command approval authorizes that program's behavior as the Unix user; it is not an OS sandbox. Protect secrets and approve executable scripts with care.

Worker teams are local groupings, not organization permission teams. Workers belong to the same Unix UID, host/container and application identity that created them. Different agents run in parallel; each agent's queue is sequential. Stop first asks the active agent to conclude; repeated calls escalate to cancellation and forced process cleanup. Queued tasks are cancelled and the worker stays paused until resumed. Agent threads still receive background indexing.

Give a coordinator `Delegate` and the tools it may grant to children. `Delegate` creates isolated persona-backed children, assigns tasks, and retrieves or waits for their results. Children receive task briefs, not raw parent history; their tool grants cannot exceed their parent's. Delegation is bounded to depth two and sixteen workers per team. Include workspace-relative file paths in briefs. A completed response is not proof that the task's claims are correct: inspect evidence and run tests.

Exit codes: `0` success/completed, `1` error or failed/stopped, `2` invalid usage, `3` pending/timeout. JSON on stdout; stderr for diagnostics. Poll `events --after ID` for resumable progress. Names duplicated across teams require an explicit agent ID. Use `ok-phill -- agents ...` for a normal prompt beginning with the reserved command word.

Personas are saved and listed under Settings → Personas or entirely through the CLI:

```sh
ok-phill personas list
ok-phill personas save '{"name":"Reviewer","prompt":"Review source carefully and report evidence.","tools":"Source"}'
ok-phill personas show Reviewer
ok-phill personas save '{"prompt":"Updated instructions"}' --id 123
```

`save -` accepts JSON stdin; `delete NAME_OR_ID` removes an owned persona. Unmapped local users can manage only their private personas; mapped users retain existing application permissions. Root is read-only. Workers copy their persona defaults and retain their selected model; `agents configure` changes idle workers explicitly. Editing the source persona does not rewrite a worker's history.

### Engineering workflows

The shipped catalog has thirteen reusable roles covering coordination, web/code/database research, architecture, implementation, testing, adversarial/security review, classification, documentation, UI testing, and operations.

```sh
ok-phill workflows list
ok-phill workflows run code-research 'Trace request error handling and its tests.'
ok-phill workflows run implement 'Make this focused change and verify it.'
ok-phill agents result COORDINATOR_ID
```

Workflows launch durable coordinators with isolated specialists, bounded tool grants, independent review, and explicit approval handoffs. A queued handle is not a completed task. Existing-file `Workspace::write` uses a current SHA-256 to prevent stale overwrites; three identical tool failures stop with preserved feedback rather than looping indefinitely. SQL research uses a separate read-only PostgreSQL role and exposes only curated aggregates and schema metadata, never private application records. See [engineering-team.md](docs/engineering-team.md) for workflow IDs, commands, approval/resume steps, and scope.

### Personas

A persona has only a name, a freeform system prompt, optional named tools, and an optional response contract, alongside its existing owner/visibility fields. There is no prompt decomposition or implicit model selection. Create/edit them under Settings → Personas. The name supplies a short identity preface and labels assistant responses; the prompt itself is kept intact. Avatars remain an independent user preference.

```sh
./bin/ok-phill --persona 'PHP developer' -T new 'Help me investigate this issue.'
./bin/ok-phill -T 123 'Here are the results; what next?'
./bin/ok-phill --persona 'PHP developer' -T 123 -t none -c '{}' 'No tools or contract this turn.'
```

Replace `123` with the reported thread ID; an initial `-p` can choose a model, which the thread retains. Names resolve only within the caller's visible personas; duplicates require an explicit ID. Private/team/organization rules also apply to CLI selection, including unmapped Unix identities. Direct application calls remain trusted.

Selecting a persona copies its prompt, name, tools and contract into editable conversation settings, with no live link to the source persona. Later edits/deletion do not rewrite those conversations or rename past responses. Explicit `-t` / `-c` replace defaults for that invocation; `-t none` and `-c '{}'` disable them. The next continuation restores the copied defaults. WebUI edits to the conversation update its copied settings. Selecting a different persona explicitly reapplies its defaults. Root and session-backed tool restrictions still apply.

```php
$request = new \Agent\Request([
    'preset' => 'default',
    'persona' => 'PHP developer', // Saved ID/name, or a trusted inline definition
    // 'tools' => [],           // Explicit keys override persona defaults
    // 'contract' => null,
]);
$request->addMessage('Review this design.');
$answer = $request->send();
```

Explicit system/developer messages take precedence over the default prompt. Persona contracts accept JSON objects, not server file paths. Response contracts are self-contained; local `#` schema references are supported, external file/network references are rejected. With tools enabled, generation remains unconstrained so engines can emit tool calls, followed by local contract validation and bounded correction. Tool-free requests use native structured output where available.

## One runtime and common messages

Every executable, worker, API, HTML entry point, and PHP integration uses `bootstrap.php`. Composer's `vendor/autoload.php` also invokes this same initializer. Initialization is idempotent and lazy: merely loading classes does not open a database connection or PHP session. The active Composer loader resolves classes when present; the same entry point has a project-class fallback without vendor dependencies. PHP errors/exceptions use the shared message routing, keeping diagnostics out of structured response bodies.

```php
use Agent\Log;

Log::writeError($anything, $anotherThing);
Log::writeDebug('Profile %s took %.2f seconds', $name, $elapsed);
Log::writeVerbose($request, $response, $arbitraryArray);
Log::write(-1, 'A warning');

Log::with(['console' => Log::VERBOSE, 'file' => Log::DEBUG], function () {
    // Focused diagnostic settings; restored even if this section throws.
});
```

Priorities: Verbose `-4`, Debug `-3`, Info `-2`, Warning `-1`, Success `0`, Error `1`, Fatal `2`. Each has a `writeName(...$values)` proxy. Arrays, objects, exceptions, resources and recursive values are normalized safely; common credential keys are redacted. Recognized sprintf patterns are formatted, additional arguments retained, and malformed patterns fall back to readable arguments. Message emission is fail-safe: failed serializers, invalid priorities or unavailable sinks do not replace the application's outcome. Log severity does not itself terminate a process; the caller controls program flow.

`Log::configure()` and scoped `Log::with()` independently set `user`, `admin`, `console`, `file`, and `database` minimum priorities (`3` disables a sink). Public minimum-priority properties and the corresponding `LOG_MIN_*` environment values are also available. The optional database sink uses `logs.raw`; it is disabled by default. File logs are private JSON lines under `storage`, separated by process UID/day, or an explicit `LOG_FILE`/`path`. Logging never requires database availability unless that sink is enabled. CLI diagnostics default to stderr; other scripts may explicitly select `cli_stream => 'split'` for ordinary messages on stdout and errors on stderr. `ok-phill` always reserves stdout for its result. `Terminal` handles TTY detection, `NO_COLOR`, `FORCE_COLOR`, ANSI styles, dimensions and a transient progress line.

Every CLI executable accepts `--debug` and `--verbose` through the shared initializer. Debug enables concise checkpoints; Verbose enables raw request/response, tool input/output and exception call-path traces. These flags override output and enabled-log thresholds for that invocation; disabled file/database sinks remain disabled. Verbose wins when both appear. Place `--` before a literal prompt containing either flag. `ok-phill --verbose "Explain this code"` still sends only the final answer to stdout, with diagnostics on stderr.

`Log::trace(...$values)` (and browser `PHiLLiPLog.trace`) uses Verbose with privileged-only delivery, even if ordinary-user thresholds are lowered. Structured credential keys, nested JSON configurations, authentication header lists and credential-bearing URLs are redacted. Exception call paths omit positional arguments, which cannot be reliably classified as secrets. Trace sinks remain opt-in; raw traces can contain sensitive application/conversation data and belong only in trusted consoles and private logs.

The initializer maps PHP warnings to Warning and notices/deprecations to Debug, respecting `@` and `error_reporting()` masks. Recoverable/user-fatal errors become catchable `ErrorException`s; uncaught `Throwable`s and shutdown fatals report once through the logger with their source location. Native PHP diagnostic output is disabled to avoid duplicate output or broken JSON. A small emergency reserve supports memory-exhaustion reporting. CLI failures exit nonzero without injecting diagnostics into stdout. HTTP failures replace buffered output with a generic, reference-linked 500 response in JSON, HTML or SSE format; already-sent responses are not appended with a second document. Errors before initialization, process kills and already-transmitted bytes cannot be repaired by a PHP handler.

HTTP delivery detects SAPI, method and JSON expectations, resolves existing session credentials lazily, and emits audience-filtered `X-PHiLLiP-Messages` envelopes without modifying JSON/fragment bodies. Ordinary buffered responses can still deliver messages emitted after content; explicit event-stream requests are not buffered. Error/Fatal messages set HTTP 400/500 only when an existing error status is not more specific; a scope can disable status changes with `http_status => false`.

PHP renders the global messages container in the page header and emits a safe JSON footer for later messages. The browser counterpart is `PHiLLiPLog` (also `PHiLLiP.Log`): the same priorities, `writeError(...)`, `writeDebug(...)`, formatting, safe text rendering and dismissible severity styling. Console-only server diagnostics are transmitted **only** to administrators or server-configured `APP_DEVELOPER_IDS`; ordinary users do not receive hidden debug data. Browser-created messages never POST back to the server. `minPriority` controls visible client messages; `minLogPriority` controls browser console output. Scoped client overrides use `PHiLLiPLog.with({visible: -1, console: -4}, callback)`.

Mutation boundaries report success or error, with Debug timing/status/identity metadata and optional raw traces. A queued message is acknowledged as accepted, not falsely reported as completed. Polling/read requests do not generate success notifications. Normal PHP form successes survive redirects once; JavaScript actions avoid duplicating server outcomes. Success notices expire and do not block underlying controls.

Messages emitted inside a background run also use the durable workflow event feed, with server-side audience filtering before transmission. Reloaded PHP fragments restore visible run messages; cursor progression remains intact when privileged diagnostics are suppressed. Thread-scoped messages follow navigation and disappear when their source run is deleted.

Convenience interfaces intentionally normalize a small, documented set of shapes before entering strict internals: profiles accept name/ID/array/JSON; contracts accept array/object/JSON/file; CLI tools accept names, never executable expressions. Recoverable interpretation may warn; ambiguous or impossible requests still throw. This is not a blanket rule to make every internal method multi-shape.

### Shared input contracts

Repeated callers should pass the data they already have. Recognition, safe cleanup, hydration and defaults belong in the shared contract, not separate conversions in controllers, forms or scripts:

```php
$request = new \Agent\Request($registry->profile('default')); // Whole registry row, not decoded config.
$request->addMessage('user', 'Hello');                       // Either role/content order.
$request->addMessages($thread->messages());                  // Stored rows, not extracted raw JSON.

$user = \Agent\Identity::user($userRow);                     // ID, username, row/object, reference JSON.
$user = \Agent\Identity::authenticate($credentials);        // Username/password payload, or two arguments.
$thread = new \Agent\Thread($threadRow);                    // ID, row/object, JSON row, or Thread.
$thread = \Agent\Thread::create($user['id'], [
    'title' => 'A conversation', 'settings' => ['profile' => 'default']
]);
$files = \Agent\Attachments::normalize($attachmentIds);     // ID/row, list, CSV, or JSON.
```

`Message::normalize()` always returns a message array with `role` and `content`, preserving additional raw metadata. It accepts text, message arrays/decoded objects, recognized message JSON, database rows, and native multimodal content parts. `Message::many()` accepts one message or a list. Two arguments require exactly one recognized role; use an explicit payload for ambiguous content such as the literal word `assistant`. Ordinary JSON data that is not a message remains text. Stored-row IDs override stale source IDs; model projection excludes application metadata.

`Config::profile()` extracts request configuration from a profile row; `Config::resolve()` also applies inheritance and private credentials. `Profiles::save()` accepts a single named payload or its existing positional arguments. Profile config, capability overrides, service maps and attachment-parser maps accept their existing JSON textarea strings directly. Schema normalization/validation accept array, decoded object, JSON or schema-file inputs. `Thread::options()` is the pure creation-options contract shared by direct and frontend creation.

Hydration does not grant authority: identity/thread/persona/attachment reference lookups reload database state; frontend profile selection stores the administrator-enabled profile reference, never supplied execution config. User lookup is not authentication. Internal foreign-key/permission lookups explicitly identify primary keys so missing IDs cannot become usernames. Non-unique thread titles are not lookup keys; ambiguous visible persona names require IDs. Direct trusted application configuration remains available without frontend policy checks.

Safe cleanup is selective: whitespace around numeric resource references, CSV items and recognized boolean settings can be normalized; invalid ports/IDs are rejected rather than truncated or stripped into a different identifier. Existing exact usernames remain resolvable; password bytes and message content are not trimmed or HTML-escaped by input normalization. Output escaping remains specific to HTML, terminal and other sinks. Recursive or ambiguous configuration fails explicitly rather than silently becoming a different request.

`Config::boolean()` is the shared form/JSON boolean contract used by permission grants, administrator flags, profile enabling and scan options: explicit `false`/`off`/`no`/`0` remain false, unlike PHP truthiness. Ambiguous values throw before changing the corresponding setting or permission.

`Utilities::cleanup($input, $contract)` is the best-effort engine underneath shared normalizers. It logs permitted guesses as warnings and failures as errors, then returns the input in its current, possibly partially cleaned condition; it never throws. Callers check their own requirements. JSON decoding uses a temporary array and only adopts usable non-empty containers unless the contract explicitly permits empty ones. See [cleanup contracts and examples](docs/cleanup.md).

Reflected tools use this same normalization for typed boolean, integer, and float arguments, including variadics. Explicit false strings remain false; ambiguous booleans, fractional integer IDs, overflow and nonfinite numbers return a tool error without executing the callable. Variadic parameter descriptions and item types are reflected separately.

`Database::row($sql, $params)`, `rows()` and `execute()` reuse up to 32 prepared statements per PDO connection, returning one associative row (or `false`), all rows, or the affected-row count. Every call executes against current database/transaction state and releases its cursor; no query results or permissions are cached. Hot workflow/state/log queries use these helpers. Bulk registry and knowledge writes reuse operation-local statements within their loops.

```sh
docker compose exec app php tests/logging.php
docker compose exec app php tests/run.php runtime runtime_cli_options user_actions public_boundary
docker compose exec app php tests/log_events.php
docker compose exec app php tests/cli.php
docker compose exec app php tests/cli_memory.php
docker compose exec app php tests/messages.php
docker compose exec app php tests/messages_attachments.php
docker compose exec app php tests/row_contracts.php
docker compose exec app php tests/input_contracts.php
docker compose exec app php tests/profile_protection.php
docker compose exec app php tests/resource_contracts.php
docker compose exec app php tests/settings_contracts.php
docker compose exec app php tests/utilities.php
docker compose exec app php tests/utilities_adversarial.php
docker compose exec app php tests/cleanup_integrations.php
```

Browser message regression: `tests/ui_messages.py` (Python Playwright is a development/test tool only). Production scripts, workers and the future TUI remain PHP.

## Attachments

The chat accepts text/code, native images, PDFs, DOCX and ODT. Original bytes and immutable file metadata are stored in PostgreSQL; message `raw` contains their references. File downloads follow current thread visibility, including immediately revoked team access. Upload retries use stable keys, and editing preserves the source message's files while deleting orphaned descendants.

Native image/PDF support comes first when the selected profile advertises it. Otherwise, a configured per-file-type parsing profile runs before local conversion. Vision models receive PDF page images; text-only models use Poppler text extraction and English Tesseract OCR for scanned pages/images. DOCX/ODT use bounded PHP-native ZIP/XML parsing. Text/code is consumed directly, never executed. Unsupported files produce an actionable error rather than being silently omitted. Rejected native input or an insufficient context budget falls back to a text representation when possible. Native PDF transport is fixture-tested; real local-model tests cover Ollama image input and rendered PDF pages, not native PDF support.

Settings → Services → Attachment parsing maps exact MIME types, `.extensions`, or wildcards to profile names, in that lookup order. Configuration is database-first, then optional `config/attachment-parsers.local.json` (or `ATTACHMENT_PARSER_JSON`). For example: `{"image/*":"local-vision",".pdf":"document-parser"}`. These profiles need not be enabled in the chat dropdown. Local converter output and model-derived representations are cached. The background indexer extracts attachment text where possible for source-backed search and knowledge extraction; unavailable extraction is reported as degraded indexing, without losing the original file.

```php
$file = \Agent\Attachments::store('erp-user-id', 'invoice.pdf', file_get_contents('/path/to/invoice.pdf'));
$request = new \Agent\Request('default');
$request->addMessage(['content' => 'Explain this invoice.', 'attachments' => [$file]]);
$answer = $request->send();
```

Application calls are trusted; the frontend adapter enforces permissions. Attachments require PostgreSQL, including for ephemeral requests. Text excerpts are bounded to fit the profile; tool-capable models automatically receive reflected, current-conversation file listing/range-reading tools. Longer original text remains retrievable. PDF image/OCR conversion currently covers the first eight pages, with an explicit partial-results notice; native PDF input and ordinary PDF text extraction are not subject to that page cap. Known limits: 10 MiB per file (`ATTACHMENT_MAX_BYTES`), eight files per message, combined size at most twice the per-file limit, and 40 megapixels for native images. Increasing upload limits also requires adjusting `docker/php.ini` and rebuilding. Conversion commands are allowlisted, time/output bounded, and use private temporary directories.

PHP renders uploaded-file chips and saved messages. Browser-local attachment drafts are isolated by conversation and restored after replacing the composer. Basic multipart PDF upload also works with JavaScript disabled. Unattached uploads, including ephemeral application files, expire after 24 hours; linked originals remain until their last message reference is deleted. The operations worker periodically collects expired uploads in bounded batches; manual cleanup is `docker compose exec app php bin/phill-attachments-gc`.

```sh
docker compose exec app php tests/attachments.php
docker compose exec app php tests/attachment_media.php
docker compose exec app php tests/attachment_fallbacks.php
docker compose exec app php tests/attachment_conversation.php
docker compose exec app php tests/attachment_vision.php # Uses the locally discovered Gemma 3 endpoint
```

## Durable workflow and response state

`Agent\State` stores JSON objects scoped to resources, with revisions, atomic patches, optimistic concurrency, and parent/child relationships. Thread/run state is linked by foreign keys and deleted with replaced history. Projects and durable agents use the shared runtime; their domain state and scheduling live in their own services rather than this generic state API.

```php
$state = \Agent\State::run($runId);
$task = $state->child('agent', 'unique-task-id', ['task' => 'Research this question']);
$snapshot = $task->read();
$task->patch(['progress' => 'Checking sources'], $snapshot['revision']);
// A stale revision throws Agent\StateConflict; reread and reconcile before retrying.
```

`runs` remains authoritative for lifecycle/stop status. Event recording, live-response projections, and message persistence are transactional. `WorkflowState::snapshot()` and the event feed provide consistent state; clients do not need to reconstruct live text by replaying every delta. Missing projections recover from durable events on the next update, without stale process-wide state handles. Setup failures and deliberately deleted child runs do not block the next queued run. Profile and parameter authentication headers are stored by private reference in thread/run snapshots; execution and indexing restore them through the same configuration contract. Recovery can also rebuild projections without invoking any model or tool:

```sh
docker compose exec app php bin/phill-state-rebuild       # Incomplete projections
docker compose exec app php bin/phill-state-rebuild 123   # One specific run
```

Frontend message POSTs require a stable `submission_key`. Retrying the same user/thread/key/payload returns the original run, including while it is running or after completion. Different content with the same key returns HTTP 409. Thread creation similarly accepts `creation_key`. Database uniqueness and per-thread locking protect concurrent requests; only one run can be active per thread. This does not promise exactly-once external tool side effects after a process crash: uncertain runs are stopped, not automatically replayed.

UI preferences and panel state live in per-user local storage, not PostgreSQL. A small cookie mirrors avatar choice for immediate server rendering. Unacknowledged submission keys survive browser reloads locally. API callers must retain their own keys across retries.

Additional regression checks:

```sh
docker compose exec app php tests/submissions.php
docker compose exec app php tests/state.php
docker compose exec app php tests/views.php
docker compose exec app php tests/threads.php
docker compose exec app php tests/worker.php
docker compose exec app php tests/http.php
```

Browser regressions require Python Playwright and Chromium, a running demo installation, and an enabled chat profile: `tests/ui.py`, `tests/ui_followups.py`, `tests/ui_frontend_tightening.py`, `tests/ui_submissions.py`, `tests/ui_attachments.py`, and `tests/ui_server.py`. The follow-up test exercises ten completed turns plus Stop, queued follow-ups, tool use, editing, delayed event replies, PHP fragment replacement, and duplicate/mixed-stream detection. The tightening test holds a PHP fragment response to check that completed state immediately clears transient output and controls. Submission and attachment tests deliberately lose real HTTP acknowledgements after commit before retrying. Attachment tests additionally cover composer replacement, thread-scoped local drafts, permission changes, file lifecycle, and no-JavaScript PDF upload.

Focused Project checks are `projects`, `project_runner`, `project_integration`, `project_frontend`, and `project_cli` through the shared PHP test runner. `tests/ui_projects.py` exercises a real enabled model through nested planning, readiness invalidation, explicit Start, persisted execution, and completion gates; see its prerequisites in [Projects](docs/projects.md).

This remains an incremental development build: additional file formats, Project filesystem/remote-workspace support, the rich TUI, and benchmarks are later layers. Markdown is rendered with [Parsedown safe mode](https://github.com/erusev/parsedown#security); browser assets are self-hosted and built using `npm ci && npm run build` when changed.
