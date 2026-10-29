<?php
$body=json_decode(file_get_contents('php://input'),true);
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
header('Content-Type: application/json');
if(isset($body['model']) && $body['model']==='unavailable'){http_response_code(503);echo '{"error":"fixture temporarily unavailable"}';return;}
if(in_array($path,['/v1/embeddings','/api/embed','/embed'],true)) {
    if(isset($body['model']) && strpos($body['model'],'chat')===0){http_response_code(400);echo '{"error":"chat-only model"}';return;}
    $texts=isset($body['input'])?$body['input']:$body['inputs'];$vectors=[];$rows=[];
    foreach($texts as $i=>$text){$vector=preg_match('/password|login/i',$text)?[1.0,0.0,0.0]:(preg_match('/cake|bread/i',$text)?[0.0,1.0,0.0]:[0.0,0.0,1.0]);if(isset($body['model']) && $body['model']==='bad-dimensions' && $i===1)$vector=[1.0];$vectors[]=$vector;$rows[]=['index'=>$i,'embedding'=>$vector];}
    echo json_encode($path==='/embed'?$vectors:($path==='/api/embed'?['embeddings'=>$vectors]:['data'=>$rows,'usage'=>['prompt_tokens'=>count($texts),'total_tokens'=>count($texts)]]));return;
}
if(in_array($path,['/v1/rerank','/rerank'],true)) {
    $documents=isset($body['documents'])?$body['documents']:$body['texts'];$rows=[];
    foreach($documents as $i=>$text)$rows[]=['index'=>$i,'relevance_score'=>stripos($text,'password')!==false?.9:.1];
    echo json_encode($path==='/rerank'?$rows:['results'=>$rows]);return;
}
$schema=isset($body['response_format']['json_schema']['schema'])?$body['response_format']['json_schema']['schema']:null;
$properties=$schema && isset($schema['properties'])?$schema['properties']:[];
$data=[];
if(isset($properties['label'])){$data['label']=$properties['label']['enum'][0];if(isset($properties['confidence']))$data['confidence']=.9;}
elseif(isset($properties['ranked']))$data['ranked']=[['index'=>0,'score'=>.9],['index'=>1,'score'=>.1]];
elseif(isset($properties['invoice_id'])){$data['invoice_id']='INV-7';if(isset($properties['total']))$data['total']=42;}
elseif(isset($properties['score']))$data=['score'=>.75,'reason'=>'fixture assessment'];
$answer=$data?json_encode($data):($schema && isset($schema['type']) && $schema['type']==='string'?json_encode('red'):'red');
if(!empty($body['stream'])){
    header('Content-Type: text/event-stream');
    echo 'data: '.json_encode(['choices'=>[['delta'=>['content'=>$answer],'finish_reason'=>null]]])."\n\n";
    echo 'data: '.json_encode(['choices'=>[['delta'=>new stdClass(),'finish_reason'=>'stop']],'usage'=>['completion_tokens'=>4]])."\n\n";
    echo "data: [DONE]\n\n";
}else echo json_encode(['model'=>$body['model'],'choices'=>[['message'=>['role'=>'assistant','content'=>$answer],'finish_reason'=>'stop']],'usage'=>['completion_tokens'=>4]]);
