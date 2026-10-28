<?php
require __DIR__ . '/helpers.php';
putenv('NO_COLOR=1');
putenv('FORCE_COLOR=');

use Agent\Cli;
use Agent\Project;
use Agent\ProjectTui;

$bin = dirname(__DIR__) . '/bin/phill';

function cliRun(array $args, $input = '')
{
    $descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
    $command = 'exec ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($GLOBALS['bin']);
    foreach ($args as $arg) $command .= ' ' . escapeshellarg($arg);
    // Preserve Docker's actual database endpoint and the rest of the shared bootstrap environment.
    $proc = proc_open($command, $descriptors, $pipes);
    if (!is_resource($proc)) {
        throw new RuntimeException('Could not start ' . $GLOBALS['bin']);
    }
    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['status' => proc_close($proc), 'out' => $out, 'err' => $err];
}

// --- option parsing (no database required) ---

$options = ProjectTui::parse(['--project', '42', '--once']);
check($options['project'] === '42' && $options['once'] === true, 'parses --project and --once');

$options = ProjectTui::parse(['--project=7', '--once']);
check($options['project'] === '7' && $options['once'] === true, 'supports --project=7 syntax');

$options = ProjectTui::parse([]);
check($options['project'] === null && $options['once'] === false, 'defaults to no project and no once');

$options = ProjectTui::parse(['--debug', '--verbose', '--once']);
check($options['once'] === true, 'accepts bootstrap-owned --debug/--verbose without error');

expectException(function () { ProjectTui::parse(['--bogus']); }, 'Unknown option: --bogus');
expectException(function () { ProjectTui::parse(['--project']); }, '--project requires a nonempty project id or name');
expectException(function () { ProjectTui::parse(['--project=']); }, '--project requires a nonempty project id or name');
expectException(function () { ProjectTui::parse(['--project', '1', '--project', '2']); }, 'Duplicate --project option');
$code = ProjectTui::run(['--bogus']);
check($code === 2, 'invalid option exits 2');

// --- rendering (no database required) ---

$displays = [
    ['id' => '1', 'title' => "Alpha\nInjected", 'status' => "planning\r\nesc", 'revision' => 3, 'persona' => 'reviewer', 'attempts' => 2, 'decisions' => 0, 'latest' => [['type' => 'project.created', 'created_at' => '2024-01-01T00:00:00+00:00']], 'todos' => [['id' => '10', 'title' => 'Child', 'status' => 'waiting', 'revision' => 2, 'attempts' => 1, 'wait_reason' => 'decision']]],
    ['id' => '2', 'title' => 'Beta', 'status' => 'running', 'revision' => 1, 'persona' => null, 'attempts' => 0, 'decisions' => 1, 'latest' => [], 'todos' => []],
];
$lines = ProjectTui::buildLines($displays);
$out = implode("\n", $lines);

check(strpos($out, '1 Alpha Injected') !== false, 'renders project id and title with newline collapsed');
check(strpos($out, "\n") === false || strpos($out, '1 Alpha Injected') !== false, 'embedded newlines collapsed in labels');
check(strpos($out, 'planning esc') !== false, 'renders status with control collapsed');
check(strpos($out, 'rev=3') !== false, 'renders current revision');
check(strpos($out, 'persona=reviewer') !== false, 'renders persona when available');
check(strpos($out, 'persona=-') !== false, 'renders dash when no persona');
check(strpos($out, 'attempts=2') !== false, 'renders attempts');
check(strpos($out, 'decisions=0') !== false, 'renders decisions waiting');
check(strpos($out, '- todo Child [waiting rev=2 attempts=1 wait=decision]') !== false, 'renders nested todo with status/revision/attempts/wait');
check(strpos($out, "\033") === false, 'plain render contains no ANSI escape');
check(strpos($out, "\r") === false, 'plain render contains no carriage return');
check(strpos($out, 'project.created') !== false, 'renders latest events');

$lines = ProjectTui::buildLines($displays, '1');
check(strpos($lines[1], '1 Alpha Injected') !== false, 'can select a project by id string compare');

check(ProjectTui::stripControl("\033[1mhi\033[0m") === 'hi', 'strips a bold ANSI escape');
check(ProjectTui::stripControl("\033[2J\033[Hline") === 'line', 'strips cursor/clear escapes');
check(ProjectTui::stripControl("plain") === 'plain', 'leaves plain text untouched');

