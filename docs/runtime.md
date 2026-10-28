# Runtime reference

Start with the [README](../README.md) for installation and everyday use, the [engineering guide](engineering-team.md) for durable CLI agents, or the [project guide](projects.md) for recursive ToDos.

## Initialization and input contracts

Every executable, worker, HTTP entry point, and PHP integration uses `bootstrap.php`. Composer's `vendor/autoload.php` invokes the same initializer. Initialization is idempotent and lazy: loading classes alone does not open a database connection or session. Composer resolves classes when installed, with a project-class fallback when vendor dependencies are absent; features requiring those dependencies still need them installed.

Public interfaces normalize recognized input shapes before entering stricter internals. Callers should pass the data they already have rather than each implementing their own conversions:

```php
$request = new \Agent\Request($registry->profile('default')); // A complete profile row
$request->addMessage('user', 'Hello');                       // Either role/content order
$request->addMessages($thread->messages());                  // Stored message rows

$user = \Agent\Identity::user($userRow);                     // ID, username, row/object, reference JSON
$user = \Agent\Identity::authenticate($credentials);        // Payload or username/password arguments
$thread = new \Agent\Thread($threadRow);                    // ID, row/object, JSON row, or Thread
$files = \Agent\Attachments::normalize($attachmentIds);     // ID/row, list, CSV, or JSON
```

Hydration is not authentication or authorization. Identity, thread, persona, and attachment references reload database state. Frontend profile selection resolves an administrator-enabled profile reference, not supplied execution configuration. Thread titles are not unique lookup keys; ambiguous visible persona names require an ID. Direct application calls remain trusted.

`Message::normalize()` returns an array with `role` and `content`, preserving additional raw metadata. It accepts text, message arrays/decoded objects, recognized message JSON, database rows, and native multimodal content parts. `Message::many()` accepts one message or a list. With two arguments, exactly one must be a recognized role; use an explicit payload for ambiguous text such as the literal word `assistant`. Ordinary JSON that is not a message stays text. Model projection excludes application metadata.

`Config::profile()` extracts configuration from a profile reference or row. `Config::resolve()` also applies inheritance and private credentials. Profiles resolve database-first, then from optional private JSON. A minimal database-free configuration file is:

```json
{
  "profiles": {
    "default": {
      "url": "http://127.0.0.1:8000/v1/chat/completions",
      "model": "your-model-id",
      "temperature": 0.65
    }
  }
}
```

Save it as `config/profiles.local.json`, substitute your server URL/model ID, or set `PROFILE_JSON` to a private file. Inline arrays/JSON also work. Database-dependent features still require PostgreSQL.

`Profiles::save()` accepts a named payload or positional arguments. Profile configuration, capability overrides, service maps, and attachment-parser maps accept their JSON textarea forms. `Thread::options()` is the creation-options contract shared by application and frontend callers.

`Config::boolean()` recognizes explicit form/JSON booleans, including `false`, `off`, `no`, and `0`. Ambiguous values throw before a setting or permission changes. Numeric IDs and ports are validated, not stripped or truncated into different identifiers. Password bytes and message content are not trimmed or HTML-escaped; output escaping belongs to the destination sink.

`Utilities::cleanup($input, $contract)` performs best-effort normalization. It logs permitted guesses as warnings and failures as errors, returning the current, possibly partially cleaned input without throwing. Callers check their own requirements. JSON decoding adopts a temporary result only when it is a usable nonempty array, unless the contract permits empty arrays. See [cleanup contracts](cleanup.md).

Reflected tools use shared normalization for boolean, integer, and float arguments, including variadics. Ambiguous booleans, fractional integer IDs, overflow, and nonfinite numbers return tool feedback without executing the callable.

`Database::row($sql, $params)`, `rows()`, and `execute()` reuse up to 32 prepared statements per PDO connection. They return one associative row or `false`, all rows, or an affected-row count. Calls execute against current transaction state and release cursors; query results and permissions are not cached.

## Requests, personas, and tools

```php
$request = new \Agent\Request([
    'preset' => 'default',
    'persona' => 'Reviewer', // A saved ID/name or trusted inline definition
]);
$request->addMessage('Review this design.');
$answer = $request->send();
```

A persona contains a name, freeform system prompt, optional named tools, and optional response contract, with owner/visibility fields. It does not select a model. Selection copies its defaults into conversation settings; editing or deleting the original does not rewrite conversations. Explicit request settings can override defaults. Avatars are separate user preferences.

