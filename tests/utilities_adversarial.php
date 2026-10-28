<?php
require dirname(__DIR__) . '/bootstrap.php';

use Agent\Log;
use Agent\Utilities;

// No database, network, native PHP diagnostics, or secret-bearing error messages are needed.
Log::configure(['user' => Log::OFF, 'admin' => Log::OFF, 'console' => Log::OFF, 'file' => Log::OFF, 'database' => Log::OFF]);
$diagnostics = [];
$failures = [];
function check($condition, $message)
{
    if (!$condition) $GLOBALS['failures'][] = $message;
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $message . "\n";
}
set_error_handler(function ($severity, $message) use (&$diagnostics) { if (error_reporting() & $severity) $diagnostics[] = $message; return true; });

function cleaned($input, $contract, &$issues = null)
{
    $issues = null;
    return Utilities::cleanup($input, $contract, $issues);
}

function rejectedCleanup($input, $contract, $description)
{
    $result = cleaned($input, $contract, $issues);
    check(($result === $input || (is_float($input) && is_nan($input) && is_float($result) && is_nan($result))) && !empty($issues['errors']), $description);
    return $issues;
}

try {
    check(cleaned(' false ', 'boolean', $issues) === false && !$issues['errors'], 'False strings never become truthy grants');
    foreach (['true' => true, ' ON ' => true, '0' => false, 'off' => false] as $input => $expected) {
        check(cleaned((string)$input, 'boolean') === $expected, 'Explicit boolean value ' . (string)$input . ' is recognized');
    }
    foreach (['maybe', '', 2, -1, [], new stdClass()] as $input) rejectedCleanup($input, 'boolean', 'Ambiguous booleans are returned unchanged with errors');
    check(cleaned(' 007 ', 'integer') === 7, 'Whitespace and leading zeros are safe integer representations');
    foreach (['1.5', 1.5, '1e1000', '92233720368547758080', NAN, INF, -INF] as $input) {
        $result = cleaned($input, 'integer', $issues);
        check(!empty($issues['errors']) && (is_nan($input) ? is_nan($result) : $result === $input), 'Nonfinite, fractional, and overflowing integers are not silently truncated');
    }
    foreach ([NAN, INF, -INF, '1e1000'] as $input) {
        cleaned($input, 'number', $issues);
        check(!empty($issues['errors']), 'Nonfinite number conversions are rejected');
    }
    check(cleaned('12.25', 'number') === 12.25, 'Finite decimal text normalizes without loss');
    check(cleaned(' true ', ['type' => 'boolean', 'coerce' => false], $issues) === ' true ' && $issues['errors'], 'Strict types disable even recognized coercion');
    rejectedCleanup(false, 'string', 'Boolean text conversion requires explicit permission to guess');
    check(cleaned(false, ['type' => 'string', 'allow_guess' => true], $issues) === 'false' && $issues['warnings'], 'Permitted boolean-to-text guessing is explicit and warns');
    check(cleaned('  keep password \t ', 'string') === '  keep password \t ', 'Strings and passwords are not trimmed by default');
    check(cleaned(" \tMiXeD\n", ['type' => 'string', 'trim' => true, 'case' => 'lower']) === 'mixed', 'Explicit string cleanup is deterministic');
    check(cleaned('<script>x</script>', 'string') === '<script>x</script>', 'Cleanup does not apply output-context HTML escaping');
    rejectedCleanup('long', ['type' => 'string', 'maxLength' => 2], 'Length constraints reject instead of silently truncating content');
    rejectedCleanup(-1, ['type' => 'integer', 'minimum' => 0], 'Numeric bounds do not clamp invalid values');
    rejectedCleanup('admin', ['type' => 'string', 'enum' => ['user']], 'Enum constraints do not invent a replacement');
    rejectedCleanup('value', ['type' => 'string', 'pattern' => '/[broken/'], 'Invalid PCRE is a controlled contract error');
    rejectedCleanup('value', ['type' => 'string', 'pattern' => '/^ok$/'], 'Pattern mismatch keeps the supplied input');

    $contract = ['type' => 'object', 'properties' => ['enabled' => 'boolean', 'count' => 'integer', 'password' => 'string'], 'required' => ['count']];
    $raw = ['enabled' => 'false', 'count' => 'not-count', 'password' => '  secret  '];
    $result = cleaned($raw, $contract, $issues);
    check($result === ['enabled' => false, 'count' => 'not-count', 'password' => '  secret  '] && $issues['errors'], 'A failed field preserves already-cleaned siblings and untouched passwords');
    $result = cleaned(['enabled' => 'yes'], $contract, $issues);
    check($result === ['enabled' => true] && $issues['errors'], 'Missing required fields preserve valid partial progress without invented keys');
    check(cleaned((object)['enabled' => 'false', 'count' => '2'], $contract) === ['enabled' => false, 'count' => 2], 'Decoded objects normalize through nested field contracts');
    check(cleaned('{"enabled":"false","count":"2"}', $contract + ['decode_json' => true]) === ['enabled' => false, 'count' => 2], 'Object contracts explicitly consume recognized JSON objects');
    check(cleaned('"literal"', 'string') === '"literal"', 'JSON-looking plain strings remain strings unless decoding is requested');
    check(cleaned('"literal"', ['type' => 'string', 'decode_json' => true]) === '"literal"', 'JSON decoding never rewrites scalar JSON-looking content');
    rejectedCleanup('{"broken":', $contract, 'Malformed JSON object input fails without native diagnostics');
    rejectedCleanup('[1,2]', $contract, 'JSON lists do not silently become object contracts');
    rejectedCleanup('null', $contract, 'JSON null does not silently become an empty object');
    rejectedCleanup(['a', 'b'], $contract, 'Positional arrays cannot silently become named objects');
    check(cleaned([], ['type' => 'object']) === [], 'An empty PHP array remains a supported empty named object');
    rejectedCleanup('{}', ['type' => 'object', 'decode_json' => true], 'Empty JSON objects remain untouched unless explicitly allowed');
    rejectedCleanup('[]', ['type' => 'array', 'decode_json' => true], 'Empty JSON lists remain untouched unless explicitly allowed');
    check(cleaned('{}', ['type' => 'object', 'decode_json' => true, 'decode_empty' => true], $issues) === [] && !$issues['errors'], 'An explicit empty JSON object remains a valid object with opt-in');
    check(cleaned('[]', ['type' => 'array', 'decode_json' => true, 'decode_empty' => true], $issues) === [] && !$issues['errors'], 'An explicit empty JSON list remains a valid list with opt-in');
    rejectedCleanup('[]', ['type' => 'object', 'decode_json' => true, 'decode_empty' => true], 'An empty JSON list cannot masquerade as an empty JSON object');

    $defaults = ['type' => 'object', 'properties' => ['explicit' => ['type' => 'string', 'nullable' => true, 'default' => 'fallback'], 'missing' => ['type' => 'integer', 'default' => '2']]];
    check(cleaned(['explicit' => null], $defaults) === ['explicit' => null, 'missing' => 2], 'Defaults apply to absence, never replace explicit nullable null');
    rejectedCleanup(null, 'string', 'Nonnullable null remains null with an error');
    check(cleaned(null, ['type' => 'string', 'nullable' => true]) === null, 'Explicit nullability permits null');
    $unknown = ['type' => 'object', 'properties' => ['known' => 'integer']];
    check(cleaned(['known' => '2', 'extra' => ' untouched '], $unknown) === ['known' => 2, 'extra' => ' untouched '], 'Unknown fields are preserved by default');
    check(cleaned(['known' => '2', 'extra' => '3'], $unknown + ['additionalProperties' => 'discard']) === ['known' => 2], 'Explicit discard objects omit unknown fields');
    $result = cleaned(['known' => '2', 'extra' => '3'], $unknown + ['additionalProperties' => false], $issues);
    check($result === ['known' => 2, 'extra' => '3'] && $issues['errors'], 'Closed objects reject unknown fields without silently removing data');
    check(cleaned(['known' => '2', 'extra' => '3'], $unknown + ['additionalProperties' => 'integer']) === ['known' => 2, 'extra' => 3], 'Additional fields can share an explicit conversion contract');
    $alias = $unknown + ['aliases' => ['old' => 'known']];
    check(cleaned(['old' => '2'], $alias) === ['known' => 2], 'Aliases converge on canonical keys');
    $result = cleaned(['old' => '2', 'known' => '3'], $alias, $issues);
    check($issues['errors'] && isset($result['old']) && isset($result['known']), 'Conflicting alias and canonical values are not silently overwritten');
    rejectedCleanup(['a' => 1], ['type' => 'object', 'aliases' => ['a' => 'b', 'b' => 'a']], 'Cyclic alias contracts fail instead of depending on declaration order');
    rejectedCleanup(['a' => 1], ['type' => 'object', 'aliases' => ['a' => 'b', 'b' => 'c']], 'Chained aliases must explicitly converge on one canonical field');
    check(cleaned(['old' => '2'], $unknown + ['aliases' => ['old' => 'known', 'known' => 'known']]) === ['known' => 2], 'Canonical self-aliases remain harmless identity mappings');

    $list = ['type' => 'array', 'items' => ['type' => 'integer']];
    check(cleaned(['1', '2', '3'], $list) === [1, 2, 3], 'List items normalize in place');
    check(cleaned(['1', 'bad', '3'], $list, $issues) === [1, 'bad', 3] && $issues['errors'], 'Failed list items preserve neighboring conversions and order');
    rejectedCleanup(['label' => '1'], $list, 'Named arrays do not silently discard keys to become lists');
    check(cleaned('3, 1,2,1', $list + ['separator' => ',', 'unique' => true, 'sort' => 'asc']) === [1, 2, 3], 'Explicit CSV, uniqueness, and ordering compose after item normalization');
    $result = cleaned('3', $list + ['wrap_scalar' => true], $issues);
    check($result === [3] && !empty($issues['warnings']), 'Explicit scalar wrapping reports a guessed list shape');
    rejectedCleanup('1,2', $list, 'CSV is not inferred without an explicit separator');
    check(cleaned([['1'], ['2']], ['type' => 'array', 'items' => $list]) === [[1], [2]], 'Nested list contracts preserve hierarchy');
    rejectedCleanup([1, 2], $list + ['maxItems' => 1], 'Item bounds do not silently drop data');

    foreach ([null, 7, false, 'not-a-type', ['type' => null], ['type' => 'integer', 'unknown_contract_key' => true], ['type' => 'nope'], ['type' => 'array', 'sort' => 'sideways']] as $invalid) {
        rejectedCleanup('input', $invalid, 'Malformed contracts fail without throwing');
    }
    foreach ([['type' => 'integer', 'minimum' => 'not-number'], ['type' => 'string', 'minLength' => -1], ['type' => 'object', 'properties' => 'invalid'], ['type' => 'object', 'aliases' => ['old' => ['invalid']]], ['type' => 'array', 'separator' => 'too-long']] as $invalid) {
        rejectedCleanup('input', $invalid, 'Malformed contract values are rejected before input processing');
    }
    foreach (['case', 'sort', 'max_depth', 'enum', 'required', 'aliases'] as $key) rejectedCleanup('input', ['type' => 'any', $key => null], 'Explicit null cannot disable a typed contract keyword: ' . $key);
    check(cleaned(' 9 ', '{"type":"integer"}') === 9, 'JSON contract objects share the same engine');
    check(cleaned(' 9 ', (object)['type' => 'integer']) === 9, 'Decoded contract objects share the same engine');
    check(cleaned(false, ['type' => ['null', 'boolean']]) === false, 'Union contracts preserve matching types without truthiness');
    check(cleaned(null, ['type' => ['null', 'string']]) === null, 'Union contracts explicitly permit null');

    $executions = 0;
    $closure = function () use (&$executions) { $executions++; return 'untrusted callback'; };
    check(cleaned($closure, 'any') === $closure && $executions === 0, 'Any contracts retain closures without executing them');
    rejectedCleanup($closure, 'string', 'Typed conversion does not execute closures');
    $object = new class implements JsonSerializable {
        public $calls = 0;
        #[\ReturnTypeWillChange]
        public function jsonSerialize() { $this->calls++; throw new RuntimeException('NO_SERIALIZATION'); }
        public function __toString() { $this->calls++; return 'SECRET_OBJECT_VALUE'; }
    };
    check(cleaned($object, 'any') === $object && !$object->calls, 'Arbitrary domain objects are retained without invoking magic or serializers');
    rejectedCleanup($object, 'string', 'Object coercion does not invoke untrusted string conversion');
    check(!$object->calls, 'Error reporting also avoids invoking serializers and magic methods');
    $resource = fopen('php://memory', 'r+');
    check(cleaned($resource, 'any') === $resource, 'Any contracts preserve resources without consuming them');
    fclose($resource);
    $serialized = 'O:8:"stdClass":1:{s:4:"test";s:5:"value";}';
    check(cleaned($serialized, 'any') === $serialized, 'Serialized PHP text is never unserialized');
    check(cleaned((object)['nested' => (object)['enabled' => false]], 'any') === ['nested' => ['enabled' => false]], 'Any contracts normalize ordinary decoded containers deeply');
    foreach ([NAN, INF, $closure, "\xff"] as $input) rejectedCleanup($input, 'json', 'JSON-safe contracts reject nonportable values without serializing them');
    check(cleaned(['number' => 1, 'bool' => false, 'null' => null], 'json') === ['number' => 1, 'bool' => false, 'null' => null], 'JSON-safe contracts retain native primitive types');

    $cycle = new stdClass(); $cycle->self = $cycle;
    cleaned($cycle, 'any', $issues);
    check($issues['errors'], 'Cyclic decoded objects are bounded and reported');
    $recursive = []; $recursive['self'] = &$recursive;
    cleaned($recursive, 'any', $issues);
    check($issues['errors'], 'Recursive arrays are bounded and reported');
    $anotherRecursive = []; $anotherRecursive['self'] = &$anotherRecursive;
    cleaned($recursive, ['type' => 'any', 'enum' => [$anotherRecursive]], $issues);
    check($issues['errors'], 'Enum equality never fatally compares independent recursive arrays');
    cleaned(['old' => $recursive, 'known' => $anotherRecursive], $alias, $issues);
    check($issues['errors'], 'Alias equality never fatally compares independent recursive arrays');
    cleaned([$recursive, $anotherRecursive], ['type' => 'array', 'unique' => true], $issues);
    check($issues['errors'], 'Uniqueness checks on recursive values remain bounded');
    $prefix = array_fill(0, 20, 0); $expensiveUnique = [];
    for ($i = 0; $i < 80; $i++) { $item = $prefix; $item[19] = $i; $expensiveUnique[] = $item; }
    check(count(cleaned($expensiveUnique, ['type' => 'array', 'unique' => true], $issues)) === 80 && $issues['errors'], 'A shared comparison budget bounds prefix-heavy unique normalization without dropping values');
    $recursiveContract = ['type' => 'object']; $recursiveContract['properties']['self'] = &$recursiveContract;
    cleaned(['self' => []], $recursiveContract, $issues);
    check($issues['errors'], 'Recursive contracts cannot create unbounded processing');
    $deep = 'leaf'; for ($i = 0; $i < 80; $i++) $deep = ['child' => $deep];
    cleaned($deep, 'any', $issues);
    check($issues['errors'], 'Deep input is bounded even without a detailed object contract');
    cleaned(array_fill(0, 30000, 'item'), 'any', $issues);
    check($issues['errors'], 'Wide input has a bounded processing budget');
    cleaned(str_repeat('x', 1048577), 'string', $issues);
    check($issues['errors'], 'Oversized strings are bounded before expensive transformations');
    $large = str_repeat('x', 1048577);
    check(cleaned($large, ['type' => 'string', 'max_bytes' => 2097152], $issues) === $large && !$issues['errors'], 'Explicit byte-limit overrides support intentionally large payloads');
    rejectedCleanup('1234', ['type' => 'string', 'max_bytes' => 3], 'Smaller byte budgets preserve rejected inputs without truncation');
    foreach ([0, -1, 67108865, '10', null] as $limit) rejectedCleanup('input', ['type' => 'any', 'max_bytes' => $limit], 'Invalid resource budget cannot weaken a contract');

    Agent\Runtime::bindIdentity(['id' => 'utilities-adversarial', 'is_admin' => true]);
    Log::with(['console' => Log::VERBOSE], function () {
        Utilities::cleanup('UNIQUE_RAW_SECRET_CLEANUP_VALUE', 'integer');
        Utilities::cleanup('UNIQUE_RAW_SECRET_GUESS_VALUE', ['type' => 'array', 'wrap_scalar' => true]);
    });
    $messages = Log::browserMessages();
    $json = json_encode($messages);
    check(strpos($json, 'UNIQUE_RAW_SECRET_CLEANUP_VALUE') === false && strpos($json, 'UNIQUE_RAW_SECRET_GUESS_VALUE') === false, 'Error and warning logs never include raw input values');
    $priorities = array_column($messages, 'priority');
    check(in_array(Log::ERROR, $priorities, true) && in_array(Log::WARNING, $priorities, true), 'Failures use writeError and permitted guesses use writeWarning');
    if ($diagnostics) foreach ($diagnostics as $diagnostic) echo 'DIAGNOSTIC: ' . $diagnostic . "\n";
    check($diagnostics === [], 'Malformed and hostile inputs emit no native PHP warnings, notices, or deprecations');
} finally {
    restore_error_handler();
    Agent\Runtime::bindIdentity(null);
}
if ($failures) throw new RuntimeException(count($failures) . ' utilities adversarial checks failed');
