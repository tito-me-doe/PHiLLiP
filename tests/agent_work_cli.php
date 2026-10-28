<?php
require __DIR__ . '/helpers.php';

use Agent\AgentCli;

function parseErr(array $args)
{
    try { AgentCli::parse($args); return null; } catch (\InvalidArgumentException $e) { return $e->getMessage(); }
}

// work accepts repeated --file and --command only.
$r = AgentCli::parse(['work','alice','--file','a.txt','--file','b.txt','--command','["x"]','--command','["y"]','do it']);
check($r['action'] === 'work', 'work action');
check(is_array($r['options']['file'] ?? null) && $r['options']['file'] === ['a.txt','b.txt'], 'work repeated --file collected');
check(is_array($r['options']['command'] ?? null) && $r['options']['command'] === ['["x"]','["y"]'], 'work repeated --command collected raw');
check($r['words'] === ['alice','do it'], 'work keeps name and task words');

// work rejects duplicates of other options and missing reference.
check(parseErr(['work','alice','--profile','p','--profile','q']) !== null, 'work repeated profile rejected');
check(parseErr(['work']) !== null, 'work missing agent reference rejected');
check(parseErr(['work','alice','--timeout','31']) !== null, 'work timeout above 30 rejected');
check(parseErr(['work','alice','--timeout','-1']) !== null, 'work timeout negative rejected');
$timeout0 = AgentCli::parse(['work','alice','--timeout','0']);
check($timeout0['options']['timeout'] === 0, 'work timeout 0 accepted');
$timeout30 = AgentCli::parse(['work','alice','--timeout','30']);
check($timeout30['options']['timeout'] === 30, 'work timeout 30 accepted');

// literal -- preserves leading-dash task words.
$r = AgentCli::parse(['work','alice','--','-weird']);
check($r['words'] === ['alice','-weird'], 'work literal -- preserves dash task');

// peek requires exactly one agent.
check(parseErr(['peek']) !== null, 'peek missing reference rejected');
check(parseErr(['peek','a','b']) !== null, 'peek two args rejected');
$peek = AgentCli::parse(['peek','alice']);
check($peek['action'] === 'peek' && $peek['words'] === ['alice'], 'peek one arg accepted');

// other actions still reject duplicates.
check(parseErr(['assign','alice','--key','k','--key','k2']) !== null, 'assign repeated key rejected');
check(parseErr(['create','a','--profile','p','--profile','q']) !== null, 'create repeated profile rejected');

// help includes work and peek.
$help = AgentCli::help();
check(strpos($help, 'work NAME') !== false && strpos($help, 'peek NAME') !== false, 'help documents work and peek');
