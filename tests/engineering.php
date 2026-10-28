<?php
require __DIR__ . '/helpers.php';

use Agent\Engineering;
use Agent\WorkflowCli;
use Agent\ToolRegistry;
use Agent\Runtime;
use Agent\ToolContext;

/** Engineering persona catalog, workflow definitions, tool reflection, CLI parse and installer idempotency. */
$catalog = Engineering::personas();
check(is_array($catalog) && count($catalog) === 13, 'Engineering persona catalog loads exactly 13 reusable roles');

$expectedRoles = [
    'engineering-coordinator', 'web-researcher', 'code-researcher', 'database-researcher',
    'architect', 'implementer', 'test-engineer', 'adversarial-reviewer', 'security-reviewer',
    'classifier', 'documentation-writer', 'ui-tester', 'operations-researcher',
];
foreach ($expectedRoles as $roleId) {
    check(isset($catalog[$roleId]), 'Catalog contains role ' . $roleId);
    $p = $catalog[$roleId];
    check(is_string($p['name']) && trim($p['name']) !== '', 'Role ' . $roleId . ' has an exact name');
    check(is_string($p['prompt']) && trim($p['prompt']) !== '', 'Role ' . $roleId . ' has a prompt');
    check(is_array($p['tools']), 'Role ' . $roleId . ' tools is a list');
    foreach ($p['tools'] as $tool) {
        check(is_string($tool) && strpos($tool, '\\') !== false && strpos($tool, '::') === false, 'Role ' . $roleId . ' tool is a namespaced class: ' . $tool);
    }
}

// Reflect ALL role tool definitions (deduplicated). Diagnostics and SQL are role tools but never executed here.
$registry = new ToolRegistry();
$allTools = []; foreach ($catalog as $persona) foreach ($persona['tools'] as $tool) $allTools[] = $tool; $allTools = array_values(array_unique($allTools));
foreach ($allTools as $tool) $registry->register($tool);
$names = [];
foreach ($registry->definitions() as $def) $names[] = $def['function']['name'];
$diagnosticsReflected = $sqlReflected = false;
foreach ($names as $name) {
    if (strpos($name, 'Agent_Tools_Diagnostics_') === 0) $diagnosticsReflected = true;
    if (strpos($name, 'Agent_Tools_SQL_') === 0) $sqlReflected = true;
}
check($diagnosticsReflected, 'Diagnostics is a reflected role tool (coordinator union; never executed here)');
check($sqlReflected, 'SQL is a reflected role tool (database/operations researchers; never executed here)');

// Classifier contract normalizes to object with category/confidence/reason.
$classifier = $catalog['classifier'];
check($classifier['tools'] === [], 'Classifier has no tools');
check(isset($classifier['contract']) && is_array($classifier['contract']), 'Classifier has a response contract');
$contract = \Agent\Schema::normalize($classifier['contract']);
check($contract['type'] === 'object', 'Classifier contract normalizes to object type');
$props = is_object($contract['properties']) ? (array)$contract['properties'] : $contract['properties'];
check(is_array($props) && isset($props['category']) && isset($props['confidence']) && isset($props['reason']), 'Classifier contract has category/confidence/reason properties');
check(is_array($contract['required']) && in_array('category', $contract['required'], true) && in_array('confidence', $contract['required'], true) && in_array('reason', $contract['required'], true), 'Classifier contract requires category, confidence, reason');

// Workflow catalog: 6 workflows, roles resolve, phases and coordinator present.
$workflows = Engineering::workflows();
$expectedWorkflows = ['web-research', 'code-research', 'database-research', 'implement', 'review', 'triage'];
check(count($workflows) === 6, 'Engineering workflow catalog defines 6 workflows');
foreach ($expectedWorkflows as $id) {
    check(isset($workflows[$id]), 'Workflow ' . $id . ' is defined');
    $wf = $workflows[$id];
    check(is_array($wf['roles']) && $wf['roles'] !== [], 'Workflow ' . $id . ' owns a roles list');
    check(is_array($wf['phases']) && $wf['phases'] !== [], 'Workflow ' . $id . ' owns phases');
    check(is_string($wf['coordinator']) && trim($wf['coordinator']) !== '', 'Workflow ' . $id . ' owns coordinator instructions');
    foreach ($wf['roles'] as $roleId) check(isset($catalog[$roleId]), 'Workflow ' . $id . ' role ' . $roleId . ' resolves to a catalog persona');
}

