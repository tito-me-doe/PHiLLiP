<?php
// Opt-in real Nemotron recursive smoke test. --help or no args prints usage and never calls the model.
require __DIR__ . '/helpers.php';

use Agent\Database;
use Agent\Project;
use Agent\ProjectRunner;
use Agent\Run;

global $argv;
$argv = isset($argv) ? $argv : $_SERVER['argv'];
array_shift($argv);
if (!$argv || in_array($argv[0], ['--help', '-h', 'help'], true)) {
    echo "Usage: php tests/project_recursive_live.php [--profile NAME]\n";
    echo "  --profile NAME   Nemotron profile to use (default nemotron-vl-fast)\n";
    echo "Opt-in live recursive smoke test; prints usage and exits without calling the model when no profile is given.\n";
    exit(0);
}
$profile = 'nemotron-vl-fast';
for ($i = 0; $i < count($argv); $i++) {
    if ($argv[$i] === '--profile') { if (!isset($argv[$i+1])) { echo "FAIL: --profile requires a value\n"; exit(2); } $profile = $argv[++$i]; }
    elseif (preg_match('/^--profile=(.+)$/', $argv[$i], $m)) { $profile = $m[1]; }
    else { echo "FAIL: unknown argument {$argv[$i]}\n"; exit(2); }
}

$owner = \Agent\Cli::identity()['owner'];
$failures = [];
function record($cond, $msg) { global $failures; if (!$cond) { $failures[] = $msg; echo "FAIL: $msg\n"; } else echo "PASS: $msg\n"; }

$facts = 'The read-only TUI has --once (one render then exit), strips terminal controls from user content, and displays only projects owned by the current CLI identity. Write acceptance checks from these supplied facts, not claims that you executed tests.';
$leafSpec = [
    'title' => 'Leaf TUI checklist', 'goal' => 'Write three TUI acceptance checks.',
    'requirements' => 'Write one check each for --once, terminal-control stripping, and owner-only display. This is a leaf: create no children or decisions. Call Project.report with your checklist.',
    'context' => $facts, 'gates' => ['Checklist covers all three facts without claiming unperformed tests'],
];
$childSpec = [
    'title' => 'TUI acceptance coordinator', 'goal' => 'Integrate a verified grandchild checklist and a parent decision.',
    'requirements' => 'Read Project.status. With no child, create exactly one child using the complete LEAF_SPEC from context, then waitForChildren. Never create a second child. With no outbound decision for your current revision, call escalate asking your parent to choose the refresh cadence. Once your child is done and your decision is answered, report an integrated checklist citing the child ID and the cadence answer. Include FINAL_REVISION when present in context. Reuse the existing child after revision.',
    'context' => $facts . "\nLEAF_SPEC=" . \Agent\State::encode($leafSpec),
    'gates' => ['Report integrates the completed child checklist and cites its ID', 'Report uses the cadence from an answered parent decision', 'Report includes FINAL_REVISION when present in context'],
];
$rootSpec = [
    'title' => 'Recursive TUI acceptance root',
    'goal' => 'Deliver a 3-level recursive project and a final report citing verified child results.',
    'requirements' => 'Read Project.status first on every continuation. If you have no child, call addTodo using the complete CHILD_SPEC from context without dropping any fields, then waitForChildren. Answer inbound child decisions with "1 second"; this choice is authorized and needs no human question. When your only child becomes done at revision1, update its context to its existing context plus FINAL_REVISION, then waitForChildren. Do not revise again. Once that child is done at revision2 or higher, report its verified checklist, cadence answer, actual child and grandchild IDs, and FINAL_REVISION. Never perform the child assignment yourself or fabricate descendant IDs.',
    'context' => $facts . "\nYou are authorized to choose a refresh cadence of 1 second. CHILD_SPEC=" . \Agent\State::encode($childSpec),
    'gates' => ['Report cites an actual child and grandchild from the completed decomposition', 'Report states the refresh cadence answered by the root', 'Report includes the revised direct child result with FINAL_REVISION', 'Report integrates the independently verified direct child acceptance checklist'],
];

$project = Project::create($owner, [
    'title' => 'Live recursive smoke ' . $owner,
    'settings' => ['profile' => $profile, 'project_manual' => true, 'parameters' => ['max_rounds' => 12, 'max_tokens' => 8192]],
]);
echo "PROJECT_ID=" . $project->id . "\n";
$root = $project->addTodo($rootSpec);

$project->requestPreflight();
ProjectRunner::tick($project->id);
$row = $project->get();
record($row['preflight_status'] === 'ready' && $row['preflight_revision'] === $row['revision'], 'Preflight ready before human start');
if ($failures) {
    echo 'PREFLIGHT=' . json_encode($row['preflight_result']) . "\n";
    echo 'LIVE SMOKE FAILED PROJECT_ID=' . $project->id . "\n";
    exit(1);
}
$project->start($owner);
record($project->get()['status'] === 'running', 'Human start by script operator');

