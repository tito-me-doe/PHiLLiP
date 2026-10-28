<?php
require __DIR__ . '/helpers.php';
$page = Agent\Tools\Fetch::fetch('https://www.php.net/releases/8.5/en.php');
check(strpos($page['text'], 'PHP') !== false, 'Fetch extracts real HTML');
check(count($page['links']) > 0, 'Fetch retains source links');
expectException(function () { Agent\Tools\Fetch::fetch('file:///etc/passwd'); }, 'HTTP or HTTPS');
$results = Agent\Tools\SearchWeb::search('PHP official website', 3);
check(count($results['results']) > 0, 'Self-hosted SearXNG returns real search results');
check(count($results['results']) <= 3, 'Search result limit honored');
$request = new Agent\Request(['preset' => 'default', 'temperature' => 0, 'max_tokens' => 1024]);
$request->registerTool('Agent\\Tools\\Fetch');
$request->addMessage('Use Fetch to read https://www.php.net/releases/8.5/en.php and tell me which programming language the page discusses. Answer in one sentence.');
check(stripos($request->send(), 'PHP') !== false, 'Real model uses Fetch to answer from a web page');
