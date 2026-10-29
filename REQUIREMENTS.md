# Requirements and validated coverage

This document describes the current standalone development system, not a certification of every platform allowed by package metadata. See [installation and administration](docs/administration.md) for setup and operation, and [CONTRIBUTING.md](CONTRIBUTING.md) for testing instructions.

## Recommended installation

| Component | Current expectation |
| --- | --- |
| Host | Modern Debian or Ubuntu with Bash and Git |
| Containers | Docker Engine/Desktop with the Compose plugin; access to the daemon |
| Application | PHP 8.5 supplied by the Docker image |
| Internal database | PostgreSQL 17 with pgvector, supplied by Compose |
| Model engine | A separately running, reachable compatible endpoint; not bundled |
| Browser | A modern browser; JavaScript enables streaming, live updates, and animation |
| Storage | Writable application storage/log paths and persistent PostgreSQL volume |
| Network | Access to model/services; downloads during installation/build |

Host PHP is not needed for `./bin/install` or Docker-based use. The installer asks before installing missing packages or selecting LAN exposure. It does not automatically add users to the Docker group. Other package-manager fallbacks exist but are not validated like the Debian/Ubuntu installer path. Native Windows execution, macOS installation, and alternate web servers are not validated deployment paths.

No measured minimum CPU, RAM, disk, GPU, or concurrency specification has been established. Model hardware requirements belong to the separately hosted engines; do not mistake the PHP application's requirements for the memory needed to load a model. Budget separately for browser processes, model calls, conversion jobs, and growing conversation/attachment/index storage. The Camoufox service requests a 1 GiB shared-memory allocation in Compose; that is not a total-memory recommendation.

## Native PHP and application-code use

Development targets Docker PHP 8.5; native functional checks have also run on PHP 8.3. `composer.json` declares PHP `>=8.0`, but that does not establish full coverage for PHP 8.0–8.4. PHP 7.x is unsupported and is not a testing requirement. Ubuntu 18.04 is not a current validated target.

Install Composer dependencies with `composer install` for native use. Composer declares `curl`, `json`, and `pdo`; the complete application's needs exceed that minimal declaration:

- `pdo_pgsql` for PHiLLiP's internal PostgreSQL storage; `pdo_mysql`/`pdo_sqlite` only for features or mappings using those drivers.
- `mbstring` and `fileinfo` for text handling and attachment detection.
- `zip`, DOM, and libxml for DOCX/ODT conversion.
- Unix identity/process support, including POSIX and PCNTL functions, for local workers and cancellation behavior.
- Enabled `exec`/`proc_open`, Bash, `/usr/bin/setsid`, and `/usr/bin/nohup` for detached engineering agents; process/conversion functionality is not suited to a restricted shared-hosting account.

The Docker image supplies the reference extensions and Linux utilities. Prefer it when you need the full application rather than a limited embedded request client. Native services must be reachable using host addresses; Compose service names do not automatically resolve on the host.

Inline `Agent\Request` configuration can perform ephemeral text requests without PostgreSQL. Saved profiles can also fall back to private JSON. Threads, accounts, attachments, workflow state, projects, and knowledge features require the internal database. MySQL, MariaDB, and SQLite PDO mapping support does **not** mean those engines can replace PostgreSQL for the complete application. Graph relationships currently use PostgreSQL tables and recursive queries; Apache AGE and Redis are not required or bundled.

## Models and optional features

| Feature | Required capability or service |
| --- | --- |
| Basic chat | A working chat-completions profile; setup verifies the profile named `default` |
| Streamed output | Streaming supported by the selected endpoint/profile |
| Agent tools | Working model tool calling, selected tools, and applicable grants |
| Web search | SearXNG or the configured search service |
| Browser interaction | Camoufox service; vision is needed to interpret screenshot images |
| Vector retrieval | `embed` task default (or capable current model) and pgvector-backed indexing |
| Knowledge extraction | Working chat model/profile and background indexer; extraction is asynchronous |
| Native images/PDF | Corresponding verified model/transport capability; not every engine supports these |
| Local PDF/text/OCR fallback | Poppler and English Tesseract, included in the application image |
| Projects | PostgreSQL, run worker, project scheduler, and a working chat profile |
| Benchmarks | PostgreSQL and a saved model profile; persistent queue execution uses the `benchmarks` worker |
| Engineering SQL research | Operator-provisioned restricted research role and curated views |

The registry recognizes vLLM, Ollama, llama.cpp, and generic OpenAI-compatible endpoint shapes. Compatibility depends on the actual server, model, chat template, parameters, and enabled features. Metadata alone is not verification. Profiles must pass capability tests before frontend enablement; a single working chat probe does not imply vision, tools, or every request parameter is supported.

Ordinary chat does not require embedding or vision models. Unavailable converters/services can reduce functionality or trigger fallbacks; successful basic chat does not establish that all optional features are configured. Native PDF transport has fixture coverage, while live visual-document checks have exercised image/page-image paths; universal native-PDF support is not claimed.

