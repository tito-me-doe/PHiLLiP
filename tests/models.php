<?php
require __DIR__.'/helpers.php';
class ModelSummaryProbe extends Agent\Request { public function summary($messages){return $this->summarize($messages,1024);} }
$db=Agent\Database::connection();$schema='model_test_'.bin2hex(random_bytes(5));$old=$db->query('SHOW search_path')->fetchColumn();$process=null;
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$address=stream_socket_get_name($socket,false);fclose($socket);
try {
    $db->exec('CREATE SCHEMA '.$schema);$db->exec('SET search_path TO '.$schema.',public');
    foreach(glob(dirname(__DIR__).'/database/*.sql') as $file)$db->exec(file_get_contents($file));
    $process=proc_open('exec '.escapeshellarg(PHP_BINARY).' -S '.escapeshellarg($address).' '.escapeshellarg(__DIR__.'/fixtures/models_engine.php'),[['file','/dev/null','r'],['file','/dev/null','w'],['file','/dev/null','w']],$pipes);
    for($i=0;$i<50;$i++){$probe=@stream_socket_client('tcp://'.$address,$errno,$error,.02);if($probe){fclose($probe);break;}usleep(20000);}
    $config=['url'=>'http://'.$address.'/v1/chat/completions','model'=>'native-fixture','stream'=>false];
    foreach(['openai','ollama','tei'] as $format){
        $result=Agent\Models::native('embed',['texts'=>['password','cake']],array_replace($config,['request_schema'=>$format]));
        check(count($result['data']['vectors'])===2 && count($result['data']['vectors'][0])===3,'Embedding schema: '.$format);
    }
    foreach(['openai','tei'] as $format){
        $rank=Agent\Models::native('rerank',['query'=>'password','documents'=>['cake','password']],array_replace($config,['request_schema'=>$format]));
        check(array_column($rank['data'],'index')===[1,0],'Reranking schema: '.$format);
    }
    expectException(function()use($config){Agent\Models::native('embed',['texts'=>['a','b']],array_replace($config,['model'=>'bad-dimensions']));},'dimensions differ');
    expectException(function(){Agent\Models::texts(['']);},'nonempty');
    $chat=Agent\Profiles::save(['name'=>'default','config'=>array_replace($config,['model'=>'chat-fixture'])]);
    $special=Agent\Profiles::save(['name'=>'specialist','config'=>array_replace($config,['request_type'=>'rerank'])]);
    $classification=Agent\Models::classify('Please refund this invoice',['billing','technical']);
    check($classification['label']==='billing','Classification falls back to the current/general-purpose chat model');
    $extracted=Agent\Models::extract('Invoice INV-7 total 42',['invoice_id'=>['type'=>'string'],'total'=>['type'=>'number']]);
    check($extracted['invoice_id']==='INV-7' && $extracted['total']===42,'Extraction fallback validates its contract');
    check(Agent\Tools\Tasks::extract('The dominant color is red.',['type'=>'string'])==='red','Extraction returns the contract shape, including scalar JSON, without imposing an array return type');
    Agent\Profiles::setDefault('classify',['id'=>$special['id']]);Agent\Profiles::setDefault('extract',['id'=>$special['id']]);
    $taskModels=[];
    Agent\Events::withListener(function($event)use(&$taskModels){if($event['type']==='model.task.started')$taskModels[]=$event['data']['model'];},function(){
        Agent\Models::classify('Refund request',['billing','technical']);Agent\Models::extract('Invoice INV-7 total 42',['invoice_id'=>'string','total'=>'number']);
    });
    check($taskModels===['native-fixture','native-fixture'],'Classification and extraction use their explicit specialist assignments');
    Agent\Profiles::setDefault('summarize',['id'=>$special['id']]);
    check((new ModelSummaryProbe($config))->summary([['role'=>'user','content'=>'Remember invoice INV-7.']])==='red','Summarization explicitly uses chat even when the assigned profile defaults to another operation');
    $rank=Agent\Models::rerank('password',['password reset','cake recipe']);
    check($rank[0]['index']===0,'No reranker assignment uses bounded chat ranking');
    expectException(function(){Agent\Models::embed(['password']);},'Configure a working embed');
    Agent\Profiles::setDefault('embed',['id'=>$special['id']]);Agent\Profiles::setDefault('rerank',['id'=>$special['id']]);
    $embeddings=Agent\Models::embed(['password','cake']);check(count($embeddings['vectors'])===2,'Explicit embedding task default works');
    $similar=Agent\Tools\Tasks::similarity('password',['cake recipe','password reset']);check($similar[0]['index']===1,'Agent embedding tool returns useful similarity ranking, not raw vector dumps');
    $rank=Agent\Tools\Tasks::rank('password',['cake recipe','password reset']);check($rank[0]['index']===1,'Agent ranking tool uses configured native reranker');
    $registry=new Agent\ToolRegistry();$registry->register('Agent\\Tools\\Tasks');check(count($registry->definitions())===4,'Specialist tools reflect as four ordinary shared tools');
    foreach($registry->definitions() as $definition)if($definition['function']['name']==='Agent_Tools_Tasks_extract')check($definition['function']['parameters']['properties']->contract['type']==='object','Extraction contract is advertised as an object, not a list');
    $tool=$registry->execute(['id'=>'extract-fixture','function'=>['name'=>'Agent_Tools_Tasks_extract','arguments'=>json_encode(['text'=>'Invoice INV-7 total 42','contract'=>['invoice_id'=>'string','total'=>'number']])]]);
    check(empty($tool['_tool_error']) && json_decode($tool['content'],true)['total']===42,'Reflected extraction accepts a JSON object with named shorthand field types');

    foreach(['embed','rerank','classify','extract','vision'] as $kind){
        $test=Agent\Benchmarks::saveTest(['name'=>'fixture-'.$kind,'model_type'=>$kind]);
        if($kind==='embed' || $kind==='rerank'){$input=['query'=>'password','documents'=>['password reset','cake recipe']];$expected=[0,1];}
        elseif($kind==='classify'){$input=['prompt'=>'Refund request','labels'=>['billing','technical']];$expected='billing';}
        elseif($kind==='extract'){$input=['prompt'=>'Invoice INV-7 total 42','contract'=>['invoice_id'=>['type'=>'string'],'total'=>['type'=>'number']]];$expected=['invoice_id'=>'INV-7','total'=>42];}
        else{$input=['prompt'=>'Return the dominant color, one word.','image'=>Agent\Profiles::imageData()];$expected='red';}
        Agent\Benchmarks::saveQuestion($test['id'],['name'=>'Typed operation','input'=>$input,'scoring_type'=>'matches','scoring_criteria'=>$expected]);
        $candidate=in_array($kind,['classify','extract'],true)?'default':'specialist';
        $job=Agent\Benchmarks::execute(Agent\Benchmarks::enqueue($candidate,$test['id'],'fixture-owner',true)['id']);
        check($job['status']==='completed' && $job['score']===1.0,'End-to-end typed benchmark: '.$kind);
        if($candidate==='default')check($job['results'][0]['raw']['parsed']['model']==='chat-fixture','Benchmark candidate is never replaced by a task-default specialist: '.$kind);
        check(count((new Agent\Thread($job['results'][0]['threadid']))->messages())===3,'Typed operation uses shared raw thread lifecycle: '.$kind);
    }
    Agent\Profiles::setDefault('generate_titles',['id'=>$special['id']]);
    $thread=new Agent\Thread($job['results'][0]['threadid']);$thread->generateTitle($job['results'][0]['runid'],$thread->get()['title']);
    check($thread->get()['title']==='red','Title generation explicitly uses chat with the assigned task profile');
    $down=Agent\Profiles::save('unavailable',array_replace($config,['model'=>'unavailable']));
    $test=Agent\Benchmarks::saveTest(['name'=>'fixture-unavailable','model_type'=>'embed']);
    foreach([1,2] as $i)Agent\Benchmarks::saveQuestion($test['id'],['name'=>'Native question '.$i,'input'=>['texts'=>['password']],'scoring_type'=>'contains','scoring_criteria'=>'vectors']);
    $failed=Agent\Benchmarks::execute(Agent\Benchmarks::enqueue('unavailable',$test['id'],'fixture-owner',true)['id']);
    check($failed['status']==='failed' && count($failed['results'])===1 && $failed['results'][0]['raw']['status']===503 && $failed['results'][0]['raw']['parsed']['error']==='fixture temporarily unavailable','Native errors retain raw provider evidence and stop before repeating a broken profile');
    Agent\Profiles::save('default',array_replace($config,['model'=>'chat-fixture','request_type'=>'embed']));
    Agent\Profiles::setDefault('score',['id'=>$chat['id']]);
    $judge=null;check(Agent\Benchmarks::score('answer','llm_score','rubric',array_replace($config,['control'=>function(){return 0;}]),$judge)===.75 && $judge['model']==='chat-fixture' && $judge['result']['reason']==='fixture assessment' && !isset($judge['config']['control']),'LLM scoring uses its explicit judge default and records bounded, serializable evidence');
    Agent\Profiles::setDefault('score',['id'=>$down['id']]);$judge=null;
    expectException(function()use($config,&$judge){Agent\Benchmarks::score('answer','llm_score','rubric',$config,$judge);},'503');
    check($judge['model']==='unavailable' && $judge['result']===null && $judge['response']['status']===503,'Failed LLM judges retain their configuration and raw response evidence');
} finally {
    if(is_resource($process)){proc_terminate($process);proc_close($process);}
    $db->exec('SET search_path TO '.$old);$db->exec('DROP SCHEMA '.$schema.' CASCADE');
}
