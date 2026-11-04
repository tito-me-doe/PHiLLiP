<?php
namespace Agent;

/**
 * Persona character sheets: load the shipped ones (resources/personas/*.json), validate, build a sheet from a form,
 * compose the system prompt, and install the shipped cast as organization defaults.
 * A persona is a character a human talks to, never a job title. The rules in SHARED_* apply to every persona and
 * cannot be overridden by a sheet; the sheet supplies only who the character is and how they sound.
 */
class PersonaSheet
{
    const DIRECTORY = '/resources/personas';
    const MATURITY = ['everyone', 'adult'];
    /** Words that make a shipped name a role, not a person. User-created personas may be named anything. */
    const ROLE_WORDS = ['developer', 'engineer', 'researcher', 'assistant', 'reviewer', 'writer', 'tester', 'coordinator', 'manager', 'architect', 'analyst', 'bot'];

    /** What the companion says: resting and status lines, then one line per expression. Shipped characters write all of them. */
    const REACTIONS = ['resting', 'thinking', 'talking', 'happy', 'sad', 'angry', 'surprised', 'love', 'wink', 'eyeroll', 'cool', 'party', 'sleepy', 'confused', 'bsod'];

    const SHARED_BASE = <<<'TXT'
You are an AI character that people choose to talk to. Stay in character: the sheet below defines your name, voice and manner.
If anyone sincerely asks whether you are an AI, say yes plainly. Your personality never overrides safety: if a person may be in danger or crisis, set the performance aside, be direct and kind, and point them to real help. Respect any limit a person states, immediately.
TXT;
    const SHARED_WITH_TOOLS = <<<'TXT'
You can look things up and check facts with the tools you have been given. Use them whenever an answer depends on current or checkable information, and say in your own voice what you are doing. Your voice changes how you say things, never what is true: keep every fact, number, name, date and source exactly as a tool reported it, and never invent a result you were not given. If you do not know, say so in character.
TXT;
    const SHARED_WITHOUT_TOOLS = <<<'TXT'
You have no tools in this conversation, so you cannot look things up. When an answer needs current or checkable information, say so in character instead of guessing, and never invent facts, numbers or sources.
TXT;

    /** @return array<string, array> every shipped sheet keyed by slug, PHiLLiP first. */
    public static function all()
    {
        $sheets = [];
        foreach (glob(Runtime::root() . self::DIRECTORY . '/*.json') as $file) {
            $sheet = json_decode((string)file_get_contents($file), true);
            if (!is_array($sheet) || empty($sheet['slug'])) throw new \RuntimeException('Invalid persona sheet: ' . basename($file));
            $sheets[$sheet['slug']] = $sheet;
        }
        uksort($sheets, function ($a, $b) { return $a === 'phillip' ? -1 : ($b === 'phillip' ? 1 : strcmp($a, $b)); });
        return $sheets;
    }

    public static function get($slug)
    {
        $all = self::all();
        if (!isset($all[$slug])) throw new \InvalidArgumentException("Unknown persona: $slug");
        return $all[$slug];
    }

    /** The shipped characters' avatar ids; any body and face from Agent\Avatar may also be chosen. @return list<string> */
    public static function avatars()
    {
        return Avatar::presets();
    }

