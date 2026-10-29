# Installation and administration

See [REQUIREMENTS.md](../REQUIREMENTS.md) for dependencies, security caveats, and validation coverage. The standalone installation is the primary tested path; external application mappings are available but not fully integration-validated.

## Install and finish setup

From a clone of the repository:

```sh
./bin/install
```

The Bash installer checks Docker/Compose and asks before installing missing dependencies. It creates private `.env` secrets, starts the database/application/workers, and creates an administrator. The username defaults to the invoking Unix username, or `admin` for root. The temporary password is printed once and must be changed at first sign-in.

The script prints the web URL as soon as core services are ready, then starts optional SearXNG/Camoufox services. The default URL is `http://127.0.0.1:8088`. Follow the printed URL rather than assuming the defaults if you changed the bind address/port.

```sh
./bin/install --check    # Prerequisites only; no system changes
./bin/install --yes      # Accept dependency installation and local-only defaults
./bin/install --debug    # Diagnostic checkpoints
./bin/install --verbose  # More detailed bootstrap diagnostics
```

Reruns retain configuration, credentials, and database contents. They do not reset an existing administrator password. Docker-group membership is never changed automatically.

In the browser:

1. Sign in and replace the temporary password.
2. Choose standalone accounts or existing-application mapping preferences. One account is enough; teams and additional users are optional.
3. Discover models on an authorized network range, or configure a custom endpoint and any authentication headers.
4. Select and verify a working `default` chat profile, then finish setup.

Discovery/verification are background jobs with progress and reload-safe results. Metadata claims do not automatically enable a model. Embedding, vision, search, and browsing are optional capabilities, not basic-chat prerequisites. Model engines run separately and must be reachable from the containers.

## Profiles, discovery, and services

Use **Models** for model/profile administration. Profiles bind a model, endpoint, and parameters. Saving an enabled profile tests its actual capabilities; disabled discovered profiles remain inspectable and usable by trusted code. Arbitrary frontend clients may not substitute their own execution configuration for an enabled reference.

```sh
# Substitute your endpoint; host.docker.internal refers to the Docker host.
docker compose exec -T app ./bin/phill-scan --url http://host.docker.internal:8000 --json
docker compose exec -T app ./bin/phill-models --profiles
docker compose exec -T app ./bin/phill-models --test default --json
docker compose exec -T app ./bin/phill-models --help
```

`phill-scan` accepts `--cidr`, `--ports`, `--no-save`, and `-a` for all TCP ports in the selected IPv4 ranges. Default ports/ranges come from configuration. Discovery reads metadata without generating text or loading/unloading models; verification performs inference probes. Scan only systems you have authority to inspect.

