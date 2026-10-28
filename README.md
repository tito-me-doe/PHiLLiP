# PHiLLiP

**A PHP Harness incorporating Large Language in PHP.**

PHiLLiP is an open-source, local-first AI platform for PHP developers and the people using their applications. It provides standalone web chat, command-line agents, project management, and PHP classes built on the same runtime.

Run models on your own infrastructure, connect tools to self-hosted services, and keep conversations and workflow state in PostgreSQL. Custom endpoints and authentication headers also allow connections to hosted model providers.

PHiLLiP is under active development. The features below are implemented; a full conversational TUI, project workspaces, and benchmarks are not yet available. Licensed under [MIT](LICENSE).

## Quick start

```sh
git clone https://github.com/tito-me-doe/PHiLLiP.git
cd PHiLLiP
./bin/install
```

Modern Debian and Ubuntu are the primary installation targets. You need Git to clone the repository; the Bash installer checks Docker and Compose and asks before installing missing dependencies. You do not need PHP installed on the host. Other package managers have best-effort fallbacks.

The installer:

1. Creates a private `.env` with generated secrets and starts the application, PostgreSQL, and background workers.
2. Creates an administrator, defaulting to your Unix username, or `admin` when run as root.
3. Prints the web URL and a temporary password. The default URL is `http://127.0.0.1:8088`.
4. Starts the bundled SearXNG search and Camoufox browser services. These are optional for basic chat.

Open the printed URL, sign in, and replace the temporary password. Web setup guides you through account configuration, model discovery, and verification of a default chat profile. Once setup finishes, you can chat immediately. One administrator account is sufficient; additional users and teams can be added later.

**Model servers are not included.** Run an engine such as vLLM, Ollama, or llama.cpp separately, reachable from the application container. Setup can scan your network or accept a custom endpoint. Embeddings, vision, and other optional capabilities are not prerequisites for basic chat.

Inside Docker, `localhost` refers to the container. Use a reachable host address or `host.docker.internal` for a model server on the Docker host, and ensure the server listens on an accessible interface.

```sh
./bin/install --check    # Report prerequisites without changing the machine
./bin/install --yes      # Accept dependency installation and local-only defaults
```

Rerunning the installer preserves existing configuration, accounts, and database contents. The web server binds to loopback by default; LAN exposure is an explicit installation choice. For remote access, use an SSH tunnel or an appropriately configured HTTPS reverse proxy.

## What works today

- **Web chat:** streamed responses, saved conversations, Markdown and highlighted code, attachments, editable user messages, model switching, selectable tools, and expandable tool/reasoning activity. Background work continues after a tab closes, and incoming messages queue sequentially.
- **Personas and appearance:** named system prompts with optional tools and response contracts; light/dark mode and floating, animated SVG companions. Avatars are independent of personas and use no speech synthesis.
- **Model registry:** discover engines and models, retain metadata and capabilities, and configure reusable profiles with a server, model, and request parameters.
- **Tools:** web search, fetching, browser interaction, memory, and scoped conversation history. CLI engineering agents additionally support code research, approved file changes and commands, database research, and delegation.
- **Durable agents and projects:** queued assignments, progress events, nested ToDos, readiness checks, escalation, and independently reviewed completion gates.
- **PHP integration:** use the same request, message, tool, logging, and workflow classes directly in application code.

The web UI is rendered by PHP. JavaScript handles streaming and interactivity; completed responses are refreshed from server-rendered HTML.

## Models and tools

Use **Settings → Models** to configure profiles. Discovery records metadata claims; enabling a profile runs capability tests before making it selectable in the frontend. A discovered model is not automatically an enabled chat model. Application code can select disabled profiles explicitly.

The `default` profile is used by ordinary CLI and PHP requests. Profiles resolve from the database first, with an optional private `config/profiles.local.json` fallback. Machine-specific profiles and credentials are not shipped in the repository.

```sh
# Replace the URL with your model server. Scan only systems you are authorized to inspect.
docker compose exec -T app ./bin/phill-scan --url http://host.docker.internal:8000 --json
docker compose exec -T app ./bin/phill-models --profiles
docker compose exec -T app ./bin/phill-models --test default --json
```

