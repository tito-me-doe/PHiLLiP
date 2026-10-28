<?php
function cliProcess(array $args, $input = '', $unprivileged = false, array $environment = [], $signal = null)
{
    $env = array_merge(['LOG_MIN_FILE'=>'3','NO_COLOR'=>'1'], $environment);
    $prefix = 'exec env'; foreach ($env as $key=>$value) $prefix .= ' ' . escapeshellarg($key . '=' . $value);
    $command = $prefix . ' ' . ($unprivileged ? escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/cli_unprivileged.php') : escapeshellarg(dirname(__DIR__, 2) . '/bin/ok-phill'));
    foreach ($args as $arg) $command .= ' ' . escapeshellarg($arg);
    $child = proc_open($command, [['pipe','r'],['pipe','w'],['pipe','w']], $streams);
    fwrite($streams[0], $input); fclose($streams[0]);
    if ($signal !== null) { usleep(500000); proc_terminate($child, $signal); }
    $out = stream_get_contents($streams[1]); $err = stream_get_contents($streams[2]); fclose($streams[1]); fclose($streams[2]);
    return ['status'=>proc_close($child),'out'=>$out,'err'=>$err];
}
