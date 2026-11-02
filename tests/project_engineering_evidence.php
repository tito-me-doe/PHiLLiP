<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/fixtures/WebFixture.php';
require __DIR__ . '/fixtures/cli_process.php';

$fixture = new WebFixture();
try {
    Agent\Installation::install(['auth' => 'standalone'], 'admin', 'fixture-secret', 'Admin');
    $owner = Agent\Cli::identity()['owner'];
    $project = Agent\Project::create($owner, ['title' => 'Evidence fixture']);
    $todo = $project->addTodo(['title' => 'Task', 'goal' => 'Goal', 'requirements' => 'Requirements', 'context' => 'Context', 'gates' => ['Test']]);
    $agent = Agent\Agents::create('evidence-fixture', ['profile' => ['url' => 'http://127.0.0.1:1/v1/chat/completions', 'model' => 'fixture'], 'tools' => [], 'workspace' => getcwd()]);
    $run = (new Agent\Thread($agent['thread_id']))->enqueue($owner, 'work');
    Agent\Database::execute("UPDATE runs SET status = 'completed', finished_at = NOW() WHERE id = ?", [$run['id']]);
    $link = Agent\ProjectAssignments::link($project, $todo['id'], $agent['id'], $run['id']);
    Agent\ProjectAssignments::verdict($project, $todo['id'], 'accept', ['test' => 'passed']);
    $payload = '{"lint":"php -l passed","test":"suite passed","review":"owner reviewed"}';
    $response = cliProcess(['projects', 'evidence', (string)$project->id, (string)$todo['id'], '--evidence', $payload]);
    $record = json_decode($response['out'], true);
    check($response['status'] === 0 && preg_match('/^[a-f0-9]{40,64}$/', $record['git_head']) && $record['todo_revision'] === (int)$todo['revision'], 'CLI records revision-bound Git and owner evidence');
    check($record['link_id'] === (int)$link['id'] && $record['run_id'] === (int)$run['id'], 'Evidence identifies its worker link and run');
    check($record['owner_attestation']['review'] === 'owner reviewed' && !isset($record['owner_attestation']['remote_identity']), 'Remote identity is optional');
    Agent\ToolContext::withValues(['run_id' => 1], function () use ($project, $todo) {
        expectException(fn() => Agent\ProjectEvidence::record($project, $todo['id'], (object)['lint'=>'x','test'=>'x','review'=>'x']), 'local owner');
        expectException(fn() => $project->reopen(), 'local owner');
    });
    expectException(fn() => $project->reopen(), 'completed');
    Agent\Database::execute("UPDATE projects SET status = 'completed', preflight_status = 'ready', preflight_revision = revision WHERE id = ?", [$project->id]);
    Agent\Database::execute("UPDATE project_todos SET status = 'done', evidence = '{\"old\":true}'::jsonb WHERE id = ?", [$todo['id']]);
    $planning = (new Agent\Thread($project->get()['thread_id']))->enqueue($owner, 'pending planning');
    expectException(fn() => $project->reopen(), 'Finish planning work');
    Agent\Database::execute("UPDATE runs SET status = 'stopped', finished_at = NOW() WHERE id = ?", [$planning['id']]);
    $before = $project->get();
    $response = cliProcess(['projects', 'reopen', (string)$project->id]);
    $after = json_decode($response['out'], true);
    check($response['status'] === 0 && $after['status'] === 'planning' && $after['revision'] === $before['revision'] + 1 && $after['preflight_status'] === 'unchecked' && $after['preflight_revision'] === null, 'Reopen invalidates readiness at a new revision');
    $updated = $project->todos()[0];
    check($updated['status'] === 'pending' && (int)$updated['revision'] === (int)$todo['revision'] + 1 && !$updated['evidence'], 'Reopen requires fresh ToDo work and evidence');
    check(Agent\ProjectAssignments::listing($project)['counts']['stale'] === 1, 'Reopen makes prior worker acceptance stale');
    expectException(fn() => Agent\ProjectAssignments::verdict($project, $todo['id'], 'accept', []), 'No current');
    expectException(fn() => Agent\ProjectEvidence::record($project, $todo['id'], (object)['lint'=>'x','test'=>'x','review'=>'x']), 'current worker link');
    $events = $project->history();
    $saved = array_values(array_filter($events, fn($e) => $e['type'] === 'project.reopened'));
    check(count($saved) === 1 && $saved[0]['data']['previous_todos'][0]['evidence']['old'], 'Old evidence is retained in reopen history');
    expectException(fn() => $project->start($owner), 'ready preflight');
    echo "ALL PASS\n";
} finally { $fixture->close(); }