    /** @return list<string> problems with a SHIPPED sheet; an empty list means it meets the shipping standard. */
    public static function validate(array $s)
    {
        $problems = [];
        $text = function ($value) { return is_string($value) && trim($value) !== ''; };
        $list = function ($value, $min) use ($text) { return is_array($value) && count($value) >= $min && count(array_filter($value, $text)) === count($value); };
        foreach (['slug', 'name', 'pronouns', 'tagline', 'archetype', 'for', 'bio', 'approach'] as $key) if (!$text($s[$key] ?? null)) $problems[] = "$key is required";
        if ($text($s['slug'] ?? null) && !preg_match('/^[a-z][a-z0-9-]*$/D', $s['slug'])) $problems[] = 'slug must be lowercase letters, digits and dashes';
        if ($text($s['pronouns'] ?? null) && strpos($s['pronouns'], '/') === false) $problems[] = 'pronouns must look like they/them';
        if (!in_array($s['maturity'] ?? null, self::MATURITY, true)) $problems[] = 'maturity must be one of: ' . implode(', ', self::MATURITY);
        if ($text($s['name'] ?? null)) foreach (self::ROLE_WORDS as $word) if (stripos($s['name'], $word) !== false) $problems[] = "name looks like a role ('$word'): personas are people, roles are experts";
        $voice = isset($s['voice']) && is_array($s['voice']) ? $s['voice'] : [];
        foreach (['summary', 'diction', 'rhythm', 'humor'] as $key) if (!$text($voice[$key] ?? null)) $problems[] = "voice.$key is required";
        if (!$list($voice['signature'] ?? null, 3)) $problems[] = 'voice.signature needs at least 3 phrases';
        if (!$list($voice['avoid'] ?? null, 2)) $problems[] = 'voice.avoid needs at least 2 entries';
        if (!$list($s['values'] ?? null, 3)) $problems[] = 'values needs at least 3 entries';
        if (!$list($s['boundaries'] ?? null, 2)) $problems[] = 'boundaries needs at least 2 entries';
        else {
            $joined = implode(' ', $s['boundaries']);
            if (stripos($joined, 'AI') === false) $problems[] = 'boundaries must say the character admits being an AI when sincerely asked';
            if (($s['maturity'] ?? '') === 'adult' && stripos($joined, 'under 18') === false) $problems[] = 'adult personas must define behavior for users who are under 18';
        }
        if (!$list($s['greetings'] ?? null, 2)) $problems[] = 'greetings needs at least 2 entries';
        if (!$list($s['signoffs'] ?? null, 2)) $problems[] = 'signoffs needs at least 2 entries';
        $examples = $s['examples'] ?? null;
        if (!is_array($examples) || count($examples) < 3) $problems[] = 'examples needs at least 3 exchanges (models imitate examples, so they define the voice)';
        else foreach ($examples as $i => $example) if (!$text($example['user'] ?? null) || !$text($example['reply'] ?? null)) $problems[] = "examples[$i] needs user and reply";
        if (!is_array($s['tools'] ?? null)) $problems[] = 'tools must be a list (it may be empty)';
        else { try { ToolRegistry::names($s['tools']); } catch (\Throwable $e) { $problems[] = 'tools are not valid tool names: ' . $e->getMessage(); } }
        $reactions = isset($s['reactions']) && is_array($s['reactions']) ? $s['reactions'] : [];
        foreach (self::REACTIONS as $key) if (!$text($reactions[$key] ?? null) || mb_strlen($reactions[$key]) > 60) $problems[] = "reactions.$key is required (one short line, at most 60 characters)";
        $avatar = isset($s['avatar']) && is_array($s['avatar']) ? $s['avatar'] : [];
        if (!$text($avatar['object'] ?? null)) $problems[] = 'avatar.object is required (what the character looks like)';
        if (!$text($avatar['file'] ?? null) || !preg_match('/^[a-z][a-z0-9-]*(\.[a-z][a-z0-9-]*)?\.svg$/D', $avatar['file'])) $problems[] = 'avatar.file must be an avatar id with .svg (a shipped character, or body.face)';
        elseif (!Avatar::valid(substr($avatar['file'], 0, -4))) $problems[] = 'avatar does not exist: ' . $avatar['file'];
        return $problems;
    }

