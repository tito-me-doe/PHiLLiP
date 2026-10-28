<?php
// OpenAI-compatible fixture; mutations are real Project tool calls.
header('Content-Type: application/json');
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) { http_response_code(400); echo '{}'; return; }
$payload = []; $turn = [];
foreach ($body['messages'] as $message) {
    if ($message['role'] === 'user') {
        $decoded = json_decode($message['content'], true);
        if (is_array($decoded) && isset($decoded['kind'])) { $payload = $decoded; $turn = []; }
    } else $turn[] = $message;
}
$kind = isset($payload['kind']) ? $payload['kind'] : '';
if ($kind !== 'project.todo' && !empty($body['tools'])) { http_response_code(400); echo '{"error":"Checker received tools"}'; return; }
$reply = function ($content, $calls = []) {
    $message = ['role' => 'assistant', 'content' => $content];
    if ($calls) $message['tool_calls'] = $calls;
    echo json_encode(['choices' => [['message' => $message, 'finish_reason' => $calls ? 'tool_calls' : 'stop']]]);
};
if ($kind === 'project.preflight') { $reply('ready'); return; }
if ($kind === 'project.todo_preflight') {
    if ($payload['todo']['title'] === 'Slow readiness child') usleep(1500000);
    if ($payload['todo']['title'] === 'Child readiness loop' && !empty($payload['decisions']['inbound'])) { $reply('Additional parent acceptance context required.'); return; }
    $reply(strpos($payload['todo']['context'], 'NOT_READY') !== false ? 'Acceptance context missing.' : 'ready'); return;
}
if ($kind === 'project.gate_review') {
    if (strpos($payload['todo']['title'], 'Slow review') !== false) usleep(1500000);
    $gates = [];
    foreach ($payload['todo']['gates'] as $index => $gate) {
        $passed = strpos($gate, 'ALWAYS_FAIL') === false && (strpos($gate, 'RETRY_ONCE') === false || $payload['attempt'] > 1);
        $gates[] = ['index' => $index, 'passed' => $passed, 'evidence' => $passed ? 'Verified fixture output: ' . $payload['output'] : 'Parent integration requires correction.'];
    }
    $reply(json_encode(['gates' => $gates])); return;
}
if ($kind !== 'project.todo') { $reply('Unknown request'); return; }
$todo = $payload['todo']; $title = $todo['title']; $children = $payload['completed_children'];
$decisions = isset($payload['decisions']) ? $payload['decisions'] : ['inbound' => [], 'outbound' => []];
$actions = [];
$act = function ($method, array $args = []) use (&$actions) { $actions[] = ['name' => 'Agent_Tools_Project_' . $method, 'arguments' => json_encode((object)$args)]; };
$child = function ($title, $context = 'Complete the deterministic recursive fixture.') {
    return ['title' => $title, 'goal' => 'Produce a verified recursive deliverable', 'requirements' => 'Use Project reports and scoped decisions', 'context' => $context, 'gates' => ['Verified fixture deliverable']];
};
$open = array_values(array_filter($decisions['inbound'], function ($d) { return $d['answer'] === null; }));
$answered = array_values(array_filter($decisions['outbound'], function ($d) { return $d['answer'] !== null; }));
if (in_array($title, ['Root recursive', 'Root readiness', 'Root child failure', 'Root slow readiness', 'Root gate budget', 'Root readiness loop'], true)) {
    if (!$children) {
        $name = $title === 'Root slow readiness' ? 'Slow readiness child' : ($title === 'Root readiness' ? 'Readiness child' : ($title === 'Root child failure' ? 'Failure child' : 'Child recursive'));
        $spec = $child($name, $title === 'Root readiness' ? 'NOT_READY' : 'Complete the deterministic recursive fixture.');
        if ($title === 'Root readiness loop') $spec['title'] = 'Child readiness loop';
        if ($title === 'Root child failure' || $title === 'Root gate budget') { $spec['title'] = 'Failure child'; $spec['max_attempts'] = 1; $spec['gates'] = ['ALWAYS_FAIL']; }
        $act('addTodo', ['todo' => $spec]); $act('waitForChildren');
    } elseif ($open) {
        if ($title === 'Root readiness loop') $act('escalate', ['question' => 'Human context is required to revise the child readiness.']);
        elseif ($title === 'Root gate budget') {
            foreach ($open as $d) $act('answerDecision', ['decisionId' => (int)$d['id'], 'answer' => 'Please continue without changing your assignment.']);
            $act('escalate', ['question' => 'Should the exhausted child receive a revision?']);
        } elseif ($title !== 'Root recursive') {
            $act('updateTodo', ['todoId' => (int)$children[0]['id'], 'changes' => ['context' => 'Acceptance evidence supplied', 'gates' => ['Verified fixture deliverable']]]); $act('waitForChildren');
        } elseif (!$answered) $act('escalate', ['question' => 'Human: approve the fixture choice?', 'evidence' => ['source' => 'child-question']]);
        else {
            foreach ($open as $d) $act('answerDecision', ['decisionId' => (int)$d['id'], 'answer' => 'Approved by the human; use the fixture choice.']);
            $act('waitForChildren');
        }
    } elseif ($title === 'Root recursive' && $children[0]['status'] === 'done' && (int)$children[0]['revision'] === 1) {
        $act('updateTodo', ['todoId' => (int)$children[0]['id'], 'changes' => ['context' => 'Rework: independently verify the integrated result.']]); $act('waitForChildren');
    }
} elseif ($title === 'Child recursive' || $title === 'Child readiness loop') {
    if (!$children) { $act('addTodo', ['todo' => $child('Leaf recursive')]); $act('waitForChildren'); }
    elseif ($open && !$answered) {
        if (count($open) > 1) $act('answerDecision', ['decisionId' => (int)$open[0]['id'], 'answer' => 'First fixture value is resolved locally.']);
        $act('escalate', ['question' => 'Parent: obtain approval for the remaining leaf fixture choice.']);
    }
    elseif ($open) {
        foreach ($open as $d) $act('answerDecision', ['decisionId' => (int)$d['id'], 'answer' => 'Use the approved deterministic fixture choice.']);
        $act('waitForChildren');
    }
} elseif ($title === 'Leaf recursive' && (int)$todo['revision'] === 1 && !$decisions['outbound']) {
    $act('escalate', ['question' => 'Choose the first fixture value.']); $act('escalate', ['question' => 'Choose the second fixture value.']);
} elseif ($title === 'Yield forever') $act('waitForChildren');
elseif ($title === 'Batch yield root') {
    $act('addTodo', ['todo' => $child('Batch yield leaf')]);
    $act('waitForChildren');
    $act('report', ['result' => 'The complete batch ran before the engine yielded.', 'evidence' => ['batch' => 'all-tool-results']]);
}
if (!$actions) $act('report', ['result' => 'Verified recursive deliverable for ' . $title, 'evidence' => ['fixture' => 'actual-tool-output', 'revision' => $todo['revision']]]);
$used = 0;
foreach ($turn as $message) if ($message['role'] === 'tool') $used++;
if (($title === 'Batch yield root' || ($title === 'Leaf recursive' && count($actions) === 2)) && $used === 0) {
    $calls = [];
    foreach ($actions as $index => $action) $calls[] = ['id' => 'recursive-step-' . $index, 'type' => 'function', 'function' => $action];
    $reply(null, $calls); return;
}
if ($title === 'Batch yield root') { http_response_code(400); echo '{"error":"Yield must finish without another model call"}'; return; }
if (isset($actions[$used])) $reply(null, [['id' => 'recursive-step-' . $used, 'type' => 'function', 'function' => $actions[$used]]]);
else $reply('Verified recursive deliverable for ' . $title . '. Children: ' . count($children));
