<?php
require dirname(__DIR__, 2) . '/bootstrap.php';
$mode = $argv[1]; $resource = $argv[2]; $count = (int)$argv[3];
if ($mode === 'events') {
    for ($i = 0; $i < $count; $i++) Agent\Run::event((int)$resource, ['type' => 'tool.progress', 'data' => ['message' => 'Concurrent progress ' . $i]]);
} else {
    $state = Agent\State::open('fixture.concurrent', $resource);
    for ($i = 0; $i < $count; $i++) {
        do {
            $row = $state->read();
            try { $state->patch(['counter' => $row['value']['counter'] + 1], $row['revision']); $retry = false; }
            catch (Agent\StateConflict $e) { $retry = true; }
        } while ($retry);
    }
}
