<?php
use Agent\Views as V;
$count = 0;
$date = null;
?>
<table class="history-table" aria-label="Conversations and projects"><tbody>
<?php foreach ($threads as $row):
    if ($row['visibility'] !== $tab) continue;
    $count++;
    $project = !empty($row['project_id']);
    $stamp = $row['activity_at'];
    $day = substr($stamp, 0, 10);
    $active = $current && $current['thread']['id'] == $row['id'];
    if ($project && isset($_GET['project'])) $active = (string)$_GET['project'] === (string)$row['project_id'];
    if ($day !== $date):
        $date = $day; ?>
        <tr class="history-date"><td><time datetime="<?= V::h($day) ?>"><?= V::h(date('M j, Y', strtotime($day))) ?></time></td></tr>
    <?php endif; ?>
    <tr class="thread-row" data-work-kind="<?= $project ? 'project' : 'conversation' ?>">
        <td><a class="thread-item <?= $active ? 'active' : '' ?>"
            href="<?= $project ? Agent\Pages::link('projects', ['project'=>$row['project_id']]) : Agent\Pages::link('conversations', ['thread'=>$row['id']]) ?>"
            <?= $project ? '' : 'data-thread="'.$row['id'].'"' ?> title="<?= V::h($stamp) ?>">
            <strong><?= V::h(trim($row['title']) !== '' ? $row['title'] : ($project ? 'Untitled project' : 'New conversation')) ?></strong>
            <small><?= $project ? 'Project · '.V::h($row['project_status']) : 'Conversation' ?></small>
	</a>
        <?php if (!$project && (string)$row['owner_id'] === (string)$state['user']['id']): ?>
            <form method="post" action="<?= V::url() ?>" class="delete-thread-form">
                <?= V::csrf($state, 'delete-thread') ?><input type="hidden" name="thread_id" value="<?= $row['id'] ?>">
                <button class="icon thread-delete" aria-label="Delete conversation <?= V::h($row['title'] ?: 'New conversation') ?>"><?= V::icon('trash') ?></button>
            </form>
        <?php endif; ?></td>
    </tr>
<?php endforeach; ?>
<?php if (!$count): ?><tr><td colspan="2" class="empty">Your conversations and projects will appear here.</td></tr><?php endif; ?>
</tbody></table>
