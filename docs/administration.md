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

Use **Settings → Models** for model/profile administration. Profiles bind a model, endpoint, and parameters. Saving an enabled profile tests its actual capabilities; disabled discovered profiles remain inspectable and usable by trusted code. Arbitrary frontend clients may not substitute their own execution configuration for an enabled reference.

```sh
# Substitute your endpoint; host.docker.internal refers to the Docker host.
docker compose exec -T app ./bin/phill-scan --url http://host.docker.internal:8000 --json
docker compose exec -T app ./bin/phill-models --profiles
docker compose exec -T app ./bin/phill-models --test default --json
docker compose exec -T app ./bin/phill-models --help
```

`phill-scan` accepts `--cidr`, `--ports`, `--no-save`, and `-a` for all TCP ports in the selected IPv4 ranges. Default ports/ranges come from configuration. Discovery reads metadata without generating text or loading/unloading models; verification performs inference probes. Scan only systems you have authority to inspect.

`phill-models --save` accepts inline JSON, a file, or `-` for stdin. A payload contains a name/configuration and optional enabling/overrides. `--test` probes without changing the saved profile. Use private stdin/files for credentials, not shared shell history. Profile resolution is database-first, then optional private JSON; see [PHP configuration](php-classes.md#profiles-and-personas).

Configure tool services and per-file-type parser profiles under **Settings → Services**. Compose supplies SearXNG and Camoufox. Custom database service URLs remain authoritative unless explicitly configured to use runtime environment URLs. Inside a container, `localhost` is that container, not the host or browser service.

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

The run worker handles queued chat work, the indexer handles background retrieval/knowledge work, operations handles deferred jobs such as discovery/titles/cleanup, and the project scheduler coordinates readiness/tasks/review. A closed browser does not cancel accepted work. Investigate uncertain/interrupted runs rather than blindly replaying possible external side effects.

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
