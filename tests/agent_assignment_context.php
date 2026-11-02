<?php
if (php_sapi_name() === 'cli-server') {
    // Fixture-engine router branch: a local stub provider that logs every request.
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($path === '/v1/chat/completions') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $logPath = getenv('ASSIGN_CTX_LOG');
        if ($logPath === false) $logPath = $GLOBALS['ASSIGN_CTX_LOG'] ?? null;
        if ($logPath) file_put_contents($logPath, json_encode($body) . "\n", FILE_APPEND | LOCK_EX);
        header('Content-Type: application/json');
        echo json_encode(['choices' => [['message' => ['role' => 'assistant', 'content' => 'fixture answer'], 'finish_reason' => 'stop']]]);
    } else {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'not found']);
    }
    exit;
}

require __DIR__ . '/helpers.php';
require __DIR__ . '/fixtures/WebFixture.php';

use Agent\Database;
use Agent\Identity;
use Agent\Installation;
use Agent\Profiles;
use Agent\Run;
use Agent\Thread;

$logFile = tempnam(sys_get_temp_dir(), 'assign_ctx_');
if ($logFile === false) throw new RuntimeException('Cannot create fixture log');
unlink($logFile);
$GLOBALS['ASSIGN_CTX_LOG'] = $logFile;
putenv('ASSIGN_CTX_LOG=' . $logFile);

function rotateLog()
{
    file_put_contents($GLOBALS['ASSIGN_CTX_LOG'], '');
}

function capturedInput(): array
{
    $lines = array_filter(explode("\n", (string)@file_get_contents($GLOBALS['ASSIGN_CTX_LOG'])));
    $line = end($lines);
    if ($line === false) throw new RuntimeException('No provider request was captured');
    $decoded = json_decode($line, true);
    if (!is_array($decoded)) throw new RuntimeException('Provider request log is not readable');
    return $decoded;
}

