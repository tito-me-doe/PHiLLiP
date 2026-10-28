<?php
require __DIR__ . '/helpers.php';
$alice=Agent\Identity::authenticate('alice','phillip-demo');$admin=Agent\Identity::authenticate('admin','phillip-demo');
$thread=Agent\Thread::create($alice['id'],'Workflow log fixture',['manual'=>true],'organization');
Agent\Log::configure(['file'=>Agent\Log::OFF]);
try {
    $run=$thread->enqueue($alice['id'],'A tool is about to emit diagnostics');Agent\Run::claim($thread->id);
    Agent\ToolContext::withValues(['thread_id'=>$thread->id,'run_id'=>$run['id'],'frontend'=>true,'user_id'=>$alice['id']],function(){
        Agent\Log::writeError('WORKFLOW_VISIBLE_FIXTURE');
        Agent\Log::writeDebug('WORKFLOW_DEVELOPER_SECRET');
        Agent\Log::with(['console'=>Agent\Log::VERBOSE],function(){Agent\Log::writeVerbose('WORKFLOW_VERBOSE_SECRET');});
    });
    $plain=Agent\WorkflowState::feed($thread->id,0,$alice['id']);$privileged=Agent\WorkflowState::feed($thread->id,0,$admin['id']);
    check(strpos(json_encode($plain),'WORKFLOW_VISIBLE_FIXTURE')!==false,'Tool logger persists messages through shared workflow events');
    check(strpos(json_encode($plain),'WORKFLOW_DEVELOPER_SECRET')===false && strpos(json_encode($plain),'WORKFLOW_VERBOSE_SECRET')===false,'Ordinary event feeds never transmit privileged log contents');
    check(strpos(json_encode($privileged),'WORKFLOW_DEVELOPER_SECRET')!==false && strpos(json_encode($privileged),'WORKFLOW_VERBOSE_SECRET')!==false,'Authorized viewers receive debug and scoped verbose events');
    check(array_column($plain['events'],'id')===array_column($privileged['events'],'id'),'Filtered diagnostics preserve cursor progression and event ordering');
    Agent\Log::restoreThread($thread->id);Agent\Runtime::bindIdentity($alice);
    check(strpos(Agent\Log::headerHtml(),'WORKFLOW_VISIBLE_FIXTURE')!==false && strpos(Agent\Log::footerHtml(),'WORKFLOW_DEVELOPER_SECRET')===false,'Reloaded PHP pages restore visible history without embedding private diagnostics');
    $snapshot=Agent\WorkflowState::snapshot($thread->id);$rebuilt=Agent\WorkflowState::rebuild($run['id']);
    check($snapshot['runs'][0]['state']===$rebuilt['value'],'Logging does not break rebuildable workflow state');
} finally {$thread->delete();}