`registerTool()` makes a callable available; registering a class reflects its public static methods. CLI registration accepts names, not executable expressions. Frontend adapters and CLI contexts impose their authority boundaries; registration is not itself permission approval.

`registerContract()` accepts an array, decoded object, JSON, or a schema file. Self-contained schemas and local `#` references are supported; external file/network references are rejected. Tool-free requests can use native structured output. With tools enabled, generation remains unconstrained for tool calls, followed by local final-answer validation and bounded correction.

`max_rounds` defaults to 10 and bounds agent/model rounds separately from transport retries and contract corrections. `send($maxRounds)` overrides that limit. Exhausted output-token limits preserve partial output in request details and report an error rather than spending correction attempts on truncated JSON. `$request->response` contains execution details; `send()` returns the final answer only.

## Logging and terminal output

```php
use Agent\Log;

Log::writeError($anything, $anotherThing);
Log::writeDebug('Profile %s took %.2f seconds', $name, $elapsed);
Log::writeVerbose($request, $response, $arbitraryArray);
Log::write(-1, 'A warning');

Log::with(['console' => Log::VERBOSE, 'file' => Log::DEBUG], function () {
    // Focused thresholds are restored even if this section throws.
});
```

| Priority | Name |
| --- | --- |
| `-4` | Verbose |
| `-3` | Debug |
| `-2` | Info |
| `-1` | Warning |
| `0` | Success |
| `1` | Error |
| `2` | Fatal |

Each priority has a `writeName(...$values)` proxy. Arrays, objects, exceptions, resources, and recursive values are normalized safely. Recognized sprintf patterns are formatted; extra arguments are retained and malformed patterns fall back to readable values. Common credential keys are redacted. Failed serialization or unavailable sinks must not replace the application's outcome. Logging a Fatal message does not itself terminate execution.

`Log::configure()` and scoped `Log::with()` independently control `user`, `admin`, `console`, `file`, and `database` minimum priorities. `3` disables a sink. Corresponding `LOG_MIN_*` variables are listed in `.env.example`. The database sink uses `logs.raw` and is disabled by default. File logs are private JSON lines under `storage`, separated by process UID/day, or an explicit `LOG_FILE`/`path`.

CLI diagnostics default to stderr; `ok-phill` reserves stdout for results. Other callers can select `cli_stream => 'split'`. `Terminal` handles TTY detection, `NO_COLOR`, `FORCE_COLOR`, styles, dimensions, and transient progress output.

CLI `--debug` and `--verbose` flags use the shared initializer. They override output and enabled-log thresholds for that invocation; disabled file/database sinks stay disabled. Verbose wins when both are present. Use `--` before a literal prompt containing those flag words.

`Log::trace()` and browser `PHiLLiPLog.trace` use privileged-only Verbose delivery. Exception traces omit positional arguments; structured credentials and credential-bearing URLs are redacted. Raw traces can still contain sensitive application or conversation data: enable them only in trusted consoles and private logs.

The initializer maps PHP warnings to Warning and notices/deprecations to Debug, respecting error masks. Uncaught exceptions and shutdown fatals report through the logger. Native diagnostic output is disabled to avoid duplicates or broken structured responses. CLI failures exit nonzero without putting diagnostics on stdout. Buffered HTTP failures produce a generic, reference-linked error in the expected format. Handlers cannot repair bytes already sent, process kills, or errors before initialization.

HTTP delivery detects context, resolves session credentials lazily, and sends audience-filtered `X-PHiLLiP-Messages` envelopes without changing JSON/fragment bodies. Error/Fatal messages can set HTTP status unless a more specific error exists; a scope with `http_status => false` disables that behavior.

PHP renders the page messages container and a safe footer payload for later messages. The browser counterpart, `PHiLLiPLog` (also `PHiLLiP.Log`), provides the same named methods, safe text rendering, and severity styles. Server diagnostics reach browser consoles only for administrators or configured `APP_DEVELOPER_IDS`; ordinary users do not receive hidden debug data. Client messages never POST back to the server. `minPriority` controls visible messages, `minLogPriority` controls console output, and scoped overrides use `PHiLLiPLog.with({visible: -1, console: -4}, callback)`.

Success notices expire after three seconds and informational notices after five; warnings/errors remain dismissible. Background-run messages also enter the durable event feed, with audience filtering before transmission. A queued mutation is acknowledged as accepted, not reported as completed.

## Attachments

Chat accepts text/code, images, PDFs, DOCX, and ODT. Original bytes and immutable metadata are stored in PostgreSQL; message `raw` holds references. Downloads obey current thread visibility. Attachment storage requires PostgreSQL even for otherwise ephemeral requests.

