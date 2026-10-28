<?php
require __DIR__ . '/helpers.php';
$alice = Agent\Identity::authenticate('alice', 'phillip-demo'); $bob = Agent\Identity::authenticate('bob', 'phillip-demo'); $carol = Agent\Identity::authenticate('carol', 'phillip-demo'); $admin = Agent\Identity::authenticate('admin', 'phillip-demo');
$front = new Agent\Frontend($alice['id']); $files = [];
$thread = Agent\Thread::create($alice['id'], 'Attachment fixture', ['manual' => true]);
try {
    $key = bin2hex(random_bytes(16));
    $file = Agent\Attachments::store($alice['id'], '../note.php', "<?php\n// The delivery code is COPPER-482.\necho 'hello';\n", $key); $files[] = $file['id'];
    check($file['name'] === 'note.php', 'Upload names cannot become filesystem paths');
    check(Agent\Attachments::store($alice['id'], '../note.php', "<?php\n// The delivery code is COPPER-482.\necho 'hello';\n", $key)['id'] === $file['id'], 'Upload retries return the same file');
    expectException(function () use ($alice, $key) { Agent\Attachments::store($alice['id'], 'note.php', 'different', $key); }, 'different file');
    expectException(function () use ($bob, $file) { (new Agent\Frontend($bob['id']))->attachment($file['id']); }, 'access denied');
    $run = $front->send($thread->id, ['content' => 'What is the delivery code?', 'attachments' => [['id' => $file['id'], 'name' => 'forged.txt']], 'submission_key' => bin2hex(random_bytes(16))]);
    $raw = $thread->messages()[1]['raw'];
    check($raw['attachments'][0]['name'] === 'note.php', 'Message attachment metadata is resolved server-side');
    expectException(function () use ($admin, $file) { (new Agent\Frontend($admin['id']))->attachment($file['id']); }, 'access denied');
    check(strpos(Agent\Message::text($raw), 'COPPER-482') !== false, 'Attachment text participates in search and indexing');
    $front->discardAttachment($file['id']);
    check(Agent\Attachments::get($file['id'])['id'] === $file['id'], 'Removing a recovered draft does not delete its committed file');
    $projected = Agent\Attachments::forModel($raw, ['context_window' => 4096]);
    check(strpos($projected['content'], 'COPPER-482') !== false, 'Text file becomes model-readable input without a converter');
    Agent\Database::connection()->prepare("UPDATE threads SET visibility = 'team', team_id = 'engineering' WHERE id = ?")->execute([$thread->id]);
    check((new Agent\Frontend($bob['id']))->attachment($file['id'])['id'] === $file['id'], 'Attachment inherits team visibility');
    expectException(function () use ($carol, $file) { (new Agent\Frontend($carol['id']))->attachment($file['id']); }, 'access denied');
    Agent\Database::connection()->prepare("UPDATE threads SET visibility = 'private', team_id = NULL WHERE id = ?")->execute([$thread->id]);
    expectException(function () use ($bob, $file) { (new Agent\Frontend($bob['id']))->attachment($file['id']); }, 'access denied');
    $second = Agent\Attachments::store($alice['id'], 'later.txt', 'A later attachment'); $files[] = $second['id'];
    $later = $thread->enqueue($alice['id'], ['content' => 'Later', 'attachments' => [$second]]);
    Agent\ToolContext::withValues(['thread_id' => $thread->id, 'run_id' => $run['id'], 'user_id' => $alice['id'], 'frontend' => true], function () use ($file, $second) {
        check(Agent\Tools\Attachments::read($file['id'], 0, 100)['total_characters'] > 0, 'Agent can read its current-thread attachment');
        expectException(function () use ($second) { Agent\Tools\Attachments::read($second['id']); }, 'not in the current');
    });
    $thread->edit($thread->messages()[1]['id'], 'Corrected question');
    check(Agent\Attachments::get($file['id'])['id'] === $file['id'], 'Editing retains source attachments across the replacement transaction');
    expectException(function () use ($second) { Agent\Attachments::get($second['id']); }, 'not found');
    $utf = Agent\Attachments::store($alice['id'], 'utf16.txt', "\xff\xfeH\0i\0"); $files[] = $utf['id'];
    check(Agent\Attachments::get($utf['id'])['text_content'] === 'Hi', 'UTF-16 text decoded locally');
    $binary = Agent\Attachments::store($alice['id'], 'unsafe.php', "\x00\x01\xff"); $files[] = $binary['id'];
    expectException(function () use ($binary) { Agent\Attachments::forModel(['content' => '', 'attachments' => [$binary]], []); }, 'Cannot parse');
    $thread->delete();
    expectException(function () use ($file) { Agent\Attachments::get($file['id']); }, 'not found');
    check((new Agent\Registry())->profiles() !== [], 'Attachment cleanup does not alter model profiles');
} finally {
    Agent\Database::connection()->prepare('DELETE FROM threads WHERE id = ?')->execute([$thread->id]);
    foreach ($files as $id) Agent\Database::connection()->prepare('DELETE FROM attachments WHERE id = ?')->execute([$id]);
}