// --- malicious control and newline injection via externally derived strings ---

$evil = [
    ['id' => '9', 'title' => "\033[1mPWNED\033[0m\nsecond", 'status' => 'running', 'revision' => 1, 'persona' => "\033[31mevil", 'attempts' => 0, 'decisions' => 0, 'latest' => [['type' => "\033[2Jbad", 'created_at' => 'x']], 'todos' => []],
];
$evilOut = implode("\n", ProjectTui::buildLines($evil));
check(strpos($evilOut, "\033") === false, 'strips ANSI from externally derived title/persona/event');
check(strpos($evilOut, 'PWNED second') !== false, 'collapses injected newline in title');
check(strpos($evilOut, 'bad') !== false, 'still renders sanitized event type');

// --- owner filter + actual CLI execution against the real PostgreSQL database ---

$identity = Cli::identity();
$owner = $identity['owner'];
$db = Agent\Database::connection();

$created = [];
$cleanup = function () use ($db, &$created) {
    foreach (array_reverse($created) as $table => $ids) {
        if ($ids === []) continue;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        Agent\Database::execute("DELETE FROM $table WHERE id IN ($placeholders)", $ids);
    }
};
register_shutdown_function($cleanup);

$foreignOwner = 'cli:foreign:' . substr(hash('sha256', 'foreign-owner'), 0, 12);

$ownThread = $db->prepare("INSERT INTO threads (title, owner_id, visibility, settings) VALUES (?, ?, 'private', ?::jsonb) RETURNING id");
$ownThread->execute(['TUI own ' . bin2hex(random_bytes(4)), $owner, json_encode(['persona' => 'reviewer'])]);
$ownThreadId = (int)$ownThread->fetchColumn();
$created['threads'][] = $ownThreadId;

$ownProject = $db->prepare("INSERT INTO projects (thread_id, status, revision) VALUES (?, 'planning', 5) RETURNING id");
$ownProject->execute([$ownThreadId]);
$ownProjectId = (int)$ownProject->fetchColumn();
$created['projects'][] = $ownProjectId;

$ownTodo = $db->prepare("INSERT INTO project_todos (project_id, parent_id, title, goal, requirements, context, gates, status, revision, attempts, wait_reason) VALUES (?, NULL, 'Own todo', 'goal', 'req', 'ctx', '[]'::jsonb, 'waiting', 2, 1, 'decision') RETURNING id");
$ownTodo->execute([$ownProjectId]);
$ownTodoId = (int)$ownTodo->fetchColumn();
$created['project_todos'][] = $ownTodoId;
$parentId = $ownTodoId;
$nestedIds = [];
foreach (['Nested child', 'Nested grandchild', 'Nested level three'] as $title) {
    $row = Agent\Database::row("INSERT INTO project_todos (project_id, parent_id, title, goal, requirements, context, gates) VALUES (?, ?, ?, 'goal', 'req', 'ctx', '[]'::jsonb) RETURNING id", [$ownProjectId, $parentId, $title]);
    $parentId = (int)$row['id'];
    $nestedIds[] = $parentId;
    $created['project_todos'][] = $parentId;
}
foreach ([
    [$ownTodoId, null, 2, null],
    [$nestedIds[0], $ownTodoId, 1, null],
    [$ownTodoId, null, 1, null],
    [$nestedIds[0], $ownTodoId, 999, null],
    [$ownTodoId, null, 2, 'Already answered'],
] as $decision) {
    $row = Agent\Database::row("INSERT INTO project_decisions (project_id, todo_id, target_todo_id, todo_revision, question, answer) VALUES (?, ?, ?, ?, 'Fixture question', ?) RETURNING id", array_merge([$ownProjectId], $decision));
    $created['project_decisions'][] = (int)$row['id'];
}

$foreignThread = $db->prepare("INSERT INTO threads (title, owner_id, visibility, settings) VALUES (?, ?, 'private', '{}') RETURNING id");
$foreignThread->execute(['TUI foreign ' . bin2hex(random_bytes(4)), $foreignOwner]);
$foreignThreadId = (int)$foreignThread->fetchColumn();
$created['threads'][] = $foreignThreadId;

