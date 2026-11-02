<?php
if (PHP_SAPI === 'cli-server') {
    header('Content-Type: application/json');
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($path === '/v1/models') {
        echo '{"data":[{"id":"indexer-local","owned_by":"fixture","max_model_len":4096}]}';
        return;
    }
    if ($path === '/health') { echo '{}'; return; }
    if ($path === '/v1/embeddings') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $texts = isset($body['input']) ? $body['input'] : [];
        $data = [];
        foreach ($texts as $i => $text) {
            $data[] = ['index' => $i, 'embedding' => [0.1, 0.2, 0.3]];
        }
        echo json_encode(['data' => $data]);
        return;
    }
    if ($path !== '/v1/chat/completions') { http_response_code(404); echo '{}'; return; }
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $extracting = isset($body['messages'][0]['content']) && is_string($body['messages'][0]['content'])
        && strpos($body['messages'][0]['content'], 'Extract durable source-backed knowledge') !== false;
    if ($extracting) {
        $payload = json_decode($body['messages'][1]['content'] ?? '[]', true);
        $msgIds = []; $texts = []; $existingIds = [];
        if (is_array($payload)) {
            if (isset($payload['messages']) && is_array($payload['messages'])) {
                foreach ($payload['messages'] as $m) {
                    if (isset($m['id'])) $msgIds[] = (int)$m['id'];
                    if (isset($m['text'])) $texts[] = $m['text'];
                }
            }
            if (isset($payload['existing_claims']) && is_array($payload['existing_claims'])) {
                foreach ($payload['existing_claims'] as $c) {
                    if (isset($c['id'])) $existingIds[] = (int)$c['id'];
                }
            }
        }
        $hasVim = false; $hasVSCode = false;
        foreach ($texts as $text) {
            if (stripos($text, 'Vim') !== false) $hasVim = true;
            if (stripos($text, 'Visual Studio Code') !== false) $hasVSCode = true;
        }
        $claims = []; $remove = [];
        if ($hasVSCode && !empty($msgIds)) {
            $claims[] = ['content' => 'The user prefers Visual Studio Code as their editor.', 'subject' => 'user', 'sources' => $msgIds, 'relation' => 'prefers', 'object' => 'Visual Studio Code'];
        }
        if ($hasVim && !$hasVSCode && !empty($msgIds)) {
            $claims[] = ['content' => 'The user prefers Vim as their editor.', 'subject' => 'user', 'sources' => $msgIds, 'relation' => 'prefers', 'object' => 'Vim'];
        }
        if ($hasVSCode && !empty($existingIds)) {
            $remove = $existingIds;
        }
        echo json_encode(['choices' => [['message' => ['role' => 'assistant', 'content' => json_encode(['claims' => $claims, 'remove' => $remove])], 'finish_reason' => 'stop']]]);
        return;
    }
    echo json_encode(['choices' => [['message' => ['role' => 'assistant', 'content' => 'OK.'], 'finish_reason' => 'stop']]]);
    return;
}
require __DIR__ . '/helpers.php';
require __DIR__ . '/fixtures/media.php';
$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$listener) throw new RuntimeException('Cannot reserve fixture port: ' . $error);
$fixtureAddress = stream_socket_get_name($listener, false); fclose($listener);
$server = proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' -S ' . escapeshellarg($fixtureAddress) . ' ' . escapeshellarg(__FILE__),
    [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
$ready = false;
for ($i = 0; $i < 40; $i++) {
    if (!is_resource($server) || !proc_get_status($server)['running']) throw new RuntimeException('Indexer fixture process failed to start');
    $socket = @stream_socket_client('tcp://' . $fixtureAddress, $errno, $error, 0.05);
    if ($socket) { fclose($socket); if (!proc_get_status($server)['running']) throw new RuntimeException('Indexer fixture process exited during startup'); $ready = true; break; }
    usleep(50000);
}
if (!$ready) throw new RuntimeException('Indexer fixture did not become ready');
$engineUrl = 'http://' . $fixtureAddress . '/v1/chat/completions';
$alice = Agent\Identity::authenticate('alice', 'phillip-demo');
Agent\Profiles::save('default', ['url' => $engineUrl, 'model' => 'indexer-local', 'stream' => false, 'max_tokens' => 1024]);
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
} finally { $thread->delete(); if (is_resource($server)) { proc_terminate($server); proc_close($server); } }
