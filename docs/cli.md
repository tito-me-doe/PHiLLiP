# Command-line guide

`bin/ok-phill` is an executable PHP script using the same classes as the web UI. Use it directly with native PHP/Composer dependencies, optionally add the repository's `bin` directory to your PATH, or run it inside Docker. See [requirements](../REQUIREMENTS.md) for native service/process prerequisites.

## Ask a question

The following examples use the installed Compose environment and an unprivileged container account:

```sh
docker compose exec -T --user www-data app ./bin/ok-phill 'Explain this PHP error.'
printf '%s\n' 'Text to summarize' | docker compose exec -T --user www-data app ./bin/ok-phill 'Summarize this input.'
docker compose exec -T --user www-data app ./bin/ok-phill -t SearchWeb,Fetch 'Research the current PHP release using official sources.'
docker compose exec -T --user www-data app ./bin/ok-phill --help
```

Only the final answer goes to stdout; diagnostics and progress go to stderr. A plain prompt uses the `default` profile and grants zero tools. Piped input becomes the prompt, or is appended as data to an explicit prompt. No prompt/input produces a short server-health greeting, with a factual fallback if the model is unavailable. Health checks describe reachability from that process, not a complete audit of the host.

`www-data` is not automatically a frontend user or administrator. The invoking identity needs readable configuration and private writable log/runtime paths; set `LOG_FILE` appropriately if its default path is not writable. For local filesystem work, choose an actual unprivileged operator identity with the intended workspace permissions, not a more privileged process merely to avoid filesystem errors.

## Options

| Option | Purpose |
| --- | --- |
| `-p`, `--profile` | Profile name, ID, or inline JSON configuration |
| `--persona` | Visible saved persona name or ID; copies its prompt, tools, and contract |
| `-T`, `--thread-id` | `new` to save a conversation, or an owned thread ID to continue |
| `-t`, `--tool` | Comma-separated tool names; `none` disables persona defaults |
| `-c`, `--contract` | JSON object or JSON-file path; `{}` disables a default contract |
| `-s`, `--session-id` | Existing authenticated PHP session for controlled backend handoffs |
| `--debug`, `--verbose` | Diagnostic checkpoints or detailed traces; stdout stays reserved for results |
| `-h`, `--help` | Usage information |
| `--` | End options; also escape a prompt beginning with a reserved subcommand |

Native examples, after configuring native dependencies:

```sh
./bin/ok-phill -p default 'Explain this design.'
./bin/ok-phill -c '{"answer":{"type":"string"}}' 'Return a short JSON answer.'
./bin/ok-phill --persona 'PHP developer' -T new 'Help investigate this issue.'
./bin/ok-phill -- agents are useful for which tasks?
```

Choose a persona visible to your identity. Tool values are callable/class names, never evaluated closures or PHP expressions. Registering a tool does not guarantee its service/capability is configured.

## Saved conversations

```sh
docker compose exec -T --user www-data app ./bin/ok-phill -T new 'Help me plan a migration.'
# Replace 123 with the thread ID printed on stderr.
docker compose exec -T --user www-data app ./bin/ok-phill -T 123 'What should I verify first?'
```

Continue under the same Unix/host/container/application identity. The ID is stderr result metadata even when diagnostic thresholds are disabled. A continuation reuses its saved profile unless `-p` overrides it. Copied persona tools/contracts persist; explicit `-t`/`-c` override them for one invocation. Without a persona, select tools on every invocation. Busy CLI threads fail before enqueueing instead of running twice; browser submissions use sequential queues.

Keep `APP_HOSTNAME` stable when recreating containers with saved local workers. Native and container identities are separate even when accessing the same database.

## Management commands

```sh
./bin/ok-phill profiles help
./bin/ok-phill personas help
./bin/ok-phill agents help
./bin/ok-phill workflows help
./bin/ok-phill projects help
```

- `profiles` inspects database/JSON profiles and deliberately saves/tests global configuration under operator restrictions.
- `personas` manages prompts, named tool defaults, and response contracts within the caller's audience.
- `agents` creates local durable workers, queues assignments, supplies between-round feedback, grants access, and exposes results/events. Different workers run concurrently; each queue is sequential.
- `workflows` lists/runs engineering coordinators. Installing the shipped catalog is a separate operator-controlled step; see the [engineering guide](engineering-team.md).
- `projects` plans nested ToDos, requests readiness, explicitly starts work, and inspects decisions/progress. See [Projects](projects.md).

Management commands emit JSON results, not a final natural-language answer. A queued handle is not completion. Inspect actual tool evidence and run tests rather than relying on a model's claims. `agents remove` and project deletion are destructive, unlike sidebar chat hiding; read their help before use.

`./bin/phill --once` renders a read-only project snapshot. Interactive terminal mode supports refresh and project selection, not conversational chat or task mutations yet.

## Identity, authority, and exit status

Identity comes from the effective Unix UID/passwd entry, not `USER`, `LOGNAME`, or `SUDO_USER`. Root/unidentified UIDs cannot supply tools or sessions. Mapped active users can access authorized private/team/organization memories; unmapped accounts have a restricted audience including organization-published knowledge and current-thread knowledge. A tool/persona selection does not widen that scope.

Session-backed calls retain frontend authentication and tool policy. Session IDs are bearer secrets: avoid shell history, logs, and exposed process arguments; use only controlled handoffs. Workspace file/command approvals are explicit, per-thread, and not an OS sandbox. See [requirements and caveats](../REQUIREMENTS.md#operational-and-security-caveats).

Ordinary `ok-phill` exits `0` for a result/help/health report, `1` for execution/authentication failure, `2` for invalid usage, `130` for Ctrl+C, and `143` for termination. Interrupted requests do not print partial answers. Agent/project management also uses `3` for pending/timeouts where documented. Consult subcommand help rather than treating every nonzero value as the same outcome.
