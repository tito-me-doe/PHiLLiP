<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/fixtures/cli_process.php';
putenv('PHP_CLI_SERVER_WORKERS');
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$socket) throw new RuntimeException('Cannot allocate persona fixture port');
$address = stream_socket_get_name($socket, false); fclose($socket);
$process = proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' -S ' . escapeshellarg($address) . ' ' . escapeshellarg(__DIR__ . '/fixtures/cli_engine.php'), [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
$alice = Agent\Identity::authenticate('alice', 'phillip-demo');
$bob = Agent\Identity::authenticate('bob', 'phillip-demo');
$carol = Agent\Identity::authenticate('carol', 'phillip-demo');
$admin = Agent\Identity::authenticate('admin', 'phillip-demo');
$db = Agent\Database::connection(); $personas = []; $threads = [];
$marker = 'persona-fixture-' . bin2hex(random_bytes(5));
$config = ['url' => 'http://' . $address . '/v1/chat/completions', 'model' => 'inspect', 'context_window' => 262144, 'max_retries' => 0];
try {
    usleep(200000);
    $persona = Agent\Personas::save($alice['id'], ['name' => ' ' . $marker . ' ', 'prompt' => "Keep this prompt exactly.\n  Whitespace matters.  ", 'tools' => ' fetch, SearchWeb, Fetch ', 'visibility' => 'organization']); $personas[] = $persona;
    check($persona['name'] === $marker && $persona['tools'] === ['Agent\\Tools\\Fetch', 'Agent\\Tools\\SearchWeb'] && $persona['contract'] === null, 'Persona name and named tools normalize once; optional contract stays absent');
    check($persona['prompt'] === "Keep this prompt exactly.\n  Whitespace matters.  ", 'System prompt content is not decomposed, trimmed, or rewritten');
    check(Agent\Personas::resolve($marker, $carol['id']) === $persona && Agent\Personas::get($persona) === $persona, 'Visible names and canonical row/ID references resolve to the saved persona');
    $private = Agent\Personas::save($alice['id'], ['name' => $marker, 'prompt' => 'PRIVATE_MARKER']); $personas[] = $private;
    check(Agent\Personas::resolve($marker, $carol['id'])['id'] === $persona['id'], 'Private duplicate names cannot poison or leak into another audience lookup');
    expectException(function () use ($marker, $alice) { Agent\Personas::resolve($marker, $alice['id']); }, 'Ambiguous');
    expectException(function () use ($private, $admin) { Agent\Personas::resolve($private['id'], $admin['id']); }, 'access denied');
    expectException(function () use ($private, $bob) { (new Agent\Frontend($bob))->persona($private['id']); }, 'access denied');
    expectException(function () use ($private, $bob) { (new Agent\Frontend($bob))->create((object)['title' => 'Forbidden persona', 'settings' => (object)['persona' => $private['id']]]); }, 'access denied');
    $cli = cliProcess(['--persona', (string)$private['id'], '-p', json_encode($config), 'Try private persona'], '', true);
    check($cli['status'] === 1 && $cli['out'] === '' && strpos($cli['err'], 'PRIVATE_MARKER') === false, 'An unmapped CLI account cannot select or disclose a private persona');
    $team = Agent\Personas::save($alice['id'], ['name' => $marker . '-team', 'prompt' => 'Team only', 'visibility' => 'team', 'team_id' => 'engineering']); $personas[] = $team;
    check(Agent\Personas::resolve($team['id'], $bob['id'])['id'] === $team['id'], 'Team members may select a shared persona');
    expectException(function () use ($team, $carol) { Agent\Personas::resolve($team['id'], $carol['id']); }, 'access denied');
    $before = Agent\Personas::get($persona);
    foreach ([['tools' => [function () {}]], ['tools' => 'function () {}'], ['contract' => '/etc/passwd'], ['contract' => '[]'], ['contract' => ['$ref' => 'file:///etc/passwd']], ['contract' => ['type' => 'object', 'definitions' => ['bad' => ['$ref' => 'http://127.0.0.1/private']]]]] as $bad) {
        $rejected = false;
        try { Agent\Personas::save($alice['id'], $bad, $persona); } catch (Throwable $e) { $rejected = true; }
        check($rejected && Agent\Personas::get($persona) === $before, 'Invalid tools/contracts cannot mutate a persona or read server-side contract files');
    }
    $prepared = Agent\Personas::settings($persona, ['tools' => [], 'contract' => null]);
    check($prepared['tools'] === [] && $prepared['contract'] === null && strpos($prepared['system_prompt'], 'Your name is ' . $marker) === 0, 'Explicit empty tools/null contract override persona defaults while identity is supplied');
    check(Agent\Schema::errors('{"answer":"ok"}', ['type' => 'object', 'properties' => ['answer' => ['$ref' => '#/definitions/answer']], 'definitions' => ['answer' => ['type' => 'string']]]) === [], 'Self-contained contracts still support local reusable schema references');
    $request = new Agent\Request(array_replace($config, ['persona' => $persona])); $request->addMessage('Inspect request');
    $body = json_decode($request->send(), true);
    check(count($body['tools']) === 2 && $body['messages'][0]['role'] === 'system' && strpos($body['messages'][0]['content'], $marker) !== false, 'PHP Request integration uses the shared persona prompt and tool defaults');
    check(!isset($body['persona'], $body['persona_defaults'], $body['assistant_name']) && $request->response['messages'][0]['_assistant_name'] === $marker, 'Persona metadata never leaks into model API parameters and labels the resulting assistant');
    $request = new Agent\Request(array_replace($config, ['persona' => $persona, 'tools' => [], 'contract' => null]));
    $request->addMessages([['role' => 'system', 'content' => 'Explicit system wins'], ['role' => 'user', 'content' => 'Inspect']]);
    $body = json_decode($request->send(), true);
    check($body['messages'][0]['content'] === 'Explicit system wins' && count($body['messages']) === 2 && empty($body['tools']), 'Explicit PHP instructions/tools override defaults without duplicate system messages');
    $thread = Agent\Thread::create($alice['id'], $marker, ['profile' => $config, 'persona' => $persona, 'manual' => true]); $threads[] = $thread;
    $copied = [$thread->get()['settings'], $thread->messages()[0]['raw']['content']];
    Agent\Personas::save($alice['id'], ['name' => $marker . '-edited', 'prompt' => 'Changed', 'tools' => []], $persona);
    check([$thread->get()['settings'], $thread->messages()[0]['raw']['content']] === $copied, 'Editing a persona never rewrites already copied thread settings or prompt');
    $run = $thread->enqueue($alice['id'], 'Run with copied defaults'); $claimed = Agent\Run::claim($thread->id);
    check($claimed['id'] === $run['id'], 'Persona thread uses the shared durable run claim');
    Agent\Run::execute($run['id']);
    $messages = Agent\Views::messageAuthors($thread->messages());
    check(end($messages)['author_name'] === $marker, 'Saved assistant messages preserve the name used for their own response');
    $frontend = new Agent\Frontend($alice);
    $draft = $frontend->persona($persona['id']);
    check($draft['tools'] === [] && $draft['assistant_name'] === $marker . '-edited', 'Frontend persona preparation returns the current authorized defaults');
    check($frontend->persona($persona['id'], ['tools' => 'Fetch::fetch'])['tools'] === ['Agent\\Tools\\Fetch::fetch'], 'Named static tool methods share the same frontend class policy as class toolsets');
    expectException(function () use ($persona, $carol) { (new Agent\Frontend($carol))->persona($persona['id'], ['tools' => ['Agent\\Tools\\SearchWeb']]); }, 'not permitted');
    $restricted = Agent\Personas::save($alice['id'], ['name' => $marker . '-restricted', 'prompt' => 'Browser required', 'tools' => ['BrowseWeb'], 'visibility' => 'organization']); $personas[] = $restricted;
    expectException(function () use ($restricted, $bob) { (new Agent\Frontend($bob))->persona($restricted['id']); }, 'not permitted');

    // Every model interaction below runs through the executable, not a separate PHP client.
    $cli = cliProcess(['--persona', $marker . '-edited', '-p', json_encode($config), '-T', 'new', 'First turn'], '', true);
    check($cli['status'] === 0 && preg_match('/\[Info\] Thread ([0-9]+)/', $cli['err'], $match), 'CLI selects a visible persona by name and returns its saved thread handle');
    $cliThread = new Agent\Thread($match[1]); $threads[] = $cliThread;
    $body = json_decode($cli['out'], true);
    check(strpos($body['messages'][0]['content'], $marker . '-edited') !== false && strpos($cli['out'], '[Debug]') === false, 'Persona CLI sends named system instructions while preserving final-only stdout');
    Agent\Personas::delete($alice['id'], $persona['id']);
    foreach (['Second', 'Third', 'Fourth'] as $word) {
        $cli = cliProcess(['-T', (string)$cliThread->id, $word . ' turn'], '', true); $body = json_decode($cli['out'], true);
        check($cli['status'] === 0 && strpos($body['messages'][0]['content'], $marker . '-edited') !== false && end($body['messages'])['content'] === $word . ' turn', 'CLI follow-up survives persona deletion with its copied prompt/name/defaults: ' . $word);
    }
    $toolsPersona = Agent\Personas::save($alice['id'], ['name' => $marker . '-cli-tools', 'prompt' => 'Use tools only when necessary.', 'tools' => 'Fetch', 'visibility' => 'organization']); $personas[] = $toolsPersona;
    $cli = cliProcess(['--persona', (string)$toolsPersona['id'], '-p', json_encode($config), '-T', 'new', 'Inspect'], '', true);
    check($cli['status'] === 0 && preg_match('/\[Info\] Thread ([0-9]+)/', $cli['err'], $match) && count(json_decode($cli['out'], true)['tools']) === 1, 'CLI persona selection explicitly opts into its named default toolset');
    $toolsThread = new Agent\Thread($match[1]); $threads[] = $toolsThread;
    foreach ([['-t', 'none'], []] as $override) {
        $cli = cliProcess(array_merge(['-T', (string)$toolsThread->id], $override, ['Inspect follow-up']), '', true); $body = json_decode($cli['out'], true);
        check($cli['status'] === 0 && (bool)!empty($body['tools']) === !$override, 'Explicit tool override is invocation-local; the next turn restores copied persona defaults');
    }
    $cli = cliProcess(['--persona', (string)$toolsPersona['id'], '-p', json_encode($config), 'Inspect persona tool defaults']);
    if (posix_geteuid() === 0) {
        check($cli['status'] === 1 && $cli['out'] === '' && strpos($cli['err'], 'Root') !== false, 'Persona defaults cannot bypass the root tool restriction');
    } else {
        check($cli['status'] === 0 && count(json_decode($cli['out'], true)['tools']) === 1, 'A native unprivileged CLI may select the persona default toolset');
    }
    $cli = cliProcess(['--persona', (string)$toolsPersona['id'], '-t', 'none', '-p', json_encode($config), 'No tools']);
    check($cli['status'] === 0 && empty(json_decode($cli['out'], true)['tools']), 'The current Unix caller may explicitly disable all persona tools');
    $contractPersona = Agent\Personas::save($alice['id'], ['name' => $marker . '-contract', 'prompt' => 'Return ready true.', 'contract' => '{"ready":{"type":"boolean"}}', 'visibility' => 'organization']); $personas[] = $contractPersona;
    $cli = cliProcess(['--persona', (string)$contractPersona['id'], '-p', json_encode($config), '-T', 'new', 'Ready?'], '', true);
    check($cli['status'] === 0 && json_decode($cli['out'], true) === ['ready' => true] && preg_match('/\[Info\] Thread ([0-9]+)/', $cli['err'], $match), 'CLI persona contract validates the final response through the shared request path');
    $contractThread = new Agent\Thread($match[1]); $threads[] = $contractThread;
    $cli = cliProcess(['--persona', (string)$contractPersona['id'], '-p', json_encode(array_replace($config, ['model' => 'error']))], '', true);
    check($cli['status'] === 1 && $cli['out'] === '', 'Health fallback cannot replace a failed persona contract with a plain-text greeting');
    $cli = cliProcess(['-T', (string)$contractThread->id, '-c', '{}', 'No contract this turn'], '', true);
    check($cli['status'] === 0 && !isset(json_decode($cli['out'], true)['response_format']), 'An explicit empty contract disables a persona contract for one invocation');
    $cli = cliProcess(['-T', (string)$contractThread->id, 'Restore contract'], '', true);
    check($cli['status'] === 0 && json_decode($cli['out'], true) === ['ready' => true], 'Copied persona contract resumes on the next follow-up without flags');
} finally {
    foreach ($threads as $thread) $thread->delete();
    foreach ($personas as $persona) $db->prepare('DELETE FROM personas WHERE id = ?')->execute([$persona['id']]);
    proc_terminate($process); proc_close($process);
}
