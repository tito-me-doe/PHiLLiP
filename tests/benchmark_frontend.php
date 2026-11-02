<?php
require __DIR__.'/helpers.php';
$db=Agent\Database::connection();$schema='benchmark_http_'.bin2hex(random_bytes(5));$old=$db->query('SHOW search_path')->fetchColumn();$oldOptions=getenv('PGOPTIONS');$oldWorkers=getenv('PHP_CLI_SERVER_WORKERS');$processes=[];$log=tmpfile();$cookies=[];
$reserve=function(){$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$address=stream_socket_get_name($socket,false);fclose($socket);return $address;};
$launch=function(array $args)use(&$processes,$log){$process=proc_open('exec '.implode(' ',array_map('escapeshellarg',$args)),[['file','/dev/null','r'],$log,$log],$pipes,dirname(__DIR__));if(!is_resource($process))throw new RuntimeException('Fixture process could not start');$processes[]=$process;return $process;};
$request=function($base,$path,$body=null,$csrf='',$client='admin',$form=false)use(&$cookies){
    if(!isset($cookies[$client]))$cookies[$client]=tmpfile();$cookie=stream_get_meta_data($cookies[$client])['uri'];$curl=curl_init($base.$path);
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie]);
    if($body!==null)curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$form?http_build_query($body):json_encode($body),CURLOPT_HTTPHEADER=>['Content-Type: '.($form?'application/x-www-form-urlencoded':'application/json'),'X-CSRF-Token: '.$csrf]]);
    $text=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);if($text===false)throw new RuntimeException(curl_error($curl));return ['status'=>$status,'text'=>$text,'json'=>json_decode($text,true)];
};
try{
    $db->exec('CREATE SCHEMA '.$schema);$db->exec('SET search_path TO '.$schema.',public');putenv('PGOPTIONS=-c search_path='.$schema.',public');putenv('PHP_CLI_SERVER_WORKERS');putenv('APP_BASE_PATH=');
    foreach(glob(dirname(__DIR__).'/database/*.sql') as $file)$db->exec(file_get_contents($file));
    $admin=Agent\Installation::install(['auth'=>'standalone'],'bench_admin','benchmark-browser-password','Benchmark admin');
    Agent\Identity::createUser('bench_user','benchmark-browser-password','Benchmark user');
    $engine=$reserve();$server=$reserve();$base='http://'.$server;
    $launch([PHP_BINARY,'-S',$engine,__DIR__.'/fixtures/benchmark_engine.php']);
    $profile=Agent\Profiles::save('default',['url'=>'http://'.$engine.'/v1/chat/completions','model'=>'fixture','stream'=>true]);
    Agent\Database::execute('UPDATE profiles SET enabled=TRUE,supports_chat=TRUE,supports_streaming=TRUE,tested_at=NOW() WHERE id=?',[$profile['id']]);
    Agent\Database::execute('DELETE FROM benchmark_tests');
    $test=Agent\Benchmarks::saveTest(['name'=>'browser-suite','model_type'=>'chat']);
    foreach([1,2] as $position)Agent\Benchmarks::saveQuestion($test['id'],['name'=>'Slow fixture '.$position,'input'=>['prompt'=>'BENCH_SLOW return FIXTURE_PASS'],'scoring_type'=>'matches','scoring_criteria'=>'FIXTURE_PASS']);
    $launch([PHP_BINARY,'-S',$server,'-t',dirname(__DIR__).'/public',dirname(__DIR__).'/public/index.php']);
    for($i=0;$i<100;$i++){$socket=@stream_socket_client('tcp://'.$server,$errno,$error,.02);if($socket){fclose($socket);break;}usleep(20000);}
    $anonymous=$request($base,'/api/admin/benchmarks');
    check($anonymous['status']===401,'Anonymous cannot read benchmark data');
    foreach(['admin'=>'bench_admin','user'=>'bench_user'] as $client=>$username){$state=$request($base,'/api/state',null,'',$client)['json'];$login=$request($base,'/api/login',['username'=>$username,'password'=>'benchmark-browser-password'],$state['csrf'],$client);check($login['status']===200,'Fixture login: '.$client);$tokens[$client]=$login['json']['csrf'];}
    check($request($base,'/api/admin/benchmarks',null,'','user')['status']===403,'Ordinary users cannot read report API');
    check($request($base,'/fragments/benchmarks',null,'','user')['status']===403,'Ordinary users cannot obtain benchmark fragments');
    $html=$request($base,'/?view=benchmarks');check($html['status']===200 && strpos($html['text'],'data-benchmark-root')!==false && strpos($html['text'],'benchmark-chart')!==false,'Full page includes a server-rendered report/chart');
    check($request($base,'/api/admin/benchmarks',['action'=>'play'],'wrong')['status']===403,'Process controls reject missing/invalid CSRF');
    check($request($base,'/api/admin/benchmarks',['action'=>'play'],$tokens['admin'])['status']===200,'Administrator can queue work');
    check($request($base,'/api/admin/benchmarks',['action'=>'pause'],$tokens['admin'])['status']===200,'Administrator can pause queue');
    $launch([PHP_BINARY,dirname(__DIR__).'/bin/phill-bench-run','--worker']);
    check($request($base,'/api/admin/benchmarks',['action'=>'play'],$tokens['admin'])['status']===200,'Repeated Play resumes rather than duplicating unfinished work');
    check(Agent\Database::row('SELECT COUNT(*) AS count FROM benchmark_runs')['count']===1,'Duplicate queue submissions do not duplicate the active batch');
    $until=microtime(true)+12;do{$row=Agent\Database::row('SELECT * FROM benchmark_runs ORDER BY id DESC LIMIT 1');if($row['status']==='completed')break;usleep(100000);}while(microtime(true)<$until);
    check($row['status']==='completed','Background worker executes HTTP-queued questions');
    $cliOut=tmpfile();$cliErr=tmpfile();
    $cli=proc_open('exec '.escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/bin/phill-bench-run').' -p default -b browser-suite --json',[['file','/dev/null','r'],$cliOut,$cliErr],$pipes,dirname(__DIR__));
    $cliCode=proc_close($cli);rewind($cliOut);$cliJobs=json_decode(stream_get_contents($cliOut),true);rewind($cliErr);$cliLog=stream_get_contents($cliErr);fclose($cliOut);fclose($cliErr);
    check($cliCode===0 && count($cliJobs)===1 && $cliJobs[0]['status']==='completed' && strpos($cliLog,'observing submitted work')!==false,'CLI cooperates with a persistent worker and returns actual final results');
    $raw=$request($base,'/?view=benchmarks&benchmark='.$row['id']);check(strpos($raw['text'],'Raw saved conversation and response')!==false,'Administrator can inspect raw saved question conversations');
    $modal=$request($base,'/fragments/dialog?kind=benchmark-run&id='.$row['id']);
    check($modal['status']===200 && strpos($modal['text'],'Raw saved conversation and response')!==false,'Run details use a PHP-rendered dialog');
    $metadata=$request($base,'/fragments/dialog?kind=benchmark-metadata&id='.$profile['id']);
    check($metadata['status']===200 && strpos($metadata['text'],'inspection-json')!==false && strpos($metadata['text'],'benchmark-browser-password')===false,'Raw model metadata has a separate credential-free dialog');
    foreach (['benchmark-run'=>$row['id'],'benchmark-metadata'=>$profile['id']] as $kind=>$id) check($request($base,'/fragments/dialog?kind='.$kind.'&id='.$id,null,'','user')['status']===403,'Benchmark dialog enforces report permissions: '.$kind);
    $db->beginTransaction();
    try {
        $ranked=[];
        foreach (['sort-z-fast'=>[.9,2],'sort-a-slow'=>[.9,5],'sort-b-low'=>[.4,.01],'sort-c-zero'=>[0,1],'sort-d-unscored'=>null] as $name=>$ranking) {
            $candidate=Agent\Profiles::save($name,Agent\Config::profile($profile)); $ranked[]=$candidate['id'];
            if ($ranking!==null) {
                $rankRun=Agent\Database::row("INSERT INTO benchmark_runs(testid,profileid,owner_id,status,snapshot,score,elapsed_seconds) SELECT testid,?,owner_id,'completed',snapshot,?,? FROM benchmark_runs WHERE id=? RETURNING id",[$candidate['id'],$ranking[0],$ranking[1],$row['id']]);
                foreach ([1,2] as $position) Agent\Database::execute("INSERT INTO benchmark_results(benchmark_runid,position,question,status,score,elapsed_seconds) VALUES (?,?,'{}','completed',?,?)",[$rankRun['id'],$position,$ranking[0],$ranking[1]/2]);
            }
        }
        $adapter=new Agent\BenchmarkFrontend($admin);
        $ranking=array_values(array_filter(array_column($adapter->report($test['id'])['profiles'],'id'),function($id)use($ranked){return in_array($id,$ranked,true);}));
        check($ranking===$ranked,'Leaderboard sorts score descending, duration ascending, zero scores before unscored profiles, without requiring token metrics');
        Agent\Database::execute("UPDATE benchmark_runs SET snapshot=jsonb_set(snapshot,'{test,revision}','0') WHERE profileid=?",[$ranked[0]]);
        $ranking=array_values(array_filter(array_column($adapter->report($test['id'])['profiles'],'id'),function($id)use($ranked){return in_array($id,$ranked,true);}));
        check($ranking===$ranked,'Old revisions retain scores until rerun');
        $extra=Agent\Benchmarks::saveTest(['name'=>'aggregation-fixture']);
        Agent\Benchmarks::saveQuestion($extra['id'],['name'=>'Aggregate question','input'=>['prompt'=>'Return FIXTURE_PASS'],'scoring_type'=>'matches','scoring_criteria'=>'FIXTURE_PASS']);
        $before=$adapter->report()['profiles'];
        $before=array_values(array_filter($before,function($p)use($ranked){return $p['id']===$ranked[0];}))[0]['benchmark']['score'];
        check(abs($before-.9)<.00001,'Adding an unrun test does not invalidate the All-tab score');
        $aggregateRun=Agent\Database::row("INSERT INTO benchmark_runs(testid,profileid,owner_id,status,snapshot,score,elapsed_seconds) SELECT ?,?,owner_id,'completed',jsonb_set(snapshot,'{questions}','[{}]'::jsonb),0,3 FROM benchmark_runs WHERE id=? RETURNING id",[$extra['id'],$ranked[0],$row['id']]);
        Agent\Database::execute("INSERT INTO benchmark_results(benchmark_runid,position,question,status,score,elapsed_seconds) VALUES (?,1,'{}','completed',0,3)",[$aggregateRun['id']]);
        $all=$adapter->report()['profiles'];
        $combined=array_values(array_filter($all,function($p)use($ranked){return $p['id']===$ranked[0];}))[0]['benchmark'];
        check(abs($combined['score']-.6)<.00001 && $combined['seen']===3 && $combined['elapsed_seconds']===5.0,'All weights total earned scores by questions seen and sums wall time, not test percentages or token speeds');
    } finally { $db->rollBack(); }
    $editor=$request($base,'/benchmarks?test='.$test['id'].'&questions=1');check(strpos($editor['text'],'data-question-list')!==false,'Readonly question editor lives inside Benchmarks');
    $library=$request($base,'/benchmarks');check(strpos($library['text'],'benchmark-tabs')!==false && strpos($library['text'],'Recent runs')===false && strpos($library['text'],'Queue enabled')===false && $request($base,'/test-suites')['status']===404,'All-tab leaderboard replaces the separate suite page, queue section, and recent runs');
    $definition=Agent\Benchmarks::test($test['id']);
    $save=$request($base,'/',['action'=>'benchmark-save-test','csrf'=>$tokens['admin'],'testid'=>$test['id'],'revision'=>$definition['revision'],'name'=>'Updated browser suite','description'=>'Edited without JavaScript','model_type'=>'chat'],'','admin',true);
    check($save['status']===303 && Agent\Benchmarks::test($test['id'])['name']==='Updated browser suite','Ordinary PHP forms save editor changes and redirect');
    check($request($base,'/api/admin/benchmarks',['action'=>'save-test','testid'=>$test['id'],'revision'=>$definition['revision'],'name'=>'Stale overwrite','model_type'=>'chat'],$tokens['admin'])['status']===409,'HTTP editor rejects stale test revisions');
    if(in_array('--browser',$argv,true)){
        $code=proc_close(proc_open('python3 '.escapeshellarg(__DIR__.'/ui_benchmarks.py').' '.escapeshellarg($base),[['file','/dev/null','r'],STDOUT,STDERR],$pipes));check($code===0,'Browser benchmark workflow');
    }
}catch(Throwable $e){rewind($log);fwrite(STDERR,substr(stream_get_contents($log),-10000));throw $e;}
finally{
    foreach(array_reverse($processes) as $process){proc_terminate($process);proc_close($process);}foreach($cookies as $cookie)fclose($cookie);
    $oldOptions===false?putenv('PGOPTIONS'):putenv('PGOPTIONS='.$oldOptions);$oldWorkers===false?putenv('PHP_CLI_SERVER_WORKERS'):putenv('PHP_CLI_SERVER_WORKERS='.$oldWorkers);
    $db->exec('SET search_path TO '.$old);$db->exec('DROP SCHEMA '.$schema.' CASCADE');
}
