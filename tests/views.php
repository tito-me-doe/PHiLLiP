<?php
require __DIR__ . '/helpers.php';
$alice = Agent\Identity::authenticate('alice', 'phillip-demo');
$state = ['user' => $alice, 'profiles' => [['name' => 'default', 'model' => 'local model']], 'personas' => [], 'teams' => [], 'tools' => [], 'preferences' => [], 'csrf' => 'test-token'];
$thread = Agent\Thread::create($alice['id'], 'Server-rendered fixture', ['manual' => true]);
try {
    $run = $thread->enqueue($alice['id'], 'Show **server Markdown** and do not execute <script>alert(1)</script>');
    $thread->append($run['id'], ['role' => 'assistant', 'content' => "**Rendered in PHP**\n\n```php\necho '<unsafe>';\n```\n\n[bad](javascript:alert%281%29)\n<script>alert(2)</script>", 'reasoning' => '<reasoning>']);
    $current = Agent\Views::thread($state, $thread->id);
    $html = Agent\Views::render('messages', compact('state', 'current'));
    check(strpos($html, '<strong>Rendered in PHP</strong>') !== false, 'Saved Markdown is formatted by PHP');
    check(strpos($html, 'language-php') !== false, 'PHP emits code blocks before highlighting');
    check(strpos($html, '<script>alert') === false && strpos($html, 'href="javascript:') === false, 'Untrusted message HTML and script links are neutralized server-side');
    check(strpos($html, '&lt;reasoning&gt;') !== false, 'Reasoning escaped by PHP');
    check(substr_count($html, 'Edit your message') === 1, 'Only the author receives a user message edit control');
    check(strpos($html, '<svg') !== false, 'Avatar SVG rendered by PHP, not fetched during hydration');
    $chat = Agent\Views::render('chat', compact('state', 'current'));
    check(strpos($chat, 'method="post"') !== false && strpos($chat, 'name="csrf"') !== false, 'Basic chat has a CSRF-protected ordinary POST fallback');
    check(strpos(Agent\Views::json(['content' => '</script><script>alert(3)</script>']), '</script>') === false, 'Embedded state cannot escape its JSON script element');
    $thread->append($run['id'], ['role' => 'tool', 'tool_call_id' => 'screenshot', 'content' => [
        ['type' => 'text', 'text' => '<screenshot metadata>'],
        ['type' => 'image_url', 'image_url' => ['url' => Agent\Profiles::imageData()]],
        ['type' => 'image_url', 'image_url' => ['url' => 'https://untrusted.invalid/tracker.png']],
        ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,abc" onerror="alert(1)']],
    ]]);
    $current = Agent\Views::thread($state, $thread->id);
    $html = Agent\Views::render('messages', compact('state', 'current'));
    check(strpos($html, '&lt;screenshot metadata&gt;') !== false && strpos($html, 'alt="Browser screenshot"') !== false, 'PHP renders native tool screenshots and escapes readable metadata');
    check(strpos($html, 'untrusted.invalid') === false && strpos($html, 'onerror=') === false, 'Screenshot activity never loads remote image URLs or attribute injection');
} finally { $thread->delete(); }
