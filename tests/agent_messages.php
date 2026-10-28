<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/fixtures/cli_process.php';

/** No HTTP transport: record the payloads around a genuine Request tool batch. */
class AgentMessageRequestFixture extends Agent\Request
{
    public $payloads = [];
    protected function complete(array $payload)
    {
        $this->payloads[] = $payload;
        if (count($this->payloads) === 1) {
            $name = $payload['tools'][0]['function']['name'];
            $calls = [];
            foreach (['first', 'second'] as $id) $calls[] = ['id' => $id, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => '{}']];
            $message = ['role' => 'assistant', 'content' => null, 'tool_calls' => $calls];
        } else $message = ['role' => 'assistant', 'content' => 'feedback received'];
        return ['json' => ['choices' => [['message' => $message, 'finish_reason' => isset($message['tool_calls']) ? 'tool_calls' : 'stop']]]];
    }
}

$marker = 'agent-messages-' . bin2hex(random_bytes(5));
$config = ['url' => 'http://127.0.0.1:1/unused', 'model' => 'no-provider', 'compaction_enabled' => false];
$agents = [];
$create = function ($name) use ($marker, $config, &$agents) {
    $agent = Agent\Agents::create($marker . '-' . $name, ['profile' => $config, 'tools' => [], 'team' => $marker]);
    $agents[] = $agent;
    return $agent;
};
try {
    $agent = $create('parent');
    $other = $create('other');
    $thread = new Agent\Thread($agent['thread_id']);
    expectException(function () use ($agent) { Agent\Agents::message($agent['id'], 'No current task'); }, 'no queued or running');
    $run = $thread->enqueue($agent['owner_id'], 'Fixed assignment');
    $first = Agent\Agents::message($agent['id'], 'Initial feedback');
    check($first['run_id'] === (int)$run['id'] && strlen($first['message_id']) === 32, 'Notes have durable IDs and target the existing queued assignment');
    $claimed = Agent\Run::claim($thread->id);
    $queued = $thread->enqueue($agent['owner_id'], 'Later assignment');
    check(Agent\Agents::message($agent['id'], 'Current feedback')['run_id'] === (int)$run['id'], 'Running assignment receives feedback before a queued successor');
    $hooks = 0; $calls = 0; $request = null;
    $request = new AgentMessageRequestFixture(array_replace($config, ['before_round' => function ($current) use ($run, &$hooks) {
        $hooks++;
        Agent\Agents::deliverMessages($run['id'], $current);
    }]));
    $request->registerTool(/** Observe a tool batch without contacting a provider. */ function () use ($agent, &$calls, &$request) {
        $calls++;
        if ($calls === 1) Agent\Agents::message($agent['id'], 'Feedback during first tool');
        $notes = array_filter($request->request['body']['messages'], function ($message) { return isset($message['_agent_note_id']); });
        check(count($notes) === 2, 'Notes arriving during a batch wait until every tool finishes');
        return 'verified';
    });
    $request->addMessage('Fixed assignment');
    check($request->send(2) === 'feedback received' && $hooks === 2 && $calls === 2, 'Round hook delivers notes before each payload without interrupting a tool batch');
    check(!isset($request->payloads[0]['before_round']) && count($request->payloads[1]['messages']) === 7, 'Internal callback stays out of the provider payload and next round contains feedback');
    check(Agent\Agents::deliverMessages($run['id'], $request) === 0, 'Acknowledged notes are never delivered twice');
    $saved = Agent\Database::rows("SELECT raw FROM messages WHERE run_id = ? AND raw->>'_agent_note_id' IS NOT NULL ORDER BY id", [$run['id']]);
    check(count($saved) === 3 && Agent\State::run($run['id'])->read()['value']['pending_notes'] === [], 'Delivery and acknowledgment persist exactly once in run history');
    check(count(Agent\Database::rows('SELECT id FROM runs WHERE thread_id = ?', [$thread->id])) === 2, 'Feedback does not create another assignment');
    foreach (['', str_repeat('a', 65537), "bad\0text", "\xff"] as $bad) expectException(function () use ($agent, $bad) { Agent\Agents::message($agent['id'], $bad); }, 'nonempty UTF-8');
    for ($i = 0; $i < 16; $i++) Agent\Agents::message($agent['id'], 'Bounded note');
    expectException(function () use ($agent) { Agent\Agents::message($agent['id'], 'Excess note'); }, '16 notes');
    Agent\WorkflowState::rebuild($run['id']);
    check(count(Agent\State::run($run['id'])->read()['value']['pending_notes']) === 16, 'Projection rebuild preserves undelivered messages');
    Agent\Agents::deliverMessages($run['id'], $request);
    Agent\Agents::message($agent['id'], str_repeat('a', 65536));
    expectException(function () use ($agent) { Agent\Agents::message($agent['id'], 'Excess bytes'); }, '64 KiB');
    Agent\Agents::deliverMessages($run['id'], $request);
    $cli = cliProcess(['agents', 'message', (string)$agent['id'], 'Operator text'], 'Piped feedback');
    $sent = json_decode($cli['out'], true);
    $pending = Agent\State::run($run['id'])->read()['value']['pending_notes'];
    check($cli['status'] === 0 && $sent['run_id'] === (int)$run['id'] && strpos($pending[0]['text'], 'Operator text') !== false && strpos($pending[0]['text'], 'Piped feedback') !== false, 'CLI message appends stdin and returns machine-readable delivery identity');
    Agent\Agents::deliverMessages($run['id'], $request);
    Agent\ToolContext::withValues(['frontend' => true], function () use ($agent) {
        expectException(function () use ($agent) { Agent\Agents::message($agent['id'], 'Forbidden'); }, 'local CLI');
    });
    Agent\ToolContext::withValues(['thread_id' => $thread->id, 'run_id' => $run['id'], 'agent_id' => $agent['id']], function () use ($other, $agent, $config, $marker, &$agents) {
        expectException(function () use ($other) { Agent\Agents::message($other['id'], 'Forbidden sibling'); }, 'not owned');
        expectException(function () use ($agent) { Agent\Agents::message($agent['id'], 'Change my assignment'); }, 'not owned');
        $child = Agent\Agents::create($marker . '-child', ['profile' => $config, 'tools' => []]); $agents[] = $child;
        $childRun = (new Agent\Thread($child['thread_id']))->enqueue($child['owner_id'], 'Child task');
        check(Agent\Agents::message($child['id'], 'Parent feedback')['run_id'] === (int)$childRun['id'], 'Model parent may send notes to its owned child');
    });
    $parsed = Agent\AgentCli::parse(['message', $agent['name'], 'Use', 'this feedback']);
    check($parsed['action'] === 'message' && count($parsed['words']) === 3, 'Agent CLI parses message text without making an assignment');
    expectException(function () { Agent\AgentCli::parse(['message']); }, 'agent name or ID');
    expectException(function () use ($config) { new Agent\Request(array_replace($config, ['before_round' => 'not-a-real-hook'])); }, 'must be callable');
} finally {
    foreach (array_reverse($agents) as $agent) Agent\Database::execute('DELETE FROM threads WHERE id = ? AND owner_id = ?', [$agent['thread_id'], $agent['owner_id']]);
}
