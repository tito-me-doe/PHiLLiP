<?php
require dirname(__DIR__) . '/bootstrap.php';

function check($condition, $message)
{
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: $message\n";
}

function expectException($callback, $contains)
{
    try { $callback(); } catch (Throwable $e) {
        check(strpos($e->getMessage(), $contains) !== false, 'Expected failure: ' . $contains);
        return;
    }
    throw new RuntimeException('Expected exception: ' . $contains);
}
