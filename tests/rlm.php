<?php
require __DIR__ . '/helpers.php';

// Agent\Rlm::create_expert input guards (throw before any agent/model/db work)
expectException(function () { Agent\Rlm::create_expert('bad name', 'prompt'); }, 'Expert name must be unique, 1-64 letters, digits, dashes or underscores');
expectException(function () { Agent\Rlm::create_expert('', 'prompt'); }, 'Expert name must be unique, 1-64 letters, digits, dashes or underscores');
expectException(function () { Agent\Rlm::create_expert('ok', ''); }, 'Expert system prompt is required');
expectException(function () { Agent\Rlm::create_expert('ok', 'prompt', 'Agent\Tools\Workspace'); }, 'Tool not available to experts: Agent\Tools\Workspace');
expectException(function () { Agent\Rlm::create_expert('ok', 'prompt', 'Agent\Tools\Delegate::create'); }, 'Tool not available to experts: Agent\Tools\Delegate::create');
expectException(function () { Agent\Rlm::create_expert('ok', 'prompt', 'agent\tools\workspace'); }, 'Tool not available to experts: agent\tools\workspace');
expectException(function () { Agent\Rlm::create_expert('ok', 'prompt', '\Agent\Tools\DELEGATE::create'); }, 'Tool not available to experts: Agent\Tools\DELEGATE::create');
expectException(function () { Agent\Rlm::create_expert('ok', 'prompt', 'Patch_replace'); }, 'Tool not available to experts: Patch_replace');
expectException(function () { Agent\Rlm::create_expert('ok', 'prompt', 'NoSuchTool::x'); }, 'Tool not available to experts: NoSuchTool::x');
expectException(function () { Agent\Rlm::create_expert('ok', 'prompt', 'Source::nope'); }, 'Unknown tool method');

// source::READ (mixed case) is allowed by the case-insensitive allowlist and is not refused;
// intentionally not passed to create_expert here because success would create a real agent.

// Agent\Rlm::message_expert input guards
expectException(function () { Agent\Rlm::message_expert([]); }, 'Provide at least one assignment');
expectException(function () { Agent\Rlm::message_expert([['expert' => 'x']]); }, 'Assignment 0 needs string');
expectException(function () { Agent\Rlm::message_expert([['expert' => 'no-such', 'message' => 'hi']]); }, "Unknown expert 'no-such'. Use list_experts.");
$object = new stdClass();
$object->expert = 'no-such';
$object->message = 'hi';
expectException(function () use ($object) { Agent\Rlm::message_expert([$object]); }, "Unknown expert 'no-such'. Use list_experts.");

// Agent\Tools\Verify input guards
expectException(function () { Agent\Tools\Verify::testUnits(['ui_fix_02']); }, 'Not an allowed unit test: ui_fix_02');
expectException(function () { Agent\Tools\Verify::testUnits(['project_recursive_live']); }, 'Not an allowed unit test: project_recursive_live');
expectException(function () { Agent\Tools\Verify::testUnits(['../x']); }, 'Not an allowed unit test: ../x');
expectException(function () { Agent\Tools\Verify::lint('composer.json'); }, 'Lint supports .php, .js and .py files');

// Agent\Tools\Patch path guards
expectException(function () { Agent\Tools\Patch::replace('../x.txt', 'a', 'b'); }, 'Private or invalid workspace path');
expectException(function () { Agent\Tools\Patch::replace('.env', 'a', 'b'); }, 'Private or invalid workspace path');
expectException(function () { Agent\Tools\Patch::create('../x.txt', 'c'); }, 'Private or invalid workspace path');
expectException(function () { Agent\Tools\Patch::create('.env', 'c'); }, 'Private or invalid workspace path');

expectException(function () { Agent\Rlm::message_expert([], 'no-such', 'hi'); }, "Unknown expert 'no-such'");
expectException(function () { Agent\Rlm::message_expert(['expert' => 'no-such', 'message' => 'hi']); }, "Unknown expert 'no-such'");
expectException(function () { Agent\Tools\Patch::replaceLines('composer.json', 1, 1, 'x', 'abc'); }, 'requires the current whole-file sha256');
expectException(function () { Agent\Tools\Patch::replaceLines('composer.json', 3, 2, 'x', str_repeat('a', 64)); }, 'Line range must be inclusive');
check(true, 'all Rlm, Verify and Patch input guards throw as expected');