`phill-models --save` accepts inline JSON, a file, or `-` for stdin. A payload contains a name/configuration and optional enabling/overrides. `--test` probes without changing the saved profile. Use private stdin/files for credentials, not shared shell history. Profile resolution is database-first, then optional private JSON; see [PHP configuration](php-classes.md#profiles-and-personas).

Configure tool services on **Services** and per-file-type profiles on **Attachment parsing**. Compose supplies SearXNG and Camoufox. Custom database service URLs remain authoritative unless explicitly configured to use runtime environment URLs. Inside a container, `localhost` is that container, not the host or browser service.

Task defaults are configured on **Models** or through `ok-phill profiles defaults` / `ok-phill profiles default TASK PROFILE_OR_none`. Fixed concepts map to a saved profile, never prompt-based routing. Missing assignments retain the current model. See [benchmarks and typed model operations](benchmarks.md).

## Pages, navigation, and permissions

The sidebar combines conversations and projects in one activity-ordered history. Visibility tabs sit above equal-width **+ Chat** / **+ Project** buttons. The top-left hamburger toggles a flat menu sorted by route path; the logo is on its right and the local badge sits beside the profile. Personas, Memory, Benchmarks, Test Suites, Appearance, and administrative concepts have their own pages.

**Access control** combines Users, Groups, and Permissions on one screen. Lists are read-only tables; icon actions open PHP-rendered modal forms (ordinary editor pages without JavaScript). Creation actions sit in left-aligned table footers. Edit a user's groups in the user editor, or a group's members in the group editor. There is no separate memberships page. Memberships remain many-to-many. **Navigation & routes** shows each page's allowed users and groups and edits those same global permission rows alongside labels and paths. There are no menu sections, manual positions, enabled flags, or menu-visibility flags.

Every standalone install creates fixed groups **0 Everyone**, **1 Administrators**, and **2 Developers**. Everyone is implicit for each active, signed-in user. It starts with only `/chat` and SearchWeb, Fetch, BrowseWeb, and Memory availability—not other pages or tools. Tool availability still requires explicit selection by the user/agent configuration. The first administrator joins both 1 and 2. Active Administrators automatically have all permissions, including future pages and tools; no explicit grants are needed or stored for group 1. Developers identifies the logging/console audience and does not itself grant other pages. Built-in IDs/names cannot be edited or deleted. Everyone is not offered as a team-sharing folder; use organization visibility for that audience.

The `pages` table is the source for both the menu and HTML routes. A forbidden page is omitted from navigation and rejected before its view runs. Related APIs, fragments, and legacy query links enforce the same page policy. Handlers are a fixed PHP allowlist, not database-supplied executable paths. Additional routes can reuse a registered concept, but require both their own permission and the original concept's permission.

Permissions are additive grants for a resource (`page.models` or a tool class), assigned to a user or group. Except for active Administrators, a matching grant from the user or any group is required; otherwise access is denied. Everyone is simply group 0. There are no deny rules or default-allow pages. Removing one grant does not cancel another. Content visibility separately requires ownership, group membership, or organization visibility; administrator permissions do not expose private or unjoined-group conversations or override message ownership and execution approvals. A permitted page is both routed and shown in the menu. Removing all grants removes access and its menu entry for ordinary users; Administrators retain access automatically. Route editors display this non-editable administrator access explicitly.

Granting Access control or Navigation delegates trusted administrative capabilities. Delegated non-administrator access managers cannot create, modify, or reset administrator accounts. Accounts are deactivated rather than deleted; the current administrator cannot deactivate or demote themselves. User/group editors replace only the memberships shown by that editor, not unrelated users' memberships.

Changes take effect on subsequent requests, including existing sessions. Permission decisions are not held in a long-lived cache. See the [PHP authorization contracts](php-classes.md#frontend-authorization-and-navigation) before extending a frontend boundary.

Migration 028 removes stored deny rules and legacy global rules, establishes Everyone's minimal grants, and transfers existing administrators into the built-in groups. Other positive user/group grants are retained. Review any existing installation's intended grants after upgrading from the earlier precedence-based policy.

Migration 029 removes the old route flags. Previously disabled pages become pages without grants; previously hidden but permitted pages appear in the menu. Subsequent access changes are made only through grants.

Migration 030 removes redundant group 1 permission rows. Administrator access derives from active membership in group 1, not stored grant rows or supplied privilege flags. Removing membership revokes implicit permissions on subsequent checks, including existing sessions.

## Users, sharing, and tool authority

Conversations, projects, and personas are private, shared with one team, or organization-wide. Organization-wide means users of this installation, not internet publication. Team access requires current membership; normal navigation/sharing controls show a team only when the current user shares it with another active member. Administrators can still manage teams/memberships.

Only a message's author can edit that user message; editing removes later history, possibly including other users' messages. Assistant/tool messages are not editable. Thread owners control prompt/settings and visibility. Selecting a persona copies its name/prompt/tool/contract defaults; later changes to the source persona do not rewrite the thread. Owners manage private personas; administrators can manage default/shared personas under the existing policy.

Administrators constrain frontend tool availability, then users explicitly select permitted tools. Shared project collaborators can plan/start/pause under project access rules; ownership controls settings/sharing/deletion. Local engineering grants are a separate Unix-bound authority, not a frontend bypass or sandbox.

Sidebar chat deletion sets a soft-delete timestamp and hides the conversation without erasing stored data. Direct PHP hard-delete methods and project/agent removal have different semantics. Review retention requirements before choosing an operation.

## Daily operation and recovery

Run Compose commands from the repository:

```sh
docker compose ps
docker compose logs --tail=100 app worker operations
docker compose stop
docker compose up -d
docker compose up -d --build
```

`stop` leaves containers and data in place. The PostgreSQL named volume holds durable application data; removing it erases the database. Back up that volume/database along with private configuration and any needed runtime files. A Git clone is not a data backup. Backups can contain credentials and conversations and need appropriate access protection.

The run worker handles queued chat work, the indexer handles background retrieval/knowledge work, operations handles deferred jobs such as discovery/titles/cleanup, the benchmark worker handles its durable evaluation queue, and the project scheduler coordinates readiness/tasks/review. A closed browser does not cancel accepted work. Investigate uncertain/interrupted runs rather than blindly replaying possible external side effects.

Projection recovery and attachment cleanup do not require handwritten model commands:

```sh
docker compose exec -T app ./bin/phill-state-rebuild
docker compose exec -T app ./bin/phill-attachments-gc
```

Rebuilding projections replays durable events, not model/tool side effects. See [runtime state](runtime.md#durable-workflow-and-response-state) and [project recovery](projects.md#plan-details-and-recovery) before changing state.

Use [CLI diagnostics](cli.md) and private logs for troubleshooting. Configure writable private storage/log destinations for each Unix identity. Verbose can expose sensitive conversation data even with credential redaction; do not leave traces enabled broadly.

## HTTP exposure and embedding

Loopback-only HTTP is the default. LAN exposure does not supply TLS; prefer an SSH tunnel or suitable HTTPS reverse proxy/authentication/network controls. The bundled PHP server is a local/development server, not a production web-server recipe.

Use `public/` as the document root and route **all** requests, including assets, through `public/index.php`. This validates asset paths/types; serving arbitrary repository files directly bypasses that boundary. Classes, configuration, templates, dependencies, and storage must remain outside the document root. `APP_BASE_PATH` supplies a URL prefix when mounting under an application route.

Onboarding can independently map users, groups/memberships, and permissions or create the missing tables, with read-only/writable mapping choices and standalone/ERP session modes. These hooks are not evidence that an arbitrary external ERP integration is validated. See [requirements coverage](../REQUIREMENTS.md#what-has-been-validated) and the [integration roadmap](../ROADMAP.md#integration-formats-and-deployment-coverage).
