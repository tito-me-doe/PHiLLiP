# Using PHiLLiP classes from PHP

PHiLLiP's PHP classes are the common implementation behind the frontend, CLI, workers, and agents. Use them directly rather than invoking the CLI or building an internal HTTP client to reach functionality already available in-process.

This guide documents current usage, not every public implementation method. Requirements and compatibility limits are in [REQUIREMENTS.md](../REQUIREMENTS.md); logging, attachment conversion, and event/state mechanics are in the [runtime reference](runtime.md).

## Initialize once

```php
require '/path/to/PHiLLiP/bootstrap.php';
```

Composer's `vendor/autoload.php` invokes that same initializer; choose the entry point appropriate to your application, not a second custom initialization path. Bootstrap is idempotent and class loading is lazy. It loads environment/runtime behavior and installs shared PHP diagnostic handlers, so review that behavior when embedding in an existing application.

Application code is trusted. A class reference or a supplied user ID does not authenticate an end user. Exposing these calls through your own controllers requires your own authenticated context and the appropriate authorization adapters. Some classes deliberately retain narrower boundaries—local engineering agents require a valid Unix operator, persona mutations enforce their access rules, and workspace grants cannot be self-approved.

## Choose the right class

| Class | Expected use |
| --- | --- |
| `Request` | Synchronous ephemeral model/tool loop, with optional output validation and events |
| `Message` | Normalize text, role/content, raw payloads, stored rows, and multimodal parts |
| `Config`, `Profiles`, `Registry` | Resolve configuration, save/test profiles, inspect discovered endpoints/models |
| `Personas` | Resolve a named prompt plus optional tools/contract and copy defaults |
| `ToolRegistry` | Reflect named callables and execute tool calls; normally owned by `Request` |
| `Thread`, `Run` | Persist messages, queue work, inspect/control a durable run |
| `Project`, `ProjectWorkflow` | Plan nested ToDos and enforce readiness/revision/decision gates |
| `Agents`, `Engineering` | Local durable engineering workers and shipped workflow coordination |
| `Attachments`, `AttachmentParser`, `Converters` | Store references and choose native/model/local file representations |
| `History`, `Vectors`, `Knowledge` | Retrieve conversations and source-backed facts/relationships |
| `Identity`, `Auth`, `Access`, `ProjectAccess` | Resolve users, establish frontend identity, and enforce audience/action policy |
| `Database`, `State`, `WorkflowState` | SQL access, revisioned resource state, durable response projections |
| `Events`, `Log`, `Terminal` | Shared progress, context-aware diagnostics, and terminal presentation |
| `Utilities`, `Schema` | Best-effort input cleanup and explicit response-schema validation |

All classes above are under `Agent\`. Model-facing tools live under `Agent\Tools\`; for example, `Agent\Tools\Memory` is distinct from the underlying `Knowledge` service. There is no current generic `Graphs`, `Redis`, or `Responses` class: see the [roadmap](../ROADMAP.md#broader-php-service-interfaces).

## Send an ephemeral request

With a configured database or JSON profile:

```php
$request = new \Agent\Request('default');
$request->addMessage('Explain the trade-offs of this design.');
$answer = $request->send();
$details = $request->response;
```

`send()` returns the final answer string, not a provider response envelope. Rounds, events, generated messages, and any validated contract output remain in `$request->response`. Transport failures, exhausted rounds/correction attempts, and truncated output can throw; handle those according to your application's needs.

To avoid profile/database requirements for a simple text request, pass inline configuration:

```php
$request = new \Agent\Request([
    'url' => 'http://127.0.0.1:8000/v1/chat/completions',
    'model' => 'your-model-id',
    'temperature' => 0.65,
    'stream' => false,
    'max_rounds' => 10,
]);
$request->addMessages([
    ['role' => 'system', 'content' => 'You are a helpful PHP assistant.'],
    ['role' => 'user', 'content' => 'Explain prepared statements.'],
]);
$answer = $request->send();
```

Replace the URL/model with your endpoint. Model parameters must be supported by that engine; accepting configuration is not proof every provider understands it. `max_rounds` is a harness limit, not an engine parameter. Transport retries and contract corrections have their own limits. `send($maxRounds)` overrides the round limit, not the transport retry count.

For an ephemeral multi-turn conversation, reuse the request:

```php
$request->addMessage('Show a small example based on that explanation.');
$followUp = $request->send();
```

The request retains its messages, including assistant/tool messages; do not append the previous answer again. Each `send()` refreshes the execution-details response for that invocation. This does not save a thread to the database.

For streamed provider output, configure `stream => true` and an `on_event` callback on the request. The callback receives event arrays with `type` and `data`; consume those through your own transport adapter. Streaming does not automatically echo output or create an HTTP/SSE response.

### Normalize messages at the shared boundary

```php
$message = \Agent\Message::normalize('Hello');
$message = \Agent\Message::normalize('user', 'Hello');
$message = \Agent\Message::normalize('Hello', 'user');
$message = \Agent\Message::normalize(['role' => 'user', 'content' => 'Hello']);
$messages = \Agent\Message::many([$message]);
```

Recognized inputs include row-shaped arrays/objects, recognized message JSON, and native content parts. Arbitrary JSON that is not a message remains text. With two arguments, exactly one must be a recognized role; use an explicit payload if content itself is a role word such as `assistant`. `addMessage()`/`addMessages()` apply these contracts already, including for `$thread->messages()` rows, so callers need not decode/extract `raw` themselves.

## Register tools and a response contract

Before calling `send()`:

```php
$request->registerTool('Agent\\Tools\\SearchWeb');
$request->registerContract([
    'answer' => ['type' => 'string'],
    'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
]);
$request->addMessage('Research this question and return the required JSON.');
$answer = $request->send();
$validated = $request->response['validated'];
```

This shorthand makes the named properties required and rejects additional properties. A complete JSON Schema is also accepted, as an array, decoded object, JSON object string, or readable schema file. External `$ref` retrieval is forbidden; local `#` references are supported. Tool-free requests can use native structured output; tool-enabled requests use local validation and bounded feedback/correction so an output grammar does not prevent tool calls.