`phill-scan` also accepts `--cidr`, `--ports`, and `--no-save`; `-a` scans all TCP ports in the selected IPv4 ranges. Discovery reads metadata without generating responses or loading/unloading models. Capability verification does make inference requests.

The Compose stack supplies SearXNG for search and Camoufox for browsing. Configure connections under **Settings → Services**. Tools and attachment parsing use available capabilities and fallbacks; features needing an unconfigured service report what is missing.

## Command line

`ok-phill` returns only the final answer on stdout. Progress and diagnostics go to stderr, so results can be piped into other programs. A plain prompt uses the default model and grants no tools. With no prompt or piped input, it produces a short server-health greeting, with a factual fallback if the model is unavailable.

Run it inside the installed Docker environment:

```sh
docker compose exec -T --user www-data app ./bin/ok-phill 'Explain this PHP error.'
printf '%s\n' 'Text to summarize' | docker compose exec -T --user www-data app ./bin/ok-phill 'Summarize this input.'
docker compose exec -T --user www-data app ./bin/ok-phill -t SearchWeb,Fetch 'Research the current PHP release using official sources.'
docker compose exec -T --user www-data app ./bin/ok-phill --help
```

Use an unprivileged identity for tools: root cannot use tools or authenticated frontend sessions. `www-data` is an unprivileged container account, not automatically an application user or administrator. Native execution is also available through `./bin/ok-phill` when PHP, Composer dependencies, and the required service connections are installed on the host.

| Option | Purpose |
| --- | --- |
| `-p`, `--profile` | Profile name, ID, or inline JSON configuration |
| `--persona` | Visible persona name or ID; copies its prompt, optional tools, and contract |
| `-T`, `--thread-id` | `new` to save a conversation, or an ID to continue it |
| `-t`, `--tool` | Comma-separated tool names; `none` disables persona defaults |
| `-c`, `--contract` | JSON response contract or path to a JSON file; `{}` disables a default contract |
| `-s`, `--session-id` | Existing authenticated PHP session for controlled backend handoffs |
| `--debug`, `--verbose` | Diagnostic checkpoints or detailed traces, without changing stdout output |

Session IDs are bearer secrets: do not put them in shared shell history or logs. Named CLI tools are resolved as callables, never evaluated as PHP expressions or closures.

To keep a conversation:

```sh
docker compose exec -T --user www-data app ./bin/ok-phill -T new 'Help me plan a migration.'
# Replace 123 with the thread ID printed on stderr.
docker compose exec -T --user www-data app ./bin/ok-phill -T 123 'What should I verify first?'
```

Continue using the same Unix/container identity. Saved threads retain their profile and copied persona defaults. Without a persona, tools must be selected explicitly on each invocation. CLI continuations reject a busy thread rather than submitting duplicate work.

### Agents and engineering workflows

`ok-phill agents` manages persistent workers with separate conversations, sequential assignment queues, progress events, and explicit workspace grants. Different workers can run concurrently. The shipped persona catalog covers research, architecture, implementation, testing, security/adversarial review, documentation, classification, and coordination.

Discover the management commands without contacting a model:

```sh
docker compose exec -T --user www-data app ./bin/ok-phill agents help
docker compose exec -T --user www-data app ./bin/ok-phill personas list
docker compose exec -T --user www-data app ./bin/ok-phill workflows list
docker compose exec -T --user www-data app ./bin/ok-phill profiles help
```

The engineering catalog has a separate, operator-controlled installation step. See the [engineering agent guide](docs/engineering-team.md) for installation, assignments, workflow execution, approvals, and result inspection. A queued assignment is not a completed task, and a model's completion claim is not proof that its changes are correct.

## PHP integration

Load the shared initializer and send a request using a configured profile:

```php
require '/path/to/PHiLLiP/bootstrap.php';

$request = new \Agent\Request('default');
$request->addMessage('Explain the trade-offs of this design.');
$answer = $request->send();

// The final answer is returned; rounds, messages, and events remain in:
$details = $request->response;
```

The constructor also accepts a profile row, configuration array, or JSON configuration. Inline configuration containing `url` and `model` supports ephemeral requests without a database. Persistence, attachments, shared identities, and projects require their backing services.

Register tools or a response contract before sending:

```php
$request->registerTool('Agent\\Tools\\SearchWeb');
$request->registerContract([
    'answer' => ['type' => 'string'],
    'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
]);
```

