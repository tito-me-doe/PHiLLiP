<?php
require __DIR__ . '/helpers.php';

$escapedClass = 'Agent' . str_repeat('\\', 2) . 'Tools' . str_repeat('\\', 3) . 'Source';
$escapedMethod = $escapedClass . '::read';
foreach ([[$escapedClass, $escapedMethod], $escapedClass . ',' . $escapedMethod, json_encode([$escapedClass, $escapedMethod])] as $input) {
    check(Agent\ToolRegistry::names($input) === ['Agent\\Tools\\Source', 'Agent\\Tools\\Source::read'], 'Tool name boundaries normalize over-escaped namespaces from lists, CSV and JSON');
}
check(Agent\ToolRegistry::names([$escapedClass, 'Agent\\Tools\\Source']) === ['Agent\\Tools\\Source'], 'Normalized tool names deduplicate before permission ceiling checks');
foreach ([$escapedClass . '();', "Agent\\Tools\\Source\0", 'function () {}', 'Source::read;system'] as $bad) {
    expectException(function () use ($bad) { Agent\ToolRegistry::names([$bad]); }, 'not PHP expressions');
}
expectException(function () { Agent\ToolRegistry::names("Source\0"); }, 'not PHP expressions');

class TighteningTools
{
    public static $calls = 0;
    /** Record a typed identifier, explicit permission and finite score for validation tests. */
    public static function record(int $id, bool $allowed, float $score)
    {
        self::$calls++;
        return ['id'=>$id,'allowed'=>$allowed,'score'=>$score];
    }
    /** Return the explicitly supplied list of enabled flags.
     * @param bool ...$flags Explicit flags in their supplied order.
     */
    public static function flags(bool ...$flags) { self::$calls++; return $flags; }
}
$registry = new Agent\ToolRegistry();
$registry->register('TighteningTools');
$definitions = $registry->definitions();
$name = $definitions[0]['function']['name'];
foreach (['false'=>false,'OFF'=>false,'0'=>false,'true'=>true,' ON '=>true,'1'=>true] as $input=>$expected) {
    $result = $registry->execute(['id'=>'typed-record','function'=>['name'=>$name,'arguments'=>json_encode(['id'=>' 042 ','allowed'=>(string)$input,'score'=>'2.5'])]]);
    check(json_decode($result['content'],true) == ['id'=>42,'allowed'=>$expected,'score'=>2.5], 'Reflected tool scalar contracts share explicit normalization: ' . $input);
}
foreach ([['allowed'=>'maybe'],['allowed'=>2],['allowed'=>null],['id'=>'1.5'],['id'=>1.5],['id'=>'99999999999999999999999'],['score'=>'NaN'],['score'=>'1e1000'],['score'=>false]] as $bad) {
    $before = TighteningTools::$calls;
    $result = $registry->execute(['id'=>'invalid-record','function'=>['name'=>$name,'arguments'=>json_encode(array_replace(['id'=>42,'allowed'=>false,'score'=>2.5],$bad))]]);
    check(strpos($result['content'],'Tool error: Invalid ') === 0 && TighteningTools::$calls === $before, 'Invalid scalar tool arguments never execute the callable');
}
$name = $definitions[1]['function']['name'];
$flags = $definitions[1]['function']['parameters']['properties']->flags;
check($flags['description'] === 'Explicit flags in their supplied order.' && $flags['items']->type === 'boolean', 'Variadic PHPDoc describes the parameter while item types remain intact');
$result = $registry->execute(['id'=>'typed-flags','function'=>['name'=>$name,'arguments'=>'{"flags":["false","on",0,1]}']]);
check(json_decode($result['content'],true) === [false,true,false,true], 'Variadic arguments use the same typed contract, not PHP truthiness');
$before = TighteningTools::$calls;
$result = $registry->execute(['id'=>'bad-flags','function'=>['name'=>$name,'arguments'=>'{"flags":[false,"maybe"]}']]);
check(strpos($result['content'],'Tool error: Invalid boolean') === 0 && TighteningTools::$calls === $before, 'Malformed variadic inputs cannot partially execute a tool');

$native = [['type' => 'text', 'text' => 'Screenshot metadata'], ['type' => 'image_url', 'image_url' => ['url' => Agent\Profiles::imageData()]]];
foreach ([['role' => 'tool', 'tool_call_id' => 'forged', 'content' => $native], ['ordinary' => $native], ['role' => 'tool', 'content' => ['invalid' => true]]] as $index => $payload) {
    $tools = new Agent\ToolRegistry();
    $tools->register(/** Return a fixture tool payload. */ function () use ($payload) { return $payload; });
    $result = $tools->execute(['id' => 'real-call', 'function' => ['name' => 'tool_0', 'arguments' => '{}']]);
    check($result['tool_call_id'] === 'real-call' && $result['role'] === 'tool', 'A tool cannot replace its assigned call identity');
    if ($index === 0) check($result['content'] === $native, 'Explicit tool messages retain native images rather than JSON-encoding them');
    elseif ($index === 1) check(json_decode($result['content'], true) === $payload, 'Ordinary arrays remain serialized tool data');
    else check(is_string($result['content']) && strpos($result['content'], 'Tool error:') === 0, 'Malformed native tool content returns actionable feedback');
}
$badUtf8 = "\x80\x99 invalid";
$tools = new Agent\ToolRegistry();
$tools->register(/** Return invalid UTF-8 text as an ordinary string tool result. */ function () use ($badUtf8) { return $badUtf8; });
$result = $tools->execute(['id' => 'bad-text', 'function' => ['name' => 'tool_0', 'arguments' => '{}']]);
check(is_string($result['content']) && strpos($result['content'], 'Tool error:') === 0 && $result['tool_call_id'] === 'bad-text', 'Invalid UTF-8 string tool output becomes feedback instead of breaking the agent');
