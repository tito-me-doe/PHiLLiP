<?php
require __DIR__ . '/helpers.php';
use Agent\Log;
use Agent\Runtime;
$file = tmpfile(); $path = stream_get_meta_data($file)['uri'];
Log::configure(['user' => Log::OFF, 'admin' => Log::OFF, 'console' => Log::OFF, 'file' => Log::OFF]);
check(Log::format('Count %d %s', 3, 'items') === 'Count 3 items', 'Logger detects sprintf input');
check(Log::format('%d', true) === '1', 'Sprintf retains scalar types');
check(Log::format('Value %d', 3, ['extra' => true]) === 'Value 3 {"extra":true}', 'Additional arguments are retained after sprintf formatting');
check(strpos(Log::format('%s %d', ['one' => 1]), 'one') !== false, 'Malformed sprintf gracefully becomes useful argument text');
$cycle = new stdClass(); $cycle->self = $cycle;
check(strpos(Log::format($cycle), 'circular') !== false, 'Circular objects are safe to log');
$array = []; $array['self'] = &$array;
check(strpos(Log::format($array), 'depth limit') !== false, 'Recursive arrays are bounded');
$throwing = new class implements JsonSerializable { public function jsonSerialize() { throw new RuntimeException('fixture'); } };
check(strpos(Log::format($throwing), 'unprintable') !== false, 'Throwing serializers do not interrupt logging');
check(strpos(Log::format(['password' => 'PRIVATE', 'nested' => ['api_key' => 'PRIVATE']]), 'PRIVATE') === false, 'Sensitive structured fields are redacted');
foreach ([['csrf' => 'PRIVATE', 'PHPSESSID' => 'PRIVATE', 'passwd' => 'PRIVATE'],
    '{"config":{"headers":["Authorization: Bearer PRIVATE"],"csrf":"PRIVATE"}}',
    ['  X-Auth-Token : PRIVATE', 'Cookie: PRIVATE', 'Set-Cookie: PRIVATE'],
    'https://someone:PRIVATE@example.invalid/?%61pi_key=PRIVATE&normal=retained',
    '{"password":"PRIVATE", invalid JSON'] as $raw) {
    check(strpos(Log::format($raw), 'PRIVATE') === false, 'Raw traces redact encoded configurations, header lists and credential URLs');
}
check(Log::format('[Warning] connection retry') === '[Warning] connection retry', 'Ordinary bracketed diagnostics are not mistaken for JSON');
check(strpos(Log::format('https://example.invalid/?normal=retained'), 'normal=retained') !== false, 'Non-sensitive URL details remain useful in traces');
foreach (['max_tokens', 'max_completion_tokens', 'prompt_tokens', 'completion_tokens', 'total_tokens', 'cached_tokens', 'reasoning_tokens', 'token_count'] as $key) {
    foreach ([0, 0.0, 42, 42.0, '0042'] as $count) check(json_decode(Log::format([$key => $count]), true)[$key] == $count, 'Known numeric token limits and usage remain readable: ' . $key);
    foreach ([true, null, -1, 3.5, INF, NAN, '-0', "42\n", '4.2', 'secret', str_repeat('9', 400)] as $invalid) check(json_decode(Log::format([$key => $invalid]), true)[$key] === '[redacted]', 'Invalid token-count shapes never bypass redaction: ' . $key);
}
check(json_decode(Log::format(['access_token' => 42, 'unknown_token' => '0042']), true) === ['access_token' => '[redacted]', 'unknown_token' => '[redacted]'], 'Numeric credential values and unknown token fields stay private');
Runtime::bindIdentity(['id' => 'fixture-user', 'is_admin' => false]);
Log::with(['user' => Log::VERBOSE, 'admin' => Log::VERBOSE, 'console' => Log::OFF, 'file' => Log::VERBOSE, 'path' => $path], function () {
    try { call_user_func(function ($password) { throw new RuntimeException('raw trace marker'); }, 'PRIVATE_POSITIONAL'); }
    catch (Throwable $error) { $trace = Log::trace('Trace fixture', $error, ['input' => ['value' => 'retained']]); }
    check($trace['priority'] === Log::VERBOSE && strpos($trace['message'], '"trace"') !== false && strpos($trace['message'], 'retained') !== false, 'Verbose traces retain raw inputs and source call paths');
    check(strpos($trace['message'], 'PRIVATE_POSITIONAL') === false, 'Exception traces omit unlabelled positional argument secrets');
    check(strpos(json_encode(Log::browserMessages()), 'raw trace marker') === false, 'Raw traces never become user-visible even when user minimum is Verbose');
});
ftruncate($file, 0);
foreach ([-4 => 'Verbose', -3 => 'Debug', -2 => 'Info', -1 => 'Warning', 0 => 'Success', 1 => 'Error', 2 => 'Fatal'] as $level => $name) check(Log::write($level, null, true, 1.25, ['test' => 'yes'], new RuntimeException('sample'))['label'] === $name, 'Priority mapping: ' . $name);
check(Log::write('bad level', $cycle)['priority'] === Log::ERROR, 'Unknown priority safely falls back to error');
Log::with(['console' => Log::VERBOSE, 'file' => Log::VERBOSE, 'path' => $path], function () { Log::writeDebug('Scoped fixture', ['password' => 'PRIVATE']); });
check(Log::$minConsolePriority === Log::OFF && Log::$minFilePriority === Log::OFF, 'Scoped priority overrides restore prior configuration');
expectException(function () { Log::with(['file' => Log::DEBUG], function () { throw new RuntimeException('scope marker'); }); }, 'scope marker');
check(Log::$minFilePriority === Log::OFF, 'Scoped configuration restores after an exception');
$line = json_decode(trim(file_get_contents($path)), true);
check($line['priority'] === Log::DEBUG && strpos($line['message'], 'PRIVATE') === false, 'File log is structured JSON without secrets or ANSI');
Log::with(['file' => Log::ERROR, 'path' => '/nonexistent-phillip-fixture/log'], function () { Log::writeError($GLOBALS['argv'], 'Cannot write file'); });
check(true, 'Unavailable file sink does not throw');
check(Agent\Terminal::strip("\033[31mred\033[0m\033]0;title\007") === 'red', 'Terminal strips ANSI and OSC from untrusted text');
$terminalControls = "\xC2\x9B\xC2\x9D\xD8\x9C\xE2\x80\x8E\xE2\x80\x8F\xE2\x80\xAA\xE2\x80\xAB\xE2\x80\xAC\xE2\x80\xAD\xE2\x80\xAE\xE2\x81\xA6\xE2\x81\xA7\xE2\x81\xA8\xE2\x81\xA9";
check(Agent\Terminal::strip('before' . $terminalControls . 'after') === 'beforeafter', 'Terminal removes UTF-8 C1 and every bidirectional formatting control');
check(Agent\Terminal::strip("caf\xC3\xA9 \xE6\x97\xA5\xE6\x9C\xAC \xF0\x9F\x99\x82\n\t") === "caf\xC3\xA9 \xE6\x97\xA5\xE6\x9C\xAC \xF0\x9F\x99\x82\n\t", 'Terminal preserves ordinary Unicode, emoji, newlines and tabs');
check(Agent\Terminal::strip("bad\xFF" . $terminalControls . 'after') === "bad\xFFafter", 'Terminal filtering does not fail on invalid UTF-8 bytes');
check(Log::format('before' . $terminalControls . 'after') === 'beforeafter', 'Shared logger also removes Unicode terminal controls before routing messages');
putenv('FORCE_COLOR=1'); putenv('NO_COLOR=1');
check(Agent\Terminal::format('plain', 'red') === 'plain', 'NO_COLOR overrides forced color');
putenv('NO_COLOR'); check(strpos(Agent\Terminal::format('red', 'red'), "\033[") === 0, 'Terminal can explicitly enable ANSI'); putenv('FORCE_COLOR');
Runtime::bindIdentity(['id' => 'fixture-admin', 'is_admin' => true]);
Log::with(['console' => Log::VERBOSE], function () { Log::writeVerbose('SCOPED_DEVELOPER_SECRET'); });
check(strpos(json_encode(Log::browserMessages()), 'SCOPED_DEVELOPER_SECRET') !== false, 'Scoped browser console thresholds survive footer delivery');
Runtime::bindIdentity(null);
check(strpos(json_encode(Log::browserMessages()), 'SCOPED_DEVELOPER_SECRET') === false, 'Privileged diagnostics are not returned to ordinary users');
Log::with(['file' => Log::OFF, 'database' => Log::ERROR], function () { Log::writeError('Database sink fixture'); });
$q = Agent\Database::connection()->query("DELETE FROM logs WHERE raw->>'message' = 'Database sink fixture' RETURNING id");
check((bool)$q->fetchColumn(), 'Optional database sink uses the shared priority mapping');
fclose($file);
