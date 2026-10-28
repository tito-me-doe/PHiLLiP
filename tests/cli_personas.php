<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/fixtures/cli_process.php';

$db = Agent\Database::connection();
$ids = [];
$marker = 'cli-personas-' . bin2hex(random_bytes(5));
$alice = Agent\Identity::authenticate('alice', 'phillip-demo');
$admin = Agent\Identity::authenticate('admin', 'phillip-demo');
$local = ['unix' => ['uid' => 12345, 'root' => false], 'user' => null, 'owner' => 'cli:0123456789ab:12345'];
$mapped = ['unix' => ['uid' => 12345, 'root' => false], 'user' => $alice, 'owner' => $alice['id']];
$mappedAdmin = array_replace($mapped, ['user' => $admin, 'owner' => $admin['id']]);

try {
    $result = cliProcess(['personas', '--help']);
    check($result['status'] === 0 && strpos($result['out'], 'Usage: ok-phill personas') === 0 && $result['err'] === '', 'Persona help is model-free, successful and clean on stdout');
    foreach ([['personas', 'save'], ['personas', 'show'], ['personas', 'list', 'extra'], ['personas', 'save', '{}', '--id', 'invalid']] as $args) {
        $result = cliProcess($args, '', true);
        check($result['status'] === 2 && $result['out'] === '' && strpos($result['err'], '[Error]') !== false, 'Malformed command arguments fail predictably without stdout contamination');
    }
    foreach (['{oops', '[]', '/etc/passwd', '{"name":"Missing prompt"}', '{"name":"Bad tools","prompt":"Hi","tools":["function () {}"]}', '{"name":"Bad contract","prompt":"Hi","contract":{"$ref":"file:///etc/passwd"}}'] as $input) {
        $result = cliProcess(['personas', 'save', '-'], $input, true);
        check($result['status'] === 2 && $result['out'] === '', 'Invalid persona input cannot save, execute a closure, or load server-side contract files');
    }
    $result = cliProcess(['personas', 'save', '-'], str_repeat('x', 1048577), true);
    check($result['status'] === 2 && $result['out'] === '' && strpos($result['err'], '1 MiB') !== false, 'Persona stdin has a bounded read');

    $definition = ['name' => ' ' . $marker . ' ', 'prompt' => "Keep this raw prompt.\n  Spaces stay.  ", 'tools' => ' fetch,Fetch ', 'contract' => ['answer' => ['type' => 'string']], 'owner_id' => $admin['id'], 'is_default' => true];
    $result = cliProcess(['personas', 'save', '-'], json_encode($definition), true);
    $saved = json_decode($result['out'], true);
    check($result['status'] === 0 && isset($saved['id']) && strpos($result['err'], '[Success]') !== false, 'Unmapped Unix users create a private saved persona with machine-readable JSON');
    $ids[] = $saved['id'];
    check($saved['name'] === $marker && $saved['prompt'] === $definition['prompt'] && $saved['visibility'] === 'private' && strpos($saved['owner_id'], 'cli:') === 0 && !$saved['is_default'], 'CLI persona ownership/default flags cannot be forged and names normalize without rewriting prompt content');
    check($saved['tools'] === ['Agent\\Tools\\Fetch'] && $saved['contract']['answer']['type'] === 'string', 'CLI uses shared named-tool and response-contract normalization');
    foreach ([$marker, (string)$saved['id']] as $reference) {
        $result = cliProcess(['personas', 'show', $reference], '', true);
        check($result['status'] === 0 && json_decode($result['out'], true) === $saved, 'Saved persona is readable by its owner using a name or ID');
    }
    $result = cliProcess(['personas', 'list'], '', true);
    check($result['status'] === 0 && in_array($saved['id'], array_column(json_decode($result['out'], true), 'id'), true), 'Visible persona listings include the current Unix owner\'s private persona');
    $result = cliProcess(['--debug', 'personas', 'save', '{"system_prompt":"Updated raw prompt"}', '--id', (string)$saved['id']], '', true);
    $updated = json_decode($result['out'], true);
    check($result['status'] === 0 && $updated['prompt'] === 'Updated raw prompt' && $updated['tools'] === $saved['tools'] && $updated['contract'] === $saved['contract'] && strpos($result['err'], '[Debug]') !== false, 'Partial CLI updates preserve omitted defaults, accept the shared prompt alias, and keep debug off stdout');
    $terminalText = "Literal control text: \xc2\x9b31m\xe2\x80\xae stays data";
    $result = cliProcess(['personas', 'save', json_encode(['prompt' => $terminalText]), '--id', (string)$saved['id']], '', true);
    check($result['status'] === 0 && strpos($result['out'], "\xc2\x9b") === false && strpos($result['out'], "\xe2\x80\xae") === false && json_decode($result['out'], true)['prompt'] === $terminalText, 'CLI JSON preserves arbitrary prompt text while keeping C1 controls and bidi markers inert in terminals');
    $updated = json_decode($result['out'], true);

    foreach (['team', 'organization'] as $visibility) {
        $result = cliProcess(['personas', 'save', json_encode(['visibility' => $visibility]), '--id', (string)$saved['id']], '', true);
        check($result['status'] === 1 && $result['out'] === '' && Agent\Personas::get($saved['id']) === $updated, 'Unmapped local identities cannot publish or mutate a private persona into ' . $visibility . ' visibility');
    }
    $foreignUid = posix_geteuid() === 34 ? 35 : 34;
    $foreignEnvironment = posix_geteuid() === 0 ? ['PHILLIP_TEST_UID' => (string)$foreignUid] : [];
    $foreignPersona = $updated;
    if (posix_geteuid() !== 0) {
        // Exercise access against a foreign fixture owner while retaining the caller's real Unix identity.
        $foreignPersona['owner_id'] = preg_replace('/:[0-9]+$/D', ':' . $foreignUid, $saved['owner_id']);
        $db->prepare('UPDATE personas SET owner_id = ? WHERE id = ?')->execute([$foreignPersona['owner_id'], $saved['id']]);
    }
    try {
        $result = cliProcess(['personas', 'show', (string)$saved['id']], '', true, $foreignEnvironment);
        check($result['status'] === 1 && $result['out'] === '' && strpos($result['err'], 'access denied') !== false, 'A real Unix caller cannot inspect another UID\'s private persona');
        foreach ([['save', '{"prompt":"Intrusion"}', '--id', (string)$saved['id']], ['delete', (string)$saved['id']]] as $args) {
            $result = cliProcess(array_merge(['personas'], $args), '', true, $foreignEnvironment);
            check($result['status'] === 1 && $result['out'] === '' && strpos($result['err'], 'access denied') !== false && Agent\Personas::get($saved['id']) === $foreignPersona, 'A real Unix caller cannot modify or delete another UID\'s private persona');
        }
    } finally {
        if (posix_geteuid() !== 0) $db->prepare('UPDATE personas SET owner_id = ? WHERE id = ?')->execute([$saved['owner_id'], $saved['id']]);
    }
    $shared = Agent\Personas::save($alice['id'], ['name' => $marker . '-shared', 'prompt' => 'Organization prompt', 'visibility' => 'organization']); $ids[] = $shared['id'];
    $result = cliProcess(['personas', 'show', $shared['name']]);
    check($result['status'] === 0 && json_decode($result['out'], true)['id'] === $shared['id'], 'The current Unix caller may read organization personas without receiving tools');
    if (posix_geteuid() === 0) {
        foreach ([['save', json_encode(['name' => $marker . '-root', 'prompt' => 'No'])], ['delete', (string)$shared['id']]] as $args) {
            $result = cliProcess(array_merge(['personas'], $args));
            check($result['status'] === 1 && $result['out'] === '' && strpos($result['err'], 'Root') !== false, 'Root cannot write persona defaults or delete a persona');
        }
    } else {
        $result = cliProcess(['personas', 'save', json_encode(['name' => $marker . '-native', 'prompt' => 'Native owner'])]);
        $native = json_decode($result['out'], true);
        if (isset($native['id'])) $ids[] = $native['id'];
        check($result['status'] === 0 && $native['owner_id'] === $saved['owner_id'] && $native['visibility'] === 'private' && !$native['is_default'], 'A native unprivileged CLI creates only its own private persona');
        $result = cliProcess(['personas', 'delete', (string)$native['id']]);
        check($result['status'] === 0 && (int)json_decode($result['out'], true)['deleted'] === $native['id'], 'A native unprivileged CLI deletes its own private persona');
        $result = cliProcess(['personas', 'delete', (string)$shared['id']]);
        check($result['status'] === 1 && $result['out'] === '' && Agent\Personas::get($shared['id']) === $shared, 'A native unprivileged CLI cannot delete another owner\'s organization persona');
    }
    $result = cliProcess(['personas', 'save', '{"prompt":"No"}', '--id', (string)$shared['id']], '', true);
    check($result['status'] === 1 && $result['out'] === '' && Agent\Personas::get($shared['id']) === $shared, 'Unmapped Unix users may select but cannot edit organization personas');

    $private = Agent\Personas::saveLocal($mapped, ['name' => $marker . '-mapped', 'prompt' => 'Mapped owner']); $ids[] = $private['id'];
    check($private['owner_id'] === $alice['id'], 'Mapped CLI persona ownership uses the authoritative ERP user');
    $team = Agent\Personas::save($alice['id'], ['name' => $marker . '-team', 'prompt' => 'Team only', 'visibility' => 'team', 'team_id' => 'engineering']); $ids[] = $team['id'];
    $result = cliProcess(['personas', 'list'], '', true);
    $visibleIds = array_column(json_decode($result['out'], true), 'id');
    check($result['status'] === 0 && in_array($saved['id'], $visibleIds, true) && in_array($shared['id'], $visibleIds, true) && !in_array($private['id'], $visibleIds, true) && !in_array($team['id'], $visibleIds, true), 'Local persona listings include only own-private and organization resources, never ERP-private or team resources');
    expectException(function () use ($mappedAdmin, $private) { Agent\Personas::saveLocal($mappedAdmin, ['prompt' => 'Denied'], $private['id']); }, 'Private persona');
    expectException(function () use ($mappedAdmin, $private) { Agent\Personas::deleteLocal($mappedAdmin, $private['id']); }, 'access denied');
    Agent\Personas::saveLocal($mappedAdmin, ['prompt' => 'Administrator shared update'], $shared['id']);
    check(Agent\Personas::get($shared['id'])['prompt'] === 'Administrator shared update', 'Mapped administrators retain existing shared-persona edit permissions');
    expectException(function () use ($local, $private) { Agent\Personas::saveLocal($local, ['prompt' => 'No'], $private['id']); }, 'access denied');
    expectException(function () use ($local) { Agent\Personas::saveLocal($local, ['name' => 'No', 'prompt' => 'No', 'visibility' => 'team']); }, 'private');

    $result = cliProcess(['personas', 'delete', $marker], '', true);
    check($result['status'] === 0 && (string)json_decode($result['out'], true)['deleted'] === (string)$saved['id'], 'Owner deletes a persona by its unique visible name');
    $result = cliProcess(['personas', 'show', (string)$saved['id']], '', true);
    check($result['status'] === 1 && $result['out'] === '', 'Deleted personas are no longer selectable');
} finally {
    foreach ($ids as $id) $db->prepare('DELETE FROM personas WHERE id = ?')->execute([$id]);
}
