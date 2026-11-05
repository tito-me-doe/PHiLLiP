# Roadmap

These are discussed but unfinished capabilities, not available commands or release promises. Order follows the principle of validating one useful end-to-end layer at a time. Individual designs may change as real use exposes better solutions; no dates are committed here.

Existing foundations—standalone onboarding, registry/discovery, chat, `ok-phill`, durable engineering agents, character personas with composed avatars, recursive projects, benchmarks, explicit task-profile defaults, additive page/tool grants with delegated user management, shared logging/state, and initial attachment pipelines—are not future features. Their present boundaries are documented in [REQUIREMENTS.md](REQUIREMENTS.md), the [benchmark guide](docs/benchmarks.md), the [project guide](docs/projects.md), and the [engineering guide](docs/engineering-team.md).

## Conversational PHP TUI

Current `phill` is a read-only project monitor. Extend it into a terminal application using the same classes and durable execution as the web UI and `ok-phill`, not a separate agent implementation.

- Interactive chat and task work scoped to the current directory.
- Persona/profile/tool selection, saved conversations, and visible tool/progress activity.
- File/command approval prompts, queued follow-ups, escalating Stop, and reconnectable work.
- Project planning and ToDo editing, readiness findings, explicit Start confirmation, decisions, and progress inspection.
- Team/child-agent coordination without injecting all parent history into every child.

Completion should be demonstrated by real multi-turn work, approvals, interruption/recovery, and project coordination—not just terminal rendering. Keep `ok-phill` useful and independently tested as the scriptable foundation.

## Project workspaces and Git

Recursive ToDos already support decomposition, parent escalation, revision, and independent gate review. The unfinished layer is safe work against an actual project workspace.

- Optional remote filesystem/CLI workspace for web projects, with explicit connection identity and administrator/user authority limits. Web agents must not implicitly gain local application-host access.
- Read versus patch approvals per thread; patch includes read, while read does not grant patch.
- Command approvals with a well-defined literal-argv prefix contract, revocation, cancellation, and auditable results. Existing CLI engineering approvals are a foundation, not a sandbox or a completed remote-workspace design.
- Git inspection and change/review workflows linked to ToDos, with implementation, lint/test, and reviewer evidence contributing to acceptance gates.
- Bridge verified workspace evidence into the existing project state machine without letting agents execute generated workflow programs or self-approve completion.

The current Project tool ceiling deliberately excludes Source, Workspace, SQL, and Delegate. Expanding it requires an explicit authority design and tests; a persona must not silently widen that ceiling. Reopening a completed project exists only as `ok-phill projects reopen` for the local owner; there is no web control.

## Benchmarks and model exploration

The single-run command, durable runner, administrator report/Pareto chart, process controls, test/question editor, six scorers, and three ten-question suites are implemented. See the [operator guide](docs/benchmarks.md). Definitions/configuration are snapshotted, question conversations remain inspectable, and known-incompatible profile/test pairs are skipped.

### Runner and discovery extensions

- Persist and refresh the Hugging Face catalog. `phill-scan-hf` only prints the current top 100 results as JSON (trending by default, `-s` for another sort); stored entries must remain distinct from locally runnable models.
- If an Unsloth Studio endpoint is available, request permission to load a selected nonlocal Hugging Face model and automate load/unload/configuration through its API. `phill-unsloth status` reads `/v1/models`, but `load` and `unload` are unsupported until Studio publishes a stable control API.
- A model-assisted step recommending suitable loading settings for the target server, with validation and operator control rather than unbounded arbitrary configuration.

Catalog persistence and Unsloth load/unload orchestration remain unimplemented. Add real-engine coverage beyond the currently validated local chat/embedding paths; fixture coverage for a schema is not a guarantee for every provider implementing it. Expand evaluation quality and repeatability before treating short-suite scores as model-quality claims.

## Task-specific routing and privacy policy

Fixed `profile_defaults` assignments now cover embedding, reranking, classification, extraction, title generation, summarization, and evaluation. Callers explicitly request the operation; an unassigned task falls back to the current/general-purpose model. Document parsing retains its MIME/extension mappings. **Do not infer a model choice from prompt content.**

- Extend explicit operation/capability fallbacks where genuinely useful, with an actionable error when no valid route exists. A chat-only model cannot fabricate embeddings. Existing attachment fallbacks are not a universal delegation/router implementation.
- Administrator-enforced restrictions preventing specified data categories from leaving the organization, regardless of user-selected endpoints. `DataExitPolicy` is inactive by default, has no administrator page, and is enforced only at the tool-call seam; model and broker seams are not wired. See [privacy](docs/privacy.md).
- A local obfuscate → external processing → local recovery workflow, with inspectable mappings and failure behavior. `PrivacyTransform` holds tokens in process memory and tool arguments can be obfuscated, but results are not recovered end to end. This is an intended privacy aid, not a present protection or a guarantee that anonymization is safe.
- Continue supporting hosted/premium endpoints without displacing the local/self-hostable default path.

## Broader PHP service interfaces

`Request` supports chat completions and native embedding/rerank operations. `Models` and the opt-in Tasks tools expose embedding similarity, reranking, classification, and extraction through explicit defaults. Broader public service interfaces remain incremental work:

- Provider-specific Responses APIs and SystemOne requests where useful; do not present these as existing standalone request classes.
- Expose `VectorStore` collections and `GraphStore` entities/edges (see [knowledge services](docs/knowledge-services.md)) through tools, CLI, and UI. They exist as PHP classes only, with no evidence linking to source-backed knowledge claims.
- Redis integration if a concrete need justifies it. Redis is not a current runtime requirement.
- Broader business-database access patterns. Engineering SQL research currently exposes restricted curated PostgreSQL relations, not arbitrary ERP data to agents.

These should remain simple PHP contracts with strict internals, not a speculative framework or duplicated per-transport clients.

## Integration, formats, and deployment coverage

- Validate real existing-application user/team/permission mappings independently, including mixed mapped/new tables, read-only/writable mappings, and ERP session mounting.
- Exercise PostgreSQL, MySQL, MariaDB, and SQLite mapping paths with realistic external schemas; this does not imply replacing the internal PostgreSQL database.
- Expand converter/file-type coverage and targeted parser profiles only where native input or current pipelines are insufficient.
- Establish measured resource guidance, supported runtime/platform/browser coverage, and deployment hardening beyond the supplied local/development HTTP server.

Standalone functionality stays the first validation target. Legacy PHP 7.x support, a branching chat UI, speech synthesis/audio avatars, persona schemas decomposed beyond the character sheet, and agent-authored executable project workflows are not goals of this roadmap. Expressive silent SVG avatars composed by PHP and character-sheet personas are already the chosen approach.