```php
$file = \Agent\Attachments::store('erp-user-id', 'invoice.pdf', file_get_contents('/path/to/invoice.pdf'));
$request = new \Agent\Request('default');
$request->addMessage(['content' => 'Explain this invoice.', 'attachments' => [$file]]);
$answer = $request->send();
```

Native image/PDF input is preferred when supported. Otherwise, a configured parser profile runs before local conversion. Vision models can receive PDF page images; text-only paths use Poppler and English Tesseract OCR. DOCX/ODT use bounded PHP ZIP/XML parsing. Text/code is never executed. Rejected native input or insufficient context falls back to text when possible; unsupported files produce an actionable error.

Settings → Services → Attachment parsing maps MIME types, `.extensions`, or wildcards to profile names, in that lookup order. Configuration is database-first, then optional `config/attachment-parsers.local.json` or `ATTACHMENT_PARSER_JSON`. For example: `{"image/*":"local-vision",".pdf":"document-parser"}`. Parser profiles need not be enabled in the chat dropdown. Converted/model-derived representations are cached. The indexer extracts attachment text where possible and reports degraded indexing when it cannot.

Current limits are 10 MiB per file (`ATTACHMENT_MAX_BYTES`), eight files per message, combined size twice the per-file limit, and 40 megapixels for native images. Increasing upload limits also requires adjusting `docker/php.ini` and rebuilding. PDF image/OCR conversion covers the first eight pages with a partial-results notice; native PDF input and ordinary text extraction do not share that cap. Conversion commands are allowlisted and time/output bounded.

Tool-capable models receive current-conversation file listing/range-reading tools. PHP renders saved attachment chips and messages; browser drafts survive composer replacement and remain conversation-scoped. Unattached uploads expire after 24 hours, while linked originals remain until their last message reference is deleted. The operations worker collects expired uploads; manual cleanup is `docker compose exec -T app ./bin/phill-attachments-gc`.

## Durable workflow and response state

`Agent\State` stores resource-scoped JSON objects with revisions, atomic patches, optimistic concurrency, and parent/child relationships. Thread/run state follows foreign-key deletion when history is replaced. Projects and durable agents also have their own domain state and scheduling services.

```php
$state = \Agent\State::run($runId);
$task = $state->child('agent', 'unique-task-id', ['task' => 'Research this question']);
$snapshot = $task->read();
$task->patch(['progress' => 'Checking sources'], $snapshot['revision']);
// A stale revision throws Agent\StateConflict; reread and reconcile before retrying.
```

`runs` is authoritative for lifecycle and stop status. Event recording, live-response projections, and message persistence are transactional. `WorkflowState::snapshot()` and the event feed expose consistent state without requiring clients to reconstruct text from every delta. Missing projections recover from durable events. Profile authentication headers use private references in persisted settings; execution and indexing resolve them through the same configuration contract.

Recovery can rebuild projections without invoking a model or tool:

```sh
docker compose exec -T app ./bin/phill-state-rebuild       # Incomplete projections
docker compose exec -T app ./bin/phill-state-rebuild 123   # A specific run
```

Frontend message POSTs require a stable `submission_key`. Retrying the same user/thread/key/payload returns the original run; different content with the same key returns HTTP 409. Thread creation also accepts `creation_key`. Database uniqueness and per-thread locking permit only one active run per thread. This is not an exactly-once guarantee for external tool side effects after crashes: uncertain runs stop for investigation rather than automatically replaying.

UI preferences and panel state stay in browser-local storage, not PostgreSQL. Small cookies mirror appearance preferences for immediate PHP rendering. Unacknowledged submission keys survive browser reloads locally; API callers must retain their own keys across retries.

## Focused tests

Use the shared runner with the services needed by each selected suite:

```sh
docker compose exec -T app php tests/run.php logging runtime runtime_cli_options
docker compose exec -T app php tests/run.php messages row_contracts input_contracts utilities
docker compose exec -T app php tests/run.php attachments attachment_media attachment_fallbacks
docker compose exec -T app php tests/run.php submissions state views threads worker
```

Browser suites additionally require Python Playwright, Chromium, and their documented account/model fixtures. `tests/ui_followups.py` checks repeated turns, Stop, queued messages, tool activity, editing, fragment replacement, and duplicate/mixed-stream detection. `tests/onboarding.php --browser` combines isolated onboarding fixtures with browser checks.
