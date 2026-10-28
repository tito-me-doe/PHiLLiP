<?php
require __DIR__ . '/helpers.php';

use Agent\Tools\Source;

Agent\Log::configure(['user' => 3, 'admin' => 3, 'console' => 3, 'file' => 3, 'database' => 3]);
$previous = getcwd();
$workspace = sys_get_temp_dir() . '/phillip-source-adversarial-' . bin2hex(random_bytes(6));
mkdir($workspace, 0700);
$names = ['valid.php', 'internal space.php', ' leading.php', 'trailing.php ', "invalid-\xff.php", "line\nbreak.php", 'back\\slash.php', 'C:drive.php'];
try {
    foreach ($names as $name) file_put_contents($workspace . '/' . $name, "<?php\nreturn true;\n");
    chdir($workspace);
    $registry = new Agent\ToolRegistry();
    $registry->register(Source::class);
    $name = $registry->definitions()[0]['function']['name'];
    $result = $registry->execute(['id' => 'source-list', 'function' => ['name' => $name, 'arguments' => '{}']]);
    check(strpos($result['content'], 'Tool error:') !== 0, 'One non-UTF-8 filename cannot poison the complete source listing');
    $listing = json_decode($result['content'], true);
    check($listing === ['internal space.php', 'valid.php'], 'JSON source listing retains internal spaces and usable siblings while skipping unaddressable names');
    foreach ($listing as $path) {
        check(preg_match('//u', $path) === 1 && !preg_match('/[\x00-\x1f\x7f\\\\]/', $path), 'Listed paths are UTF-8 and addressable by the Source read contract');
        check(Source::read($path)['text'] === "<?php\nreturn true;", 'Every listed fixture source path is readable');
    }
} finally {
    chdir($previous);
    foreach ($names as $name) unlink($workspace . '/' . $name);
    rmdir($workspace);
}