Registering a class exposes its public static methods, derived from signatures and docblocks. A custom pure tool can be as small as:

```php
class TextTools
{
    /**
     * Count whitespace-separated words in supplied text.
     * @param string $text Text to inspect.
     * @return int Number of words.
     */
    public static function wordCount(string $text): int
    {
        return count(preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY));
    }
}

$request->registerTool(TextTools::class);
```

Keep unrelated helpers private or instance-based so registration does not expose them accidentally. Tool arguments receive shared typed normalization; ambiguity produces tool feedback rather than executing a lossy cast. Normal results become text/JSON; explicit tool messages can preserve validated native content parts. Do not give an untrusted agent arbitrary callable registration or privileged application objects.

## Profiles and personas

`Config::profile()` accepts a name, ID, row/decoded object, or inline configuration/JSON. `Config::resolve()` additionally resolves inherited presets and private credentials. Database profiles take precedence over optional private `config/profiles.local.json`; missing configuration produces an actionable exception. A row is a supported input, not a reason for the caller to decode its `config` column.

For database-free profile lookup, the optional private JSON file has this shape (substitute your URL/model):

```json
{
  "profiles": {
    "default": {
      "url": "http://127.0.0.1:8000/v1/chat/completions",
      "model": "your-model-id"
    }
  }
}
```

`PROFILE_JSON` can select a different private file. This removes the database requirement for profile lookup and ephemeral text requests, not for persisted application features.

```php
$registry = new \Agent\Registry();
$profiles = $registry->profiles();
$enabled = $registry->profiles(true);
$row = $registry->profile('default');
$request = new \Agent\Request($row);
```

Use `Profiles::test($config)` for inference probes without a saved-state change, and `Profiles::save($payload)` for deliberate global configuration changes. A save payload can contain `name`, `config`, `enabled`, and capability `overrides`; enabling verifies before frontend selection. Trusted code can explicitly use disabled profiles. Never treat user-supplied inline configuration as equivalent to an administrator-enabled frontend profile.

A persona supplies name, one freeform prompt, optional tools, and optional contract—not a model choice:

```php
$request = new \Agent\Request([
    'preset' => 'default',
    'persona' => [
        'name' => 'Reviewer',
        'prompt' => 'Review the supplied design. Distinguish evidence from assumptions.',
        'tools' => [],
    ],
]);
$request->addMessage('Review this proposed interface.');
$answer = $request->send();
```

Trusted code can also supply a saved persona ID or unambiguous name. Use `Personas::resolve($reference, $userId)` when selecting within a user's audience; `Personas::get()` resolves an ID without that audience selection. `Personas::settings()` copies defaults, with explicit overrides winning, including empty tools or a null contract. An explicit system/developer message takes precedence over the default prompt. Source persona edits do not rewrite copied conversation settings.

## Persist a conversation and queue execution

Use `Thread`, not a second persistence wrapper around `Request`, when work needs durable history, idempotency, progress, or background execution. These examples assume `$ownerId` is an existing application identity you have already authenticated/authorized.

```php
$thread = \Agent\Thread::create($ownerId, [
    'title' => 'Design review',
    'settings' => ['profile' => 'default', 'tools' => []],
    'visibility' => 'private',
]);

$run = $thread->enqueue($ownerId, [
    'content' => 'Review these requirements.',
    'submission_key' => 'design-review-message-001',
]);

$runId = $run['id'];
$status = \Agent\Run::get($runId);
$state = \Agent\WorkflowState::snapshot($thread->id);
```

