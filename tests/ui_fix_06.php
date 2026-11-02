<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/fixtures/WebFixture.php';

/** Regression: ToDo Work execution threads render read-only; project planning root stays writable.
 *
 * Covers:
 *  - a child execution thread (todo with its own thread) renders read_only in Views::thread
 *  - the project planning thread (root) is not read_only
 *  - server rejection remains authoritative (Frontend::send on a read-only thread is refused)
 *  - browser: read-only banner + disabled composer + project plan link on execution thread
 *  - browser: planning thread composer remains enabled
 */
$web = new WebFixture();
try {
    $admin = Agent\Installation::install(['auth' => 'standalone'], 'admin', 'phillip-demo', 'Administrator');
    $alice = Agent\Identity::createUser('alice', 'phillip-demo', 'Alice');
    $aliceAuth = Agent\Identity::authenticate('alice', 'phillip-demo');

    Agent\Identity::grant('user', $aliceAuth['id'], 'page.conversations');
    Agent\Identity::grant('user', $aliceAuth['id'], 'page.projects');

    // Enable a chat profile so the composer is not disabled for missing profiles.
    $profile = Agent\Profiles::save('default', ['url' => 'http://127.0.0.1:1/v1/chat/completions', 'model' => 'fixture', 'stream' => true]);
    Agent\Database::execute('UPDATE profiles SET enabled=TRUE,supports_chat=TRUE WHERE id=?', [$profile['id']]);

    $threads = [];
    try {
        // 1. Create a project; its planning thread is the writable root.
        $project = Agent\Project::create($aliceAuth['id'], ['title' => 'Read-only regression project', 'settings' => ['manual' => true]]);
        $threads[] = $project->get()['thread_id'];
        $planningThreadId = $project->get()['thread_id'];

        // 2. Create a child execution thread and link it to a todo in the project.
        $todo = $project->addTodo(['title' => 'Execution child', 'goal' => 'Goal', 'requirements' => 'Req', 'context' => 'Ctx', 'gates' => ['Gate']]);
        $childThread = Agent\Thread::create($aliceAuth['id'], 'Child execution thread', ['manual' => true]);
        $threads[] = $childThread->id;
        Agent\Database::execute('UPDATE project_todos SET thread_id = ? WHERE id = ?', [$childThread->id, $todo['id']]);

        // 3. Views::thread marks the child execution thread read_only, the planning thread not.
        $state = ['user' => $aliceAuth, 'csrf' => bin2hex(random_bytes(24)), 'profiles' => [], 'tools' => [], 'teams' => []];
        $child = Agent\Views::thread($state, $childThread->id);
        check(!empty($child['thread']['read_only']), 'Child execution thread is read_only');
        $plan = Agent\Views::thread($state, $planningThreadId);
        check(empty($plan['thread']['read_only']), 'Project planning thread is writable (not read_only)');

        // 4. Server rejection remains authoritative: Frontend::send on a read-only thread is refused.
        $frontend = new Agent\Frontend($aliceAuth);
        expectException(function () use ($frontend, $childThread) {
            $frontend->send($childThread->id, ['content' => 'Should be rejected', 'submission_key' => bin2hex(random_bytes(16))]);
        }, 'read-only');

        // 5. Browser: open the child execution thread and verify read-only UI.
        $web->server(dirname(__DIR__) . '/public/index.php', true);
        $base = $web->base;

        putenv('FIX06_BASE=' . $base);
        putenv('FIX06_USERNAME=alice');
        putenv('FIX06_PASSWORD=phillip-demo');
        putenv('FIX06_CHILD_THREAD=' . $childThread->id);
        putenv('FIX06_PLANNING_THREAD=' . $planningThreadId);
        putenv('PYTHONPATH=/home/bfoxwell/.local/share/codex-tools/agent-ui-test/python');
        putenv('PLAYWRIGHT_BROWSERS_PATH=/home/bfoxwell/.local/share/codex-tools/agent-ui-test/browsers');

        $browser = $web->launch(['python3', __DIR__ . '/ui_fix_06.py'], true);
        $exit = proc_close($browser);
        if ($exit !== 0) {
            fwrite(STDERR, "Browser test exited with code $exit\n");
            fwrite(STDERR, $web->diagnostics() . "\n");
        }
        check($exit === 0, 'Browser read-only regression passed');
    } finally {
        foreach ($threads as $threadId) {
            try {
                (new Agent\Thread($threadId))->delete();
            } catch (\Throwable $e) {
                // Thread may already have been removed.
            }
        }
    }
} finally {
    $web->close();
}
echo "ALL PASS\n";
