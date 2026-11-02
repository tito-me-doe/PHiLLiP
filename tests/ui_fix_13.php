<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/fixtures/WebFixture.php';

/**
 * Issue13: service editor must operate on the RAW stored fallback, never the
 * environment-resolved URL.
 *
 * The edit form previously reused the resolved listing ($services[$name] from
 * Config::service($name)), so editing a service whose URL lives behind a url_env
 * could bake the runtime URL back into storage as the fallback. The editor now
 * loads Config::service($name, false): missing/unloadable -> blank warning form.
 *
 * Coverage: shared Config (direct PHP) plus HTTP (native form POST and JSON API),
 * authorized alice and unprivileged bob, no external models, no Python. Temporary
 * environment is restored and the fixture is closed in finally.
 */
$web = new WebFixture();
try {
    Agent\Installation::install(['auth' => 'standalone'], 'admin', 'phillip-demo', 'Administrator');
    $alice = Agent\Identity::createUser('alice', 'phillip-demo', 'Alice');
    Agent\Identity::grant('user', $alice['id'], 'page.models');
    $bob = Agent\Identity::createUser('bob', 'phillip-demo', 'Bob');
    // bob deliberately has no models grant.

    $envVar = 'FIX13_SEARXNG_URL';
    $previousEnv = getenv($envVar);
    $envUrl = 'http://127.0.0.1:9714/env';
    putenv($envVar . '=' . $envUrl);

    $web->server(dirname(__DIR__) . '/public/index.php', true);
    $web->login('alice', 'phillip-demo', 'alice');
    $web->login('bob', 'phillip-demo', 'bob');

    // Retry page GETs: the bundled single-worker dev server can occasionally
    // close a connection without a body in this sandbox; ids are stable, so
    // retry until we get real HTML.
    $fetchPage = function ($path, $client) use ($web) {
        $resp = null;
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $resp = $web->request($path, null, $client);
            if ($resp['text'] !== false && $resp['text'] !== '') {
                return $resp;
            }
            usleep(150000);
        }
        return $resp;
    };

    $servicesUrl = Agent\Pages::link('models');
    $searxngEditUrl = Agent\Pages::link('models', ['configure' => 'services', 'name' => 'searxng']);
    $camoufoxEditUrl = Agent\Pages::link('models', ['configure' => 'services', 'name' => 'camoufox']);

    $rawFallback = 'http://127.0.0.1:9713/fallback';
    $tokenVar = 'FIX13_SEARXNG_TOKEN';

    // ------------------------------------------------------------------
    // Phase 1: shared Config contracts (direct PHP), arbitrary scratch state.
    // ------------------------------------------------------------------

    // Legacy bulk JSON map accepted (array, JSON string, and decoded stdClass).
    $bulkMap = [
        'searxng' => ['url' => 'http://127.0.0.1:9801/s', 'headers' => ['X-S' => 'a']],
        'camoufox' => ['url' => 'http://127.0.0.1:9802/c'],
    ];
    foreach ([$bulkMap, json_encode($bulkMap), json_decode(json_encode($bulkMap))] as $input) {
        $result = Agent\Config::configureServices($input);
        check($result['searxng']['url'] === 'http://127.0.0.1:9801/s'
            && $result['camoufox']['url'] === 'http://127.0.0.1:9802/c',
            'Legacy bulk service map accepted: ' . gettype($input));
    }
    $rawSe = Agent\Config::service('searxng', false);
    check($rawSe['url'] === 'http://127.0.0.1:9801/s' && $rawSe['headers'] === ['X-S' => 'a'],
        'Legacy bulk service map readable back as raw stored config');

    // Named flat fields accepted.
    $flat = ['name' => 'searxng', 'url' => 'http://127.0.0.1:9803/f', 'url_env' => 'FIX13_FLAT_ENV', 'token_env' => 'FIX13_FLAT_TOKEN'];
    $result = Agent\Config::configureServices($flat);
    check($result['searxng']['url'] === 'http://127.0.0.1:9803/f', 'Named flat fields accepted (direct)');
    check($result['searxng']['url_env'] === 'FIX13_FLAT_ENV' && $result['searxng']['token_env'] === 'FIX13_FLAT_TOKEN',
        'Named flat fields carry env names (direct)');

    // Blank/null env clears the old value; omitted preserves it.
    Agent\Config::configureServices(['name' => 'searxng', 'url' => 'http://127.0.0.1:9804/p', 'url_env' => 'FIX13_PRESENT_ENV']);
    check(Agent\Config::service('searxng', false)['url_env'] === 'FIX13_PRESENT_ENV', 'Present url_env stored');

    Agent\Config::configureServices(['name' => 'searxng', 'url' => 'http://127.0.0.1:9804/p', 'url_env' => '']);
    check(!isset(Agent\Config::service('searxng', false)['url_env']), 'Blank url_env clears the stored variable (direct)');

    Agent\Config::configureServices(['name' => 'searxng', 'url' => 'http://127.0.0.1:9804/p', 'url_env' => null]);
    check(!isset(Agent\Config::service('searxng', false)['url_env']), 'Null url_env clears the stored variable (direct)');

    Agent\Config::configureServices(['name' => 'searxng', 'url' => 'http://127.0.0.1:9804/p', 'url_env' => 'FIX13_KEEP_ENV']);
    Agent\Config::configureServices(['name' => 'searxng', 'url' => 'http://127.0.0.1:9804/q']);
    check(Agent\Config::service('searxng', false)['url_env'] === 'FIX13_KEEP_ENV', 'Omitted url_env preserves the old value (direct)');
    check(Agent\Config::service('searxng', false)['url'] === 'http://127.0.0.1:9804/q', 'Omitted url_env still allows the URL to change (direct)');

    // URL and env-name trim + validation; rejections leave data unchanged.
    Agent\Config::configureServices(['name' => 'searxng', 'url' => '  http://127.0.0.1:9805/t  ', 'url_env' => ' FIX13_TRIM_ENV ']);
    check(Agent\Config::service('searxng', false)['url'] === 'http://127.0.0.1:9805/t', 'URL is trimmed (direct)');
    check(Agent\Config::service('searxng', false)['url_env'] === 'FIX13_TRIM_ENV', 'url_env name is trimmed (direct)');

    $stableUrl = Agent\Config::service('searxng', false)['url'];
    $stableEnv = Agent\Config::service('searxng', false)['url_env'];
    expectException(function () { Agent\Config::configureServices(['name' => 'searxng', 'url' => 'ftp://not-http']); }, 'HTTP(S) URL');
    check(Agent\Config::service('searxng', false)['url'] === $stableUrl, 'Invalid URL rejected with no data change (direct)');
    expectException(function () { Agent\Config::configureServices(['name' => 'searxng', 'url' => 'http://ok', 'url_env' => 'bad name']); }, 'environment variable');
    check(Agent\Config::service('searxng', false)['url_env'] === $stableEnv, 'Invalid url_env rejected with no data change (direct)');

    // Unknown service and bad types rejected with no data change.
    $beforeUnknown = Agent\Config::service('searxng', false);
    expectException(function () { Agent\Config::configureServices(['name' => 'unknown-service', 'url' => 'http://localhost']); }, 'Unknown service');
    expectException(function () { Agent\Config::configureServices(['searxng' => ['url' => ['not' => 'a string']]]); }, 'URL must be a string');
    check(Agent\Config::service('searxng', false) === $beforeUnknown, 'Rejected mutations leave stored data unchanged (direct)');

    // Unrelated service and its headers/raw secret are preserved across edits.
    Agent\Config::configureServices([
        'searxng' => ['url' => 'http://127.0.0.1:9810/s', 'headers' => ['X-A' => '1', 'Authorization' => 'Bearer SECRET-TOKEN']],
        'camoufox' => ['url' => 'http://127.0.0.1:9811/c'],
    ]);
    Agent\Config::configureServices(['name' => 'camoufox', 'url' => 'http://127.0.0.1:9811/c-new']);
    check(Agent\Config::service('searxng', false)['headers']['Authorization'] === 'Bearer SECRET-TOKEN', 'Unrelated service headers/raw secret preserved (direct)');
    check(Agent\Config::service('camoufox', false)['url'] === 'http://127.0.0.1:9811/c-new', 'Target service updated without touching siblings (direct)');

    // ------------------------------------------------------------------
    // Phase 2: editor scenario - raw fallback + url_env to a DIFFERENT URL.
    // ------------------------------------------------------------------
    Agent\Config::configureServices([
        'searxng' => [
            'url' => $rawFallback,
            'url_env' => $envVar,
            'token_env' => $tokenVar,
            'headers' => ['X-Fix13' => 'seed-header'],
        ],
    ]);

    $resolved = Agent\Config::service('searxng');
    $raw = Agent\Config::service('searxng', false);
    check($resolved['url'] === $envUrl, 'Resolved URL follows the runtime url_env override');
    check($raw['url'] === $rawFallback, 'Raw stored fallback remains the seeded fallback URL');
    check($raw['url_env'] === $envVar && $raw['token_env'] === $tokenVar, 'Raw stored env names are preserved');
    check($raw['headers'] === ['X-Fix13' => 'seed-header'], 'Raw stored headers are preserved');

    // ------------------------------------------------------------------
    // Phase 3: native editor input must show the RAW fallback.
    // ------------------------------------------------------------------
    $editResponse = $fetchPage($searxngEditUrl, 'alice');
    $editHtml = $editResponse['text'];
    check($editResponse['status'] === 200 && strpos($editHtml, 'id="service-form"') !== false, 'Service editor form renders for a configured service');
    check(strpos($editHtml, 'value="' . $rawFallback . '"') !== false, 'Editor URL input shows the RAW fallback URL');
    check(strpos($editHtml, $envUrl) === false, 'Editor URL input never reveals the resolved env URL');
    check(strpos($editHtml, 'SECRET') === false, 'Editor leaks no token/header secret');

    // ------------------------------------------------------------------
    // Phase 4: native CSRF POST saves the flat named payload (303).
    // ------------------------------------------------------------------
    $nativeNew = ['name' => 'searxng', 'url' => 'http://127.0.0.1:9713/native-new', 'url_env' => '', 'token_env' => $tokenVar];
    $native = $web->request($servicesUrl, [
        'action' => 'save-services',
        'csrf' => $web->tokens['alice'],
        'name' => $nativeNew['name'], 'url' => $nativeNew['url'], 'url_env' => $nativeNew['url_env'], 'token_env' => $nativeNew['token_env'],
    ], 'alice', true);
    check($native['status'] === 303, 'Native CSRF POST saves the flat service payload (303)');
    $afterNative = Agent\Config::service('searxng', false);
    check($afterNative['url'] === 'http://127.0.0.1:9713/native-new', 'Native POST persisted the raw URL');
    check(!isset($afterNative['url_env']), 'Native POST clears and removes blank url_env');
    check($afterNative['headers'] === ['X-Fix13' => 'seed-header'], 'Native POST preserved unrelated headers');

    // ------------------------------------------------------------------
    // Phase 5: JSON API POST saves the same flat named payload through Config.
    // ------------------------------------------------------------------
    $apiNew = ['name' => 'searxng', 'url' => 'http://127.0.0.1:9713/api-new', 'url_env' => $envVar, 'token_env' => $tokenVar];
    $api = $web->request('/api/admin/services', ['services' => $apiNew], 'alice', false, $web->tokens['alice']);
    check($api['status'] === 200 && !empty($api['json']['ok']), 'JSON API POST saves the flat service payload (200)');
    $afterApi = Agent\Config::service('searxng', false);
    check($afterApi['url'] === 'http://127.0.0.1:9713/api-new', 'API POST persisted the raw URL');
    check($afterApi['url_env'] === $envVar, 'API POST preserved the url_env name');

    // ------------------------------------------------------------------
    // Phase 6: authorization and CSRF negatives with a VALID payload.
    // ------------------------------------------------------------------
    $validPayload = ['name' => 'searxng', 'url' => 'http://127.0.0.1:9713/valid-neg', 'url_env' => '', 'token_env' => ''];
    $negBefore = Agent\Config::service('searxng', false);

    // Bob (unprivileged, no models grant) cannot open the editor page.
    $bobPage = $web->request($searxngEditUrl, null, 'bob');
    check($bobPage['status'] === 403, 'Bob cannot open the service editor page (403)');

    // Bob cannot mutate via native POST even with a valid payload.
    $bobPost = $web->request($servicesUrl, [
        'action' => 'save-services', 'csrf' => $web->tokens['bob'], 'services' => json_encode($validPayload),
    ], 'bob', true);
    check($bobPost['status'] >= 400 && $bobPost['status'] < 500 && $bobPost['status'] !== 303, 'Bob native POST is rejected with a valid payload');
    check(Agent\Config::service('searxng', false) === $negBefore, 'Bob native POST changes nothing');

    // Bob cannot mutate via API even with a valid payload.
    $bobApi = $web->request('/api/admin/services', ['services' => $validPayload], 'bob', false, $web->tokens['bob']);
    check($bobApi['status'] >= 400 && $bobApi['status'] < 500, 'Bob API POST is rejected with a valid payload');
    check(Agent\Config::service('searxng', false) === $negBefore, 'Bob API POST changes nothing');

    // Invalid CSRF is rejected for the native POST with a valid payload.
    $csrfNative = $web->request($servicesUrl, [
        'action' => 'save-services', 'csrf' => 'invalid-csrf-token', 'services' => json_encode($validPayload),
    ], 'alice', true);
    check($csrfNative['status'] >= 400 && $csrfNative['status'] < 500 && $csrfNative['status'] !== 303, 'Native POST rejects an invalid CSRF token with a valid payload');
    check(Agent\Config::service('searxng', false) === $negBefore, 'Invalid native CSRF changes nothing');

    // Invalid CSRF is rejected for the API with a valid payload.
    $csrfApi = $web->request('/api/admin/services', ['services' => $validPayload], 'alice', false, 'invalid-csrf-token');
    check($csrfApi['status'] >= 400 && $csrfApi['status'] < 500, 'API POST rejects an invalid CSRF token with a valid payload');
    check(Agent\Config::service('searxng', false) === $negBefore, 'Invalid API CSRF changes nothing');

    // ------------------------------------------------------------------
    // Phase 7: the editor page is safe and editable when a service is unconfigured.
    // ------------------------------------------------------------------
    // camoufox has no stored configuration in this scenario.
    $db = Agent\Database::connection();
    $db->prepare('DELETE FROM settings WHERE key = ?')->execute(['services.camoufox']);
    $resp = $fetchPage($camoufoxEditUrl, 'alice');
    $unconfigured = $resp['text'] ?? '';
    check($unconfigured !== false && strpos($unconfigured, 'id="service-form"') !== false, 'Editor page renders for an unconfigured service');
    check(strpos($unconfigured, 'value=""') !== false || strpos($unconfigured, 'name="url"') !== false, 'Unconfigured service editor presents a blank editable URL field');

    $createCamoufox = $web->request($servicesUrl, [
        'action' => 'save-services', 'csrf' => $web->tokens['alice'],
        'services' => json_encode(['name' => 'camoufox', 'url' => 'http://127.0.0.1:9713/camoufox-new']),
    ], 'alice', true);
    check($createCamoufox['status'] === 303, 'Unconfigured service can be created through the native editor (303)');
    check(Agent\Config::service('camoufox', false)['url'] === 'http://127.0.0.1:9713/camoufox-new', 'Created unconfigured service is stored');

    // Focused parser editor: native and JSON saves preserve unrelated mappings.
    Agent\Profiles::save('fix13-parser', ['url' => 'http://127.0.0.1:9713/v1/chat/completions', 'model' => 'fixture'], false);
    Agent\AttachmentParser::configure(['.pdf' => 'fix13-parser', 'image/*' => 'fix13-parser']);
    $parserUrl = Agent\Pages::link('models', ['configure' => 'parsers', 'name' => '.pdf']);
    $parserHtml = $web->request($parserUrl, null, 'alice');
    check($parserHtml['status'] === 200 && strpos($parserHtml['text'], 'name="type" value=".pdf"') !== false, 'Native parser editor targets the selected mapping');
    check(strpos($parserHtml['text'], 'name="parsers"') === false && strpos($parserHtml['text'], 'value="fix13-parser" selected') !== false, 'Focused parser editor selects its profile without a bulk JSON editor');
    $parserDialog = $web->request('/fragments/dialog?kind=parsers&name=.pdf', null, 'alice');
    check($parserDialog['status'] === 200 && strpos($parserDialog['text'], 'name="type" value=".pdf"') !== false, 'Parser dialog targets the selected mapping');
    $parserPost = $web->request($servicesUrl, ['action' => 'save-parsers', 'csrf' => $web->tokens['alice'], 'type' => '.pdf', 'profile' => ''], 'alice', true);
    check($parserPost['status'] === 303 && Agent\AttachmentParser::configuration() === ['image/*' => 'fix13-parser'], 'Native parser removal preserves the unrelated mapping');
    $parserApi = $web->request('/api/admin/attachment-parsers', ['type' => '.txt', 'profile' => 'fix13-parser'], 'alice', false, $web->tokens['alice']);
    check($parserApi['status'] === 200 && Agent\AttachmentParser::configuration() == ['image/*' => 'fix13-parser', '.txt' => 'fix13-parser'], 'JSON parser save preserves unrelated mappings');
    $parserBefore = Agent\AttachmentParser::configuration();
    $badParser = $web->request('/api/admin/attachment-parsers', ['type' => 'BAD', 'profile' => 'fix13-parser'], 'alice', false, $web->tokens['alice']);
    check($badParser['status'] >= 400 && Agent\AttachmentParser::configuration() === $parserBefore, 'Invalid focused mapping cannot change configuration');
    $bulkParser = $web->request('/api/admin/attachment-parsers', ['parsers' => json_encode(['.csv' => 'fix13-parser'])], 'alice', false, $web->tokens['alice']);
    check($bulkParser['status'] === 200 && Agent\AttachmentParser::configuration() === ['.csv' => 'fix13-parser'], 'Legacy parser JSON bulk API remains supported');

    // Browser-driven native and JavaScript editor paths, using only this fixture.
    Agent\Profiles::save('fix13-parser-alt', ['url' => 'http://127.0.0.1:9713/v1/chat/completions', 'model' => 'fixture-alt'], false);
    Agent\AttachmentParser::configure(['.pdf' => 'fix13-parser', 'image/*' => 'fix13-parser']);
    Agent\Config::configureServices(['name' => 'searxng', 'url' => 'http://127.0.0.1:9713/browser-fallback', 'url_env' => $envVar]);
    $env = $_ENV;
    $env['PYTHONPATH'] = '/home/bfoxwell/.local/share/codex-tools/agent-ui-test/python';
    $env['PLAYWRIGHT_BROWSERS_PATH'] = '/home/bfoxwell/.local/share/codex-tools/agent-ui-test/browsers';
    $code = proc_close(proc_open('python3 ' . escapeshellarg(__DIR__ . '/ui_fix_13.py') . ' ' . escapeshellarg($web->base),
        [['file', '/dev/null', 'r'], STDOUT, STDERR], $pipes, dirname(__DIR__), $env));
    check($code === 0, 'Focused service and parser editors work in native and JS browser flows');
    $finalService = Agent\Config::service('searxng', false);
    check($finalService['url'] === 'http://127.0.0.1:9713/browser-fallback' && $finalService['url_env'] === $envVar, 'Browser service saves retain raw fallback and environment reference');
    check(Agent\AttachmentParser::configuration() == ['image/*' => 'fix13-parser', '.txt' => 'fix13-parser'], 'Browser parser edits preserve the unrelated mapping and persist removal and creation');

} catch (Throwable $e) {
    fwrite(STDERR, $web->diagnostics());
    throw $e;
} finally {
    if (isset($envVar)) {
        if ($previousEnv === false) { putenv($envVar); } else { putenv($envVar . '=' . $previousEnv); }
    }
    $web->close();
}
echo "ALL PASS\n";
