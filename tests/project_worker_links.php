<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/fixtures/WebFixture.php';
require __DIR__ . '/fixtures/cli_process.php';

$fixture = new WebFixture();
try {
    Agent\Installation::install(['auth' => 'standalone'], 'admin', 'fixture-secret', 'Admin');
    $owner = Agent\Cli::identity()['owner'];
    $config = ['url' => 'http://127.0.0.1:1/v1/chat/completions', 'model' => 'fixture'];
    $project = Agent\Project::create($owner, ['title' => 'Link fixture', 'settings' => ['profile' => $config, 'manual' => true]]);
    $todo = $project->addTodo(['title' => 'First']);
    $second = $project->addTodo(['title' => 'Second']);
    $agent = Agent\Agents::create('link-fixture', ['profile' => $config, 'tools' => [], 'workspace' => getcwd()]);
    $thread = new Agent\Thread($agent['thread_id']);
    $run = $thread->enqueue($owner, 'Existing assignment');
    $failed = $thread->enqueue($owner, 'Failed assignment');
    Agent\Database::execute("UPDATE runs SET status = 'completed', finished_at = NOW() WHERE id = ?", [$run['id']]);
    Agent\Database::execute("UPDATE runs SET status = 'failed', error = 'fixture', finished_at = NOW() WHERE id = ?", [$failed['id']]);
    $before = $project->todos();
    $link = Agent\ProjectAssignments::link($project, $todo['id'], $agent['id'], $run['id']);
    $again = Agent\ProjectAssignments::link($project, $todo['id'], $agent['id'], $run['id']);
    check($link['id'] === $again['id'], 'Link is idempotent for the same todo revision and run');
    $shared = Agent\ProjectAssignments::link($project, $second['id'], $agent['id'], $run['id']);
    check($shared['id'] !== $link['id'], 'One run may be linked to multiple ToDos');
    Agent\ProjectAssignments::verdict($project, $todo['id'], 'accept', ['checks' => 'passed']);
    $view = Agent\ProjectAssignments::listing($project, null, true);
    check($view['counts']['accepted'] === 1 && $view['counts']['pending'] === 1, 'Compact engineering counts separate acceptance from pending');
    check($project->todos() === $before, 'Links and owner verdicts do not change runtime ToDos or evidence');
    // Typed verdict evidence: the mutation returns typed evidence (never an escaped JSON string), and nested objects/arrays plus {} vs [] roundtrip distinctly. A fresh third ToDo is used so the existing pending count on $second is undisturbed.
    $third = $project->addTodo(['title' => 'Third']);
    Agent\ProjectAssignments::link($project, $third['id'], $agent['id'], $run['id']);
    $nested = Agent\ProjectAssignments::verdict($project, $third['id'], 'accept', ['result' => ['scores' => [1, 2], 'meta' => (object)['ok' => true]]]);
    check(!is_string($nested['evidence']) && (is_array($nested['evidence']) || $nested['evidence'] instanceof \stdClass), 'Verdict mutation returns typed evidence rather than a JSON string');
    check(is_array($nested['evidence']->result->scores) && $nested['evidence']->result->scores === [1, 2], 'Nested JSON array roundtrips as a PHP array');
    check($nested['evidence']->result->meta instanceof \stdClass && $nested['evidence']->result->meta->ok === true, 'Nested JSON object roundtrips as a PHP object');
    $emptyObject = Agent\ProjectAssignments::verdict($project, $third['id'], 'reject', new \stdClass());
    check($emptyObject['evidence'] instanceof \stdClass && !is_array($emptyObject['evidence']), 'Empty JSON object {} roundtrips as an object, not an array');
    $emptyArray = Agent\ProjectAssignments::verdict($project, $third['id'], 'reject', []);
    check(is_array($emptyArray['evidence']) && $emptyArray['evidence'] === [], 'Empty JSON array [] roundtrips as an array, not an object');
    $listed = Agent\ProjectAssignments::listing($project, $third['id']);
    $recent = $listed['todos'][0]['history'][0]['verdicts'][0]['evidence'];
    check(is_array($recent) && $recent === [], 'Listing history preserves the empty-array evidence type');
    $project->updateTodo($todo['id'], ['title' => 'Revised']);
    $view = Agent\ProjectAssignments::listing($project);
    check($view['counts']['stale'] === 1, 'Todo revision makes the old link stale');
    expectException(fn() => Agent\ProjectAssignments::verdict($project, $todo['id'], 'accept', []), 'No current');
    $new = Agent\ProjectAssignments::link($project, $todo['id'], $agent['id'], $run['id']);
    check($new['id'] !== $link['id'], 'Relinking the same run on a new revision appends history');
    Agent\ProjectAssignments::link($project, $todo['id'], $agent['id'], $failed['id']);
    expectException(fn() => Agent\ProjectAssignments::verdict($project, $todo['id'], 'accept', []), 'successful');
    Agent\ProjectAssignments::verdict($project, $todo['id'], 'reject', ['reason' => 'failed']);
    $view = Agent\ProjectAssignments::listing($project, $todo['id']);
    check($view['counts']['rejected'] === 1 && count($view['todos'][0]['history']) === 3 && count($view['todos'][0]['history'][2]['verdicts']) === 1, 'Replacement and verdict history are retained');
    expectException(fn() => Agent\ProjectAssignments::link($project, 999999, $agent['id'], $run['id']), 'ToDo not found');
    $other = Agent\Agents::create('other-link-fixture', ['profile' => $config, 'tools' => [], 'workspace' => getcwd()]);
    expectException(fn() => Agent\ProjectAssignments::link($project, $todo['id'], $other['id'], $run['id']), 'does not belong');
    Agent\ToolContext::withValues(['run_id' => $run['id'], 'agent_id' => $agent['id']], function () use ($project, $todo, $agent, $run) {
        expectException(fn() => Agent\ProjectAssignments::link($project, $todo['id'], $agent['id'], $run['id']), 'local operator');
        expectException(fn() => Agent\ProjectAssignments::listing($project), 'local operator');
    });
    foreach (['unix_uid' => 999999, 'hostname' => 'foreign-host', 'owner_id' => 'foreign-owner'] as $field => $value) {
        $original = Agent\Database::row('SELECT ' . $field . ' FROM agents WHERE id = ?', [$other['id']])[$field];
        Agent\Database::execute('UPDATE agents SET ' . $field . ' = ? WHERE id = ?', [$value, $other['id']]);
        expectException(fn() => Agent\ProjectAssignments::link($project, $todo['id'], $other['id'], $run['id']), 'not found or not owned');
        Agent\Database::execute('UPDATE agents SET ' . $field . ' = ? WHERE id = ?', [$original, $other['id']]);
    }
    Agent\Database::execute('UPDATE agents SET parent_id = ?, depth = 1 WHERE id = ?', [$agent['id'], $other['id']]);
    expectException(fn() => Agent\ProjectAssignments::link($project, $todo['id'], $other['id'], $run['id']), 'standalone');
    Agent\Database::execute('UPDATE agents SET parent_id = NULL, depth = 0 WHERE id = ?', [$other['id']]);
    $foreign = Agent\Project::create('foreign-owner', ['title' => 'Foreign', 'settings' => ['profile' => $config, 'manual' => true]]);
    expectException(fn() => Agent\ProjectAssignments::listing($foreign), 'local project owner');
    $pending = $thread->enqueue($owner, 'Pending assignment');
    Agent\ProjectAssignments::link($project, $todo['id'], $agent['id'], $pending['id']);
    expectException(fn() => Agent\ProjectAssignments::verdict($project, $todo['id'], 'reject', []), 'terminal');
    $args = ['projects', 'assignments', (string)$project->id, '--compact'];
    $cli = cliProcess($args);
    $parsed = json_decode($cli['out'], true);
    check($cli['status'] === 0 && $parsed['counts']['pending'] === 2, 'CLI compact assignments reports current engineering state');
    $cli = cliProcess(['projects', 'link-run', (string)$project->id, (string)$todo['id'], (string)$agent['id'], (string)$run['id']]);
    check($cli['status'] === 0, 'CLI link-run accepts an existing link idempotently');
    $cli = cliProcess(['projects', 'verdict', (string)$project->id, (string)$second['id'], 'accept', '--evidence', '{"verified":true}']);
    check($cli['status'] === 0, 'CLI verdict records structured owner evidence');
    $payloads = ['{"nested":{"meta":{},"items":[{},[],{"ok":true}]}}', '{}', '[]'];
    foreach ($payloads as $payload) {
        $response = cliProcess(['projects', 'verdict', (string)$project->id, (string)$second['id'], 'accept', '--evidence', $payload]);
        $result = json_decode($response['out']);
        check($response['status'] === 0 && isset($result->evidence) && json_encode($result->evidence) === json_encode(json_decode($payload)), 'CLI verdict returns typed evidence for ' . $payload);
    }
    $response = cliProcess(['projects', 'assignments', (string)$project->id, (string)$second['id']]);
    $history = json_decode($response['out']);
    $verdicts = $history->todos[0]->history[0]->verdicts;
    check($response['status'] === 0 && is_array($verdicts[0]->evidence) && $verdicts[0]->evidence === [], 'CLI history returns empty array as []');
    check($verdicts[1]->evidence instanceof \stdClass && json_encode($verdicts[1]->evidence) === '{}', 'CLI history returns empty object as {}');
    $nestedHistory = $verdicts[2]->evidence;
    check($nestedHistory instanceof \stdClass && $nestedHistory->nested->meta instanceof \stdClass && is_array($nestedHistory->nested->items) && $nestedHistory->nested->items[0] instanceof \stdClass && $nestedHistory->nested->items[1] === [] && $nestedHistory->nested->items[2]->ok === true, 'CLI history preserves nested objects and arrays');
    check(cliProcess(['projects', 'verdict', (string)$project->id, (string)$second['id'], 'accept'])['status'] === 2, 'CLI verdict requires evidence');
    echo "ALL PASS\n";
} finally { $fixture->close(); }
