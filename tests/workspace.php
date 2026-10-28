<?php
require __DIR__ . '/helpers.php';
if (Agent\Runtime::unixIdentity()['root']) { posix_setgid(33); posix_setuid(33); }
Agent\Log::configure(['file' => Agent\Log::OFF]);
$directory = sys_get_temp_dir() . '/phillip-workspace-' . bin2hex(random_bytes(6));
if (!mkdir($directory, 0700)) throw new RuntimeException('Cannot allocate isolated test workspace');
$previous = getcwd(); chdir($directory);
$identity = Agent\Cli::identity();
$thread = Agent\Thread::create($identity['owner'], 'Workspace grants test');
$other = Agent\Thread::create($identity['owner'], 'Workspace separate grants test');
$context = ['thread_id' => $thread->id];
try {
    file_put_contents($directory . '/example.php', "<?php\necho 'before';\n");
    foreach (['../escape.php', '/etc/passwd', '.env', 'config/secrets.php', "bad\0path"] as $path) expectException(function () use ($path) { Agent\WorkspaceAccess::path($path); }, $path === 'config/secrets.php' || $path === '.env' || $path === '../escape.php' ? 'Private or invalid' : 'workspace-relative');
    symlink($directory . '/example.php', $directory . '/link.php');
    expectException(function () { Agent\WorkspaceAccess::path('link.php'); }, 'symlinks');
    Agent\ToolContext::withValues($context, function () {
        expectException(function () { Agent\Tools\Workspace::replace('example.php', 'before', 'after'); }, 'approval required');
    });
    Agent\WorkspaceAccess::approve($thread->id, 'read', 'example.php');
    Agent\ToolContext::withValues($context, function () {
        expectException(function () { Agent\Tools\Workspace::replace('example.php', 'before', 'after'); }, 'approval required');
    });
    Agent\WorkspaceAccess::approve($thread->id, 'patch', 'example.php');
    Agent\WorkspaceAccess::approve($thread->id, 'read', 'example.php');
    Agent\ToolContext::withValues($context, function () {
        $result = Agent\Tools\Workspace::replace('example.php', 'before', 'after');
        check(strpos(file_get_contents('example.php'), "'after'") !== false && $result['sha256'] === hash_file('sha256', 'example.php'), 'Approved patches update the exact file and report verifiable output');
        expectException(function () { Agent\Tools\Workspace::replace('example.php', 'missing', 'after'); }, 'exactly once');
        expectException(function () { Agent\Tools\Workspace::replace('example.php', '', 'after'); }, 'cannot be empty');
        expectException(function () { Agent\Tools\Workspace::replace('example.php', 'after', "bad\0text"); }, 'UTF-8');
    });
    Agent\WorkspaceAccess::revoke($thread->id, 'patch', 'example.php');
    Agent\ToolContext::withValues($context, function () {
        expectException(function () { Agent\Tools\Workspace::replace('example.php', 'after', 'revoked'); }, 'approval required');
        check(strpos(file_get_contents('example.php'), "'after'") !== false, 'Revoking a file grant prevents subsequent mutations');
    });
    Agent\ToolContext::withValues(['thread_id' => $other->id], function () {
        expectException(function () { Agent\Tools\Workspace::replace('example.php', 'after', 'other'); }, 'approval required');
    });
    Agent\ToolContext::withValues(['thread_id' => $thread->id, 'run_id' => 123], function () use ($thread) {
        expectException(function () use ($thread) { Agent\WorkspaceAccess::approve($thread->id, 'patch', 'other.php'); }, 'cannot approve');
    });
    Agent\WorkspaceAccess::approve($thread->id, 'patch', 'new.php');
    Agent\ToolContext::withValues($context, function () {
        Agent\Tools\Workspace::create('new.php', '<?php return true;');
        expectException(function () { Agent\Tools\Workspace::create('new.php', 'overwrite'); }, 'Cannot create');
        check(file_get_contents('new.php') === '<?php return true;', 'Create never overwrites an existing file');
        $original = file_get_contents('new.php');
        expectException(function () { Agent\Tools\Workspace::write('new.php', 'unconditional'); }, 'requires its current SHA-256');
        expectException(function () { Agent\Tools\Workspace::write('new.php', 'bad hash', str_repeat('0', 64)); }, 'does not match');
        check(file_get_contents('new.php') === $original, 'Failed hash checks never modify the file');
        $hash = hash('sha256', $original);
        $result = Agent\Tools\Workspace::write('new.php', '<?php return false;', $hash);
        check($result['sha256'] === hash_file('sha256', 'new.php'), 'Hash-guarded overwrite reports its verifiable new content');
        expectException(function () use ($hash) { Agent\Tools\Workspace::write('new.php', 'stale writer', $hash); }, 'does not match');
    });
    Agent\ToolContext::withValues(['thread_id' => $other->id], function () {
        expectException(function () { Agent\Tools\Workspace::write('new.php', 'wrong thread', hash_file('sha256', 'new.php')); }, 'approval required');
    });
    Agent\WorkspaceAccess::approve($thread->id, 'patch', 'invalid.php');
    Agent\WorkspaceAccess::approve($thread->id, 'patch', 'php-script');
    Agent\ToolContext::withValues($context, function () {
        expectException(function () { Agent\Tools\Workspace::create('invalid.php', '<?php class Truncated {'); }, 'Refusing invalid PHP');
        check(!file_exists('invalid.php'), 'Invalid PHP creation never publishes a partial file');
        expectException(function () { Agent\Tools\Workspace::write('php-script', "#!/usr/bin/env php\n<?php class Truncated {"); }, 'Refusing invalid PHP');
        check(!file_exists('php-script'), 'Extensionless PHP CLI scripts receive the same parse protection');
        $before = file_get_contents('new.php'); $hash = hash('sha256', $before);
        expectException(function () use ($hash) { Agent\Tools\Workspace::write('new.php', '<?php class Truncated {', $hash); }, 'Refusing invalid PHP');
        expectException(function () { Agent\Tools\Workspace::replace('new.php', 'return false;', 'class Truncated {'); }, 'Refusing invalid PHP');
        check(file_get_contents('new.php') === $before && hash_file('sha256', 'new.php') === $hash, 'Rejected PHP overwrites and replacements preserve the original file and hash');
        Agent\Tools\Workspace::write('invalid.php', "<?php file_put_contents('parse-side-effect', 'bad');");
        check(!file_exists('parse-side-effect'), 'PHP validation parses source without executing it');
    });
    Agent\WorkspaceAccess::approve($thread->id, 'patch', 'created.php');
    Agent\ToolContext::withValues($context, function () {
        Agent\Tools\Workspace::write('created.php', '');
        check(file_exists('created.php') && filesize('created.php') === 0, 'Hash-guarded interface can exclusively create an empty file');
        Agent\Tools\Workspace::write('created.php', 'filled', hash('sha256', ''));
        check(file_get_contents('created.php') === 'filled', 'Empty existing files can be replaced with their correct hash');
    });
    expectException(function () { Agent\WorkspaceAccess::command('php -l example.php'); }, 'argv list');
    expectException(function () { Agent\WorkspaceAccess::command(['php', function () {}]); }, 'bounded strings');
    $literal = ['php', '-r', 'echo $argv[1];', '; touch SHOULD_NOT_EXIST'];
    Agent\WorkspaceAccess::approve($thread->id, 'command', $literal);
    Agent\ToolContext::withValues($context, function () use ($literal) {
        $result = Agent\Tools\Workspace::run($literal);
        check($result['exit_code'] === 0 && $result['stdout'] === '; touch SHOULD_NOT_EXIST' && !file_exists('SHOULD_NOT_EXIST'), 'Command argv preserves literal shell metacharacters without executing them');
        $later = $literal; $later[3] = 'different data';
        check(Agent\Tools\Workspace::run($later)['stdout'] === 'different data', 'Command grants match the first three words, allowing later argument changes');
        expectException(function () { Agent\Tools\Workspace::run(['php', '-r', 'echo "different code";']); }, 'approval required');
    });
    $failure = ['php', '-r', 'fwrite(STDERR, "known failure"); exit(7);'];
    $slow = ['php', '-r', 'sleep(5);'];
    $big = ['php', '-r', 'echo str_repeat("x", 100000);'];
    foreach ([$failure, $slow, $big] as $command) Agent\WorkspaceAccess::approve($thread->id, 'command', $command);
    Agent\ToolContext::withValues($context, function () use ($failure, $slow, $big) {
        $result = Agent\Tools\Workspace::run($failure);
        check($result['exit_code'] === 7 && $result['stderr'] === 'known failure', 'Command failures retain exit code and stderr for agent feedback');
        $started = microtime(true); $result = Agent\Tools\Workspace::run($slow, 1);
        check($result['timed_out'] && microtime(true) - $started < 3, 'Command timeout terminates promptly');
        $result = Agent\Tools\Workspace::run($big);
        check($result['truncated'] && strlen($result['stdout']) === 32768, 'Command output is bounded without blocking its process');
    });
    Agent\ToolContext::withValues(array_replace($context, ['control' => function () { return 2; }]), function () use ($slow) {
        expectException(function () use ($slow) { Agent\Tools\Workspace::run($slow); }, 'cancelled');
    });
    Agent\ToolContext::withValues(['thread_id' => $thread->id, 'frontend' => true], function () { expectException(function () { Agent\Tools\Workspace::create('new.php', 'blocked'); }, 'unprivileged local CLI'); });
    $thread->configure(['manual' => true]);
    $run = $thread->enqueue($identity['owner'], 'Local process cleanup fixture');
    Agent\Run::claim($thread->id);
    $process = proc_open('exec /usr/bin/setsid php -r ' . escapeshellarg('sleep(30);'), [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
    $pid = proc_get_status($process)['pid'];
    try {
        usleep(100000);
        Agent\ToolContext::withValues(['thread_id' => $thread->id, 'run_id' => $run['id']], function () use ($pid) { Agent\WorkspaceAccess::trackProcess($pid); });
        Agent\WorkflowState::rebuild($run['id']);
        Agent\WorkspaceAccess::stopProcesses($run['id']);
        $until = microtime(true) + 2;
        do { usleep(10000); $running = proc_get_status($process)['running']; } while ($running && microtime(true) < $until);
        check(!$running, 'Durable local process ownership survives projection rebuilds and allows forced cleanup');
    } finally {
        @posix_kill(-$pid, SIGKILL); proc_terminate($process, 9); proc_close($process);
        Agent\Run::finishStopped($run['id'], 'Fixture cleaned up');
    }
} finally {
    $thread->delete(); $other->delete();
    foreach (['link.php', 'example.php', 'new.php', 'created.php', 'invalid.php', 'php-script', 'SHOULD_NOT_EXIST'] as $file) if (file_exists($directory . '/' . $file) || is_link($directory . '/' . $file)) unlink($directory . '/' . $file);
    chdir($previous); rmdir($directory);
}
