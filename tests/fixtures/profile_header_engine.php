<?php
header('Content-Type: application/json');
if (!isset($_SERVER['HTTP_AUTHORIZATION']) || $_SERVER['HTTP_AUTHORIZATION'] !== 'Bearer PROFILE_CONTRACT_SECRET') {
    http_response_code(401); echo '{"error":"Missing fixture authorization"}'; return;
}
echo '{"choices":[{"message":{"role":"assistant","content":"PROFILE_AUTH_READY"},"finish_reason":"stop"}]}';