Tool definitions are derived from callable signatures and docblocks. Registering a class exposes its public static methods. Response contracts use native structured output where appropriate, with local validation and bounded correction attempts.

Direct PHP calls are trusted application code, not the frontend permission boundary. Web and session-backed requests apply user/team tool policies. See the [runtime reference](docs/runtime.md) for shared input contracts, logging, attachments, and durable state.

## Projects

A project combines a planning conversation with a tree of ToDos. Each ToDo has a goal, requirements, context, acceptance gates, and its own execution conversation.

Before execution, all required fields must be complete, an agent readiness check must pass, and a person must explicitly select **Start Project**. Planning and readiness checks never start work automatically. During execution, agents can decompose their own assignments, refine children they created, and escalate decisions to their parent. They cannot rewrite their own assigned scope. Completion requires passing gate reviews with supporting evidence, not just finished children.

Projects currently support research and written deliverables using web/memory tools. They do **not** provide filesystem or remote workspaces; the broader tools available to standalone engineering agents do not carry into Projects. Independent review is an LLM assessment, not a substitute for objective checks.

Use Projects in the web sidebar or `ok-phill projects help`. `./bin/phill` is currently a **read-only project monitor**, with `--once` for a single snapshot; it is not yet a conversational TUI. See the [project guide](docs/projects.md) for planning, execution, decisions, recovery, and CLI/API usage.

## Users, sharing, and security

- Conversations, projects, and personas have exclusive private, one-team, or organization-wide visibility. “Organization-wide” means users of this installation, not publication on the internet.
- Team access requires current membership. Teams appear in normal navigation only when the current user shares them with another active user.
- Administrators constrain frontend tool availability; users then select which permitted tools to grant. CLI file/command approvals are separate and do not constitute an OS sandbox.
- Conversations retain their original stored messages while context sent to a model can be pruned or summarized. Background indexing and persistent memory depend on configured model capabilities; cross-thread memory access is permission-scoped.
- Deleting a chat from the sidebar hides it without erasing its stored history. Do not treat that action as data removal.

The shipped HTTP server is for local/development use. Keep secrets in private configuration, review tool grants, and do not expose demo accounts. All HTTP requests must pass through `public/index.php`; application classes, configuration, dependencies, and storage belong outside the document root.

Standalone accounts and onboarding are the validated path. Existing-application identity/session/table mapping hooks are present, but external ERP integration is not yet a fully validated deployment path.

## Running and developing

The Docker image supplies PHP 8.5; the default database is PostgreSQL with pgvector. Native development checks also run on PHP 8.3. PHP 7.x is unsupported; the Composer minimum of PHP 8.0 does not imply every PHP 8.x version has been validated.

Common service commands, run from the repository:

```sh
docker compose ps
docker compose logs --tail=100 app worker operations
docker compose stop          # Stop services without removing their containers
docker compose up -d         # Start the configured stack
docker compose up -d --build # Rebuild after Dockerfile changes
```

Do not remove the PostgreSQL volume unless you intend to erase the installation's database. Private `.env`, local JSON configuration, and runtime storage are excluded from version control and should be backed up appropriately.

Run the focused default tests, list available suites, or select suites explicitly:

```sh
docker compose exec -T app php tests/run.php
docker compose exec -T app php tests/run.php --list
docker compose exec -T app php tests/run.php onboarding public_boundary
docker compose exec -T app php tests/run.php projects project_workflow project_recursive
```

The default suite is self-contained. Additional suites may require PostgreSQL, other services, a live model, or a particular identity; inspect their prerequisites before running. Installer dependency tests use command stubs; `tests/install_compose.php --run` explicitly exercises an isolated real Compose installation. Browser tests use Python Playwright and Chromium as development tools, not application dependencies.

Browser dependencies are self-hosted. When changing their bundled assets, rebuild them with `npm ci && npm run build`.

## Further documentation

- [Engineering agents and workflows](docs/engineering-team.md)
- [Projects, recursive ToDos, and completion gates](docs/projects.md)
- [Runtime: PHP contracts, logging, attachments, and state](docs/runtime.md)
- [Best-effort input cleanup](docs/cleanup.md)

PHiLLiP is [MIT licensed](LICENSE) and can be embedded in proprietary PHP applications.
