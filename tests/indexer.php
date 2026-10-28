<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/fixtures/media.php';
$alice = Agent\Identity::authenticate('alice', 'phillip-demo');
$thread = Agent\Thread::create($alice['id'], 'Automatic knowledge fixture', ['manual' => true, 'parameters' => ['temperature' => 0, 'max_tokens' => 256]]);
try {
    $run = $thread->enqueue($alice['id'], 'My preferred editor is Vim. Remember this preference. Reply OK.');
    Agent\Run::execute(Agent\Run::claim($thread->id)['id']);
    check(Agent\Indexer::process($run['id']) === [], 'Background extraction and embeddings work with discovered local profiles');
    $q = Agent\Database::connection()->prepare('SELECT COUNT(*) FROM message_vectors v JOIN messages m ON m.id = v.message_id WHERE m.thread_id = ?');
    $q->execute([$thread->id]);
    check($q->fetchColumn() >= 3, 'System, user, and assistant messages embedded');
    check(count(Agent\Knowledge::recall('Vim', $alice['id'], $thread->id)) > 0, 'Agent extracts durable preference');
    $originalClaims = array_column(Agent\Knowledge::recall('Vim', $alice['id'], $thread->id), 'id');
    $second = $thread->enqueue($alice['id'], 'Correction: I no longer prefer Vim. My preferred editor is now Visual Studio Code. Reply OK.');
    Agent\Run::execute(Agent\Run::claim($thread->id)['id']);
    check(Agent\Indexer::process($second['id']) === [], 'Ephemeral background agent reconciles new knowledge');
    $claims = Agent\Knowledge::recall('', $alice['id'], $thread->id, 50);
    check(array_intersect($originalClaims, array_column($claims, 'id')) === [], 'Contradiction resolver removes superseded claims');
    check(count(array_filter($claims, function ($claim) { return stripos($claim['content'], 'Visual Studio Code') !== false; })) > 0, 'Corrected preference retained');
    check(count(Agent\History::search('preferred editing software', $thread->id, $alice['id'])) > 0, 'Hybrid vector/text retrieval finds conversation');
    $file = Agent\Attachments::store($alice['id'], 'invoice.pdf', fixturePdf('INVOICE 8127 - Total 49 dollars'));
    $third = $thread->enqueue($alice['id'], ['content' => 'Index the supplied invoice.', 'attachments' => [$file]]);
    Agent\Run::claim($thread->id);
    $thread->append($third['id'], ['role' => 'assistant', 'content' => 'Invoice received.']);
    Agent\Database::connection()->prepare("UPDATE runs SET status = 'completed' WHERE id = ?")->execute([$third['id']]);
    Agent\Run::event($third['id'], ['type' => 'run.completed', 'data' => ['content' => 'Invoice received.']]);
    check(Agent\Attachments::get($file['id'])['text_content'] === null, 'Native attachment can reach the indexer without prior text conversion');
    check(Agent\Indexer::process($third['id']) === [], 'Background indexer extracts and embeds attachment content');
    $q = Agent\Database::connection()->prepare("SELECT search_text FROM messages WHERE run_id = ? AND role = 'user'"); $q->execute([$third['id']]);
    check(strpos($q->fetchColumn(), '8127') !== false, 'Attachment contents become searchable in their source message');
} finally { $thread->delete(); }
