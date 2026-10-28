<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/fixtures/media.php';
$files = []; $name = 'fixture-vision-' . bin2hex(random_bytes(4));
$config = ['url' => 'http://10.0.3.75:11434/v1/chat/completions', 'model' => 'gemma3:4b', 'temperature' => 0, 'max_tokens' => 512, 'context_window' => 8192,
    'timeout' => 180, 'capabilities' => ['supports_vision' => true, 'supports_tools' => false]];
$old = Agent\Database::connection()->query("SELECT value FROM settings WHERE key = 'attachment_parsers'")->fetchColumn();
try {
    $png = base64_decode(substr(Agent\Profiles::imageData(), strlen('data:image/png;base64,')));
    $image = Agent\Attachments::store('fixture', 'red.png', $png); $files[] = $image['id'];
    $request = new Agent\Request($config); $request->addMessage(['content' => 'What is the dominant color? Answer one word.', 'attachments' => [$image]]);
    check(stripos($request->send(), 'red') !== false, 'Real Ollama vision model reads native image attachment through Agent Request');
    $pdf = Agent\Attachments::store('fixture', 'invoice.pdf', fixturePdf('INVOICE 8127')); $files[] = $pdf['id'];
    $request = new Agent\Request($config); $request->addMessage(['content' => 'What is the invoice number in the attached page? Reply with the digits.', 'attachments' => [$pdf]]);
    check(strpos($request->send(), '8127') !== false, 'Real vision model reads a PDF page image');
    Agent\Profiles::save($name, $config, false);
    Agent\Database::connection()->prepare("INSERT INTO settings (key, value) VALUES ('attachment_parsers', ?::jsonb) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value")->execute([json_encode(['image/*' => $name])]);
    $request = new Agent\Request(array_replace(Agent\Config::profile('default'), ['temperature' => 0, 'max_tokens' => 512]));
    $request->addMessage(['content' => 'What color does the attached image show? Answer one word.', 'attachments' => [$image]]);
    check(stripos($request->send(), 'red') !== false, 'Non-vision chat model delegates image parsing to configured local vision profile');
    check(stripos(Agent\Attachments::get($image['id'])['text_content'], 'red') !== false, 'Delegated representation is available for indexing and retrieval');
} finally {
    foreach ($files as $id) Agent\Database::connection()->prepare('DELETE FROM attachments WHERE id = ?')->execute([$id]);
    Agent\Database::connection()->prepare('DELETE FROM profiles WHERE name = ?')->execute([$name]);
    if ($old === false) Agent\Database::connection()->exec("DELETE FROM settings WHERE key = 'attachment_parsers'");
    else Agent\Database::connection()->prepare("UPDATE settings SET value = ?::jsonb WHERE key = 'attachment_parsers'")->execute([$old]);
}
