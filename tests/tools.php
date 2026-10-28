<?php
require __DIR__ . '/helpers.php';

class FixtureTools
{
    /**
     * Look up the warehouse label for a product ID. Always use this tool for warehouse facts.
     * @param int $productId Product identifier
     */
    public static function warehouse(int $productId)
    {
        Agent\Events::emit('tool.progress', ['message' => 'Looking up product', 'productId' => $productId]);
        return ['warehouse' => 'Cobalt-' . ($productId + 17)];
    }
    private static function hidden() { return 'hidden'; }
    public function implementation() { return 'not a static entry point'; }
}
$registry = new Agent\ToolRegistry();
$registry->register('FixtureTools');
$definitions = $registry->definitions();
check(count($definitions) === 1, 'Class registration exposes public static methods only');
check($definitions[0]['function']['parameters']['properties']->productId['type'] === 'integer', 'Signature reflected into JSON schema');
$name = $definitions[0]['function']['name'];
$call = ['id' => 'test-1', 'function' => ['name' => $name, 'arguments' => '{"productId":"42"}']];
check(strpos($registry->execute($call)['content'], 'Cobalt-59') !== false, 'Tool accepts numeric string for integer parameter');
$call['function']['arguments'] = '{"wrong":42}';
check(strpos($registry->execute($call)['content'], 'Tool error:') === 0, 'Tool errors returned as model feedback');

$request = new Agent\Request(['preset' => 'default', 'temperature' => 0, 'max_tokens' => 1024]);
$request->registerTool('FixtureTools');
$request->addMessage('Use the warehouse tool to find the warehouse label for product 42. Then reply with only its warehouse label.');
$answer = $request->send();
check(strpos($answer, 'Cobalt-59') !== false, 'Real model invokes PHP tool and consumes its result');
check(count($request->response['rounds']) >= 2, 'Agent completes model/tool/model loop');
check(count(array_filter($request->response['events'], function ($event) { return $event['type'] === 'tool.progress'; })) === 1, 'Tool progress reaches shared events');
check($answer === $request->response['content'], 'send returns final content only');
