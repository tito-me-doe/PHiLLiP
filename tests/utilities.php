<?php
require __DIR__ . '/helpers.php';

use Agent\Log;
use Agent\Utilities;

Log::configure(['user' => Log::OFF, 'admin' => Log::OFF, 'console' => Log::OFF, 'file' => Log::OFF, 'database' => Log::OFF]);

$input = ['name' => ' Ada ', 'enabled' => 'off', 'count' => ' 02 ', 'tags' => 'PHP, agents,PHP', 'password' => ' secret '];
$contract = ['type' => 'object', 'properties' => [
    'name' => ['type' => 'string', 'trim' => true, 'minLength' => 1],
    'enabled' => 'boolean', 'count' => ['type' => 'integer', 'minimum' => 1],
    'tags' => ['type' => 'array', 'separator' => ',', 'items' => ['type' => 'string', 'trim' => true], 'unique' => true, 'sort' => 'asc'],
    'password' => 'string', 'missing' => ['type' => 'string', 'default' => 'default']
], 'required' => ['name', 'count']];
$value = Utilities::cleanup($input, $contract, $issues);
check($value === ['name' => 'Ada', 'enabled' => false, 'count' => 2, 'tags' => ['PHP', 'agents'], 'password' => ' secret ', 'missing' => 'default'] && !$issues['errors'], 'A shared contract composes explicit normalization without changing passwords');
check($input['name'] === ' Ada ' && $input['count'] === ' 02 ', 'Normalization does not mutate caller arrays');
check(Utilities::cleanup('32', 'integer') === 32, 'Optional diagnostics are not required by ordinary callers');
check(Utilities::cleanup('{"count":"2"}', ['type'=>'object','properties'=>['count'=>'integer']]) === ['count'=>2], 'A container contract recognizes useful JSON without caller-side decoding');
check(Utilities::cleanup('["2","1"]', ['type'=>'array','items'=>'integer']) === [2,1], 'A list contract recognizes nonempty JSON lists');
check(Utilities::cleanup('{"count":2}', ['type'=>['string','object']]) === '{"count":2}', 'Native string alternatives prevent speculative JSON decoding');
check(Utilities::cleanup('{"count":2}', ['type'=>'object','decode_json'=>false], $issues) === '{"count":2}' && $issues['errors'], 'Explicit decoding opt-out preserves JSON text');
check(Utilities::cleanup('{"count":2}', ['type'=>'object','coerce'=>false], $issues) === '{"count":2}' && $issues['errors'], 'Strict native container contracts do not automatically decode text');
check(Utilities::cleanup('0012', ['type' => ['string', 'integer']]) === '0012', 'Unions preserve a native shape rather than eagerly coercing it');
check(Utilities::cleanup(' 0012 ', ['type' => 'integer', 'coerce' => false], $issues) === ' 0012 ' && $issues['errors'], 'Strict contracts never silently convert numeric strings');
check(Utilities::cleanup((string)PHP_INT_MAX, 'integer', $issues) === PHP_INT_MAX && !$issues['errors'], 'Maximum native integers retain exact precision');
check(Utilities::cleanup((string)PHP_INT_MIN, 'integer', $issues) === PHP_INT_MIN && !$issues['errors'], 'Minimum native integers retain exact precision');
$hex = '0' . 'x20';
check(Utilities::cleanup($hex, 'integer', $issues) === $hex && $issues['errors'], 'Integer conversion does not interpret hex or octal formats');
check(Utilities::cleanup('1e3', 'number') === 1000.0, 'Number contracts explicitly support finite scientific notation');
check(Utilities::cleanup(['b', 'a', 'b'], ['type' => 'array', 'unique' => true, 'sort' => 'desc']) === ['b', 'a'], 'Explicit descending sort and uniqueness preserve homogeneous string types');
check(Utilities::cleanup([PHP_INT_MAX, PHP_INT_MAX - 1], ['type' => 'array', 'sort' => 'asc']) === [PHP_INT_MAX - 1, PHP_INT_MAX], 'Ascending sort compares adjacent large integers without float conversion');
check(Utilities::cleanup([PHP_INT_MAX - 1, PHP_INT_MAX], ['type' => 'array', 'sort' => 'desc']) === [PHP_INT_MAX, PHP_INT_MAX - 1], 'Descending sort compares adjacent large integers without float conversion');
$mixed = ['2', 1];
check(Utilities::cleanup($mixed, ['type' => 'array', 'sort' => 'asc'], $issues) === $mixed && $issues['errors'], 'Mixed-type sorting is refused instead of applying PHP loose comparison');
check(Utilities::cleanup('"one,two",three', ['type' => 'array', 'separator' => ',']) === ['one,two', 'three'], 'Explicit CSV accepts quoted delimiters');
check(Utilities::cleanup('é', ['type' => 'string', 'minLength' => 1, 'maxLength' => 1], $issues) === 'é' && !$issues['errors'], 'String length counts Unicode characters when supported');
check(Utilities::cleanup(' token ', ['type' => 'string', 'trim' => true, 'case' => 'upper', 'pattern' => '/^[A-Z]+$/D']) === 'TOKEN', 'Patterns apply to the explicitly cleaned string');