    /**
     * Build a sheet from the persona form (flat fields). With a $base sheet only the submitted fields change, so editing a
     * shipped character never drops the richer fields the form does not show. Only a name, a tagline and a description are
     * required; everything else is optional and simply left out of the prompt. @throws \InvalidArgumentException
     */
    public static function fromForm(array $f, array $base = null)
    {
        $has = function ($key) use ($f) { return array_key_exists($key, $f); };
        $line = function ($key, $max = 400) use ($f) {
            $v = isset($f[$key]) && is_string($f[$key]) ? trim(preg_replace('/\s+/u', ' ', $f[$key])) : '';
            if (mb_strlen($v) > $max) throw new \InvalidArgumentException("$key is too long (at most $max characters)");
            return $v;
        };
        $block = function ($key, $max = 2000) use ($f) {
            $v = isset($f[$key]) && is_string($f[$key]) ? trim(str_replace("\r", '', $f[$key])) : '';
            if (mb_strlen($v) > $max) throw new \InvalidArgumentException("$key is too long (at most $max characters)");
            return $v;
        };
        $lines = function ($key) use ($block) { return array_values(array_filter(array_map('trim', explode("\n", $block($key, 1500))), 'strlen')); };
        $sheet = $base ?: ['voice' => [], 'examples' => [], 'avatar' => ['file' => 'terminal.svg'], 'maturity' => 'everyone'];
        if (!isset($sheet['voice']) || !is_array($sheet['voice'])) $sheet['voice'] = [];
        foreach (['name' => 80, 'pronouns' => 40, 'tagline' => 140] as $key => $max) if ($has($key)) $sheet[$key] = $line($key, $max);
        if ($has('bio')) $sheet['bio'] = $block('bio', 2000);
        if ($has('approach')) $sheet['approach'] = $block('approach', 1200);
        if ($has('maturity')) $sheet['maturity'] = in_array($f['maturity'], ['adult', '1', 'on', 'true', true, 1], true) ? 'adult' : 'everyone';
        if ($has('voice_summary')) $sheet['voice']['summary'] = $line('voice_summary', 400);
        if ($has('humor')) $sheet['voice']['humor'] = $line('humor', 400);
        if ($has('signature')) $sheet['voice']['signature'] = $lines('signature');
        if ($has('avoid')) $sheet['voice']['avoid'] = $lines('avoid');
        if ($has('boundaries')) $sheet['boundaries'] = $lines('boundaries');
        if ($has('avatar') && is_string($f['avatar']) && $f['avatar'] !== '') $sheet['avatar']['file'] = $f['avatar'] . '.svg';
        if ($has('tools')) $sheet['tools'] = ToolRegistry::names($f['tools']);
        if ($has('example1_user') || $has('example1_reply') || $has('example2_user') || $has('example3_user')) {
            $sheet['examples'] = [];
            foreach ([1, 2, 3] as $n) {
                $user = $line("example{$n}_user", 400); $reply = $block("example{$n}_reply", 1200);
                if ($user !== '' && $reply !== '') $sheet['examples'][] = ['user' => $user, 'reply' => $reply];
                elseif ($user !== '' || $reply !== '') throw new \InvalidArgumentException("Example $n needs both what the person says and how the character replies");
            }
        }
        $shipped = !empty($sheet['slug']) && isset($sheet['for']);
        $problems = $shipped ? self::validate($sheet) : self::validateUser($sheet);
        if ($problems) throw new \InvalidArgumentException(implode('; ', $problems));
        return $sheet;
    }

    /** Lenient standard for personas people create themselves. @return list<string> */
    public static function validateUser(array $s)
    {
        $problems = [];
        foreach (['name' => 'A name', 'tagline' => 'A one-line tagline', 'bio' => 'A description of who they are'] as $key => $label) if (!is_string($s[$key] ?? null) || trim($s[$key]) === '') $problems[] = "$label is required";
        if (!in_array($s['maturity'] ?? 'everyone', self::MATURITY, true)) $problems[] = 'maturity must be one of: ' . implode(', ', self::MATURITY);
        $file = isset($s['avatar']['file']) ? (string)$s['avatar']['file'] : '';
        if (!preg_match('/^[a-z][a-z0-9-]*(\.[a-z][a-z0-9-]*)?\.svg$/D', $file) || !Avatar::valid(substr($file, 0, -4))) $problems[] = 'Choose one of the available avatars';
        return $problems;
    }

    /** The system prompt for a persona: shared rules first, then the character. Optional parts that are empty are left out. */
    public static function prompt(array $s)
    {
        $problems = !empty($s['slug']) && isset($s['for']) ? self::validate($s) : self::validateUser($s);
        // Reactions are what the companion says, not part of the prompt: a sheet without them still composes.
        $problems = array_values(array_filter($problems, function ($problem) { return strpos($problem, 'reactions.') !== 0; }));
        if ($problems) throw new \InvalidArgumentException('Persona sheet is not valid: ' . implode('; ', $problems));
        $v = isset($s['voice']) && is_array($s['voice']) ? $s['voice'] : [];
        $tools = ToolRegistry::names(isset($s['tools']) ? $s['tools'] : []);
        $lines = [self::SHARED_BASE, $tools ? self::SHARED_WITH_TOOLS : self::SHARED_WITHOUT_TOOLS, '', '## Who you are',
            'You are ' . $s['name'] . (!empty($s['pronouns']) ? " ({$s['pronouns']})" : '') . ', ' . $s['tagline'], $s['bio'], '', '## How you sound'];
        foreach ([null => 'summary', 'Diction' => 'diction', 'Rhythm' => 'rhythm', 'Humor' => 'humor'] as $label => $key) if (!empty($v[$key])) $lines[] = (is_string($label) ? "$label: " : '') . $v[$key];
        if (!empty($v['signature'])) $lines[] = 'Phrases that are typically yours: ' . implode(' | ', $v['signature']);
        if (!empty($v['avoid'])) $lines[] = 'Never: ' . implode(', ', $v['avoid']);
        if (!empty($s['approach']) || !empty($s['values'])) {
            $lines[] = ''; $lines[] = '## How you approach problems';
            if (!empty($s['approach'])) $lines[] = $s['approach'];
            if (!empty($s['values'])) $lines[] = 'What you value: ' . implode('; ', $s['values']) . '.';
        }
        if (!empty($s['boundaries'])) { $lines[] = ''; $lines[] = '## Lines you hold'; foreach ($s['boundaries'] as $line) $lines[] = '- ' . $line; }
        if (!empty($s['greetings']) || !empty($s['signoffs'])) {
            $lines[] = ''; $lines[] = '## How you open and close';
            if (!empty($s['greetings'])) $lines[] = 'Openers: ' . implode(' | ', $s['greetings']);
            if (!empty($s['signoffs'])) $lines[] = 'Closers: ' . implode(' | ', $s['signoffs']);
        }
        if (!empty($s['examples'])) {
            $lines[] = ''; $lines[] = '## Examples of your voice (match the manner, not the topics)';
            foreach ($s['examples'] as $example) { $lines[] = 'Person: ' . $example['user']; $lines[] = $s['name'] . ': ' . $example['reply']; $lines[] = ''; }
        }
        return rtrim(implode("\n", $lines)) . "\n";
    }

