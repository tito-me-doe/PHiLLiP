<?php
require __DIR__ . '/helpers.php';

use Agent\PersonaSheet;

$sheets = PersonaSheet::all();
check(count($sheets) >= 8, 'At least eight personas ship by default');
check(array_keys($sheets)[0] === 'phillip', 'PHiLLiP is first, the default companion');

// Every sheet meets the shipping standard, and its prompt composes.
foreach ($sheets as $slug => $sheet) {
    check(PersonaSheet::validate($sheet) === [], "$slug meets the shipping standard: " . implode('; ', PersonaSheet::validate($sheet)));
    $prompt = PersonaSheet::prompt($sheet);
    check(strpos($prompt, $sheet['name']) !== false && strpos($prompt, $sheet['examples'][0]['reply']) !== false, "$slug prompt carries the name and example replies");
    check(str_word_count($prompt) < 1200, "$slug prompt stays a reasonable size");
}

// Uniqueness: names, taglines, archetypes and signature phrases are never shared.
foreach (['name', 'tagline', 'archetype'] as $field) {
    $values = array_map('strtolower', array_column($sheets, $field));
    check(count($values) === count(array_unique($values)), "Every persona has a unique $field");
}
$signatures = [];
foreach ($sheets as $slug => $sheet) foreach ($sheet['voice']['signature'] as $phrase) $signatures[strtolower(trim($phrase, ' .'))][] = $slug;
check(!array_filter($signatures, function ($owners) { return count($owners) > 1; }), 'No signature phrase is shared between personas');

// Voices must differ, not merely carry different names: vocabulary overlap between any two characters stays low.
$words = function ($sheet) {
    $text = strtolower(implode(' ', array_column($sheet['examples'], 'reply')) . ' ' . $sheet['voice']['diction'] . ' ' . implode(' ', $sheet['voice']['signature']));
    preg_match_all("/[a-z']+/", $text, $m);
    return array_unique(array_filter($m[0], function ($w) { return strlen($w) > 2 && !in_array($w, ['the', 'and', 'for', 'you', 'your', 'that', 'this', 'with', 'are', 'not', 'but', 'will', 'can', 'its'], true); }));
};
$slugs = array_keys($sheets); $closest = 0.0;
foreach ($slugs as $i => $a) foreach (array_slice($slugs, $i + 1) as $b) {
    $x = $words($sheets[$a]); $y = $words($sheets[$b]);
    $closest = max($closest, count(array_intersect($x, $y)) / count(array_unique(array_merge($x, $y))));
}
check($closest < 0.25, 'No two personas share much vocabulary (closest overlap ' . round($closest, 3) . ')');

// A cast with something for everyone.
$pronouns = array_unique(array_column($sheets, 'pronouns'));
check(count($pronouns) >= 3 && in_array('he/him', $pronouns, true) && in_array('she/her', $pronouns, true) && in_array('they/them', $pronouns, true), 'The cast includes he/him, she/her and they/them characters');
check($sheets['phillip']['maturity'] === 'everyone' && preg_match('/\b(sexy|raunchy|damn|hell)\b/i', PersonaSheet::prompt($sheets['phillip'])) === 0, 'The default persona is wholesome');
$adult = array_filter($sheets, function ($s) { return $s['maturity'] === 'adult'; });
check($adult && count($adult) < count($sheets) / 2, 'Adult-flavored personas exist but are a minority');
foreach ($adult as $slug => $sheet) check(stripos(implode(' ', $sheet['boundaries']), 'explicit') !== false, "$slug (adult) states it is never sexually explicit");

// Invalid sheets are rejected with a reason.
$bad = $sheets['dave']; $bad['name'] = 'PHP Developer';
check(count(array_filter(PersonaSheet::validate($bad), function ($p) { return strpos($p, 'role') !== false; })) === 1, 'A role title is refused as a persona name');
$bad = $sheets['dave']; $bad['examples'] = array_slice($bad['examples'], 0, 1);
check(PersonaSheet::validate($bad) !== [], 'A sheet without enough example exchanges is refused');
$bad = $sheets['dave']; unset($bad['reactions']['sad']);
check(count(array_filter(PersonaSheet::validate($bad), function ($p) { return strpos($p, 'reactions.sad') !== false; })) === 1, 'A shipped persona must define every companion reaction');
$bad = $sheets['dave']; $bad['reactions']['happy'] = str_repeat('x', 61);
check(PersonaSheet::validate($bad) !== [], 'A companion reaction must stay a short line');
foreach ($sheets as $slug => $sheet) check(array_keys($sheet['reactions']) === PersonaSheet::REACTIONS, "$slug defines its reactions in the standard order");
check(count(array_unique(array_map(function ($s) { return $s['reactions']['resting']; }, $sheets))) === count($sheets), 'Every persona greets from the corner in its own words');
$bad = $sheets['vesper']; $bad['boundaries'] = ['Be nice.', 'Say you are an AI.'];
check(count(array_filter(PersonaSheet::validate($bad), function ($p) { return strpos($p, 'under 18') !== false; })) === 1, 'An adult persona must define behavior for under-18 users');
expectException(function () use ($bad) { PersonaSheet::prompt($bad); }, 'not valid');

// A stored sheet from before reactions existed still starts conversations, and install fills the gap without touching edits.
$stale = $sheets['dave']; unset($stale['reactions']);
check(is_string(PersonaSheet::prompt($stale)) && PersonaSheet::prompt($stale) !== '', 'A sheet without reactions still composes its prompt');
PersonaSheet::install();
Agent\Database::execute("UPDATE personas SET sheet = sheet - 'reactions', tagline = 'Edited by an administrator' WHERE slug = 'dave'");
$result = PersonaSheet::install();
$row = Agent\Database::row("SELECT sheet, tagline FROM personas WHERE slug = 'dave'");
$stored = is_string($row['sheet']) ? json_decode($row['sheet'], true) : $row['sheet'];
check($result['updated'] === 1 && !empty($stored['reactions']), 'Install backfills missing reactions on an existing persona');
check($row['tagline'] === 'Edited by an administrator', 'Backfilling reactions keeps administrator edits');
Agent\Database::execute("UPDATE personas SET tagline = ? WHERE slug = 'dave'", [$sheets['dave']['tagline']]);
echo "ALL PASS\n";