// All four write-capable roles report required approval in their catalog prompts.
foreach (['implementer','test-engineer','documentation-writer','ui-tester'] as $role) {
    check(strpos($catalog[$role]['prompt'], 'approval') !== false, 'Write role ' . $role . ' reports required approval');
}

// Workflow CLI parse: list/show/help succeed; invalid usage exits 2.
foreach ([['list'], ['show', 'web-research'], ['help']] as $args) {
    check(WorkflowCli::run($args) === 0, 'WorkflowCli ' . implode(' ', $args) . ' returns success');
}
foreach ([['frobnicate'], ['run'], ['run', 'web-research'], ['show', 'unknown'], ['install', 'extra'], ['list', '--bogus']] as $args) {
    check(WorkflowCli::run($args) === 2, 'Invalid workflow CLI usage fails with exit code 2: ' . implode(' ', $args));
}

expectException(function () { Engineering::start('no-such-workflow', 'do the thing'); }, 'Unknown workflow');
expectException(function () { Engineering::start('web-research', ''); }, 'A workflow task is required');

// Installer idempotency inside an outer transaction that always rolls back; never persists test changes.
$canInstall = Runtime::isCli() && !ToolContext::get('frontend') && !ToolContext::get('run_id');
$unix = Runtime::unixIdentity();
$catalogPath = Runtime::root() . '/resources/engineering/personas.json';
$ownerUid = @fileowner($catalogPath);
$isInstaller = $canInstall && $unix['uid'] !== null && ($unix['root'] === true || $unix['uid'] === $ownerUid);
$coordinator = $catalog['engineering-coordinator'];
$shippedPrompt = $coordinator['prompt'];

if (!$canInstall) {
    check(true, 'Install test skipped: not a plain local CLI operator context');
} elseif (!$isInstaller) {
    check(true, 'Install test skipped: not root and not the catalog owner Unix UID (' . var_export($ownerUid, true) . ')');
} else {
    $db = Agent\Database::connection();
    $db->beginTransaction();
    try {
        // Sentinel prompt on one catalog default; install(false) must preserve it (idempotent DO NOTHING).
        $db->prepare("UPDATE personas SET prompt = ? WHERE name = ? AND owner_id IS NULL AND visibility = 'organization' AND is_default = TRUE")->execute(['engineering-install-sentinel', $coordinator['name']]);
        $row = Agent\Database::row("SELECT prompt FROM personas WHERE name = ? AND owner_id IS NULL AND visibility = 'organization' AND is_default = TRUE", [$coordinator['name']]);
        check($row['prompt'] === 'engineering-install-sentinel', 'Sentinel prompt installed for idempotency test');

        $result = Engineering::install(false);
        check(is_array($result) && isset($result['count']) && $result['count'] === 13, 'Install default registers 13 organization default personas');
        check(!isset($result['replaced']) || $result['replaced'] === false, 'Default install does not replace existing personas');
        $row = Agent\Database::row("SELECT prompt FROM personas WHERE name = ? AND owner_id IS NULL AND visibility = 'organization' AND is_default = TRUE", [$coordinator['name']]);
        check($row['prompt'] === 'engineering-install-sentinel', 'Idempotent install preserves the sentinel prompt');

        // install(true) restores the shipped prompt within the same transaction.
        $result = Engineering::install(true);
        check(is_array($result) && $result['replaced'] === true, 'Replace install updates all 13 personas');
        $row = Agent\Database::row("SELECT prompt FROM personas WHERE name = ? AND owner_id IS NULL AND visibility = 'organization' AND is_default = TRUE", [$coordinator['name']]);
        check($row['prompt'] === $shippedPrompt, 'Replace install restores the shipped prompt within transaction');
    } finally {
        $db->rollBack();
    }
    // Rollback restored the original DB state; no test-modified personas persist.
    $row = Agent\Database::row("SELECT prompt FROM personas WHERE name = ? AND owner_id IS NULL AND visibility = 'organization' AND is_default = TRUE", [$coordinator['name']]);
    check($row['prompt'] !== 'engineering-install-sentinel', 'Rollback removed the sentinel; no test personas persist');
}

echo "PASS: engineering catalog, contracts, reflection, workflows, CLI parse and installer idempotency validated\n";
