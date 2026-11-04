<?php
require __DIR__ . '/helpers.php';

use Agent\Avatar;

// Every shipped character is one body wearing one face, and composes into a single inline SVG.
$presets = Avatar::presets();
check(count($presets) === 11 && in_array('terminal', $presets, true) && in_array('sprout', $presets, true), 'The eleven shipped characters are presets');
foreach ($presets as $id) {
    $svg = Avatar::render($id);
    check(strpos($svg, '<svg ') === 0 && substr_count($svg, 'class="avatar-body"') === 1 && substr_count($svg, 'class="avatar-face"') === 1
        && substr_count($svg, 'class="avatar-speaking-mouth"') === 1 && substr_count($svg, 'class="avatar-crash"') === 1
        && strpos($svg, '{{') === false && ($id === 'sprout' || substr_count($svg, 'class="lid ') === 6), "$id composes with the shared part vocabulary");
}

// Ids: a character slug, or body.face; anything unknown is rejected and falls back to the terminal.
check(Avatar::valid('arthur') && Avatar::valid('noto-1f34e.sprout') && Avatar::valid('noto-1f34e'), 'Characters, mixes and bare bodies are valid ids');
check(!Avatar::valid('nope') && !Avatar::valid('arthur.nope') && !Avatar::valid('../arthur') && !Avatar::valid("arthur\n") && !Avatar::valid('a.b.c') && !Avatar::valid(null), 'Unknown or malformed ids are rejected');
check(Avatar::render('nope') === Avatar::render('terminal'), 'An unknown id renders the terminal');
check(Avatar::id('arthur', 'arthur') === 'arthur' && Avatar::id('arthur', 'bram') === 'arthur.bram' && Avatar::id('noto-1f34e', 'noto-1f34e') === 'noto-1f34e.noto-1f34e', 'The shortest id names a character exactly');

// Mixing: the face moves to the body's face anchor and adapts its ink to what is behind it.
$mixed = Avatar::render('noto-1f34e.terminal');
check(strpos($mixed, 'transform="translate(') !== false && strpos($mixed, '#c3f3a5') === false, 'A dark-screen face changes its ink on a light body');
check(strpos(Avatar::render('terminal'), '#c3f3a5') !== false, 'A character keeps its own colours on its own body');
check(Avatar::render('arthur.bram') !== Avatar::render('bram.arthur'), 'Body and face are independent choices');

// The library: plain shapes only, ids namespaced so many avatars can share one page, and every body credited.
$bodies = Avatar::bodies();
check(count($bodies) > 100 && $bodies[0]['own'] === true, 'The library holds the shipped bodies first, then the Noto bodies');
$ids = [];
foreach ($bodies as $body) {
    $svg = Avatar::render($body['id'] . '.imani');
    check(!preg_match('/<(script|style|image|foreignObject|filter|a)\b|on[a-z]+\s*=|javascript:|href="(?!#)|xlink:/i', $svg), 'No active content in ' . $body['id']);
    foreach (['id', 'href'] as $attribute) {
        preg_match_all('/\b' . $attribute . '="#?([^"]+)"/', $svg, $m);
        if ($attribute === 'id') foreach ($m[1] as $one) { check(!isset($ids[$one]) || $ids[$one] === $body['id'], "Id $one is unique to {$body['id']}"); $ids[$one] = $body['id']; }
    }
}
$credits = Avatar::credits();
check(in_array(['source' => 'Noto Emoji', 'license' => 'Apache-2.0'], $credits, true) && is_file(dirname(__DIR__) . '/resources/avatars/LICENSE-noto-emoji'), 'Library bodies are credited and the license ships with them');
echo "ALL PASS\n";
