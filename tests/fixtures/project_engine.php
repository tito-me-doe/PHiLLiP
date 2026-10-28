<?php
header('Content-Type: application/json');
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) { http_response_code(400); echo '{"error":"JSON required"}'; return; }
if (isset($body['tools']) && $body['model'] === 'project-no-tools') { http_response_code(400); echo '{"error":"Unexpected tools"}'; return; }
if ($body['model'] === 'project-outage') { http_response_code(503); echo '{"error":"Fixture model outage"}'; return; }
$payload = [];
foreach ($body['messages'] as $message) if ($message['role'] === 'user') {
    $decoded = json_decode($message['content'], true);
    if (is_array($decoded) && isset($decoded['kind'])) $payload = $decoded;
}
$kind = isset($payload['kind']) ? $payload['kind'] : '';
if (in_array($kind, ['project.preflight', 'project.gate_review'], true) && !empty($body['tools'])) { http_response_code(400); echo '{"error":"Ephemeral checks must have no tools"}'; return; }
if ($kind === 'project.preflight') {
    if ($body['model'] === 'project-slow-preflight') usleep(1200000);
    $answer = strpos(json_encode($payload), 'PREFLIGHT_GAP') !== false ? 'Clarify the acceptance evidence required by PREFLIGHT_GAP.' : 'ready';
} elseif ($kind === 'project.gate_review') {
    $gates = [];
    foreach ($payload['todo']['gates'] as $index => $gate) {
        $passed = strpos($gate, 'ALWAYS_FAIL') === false && (strpos($gate, 'RETRY_ONCE') === false || $payload['attempt'] > 1);
        $gates[] = ['index' => $index, 'passed' => $passed, 'evidence' => $passed ? 'Fixture output contains the required deliverable: ' . $payload['output'] : 'Required verification is missing; supply it on the next attempt.'];
    }
    if (strpos(json_encode($payload['todo']), 'DUPLICATE_REVIEW') !== false && count($gates) > 1) $gates[1]['index'] = 0;
    $answer = json_encode(['gates' => $gates]);
} elseif ($kind === 'project.todo') {
    if (strpos($payload['todo']['title'], 'FAIL_RUN') !== false) { http_response_code(400); echo '{"error":"Fixture execution failed"}'; return; }
    if (strpos($payload['todo']['title'], 'REPORT_RESULT') !== false && end($body['messages'])['role'] !== 'tool') {
        echo json_encode(['choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'report-fixture', 'type' => 'function', 'function' => ['name' => 'Agent_Tools_Project_report', 'arguments' => json_encode(['result' => 'Durably reported fixture deliverable', 'evidence' => ['citation' => 'fixture:verified']])]]]], 'finish_reason' => 'tool_calls']]]);
        return;
    }
    $answer = 'Deliverable for ' . $payload['todo']['title'] . ': verified fixture result. Children: ' . count($payload['completed_children']);
} else $answer = 'Unknown fixture request';
echo json_encode(['choices' => [['message' => ['role' => 'assistant', 'content' => $answer], 'finish_reason' => 'stop']]]);