    /**
     * Install the shipped cast as organization-visible defaults. Idempotent: an existing shipped character (identified by
     * slug) is left alone, so administrator edits survive, unless $replace is true. The legacy seed row with the same name
     * and no slug is adopted and replaced. Local operator only; never from a model run.
     * @return array{installed: int, updated: int, kept: int}
     */
    public static function install($replace = false)
    {
        if (!Runtime::isCli() || ToolContext::get('run_id') !== null || ToolContext::get('frontend')) throw new \RuntimeException('Installing the shipped personas requires a local operator.');
        $result = ['installed' => 0, 'updated' => 0, 'kept' => 0];
        State::transaction(function () use ($replace, &$result) {
            foreach (self::all() as $slug => $sheet) {
                $problems = self::validate($sheet);
                if ($problems) throw new \RuntimeException("Shipped persona '$slug' is invalid: " . implode('; ', $problems));
                $values = [$sheet['name'], self::prompt($sheet), json_encode(ToolRegistry::names($sheet['tools'])), $slug, $sheet['pronouns'], $sheet['tagline'], $sheet['archetype'],
                    $sheet['maturity'], substr($sheet['avatar']['file'], 0, -4), json_encode($sheet, JSON_UNESCAPED_UNICODE)];
                $existing = Database::row('SELECT id, sheet FROM personas WHERE owner_id IS NULL AND slug = ?', [$slug]);
                $legacy = $existing ? null : Database::row('SELECT id FROM personas WHERE owner_id IS NULL AND is_default = TRUE AND slug IS NULL AND name = ?', [$sheet['name']]);
                if (!$existing && !$legacy) {
                    Database::execute("INSERT INTO personas (name, prompt, visibility, is_default, tools, slug, pronouns, tagline, archetype, maturity, avatar, sheet) VALUES (?, ?, 'organization', TRUE, ?::jsonb, ?, ?, ?, ?, ?, ?, ?::jsonb)", $values);
                    $result['installed']++;
                } elseif ($legacy || $replace) {
                    Database::execute("UPDATE personas SET name = ?, prompt = ?, tools = ?::jsonb, slug = ?, pronouns = ?, tagline = ?, archetype = ?, maturity = ?, avatar = ?, sheet = ?::jsonb, visibility = 'organization', is_default = TRUE WHERE id = ?",
                        array_merge($values, [($existing ?: $legacy)['id']]));
                    $result['updated']++;
                } else {
                    // Administrator edits are kept, but fields added to the shipped sheet later are filled in when missing.
                    $stored = is_string($existing['sheet']) ? json_decode($existing['sheet'], true) : $existing['sheet'];
                    if (is_array($stored) && empty($stored['reactions'])) {
                        Database::execute("UPDATE personas SET sheet = jsonb_set(sheet, '{reactions}', ?::jsonb) WHERE id = ?", [json_encode($sheet['reactions'], JSON_UNESCAPED_UNICODE), $existing['id']]);
                        $result['updated']++;
                    } else $result['kept']++;
                }
            }
        });
        return $result;
    }
}
