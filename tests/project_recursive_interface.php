<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/fixtures/cli_process.php';

$alice = Agent\Identity::authenticate('alice', 'phillip-demo');
$bob = Agent\Identity::authenticate('bob', 'phillip-demo');
$marker = 'recursive-interface-' . bin2hex(random_bytes(8));
$fixtures = []; $cookies = []; $server = null;
$log = tmpfile();
$create = function ($owner, $name) use ($marker, &$fixtures) {
    $project = Agent\Project::create($owner['id'], ['title' => $marker . '-' . $name, 'settings' => ['manual' => true, 'project_manual' => true]]);
    $fixtures[] = ['id' => $project->id, 'thread_id' => $project->get()['thread_id'], 'owner_id' => (string)$owner['id']];
    Agent\Database::row("SELECT pg_advisory_lock(hashtext('phill-project-runner'), ?::integer)", [$project->id]);
    return $project;
};
$decision = function ($project, $todo, $question, $target = null) {
    return (int)Agent\Database::row('INSERT INTO project_decisions (project_id, todo_id, target_todo_id, todo_revision, question, evidence) VALUES (?, ?, ?, ?, ?, ?::jsonb) RETURNING id',
        [$project->id, $todo['id'], $target, $todo['revision'], $question, Agent\State::encode(['source' => '<script>evidence</script>'])])['id'];
};
try {
    $parsed = Agent\ProjectCli::parse(['answer', '42', '7', 'approve with conditions']);
    check($parsed['words'] === ['42', '7', 'approve with conditions'], 'CLI accepts an explicitly quoted answer');
    check(Agent\ProjectCli::parse(['decisions', '42'])['words'] === ['42'], 'CLI parses the decision listing');
    $traceParsed = Agent\ProjectCli::parse(['trace', '42', '7', '--after', '10']);
    check($traceParsed['words'] === ['42', '7'] && $traceParsed['options']['after'] === 10 && Agent\ProjectCli::parse(['trace', '42'])['words'] === ['42'], 'CLI trace accepts an optional task and event cursor');
    foreach ([['answer', '42'], ['answer', '42', '7'], ['answer', '42', '7', 'two', 'words'], ['decisions'], ['decisions', '42', '7']] as $bad) {
        expectException(function () use ($bad) { Agent\ProjectCli::parse($bad); }, 'requires');
    }
    foreach ([['trace'], ['trace', '42', '7', 'extra'], ['trace', '42', 'invalid'], ['trace', '42', '0'], ['trace', '42', '--after', 'invalid'], ['trace', '42', '--compact']] as $bad) {
        $rejected = false;
        try { Agent\ProjectCli::parse($bad); } catch (InvalidArgumentException $error) { $rejected = true; }
        check($rejected, 'CLI rejects malformed trace arguments: ' . implode(' ', $bad));
    }
    $method = new ReflectionMethod('Agent\\Tools\\Project', 'answerDecision');
    check($method->getNumberOfParameters() === 2 && (string)$method->getParameters()[0]->getType() === 'int' && (string)$method->getParameters()[1]->getType() === 'string', 'Model decision tool has typed inputs and no actor override');

    $project = $create($alice, 'alice');
    $todo = $project->addTodo(['title' => 'Root deliverable', 'goal' => 'A goal', 'requirements' => 'A requirement', 'context' => 'Known context', 'gates' => ['Verified']]);
    $root = $decision($project, $todo, 'Root <script>question</script>');
    $apiRoot = $decision($project, $todo, 'API root question');
    $child = $decision($project, $todo, 'Internal parent question', $todo['id']);
    $foreign = $create($bob, 'bob');
    $foreignTodo = $foreign->addTodo(['title' => 'Foreign deliverable']);
    $foreignDecision = $decision($foreign, $foreignTodo, 'Foreign private question');
    $staleTodo = $project->addTodo(['title' => 'Revised deliverable']);
    $stale = $decision($project, $staleTodo, 'Obsolete question');
    Agent\Database::execute('UPDATE project_todos SET revision = revision + 1 WHERE id = ?', [$staleTodo['id']]);

    $frontend = new Agent\ProjectFrontend($alice);
    $bobFrontend = new Agent\ProjectFrontend($bob);
    expectException(function () use ($bobFrontend, $project) { $bobFrontend->action($project->id, 'decisions'); }, 'access denied');
    expectException(function () use ($frontend, $project, $foreignDecision) { $frontend->action($project->id, 'answer', ['decision_id' => $foreignDecision, 'answer' => 'spoof']); }, 'Decision not found in this project');
    expectException(function () use ($frontend, $project, $root) { $frontend->action($project->id, 'answer', ['decision_id' => $root . 'invalid', 'answer' => 'spoof']); }, 'positive task or decision id');
    expectException(function () use ($frontend, $project, $child) { $frontend->action($project->id, 'answer', ['decision_id' => $child, 'answer' => 'spoof']); }, 'No execution context');

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) throw new RuntimeException('Cannot reserve project interface port: ' . $error);
    $address = stream_socket_get_name($socket, false); fclose($socket);
    putenv('PHP_CLI_SERVER_WORKERS');
    $server = proc_open('exec env APP_BASE_PATH= ' . escapeshellarg(PHP_BINARY) . ' -S ' . escapeshellarg($address) . ' -t ' . escapeshellarg(dirname(__DIR__) . '/public') . ' ' . escapeshellarg(dirname(__DIR__) . '/public/index.php'),
        [['file', '/dev/null', 'r'], $log, $log], $pipes, dirname(__DIR__));
    if (!is_resource($server)) throw new RuntimeException('Cannot start project interface server');
    $ready = false;
    for ($i = 0; $i < 100; $i++) {
        $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.05);
        if ($probe) { fclose($probe); $ready = true; break; }
        usleep(20000);
    }
    check($ready, 'Real project HTTP server starts');
    $request = function ($path, $user = 'alice', $body = null, $csrf = '', $form = false) use ($address, &$cookies) {
        if (!isset($cookies[$user])) $cookies[$user] = tmpfile();
        $cookiePath = stream_get_meta_data($cookies[$user])['uri']; $headers = [];
        $curl = curl_init('http://' . $address . $path);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_COOKIEFILE => $cookiePath, CURLOPT_COOKIEJAR => $cookiePath,
            CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$headers) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                return strlen($line);
            }]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $form ? http_build_query($body) : json_encode($body));
            curl_setopt($curl, CURLOPT_HTTPHEADER, [$form ? 'Content-Type: application/x-www-form-urlencoded' : 'Content-Type: application/json', 'X-CSRF-Token: ' . $csrf]);
        }
        $text = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_HTTP_CODE); $error = curl_error($curl);
        if ($text === false) throw new RuntimeException('Project HTTP transport failed: ' . $error);
        return ['status' => $status, 'body' => $text, 'json' => json_decode($text, true), 'headers' => $headers];
    };
    $tokens = [];
    foreach (['alice', 'bob'] as $username) {
        $state = $request('/api/state', $username);
        $login = $request('/api/login', $username, ['username' => $username, 'password' => 'phillip-demo'], $state['json']['csrf']);
        check($login['status'] === 200 && $login['json']['user']['username'] === $username, 'HTTP authenticates ' . $username);
        $tokens[$username] = $login['json']['csrf'];
    }
    $base = '/api/projects/' . $project->id . '/decisions';
    $listed = $request($base);
    $ids = array_map('intval', array_column($listed['json']['decisions'], 'id'));
    check($listed['status'] === 200 && in_array($root, $ids, true) && in_array($child, $ids, true) && !in_array($stale, $ids, true) && !in_array($foreignDecision, $ids, true), 'GET lists current project decisions and hides stale and foreign decisions');
    check($request($base, 'bob')['status'] === 403, 'Foreign private decision listing fails through authenticated HTTP');
    check($request($base . '/' . $root . '/answer')['status'] === 403, 'GET answer route cannot act as a decision listing');
    check($request($base, 'alice', ['decision_id' => $root, 'answer' => 'wrong route'], $tokens['alice'])['status'] === 403, 'Bare POST decision route cannot accept a body-selected id');
    check($request($base . '/' . $apiRoot . '/answer', 'alice', ['answer' => 'missing csrf'])['status'] === 403, 'API answers require CSRF');
    check($request($base . '/' . $root . '/answer', 'bob', ['answer' => 'outsider'], $tokens['bob'])['status'] === 403, 'Foreign private answers fail through authenticated HTTP');
    check($request($base . '/' . $foreignDecision . '/answer', 'alice', ['answer' => 'foreign'], $tokens['alice'])['status'] === 403, 'A foreign decision id cannot be answered through a readable project');
    check($request($base . '/' . $child . '/answer', 'alice', ['answer' => 'parent impersonation'], $tokens['alice'])['status'] === 403, 'Human interface rejects questions addressed to a runtime parent');
    check($request($base . '/' . $stale . '/answer', 'alice', ['answer' => 'stale'], $tokens['alice'])['status'] === 403, 'HTTP answers reject obsolete task revisions');
    $answered = $request($base . '/' . $apiRoot . '/answer', 'alice', ['answer' => 'Proceed', 'decision_id' => $root, 'actor_id' => $bob['id']], $tokens['alice']);
    $saved = Agent\Database::row('SELECT answer, answered_by FROM project_decisions WHERE id = ?', [$apiRoot]);
    check($answered['status'] === 200 && (int)$answered['json']['project']['id'] === $project->id && $saved['answer'] === 'Proceed' && (string)$saved['answered_by'] === (string)$alice['id'], 'API derives decision from route and actor from authenticated session');
    check(Agent\Database::row('SELECT answer FROM project_decisions WHERE id = ?', [$root])['answer'] === null, 'Spoofed body decision id remains unanswered');

    $cliCreated = cliProcess(['projects', 'create', $marker . '-cli'], '', true);
    $cliRow = json_decode($cliCreated['out'], true);
    if (isset($cliRow['id'], $cliRow['thread_id'])) $fixtures[] = ['id' => (int)$cliRow['id'], 'thread_id' => $cliRow['thread_id'], 'owner_id' => (string)$cliRow['owner_id']];
    check($cliCreated['status'] === 0 && isset($cliRow['id']), 'Actual unprivileged CLI creates an owned decision fixture');
    $cliProject = new Agent\Project($cliRow['id']);
    $cliTodo = $cliProject->addTodo(['title' => 'CLI question source']);
    $cliDecision = $decision($cliProject, $cliTodo, 'CLI root question');
    $cliListed = cliProcess(['projects', 'decisions', (string)$cliProject->id], '', true);
    $cliDecisions = json_decode($cliListed['out'], true);
    check($cliListed['status'] === 0 && (int)$cliDecisions[0]['id'] === $cliDecision, 'Actual CLI decisions command returns owned current questions as JSON');
    $cliMalformed = cliProcess(['projects', 'answer', (string)$cliProject->id, $cliDecision . 'invalid', 'Malformed id'], '', true);
    check($cliMalformed['status'] === 2 && strpos(json_decode($cliMalformed['out'], true)['error'], 'positive task or decision id') !== false, 'Actual CLI rejects malformed decision ids without coercion');
    $cliAnswer = cliProcess(['projects', 'answer', (string)$cliProject->id, (string)$cliDecision, 'Approved with conditions'], '', true);
    $cliAnswered = json_decode($cliAnswer['out'], true);
    check($cliAnswer['status'] === 0 && $cliAnswered['answer'] === 'Approved with conditions' && (string)$cliAnswered['answered_by'] === (string)$cliRow['owner_id'], 'Actual unprivileged CLI saves the complete answer with the local owner identity');
    $cliForeign = cliProcess(['projects', 'answer', (string)$cliProject->id, (string)$foreignDecision, 'Cross-project answer'], '', true);
    check($cliForeign['status'] === 1 && strpos(json_decode($cliForeign['out'], true)['error'], 'Decision not found in this project') !== false, 'Actual CLI rejects a foreign project decision id');

    $traceFixture = Agent\State::transaction(function () use ($cliProject, $cliTodo, $cliRow, $marker) {
        $thread = Agent\Thread::create($cliRow['owner_id'], $marker . '-trace', ['manual' => true]);
        Agent\Database::execute('UPDATE project_todos SET thread_id = ? WHERE id = ?', [$thread->id, $cliTodo['id']]);
        $run = Agent\Database::row("INSERT INTO runs (thread_id, author_id, settings, status) VALUES (?, ?, '{}'::jsonb, 'completed') RETURNING id", [$thread->id, $cliRow['owner_id']]);
        $ids = [];
        foreach ([
            ['tool.start', ['name' => 'Fixture_tool', 'arguments' => 'PRIVATE_ARGUMENTS']],
            ['reasoning.delta', ['content' => 'PRIVATE_REASONING']],
            ['tool.result', ['name' => 'Fixture_tool', '_tool_error' => true, 'content' => str_repeat('x', 2100), 'reasoning' => 'PRIVATE_REASONING']],
            ['run.failed', ['error' => 'Concrete fixture failure']],
            ['run.stopped', ['reason' => 'Fixture stopped']],
            ['run.completed', ['content' => 'Fixture completion']],
        ] as $event) $ids[] = (int)Agent\Database::row('INSERT INTO events (run_id, type, data) VALUES (?, ?, ?::jsonb) RETURNING id', [$run['id'], $event[0], Agent\State::encode($event[1])])['id'];
        return ['run_id' => (int)$run['id'], 'ids' => $ids];
    });
    $trace = cliProcess(['projects', 'trace', (string)$cliProject->id], '', true);
    $traceRows = json_decode($trace['out'], true);
    check($trace['status'] === 0 && count($traceRows) === 5 && $traceRows[0]['id'] === $traceFixture['ids'][0] && $traceRows[0]['todo_id'] === (int)$cliTodo['id'] && $traceRows[0]['run_id'] === $traceFixture['run_id'], 'Actual CLI trace joins project task runs in durable event order');
    check($traceRows[1]['_tool_error'] === true && $traceRows[1]['name'] === 'Fixture_tool' && strlen($traceRows[1]['content']) === 2000 && $traceRows[2]['content'] === 'Concrete fixture failure' && $traceRows[3]['content'] === 'Fixture stopped', 'CLI trace exposes tool failures and run reasons with bounded content');
    check(strpos($trace['out'], 'PRIVATE_ARGUMENTS') === false && strpos($trace['out'], 'PRIVATE_REASONING') === false && !isset($traceRows[0]['data']), 'CLI trace excludes raw arguments and reasoning');
    $traceAfter = cliProcess(['projects', 'trace', (string)$cliProject->id, (string)$cliTodo['id'], '--after', (string)$traceFixture['ids'][0]], '', true);
    $traceAfterRows = json_decode($traceAfter['out'], true);
    check($traceAfter['status'] === 0 && count($traceAfterRows) === 4 && $traceAfterRows[0]['id'] === $traceFixture['ids'][2], 'CLI trace accepts a task filter and resumes strictly after the supplied cursor');
    $traceForeign = cliProcess(['projects', 'trace', (string)$cliProject->id, (string)$foreignTodo['id']], '', true);
    check($traceForeign['status'] === 1 && strpos(json_decode($traceForeign['out'], true)['error'], 'not found in this project') !== false, 'CLI trace rejects foreign tasks even when no events match');
    $tracePrivate = cliProcess(['projects', 'trace', (string)$foreign->id], '', true);
    check($tracePrivate['status'] === 1 && strpos(json_decode($tracePrivate['out'], true)['error'], 'local project owner') !== false, 'CLI trace rejects foreign projects before reading activity');
    Agent\Database::execute("INSERT INTO events (run_id, type, data) SELECT ?, 'tool.start', '{\"name\":\"limit_fixture\"}'::jsonb FROM generate_series(1, 101)", [$traceFixture['run_id']]);
    $traceLimited = cliProcess(['projects', 'trace', (string)$cliProject->id, '--after', (string)end($traceFixture['ids'])], '', true);
    check($traceLimited['status'] === 0 && count(json_decode($traceLimited['out'], true)) === 100, 'CLI trace limits each cursor page to 100 events');

    $pagePath = '/?view=projects&project=' . $project->id;
    $page = $request($pagePath);
    $document = new DOMDocument(); @$document->loadHTML($page['body']); $xpath = new DOMXPath($document);
    $form = $xpath->query('//form[@data-project-form="answer:' . $root . '"]');
    check($page['status'] === 200 && $form->length === 1 && $xpath->query('.//input[@name="csrf"]', $form->item(0))->length === 1 && strpos($page['body'], '&lt;script&gt;question&lt;/script&gt;') !== false && strpos($page['body'], '<script>question') === false && strpos($page['body'], '&lt;script&gt;evidence&lt;/script&gt;') !== false, 'Server HTML renders escaped root question/evidence and ordinary CSRF-protected answer form');
    check($xpath->query('//form[@data-project-form="answer:' . $child . '" or @data-project-form="answer:' . $stale . '" or @data-project-form="answer:' . $apiRoot . '"]')->length === 0, 'Answer panel omits runtime-parent, stale and already-answered questions');
    $formBody = ['action' => 'project-answer', 'project_id' => $project->id, 'decision_id' => $root, 'answer' => 'Ship safely', 'actor_id' => $bob['id'], 'csrf' => 'invalid'];
    $rejectedForm = $request($pagePath, 'alice', $formBody, '', true);
    check($rejectedForm['status'] === 400 && strpos($rejectedForm['body'], '>Ship safely</textarea>') !== false, 'Ordinary answer form rejects invalid CSRF and preserves the answer draft');
    $formBody['csrf'] = $tokens['alice'];
    $posted = $request($pagePath, 'alice', $formBody, '', true);
    $saved = Agent\Database::row('SELECT answer, answered_by FROM project_decisions WHERE id = ?', [$root]);
    check($posted['status'] === 303 && $posted['headers']['location'] === '/?view=projects&project=' . $project->id && $saved['answer'] === 'Ship safely' && (string)$saved['answered_by'] === (string)$alice['id'], 'No-JavaScript POST answers as authenticated Alice and redirects to the project');
    $refreshed = $request('/fragments/project?project=' . $project->id);
    check($refreshed['status'] === 200 && strpos($refreshed['body'], 'data-project-form="answer:' . $root . '"') === false, 'Server fragment refresh removes the answered question');
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    foreach ($cookies as $cookie) fclose($cookie);
    fclose($log);
    foreach ($fixtures as $fixture) {
        $row = Agent\Database::row('SELECT t.title, t.owner_id FROM projects p JOIN threads t ON t.id = p.thread_id WHERE p.id = ? AND p.thread_id = ?', [$fixture['id'], $fixture['thread_id']]);
        if ($row) {
            if (strpos($row['title'], $marker) !== 0 || (string)$row['owner_id'] !== $fixture['owner_id']) throw new RuntimeException('Refusing cleanup outside captured fixture ownership');
            (new Agent\Project($fixture['id']))->delete();
        }
        Agent\Database::row("SELECT pg_advisory_unlock(hashtext('phill-project-runner'), ?::integer)", [$fixture['id']]);
    }
}
