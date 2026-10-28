<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/fixtures/media.php';
putenv('PHP_CLI_SERVER_WORKERS');
$process = proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:18996 ' . escapeshellarg(__DIR__ . '/fixtures/attachment_engine.php'),
    [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
$old = Agent\Database::connection()->query("SELECT value FROM settings WHERE key = 'attachment_parsers'")->fetchColumn();
$files = []; $profile = 'fixture-parser-' . bin2hex(random_bytes(4));
try {
    usleep(200000);
    Agent\AttachmentParser::configure([]);
    $pdf = Agent\Attachments::store('fixture', 'invoice.pdf', fixturePdf('INVOICE 8127')); $files[] = $pdf['id'];
    $config = ['url' => 'http://127.0.0.1:18996/v1/chat/completions', 'model' => 'reject', 'context_window' => 32768,
        'capabilities' => ['supports_pdf' => true, 'supports_vision' => true, 'supports_tools' => true]];
    $request = new Agent\Request($config);
    $request->registerTool('Agent\\Tools\\Attachments'); // Explicit registration remains safe with the intrinsic tool.
    $request->addMessage(['content' => 'Read this invoice', 'attachments' => [$pdf]]);
    check($request->send(1) === 'text invoice 8127', 'Native input rejection falls back to text without consuming an agent round');
    check(count(array_filter($request->response['events'], function ($event) { return $event['type'] === 'attachment.fallback'; })) === 1, 'Native fallback emits one shared progress event');
    check(count($request->request['body']['tools']) === 2, 'Explicit and automatic attachment tool registration do not duplicate tools');
    $request = new Agent\Request(array_replace($config, ['model' => 'native']));
    $request->addMessage(['content' => 'Read', 'attachments' => [$pdf]]);
    check($request->send(1) === 'native accepted', 'Native PDF wire input is preserved despite cached extracted text');
    $request = new Agent\Request(array_replace($config, ['model' => 'native', 'context_window' => 4096]));
    $request->addMessage(['content' => 'Read', 'attachments' => [$pdf]]);
    check($request->send(1) === 'text invoice 8127', 'Oversized native input gracefully falls back to a bounded text representation');
    Agent\Profiles::save($profile, array_replace($config, ['model' => 'parser']), false);
    Agent\AttachmentParser::configure(['application/pdf' => $profile]);
    $render = Agent\AttachmentParser::render(Agent\Attachments::get($pdf['id']), [], ['supports_vision' => true]);
    check($render['text'] === 'Configured parser: invoice 8127', 'Configured MIME parser takes precedence over local page conversion');
    Agent\AttachmentParser::configure(['.pdf' => $profile]);
    check(Agent\AttachmentParser::profile(Agent\Attachments::get($pdf['id'])) === $profile, 'Extension-specific parser lookup works');
    expectException(function () use ($profile) { Agent\AttachmentParser::configure(['not a type' => $profile]); }, 'MIME');
    expectException(function () { Agent\AttachmentParser::configure(['image/*' => 'nonexistent-fixture-profile']); }, 'not configured');
    Agent\Database::connection()->exec("UPDATE settings SET value = '\"malformed fixture\"'::jsonb WHERE key = 'attachment_parsers'");
    check(strpos(Agent\AttachmentParser::render(Agent\Attachments::get($pdf['id']), [], [])['text'], '8127') !== false, 'Broken parser settings gracefully fall back to local extraction');
    Agent\AttachmentParser::configure([]);
    $fresh = Agent\Attachments::store('fixture', 'cancel.pdf', fixturePdf('INVOICE 8127')); $files[] = $fresh['id'];
    expectException(function () use ($fresh) { Agent\AttachmentParser::render(Agent\Attachments::get($fresh['id']), ['control' => function () { return 2; }], []); }, 'cancelled');
    check(count(glob(sys_get_temp_dir() . '/phillip-convert-*')) === 0, 'Converter cancellation removes private temporary files');
    $temp = tempnam(sys_get_temp_dir(), 'phillip-unsafe-fixture-'); $zip = new ZipArchive();
    $zip->open($temp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('content.xml', '<!DOCTYPE x [<!ENTITY leak SYSTEM "file:///etc/passwd">]><x><p>&leak;</p></x>'); $zip->close();
    try { $unsafe = Agent\Attachments::store('fixture', 'unsafe.odt', file_get_contents($temp)); $files[] = $unsafe['id']; } finally { unlink($temp); }
    expectException(function () use ($unsafe) { Agent\AttachmentParser::render(Agent\Attachments::get($unsafe['id']), [], []); }, 'Unsafe document XML');
} finally {
    foreach ($files as $id) Agent\Database::connection()->prepare('DELETE FROM attachments WHERE id = ?')->execute([$id]);
    Agent\Database::connection()->prepare('DELETE FROM profiles WHERE name = ?')->execute([$profile]);
    if ($old === false) Agent\Database::connection()->exec("DELETE FROM settings WHERE key = 'attachment_parsers'");
    else Agent\Database::connection()->prepare("UPDATE settings SET value = ?::jsonb WHERE key = 'attachment_parsers'")->execute([$old]);
    proc_terminate($process); proc_close($process);
}