Enqueueing acknowledges work; it does not wait for completion. The run worker claims and executes queued work. The snapshot may still be queued/running. Read `$thread->messages()` after completion for persisted messages, and use the run/event state for progress. Do not separately execute an ordinary `Request` for that same queued prompt: that would bypass the queue and duplicate work.

Keep a submission key stable across retries of the same logical message; use a new key for a different message. Same key with changed payload is a conflict. Thread creation separately accepts `creation_key`. Neither mechanism guarantees exactly-once external side effects after crashes.

`new Thread($idOrRowOrObjectOrJsonOrThread)` loads an existing thread from authoritative database state; the constructor does not create one or resolve nonunique titles. `Thread::create()` is explicit. `configure()` changes future settings; `edit()` removes later history. `Thread::delete()` is a hard delete, unlike the frontend sidebar's soft deletion—do not substitute it for a “hide conversation” action.

`Run::claim()`/`execute()` are worker-level operations, not frontend submission APIs. `Run::requestStop($runId)` requests progressively stronger stop behavior; let the execution engine clean up before proceeding. Run/frontend adapters own lifecycle and permission decisions.

## Users, files, and retrieval

`Identity::user()` resolves an ID, username, or row/object reference. Supplied `is_admin` or membership fields are not authoritative. Lookup is not login. For standalone password authentication:

```php
$user = \Agent\Identity::authenticate([
    'username' => $username,
    'password' => $password,
]);
```

An application still needs its appropriate session/login adapter and access checks. `Access`/`ProjectAccess` are the authorization boundaries used by the frontend; `Auth` and mappings bridge standalone/ERP session contexts. See [administration](administration.md#users-sharing-and-tool-authority).

Store an attachment, then pass the returned reference without hand-building model content:

```php
$file = \Agent\Attachments::store($ownerId, 'invoice.pdf', file_get_contents('/path/to/invoice.pdf'));
$request = new \Agent\Request('default');
$request->addMessage(['content' => 'Explain this invoice.', 'attachments' => [$file]]);
$answer = $request->send();
```

Attachments require PostgreSQL even for ephemeral requests. `Attachments::normalize()` accepts IDs/rows, lists, CSV, or JSON references and reloads metadata. Native input, configured parser profiles, and local conversion are selected centrally; see [formats and limits](runtime.md#attachments). File paths in trusted application code are not safe upload authorization by themselves.

Use `History` for stored-message retrieval, `Vectors::embed(array $texts)` for a batch of text embeddings, and `Vectors::search($query, $threadId, $userId, $limit)` for indexed message search. `Knowledge::remember()` requires source messages; `recall()` and `graph()` retrieve facts and evidence-linked relationships. These are current conversation/knowledge APIs, not general vector collections or a generic graph engine. Pass an explicit permitted audience for user-facing retrieval: trusted null-audience calls can be unrestricted. Model-facing History/Memory tools derive their scope from the active tool context; do not forge that context from user input.

## Projects, local agents, and infrastructure

`Project::create($ownerId, $options)` creates a planning project; `new Project($idOrUniqueNameOrRow)` loads one. `addTodo()` accepts title, goal, requirements, context, gates, and optional nesting/persona/attempt settings. `plan()` queues planning; `requestPreflight()` requests readiness; neither starts work. `start($actorId)` requires explicit human action and a ready current revision. Do not automatically invoke Start after a model's “ready” response. See [Projects](projects.md) for the full lifecycle and tool ceiling.

`Agents` creates/configures local durable workers, assigns tasks, and retrieves events/results; `Engineering` supplies the catalog and workflow coordinators. These APIs retain Unix ownership, host identity, grants, and local-operator restrictions. They are not a way to give a web process unrestricted source access. Prefer the documented [CLI workflow](engineering-team.md) for operator-driven development rather than reinventing supervisors in PHP callers.

Use `Database::row()`/`rows()`/`execute()` with bound parameters for internal SQL; identifiers still require validation and are not parameter values. `State::run()`/`thread()` provide revisioned JSON state; pass an expected revision when updating data that may have concurrent writers. Handle `StateConflict` by rereading/reconciling, not blindly overwriting. `WorkflowState` exposes/rebuilds response projections; it does not replace authoritative run lifecycle state.

Use `Events::emit()` inside shared work for progress and `Log::writeError()`/`writeDebug()`/`writeVerbose()` for diagnostics. Request `on_event` callbacks can consume generation events in trusted PHP code; setting `stream => true` alone does not render an HTTP/SSE response. The frontend adapters own transport delivery. See [runtime logging and state](runtime.md).

`Utilities::cleanup($input, $contract)` returns best-effort normalized data without throwing, logs guesses/failures, and leaves requirement handling to the caller. `Schema` is for explicit response validation. Shared normalizers already call these where appropriate; avoid wrapping every call in redundant cleanup or assuming cleanup establishes authorization. See [cleanup contracts](cleanup.md).