From a container, `localhost` is that container. Use an accessible LAN address or `host.docker.internal` for a model on the Docker host, and make sure the engine listens on that interface. Camoufox has a separate network namespace too; `http://phillip.internal:8080/` reaches the Compose app from that service. Scan only networks you are authorized to inspect.

## Operational and security caveats

- Keep the run worker, indexer, operations worker, and project scheduler running for the features they own. Closing a browser tab does not stop accepted work.
- Use a writable private `LOG_FILE` or storage path for the Unix identity running each process. A different CLI UID does not automatically gain write access to another user's files. Keep `APP_HOSTNAME` stable for persisted container-agent identity.
- Root cannot use CLI tools or frontend session handoffs. An unmapped Unix account is not an application administrator; memory access is limited to its allowed audience. CLI engineering workers retain the creating UID/host/application identity.
- File/command approvals are not an OS sandbox. Approving a command prefix authorizes the program's behavior as that Unix user. Review scripts and grants carefully.
- The shipped PHP HTTP server is a local/development server, not a production deployment recipe. All HTTP requests must route through `public/index.php`; exposing the repository root can expose secrets and source files.
- Default service ports bind to loopback. Enabling LAN HTTP does not add TLS. Use appropriate HTTPS/authentication/network controls before broader access; do not expose demo accounts.
- Local-first is not an enforced data-loss-prevention boundary. Hosted endpoints and internet-facing tools can transmit data. Administrator routing restrictions and obfuscation/recovery are deferred; see [ROADMAP.md](ROADMAP.md).
- Sidebar chat deletion is a soft delete, not data erasure. Editing earlier messages can instead remove later messages and their references. Project/agent deletion has separate, destructive semantics.
- Idempotent submissions prevent duplicate accepted work for the same key/payload, not exactly-once external side effects after a crash. Uncertain runs stop for investigation instead of automatic replay.
- Verbose traces may contain sensitive application/conversation data despite credential redaction. Restrict trace sinks and access to logs/backups.

## Current limits

Attachments default to 10 MiB each, eight files per message, a combined limit twice the per-file limit, and 40 megapixels for native images. PDF image/OCR conversion currently processes the first eight pages with a partial-results notice; ordinary text extraction/native PDF input do not share that page cap. Increasing application limits also requires matching PHP upload/post settings. See [runtime attachment handling](docs/runtime.md#attachments).

Project execution currently has no filesystem/remote workspace and excludes Source, Workspace, SQL, and Delegate tools. Standalone engineering agents have a different, explicitly approved tool boundary. Gate review is an independent LLM assessment, not objective proof that a deliverable is correct. `phill` is presently a read-only project monitor rather than a full conversational TUI.

## What has been validated

Coverage is layered. The following describes development paths and regression coverage, not a claim that every suite is run on every commit or all engine versions are supported:

| Area | Evidence and limits |
| --- | --- |
| PHP runtime | Native PHP 8.3 and Docker PHP 8.5 functional checks; no PHP 7.x matrix |
| Debian/Ubuntu installer logic | Stubbed package-manager/dependency tests, generated secrets, usernames, and reruns; not clean-OS package installation for every release |
| Standalone onboarding | Isolated PostgreSQL schemas, real PHP routes/workers, fixture model discovery/verification, password change, and repeated chat turns |
| Real Compose first run | Opt-in disposable Compose harness covers installation/rerun; requires a Docker-capable host and is distinct from dependency stubs |
| Core requests | Fixture tests for messages, tools, schemas, retries, stream decoding, limits, and error paths; the default six-suite runner does not require a live model |
| Engines | Metadata fixtures cover vLLM/Ollama/llama.cpp shapes; live endpoint/model testing is selective, not exhaustive |
| Web UI | Browser regressions cover desktop/mobile onboarding, no-JS forms, repeated follow-ups, fragment replacement, queue/Stop behavior, tools, appearance, and attachments under their fixtures |
| Permissions/state/projects | PHP suites cover authorization, submissions, revisions, recursive decomposition, escalation, review, and recovery; some integration suites need demo fixtures |
| Navigation/management | Isolated legacy-upgrade and HTTP/browser coverage for grants-only user/group policy, implicit Everyone, fixed operator groups, overlapping memberships, revocation, readonly tables/modal editors, and mixed history |
| ERP/mapped identities | Configuration hooks exist; external ERP/session/table mappings are not an end-to-end validated integration |

Native embedding schemas (`openai`, `ollama`, `tei`) and native rerank schemas (`openai`, `tei`) have fixture coverage. A local Ollama Nomic endpoint has been exercised live through the OpenAI-style embedding adapter; classification/extraction/semantic-ranking tools have live Nemotron coverage. This does not certify every native engine/schema combination. Benchmark HTTP/browser checks use isolated fixtures.

See [CONTRIBUTING.md](CONTRIBUTING.md#test-a-focused-change) for commands and prerequisites. Read test setup before execution: some suites create their own schemas, some need seeded identities/services, and live tests can incur real model calls. No production security audit, performance SLO, exhaustive browser matrix, or cross-platform compatibility certification is claimed.