$foreignProject = $db->prepare("INSERT INTO projects (thread_id, status, revision) VALUES (?, 'running', 1) RETURNING id");
$foreignProject->execute([$foreignThreadId]);
$foreignProjectId = (int)$foreignProject->fetchColumn();
$created['projects'][] = $foreignProjectId;

// listProjects must return only owned projects via WHERE t.owner_id = ? and ProjectAccess::local.
$projects = ProjectTui::listProjects(['owner' => $owner]);
$ids = [];
foreach ($projects as $p) { $ids[] = $p->id; }
check(in_array($ownProjectId, $ids, true), 'owner filter lists the owned project');
check(!in_array($foreignProjectId, $ids, true), 'owner filter excludes a foreign owner project');

// displayData reads revision, persona, attempts, decisions and nested todos from shared snapshot.
$ownProjectObj = new Project($ownProjectId);
$display = ProjectTui::displayData($ownProjectObj);
check($display['id'] === (string)$ownProjectId, 'display id matches owned project');
check($display['revision'] === 5, 'display reads current revision');
check($display['persona'] === 'reviewer', 'display reads persona');
check($display['attempts'] === 1, 'display sums todo attempts');
check(count($display['todos']) === 1, 'display nests the owned todo');
check($display['todos'][0]['wait_reason'] === 'decision', 'display reads todo wait_reason');
check($display['decisions'] === 2, 'Question count includes current root and parent questions and excludes stale or answered ones');
$nestedLines = ProjectTui::buildLines([$display]);
$nestedOutput = implode("\n", $nestedLines);
check(strpos($nestedOutput, '      - todo Nested child') !== false && strpos($nestedOutput, '        - todo Nested grandchild') !== false && strpos($nestedOutput, '          - todo Nested level three') !== false, 'Actual level-three descendants render at their nested indentation');
check($display['todos'][0]['children'][0]['children'][0]['children'][0]['children'] === [], 'Nested leaf has a normalized empty children list');
$cachedSelection = ProjectTui::listProjects($identity, $ownProjectId)[0];
Agent\Database::execute('UPDATE threads SET owner_id = ? WHERE id = ?', [$foreignOwner, $ownThreadId]);
try {
    expectException(function () use ($cachedSelection) { ProjectTui::displayData($cachedSelection); }, 'local project owner');
} finally {
    Agent\Database::execute('UPDATE threads SET owner_id = ? WHERE id = ?', [$owner, $ownThreadId]);
}
check(ProjectTui::displayData($cachedSelection)['id'] === (string)$ownProjectId, 'Each poll revalidates ownership even for an already selected project');

// Actual CLI nonTTY execution via the product entrypoint.
$result = cliRun(['--once']);
if ($result['status'] !== 0) { fwrite(STDERR, "CLI ERR: " . $result['err'] . "\n"); }
check($result['status'] === 0, 'bin/phill --once exits 0 in nonTTY');
check(strpos($result['out'], "\033") === false, 'CLI --once stdout has no ANSI');
check(strpos($result['out'], "\r") === false, 'CLI --once stdout has no CR');
check(strpos($result['out'], (string)$ownProjectId) !== false, 'CLI --once tree includes own fixture project');
check(strpos($result['out'], 'Own todo') !== false, 'CLI --once tree includes own nested todo');
check(strpos($result['out'], '          - todo Nested level three') !== false, 'Actual CLI tree renders descendants through level three');
check(preg_match('/^(?:> |  )' . $foreignProjectId . ' TUI foreign /m', $result['out']) === 0, 'CLI --once tree does not include foreign project');
$selected = cliRun(['--once', '--project', (string)$ownProjectId]);
check($selected['status'] === 0 && strpos($selected['out'], 'Own todo') !== false, 'Actual CLI parses a selected project and renders its tree');
$denied = cliRun(['--once', '--project', (string)$foreignProjectId]);
check($denied['status'] === 1 && $denied['out'] === '', 'Actual CLI refuses a foreign project without leaking its title');
$invalid = cliRun(['--bogus']);
check($invalid['status'] === 2 && $invalid['out'] === '', 'Actual CLI rejects unknown arguments without rendering project data');
