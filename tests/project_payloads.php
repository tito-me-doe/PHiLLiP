<?php
require __DIR__ . '/helpers.php';

$owner = 'cli:project-payloads-' . bin2hex(random_bytes(5));
$project = null;
$marker = sys_get_temp_dir() . '/project-inert-' . bin2hex(random_bytes(8));
$phpText = '<?php file_put_contents(' . var_export($marker, true) . ', "executed");';
$shellText = 'touch ' . escapeshellarg($marker);
$fields = ['title' => 'Structured child', 'goal' => 'Store and inspect task data', 'requirements' => ['Keep the supplied text', $phpText], 'context' => ['No execution is authorized', $shellText], 'gates' => ['Task data remains inert']];
$invoke = function ($registry, $name, array $arguments) {
    return $registry->execute(['id' => 'payload-fixture-' . bin2hex(random_bytes(4)), 'function' => ['name' => $name, 'arguments' => json_encode($arguments)]]);
};
try {
    $project = Agent\Project::create($owner, ['title' => 'Payload normalization', 'settings' => ['tools' => [], 'project_manual' => true]]);
    $root = $project->addTodo([(object)$fields]);
    check($root['requirements'] === implode("\n", $fields['requirements']) && $root['context'] === implode("\n", $fields['context']), 'Planning API unwraps a singleton named object and joins flat text lists');
    $root = $project->updateTodo($root['id'], (object)['context' => ['Revised context', $shellText]]);
    check($root['context'] === "Revised context\n" . $shellText, 'Planning updates share the canonical payload normalization');
    foreach ([[$fields, $fields], [['requirements' => [['ambiguous']]]], [['requirements' => ['text', 3]]], [['context' => ['named' => 'ambiguous']]]] as $bad) {
        expectException(function () use ($project, $bad) { $project->addTodo($bad); }, count($bad) > 1 ? 'ambiguous' : 'flat list of strings');
    }
    expectException(function () use ($project, $fields) { $project->addTodo([(object)($fields + ['status' => 'done'])]); }, 'protected todo field');
    expectException(function () use ($project, $fields) { $project->addTodo([(object)($fields + ['owner_id' => 'forged'])]); }, 'protected todo field');
    $thread = Agent\Thread::create($owner, 'Structured execution fixture', ['manual' => true]);
    $run = $thread->enqueue($owner, 'Use only structured Project tools');
    Agent\Run::claim($thread->id);
    Agent\Database::execute("UPDATE projects SET status = 'running' WHERE id = ?", [$project->id]);
    Agent\Database::execute("UPDATE project_todos SET thread_id = ?, run_id = ?, status = 'working', run_revision = revision WHERE id = ?", [$thread->id, $run['id'], $root['id']]);
    $registry = new Agent\ToolRegistry();
    foreach (Agent\ProjectRunner::safeTools(['Workspace', 'Delegate', 'CLI', 'SQL']) as $tool) $registry->register($tool);
    Agent\ToolContext::withValues(['thread_id' => $thread->id, 'run_id' => $run['id']], function () use ($registry, $invoke, $fields, $root, $project, $phpText, $shellText, $marker) {
        // json_decode in the reflected registry produces exactly the observed [stdClass] shape.
        $result = $invoke($registry, 'Agent_Tools_Project_addTodo', ['todo' => [(object)($fields + ['parent_id' => (string)$root['id']])]]);
        $child = json_decode($result['content'], true);
        check(!$result['_tool_error'] && (string)$child['parent_id'] === (string)$root['id'] && (string)$child['creator_todo_id'] === (string)$root['id'], 'Real reflected tool accepts singleton object and redundant exact parent without widening scope');
        check(strpos($child['requirements'], $phpText) !== false && strpos($child['context'], $shellText) !== false && !file_exists($marker), 'Stored PHP and shell syntax remain inert task text');
        $changed = $invoke($registry, 'Agent_Tools_Project_updateTodo', ['todoId' => $child['id'], 'changes' => [(object)['requirements' => ['Updated requirement', $phpText], 'context' => [$shellText]]]]);
        $updated = json_decode($changed['content'], true);
        check(!$changed['_tool_error'] && $updated['requirements'] === "Updated requirement\n" . $phpText && $updated['context'] === $shellText, 'Real reflected updates use identical shape and text normalization');
        foreach ([null, (int)$root['id'] + 100000, true] as $parent) {
            $denied = $invoke($registry, 'Agent_Tools_Project_addTodo', ['todo' => [$fields + ['parent_id' => $parent]]]);
            check($denied['_tool_error'], 'Null, foreign and boolean parent identities remain rejected');
        }
        foreach (['status' => 'done', 'owner_id' => 'forged', 'creator_todo_id' => $root['id']] as $field => $value) {
            $denied = $invoke($registry, 'Agent_Tools_Project_addTodo', ['todo' => [$fields + [$field => $value]]]);
            check($denied['_tool_error'], 'Normalization preserves protected field rejection: ' . $field);
        }
        $self = $invoke($registry, 'Agent_Tools_Project_updateTodo', ['todoId' => $root['id'], 'changes' => [['context' => ['Rewrite my own assignment']]]]);
        check($self['_tool_error'], 'Forgiving input shapes never grant assignment editing authority');
        foreach (['Agent_Tools_Workspace_run', 'Agent_Tools_Delegate_assign'] as $name) {
            check($registry->source($name) === null, 'Execution tool ceiling does not register ' . $name);
            $denied = $invoke($registry, $name, ['command' => ['sh', '-c', $shellText]]);
            check($denied['_tool_error'] && strpos($denied['content'], 'Unknown tool:') !== false && !file_exists($marker), 'Unregistered execution/delegation request has no authority or side effects');
        }
    });
    check(!file_exists($marker), 'The full structured task workflow never executes stored task text');
} finally {
    if ($project) {
        foreach ($project->todos() as $todo) if ($todo['thread_id'] !== null) Agent\Database::execute('DELETE FROM threads WHERE id = ? AND owner_id = ?', [$todo['thread_id'], $owner]);
        Agent\Database::execute('DELETE FROM threads WHERE id = ? AND owner_id = ?', [$project->get()['thread_id'], $owner]);
    }
}
