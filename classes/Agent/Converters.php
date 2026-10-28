<?php
namespace Agent;

/** Small, bounded local pipelines. Upload names never become command arguments or paths. */
class Converters
{
    public static function pdf(array $file, $images = false, $control = null)
    {
        return Attachments::cached($file['id'], $images ? 'pdf-images-v1' : 'pdf-text-v1', function () use ($file, $images, $control) {
            return self::temporary($file, function ($directory, $input) use ($images, $control) {
                if (!$images) return ['text' => self::command(['pdftotext', '-enc', 'UTF-8', '-layout', $input, '-'], $control)];
                $info = self::command(['pdfinfo', $input], $control); preg_match('/^Pages:\s+(\d+)/m', $info, $match);
                $total = isset($match[1]) ? (int)$match[1] : 1; $pages = min(8, $total);
                self::command(['pdftoppm', '-f', '1', '-l', (string)$pages, '-scale-to', '1200', '-png', $input, $directory . '/page'], $control);
                $parts = []; $paths = glob($directory . '/page-*.png'); natsort($paths);
                foreach ($paths as $path) {
                    if (filesize($path) > 5 * 1024 * 1024) throw new \RuntimeException('Rendered PDF page is too large');
                    $parts[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . base64_encode(file_get_contents($path))]];
                }
                if (!$parts) throw new \RuntimeException('PDF did not render any pages');
                return ['parts' => $parts, 'shown_pages' => $pages, 'total_pages' => $total];
            });
        });
    }
    public static function ocr(array $file, $control = null)
    {
        return Attachments::cached($file['id'], 'ocr-eng-v1', function () use ($file, $control) {
            return self::temporary($file, function ($directory, $input) use ($control) { return ['text' => self::command(['tesseract', $input, 'stdout', '-l', 'eng'], $control)]; });
        })['text'];
    }
    public static function pdfOcr(array $file, $control = null)
    {
        return Attachments::cached($file['id'], 'pdf-ocr-eng-v1', function () use ($file, $control) {
            $render = self::pdf($file, true, $control);
            return self::temporary($file, function ($directory, $input) use ($render, $control) {
                $parts = [];
                foreach ($render['parts'] as $part) {
                    $bytes = base64_decode(substr($part['image_url']['url'], strlen('data:image/png;base64,')));
                    file_put_contents($input, $bytes);
                    $parts[] = self::command(['tesseract', $input, 'stdout', '-l', 'eng'], $control);
                }
                $text = implode("\n", $parts);
                if (preg_match('/\S/u', $text) && $render['shown_pages'] < $render['total_pages']) $text .= "\n[OCR covers the first " . $render['shown_pages'] . ' of ' . $render['total_pages'] . ' pages only.]';
                return ['text' => $text];
            });
        })['text'];
    }
    public static function document(array $file)
    {
        if (!class_exists('ZipArchive')) throw new \RuntimeException('Install the PHP zip extension for DOCX/ODT parsing');
        return Attachments::cached($file['id'], 'document-xml-v1', function () use ($file) {
            return self::temporary($file, function ($directory, $input) {
                $zip = new \ZipArchive(); if ($zip->open($input) !== true) throw new \RuntimeException('Invalid Office/OpenDocument archive');
                try {
                    $parts = []; $bytes = 0;
                    foreach (['word/document.xml', 'content.xml'] as $entry) {
                        $stat = $zip->statName($entry); if (!$stat) continue;
                        $bytes += $stat['size']; if ($bytes > 20 * 1024 * 1024) throw new \RuntimeException('Expanded document is too large');
                        $xml = $zip->getFromName($entry);
                        if ($xml === false || stripos($xml, '<!DOCTYPE') !== false) throw new \RuntimeException('Unsafe document XML');
                        $doc = new \DOMDocument(); $old = libxml_use_internal_errors(true);
                        try { if (!$doc->loadXML($xml, LIBXML_NONET)) throw new \RuntimeException('Invalid document XML'); }
                        finally { libxml_clear_errors(); libxml_use_internal_errors($old); }
                        $xpath = new \DOMXPath($doc);
                        foreach ($xpath->query('//*[local-name()="p"]') as $paragraph) $parts[] = $paragraph->textContent;
                    }
                    if (!$parts) throw new \RuntimeException('No readable document paragraphs found');
                    return ['text' => implode("\n", $parts)];
                } finally { $zip->close(); }
            });
        })['text'];
    }
    private static function temporary(array $file, $callback)
    {
        $directory = sys_get_temp_dir() . '/phillip-convert-' . bin2hex(random_bytes(12));
        if (!mkdir($directory, 0700)) throw new \RuntimeException('Cannot create a private conversion directory');
        $input = $directory . '/input';
        try {
            $data = Attachments::get($file['id'], true);
            if (file_put_contents($input, base64_decode($data['base64'])) === false) throw new \RuntimeException('Cannot prepare attachment conversion');
            chmod($input, 0600); return call_user_func($callback, $directory, $input);
        } finally {
            foreach (glob($directory . '/*') as $path) if (is_file($path)) unlink($path);
            rmdir($directory);
        }
    }
    private static function command(array $arguments, $control)
    {
        $allowed = ['pdftotext', 'pdfinfo', 'pdftoppm', 'tesseract'];
        $binary = array_shift($arguments);
        if (!in_array($binary, $allowed, true) || !is_executable('/usr/bin/' . $binary)) throw new \RuntimeException('Configure a parsing model or install ' . $binary . ' for local attachment conversion');
        $command = 'exec /usr/bin/timeout --signal=TERM --kill-after=2s 30s ' . escapeshellarg('/usr/bin/' . $binary);
        foreach ($arguments as $argument) $command .= ' ' . escapeshellarg($argument);
        $process = proc_open($command, [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new \RuntimeException('Cannot start attachment converter');
        stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false); $output = ''; $error = ''; $status = null;
        try {
            do {
                $output .= stream_get_contents($pipes[1]); $error .= stream_get_contents($pipes[2]);
                if (strlen($output) > 20 * 1024 * 1024 || strlen($error) > 1000000) throw new \RuntimeException('Converter output exceeds its safety limit');
                if ($control && call_user_func($control) >= 2) throw new Cancelled('Attachment conversion cancelled');
                $status = proc_get_status($process); if ($status['running']) usleep(20000);
            } while ($status['running']);
            $output .= stream_get_contents($pipes[1]); $error .= stream_get_contents($pipes[2]);
        } catch (\Throwable $e) { proc_terminate($process); throw $e; }
        finally { fclose($pipes[1]); fclose($pipes[2]); $closed = proc_close($process); }
        $code = $status['exitcode'] >= 0 ? $status['exitcode'] : $closed;
        if ($code !== 0) throw new \RuntimeException($binary . ' failed: ' . mb_substr(trim($error) ?: 'conversion timed out or input was unreadable', 0, 1000));
        if (!mb_check_encoding($output, 'UTF-8')) $output = mb_convert_encoding($output, 'UTF-8', 'UTF-8');
        return str_replace("\0", '', $output);
    }
}
