<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/fixtures/WebFixture.php';

$web = new WebFixture();
try {
    // Local summary engine that validates summary sub-requests.
    $engineUrl = $web->server(__DIR__ . '/fixtures/summary_engine.php');

    // Request subclass that exposes protected summarize.
    class SummaryCompactionRequest extends Agent\Request
    {
        public function exposeSummarize(array $middle, $budget) { return $this->summarize($middle, $budget); }
    }

    $config = [
        'url' => $engineUrl . '/v1/chat/completions',
        'model' => 'summary-fixture',
        'stream' => false,
        'compaction_enabled' => true,
        'context_window' => 4096,
    ];
    // Parent-like chat config carrying nested thinking and two token fields.
    $config['body'] = [
        'model' => 'summary-fixture',
        'stream' => false,
        'reasoning_effort' => 'high',
        'chat_template_kwargs' => ['enable_thinking' => true],
        'max_tokens' => 4096,
        'max_completion_tokens' => 4096,
        'messages' => [],
    ];

    $request = new SummaryCompactionRequest($config);
    $originalBody = $request->request['body'];

    // Large middle range that forces compaction/summarization.
    $middle = [];
    for ($i = 0; $i < 200; $i++) {
        $middle[] = ['role' => 'user', 'content' => str_repeat('word ', 60) . $i];
    }

    try {
        $summary = $request->exposeSummarize($middle, 1024);
        check(is_string($summary) && $summary !== '', 'summarize returns a nonempty summary string');
    } catch (\Throwable $e) {
        throw new \RuntimeException('summarize failed: ' . $e->getMessage());
    }

    // Original caller chat config must remain unchanged (no leaked mutations).
    check($request->request['body'] === $originalBody, 'original chat config object is unchanged');
    check(($request->request['body']['chat_template_kwargs']['enable_thinking'] ?? null) === true,
        'original chat config retains enable_thinking=true');
    check(isset($request->request['body']['max_tokens']) && $request->request['body']['max_tokens'] === 4096,
        'original chat config retains its max_tokens');
    check(isset($request->request['body']['max_completion_tokens']) && $request->request['body']['max_completion_tokens'] === 4096,
        'original chat config retains its max_completion_tokens');

    // Exercise cancellation mid-summary: a control level of 2 aborts every sub-request.
    $cancelCfg = [
        'url' => $engineUrl . '/v1/chat/completions',
        'model' => 'summary-fixture',
        'stream' => false,
        'compaction_enabled' => true,
        'context_window' => 4096,
        'control' => fn() => 2,
    ];
    $cancelReq = new SummaryCompactionRequest($cancelCfg);
    expectException(function () use ($cancelReq, $middle) {
        $cancelReq->exposeSummarize($middle, 128);
    }, 'User cancelled model execution');
    check(true, 'cancellation aborts the summary sub-request via expectException');

    // Successful completions against the enforcing fixture prove every summary sub-request carried:
    // thinking explicitly false, exactly one adequate token field (>=512), no tools, and a stated
    // numeric retained length target in the system prompt.
    check($summary !== '', 'all summary sub-requests completed successfully');

    print "PASS: Summary compaction disables reasoning, bounds one token budget, preserves caller config\n";
} finally {
    $web->close();
}
