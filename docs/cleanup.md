# Best-effort input cleanup

`Agent\Utilities::cleanup($input, $contract)` returns the best available value. It never throws or returns an exception. Known conversions are quiet; explicitly permitted guesses use `Log::writeWarning()`. Invalid input or contracts use `Log::writeError()` and retain the affected value in its current condition. Successful sibling conversions are not rolled back.

Cleanup is not an application gatekeeper. Callers check the requirements of their own operation and decide whether to continue, fall back, or reject the result. It does not authenticate users, hydrate database records, read files, execute callbacks, deserialize PHP, or escape output.

```php
use Agent\Utilities;

$enabled = Utilities::cleanup(' false ', 'boolean'); // false, not PHP's truthy string.
$input = Utilities::cleanup($input, [
    'type' => 'object',
    'decode_json' => true,
    'properties' => [
        'name' => ['type' => 'string', 'trim' => true],
        'enabled' => 'boolean',
        'limit' => ['type' => 'integer', 'minimum' => 1, 'default' => 20],
    ],
    'required' => ['name'],
]);

if (!is_array($input) || !isset($input['name']) || !is_string($input['name']) || $input['name'] === '') {
    // This operation needs a name: ask for it, use a fallback, or reject here.
}
```

For example, `['enabled'=>'false', 'limit'=>'unknown']` becomes `['enabled'=>false, 'limit'=>'unknown']`, with an error for `$.limit`. The caller must not assume that logging an error made the result valid.

## Contracts

A contract can be a type string, named PHP array, decoded `stdClass`, or JSON contract object. Supported types are `string`, `boolean`, `integer`, `number`, `array`, `object`, `null`, `any`, and `json`. A type list permits unions, preferring an already matching native representation over coercion.

| Keys | Meaning |
| --- | --- |
| `coerce`, `allow_guess` | Safe coercion defaults to enabled. Less certain conversions require `allow_guess: true` and warn. `coerce: false` requires native types. |
| `trim`, `case` | Opt-in string cleanup; case is `lower` or `upper`. Content/passwords are unchanged by default. |
| `decode_json`, `decode_empty` | Object/list contracts recognize matching JSON containers when coercion is enabled and no native type already matches. `decode_json: false` disables this; `true` explicitly requests decoding. Decode into a temporary array, check it, then swap and unset the temporary. Only non-empty containers are adopted by default. `decode_empty: true` explicitly permits empty containers. Malformed JSON, scalar JSON, and the wrong container kind leave the original string intact. |
| `properties`, `required`, `default` | Named field contracts, presence requirements, and defaults for absent fields. Explicit null is not absence. A root default cannot replace an explicitly supplied invalid value. |
| `aliases` | Alias-to-canonical field map. Conflicts, chains, and cycles are errors, not arbitrary overwrite decisions. |
| `additionalProperties` | Unknown fields are preserved by default. `false` reports errors but retains them; `discard` explicitly removes them. A child contract cleans unknown values. |
| `items`, `separator`, `wrap_scalar` | Sequential-list item contracts, explicit CSV decoding, or explicit scalar-to-list guessing. Wrapping warns. Named arrays do not silently lose their keys. |
| `unique`, `sort` | Opt-in deduplication and homogeneous scalar sorting (`asc`/`desc`). Ordinary list order is preserved. |
| `enum`, `nullable` | Strict allowed values and explicit nullability. |
| `minimum`, `maximum`, `minLength`, `maxLength`, `minItems`, `maxItems` | Validate bounds without silently clamping, truncating, or dropping input. |
| `pattern` | A complete delimited PCRE, such as `/^[a-z]+$/D`. Invalid expressions are controlled errors. |
| `max_depth`, `max_bytes` | Explicit safety budgets. Depth can only lower the 32-level ceiling. Root byte budgets default to 1 MiB per string and may be raised to at most 64 MiB. |

Unknown keywords and malformed contracts are errors; they do not silently weaken the contract. This is a normalization-oriented subset, not a complete JSON Schema implementation. Existing `Agent\Schema` remains responsible for model-output schema validation.

Object output is a named PHP array. Empty PHP arrays are valid empty maps; JSON `[]` does not become an object. Numeric-keyed objects cannot satisfy a named-map contract. `any` recursively normalizes ordinary decoded containers while retaining custom objects, closures, and resources without invoking them. `json` additionally requires portable JSON-compatible values while preserving literal strings, including serialized tool arguments.

## Diagnostics and boundaries

Ordinary callers do not need diagnostics. A caller that needs them can supply a third variable by reference:

```php
$value = Utilities::cleanup($input, $contract, $issues);
// $issues = ['warnings' => [['path' => '$.field', 'message' => '...']], 'errors' => [...]];
```

Logs contain paths and fixed explanations, not raw input values. Diagnostic counts, recursion, node counts, comparisons, and input sizes are bounded. A separate 64 MiB total byte-processing ceiling prevents repeated shared strings from causing unbounded transformed copies; parsing and subsequent field processing both count. No arbitrary serializers or magic conversion methods are called.

`Config::boolean()`, `Config::object()`, configuration container normalization, `Schema::input()`, and message protocol normalization share this engine. Their existing requirement checks still apply. Message protocols explicitly allow larger native image payloads; empty settings/schema maps explicitly permit empty JSON objects. Domain-specific database lookup, identity, authorization, and attachment-reference behavior remain in their owning classes.

## Tests

```sh
docker compose exec -T app php tests/utilities.php
docker compose exec -T app php tests/utilities_adversarial.php
docker compose exec -T app php tests/cleanup_integrations.php
```