foreach (['type', 'case', 'sort', 'separator', 'max_depth', 'max_bytes', 'enum', 'required', 'aliases'] as $key) {
    $schema = ['type' => 'any']; $schema[$key] = null;
    $value = Utilities::cleanup('original', $schema, $issues);
    check($value === 'original' && $issues['errors'], 'Explicit null does not disable the contract keyword ' . $key);
}
check(Utilities::cleanup('[]', ['type' => 'object', 'decode_json' => true], $issues) === '[]' && $issues['errors'], 'An unusable JSON list is not destructively substituted for the input');
check(Utilities::cleanup('{}', ['type' => 'object', 'decode_json' => true], $issues) === '{}' && $issues['errors'], 'Empty JSON containers require an explicit domain opt-in');
check(Utilities::cleanup('{}', ['type' => 'object', 'decode_json' => true, 'decode_empty' => true], $issues) === [] && !$issues['errors'], 'An explicitly allowed empty JSON object canonicalizes to an empty named array');
check(Utilities::cleanup([], 'object', $issues) === [] && !$issues['errors'], 'An existing empty PHP configuration array remains supported');

$large = str_repeat('a', 1048577);
check(Utilities::cleanup($large, 'json', $issues) === $large && $issues['errors'], 'Default byte limits also protect protocol strings');
$protocol = ['content' => $large];
check(Utilities::cleanup($protocol, ['type' => 'json', 'max_bytes' => 2097152], $issues) === $protocol && !$issues['errors'], 'Specialized protocol budgets are inherited by nested attachment data');
foreach ([0, -1, '100', 67108865, INF] as $bytes) {
    check(Utilities::cleanup('original', ['type' => 'any', 'max_bytes' => $bytes], $issues) === 'original' && $issues['errors'], 'Byte-budget overrides cannot disable hard safety limits');
}
check(Utilities::cleanup(['outer' => ['inner' => true]], ['type' => 'json', 'max_depth' => 1], $issues) === ['outer' => ['inner' => true]] && $issues['errors'], 'Explicit depth limits retain the best available partial input');
$many = array_fill(0, 100, 'invalid');
check(Utilities::cleanup($many, ['type' => 'array', 'items' => 'integer'], $issues) === $many && count($issues['errors']) === 20, 'Repeated failures have bounded diagnostics and logging volume');

$invalid = (object)['0' => 'value'];
check(Utilities::cleanup($invalid, 'object', $issues) === $invalid && $issues['errors'], 'Object-shaped numeric keys cannot bypass named-array contracts');
check(Utilities::cleanup(['field' => NAN], 'json', $issues)['field'] !== 0 && $issues['errors'], 'JSON normalization never invents a finite replacement for NAN');

$shared = str_repeat('a', 4194304);
$repeated = array_fill(0, 17, $shared);
$bounded = Utilities::cleanup($repeated, ['type'=>'array','max_bytes'=>4194304,'items'=>['type'=>'string','case'=>'upper']], $issues);
check($issues['errors'] && $bounded[0][0] === 'A' && $bounded[16] === $shared, 'A total byte budget bounds transformed copies of repeated large strings and retains partial progress');
unset($shared, $repeated, $bounded);

echo "Utilities contract composition and resource-bound tests completed.\n";
