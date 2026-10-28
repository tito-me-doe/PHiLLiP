# Runtime reference

This guide covers context-aware logging, attachment representation/lifecycle, and durable workflow state. For application-facing class contracts and examples, use the [PHP class guide](php-classes.md). Installation/operations belong in the [administration guide](administration.md); prerequisites and caveats are in [REQUIREMENTS.md](../REQUIREMENTS.md).

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

## Testing

See [CONTRIBUTING.md](../CONTRIBUTING.md#test-a-focused-change) for suite selection and prerequisites. Runtime changes should exercise diagnostics across CLI/HTTP contexts, attachment fallbacks, durable state/idempotency, and repeated frontend follow-ups as applicable.
