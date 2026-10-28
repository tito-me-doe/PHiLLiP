<?php
require __DIR__ . '/helpers.php';
use Agent\Tools\Source;

$previous = getcwd(); $root = sys_get_temp_dir() . '/phillip-source-' . bin2hex(random_bytes(8));
mkdir($root, 0700); mkdir($root . '/src', 0700); mkdir($root . '/config', 0700); mkdir($root . '/.hidden', 0700);
$outside = tempnam(sys_get_temp_dir(), 'phillip-source-outside-');
$files = ['src/example.php', 'src/long.txt', 'src/binary.dat', 'src/large.txt', 'src/wide.txt', 'config/secret.txt', '.hidden/secret.txt', 'alias', 'outside'];
try {
    file_put_contents($root . '/src/example.php', "<?php\n// example\nreturn 42;\n");
    file_put_contents($root . '/src/long.txt', str_repeat(str_repeat('x', 100) . "\n", 600));
    file_put_contents($root . '/src/binary.dat', "data\0binary");
    file_put_contents($root . '/src/large.txt', str_repeat('x', 2097153));
    file_put_contents($root . '/config/secret.txt', 'SECRET'); file_put_contents($root . '/.hidden/secret.txt', 'SECRET');
    symlink($root . '/config', $root . '/alias'); symlink($outside, $root . '/outside'); chdir($root);
    $registry = new Agent\ToolRegistry(); $registry->register(Source::class);
    check(count($registry->definitions()) === 3, 'Source read/list/search interfaces have usable reflected docblocks');
    $listing = Source::files('.'); sort($listing);
    check($listing === ['src/binary.dat', 'src/example.php', 'src/large.txt', 'src/long.txt'], 'Listing stays within the current workspace and excludes private directories/symlinks');
    check(count(Source::files('src', 1)) === 1, 'Directory listing applies its explicit bound');
    $read = Source::read(' src/example.php ', 2, 1);
    check($read['path'] === 'src/example.php' && $read['start_line'] === 2 && strpos($read['text'], '// example') !== false && $read['truncated'], 'Source reads trim boundary whitespace and respect line ranges');
    check($read['end_line'] === 2 && $read['next_line'] === 3 && $read['total_lines'] === 3, 'Read metadata makes excerpt boundaries and continuation explicit');
    $numbered = Source::read('src/example.php', 2, 1, true);
    check($numbered['text'] === '2: // example' && array_diff_assoc($numbered, $read) === ['text' => '2: // example'], 'Optional numbering changes only text, preserving whole-file hash and excerpt metadata');
    $result = $registry->execute(['id' => 'numbered-source', 'function' => ['name' => 'Agent_Tools_Source_read', 'arguments' => json_encode(['path' => 'src/example.php', 'startLine' => 2, 'maxLines' => 2, 'numbered' => true])]]);
    $numbered = json_decode($result['content'], true);
    check(!$result['_tool_error'] && $numbered['text'] === "2: // example\n3: return 42;", 'Reflected Source.read accepts numbered=true and returns absolute line numbers');
    check(Source::read('example.php')['path'] === 'src/example.php', 'A unique bare filename hydrates its protected workspace-relative path');
    $matches = Source::search('example')['matches'];
    check($matches[0]['path'] === 'src/example.php' && $matches[1]['line'] === 2, 'Literal source search finds filenames and matching line numbers');
    check(Source::search('SECRET')['matches'] === [], 'Source search excludes private paths and symlinks');
    check(Source::search('return', 'src/example.php')['matches'] === [['path' => 'src/example.php', 'line' => 3, 'text' => 'return 42;']], 'Source.search accepts a single file scope and reports its line numbers');
    $result = $registry->execute(['id' => 'file-source-search', 'function' => ['name' => 'Agent_Tools_Source_search', 'arguments' => json_encode(['path' => 'src/example.php', 'query' => 'return'])]]);
    check(!$result['_tool_error'] && json_decode($result['content'], true) === Source::search('return', 'src/example.php'), 'Reflected Source.search accepts a file path with the normal result shape');
    check(Source::search('x', 'src/example.php')['matches'] === [['path' => 'src/example.php', 'line' => null, 'text' => 'src/example.php'], ['path' => 'src/example.php', 'line' => 2, 'text' => '// example']], 'File-scoped searches exclude matching lines in unrelated files');
    expectException(function () { Source::files('src/example.php'); }, 'directory');
    $read = Source::read('src/long.txt', 500, 2);
    check(substr_count(rtrim($read['text'], "\n"), "\n") === 1 && strlen($read['text']) >= 200 && $read['truncated'], 'Line ranges beyond the first 32KiB remain readable');
    $read = Source::read('src/long.txt', 1, 10000);
    check(strlen($read['text']) <= 32768 && $read['truncated'], 'Source output is bounded by both lines and bytes');
    check($read['sha256'] === hash_file('sha256', $root . '/src/long.txt'), 'Source hashes cover the whole file, not just a truncated excerpt');
    $numbered = Source::read('src/long.txt', 1, 10000, true);
    $raw = preg_replace('/^\d+: /m', '', $numbered['text']);
    check($raw === $read['text'] && $numbered['end_line'] === $read['end_line'] && $numbered['next_line'] === $read['next_line'] && $numbered['sha256'] === $read['sha256'], 'Numbered byte-bounded excerpts keep the same raw source and continuation');
    check(strlen($numbered['text']) <= Source::BYTE_LIMIT + 500 * 9 && substr_count($numbered['text'], "\n") < 500, 'Line-number prefixes add bounded overhead to the source byte and line budgets');
    $next = Source::read('src/long.txt', $read['next_line'], 500);
    check($next['end_line'] === 600 && $read['end_line'] + substr_count($next['text'], "\n") + 1 === 600, 'Byte-bounded excerpts resume without dropping a partial line');
    $read = Source::read('src/long.txt', 1000, 1);
    check($read['text'] === '' && !$read['truncated'], 'Ranges beyond EOF do not invent source or truncation');
    check(Source::read('src/long.txt', 1000, 1, true)['text'] === '', 'Numbered ranges beyond EOF do not invent line prefixes');
    file_put_contents($root . '/src/wide.txt', str_repeat('é', 20000));
    $read = Source::read('src/wide.txt', -2, 0);
    check($read['start_line'] === 1 && strlen($read['text']) <= 32768 && preg_match('//u', $read['text']) && $read['truncated'], 'A single long UTF-8 line is bounded safely and nonpositive ranges normalize');
    $numbered = Source::read('src/wide.txt', -2, 0, true);
    check($numbered['text'] === '1: ' . $read['text'] && $numbered['line_truncated'] && $numbered['next_line'] === null && $numbered['end_line'] === 1, 'Numbered partial UTF-8 lines retain accurate truncation metadata');
    file_put_contents($root . '/src/wide.txt', "\nα\r\nlast\r");
    check(Source::read('src/wide.txt', 1, 500, true)['text'] === "1: \n2: α\r\n3: last\r", 'Numbering preserves blank lines, CRLF and lone CR content');
    file_put_contents($root . '/src/wide.txt', '');
    $empty = Source::read('src/wide.txt', 1, 500, true);
    check($empty['text'] === '' && $empty['total_lines'] === 0 && $empty['end_line'] === 0, 'Numbered empty files retain zero lines and empty text');
    $read = Source::read('src/long.txt', 1, 10000);
    check(substr_count($read['text'], "\n") < 500, 'Large line counts cannot bypass the 500-line limit');
    check(count(Source::files('src', -1)) === 1, 'Nonpositive listing limits normalize to one file');
    foreach ([['char' => "\u{00E9}", 'bytes' => 2], ['char' => "\u{20AC}", 'bytes' => 3], ['char' => "\u{1F600}", 'bytes' => 4]] as $case) {
        for ($cut = 1; $cut < $case['bytes']; $cut++) {
            $start = 32768 - $cut;
            file_put_contents($root . '/src/wide.txt', str_repeat('a', $start) . $case['char'] . str_repeat('z', 512));
            $read = Source::read('src/wide.txt', 1, 10000);
            check($read['truncated'] === true, 'UTF-8 cut at ' . $case['bytes'] . '-byte char offset ' . $cut . ' is truncated');
            check(strlen($read['text']) <= 32768 && preg_match('//u', $read['text']) === 1, 'UTF-8 safe prefix is valid and stays within 32768 bytes');
            check($read['text'] === str_repeat('a', $start), 'UTF-8 safe prefix ends exactly at character start before cut');
        }
    }
    foreach (['../escape', '/etc/passwd', 'C:/Windows/file', 'src/../config/secret.txt', 'src\\example.php', "src/example.php\0", 'config/secret.txt', '.hidden/secret.txt', 'alias/secret.txt', 'outside', 'src/binary.dat', 'src/large.txt'] as $path) {
        $rejected = false;
        try { Source::read($path); } catch (Throwable $error) { $rejected = true; }
        check($rejected, 'Unsafe, private, binary or oversized source is rejected rather than returned');
    }
    foreach (['../', '/tmp', 'config', '.hidden', 'alias', 'outside'] as $path) {
        $rejected = false;
        try { Source::files($path); } catch (Throwable $error) { $rejected = true; }
        check($rejected, 'Caller-provided listing roots cannot change the security boundary');
        expectException(function () use ($path) { Source::search('SECRET', $path); }, $path === '/tmp' ? 'workspace-relative' : ($path === 'alias' || $path === 'outside' ? 'symlinks' : 'Private'));
    }
    Agent\ToolContext::withValues(['frontend' => true], function () {
        expectException(function () { Source::files('.'); }, 'CLI');
        expectException(function () { Source::read('src/example.php'); }, 'CLI');
        expectException(function () { Source::search('example'); }, 'CLI');
    });
    file_put_contents($root . '/src/duplicate.php', '<?php');
    file_put_contents($root . '/duplicate.php', '<?php');
    $files[] = 'src/duplicate.php'; $files[] = 'duplicate.php';
    // Exact top-level paths still win; ambiguous non-root basenames must never be guessed.
    file_put_contents($root . '/src/long.txt.copy', 'copy');
    mkdir($root . '/more', 0700); file_put_contents($root . '/more/long.txt.copy', 'copy');
    try { expectException(function () { Source::read('long.txt.copy'); }, 'Ambiguous'); }
    finally { unlink($root . '/src/long.txt.copy'); unlink($root . '/more/long.txt.copy'); rmdir($root . '/more'); }
} finally {
    chdir($previous);
    foreach (array_reverse($files) as $path) if (is_link($root . '/' . $path) || is_file($root . '/' . $path)) unlink($root . '/' . $path);
    foreach (['src', 'config', '.hidden'] as $path) rmdir($root . '/' . $path);
    rmdir($root); unlink($outside);
}
