<?php
require __DIR__ . '/helpers.php';

use Agent\DataExitPolicy;
use Agent\PrivacyTransform;

DataExitPolicy::reset();
PrivacyTransform::clear();

// Default inactive policy preserves current behavior.
check(DataExitPolicy::isActive() === false, 'Data-exit policy is inactive by default');
check(DataExitPolicy::decision(DataExitPolicy::CATEGORY_TOOL) === DataExitPolicy::ACTION_ALLOW, 'Inactive categories resolve to allow');
check(DataExitPolicy::enforce(DataExitPolicy::CATEGORY_TOOL) === true, 'Enforcement passes through when inactive');

// Administrator configures and activates the policy.
$configured = DataExitPolicy::configure(['active' => true, DataExitPolicy::CATEGORY_TOOL => DataExitPolicy::ACTION_BLOCK]);
check($configured['active'] === true, 'Policy can be activated by an administrator');
check($configured['policy'][DataExitPolicy::CATEGORY_TOOL] === DataExitPolicy::ACTION_BLOCK, 'Tool category is set to block');
check(DataExitPolicy::isActive() === true, 'isActive reflects activation');

// A blocked category is enforced at the seam: recoverable rejection, no execution.
expectException(function () { DataExitPolicy::enforce(DataExitPolicy::CATEGORY_TOOL); }, 'Data exit policy blocks ' . DataExitPolicy::CATEGORY_TOOL);

// Allow and obfuscate categories still pass enforcement.
DataExitPolicy::configure(['active' => true, DataExitPolicy::CATEGORY_TOOL => DataExitPolicy::ACTION_OBFUSCATE]);
check(DataExitPolicy::enforce(DataExitPolicy::CATEGORY_TOOL) === true, 'Obfuscate category passes enforcement');

// Unknown categories and invalid actions are rejected outright.
expectException(function () { DataExitPolicy::decision('telemetry.outbound'); }, 'Unknown data-exit category');
expectException(function () { DataExitPolicy::configure([DataExitPolicy::CATEGORY_TOOL => 'redact']); }, 'Invalid data-exit action');

// PrivacyTransform: local obfuscation returns an opaque token, never the raw value.
PrivacyTransform::clear();
$token = PrivacyTransform::obfuscate('sensitive value', DataExitPolicy::CATEGORY_TOOL);
check(is_string($token) && $token !== 'sensitive value' && strpos($token, 'sensitive value') === false, 'Obfuscation returns an opaque token that does not contain the raw value');
check(PrivacyTransform::recover($token, DataExitPolicy::CATEGORY_TOOL) === 'sensitive value', 'Explicit recovery returns the original value');

// Unknown or missing tokens fail explicitly; no silent substitute.
expectException(function () { PrivacyTransform::recover('privacy:tool:0000000000000000', DataExitPolicy::CATEGORY_TOOL); }, 'Unknown or missing privacy token');

expectException(function () use ($token) { PrivacyTransform::recover($token, DataExitPolicy::CATEGORY_MODEL); }, 'Unknown or missing privacy token');

// The local mapping is inspectable and token maps to original.
$map = PrivacyTransform::mapping(DataExitPolicy::CATEGORY_TOOL);
check(is_array($map) && isset($map[$token]) && $map[$token] === 'sensitive value', 'Local mapping is inspectable and maps the token to the original value');

// Deactivation restores current behavior.
DataExitPolicy::configure(['active' => false]);
PrivacyTransform::clear();
check(DataExitPolicy::isActive() === false, 'Deactivation restores current behavior');
check(DataExitPolicy::enforce(DataExitPolicy::CATEGORY_TOOL) === true, 'Enforcement passes through after deactivation');

echo "ALL PASS\n";
