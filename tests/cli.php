<?php
require __DIR__ . '/helpers.php';
putenv('PHP_CLI_SERVER_WORKERS');
$process = proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:18998 ' . escapeshellarg(__DIR__ . '/fixtures/cli_engine.php'), [['file','/dev/null','r'],['file','/dev/null','w'],['file','/dev/null','w']], $pipes);
require __DIR__ . '/fixtures/cli_process.php';
$db = Agent\Database::connection(); $profile = 'fixture-cli-' . bin2hex(random_bytes(4)); $threads = []; $createdUser = null;
$config = ['url'=>'http://127.0.0.1:18998/v1/chat/completions','model'=>'inspect','max_retries'=>0,'max_contract_corrections'=>0];
try {
    usleep(200000);
    $environment = ['DB_DSN'=>'sqlite::memory:','PROFILE_JSON'=>__DIR__ . '/fixtures/cli-profiles.json'];
    $result = cliProcess(['ordinary','prompt'], '', false, $environment); $body = json_decode($result['out'], true);
    check($result['status']===0 && $body['messages'][0]['content']==='ordinary prompt' && !isset($body['tools']) && !$body['stream'], 'Executable shebang CLI uses default model and zero tools, even if profile grants tools');
    check(strpos($result['out'], "\033")===false && strpos($result['out'], '[Debug]')===false
        && strpos($result['err'], '[Debug] CLI options parsed')!==false && strpos($result['err'], 'ordinary prompt')===false,
        'Successful plain request keeps answer-only stdout and safe diagnostics on stderr');
    foreach (['--debug', '--verbose'] as $loggingFlag) {
        $result = cliProcess([$loggingFlag, '-p', json_encode($config), 'logged prompt'], '', false, $environment);
        $body = json_decode($result['out'], true);
        check($result['status']===0 && is_array($body) && $body['messages'][0]['content']==='logged prompt'
            && strpos($result['out'], '[Debug]')===false && strpos($result['out'], '[Verbose]')===false,
            $loggingFlag . ' preserves the final-only stdout answer');
        check(strpos($result['err'], '[Debug] CLI options parsed')!==false && strpos($result['err'], '[Debug] CLI request completed')!==false,
            $loggingFlag . ' emits CLI diagnostics on stderr');
        if ($loggingFlag === '--debug') {
            check(strpos($result['err'], 'logged prompt')===false && strpos($result['err'], 'Model request')===false
                && strpos($result['err'], 'Model response')===false, 'Debug mode does not disclose raw prompt or model payloads');
        } else {
            check(strpos($result['err'], 'Model request')!==false && strpos($result['err'], 'Model response')!==false
                && strpos($result['err'], 'logged prompt')!==false, 'Verbose mode includes raw model request and response traces on stderr');
        }
    }
    $result = cliProcess(['-p','inherited','hello'], '', false, $environment); $body = json_decode($result['out'], true);
    check(count($body['messages'])===1 && !isset($body['tools']), 'Inherited profile cannot inject tools or old messages');
    $result = cliProcess([], 'piped prompt', false, $environment); check(json_decode($result['out'],true)['messages'][0]['content']==='piped prompt','Piped stdin is accepted without options');
    $result = cliProcess(['summarize'], 'source text', false, $environment); check(json_decode($result['out'],true)['messages'][0]['content']==="summarize\n\nsource text",'Prompt and piped data compose correctly');
    $result = cliProcess(['--','-literal prompt'], '', false, $environment); check(json_decode($result['out'],true)['messages'][0]['content']==='-literal prompt','Option terminator supports dash-prefixed prompts');
    $result = cliProcess(['-p',json_encode($config),'inline'], '', false, $environment); check(json_decode($result['out'],true)['model']==='inspect','Inline profile-compatible JSON works');
    $saved = Agent\Profiles::save($profile,$config,false);
    foreach ([$profile,(string)$saved['id']] as $reference) { $result = cliProcess(['-p',$reference,'named']); check($result['status']===0 && json_decode($result['out'],true)['model']==='inspect','Database profile reference: '.$reference); }
    $contract = '{"ready":{"type":"boolean"}}';
    $result = cliProcess(['-p',json_encode($config),'-c',$contract,'contract']); check($result['status']===0 && json_decode($result['out'],true)===['ready'=>true], 'JSON contract validates final stdout');
    $file = tmpfile(); $path = stream_get_meta_data($file)['uri']; fwrite($file,$contract);
    $result = cliProcess(['-p',json_encode($config),'-c',$path,'contract']); fclose($file); check($result['status']===0 && json_decode($result['out'],true)['ready'], 'Contract can be read from a JSON file');
    $result = cliProcess(['-p',json_encode(array_replace($config,['model'=>'invalid-contract'])),'-c',$contract,'bad']);
    check($result['status']===1 && $result['out']==='' && strpos($result['err'],'Contract correction limit')!==false, 'Invalid contract output fails without leaking intermediate answers to stdout');
    $result = cliProcess(['-p','missing-health-contract-profile','-c',$contract], '', false, $environment);
    check($result['status']!==0 && $result['out']==='', 'Missing health profile cannot replace a requested JSON contract with plain text');
    $result = cliProcess(['--unknown']); check($result['status']===2 && $result['out']==='' && strpos($result['err'],'Unknown option')!==false,'Usage errors have a distinct exit code and stderr message');
    $result = cliProcess(['-t','function () { echo "unsafe"; }','test']); check($result['status']===2 && $result['out']==='','CLI rejects closure expressions rather than evaluating them');
    if (posix_geteuid()===0) {
        foreach ([['-t','Fetch','hello'],['-s',str_repeat('a',32),'hello']] as $args) { $result=cliProcess($args); check($result['status']===1 && $result['out']==='' && strpos($result['err'],'Root')!==false,'Root refuses tools and session credentials'); }
    }
    $result = cliProcess(['-p',json_encode($config),'-t','Fetch','tools'], '', true); $body=json_decode($result['out'],true);
    check($result['status']===0 && count($body['tools'])===1 && $body['tools'][0]['function']['name']==='Agent_Tools_Fetch_fetch','Unprivileged CLI registers named tools through reflection');
    $result = cliProcess(['-p',json_encode($config),'-s',str_repeat('a',32),'invalid'], '', true);
    check($result['status']===1 && $result['out']==='' && strpos($result['err'],'Session')!==false,'Invalid sessions never fall back to Unix identity');
    foreach ([SIGINT=>130,SIGTERM=>143] as $signal=>$status) {
        $result = cliProcess(['-p',json_encode(array_replace($config,['model'=>'slow'])),'interrupt'], '', false, [], $signal);
        check($result['status']===$status && $result['out']==='', 'Signal interruption returns '.$status.' without partial stdout');
        usleep(2600000);
    }
} finally {
    proc_terminate($process); proc_close($process);
    $db->prepare('DELETE FROM profiles WHERE name = ?')->execute([$profile]);
}
