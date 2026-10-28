<?php
require __DIR__ . '/helpers.php';
use Agent\Tools\Diagnostics;
use Agent\Runtime;
use Agent\ToolContext;

$reflection = new \ReflectionClass(Diagnostics::class);
$methods = [];
foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_STATIC) as $method) {
    $methods[] = $method->getName();
}
sort($methods);
check($methods === ['health', 'runtime'], 'Diagnostics reflects only health and runtime as public static tools');
check($reflection->getMethod('health')->getDocComment() !== false, 'health has a reflective docblock for ToolRegistry');
check($reflection->getMethod('runtime')->getDocComment() !== false, 'runtime has a reflective docblock for ToolRegistry');

// Frontend HTTP context is always denied.
ToolContext::withValues(['frontend' => true], function () {
    expectException(function () { Diagnostics::health(); }, 'CLI');
});

// Root or unidentified CLI is denied, mirroring WorkspaceAccess authority without a cwd requirement.
$unix = Runtime::unixIdentity();
if ($unix['root'] === true || $unix['uid'] === null) {
    expectException(function () { Diagnostics::health(); }, 'unprivileged');
} else {
    // No active request/default profile path: health still yields bounded facts without leaking credentials.
    ToolContext::withValues([], function () {
        $health = Diagnostics::health();
        check(is_array($health) && isset($health['host']) && isset($health['os']) && isset($health['php'])
            && isset($health['checks']) && is_array($health['checks']), 'health returns bounded host and checks facts');
        $serialized = json_encode($health);
        check(strpos($serialized, 'password') === false && strpos($serialized, 'secret') === false
            && strpos($serialized, 'credential') === false, 'health fallback leaks no credentials');
    });

    // No leakage: a configured request secret or chat body never appears in health output.
    ToolContext::withValues(['request' => new \Agent\Request([
        'url' => 'http://127.0.0.1:1/v1/chat/completions',
        'body' => ['messages' => [['role' => 'user', 'content' => 'private chat content']]],
        'head' => ['Authorization' => 'Bearer diagnostics-secret'],
        'model' => 'diagnostics-probe',
    ])], function () {
        $health = Diagnostics::health();
        $serialized = json_encode($health);
        check(strpos($serialized, 'diagnostics-secret') === false, 'health does not leak configured credentials');
        check(strpos($serialized, 'private chat content') === false, 'health does not leak request chat content');
        check(isset($health['checks']['model_registry']) && is_array($health['checks']['model_registry']),
            'health includes a bounded model registry check');
    });
}

// Runtime reports actual, secret-free PHP facts.
$runtime = Diagnostics::runtime();
check($runtime['php'] === PHP_VERSION, 'runtime reports the actual PHP version');
check($runtime['sapi'] === PHP_SAPI, 'runtime reports the actual SAPI');
check(is_array($runtime['extensions']) && count($runtime['extensions']) > 0, 'runtime lists loaded extensions');
check(is_array($runtime['pdo_drivers']), 'runtime lists PDO drivers');
check(!array_key_exists('env', $runtime) && !array_key_exists('argv', $runtime)
    && !array_key_exists('paths', $runtime), 'runtime exposes no environment, arguments, or secret paths');
