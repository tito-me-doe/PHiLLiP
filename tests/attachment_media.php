<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/fixtures/media.php';
$files = []; $owner = 'fixture-media-' . bin2hex(random_bytes(4));
try {
    $pdf = Agent\Attachments::store($owner, 'invoice.pdf', fixturePdf('INVOICE 8127 - Total 49 dollars')); $files[] = $pdf['id'];
    $native = Agent\AttachmentParser::render(Agent\Attachments::get($pdf['id']), [], ['supports_pdf' => true]);
    check($native['parts'][0]['type'] === 'file', 'Native PDF capability takes precedence over all converters');
    $text = Agent\AttachmentParser::render(Agent\Attachments::get($pdf['id']), [], []);
    check(strpos($text['text'], '8127') !== false, 'Text-only models receive a real pdftotext conversion');
    $vision = Agent\AttachmentParser::render(Agent\Attachments::get($pdf['id']), [], ['supports_vision' => true]);
    check($vision['parts'][1]['type'] === 'image_url', 'Vision models receive PDF page images even after text has been cached');
    $long = Agent\Attachments::store($owner, 'nine-pages.pdf', fixturePdf('INVOICE 8127', null, 9)); $files[] = $long['id'];
    $limited = Agent\AttachmentParser::render(Agent\Attachments::get($long['id']), [], ['supports_vision' => true]);
    check(count($limited['parts']) === 9 && strpos($limited['parts'][0]['text'], '8 of 9') !== false, 'PDF image conversion bounds work to eight pages and explicitly announces partial results');
    check(substr_count(Agent\AttachmentParser::text(Agent\Attachments::get($long['id'])), '8127') === 9, 'PDF text conversion retains all pages for later range retrieval');
    $png = base64_decode(substr($vision['parts'][1]['image_url']['url'], strlen('data:image/png;base64,')));
    $scan = Agent\Attachments::store($owner, 'scanned.pdf', fixturePdf('', $png)); $files[] = $scan['id'];
    $ocr = Agent\AttachmentParser::render(Agent\Attachments::get($scan['id']), [], []);
    check(strpos($ocr['text'], '8127') !== false, 'Image-only PDF falls back to real page rendering and Tesseract OCR');
    $image = Agent\Attachments::store($owner, 'invoice.png', $png); $files[] = $image['id'];
    check(strpos(Agent\AttachmentParser::render(Agent\Attachments::get($image['id']), [], [])['text'], '8127') !== false, 'Image OCR produces usable text for a non-vision model');
    check(Agent\AttachmentParser::render(Agent\Attachments::get($image['id']), [], ['supports_vision' => true])['parts'][1]['type'] === 'image_url', 'Native image input takes precedence over cached OCR');
    $temp = tempnam(sys_get_temp_dir(), 'phillip-docx-fixture-'); $zip = new ZipArchive();
    $zip->open($temp, ZipArchive::CREATE | ZipArchive::OVERWRITE); $zip->addFromString('word/document.xml', '<w:document xmlns:w="urn:fixture"><w:body><w:p><w:r><w:t>Order 9712</w:t></w:r></w:p></w:body></w:document>'); $zip->close();
    try { $doc = Agent\Attachments::store($owner, 'order.docx', file_get_contents($temp)); $files[] = $doc['id']; } finally { unlink($temp); }
    check(strpos(Agent\AttachmentParser::render(Agent\Attachments::get($doc['id']), [], [])['text'], '9712') !== false, 'DOCX paragraphs parsed with bounded PHP-native ZIP/XML');
    $temp = tempnam(sys_get_temp_dir(), 'phillip-odt-fixture-'); $zip = new ZipArchive();
    $zip->open($temp, ZipArchive::CREATE | ZipArchive::OVERWRITE); $zip->addFromString('content.xml', '<office:document xmlns:office="urn:office" xmlns:text="urn:text"><text:p>ODT order 6291</text:p></office:document>'); $zip->close();
    try { $odt = Agent\Attachments::store($owner, 'order.odt', file_get_contents($temp)); $files[] = $odt['id']; } finally { unlink($temp); }
    check(strpos(Agent\AttachmentParser::render(Agent\Attachments::get($odt['id']), [], [])['text'], '6291') !== false, 'ODT content uses its supported PHP-native pipeline');
    $q = Agent\Database::connection()->prepare('SELECT COUNT(*) FROM attachment_derivatives WHERE attachment_id = ?'); $q->execute([$scan['id']]);
    $before = $q->fetchColumn(); Agent\AttachmentParser::render(Agent\Attachments::get($scan['id']), [], []); $q->execute([$scan['id']]);
    check($q->fetchColumn() === $before, 'Repeated turns reuse cached converter output');
} finally { foreach ($files as $id) Agent\Database::connection()->prepare('DELETE FROM attachments WHERE id = ?')->execute([$id]); }