$fixture = new WebFixture();
try {
    $address = WebFixture::address();
    $base = $fixture->server(__FILE__, true);
    $profileUrl = rtrim($base, '/') . '/v1/chat/completions';
    Profiles::save('default', ['url' => $profileUrl, 'model' => 'assign-stub', 'stream' => false, 'max_retries' => 0], false);

    Installation::install(['auth' => 'standalone', 'mappings' => ['users' => ['table' => null]]], 'admin-ctx', 'assignment-admin-secret', 'Admin Context');
    $agent = Identity::createUser('agent-ctx', 'assignment-agent-secret', 'Assignment Agent');
    $agentId = (string)$agent['id'];

    $claimAsRunning = function (array $run) {
        Database::connection()->prepare("UPDATE runs SET status = 'running', started_at = NOW(), heartbeat_at = NOW() WHERE id = ?")->execute([$run['id']]);
    };

    $newThread = function () use ($agentId) {
        return Thread::create($agentId, 'History scope fixture', ['profile' => 'default', 'parameters' => [], 'tools' => []]);
    };

    $toolNames = function (array $captured): array {
        $names = [];
        foreach ($captured['tools'] ?? [] as $definition) {
            $names[] = isset($definition['function']['name']) ? $definition['function']['name'] : '';
        }
        return $names;
    };

    // A real durable worker sees only its own current grants and identity.
    $worker = Agent\Agents::create('assignment-context-' . bin2hex(random_bytes(4)), ['profile' => 'default', 'tools' => ['Agent\\Tools\\Workspace']]);
    $other = Agent\Agents::create('assignment-other-' . bin2hex(random_bytes(4)), ['profile' => 'default', 'tools' => ['Agent\\Tools\\Workspace']]);
    Agent\WorkspaceAccess::approve($worker['thread_id'], 'patch', 'tests/agent_assignment_context.php');
    Agent\WorkspaceAccess::approve($worker['thread_id'], 'command', ['php', '-l', 'tests/agent_assignment_context.php']);
    $workerContext = ['agent_id' => $worker['id'], 'thread_id' => $worker['thread_id'], 'run_id' => 1];
    $visible = Agent\ToolContext::withValues($workerContext, fn() => Agent\Tools\Workspace::context());
    check($visible['agent_id'] === (int)$worker['id'] && count($visible['grants']) === 2, 'Worker sees its own identity and current file and command grants');
    check(in_array(['kind' => 'patch', 'target' => 'tests/agent_assignment_context.php'], $visible['grants'], true), 'Exact file grant is visible');
    check(in_array(['kind' => 'command', 'target' => ['php', '-l', 'tests/agent_assignment_context.php']], $visible['grants'], true), 'Exact argv prefix is visible');
    expectException(fn() => Agent\ToolContext::withValues(['agent_id' => $worker['id'], 'thread_id' => $other['thread_id']], fn() => Agent\Tools\Workspace::context()), 'does not match');
    $otherVisible = Agent\ToolContext::withValues(['agent_id' => $other['id'], 'thread_id' => $other['thread_id']], fn() => Agent\Tools\Workspace::context());
    check($otherVisible['grants'] === [], 'Another worker does not inherit grants');
    Agent\WorkspaceAccess::revoke($worker['thread_id'], 'patch', 'tests/agent_assignment_context.php');
    $afterRevoke = Agent\ToolContext::withValues($workerContext, fn() => Agent\Tools\Workspace::context());
    check(count($afterRevoke['grants']) === 1 && $afterRevoke['grants'][0]['kind'] === 'command', 'Current context reflects revocation immediately');
    expectException(fn() => Agent\ToolContext::withValues($workerContext, fn() => Agent\WorkspaceAccess::requireGrant('patch', 'tests/agent_assignment_context.php')), 'Operator approval required');
    $workerThread = new Thread($worker['thread_id']);
    $workerRun = $workerThread->enqueue($agentId, 'Worker identity check');
    $claimAsRunning($workerRun);
    rotateLog();
    check(Run::execute($workerRun['id']) === 'fixture answer', 'Real worker completes with identity hint');
    $workerInput = capturedInput();
    $workerMessages = $workerInput['messages'];
    check(strpos($workerMessages[0]['content'], 'Your worker ID is ' . $worker['id']) !== false && strpos($workerMessages[0]['content'], 'Workspace.context') !== false, 'Worker receives its own ID and current-grant lookup instruction');
    check(in_array('Agent_Tools_Workspace_context', $toolNames($workerInput), true), 'Worker can invoke the current-grant lookup through the provider tool registry');

    // --- Durable CLI agent opts into assignment-scoped history --------------------------------
    $thread = $newThread();
    try {
        // Durable conversation turns from previous runs remain stored but leave context.
        $previous = [
            ['user' => 'Alpha one', 'assistant' => 'Alpha two'],
            ['user' => 'Beta one', 'tool' => 'Beta tool output', 'assistant' => 'Beta two'],
        ];
        $historicIds = [];
        foreach ($previous as $index => $turn) {
            $run = $thread->enqueue($agentId, $turn['user']);
            $historicIds[] = $run['id'];
            foreach ($turn as $role => $content) {
                if ($role === 'user') continue;
                $message = ['role' => $role, 'content' => $content];
                if ($role === 'tool') $message['tool_call_id'] = 'tc-' . $index;
                $thread->append($run['id'], $message);
            }
        }
        foreach ($historicIds as $historicId) {
            Database::connection()->prepare("UPDATE runs SET status = 'completed' WHERE id = ?")->execute([$historicId]);
        }

        // Opt-in assignment scoping for this durable CLI agent only.
        $thread->configure(['execution_context' => ['agent_id' => $agentId], 'parameters' => ['history_scope' => 'assignment']]);
        $run = $thread->enqueue($agentId, 'current task');
        $thread->append($run['id'], ['role' => 'user', 'content' => 'same-run note']);
        $claimAsRunning($run);
        rotateLog();
        check(Run::execute($run['id']) === 'fixture answer', 'Assignment-scoped run completes');

        $captured = capturedInput();
        $messages = $captured['messages'] ?? [];
        check(is_array($messages) && count($messages) === 3, 'Scoped run sends system plus current-run turns only');
        check(array_column($messages, 'role') === ['system', 'user', 'user'], 'Scoped model input is system followed by current turns');
        $contents = array_column($messages, 'content');
        check(in_array('current task', $contents, true), 'Current task is retained for the model');
        check(in_array('same-run note', $contents, true), 'Same-run note is retained for the model');
        check(!in_array('Alpha one', $contents, true) && !in_array('Alpha two', $contents, true), 'Previous assistant turns are omitted from the model');
        check(!in_array('Beta one', $contents, true) && !in_array('Beta tool output', $contents, true) && !in_array('Beta two', $contents, true), 'Previous user/tool turns are omitted from the model');
        $tools = $toolNames($captured);
        $hasHistory = false;
        foreach ($tools as $name) {
            if (strpos($name, 'History') !== false) $hasHistory = true;
        }
        check($hasHistory, 'Current-thread History tool is available for the scoped run');
        check(!preg_grep('/Memory/', $tools), 'Cross-thread Memory is not granted to the scoped run');
        check(!array_key_exists('history_scope', $captured) && !isset($captured['body']['history_scope']), 'history_scope is never forwarded to the provider');

        $stored = [];
        $q = Database::connection()->prepare("SELECT raw->>'content' AS content FROM messages WHERE thread_id = ? ORDER BY id");
        $q->execute([$thread->id]);
        foreach ($q->fetchAll() as $row) $stored[] = $row['content'];
        foreach (['Alpha one', 'Alpha two', 'Beta one', 'Beta tool output', 'Beta two'] as $expected) {
            check(in_array($expected, $stored, true), "Previous turn [$expected] remains stored");
        }
    } finally {
        $thread->delete();
    }

    // --- Default conversation scope is unchanged --------------------------------------------
    $plain = $newThread();
    try {
        $first = $plain->enqueue($agentId, 'legacy one');
        $plain->append($first['id'], ['role' => 'assistant', 'content' => 'legacy reply one']);
        $second = $plain->enqueue($agentId, 'legacy two');
        $plain->append($second['id'], ['role' => 'assistant', 'content' => 'legacy reply two']);
        $target = $plain->enqueue($agentId, 'final task');
        $claimAsRunning($target);
        rotateLog();
        check(Run::execute($target['id']) === 'fixture answer', 'Default-scoped run completes');

        $captured = capturedInput();
        $messages = $captured['messages'] ?? [];
        check(array_column($messages, 'role') === ['system', 'user', 'assistant', 'user', 'assistant', 'user'], 'Default run receives the full conversation');
        check(in_array('legacy one', array_column($messages, 'content'), true), 'Previous turns remain in default context');
        check(end($messages)['content'] === 'final task', 'Default model input ends with the current task');
        check(!array_key_exists('history_scope', $captured) && !isset($captured['body']['history_scope']), 'No history_scope is present by default');
    } finally {
        $plain->delete();
    }

    // --- Explicit conversation scope accepts full history ----------------------------------
    $spoken = $newThread();
    try {
        $previous = [
            ['user' => 'Gamma one', 'assistant' => 'Gamma two'],
            ['user' => 'Delta one', 'tool' => 'Delta tool output', 'assistant' => 'Delta two'],
        ];
        foreach ($previous as $index => $turn) {
            $past = $spoken->enqueue($agentId, $turn['user']);
            foreach ($turn as $role => $content) {
                if ($role === 'user') continue;
                $message = ['role' => $role, 'content' => $content];
                if ($role === 'tool') $message['tool_call_id'] = 'tc-x' . $index;
                $spoken->append($past['id'], $message);
            }
        }

        // history_scope "conversation" is accepted as full normal history.
        $spoken->configure(['execution_context' => ['agent_id' => $agentId], 'parameters' => ['history_scope' => 'conversation']]);
        $run = $spoken->enqueue($agentId, 'now task');
        $claimAsRunning($run);
        rotateLog();
        check(Run::execute($run['id']) === 'fixture answer', 'Explicit conversation run completes');

        $captured = capturedInput();
        $messages = $captured['messages'] ?? [];
        check(count($messages) === 7, 'Conversation-scoped run sends the full conversation');
        check(array_column($messages, 'role') === ['system', 'user', 'assistant', 'user', 'tool', 'assistant', 'user'], 'Conversation model input keeps previous user/tool/assistant turns');
        $contents = array_column($messages, 'content');
        check(in_array('Gamma one', $contents, true) && in_array('Gamma two', $contents, true), 'Previous conversation turns remain in the model');
        check(in_array('Delta one', $contents, true) && in_array('Delta tool output', $contents, true) && in_array('Delta two', $contents, true), 'Previous tool turns remain in the model');
        check(in_array('now task', $contents, true), 'Current task is retained for the model');
        check(end($messages)['content'] === 'now task', 'Conversation model input ends with the current task');
        check(!array_key_exists('history_scope', $captured) && !isset($captured['body']['history_scope']), 'history_scope is never forwarded to the provider');
        $tools = $toolNames($captured);
        check(!preg_grep('/Memory/', $tools), 'Memory is not granted for conversation scope');
    } finally {
        $spoken->delete();
    }

    // --- Invalid scopes are rejected ------------------------------------------------------
    foreach (['bogus', 1] as $invalid) {
        $strict = $newThread();
        try {
            $strict->configure(['execution_context' => ['agent_id' => $agentId], 'parameters' => ['history_scope' => $invalid]]);
            $run = $strict->enqueue($agentId, 'rejected task');
            $claimAsRunning($run);
            expectException(fn() => Run::execute($run['id']), 'Invalid history_scope');
        } finally {
            $strict->delete();
        }
    }

    // --- Frontend / non-agent code cannot silently claim assignment context ----------------
    $frontend = $newThread();
    try {
        $frontend->configure(['execution_context' => ['agent_id' => $agentId], 'parameters' => ['history_scope' => 'assignment']]);
        $run = $frontend->enqueue($agentId, 'ui task', true);
        $claimAsRunning($run);
        expectException(fn() => Run::execute($run['id']), 'reserved for durable CLI agent');
    } finally {
        $frontend->delete();
    }

    $detached = $newThread();
    try {
        $detached->configure(['parameters' => ['history_scope' => 'assignment']]);
        $run = $detached->enqueue($agentId, 'detached task');
        $claimAsRunning($run);
        expectException(fn() => Run::execute($run['id']), 'reserved for durable CLI agent');
    } finally {
        $detached->delete();
    }

    // --- Regression: compaction preserves explicitly registered History tools ----------
    $pruned = new Agent\Request([
        'preset' => 'default',
        'context_window' => 4096,
        'context_reserve' => 1024,
    ]);
    $pruned->addMessage(['role' => 'system', 'content' => 'You are a helpful assistant.']);
    $pruned->addMessage(['role' => 'user', 'content' => 'Opening question.']);
    for ($i = 0; $i < 8; $i++) {
        $pruned->addMessage(['role' => 'user', 'content' => 'Deep background item ' . $i . ': ' . str_repeat('The quick brown fox jumps over the lazy dog while recording project alpha beta gamma delta status notes for compliance review. ', 10)]);
        $pruned->addMessage(['role' => 'assistant', 'content' => 'Noted background item ' . $i . '.']);
    }
    $pruned->addMessage(['role' => 'user', 'content' => 'Recent follow-up.']);
    $pruned->addMessage(['role' => 'assistant', 'content' => 'Recent reply.']);
    $pruned->addMessage(['role' => 'user', 'content' => 'Follow-up two.']);
    $pruned->addMessage(['role' => 'assistant', 'content' => 'Reply two.']);
    $pruned->addMessage(['role' => 'user', 'content' => 'Compress the deep background and confirm.']);
    // Explicit registration mirrors what Run::execute may already have done for this thread.
    $pruned->registerTool(['Agent\\Tools\\History', 'search']);
    $pruned->registerTool(['Agent\\Tools\\History', 'read']);
    rotateLog();
    check($pruned->send() === 'fixture answer', 'Pre-registered History survives automatic compaction');

    $captured = capturedInput();
    $events = $pruned->response['events'];
    check(count(array_filter($events, function ($event) { return $event['type'] === 'context.pruned'; })) === 1, 'Compaction emits context.pruned exactly once');
    $tools = $toolNames($captured);
    check(count($tools) === 2, 'Final provider payload carries exactly the two registered tools');
    $counts = array_count_values($tools);
    check(isset($counts['Agent_Tools_History_search']) && $counts['Agent_Tools_History_search'] === 1, 'History_search is registered exactly once for the provider');
    check(isset($counts['Agent_Tools_History_read']) && $counts['Agent_Tools_History_read'] === 1, 'History_read is registered exactly once for the provider');

    echo "PASS: assignment context scoping is opt-in, durable-CLI-only, and never forwarded\n";
} finally {
    putenv('ASSIGN_CTX_LOG');
    $fixture->close();
}
@unlink($logFile);
