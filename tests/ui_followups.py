"""Real local-model regression: repeated PHP fragment replacement must not break events."""
import os
import time
from pathlib import Path
from playwright.sync_api import sync_playwright

BASE=os.environ.get('PHILLIP_URL','http://127.0.0.1:8088')
ROOT=Path(__file__).resolve().parents[1]

def wait(page, expression, timeout=180):
    deadline=time.monotonic()+timeout
    while time.monotonic()<deadline:
        if page.evaluate(expression): return
        page.wait_for_timeout(150)
    raise AssertionError('Timed out: '+expression+'; '+str(page.evaluate('PHiLLiP.current?.runs')))

with sync_playwright() as p:
    browser=p.chromium.launch()
    context=browser.new_context(viewport={'width':1280,'height':950})
    page=context.new_page();errors=[]
    page.on('pageerror',lambda e:errors.append(str(e)))
    page.goto(BASE)
    page.locator('[name=username]').fill('admin');page.locator('[name=password]').fill('phillip-demo');page.locator('#login-form button').click()
    page.locator('#message-form').wait_for()
    # Observe the actual client without substituting model responses or event traffic.
    page.evaluate("""() => {
      const A=PHiLLiP;
      window.trace={events:[],fragments:[],requests:[],liveRuns:[],liveSnapshots:[],replacements:0,inflight:0,maxInflight:0,delay:0};
      const api=A.api.bind(A),html=A.html.bind(A);
      A.api=async(path,body)=>{const events=path.includes('/events?'),epoch=A.epoch;if(events){trace.inflight++;trace.maxInflight=Math.max(trace.maxInflight,trace.inflight);}try{const result=await api(path,body);if(events){if(trace.delay)await new Promise(r=>setTimeout(r,trace.delay));trace.events.push(...result.events.map(e=>({id:e.id,run:e.run_id,type:e.type,data:e.data,epoch})));}if(body&&path.endsWith('/messages'))trace.requests.push(result.run.id);return result;}finally{if(events)trace.inflight--;}};
      A.html=async(path,body)=>{const result=await html(path,body);if(path.startsWith('/fragments/messages'))trace.fragments.push({path,html:result});return result;};
      new MutationObserver(()=>{trace.replacements++;const r=A.current?.runs.find(r=>r.status==='running'),text=document.querySelector('#live-body')?.textContent;if(r&&text){trace.liveRuns.push(String(r.id));trace.liveSnapshots.push({run:String(r.id),text});}}).observe(document.querySelector('#workspace'),{childList:true,subtree:true,characterData:true});
    }""")
    page.get_by_role('button',name='Chat settings',exact=True).click()
    if os.environ.get('PHILLIP_PROFILE'):
        page.locator('#chat-settings-form [name=profile]').select_option(os.environ['PHILLIP_PROFILE'])
    page.locator('#chat-settings-form [name=max_tokens]').fill(os.environ.get('PHILLIP_MAX_TOKENS','2048'))
    page.locator('#chat-settings-form [name=system_prompt]').fill('Follow the user requests concisely. Use tools when explicitly requested.')
    page.get_by_role('button',name='Save settings',exact=True).click();page.locator('#dialog').wait_for(state='hidden')

    def submit(text, enter=True):
        before=page.evaluate('trace.requests.length')
        page.locator('#prompt').fill(text)
        if enter:page.locator('#prompt').press('Enter')
        else:page.get_by_role('button',name='Send message',exact=True).click()
        wait(page,'trace.requests.length === '+str(before+1),30)
        return page.evaluate('trace.requests.at(-1)')

    def completed(run, token):
        wait(page,f"PHiLLiP.current?.runs.some(r=>r.id=={run}&&r.status==='completed')")
        page.locator('.message.assistant .message-body').filter(has_text=token).last.wait_for()
        if not page.evaluate("PHiLLiP.current.runs.some(r=>r.status==='running')"):
            assert page.locator('#live-message').count()==0
        assert page.locator('#message-form').count()==1
        assert page.locator('#send-button').is_enabled()
        ids=page.locator('article.message[data-id]').evaluate_all('(nodes)=>nodes.map(n=>n.dataset.id)')
        assert len(ids)==len(set(ids)), 'Duplicate persisted messages after replacing HTML'
        print('PASS: '+token+' completed after PHP fragment replacement',flush=True)

    thread_id=None
    try:
        first=submit('The secret word for this conversation is cobalt. Reply TURN_ONE, then a numbered list of 60 short PHP terms.')
        completed(first,'TURN_ONE');thread_id=page.evaluate('PHiLLiP.current.thread.id')
        second=submit('What was the secret word? Reply TURN_TWO followed by the secret word, then 30 brief PHP tips.',False)
        completed(second,'TURN_TWO')
        assert 'cobalt' in page.locator('.message.assistant').last.inner_text().lower()
        third=submit('Reply TURN_THREE and then a fenced PHP code block containing echo "three";')
        completed(third,'TURN_THREE');assert page.locator('pre code.hljs').count()>=1

        # Rebind a server-rendered settings dialog after several message replacements.
        page.get_by_role('button',name='Chat settings',exact=True).click()
        page.locator('#chat-settings-form input[name=tools][value="Agent\\\\Tools\\\\Fetch"]').check()
        page.get_by_role('button',name='Save settings',exact=True).click();page.locator('#dialog').wait_for(state='hidden')
        fourth=submit('Use the Fetch tool to fetch http://phillip.internal:8080/health. Then reply TURN_FOUR followed by the PHP version from the fetched result.')
        completed(fourth,'TURN_FOUR')
        assert page.locator('details.activity summary').filter(has_text='Fetch').count()>=1
        assert page.evaluate(f"trace.events.some(e=>e.run=={fourth}&&e.type==='tool.result')")
        assert '8.5' in page.locator('.message.assistant').last.inner_text()
        print('PASS: Tool progress and results still work after multiple replacements',flush=True)

        # Delay actual event replies, without faking their content, to expose overlapping polls.
        page.evaluate('trace.delay=700')
        fifth=submit('Write a numbered list of 60 short facts about PHP. Finish with END_FIVE. Do not call tools.')
        wait(page,f"PHiLLiP.current.runs.some(r=>r.id=={fifth}&&r.status==='running')",30)
        sixth=submit('Reply exactly TURN_SIX.')
        seventh=submit('Reply exactly TURN_SEVEN.',False)
        wait(page,"PHiLLiP.current.runs.filter(r=>r.status==='queued').length>=1",15)
        completed(fifth,'END_FIVE');completed(sixth,'TURN_SIX');completed(seventh,'TURN_SEVEN')
        page.evaluate('trace.delay=0')
        runs=page.evaluate('PHiLLiP.current.runs')
        assert [r['id'] for r in runs][-3:]==[fifth,sixth,seventh]
        print('PASS: Two follow-ups queue sequentially without duplicate submissions or dropped handlers',flush=True)

        # Stop remains interactive after the thread element has repeatedly been replaced.
        long=submit('Write 100 detailed numbered PHP tips. Do not use tools. Finish with SHOULD_BE_STOPPED.')
        wait(page,f"PHiLLiP.current.runs.some(r=>r.id=={long}&&r.status==='running')",30)
        page.get_by_role('button',name='Stop agent',exact=True).click()
        wait(page,f"trace.events.some(e=>e.run=={long}&&e.type==='run.stop_requested')",20)
        wait(page,f"PHiLLiP.current.runs.some(r=>r.id=={long}&&r.status==='stopped')")
        after=submit('Reply exactly AFTER_STOP.')
        completed(after,'AFTER_STOP')
        print('PASS: Stop and the next follow-up remain functional after repeated replacements',flush=True)

        # Editing replaces the saved turn, deletes its old run, and wires another fresh stream.
        page.locator('.message.user').last.get_by_role('button',name='Edit your message').click()
        page.locator('#edit-message [name=content]').fill('Reply exactly AFTER_EDIT.')
        page.get_by_role('button',name='Replace and send',exact=True).click();page.locator('#dialog').wait_for(state='hidden')
        wait(page,"PHiLLiP.current.runs.at(-1).id != "+str(after))
        edited=page.evaluate('PHiLLiP.current.runs.at(-1).id');completed(edited,'AFTER_EDIT')
        assert page.locator('.message.assistant .message-body').filter(has_text='AFTER_STOP').count()==0
        final=submit('Reply exactly FINAL_FOLLOWUP.')
        completed(final,'FINAL_FOLLOWUP')

        wait(page,f"trace.events.some(e=>e.run=={final}&&e.type==='run.completed')",15)
        trace=page.evaluate('({events:trace.events.map(e=>({id:e.id,run:e.run,type:e.type,epoch:e.epoch})),fragments:trace.fragments.length,replacements:trace.replacements,liveRuns:trace.liveRuns,liveSnapshots:trace.liveSnapshots,requests:trace.requests,maxInflight:trace.maxInflight})')
        # Settings deliberately reopened the thread (cursor reset), so replayed old event IDs
        # are valid. Within each response the final saved text and DB rows must remain unique.
        assert trace['fragments']>=10
        assert trace['maxInflight']==1, 'Multiple simultaneous event consumer requests'
        keys=[(e['epoch'],e['id']) for e in trace['events']]
        assert len(keys)==len(set(keys)), 'An event was delivered twice within one thread-view epoch'
        assert len(set(trace['liveRuns']))>=3, 'Expected visible live response text in multiple turns'
        for run in [first,second,third,fourth,fifth,sixth,seventh,after,edited,final]:
            assert any(e['run']==run and e['type']=='text.delta' for e in trace['events']), 'No deltas for run '+str(run)
            # A fresh PHP snapshot may already include completion when the view is reopened;
            # replaying that old terminal event is intentionally unnecessary.
        stored=page.evaluate("async()=>await PHiLLiP.api('/api/threads/'+PHiLLiP.current.thread.id)")
        assert all(r['status'] in ['completed','stopped'] for r in stored['runs'])
        normal=[first,second,third,fifth,sixth,seventh,edited,final]
        for snapshot in trace['liveSnapshots']:
            if int(snapshot['run']) not in normal: continue
            answers=[m['raw']['content'] or '' for m in stored['messages'] if str(m['run_id'])==snapshot['run'] and m['role']=='assistant']
            if answers: assert ''.join(answers).startswith(snapshot['text']), 'Duplicated or mixed live text for run '+snapshot['run']
        assert not errors,errors
        page.screenshot(path=str(ROOT/'storage/ui-followups.png'),full_page=True)
        print('PASS: Ten completed turns plus Stop; multiple live streams, PHP refreshes and no JavaScript errors',flush=True)
        print('Observed '+str(trace['fragments'])+' PHP message fragment fetches; '+str(len(trace['events']))+' durable events',flush=True)
    finally:
        cleanup_id=thread_id or page.evaluate('PHiLLiP.current?.thread.id')
        if cleanup_id and page.evaluate("PHiLLiP.current.runs.every(r=>!['queued','running'].includes(r.status))"):
            page.evaluate("async id=>{await PHiLLiP.api('/api/threads/'+id+'/delete',{});}",cleanup_id)
        browser.close()
