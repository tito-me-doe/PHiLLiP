<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/fixtures/WebFixture.php';

/** Regression: project deletion from the Projects screen uses the project lifecycle.
 *
 * Covers:
 *  - owner may delete a planning project (project + planning thread cleaned up)
 *  - non-owner is refused (owner check preserved)
 *  - child execution-history threads are cleaned up on deletion
 *  - active/running work is refused (in-flight work guard preserved)
 *  - active planning Run on the planning thread is refused
 *  - ordinary conversation deletion still rejects project roots (Frontend::delete untouched)
 */
$web = new WebFixture();
try {
    $admin = Agent\Installation::install(['auth' => 'standalone'], 'admin', 'phillip-demo', 'Administrator');
    $alice = Agent\Identity::createUser('alice', 'phillip-demo', 'Alice');
    $bob = Agent\Identity::createUser('bob', 'phillip-demo', 'Bob');

    Agent\Identity::grant('user', $alice['id'], 'page.projects');
    Agent\Identity::grant('user', $bob['id'], 'page.projects');

    $aliceAuth = Agent\Identity::authenticate('alice', 'phillip-demo');
    $bobAuth = Agent\Identity::authenticate('bob', 'phillip-demo');

    $threads = [];

    try {
        // 1. Owner may delete a planning project; project and planning thread are removed.
        $project = Agent\Project::create($aliceAuth['id'], ['title' => 'Deletable project', 'settings' => ['manual' => true]]);
        $threads[] = $project->get()['thread_id'];
        $threadId = $project->get()['thread_id'];

        (new Agent\ProjectFrontend($aliceAuth))->action($project->id, 'delete');

        check(!Agent\Database::row('SELECT id FROM projects WHERE id = ?', [$project->id]), 'Owner deletion removes project row');
        check(!Agent\Database::row('SELECT id FROM threads WHERE id = ?', [$threadId]), 'Owner deletion removes planning thread');

        // 2. Non-owner is refused; project remains (private project: read boundary denies first).
        $project2 = Agent\Project::create($aliceAuth['id'], ['title' => 'Another project', 'settings' => ['manual' => true]]);
        $threads[] = $project2->get()['thread_id'];

        expectException(function () use ($bobAuth, $project2) {
            (new Agent\ProjectFrontend($bobAuth))->action($project2->id, 'delete');
        }, 'Thread access denied');
        check(Agent\Database::row('SELECT id FROM projects WHERE id = ?', [$project2->id]), 'Non-owner deletion denied; project remains');

        // 3. Non-owner who can read (team member) is still refused by the owner check.
        Agent\Mappings::insert('teams', ['id' => 'engineering', 'name' => 'Engineering']);
        Agent\Mappings::insert('memberships', ['user_id' => $bobAuth['id'], 'team_id' => 'engineering']);
        $project3 = Agent\Project::create($aliceAuth['id'], ['title' => 'Team project', 'visibility' => 'team', 'team_id' => 'engineering', 'settings' => ['manual' => true]]);
        $threads[] = $project3->get()['thread_id'];

        expectException(function () use ($bobAuth, $project3) {
            (new Agent\ProjectFrontend($bobAuth))->action($project3->id, 'delete');
        }, 'Only the thread creator');
        check(Agent\Database::row('SELECT id FROM projects WHERE id = ?', [$project3->id]), 'Team member non-owner deletion denied by owner check; project remains');

        // 4. Child execution-history cleanup: a todo with its own work thread is removed with the project.
        $project4 = Agent\Project::create($aliceAuth['id'], ['title' => 'Project with child execution', 'settings' => ['manual' => true]]);
        $threads[] = $project4->get()['thread_id'];
        $todo = $project4->addTodo(['title' => 'Child task', 'goal' => 'Goal', 'requirements' => 'Req', 'context' => 'Ctx', 'gates' => ['Gate']]);
        $childThread = Agent\Thread::create($aliceAuth['id'], 'Child execution thread', ['manual' => true]);
        $threads[] = $childThread->id;
        Agent\Database::execute('UPDATE project_todos SET thread_id = ? WHERE id = ?', [$childThread->id, $todo['id']]);
        $project4ThreadId = $project4->get()['thread_id'];

        (new Agent\ProjectFrontend($aliceAuth))->action($project4->id, 'delete');

        check(!Agent\Database::row('SELECT id FROM projects WHERE id = ?', [$project4->id]), 'Project with child execution is removed');
        check(!Agent\Database::row('SELECT id FROM threads WHERE id = ?', [$childThread->id]), 'Child execution thread is cleaned up with the project');
        check(!Agent\Database::row('SELECT id FROM threads WHERE id = ?', [$project4ThreadId]), 'Planning thread is cleaned up with the project');

        // 5. Active (running) project status is refused; project remains.
        $project5 = Agent\Project::create($aliceAuth['id'], ['title' => 'Running project', 'settings' => ['manual' => true]]);
        $threads[] = $project5->get()['thread_id'];
        Agent\Database::execute("UPDATE projects SET status = 'running' WHERE id = ?", [$project5->id]);

        expectException(function () use ($aliceAuth, $project5) {
            (new Agent\ProjectFrontend($aliceAuth))->action($project5->id, 'delete');
        }, 'Pause the project before deleting it');
        check(Agent\Database::row('SELECT id FROM projects WHERE id = ?', [$project5->id]), 'Running project deletion refused; project remains');

        // 6. Active planning Run on the planning thread is refused; project remains.
        $project6 = Agent\Project::create($aliceAuth['id'], ['title' => 'Planning run project', 'settings' => ['manual' => true]]);
        $threads[] = $project6->get()['thread_id'];
        $planningThread = new Agent\Thread($project6->get()['thread_id']);
        $run = $planningThread->enqueue($aliceAuth['id'], 'Planning message');
        Agent\Database::execute("UPDATE runs SET status = 'running' WHERE id = ?", [$run['id']]);

        expectException(function () use ($aliceAuth, $project6) {
            (new Agent\ProjectFrontend($aliceAuth))->action($project6->id, 'delete');
        }, 'Stop the active planning run before deleting the project');
        check(Agent\Database::row('SELECT id FROM projects WHERE id = ?', [$project6->id]), 'Active planning run deletion refused; project remains');

        // 7. Ordinary conversation deletion still rejects project roots (Frontend::delete untouched).
        $thread = Agent\Thread::create($aliceAuth['id'], 'Conversation fixture', ['manual' => true]);
        $threads[] = $thread->id;
        Agent\Project::create($aliceAuth['id'], ['thread_id' => $thread->id]);

        expectException(function () use ($aliceAuth, $thread) {
            (new Agent\Frontend($aliceAuth))->delete($thread->id);
        }, 'Manage projects from the Projects screen');
        check(Agent\Database::row('SELECT id FROM threads WHERE id = ?', [$thread->id]), 'Conversation deletion protected for project roots');

    } finally {
        foreach ($threads as $threadId) {
            try {
                (new Agent\Thread($threadId))->delete();
            } catch (\Throwable $e) {
                // Thread may already have been removed by project deletion.
            }
        }
    }
} finally {
    $web->close();
}
echo "ALL PASS\n";
