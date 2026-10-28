<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/fixtures/cli_process.php';
putenv('PHP_CLI_SERVER_WORKERS');
$process = proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:18998 ' . escapeshellarg(__DIR__ . '/fixtures/cli_engine.php'), [['file','/dev/null','r'],['file','/dev/null','w'],['file','/dev/null','w']], $pipes);
$db = Agent\Database::connection(); $threads = []; $sessionPaths = []; $createdUser = null; $addedMembership = false; $credentialRef = null;
$marker = 'CLI_SCOPE_FIXTURE-' . bin2hex(random_bytes(4));
try {
    usleep(200000);
    $users=Agent\Mappings::rows('users',['username'=>'www-data']);
    $mapped=$users ? Agent\Identity::user($users[0]['id']) : Agent\Identity::createUser('www-data','fixture-password-never-used','CLI fixture',false);
    if (!$users) $createdUser=$mapped['id'];
    if (!Agent\Access::inTeam($mapped['id'],'engineering')) { Agent\Mappings::insert('memberships',['user_id'=>$mapped['id'],'team_id'=>'engineering']); $addedMembership=true; }
    $alice=Agent\Identity::authenticate('alice','phillip-demo');
    foreach (['private'=>$mapped['id'],'other-private'=>'fixture-other','team'=>'fixture-other','organization'=>'fixture-other','alice'=>$alice['id']] as $kind=>$owner) {
        $thread=Agent\Thread::create($owner,$marker.' '.$kind,['manual'=>true],$kind==='team'?'team':($kind==='organization'?'organization':'private'),$kind==='team'?'engineering':null);$threads[]=$thread;
        $run=$thread->enqueue($owner,$marker.' '.$kind);$source=$thread->messages()[1]['id'];
        Agent\Knowledge::remember($thread->id,$owner,$marker.' '.$kind,$marker,[$source],'has',$kind);
        if ($kind==='other-private') {
            $published=Agent\Knowledge::remember($thread->id,$owner,$marker.' published',$marker,[$source]);
            $db->prepare("UPDATE claims SET visibility='organization' WHERE id=?")->execute([$published]);
        }
    }
    $context=['memory_restricted'=>true,'user_id'=>null,'thread_id'=>$threads[1]->id,'memory_owner'=>'fixture-other'];
    $memories=Agent\ToolContext::withValues($context,function () use($marker){return Agent\Tools\Memory::recall($marker,25);});
    $content=implode(' ',array_column($memories,'content'));
    check(strpos($content,$marker.' other-private')!==false && strpos($content,$marker.' private')===false && strpos($content,$marker.' team')===false,'Unknown CLI audience sees company knowledge and its current thread, never other private/team knowledge');
    $results=Agent\ToolContext::withValues($context,function () use($marker){return Agent\Tools\Memory::searchConversations($marker,25);});
    check(!array_diff(array_column($results,'thread_id'),[$threads[1]->id,$threads[3]->id]),'Conversation SQL/vector retrieval shares the restricted audience');
    expectException(function()use($context,$threads){Agent\ToolContext::withValues($context,function()use($threads){Agent\Tools\Memory::readMessage((int)$threads[0]->messages()[1]['id']);});},'access denied');
    $edges=Agent\ToolContext::withValues($context,function()use($marker){return Agent\Tools\Memory::traverse($marker);});
    check(!array_diff(array_column($edges,'object'),['other-private','organization']),'Graph traversal applies the same visibility boundary');
    $config=['url'=>'http://127.0.0.1:18998/v1/chat/completions','model'=>'memory','temperature'=>0,'max_retries'=>0];
    $prompt='Remember the source marker '.$marker; // Fixture model deliberately requests recall, not publication.
    $result=cliProcess(['-p',json_encode($config),'-t','Memory',$prompt],'',true);
    check($result['status']===0,'Actual unprivileged CLI executes memory through persisted Thread/Run: '.$result['err']);
    $content=$result['out'];
    check(strpos($content,$marker.' private')!==false && strpos($content,$marker.' team')!==false && strpos($content,$marker.' other-private')===false,'Mapped Unix username receives its private and team memories, not another users private memory');
    $result=cliProcess(['-p',json_encode($config),'-t','Memory',$prompt],'',true,['PHILLIP_TEST_UID'=>'65534','USER'=>'www-data','LOGNAME'=>'www-data']);
    check($result['status']===0 && strpos($result['out'],$marker.' organization')!==false && strpos($result['out'],$marker.' private')===false && strpos($result['out'],$marker.' team')===false,'Unmapped Unix user cannot gain private/team memories by spoofing environment usernames');
    $command='exec '.escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/fixtures/cli_session.php').' alice';
    $child=proc_open($command,[['file','/dev/null','r'],['pipe','w'],['file','/dev/null','w']],$streams);$sid=stream_get_contents($streams[1]);fclose($streams[1]);check(proc_close($child)===0 && preg_match('/^[a-zA-Z0-9,-]{16,256}$/D',$sid),'Created isolated authenticated session fixture');
    $sessionPath=(ini_get('session.save_path') ?: sys_get_temp_dir()).'/sess_'.$sid; $sessionPaths[]=$sessionPath; chown($sessionPath,33);
    $result=cliProcess(['-p',json_encode($config),'-s',$sid,'-t','Memory',$prompt],'',true);
    check($result['status']===0 && strpos($result['out'],$marker.' alice')!==false && strpos($result['out'],$marker.' private')===false,'Session-backed CLI uses the authenticated frontend identity, not the Unix service account');
    foreach (['visitor'=>'denied','alice'=>'expired'] as $username=>$mode) {
        $command='exec '.escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/fixtures/cli_session.php').' '.escapeshellarg($username).($mode==='expired'?' expired':'');
        $child=proc_open($command,[['file','/dev/null','r'],['pipe','w'],['file','/dev/null','w']],$streams);$testSid=stream_get_contents($streams[1]);fclose($streams[1]);check(proc_close($child)===0 && preg_match('/^[a-zA-Z0-9,-]{16,256}$/D',$testSid),'Created '.$mode.' session fixture');
        $testPath=(ini_get('session.save_path') ?: sys_get_temp_dir()).'/sess_'.$testSid;$sessionPaths[]=$testPath;chown($testPath,33);
        $result=cliProcess(['-p',json_encode($config),'-s',$testSid,'-t','Memory',$prompt],'',true);
        check($result['status']===1 && $result['out']==='' && strpos($result['err'],$mode==='expired'?'Session':'permission denied')!==false,'Session-backed CLI rejects '.$mode.' credentials/tools without fallback');
    }
    $headers=['Authorization'=>'Bearer CLI_TEST_AUTH_SECRET'];$credentialRef='credentials.'.hash('sha256',json_encode($headers));
    $result=cliProcess(['-p',json_encode(array_replace($config,['model'=>'memory-auth','headers'=>$headers])),'-t','Memory',$prompt],'',true);
    check($result['status']===0,'Persisted CLI pipeline supplies authentication headers to the actual engine');
    $q=$db->prepare('SELECT t.settings::text AS data FROM threads t WHERE t.title=? UNION ALL SELECT m.raw::text AS data FROM messages m JOIN threads t ON t.id=m.thread_id WHERE t.title=?');$q->execute(['CLI: Remember the source marker '.$marker,'CLI: Remember the source marker '.$marker]);
    check(strpos(implode(' ',$q->fetchAll(PDO::FETCH_COLUMN)),'CLI_TEST_AUTH_SECRET')===false,'Inline profile credentials never appear in frontend-visible thread or message payloads');
    $db->prepare('UPDATE users SET active=FALSE WHERE id=?')->execute([$createdUser ?: 'nonexistent-fixture']);
    if ($createdUser) {
        $result=cliProcess(['-p',json_encode($config),'-t','Memory',$prompt],'',true);
        check($result['status']===0 && strpos($result['out'],$marker.' private')===false && strpos($result['out'],$marker.' team')===false,'Inactive mapped account loses personal/team memory access');
    }
} finally {
    proc_terminate($process);proc_close($process);
    foreach($threads as $thread)$thread->delete();
    $q=$db->prepare('SELECT id FROM threads WHERE title=?');$q->execute(['CLI: Remember the source marker '.$marker]);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id)(new Agent\Thread($id))->delete();
    foreach($sessionPaths as $path)if(is_file($path))unlink($path);
    if($addedMembership)Agent\Mappings::delete('memberships',['user_id'=>$mapped['id'],'team_id'=>'engineering']);
    if($createdUser)Agent\Mappings::delete('users',['id'=>$createdUser]);
    if($credentialRef)$db->prepare('DELETE FROM settings WHERE key=?')->execute([$credentialRef]);
}
