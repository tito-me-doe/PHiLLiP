<?php
require __DIR__ . '/helpers.php';
if (Agent\Runtime::unixIdentity()['root']) { posix_setgid(33); posix_setuid(33); }
Agent\Log::configure(['user' => 3, 'admin' => 3, 'console' => 3, 'file' => 3, 'database' => 3]);
$directory = sys_get_temp_dir() . '/phillip-workspace-lines-' . bin2hex(random_bytes(6));
if (!mkdir($directory, 0700)) throw new RuntimeException('Cannot allocate isolated test workspace');
$previous = getcwd(); chdir($directory);
$identity = Agent\Cli::identity();
$thread = Agent\Thread::create($identity['owner'], 'Workspace line replacement test');
$other = Agent\Thread::create($identity['owner'], 'Workspace concurrent line replacement test');
$registry = new Agent\ToolRegistry();
$registry->register('Agent\Tools\Workspace::replaceLines');
$name = 'Agent_Tools_Workspace_replaceLines';
$invoke = function (array $arguments, $threadId = null) use ($registry, $name, $thread) {
    return Agent\ToolContext::withValues(['thread_id' => $threadId === null ? $thread->id : $threadId], function () use ($registry, $name, $arguments) {
        return $registry->execute(['id' => 'line-edit', 'function' => ['name' => $name, 'arguments' => json_encode($arguments)]]);
    });
};
$arguments = function ($path, $start, $end, $replacement) {
    return ['path' => $path, 'startLine' => $start, 'endLine' => $end, 'replacement' => $replacement, 'expectedSha256' => Agent\Tools\Source::read($path)['sha256']];
};
$failure = function ($args, $message, $threadId = null) use ($invoke) {
    $before = is_file($args['path']) ? file_get_contents($args['path']) : null;
    $result = $invoke($args, $threadId);
    check($result['_tool_error'] && strpos($result['content'], $message) !== false, 'Reflected tool rejects ' . $message);
    check(($before === null && !file_exists($args['path'])) || file_get_contents($args['path']) === $before, 'Rejected line edit preserves the original bytes');
};
try {
    $definition = $registry->definitions()[0]['function'];
    check($definition['name'] === $name && $definition['parameters']['required'] === ['path', 'startLine', 'endLine', 'replacement', 'expectedSha256'], 'Reflection exposes the exact line tool name and five required arguments');
    $properties = (array)$definition['parameters']['properties'];
    check($properties['startLine']['type'] === 'integer' && $properties['endLine']['type'] === 'integer', 'Reflected line bounds use integer arguments');

    file_put_contents('fixture.txt', "first\nsecond\nlast\n");
    $args = $arguments('fixture.txt', 2, 2, "updated\n");
    $failure($args, 'approval required');
    Agent\WorkspaceAccess::approve($thread->id, 'read', 'fixture.txt');
    $failure($args, 'approval required');
    Agent\WorkspaceAccess::approve($thread->id, 'patch', 'fixture.txt');
    $failure($args, 'approval required', $other->id);
    Agent\ToolContext::withValues(['thread_id' => $thread->id, 'frontend' => true], function () use ($registry, $name, $args) {
        $result = $registry->execute(['id' => 'frontend-line-edit', 'function' => ['name' => $name, 'arguments' => json_encode($args)]]);
        check($result['_tool_error'] && strpos($result['content'], 'unprivileged local CLI') !== false, 'Reflected line edits retain the local CLI boundary');
    });
    $missing = $args; unset($missing['expectedSha256']);
    $failure($missing, 'Missing tool argument: expectedSha256');
    foreach (['', 'bad', strtoupper(hash('sha256', 'different')), str_repeat('0', 64) . "\n"] as $hash) {
        $bad = $args; $bad['expectedSha256'] = $hash;
        $failure($bad, '64 lowercase hexadecimal');
    }
    $bad = $args; $bad['expectedSha256'] = str_repeat('0', 64);
    $failure($bad, 'does not match');
    foreach ([[0, 1], [-1, 1], [2, 1], [1, 4], [4, 4], [1, PHP_INT_MAX]] as $bounds) {
        $bad = $args; $bad['startLine'] = $bounds[0]; $bad['endLine'] = $bounds[1];
        $failure($bad, 'Line range');
    }
    foreach (["bad\0text", str_repeat('a', 2097153)] as $replacement) {
        $bad = $args; $bad['replacement'] = $replacement;
        $failure($bad, 'UTF-8');
    }
    Agent\ToolContext::withValues(['thread_id' => $thread->id], function () use ($args) {
        expectException(function () use ($args) { Agent\Tools\Workspace::replaceLines('fixture.txt', 2, 2, "bad\xff", $args['expectedSha256']); }, 'UTF-8');
        check(file_get_contents('fixture.txt') === "first\nsecond\nlast\n", 'Invalid UTF-8 outside the JSON transport also preserves the original bytes');
    });

    $cases = [
        ["first\nsecond\nlast\n", 2, 2, "new\nlines\n", "first\nnew\nlines\nlast\n"],
        ["first\r\nsecond\r\nlast\r\n", 2, 2, "new\r\n", "first\r\nnew\r\nlast\r\n"],
        ["α\r\nβ\nγ\rdangling\r\nlast", 2, 2, "雪\n", "α\r\n雪\nγ\rdangling\r\nlast"],
        ["one\rtwo\nlast\r", 1, 1, "new\n", "new\nlast\r"],
        ["first\nlast", 2, 2, '終', "first\n終"],
        ["first\nlast\n", 2, 2, '終', "first\n終\n"],
        ["first\r\nlast\r\n", 1, 1, 'new', "new\r\nlast\r\n"],
        ["first\r\nlast\n", 1, 2, 'new', "new\n"],
        ["first\nlast\n", 1, 1, '', "last\n"],
        ["first\nlast\n", 1, 1, 'literal\\n', "literal\\n\nlast\n"],
        ["\n\nlast\n", 2, 2, "middle\n", "\nmiddle\nlast\n"],
        ["first\nlast\n", 1, 2, '', ''],
        ['only', 1, 1, "replacement\n", "replacement\n"],
        ["\n", 1, 1, '', '']
    ];
    foreach ($cases as $index => $case) {
        file_put_contents('fixture.txt', $case[0]); chmod('fixture.txt', 0640); clearstatcache(true, 'fixture.txt');
        $result = $invoke($arguments('fixture.txt', $case[1], $case[2], $case[3]));
        $data = json_decode($result['content'], true);
        clearstatcache(true, 'fixture.txt');
        check(!$result['_tool_error'] && file_get_contents('fixture.txt') === $case[4], 'Exact LF range splice preserves newline and UTF-8 bytes, case ' . $index);
        check($data['path'] === 'fixture.txt' && $data['sha256'] === hash('sha256', $case[4]) && $data['bytes'] === strlen($case[4]) && (fileperms('fixture.txt') & 0777) === 0640, 'Line publication reports verifiable metadata and preserves file mode');
    }
    file_put_contents('fixture.txt', "first\r\nlast\r\n");
    Agent\Log::with(['file' => Agent\Log::WARNING, 'path' => $directory . '/warning.log'], function () use ($invoke, $arguments) {
        $result = $invoke($arguments('fixture.txt', 1, 1, 'replacement'));
        check(!$result['_tool_error'], 'A missing replacement terminator is repaired through the reflected tool');
    });
    $warnings = array_filter(array_map(function ($line) { return json_decode($line, true); }, explode("\n", trim(file_get_contents('warning.log')))), function ($record) {
        return $record['priority'] === Agent\Log::WARNING && strpos($record['message'], 'Preserved the selected line terminator') !== false;
    });
    check(count($warnings) === 1 && file_get_contents('fixture.txt') === "replacement\r\nlast\r\n", 'Terminator auto-repair emits one Warning and preserves CRLF');
    file_put_contents('fixture.txt', '');
    check(Agent\Tools\Source::read('fixture.txt')['total_lines'] === 0, 'Empty-file line count agrees with Source.read');
    $failure($arguments('fixture.txt', 1, 1, 'new'), 'Line range');
    Agent\WorkspaceAccess::approve($thread->id, 'patch', 'absent.txt');
    $bad = $args; $bad['path'] = 'absent.txt';
    $failure($bad, 'existing regular file');
    file_put_contents('fixture.txt', "bad\0content\n");
    $bad = $args; $bad['expectedSha256'] = hash_file('sha256', 'fixture.txt');
    $failure($bad, 'UTF-8');
    file_put_contents('fixture.txt', str_repeat('a', 2097153));
    $bad['expectedSha256'] = hash_file('sha256', 'fixture.txt');
    $failure($bad, 'UTF-8');

    Agent\WorkspaceAccess::approve($thread->id, 'patch', 'fixture.php');
    file_put_contents('fixture.php', "<?php\nreturn true;\n// exact trailing bytes\r\n");
    $failure($arguments('fixture.php', 2, 2, "class Truncated {\n"), 'Refusing invalid PHP');
    $result = $invoke($arguments('fixture.php', 2, 2, "file_put_contents('side-effect', 'bad');\n"));
    check(!$result['_tool_error'] && !file_exists('side-effect') && file_get_contents('fixture.php') === "<?php\nfile_put_contents('side-effect', 'bad');\n// exact trailing bytes\r\n", 'PHP edits parse without execution and preserve trailing bytes');

    file_put_contents('fixture.txt', "before\nuntouched tail\r\n");
    Agent\WorkspaceAccess::approve($other->id, 'patch', 'fixture.txt');
    $hash = Agent\Tools\Source::read('fixture.txt')['sha256'];
    $child = <<<'PHP'
require $argv[1];
Agent\Log::configure(['user' => 3, 'admin' => 3, 'console' => 3, 'file' => 3, 'database' => 3]);
chdir($argv[2]);
try {
    Agent\ToolContext::withValues(['thread_id' => (int)$argv[3]], function () use ($argv) {
        Agent\Tools\Workspace::replaceLines('fixture.txt', 1, 1, $argv[4] . "\n", $argv[5]);
    });
    echo 'updated';
} catch (Throwable $error) { echo $error->getMessage(); }
PHP;
    $processes = [];
    foreach ([$thread->id, $other->id] as $index => $threadId) {
        $command = [PHP_BINARY, '-r', $child, dirname(__DIR__) . '/bootstrap.php', $directory, (string)$threadId, 'writer-' . $index, $hash];
        $process = proc_open(implode(' ', array_map('escapeshellarg', $command)), [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot launch concurrent workspace writer');
        $processes[] = [$process, $pipes];
    }
    $outputs = [];
    foreach ($processes as $item) {
        $outputs[] = stream_get_contents($item[1][1]); $stderr = stream_get_contents($item[1][2]);
        fclose($item[1][1]); fclose($item[1][2]);
        check(proc_close($item[0]) === 0 && $stderr === '', 'Concurrent line writer exits cleanly');
    }
    check(count(array_filter($outputs, function ($output) { return $output === 'updated'; })) === 1 && count(array_filter($outputs, function ($output) { return strpos($output, 'does not match') !== false; })) === 1, 'Two independent writers using one hash permit exactly one publication');
    check(in_array(file_get_contents('fixture.txt'), ["writer-0\nuntouched tail\r\n", "writer-1\nuntouched tail\r\n"], true), 'Concurrent stale hashes preserve the winning content and untouched suffix');
    $bad = $args; $bad['expectedSha256'] = $hash;
    $failure($bad, 'does not match');
    Agent\WorkspaceAccess::revoke($thread->id, 'patch', 'fixture.txt');
    $failure($arguments('fixture.txt', 1, 1, "revoked\n"), 'approval required');
} finally {
    $thread->delete(); $other->delete();
    foreach (['fixture.txt', 'fixture.php', 'absent.txt', 'side-effect', 'warning.log'] as $file) if (file_exists($file)) unlink($file);
    chdir($previous); rmdir($directory);
}