$deadline = microtime(true) + 600;
$ticks = 0;
$stoppedForQuestion = false;
$completed = false;
while (microtime(true) < $deadline && $ticks++ < 180) {
    $row = $project->get();
    if ($row['status'] === 'completed') { $completed = true; break; }
    if ($row['status'] === 'blocked') break;
    $openRoot = Database::row('SELECT id, question FROM project_decisions WHERE project_id = ? AND target_todo_id IS NULL AND answer IS NULL LIMIT 1', [$project->id]);
    if ($openRoot) { $stoppedForQuestion = true; echo "HUMAN_ROOT_QUESTION id={$openRoot['id']}: " . $openRoot['question'] . "\n"; break; }
    ProjectRunner::tick($project->id);
    foreach ($project->todos() as $todo) {
        if ($todo['status'] === 'working' && $todo['run_id']) {
            $run = Run::get($todo['run_id']);
            if ($run['status'] === 'queued') { Run::claim($todo['thread_id']); $run = Run::get($todo['run_id']); }
            if ($run['status'] === 'running') {
                try { Run::execute($todo['run_id']); }
                catch (\Throwable $error) {
                    // Run::execute persists the failure; let the scheduler reconcile it normally.
                    echo 'RUN_FAILURE todo=' . $todo['id'] . ' run=' . $todo['run_id'] . ': ' . $error->getMessage() . "\n";
                }
                break;
            }
        }
    }
    usleep(200000);
}

$todos = $project->todos();
$rootTodo = $todos[0];

// Depth = max ancestor chain over all todos.
$maxDepth = 0;
foreach ($todos as $t) {
    $d = 0; $c = $t['id'];
    while (($p = Database::row('SELECT parent_id FROM project_todos WHERE id = ? AND project_id = ?', [$c, $project->id])) && $p['parent_id']) { $c = $p['parent_id']; $d++; }
    if ($d > $maxDepth) $maxDepth = $d;
}

// Tool start names actually used by project task threads (events JOIN runs).
$toolStarts = [];
$runThreads = [];
foreach ($todos as $t) { if ($t['thread_id']) $runThreads[] = $t['thread_id']; }
if ($runThreads) {
    $ph = implode(',', array_fill(0, count($runThreads), '?'));
    $rows = Database::rows("SELECT e.data FROM events e JOIN runs r ON r.id = e.run_id WHERE r.thread_id IN ($ph) AND e.type = 'tool.start'", $runThreads);
    foreach ($rows as $r) { $d = json_decode($r['data'], true); if ($d && isset($d['name'])) $toolStarts[] = $d['name']; }
}
$expectedTools = ['Agent_Tools_Project_addTodo', 'Agent_Tools_Project_updateTodo', 'Agent_Tools_Project_escalate', 'Agent_Tools_Project_answerDecision'];
$hasTools = true;
foreach ($expectedTools as $t) { if (!in_array($t, $toolStarts, true)) $hasTools = false; }

// Per-node independent review: review.gates all passed + nonempty evidence, run_revision matches revision.
$allReviewed = true;
foreach ($todos as $t) {
    if ($t['status'] !== 'done') { $allReviewed = false; continue; }
    if ($t['run_revision'] === null || (int)$t['run_revision'] !== (int)$t['revision']) { $allReviewed = false; continue; }
    if (!isset($t['review']['gates']) || count($t['review']['gates']) !== count($t['gates'])) { $allReviewed = false; continue; }
    foreach ($t['review']['gates'] as $gate) if ($gate['passed'] !== true || trim($gate['evidence']) === '') $allReviewed = false;
}

$finalResult = is_string($rootTodo['result']) ? $rootTodo['result'] : '';
$citesChild = stripos($finalResult, 'FINAL_REVISION') !== false;
foreach ($todos as $todo) {
    if ($todo['parent_id'] !== null && !preg_match('/\b' . preg_quote((string)$todo['id'], '/') . '\b/', $finalResult)) $citesChild = false;
}

record($completed && !$stoppedForQuestion, 'Project completed without unanswered human root question');
record($maxDepth >= 2, 'Depth >= 3 (root + child + grandchild)');
record($hasTools, 'Tool start names Project_addTodo/updateTodo/escalate/answerDecision all used');
record(!preg_match('/Workspace|Delegate/', implode(' ', $toolStarts)), 'No workspace or delegation tool authority was introduced');
record($allReviewed, 'Per node current revision independent review all passed');
record((int)$rootTodo['attempts'] === 1, 'Root attempts 1');
record((int)$rootTodo['continuations'] > 0, 'Root continuations > 0');
record($citesChild, 'Root final report cites independently verified direct child result');
$revised = array_values(array_filter($todos, function ($todo) use ($root) { return (string)$todo['parent_id'] === (string)$root['id']; }));
record(count($revised) === 1 && (int)$revised[0]['revision'] >= 2 && strpos($revised[0]['result'], 'FINAL_REVISION') !== false, 'The root agent revised its own child and the current revision was reverified');

if ($failures) { echo "LIVE SMOKE FAILED (" . count($failures) . ") PROJECT_ID=" . $project->id . "\n"; exit(1); }
echo "LIVE SMOKE COMPLETE PROJECT_ID=" . $project->id . "\n";
