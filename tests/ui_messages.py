"""Isolated PHP fixture: automatic audiences, header/footer delivery, and browser-only logging."""
import base64
import json
import os
import socket
import subprocess
import tempfile
import time
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT=Path(__file__).resolve().parents[1]
with socket.socket() as sock:
    sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
db_host=subprocess.check_output(['docker','inspect','--format','{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}','phillip-postgres-1'],text=True).strip()
with tempfile.TemporaryDirectory(prefix='phillip-log-sessions-') as sessions:
    env=dict(os.environ,DB_DSN='pgsql:host='+db_host+';dbname=phillip',PHILLIP_TEST_SESSION_PATH=sessions,LOG_MIN_FILE='3')
    env.pop('PHP_CLI_SERVER_WORKERS',None)
    server=subprocess.Popen(['php','-S','127.0.0.1:'+str(port),str(ROOT/'tests/fixtures/logging.php')],cwd=ROOT,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    try:
        time.sleep(.3)
        with sync_playwright() as p:
            browser=p.chromium.launch();base='http://127.0.0.1:'+str(port)
            for username in ['alice','admin']:
                context=browser.new_context();login=context.request.post(base+'/login',data={'username':username,'password':'phillip-demo'})
                assert login.ok,login.text()
                page=context.new_page();console=[];errors=[];requests=[]
                page.on('console',lambda message:console.append((message.type,message.text)))
                page.on('pageerror',lambda error:errors.append(str(error)))
                page.on('request',lambda request:requests.append((request.method,request.url)))
                response=page.goto(base+'/');page.locator('.log-success').filter(has_text='Late success fixture').wait_for()
                assert page.locator('.log-error').count()==1
                assert page.locator('.log-error img').count()==0
                assert page.locator('.log-error').inner_text().count('<img')==1
                assert page.locator('.log-warning').count()==1
                contents=response.text()
                if username=='alice':
                    assert 'SECRET_DEBUG_FIXTURE' not in contents
                    assert 'SCOPED_VERBOSE_FIXTURE' not in contents
                    assert 'Admin information fixture' not in contents
                    assert not any('SECRET_DEBUG_FIXTURE' in text for _,text in console)
                else:
                    assert any(kind=='debug' and 'SECRET_DEBUG_FIXTURE' in text for kind,text in console)
                    assert any(kind=='debug' and 'SCOPED_VERBOSE_FIXTURE' in text for kind,text in console)
                    assert page.locator('.log-debug').count()==0
                    assert page.locator('.log-info').filter(has_text='Admin information').count()==1
                request_count=len(requests)
                page.evaluate("""()=>{
                  const L=PHiLLiPLog,cyclic={};cyclic.self=cyclic;
                  L.writeError('Browser %s %d','fixture',7);
                  L.writeError(cyclic,new Error('Typed fixture'),null,Symbol('symbol'));
                  L.writeDebug('CLIENT_DEBUG_FIXTURE');
                  L.writeVerbos(cyclic);
                  L.writeError(new Date('invalid'));
                  L.write('not a priority',new Proxy({}, {ownKeys(){throw new Error('nope');}}));
                }""")
                page.locator('.log-error').filter(has_text='Browser fixture 7').wait_for()
                assert len(requests)==request_count,'Client messages must never POST to server'
                page.locator('.log-error').filter(has_text='Browser fixture 7').locator('[data-dismiss-log]').click()
                assert page.locator('.log-error').filter(has_text='Browser fixture 7').count()==0
                if username=='alice':assert not any('CLIENT_DEBUG_FIXTURE' in text for _,text in console)
                else:assert any(kind=='debug' and 'CLIENT_DEBUG_FIXTURE' in text for kind,text in console)
                api=context.request.get(base+'/json',headers={'Accept':'application/json'})
                assert api.json()=={'ok':True},api.text()
                records=json.loads(base64.b64decode(api.headers['x-phillip-messages']))
                assert any(record['message']=='API warning fixture' for record in records)
                assert ('SECRET_DEBUG_FIXTURE' in json.dumps(records))==(username=='admin')
                assert not errors,errors
                print('PASS: '+username+' automatic audience, safe header/footer messages, console routing and clean API JSON',flush=True)
                context.close()
            browser.close()
    finally:
        server.terminate();server.wait(timeout=10)