function rlm_run(array $args): array {
    $p = proc_open(array_merge([PHP_BINARY, __DIR__ . '/../bin/phill-rlm'], $args), [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes);
    fclose($pipes[0]);
    $r = ['out' => stream_get_contents($pipes[1]), 'err' => stream_get_contents($pipes[2])];
    fclose($pipes[1]); fclose($pipes[2]);
    $r['code'] = proc_close($p);
    return $r;
}

$c = rlm_run([]);
check($c['code'] === 2 && strpos($c['err'], 'Usage') !== false, 'no args exits 2 with usage on stderr');
$c = rlm_run(['--help']);
check($c['code'] === 0 && strpos($c['out'], 'Usage') !== false, '--help exits 0 with usage on stdout');
$c = rlm_run(['a', 'b', 'c']);
check($c['code'] === 2, 'three args exits 2');
$c = rlm_run(['nonexistent-file.md']);
check($c['code'] === 1 && strpos($c['err'], 'Prompt file is not a readable file') !== false, 'missing file exits 1 with stderr message');

// Agent\Rlm::canonicalTools (private): canonical spelling, writes flag, dedup, empty input
$canonical = new \ReflectionMethod('Agent\Rlm', 'canonicalTools');
$canonical->setAccessible(true);

$result = $canonical->invoke(null, ['source::READ', '\agent\tools\PATCH', 'fetch']);
check($result['tools'] === ['Agent\Tools\Source::read', 'Agent\Tools\Patch', 'Agent\Tools\Fetch'] && $result['writes'] === true, 'canonicalTools maps mixed-case tools to canonical spelling and sets writes=true');

$result = $canonical->invoke(null, ['Source::read']);
check($result['writes'] === false, 'canonicalTools read-only tool sets writes=false');

$result = $canonical->invoke(null, ['Source::read', 'source::READ']);
check(count($result['tools']) === 1, 'canonicalTools deduplicates case-variant tools to one entry');

$result = $canonical->invoke(null, []);
check($result['tools'] === [] && $result['writes'] === false, 'canonicalTools empty input yields empty tools and writes=false');

// Agent\\Rlm::optionalJson (private): null for empty/invalid input, trimmed string for valid JSON
$optionalJson = new ReflectionMethod('Agent\\Rlm', 'optionalJson');
$optionalJson->setAccessible(true);

foreach (['', '  ', '{}', '[]', 'null', 'NONE', '""'] as $input) {
    check($optionalJson->invoke(null, $input) === null, 'optionalJson returns null for ' . var_export($input, true));
}

check($optionalJson->invoke(null, '{"type":"object"}') === '{"type":"object"}', 'optionalJson returns the trimmed JSON object string');
check($optionalJson->invoke(null, ' {"temperature":0.1} ') === '{"temperature":0.1}', 'optionalJson trims whitespace around valid JSON');

// Agent\Tools\Verify::run (private): timeout kills the whole process group, no orphans
$run = new ReflectionMethod('Agent\\Tools\\Verify', 'run');
$run->setAccessible(true);

// Test 1: a command that spawns a grandchild must not leave an orphan on timeout
$result = $run->invoke(null, [PHP_BINARY, '-r', '$p = proc_open(["sleep","30"], [], $pp); file_put_contents(sys_get_temp_dir()."/rlm-grandchild.pid", proc_get_status($p)["pid"]); sleep(30);'], 2);
check($result['passed'] === false && $result['exit'] === null && strpos($result['output'], 'Timed out') !== false, 'Verify::run times out: passed=false, exit=null, output contains Timed out');
$pidFile = sys_get_temp_dir() . '/rlm-grandchild.pid';
if (is_file($pidFile)) {
    $pid = (int)file_get_contents($pidFile);
    $deadline = microtime(true) + 2;
    while (microtime(true) < $deadline) {
        if (!@posix_kill($pid, 0)) { break; }
        usleep(200000);
    }
    check(!@posix_kill($pid, 0), 'grandchild process is gone after Verify::run timeout');
    if (@posix_kill($pid, 0)) { posix_kill($pid, 9); } // self-cleaning: never leak a process
    unlink($pidFile);
}

// Test 2: a quick normal command still completes successfully
$result2 = $run->invoke(null, [PHP_BINARY, '-r', 'echo "ok";'], 5);
check($result2['passed'] === true && $result2['exit'] === 0 && $result2['output'] === 'ok', 'Verify::run still runs a quick command normally');
// Agent\\\\Tools\\\\Patch::replace whitespace-insensitive fallback
$pid = getmypid();
$scratchDir = 'tests/rlm_scratch_' . $pid;
@mkdir($scratchDir, 0777, true);
register_shutdown_function(function () use ($scratchDir) {
    foreach ((array) glob($scratchDir . '/*') as $f) {
        if (is_file($f)) @unlink($f);
    }
    @rmdir($scratchDir);
});
chdir(__DIR__ . '/..');

$write = function ($rel, $content) { file_put_contents($rel, $content); };
$read = function ($rel) { return file_get_contents($rel); };

// (a) exact single match still works and reports 'exact'
$write("$scratchDir/a.txt", "alpha\nbeta\ngamma\n");
$result = Agent\Tools\Patch::replace("$scratchDir/a.txt", "beta", "BETA");
check($result['matched'] === 'exact', 'Patch::replace exact match reports matched=exact');
check($read("$scratchDir/a.txt") === "alpha\nBETA\ngamma\n", 'Patch::replace exact match updates content');

// (b) search with different indentation (file uses 4 spaces, search uses a tab) matches and reports whitespace-normalized
$write("$scratchDir/b.txt", "    function foo() {\n        return 1;\n    }\n");
$result = Agent\Tools\Patch::replace("$scratchDir/b.txt", "\tfunction foo() {\n\t\treturn 1;\n\t}\n", "function foo() {\n        return 2;\n    }");
check($result['matched'] === 'whitespace-normalized', 'Patch::replace indentation-flexible match reports whitespace-normalized');
check($read("$scratchDir/b.txt") === "    function foo() {\n        return 2;\n    }\n", 'Patch::replace indentation-flexible match updates content without doubling');

// (c) CRLF file with an LF search matches
$write("$scratchDir/c.txt", "line one\r\nline two\r\nline three\r\n");
$result = Agent\Tools\Patch::replace("$scratchDir/c.txt", "line one\nline two", "LINE ONE\nLINE TWO");
check($result['matched'] === 'whitespace-normalized', 'Patch::replace CRLF file with LF search reports whitespace-normalized');
check($read("$scratchDir/c.txt") === "LINE ONE\r\nLINE TWO\r\nline three\r\n", 'Patch::replace CRLF file with LF search keeps CRLF line endings (no mixed endings)');

// (d) search spanning two lines whose line break/blank spacing differs from the file matches
$write("$scratchDir/d.txt", "start\n\n\nmiddle\nend\n");
$result = Agent\Tools\Patch::replace("$scratchDir/d.txt", "start\nmiddle", "START\nMIDDLE");
check($result['matched'] === 'whitespace-normalized', 'Patch::replace flexible line-break match reports whitespace-normalized');
check($read("$scratchDir/d.txt") === "START\nMIDDLE\nend\n", 'Patch::replace flexible line-break match updates content');

// (e) search with leading indentation and trailing newline whose replacement has the same leading indentation and trailing newline does not double them; forces the fallback (internal spacing differs) then asserts exact content
$write("$scratchDir/e.txt", "keep this\n    function foo() {\n        bar();\n    }\nalso keep\n");
$searchE = "    function foo() {\n    bar();\n    }\n";
$replaceE = "    function foo() {\n    baz();\n    }\n";
$result = Agent\Tools\Patch::replace("$scratchDir/e.txt", $searchE, $replaceE);
check($result['matched'] === 'whitespace-normalized', 'Patch::replace leading/trailing whitespace reports whitespace-normalized');
check($read("$scratchDir/e.txt") === "keep this\n    function foo() {\n    baz();\n    }\nalso keep\n", 'Patch::replace leading/trailing whitespace not doubled; exact content asserted');

// (f) a search that matches two places ignoring whitespace but zero exactly is refused
$write("$scratchDir/f.txt", "foo  bar\nfoo   bar\n");
try {
    Agent\Tools\Patch::replace("$scratchDir/f.txt", "foo bar", "X");
    check(false, 'Patch::replace ambiguous whitespace match should throw');
} catch (\Throwable $e) {
    check(strpos($e->getMessage(), 'even ignoring whitespace') !== false, 'Patch::replace refuses ambiguous match with even ignoring whitespace');
}

// (g) a search with no match at all is still refused
$write("$scratchDir/g.txt", "nothing here\n");
try {
    Agent\Tools\Patch::replace("$scratchDir/g.txt", "zzz missing", "X");
    check(false, 'Patch::replace no match should throw');
} catch (\Throwable $e) {
    check(strpos($e->getMessage(), 'Search must match exactly once') !== false, 'Patch::replace refuses no match');
}

// (h) a PHP file edited through the fallback that would produce invalid PHP is refused by the parse check and the file is left unchanged
$write("$scratchDir/h.php", "<?php\nfunction a() {\n    return 1;\n}\n");
$beforeH = $read("$scratchDir/h.php");
try {
    Agent\Tools\Patch::replace("$scratchDir/h.php", "\treturn 1;\n\t}", "\treturn );\n\t}");
    check(false, 'Patch::replace invalid PHP through fallback should throw');
} catch (\Throwable $e) {
    check(true, 'Patch::replace invalid PHP through fallback throws');
}
check($read("$scratchDir/h.php") === $beforeH, 'Patch::replace leaves file unchanged on invalid PHP');
check(true, 'Patch::replace whitespace-insensitive fallback assertions (a)-(h) complete');