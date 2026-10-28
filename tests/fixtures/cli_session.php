<?php
require dirname(__DIR__, 2) . '/bootstrap.php';
Agent\Auth::login($argv[1], 'phillip-demo');
if (isset($argv[2]) && $argv[2] === 'expired') { session_start(); $_SESSION['phillip_last_seen'] = time() - (int)ini_get('session.gc_maxlifetime') - 60; session_write_close(); }
fwrite(STDOUT, session_id());
