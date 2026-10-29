<?php use Agent\Views as V; ?>
<?php if (!$current): ?>
<div class="eyebrow">Your thoughtful little sidekick</div><h1>Who shall we talk to?</h1>
<p>Choose a persona to begin, or just send a message as yourself.</p>
<?php if (!$state['profiles']): ?><div class="error-panel">No chat models are enabled. Ask an administrator to configure a working profile.</div><?php endif; ?>
<form method="post" id="persona-start">
<?= V::csrf($state,'persona-start') ?><input type="hidden" name="creation_key" value="<?= bin2hex(random_bytes(16)) ?>">
<div class="table-wrap"><table class="persona-table registry"><thead><tr><th>Persona</th><th>About</th><th></th></tr></thead><tbody>
<?php
$personas = [[],[]];
foreach ($state['personas'] as $persona) {
    $persona['available'] = $state['profiles'] && !array_diff(Agent\ToolRegistry::policies($persona['tools']),$state['tools']);
    $personas[$persona['available'] ? 0 : 1][] = $persona;
}
foreach (array_merge($personas[0],$personas[1]) as $persona): ?>
<tr><td><strong><?= V::h($persona['name']) ?></strong></td>
<td><?= V::h(mb_strimwidth(preg_replace('/\s+/u',' ',$persona['prompt']),0,145,'…')) ?>
<?php if (!$persona['available']): ?><small><?= $state['profiles'] ? 'Requires tools not available to your account' : 'A chat model must be enabled first' ?></small><?php endif; ?></td>
<td><button class="persona-card" name="persona_id" value="<?= $persona['id'] ?>" title="<?= V::h($persona['name']) ?>" aria-label="Start conversation as <?= V::h($persona['name']) ?>" <?= $persona['available'] ? '' : 'disabled' ?>>Start Conversation</button></td></tr>
<?php endforeach; ?>
</tbody></table></div></form>
<?php else:
    $calls = [];
    foreach ($current['messages'] as $message):

        $raw = $message['raw'];
        foreach (isset($raw['tool_calls']) ? $raw['tool_calls'] : [] as $call) {
            $calls[$call['id']] = $call['function'];
        }
        if ($message['role'] === 'system' || !empty($raw['_internal'])) {
            continue;
        }
        if ($message['role'] === 'tool'): ?>
<details class="activity tool-activity" data-key="tool-<?= $message['id'] ?>" <?= !empty($current['panels']['tool-' . $message['id']])
    ? 'open'
    : '' ?>><summary><?= V::icon('tools') ?><span><?= V::h(
    isset($calls[$raw['tool_call_id']]) ? Agent\ToolRegistry::label($calls[$raw['tool_call_id']]['name']) : 'Tool result'
) ?></span><span class="badge <?= !empty($raw['_tool_error']) ? 'error' : '' ?>"><?= !empty($raw['_tool_error'])
    ? 'Needs attention'
    : 'Result' ?></span></summary><div class="tool-output"><?php
if (!empty($calls[$raw['tool_call_id']]['arguments'])): ?><h4>Inputs</h4><?= V::toolValue(
    $calls[$raw['tool_call_id']]['arguments']
) ?><h4>Result</h4><?php endif;
echo V::toolValue(\Agent\Message::text($raw));
?></div>
<?php foreach (is_array($raw['content']) ? $raw['content'] : [] as $part):
    if (
        isset($part['type'], $part['image_url']['url']) &&
        $part['type'] === 'image_url' &&
        is_string($part['image_url']['url']) &&
        preg_match('~\Adata:image/png;base64,[A-Za-z0-9+/]+={0,2}\z~', $part['image_url']['url'])
    ): ?>
<img src="<?= V::h($part['image_url']['url']) ?>" alt="Browser screenshot" style="max-width:100%;height:auto">
<?php endif;
endforeach; ?></details>
<?php continue;endif;
        ?>
<article class="message <?= V::h($message['role']) ?>" id="message-<?= $message['id'] ?>" data-id="<?= $message[
    'id'
] ?>"><div class="message-head">
<?php if ($message['role'] !== 'assistant'): ?><span class="user-circle"><?= V::h(
    mb_substr($message['author_name'], 0, 1)
) ?></span><?php endif; ?>
<span><?= V::h($message['author_name']) ?></span><?php if (
    !empty($raw['_interrupted'])
): ?><span class="meta">interrupted</span><?php endif; ?>
<?php if (
    $message['role'] === 'user' &&
    $message['author_id'] === $state['user']['id']
): ?><button class="icon" data-dialog="edit" data-id="<?= $message['id'] ?>" aria-label="Edit your message"><?= V::icon(
    'edit'
) ?></button><?php endif; ?></div>
<?php
$reasoning = isset($raw['reasoning_content']) ? $raw['reasoning_content'] : (isset($raw['reasoning']) ? $raw['reasoning'] : '');
if ($reasoning): ?><details class="activity" data-key="reason-<?= $message['id'] ?>" <?= !empty(
    $current['panels']['reason-' . $message['id']]
)
    ? 'open'
    : '' ?>><summary>Reasoning</summary><div class="reasoning"><?= V::h($reasoning) ?></div></details><?php endif;
?>
<div class="message-body"><?= V::markdown(
    is_array($raw['content']) ? implode("\n", array_column($raw['content'], 'text')) : $raw['content']
) ?></div>
<?php foreach (isset($raw['tool_calls']) ? $raw['tool_calls'] : [] as $call): ?><details class="activity tool-call" data-key="call-<?= V::h(
    $call['id']
) ?>"><summary><?= V::icon('tools') ?>Using <?= V::h(
    Agent\ToolRegistry::label($call['function']['name'])
) ?></summary><div class="tool-output"><?= V::toolValue($call['function']['arguments']) ?></div></details><?php endforeach; ?>
<?php if (!empty($raw['attachments'])): ?><div class="attachment-chips"><?= V::render('attachments', [
    'files' => $raw['attachments'],
    'pending' => false
]) ?></div><?php endif; ?>
</article>
<?php
    endforeach;
    foreach ($current['runs'] as $run):
        if ($run['status'] === 'failed'): ?><div class="error-panel">A run could not complete: <?= V::h($run['error']) ?></div><?php endif;
    endforeach;
    ?>
<?php foreach ($current['runs'] as $run):

    if ($run['status'] !== 'running') {
        continue;
    }
    $live = $run['state'];
    ?>
<article class="message assistant" id="live-message"><div class="message-head"><span data-assistant-name><?= V::h(
    isset($run['assistant_name']) ? $run['assistant_name'] : 'PHiLLiP'
) ?></span><span class="meta">working</span></div><details class="activity" id="live-reasoning" <?= empty($live['reasoning'])
    ? 'hidden'
    : '' ?>><summary>Reasoning</summary><div class="reasoning"><?= V::h(
    isset($live['reasoning']) ? $live['reasoning'] : ''
) ?></div></details><div id="live-body" class="message-body" style="white-space:pre-wrap"><?= V::h(
    isset($live['content']) ? $live['content'] : ''
) ?></div></article>
<?php
endforeach; ?>
<script type="application/json" data-runs-state><?= V::json([
    'workflow' => $current['workflow'],
    'panels' => $current['panels'],
    'title' => $current['thread']['title']
]) ?></script>
<?php endif; ?>
