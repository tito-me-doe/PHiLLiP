<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/fixtures/WebFixture.php';

putenv('PYTHONPATH=/home/bfoxwell/.local/share/codex-tools/agent-ui-test/python');
putenv('PLAYWRIGHT_BROWSERS_PATH=/home/bfoxwell/.local/share/codex-tools/agent-ui-test/browsers');
putenv('FIX05_THREAD_ID=');
$web = new WebFixture();
try {
    $admin = Agent\Installation::install(['auth' => 'standalone'], 'admin', 'phillip-demo', 'Administrator');
    $people = ['admin' => $admin];
    foreach (['alice', 'bob'] as $name) $people[$name] = Agent\Identity::createUser($name, 'phillip-demo', ucfirst($name));

    // Alice needs page.personas for save-as-persona and page.projects for the ToDo planner.
    $aliceAuth = Agent\Identity::authenticate('alice', 'phillip-demo');
    Agent\Identity::grant('user', $aliceAuth['id'], 'page.personas');
    Agent\Identity::grant('user', $aliceAuth['id'], 'page.projects');
    $engine = $web->server(__DIR__ . '/fixtures/onboarding_engine.php');

    $profile = Agent\Profiles::save('fix05-default', ['url' => $engine . '/v1/chat/completions', 'model' => 'onboarding-chat', 'stream' => true, 'max_tokens' => 1024]);
    Agent\Database::execute('UPDATE profiles SET enabled=TRUE,supports_chat=TRUE,supports_streaming=TRUE,supports_tools=TRUE WHERE id=?', [$profile['id']]);

    $web->server(dirname(__DIR__) . '/public/index.php', true);
    foreach (['admin', 'alice', 'bob'] as $name) $web->login($name, 'phillip-demo', $name);

    $alice = $people['alice']['id'];

    // One private thread with manual=true so no inference/worker involvement.
    $thread = Agent\Thread::create($alice, [
        'title' => 'Fix 05 chat settings',
        'settings' => ['profile' => 'fix05-default', 'tools' => [], 'manual' => true],
        'visibility' => 'private',
    ]);
    putenv('FIX05_THREAD_ID=' . $thread->id);

    // Real project + ToDo for the ToDo dialog discard/save verification.
    $project = Agent\Project::create($alice, [
        'title' => 'DirtyToDo fixture',
        'settings' => ['profile' => 'fix05-default', 'manual' => true],
    ]);
    $todo = $project->addTodo([
        'title' => 'Dirty ToDo',
        'goal' => 'Verify discard does not persist.',
        'requirements' => 'None.',
        'context' => 'Original context.',
        'gates' => [],
    ]);
    putenv('FIX05_PROJECT_ID=' . $project->id);
    putenv('FIX05_TODO_ID=' . $todo['id']);

    check($web->request('/chat', null, 'alice')['status'] === 200, 'Alice can open /chat');

    // Launch the browser test by default (no --browser flag required).
    $browser = $web->launch(['python3', __DIR__ . '/ui_fix_05.py', $web->base], true);
    $code = proc_close($browser);
    check($code === 0, 'Chat settings browser workflow');
} catch (Throwable $e) {
    fwrite(STDERR, $web->diagnostics());
    throw $e;
} finally {
    $web->close();
}
