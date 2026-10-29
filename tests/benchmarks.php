<?php
require __DIR__ . '/helpers.php';

foreach ([[' value ', 'matches', 'value', 1], ['{"b":2,"a":1.0}', 'matches', ['a'=>1,'b'=>2], 1], ['[]', 'matches', new stdClass(), 0], ['false','matches',0,0], ['x value y','contains','value',1], ['value x','starts_with','value',1], ['x value','ends_with','value',1], ['ABC-27','pcre','/^ABC-[0-9]+$/D',1]] as $case) {
    check(Agent\Benchmarks::score($case[0],$case[1],$case[2]) === (float)$case[3], 'Scorer: ' . $case[1]);
}
expectException(function () { Agent\Benchmarks::question(['name'=>'bad','input'=>['prompt'=>'x'],'scoring_type'=>'pcre','scoring_criteria'=>'[']); }, 'Invalid scoring PCRE');
expectException(function () { Agent\BenchmarkCli::options(['-p','default','-p','other']); }, 'Duplicate');
expectException(function () { Agent\BenchmarkCli::options(['--unknown']); }, 'Unknown');

$db = Agent\Database::connection();
$schema = 'benchmark_test_' . bin2hex(random_bytes(5));
$old = $db->query('SHOW search_path')->fetchColumn();
$socket = stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
$address = stream_socket_get_name($socket,false); fclose($socket);
$process = null;
try {
    $db->exec('CREATE SCHEMA ' . $schema);
    $db->exec('SET search_path TO ' . $schema . ',public');
    foreach (glob(dirname(__DIR__).'/database/*.sql') as $file) $db->exec(file_get_contents($file));
    $counts = Agent\Database::rows('SELECT t.name,count(q.id) AS count FROM benchmark_tests t JOIN benchmark_questions q ON q.testid=t.id GROUP BY t.name');
    check(count($counts) === 3 && array_sum(array_column($counts,'count')) === 30 && min(array_column($counts,'count')) == 10, 'Three useful shipped tests contain ten questions each');
    $process = proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' -S ' . escapeshellarg($address) . ' ' . escapeshellarg(__DIR__.'/fixtures/benchmark_engine.php'), [['file','/dev/null','r'],['file','/dev/null','w'],['file','/dev/null','w']],$pipes);
    for ($i=0;$i<50;$i++) { $probe=@stream_socket_client('tcp://'.$address,$errno,$error,.02); if($probe){fclose($probe);break;} usleep(20000); }
    $config=['url'=>'http://'.$address.'/v1/chat/completions','model'=>'benchmark-fixture','stream'=>false];
    $profile=Agent\Profiles::save(['name'=>'default','config'=>$config]);
    check(Agent\Profiles::defaultFor('generate_titles',$config)['model']==='benchmark-fixture', 'Unassigned task retains supplied current model');
    $other=Agent\Profiles::save(['name'=>'specialist','config'=>array_replace($config,['model'=>'specialist'])]);
    Agent\Profiles::setDefault('generate_titles',['id'=>$other['id']]);
    check(Agent\Profiles::defaultFor('generate_titles',$config)['model']==='specialist', 'Explicit task default selects its saved profile');
    Agent\Database::execute('DELETE FROM profiles WHERE id=?',[$other['id']]);
    check(Agent\Profiles::defaultFor('generate_titles',$config)['model']==='benchmark-fixture', 'Deleting task profile restores fallback through FK');
    $suite=Agent\Database::row("INSERT INTO benchmark_tests(name) VALUES ('fixture-test') RETURNING id");
    $insert=function($prompt) use($suite) {
        Agent\Database::execute('DELETE FROM benchmark_questions WHERE testid=?',[$suite['id']]);
        Agent\Database::execute("INSERT INTO benchmark_questions(testid,position,name,input,scoring_type,scoring_criteria) VALUES (?,1,'Fixture',?::jsonb,'matches','\"FIXTURE_PASS\"'::jsonb)",[$suite['id'],json_encode(['prompt'=>$prompt])]);
    };
    $insert('Return FIXTURE_PASS');
    $queued=Agent\Benchmarks::enqueue('default',$suite['id'],'fixture-owner');
    $insert('BENCH_FAIL');
    Agent\Database::execute("UPDATE profiles SET config=jsonb_set(config,'{model}','\"edited-after-enqueue\"') WHERE id=?",[$profile['id']]);
    $result=Agent\Benchmarks::execute($queued['id']);
    check($result['status']==='completed' && $result['score']===1.0, 'Single run uses immutable question/profile snapshot');
    check($result['results'][0]['completion_tokens']===3 && $result['results'][0]['tokens_per_second']>0, 'Usage and real wall-time are recorded');
    $thread=new Agent\Thread($result['results'][0]['threadid']);
    check(count($thread->messages())===3 && Agent\Run::get($result['results'][0]['runid'])['status']==='completed', 'Raw isolated conversation and shared run lifecycle persist');
    check(!Agent\Database::row("SELECT id FROM runs r WHERE status='queued' AND thread_id=?",[$thread->id]), 'No stray queued run remains');
    $failed=Agent\Benchmarks::execute(Agent\Benchmarks::enqueue('default',$suite['id'],'fixture-owner')['id']);
    check($failed['status']==='failed' && $failed['score']===0.0 && $failed['results'][0]['error'], 'Transport failure is saved, scored zero, and reported failed');
    $insert('BENCH_SLOW');
    $count=0;
    $stopped=Agent\Benchmarks::execute(Agent\Benchmarks::enqueue('default',$suite['id'],'fixture-owner')['id'],function()use(&$count){return ++$count>3;});
    check($stopped['status']==='stopped' && $stopped['results'][0]['status']==='stopped', 'Interrupt cleans up question and benchmark state');
    $summary=Agent\Benchmarks::summary($result);
    check(!isset($summary['snapshot']) && !isset($summary['results'][0]['raw']), 'CLI summary excludes raw provider/configuration data');

    $insert('Return FIXTURE_PASS');
    Agent\Database::execute("UPDATE profiles SET supports_streaming=TRUE WHERE id=?",[$profile['id']]);
    $batch=Agent\BenchmarkRunner::enqueue(['default'],[$suite['id']],'fixture-owner');
    Agent\BenchmarkRunner::control('pause');
    check(Agent\BenchmarkRunner::work()===[] && Agent\Benchmarks::get($batch[0]['id'])['status']==='queued','Global pause prevents dispatch');
    Agent\BenchmarkRunner::control('resume');
    $drained=Agent\BenchmarkRunner::work();
    check(count($drained)===1 && $drained[0]['status']==='completed','Runner drains queued job through the single-run execution path');
    $streamed=Agent\Benchmarks::get($batch[0]['id']);
    check($streamed['results'][0]['first_token_seconds']!==null && $streamed['results'][0]['completion_tokens']===3,'Streaming metrics retain actual usage and first-text time');

    Agent\Database::execute("INSERT INTO benchmark_questions(testid,position,name,input,scoring_type,scoring_criteria) VALUES (?,2,'Second',?::jsonb,'matches','\"FIXTURE_PASS\"'::jsonb)",[$suite['id'],json_encode(['prompt'=>'Return FIXTURE_PASS'])]);
    $pauseJob=Agent\Benchmarks::enqueue('default',$suite['id'],'fixture-owner'); $pausedOnce=false;
    $paused=Agent\Benchmarks::execute($pauseJob['id'],function()use(&$pausedOnce,$pauseJob,$profile){
        if(!$pausedOnce && Agent\Database::row("SELECT id FROM benchmark_results WHERE benchmark_runid=? AND status='completed'",[$pauseJob['id']])) {Agent\BenchmarkRunner::control('pause',$profile['id']);$pausedOnce=true;}return false;
    });
    check($paused['status']==='paused' && count($paused['results'])===1,'Pause finishes the active question and stops at a boundary');
    Agent\BenchmarkRunner::control('resume',$profile['id']);
    $resumed=Agent\Benchmarks::execute($pauseJob['id']);
    check($resumed['status']==='completed' && count($resumed['results'])===2,'Resume continues remaining questions without repeating completed results');
    $cancelJob=Agent\Benchmarks::enqueue('default',$suite['id'],'fixture-owner');
    Agent\BenchmarkRunner::control('stop');
    check(Agent\Benchmarks::get($cancelJob['id'])['status']==='stopped' && Agent\BenchmarkRunner::paused(null),'Stop cancels queued work and leaves queue paused');
    Agent\BenchmarkRunner::control('resume');

    $stale=Agent\Benchmarks::enqueue('default',$suite['id'],'fixture-owner');
    Agent\Database::execute("UPDATE benchmark_runs SET status='running',heartbeat_at=NOW()-INTERVAL '3 minutes' WHERE id=?",[$stale['id']]);
    $orphanThread=Agent\Thread::create('fixture-owner',['settings'=>['manual'=>true,'benchmark'=>true,'profile'=>$config]]);
    $orphanRun=$orphanThread->enqueue('fixture-owner','Not dispatched');
    Agent\Database::execute("INSERT INTO benchmark_results(benchmark_runid,position,question,threadid,runid,status) VALUES (?,1,'{}',?,?,'running')",[$stale['id'],$orphanThread->id,$orphanRun['id']]);
    check(Agent\BenchmarkRunner::recover()===1 && Agent\Benchmarks::get($stale['id'])['status']==='failed','Abandoned work is failed without replaying it');
    check(Agent\Run::get($orphanRun['id'])['status']==='stopped','Recovery cleans up a conversation abandoned before its run was claimed');
    $operatorJob=Agent\Benchmarks::enqueue('default',$suite['id'],'fixture-owner');$operatorStopped=false;
    $operatorResult=Agent\Benchmarks::execute($operatorJob['id'],function()use(&$operatorStopped,$operatorJob){
        if(!$operatorStopped && Agent\Database::row("SELECT id FROM benchmark_results WHERE benchmark_runid=? AND status='running'",[$operatorJob['id']])) {
            $operatorStopped=true;Agent\BenchmarkRunner::control('stop');
        }
        return false;
    });
    check($operatorResult['status']==='stopped' && $operatorResult['results'][0]['status']==='stopped' && Agent\Run::get($operatorResult['results'][0]['runid'])['status']==='stopped','Operator Stop terminalizes active state and cannot be overwritten by late execution');
    Agent\BenchmarkRunner::control('resume');

    $manual=Agent\Benchmarks::enqueue('default',$suite['id'],'fixture-owner',true);
    check(Agent\BenchmarkRunner::work()===[] && Agent\Benchmarks::get($manual['id'])['status']==='queued','Background worker cannot steal a single-run CLI job');
    Agent\BenchmarkRunner::control('stop'); Agent\BenchmarkRunner::control('resume');
    $admin=Agent\Identity::createUser('benchmark_admin','benchmark-private-test-password','Benchmark admin',true);
    $ordinary=Agent\Identity::createUser('benchmark_user','benchmark-private-test-password','Benchmark user');
    expectException(function()use($ordinary){(new Agent\BenchmarkFrontend($ordinary))->report();},'Page access denied');
    $frontend=new Agent\BenchmarkFrontend($admin);
    $data=$frontend->report($suite['id']);
    check(count($data['profiles'])===1 && !isset($data['profiles'][0]['config']) && count($data['points'])===1,'Report lists profiles and measured results without private config');
    $front=Agent\BenchmarkFrontend::frontier([['speed'=>5,'score'=>.7],['speed'=>10,'score'=>.7],['speed'=>8,'score'=>.9]]);
    check(count($front)===2,'Pareto frontier excludes dominated points');
    $state=['user'=>$admin,'csrf'=>str_repeat('a',32)];
    $html=Agent\Views::render('benchmarks',['state'=>$state]);
    check(strpos($html,'Speed versus score')!==false && strpos($html,'benchmark-play')!==false && strpos($html,'Raw metadata')!==false,'PHP report renders chart, controls, and metadata immediately');

    $created=$frontend->action('save-test',['name'=>'Editor test','description'=>'Test editor fixture','model_type'=>'chat']);
    $definition=Agent\Benchmarks::test($created['testid']);
    $saved=$frontend->action('save-question',['testid'=>$definition['id'],'revision'=>$definition['revision'],'name'=>'Typed response','input'=>'{"prompt":"Return FIXTURE_PASS"}','scoring_type'=>'matches','criteria_json'=>'"FIXTURE_PASS"']);
    $changed=Agent\Benchmarks::test($definition['id']);
    check(count($changed['questions'])===1 && $changed['revision']==$definition['revision']+1,'Editor shares JSON contracts and increments test revisions');
    expectException(function()use($definition){Agent\Benchmarks::saveQuestion($definition['id'],['name'=>'Stale','input'=>['prompt'=>'x'],'scoring_type'=>'matches','scoring_criteria'=>'x'],null,$definition['revision']);},'Test changed');
    $_GET=['edit_test'=>$changed['id']];
    $editor=Agent\Views::render('test-suites',['state'=>$state]);
    check(strpos($editor,'data-question-list')!==false && strpos($editor,'data-dialog="benchmark-question"')!==false,'Questions are read-only rows with modal editor links');
    $_GET=['test'=>(string)$changed['id'],'question'=>(string)$changed['questions'][0]['id']];
    $form=Agent\Views::render('benchmark-form',['state'=>$state,'kind'=>'benchmark-question']);
    check(strpos($form,'benchmark-save-question')!==false && strpos($form,'method="post"')!==false,'Modal editor retains ordinary PHP POST forms');
    $_GET=[];
    $frontend->action('remove-question',['testid'=>$changed['id'],'questionid'=>$changed['questions'][0]['id'],'revision'=>$changed['revision'],'confirm'=>'yes']);
    check(!Agent\Benchmarks::test($changed['id'])['questions'],'Confirmed question removal works without changing historical snapshots');
    Agent\BenchmarkRunner::control('pause');
    $manual=Agent\Benchmarks::execute(Agent\Benchmarks::enqueue('default',$suite['id'],'fixture-owner',true)['id']);
    check($manual['status']==='completed','A standalone single-run command does not wait on a paused scheduler queue');
    $frontend->action('play',['profileid'=>$profile['id']]);
    check(Agent\BenchmarkRunner::paused(null),'Per-profile Play does not resume unrelated globally paused work');
    check(!Agent\Database::row('SELECT id FROM benchmark_runs WHERE testid=?',[$changed['id']]),'Implicit queue skips empty draft suites without blocking valid tests');
    Agent\BenchmarkRunner::control('stop');Agent\BenchmarkRunner::control('resume');
} finally {
    if(is_resource($process)){proc_terminate($process);proc_close($process);}
    $db->exec('SET search_path TO '.$old);
    $db->exec('DROP SCHEMA '.$schema.' CASCADE');
}
