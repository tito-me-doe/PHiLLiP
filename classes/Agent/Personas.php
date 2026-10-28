<?php
namespace Agent;

class Personas
{
    public static function listFor($userId)
    {
        return array_map([self::class, 'row'], array_values(array_filter(Database::connection()->query('SELECT * FROM personas ORDER BY is_default DESC, name')->fetchAll(), function ($row) use ($userId) { return Access::visible($userId, $row); })));
    }

    public static function get($id)
    {
        if ($id instanceof \stdClass) $id = (array)$id;
        if (is_array($id)) $id = isset($id['id']) ? $id['id'] : null;
        if (is_string($id)) $id = trim($id);
        // Names are not unique; resolving them would risk selecting someone else's private persona.
        if ((!is_int($id) && !is_string($id)) || !preg_match('/^[1-9][0-9]*$/D', (string)$id)) throw new \InvalidArgumentException('Persona reference requires a positive integer id');
        $q = Database::connection()->prepare('SELECT * FROM personas WHERE id = ?'); $q->execute([$id]);
        $row = $q->fetch(); if (!$row) throw new \RuntimeException('Persona not found'); return self::row($row);
    }

    private static function row(array $row)
    {
        $row['tools'] = ToolRegistry::names(isset($row['tools']) ? $row['tools'] : []);
        $row['contract'] = !empty($row['contract']) ? Config::object($row['contract'], 'Persona contract') : null;
        return $row;
    }

    /** Resolve a saved reference or trusted inline persona. Names must be unique within the audience. */
    public static function resolve($reference, $audience = null)
    {
        if ($reference instanceof \stdClass) $reference = (array)$reference;
        if (is_string($reference) && substr(ltrim($reference), 0, 1) === '{') $reference = Config::object($reference, 'Persona');
        if (is_array($reference) && !isset($reference['id']) && isset($reference['prompt'])) {
            if ($audience !== null) throw new \InvalidArgumentException('Select a saved persona by ID or name');
            return self::normalize($reference);
        }
        if (is_array($reference) && isset($reference['name']) && !isset($reference['id'])) $reference = $reference['name'];
        if (is_array($reference) || is_int($reference) || (is_string($reference) && ctype_digit(trim($reference)))) $rows = [self::get($reference)];
        else {
            if (!is_string($reference) || trim($reference) === '') throw new \InvalidArgumentException('Persona requires a name, ID or definition');
            $q = Database::connection()->prepare('SELECT * FROM personas WHERE name = ? ORDER BY id');
            $q->execute([$reference]); $rows = $q->fetchAll();
            if (!$rows && trim($reference) !== $reference) { $q->execute([trim($reference)]); $rows = $q->fetchAll(); }
            $rows = array_map([self::class, 'row'], $rows);
        }
        if ($audience !== null) $rows = array_values(array_filter($rows, function ($row) use ($audience) {
            return ($row['visibility'] === 'private' && (string)$row['owner_id'] === (string)$audience) || $row['visibility'] === 'organization' || Access::visible($audience, $row);
        }));
        if (!$rows) throw new \RuntimeException('Persona not found or access denied');
        if (count($rows) !== 1) throw new \InvalidArgumentException('Ambiguous persona name; select its ID');
        return $rows[0];
    }

    /** Canonical definition: no prompt decomposition or implicit model requirements. */
    public static function normalize($data)
    {
        $data = Config::object($data, 'Persona');
        if (!isset($data['prompt']) && isset($data['system_prompt'])) $data['prompt'] = $data['system_prompt'];
        if (!isset($data['name'], $data['prompt']) || !is_string($data['name']) || !is_string($data['prompt']) || trim($data['name']) === '' || trim($data['prompt']) === '') throw new \InvalidArgumentException('Persona name and prompt are required');
        $data['name'] = trim($data['name']);
        if (preg_match('//u', $data['name']) !== 1 || preg_match('//u', $data['prompt']) !== 1 || strpos($data['prompt'], "\0") !== false) throw new \InvalidArgumentException('Persona name and prompt must be valid UTF-8 text without NUL bytes');
        if (mb_strlen($data['name']) > 160 || preg_match('/[\x00-\x1f\x7f]/', $data['name'])) throw new \InvalidArgumentException('Persona name must be at most 160 characters without control bytes');
        $data['tools'] = ToolRegistry::names(isset($data['tools']) ? $data['tools'] : []);
        $data['contract'] = isset($data['contract']) && !(is_string($data['contract']) && trim($data['contract']) === '') ? Schema::input(Config::object($data['contract'], 'Persona contract')) : null;
        return $data;
    }

    /** Copy editable defaults into a request/thread; explicitly supplied keys win, including []/null. */
    public static function settings($persona, array $overrides = [])
    {
        $persona = is_array($persona) && isset($persona['prompt']) ? self::normalize($persona) : self::resolve($persona);
        return array_replace(['assistant_name' => $persona['name'], 'system_prompt' => 'Your name is ' . $persona['name'] . ".\n\n" . $persona['prompt'],
            'tools' => $persona['tools'], 'contract' => $persona['contract'],
            'persona_defaults' => ['tools' => $persona['tools'], 'contract' => $persona['contract']]], $overrides);
    }

    public static function save($userId, $data, $id = null)
    {
        Access::authenticated($userId);
        $original = $id !== null ? self::get($id) : null;
        if ($original) Access::personaEdit($userId, $original);
        $data = self::definition($data, $original);
        $visibility = isset($data['visibility']) ? $data['visibility'] : 'private';
        $team = $visibility === 'team' && isset($data['team_id']) ? $data['team_id'] : null;
        if (!in_array($visibility, ['private', 'team', 'organization'], true)) throw new \InvalidArgumentException('Invalid persona visibility');
        if ($original && $visibility === 'private' && $original['owner_id'] !== (string)$userId) throw new \RuntimeException('Only the owner can make a persona private');
        if ($visibility === 'team' && !Access::inTeam($userId, $team) && !Identity::user($userId)['is_admin']) throw new \RuntimeException('You must belong to the selected team');
        return self::persist($userId, $data, $original);
    }

    /** CLI identities without an ERP account own private personas, never shared resources. */
    public static function listLocal(array $identity)
    {
        if ($identity['user']) return self::listFor($identity['user']['id']);
        $q = Database::connection()->prepare("SELECT * FROM personas WHERE visibility = 'organization' OR (visibility = 'private' AND owner_id = ?) ORDER BY is_default DESC, name");
        $q->execute([$identity['owner']]);
        return array_map([self::class, 'row'], $q->fetchAll());
    }

    public static function saveLocal(array $identity, $data, $id = null)
    {
        self::localWriter($identity);
        if ($identity['user']) return self::save($identity['user']['id'], $data, $id);
        $original = $id !== null ? self::resolve($id, $identity['owner']) : null;
        if ($original && ($original['visibility'] !== 'private' || (string)$original['owner_id'] !== (string)$identity['owner'])) throw new \RuntimeException('Local CLI identities may only edit their own private personas');
        $data = self::definition($data, $original);
        if (isset($data['visibility']) && $data['visibility'] !== 'private') throw new \RuntimeException('Local CLI identities may only create private personas');
        $data['visibility'] = 'private';
        return self::persist($identity['owner'], $data, $original);
    }

    public static function deleteLocal(array $identity, $reference)
    {
        self::localWriter($identity);
        $persona = self::resolve($reference, $identity['owner']);
        if ($identity['user']) self::delete($identity['user']['id'], $persona['id']);
        else {
            if ($persona['visibility'] !== 'private' || (string)$persona['owner_id'] !== (string)$identity['owner']) throw new \RuntimeException('Local CLI identities may only delete their own private personas');
            self::remove($persona['id']);
        }
        return $persona['id'];
    }

    private static function localWriter(array $identity)
    {
        if (!isset($identity['unix']['uid']) || !empty($identity['unix']['root']) || (int)$identity['unix']['uid'] === 0) throw new \RuntimeException('Root or unidentified processes cannot modify personas; run as an unprivileged Unix user');
        if (!$identity['user'] && (!isset($identity['owner']) || !preg_match('/^cli:[a-f0-9]{12}:[0-9]+$/D', $identity['owner']))) throw new \RuntimeException('A valid local CLI identity is required');
    }

    private static function definition($data, $original)
    {
        $data = Config::object($data, 'Persona');
        if (!array_key_exists('prompt', $data) && array_key_exists('system_prompt', $data)) $data['prompt'] = $data['system_prompt'];
        return self::normalize(array_replace($original ?: [], $data));
    }

    private static function persist($owner, array $data, $original)
    {
        $visibility = isset($data['visibility']) ? $data['visibility'] : 'private';
        $team = $visibility === 'team' && isset($data['team_id']) ? $data['team_id'] : null;
        if (!$original) {
            $q = Database::connection()->prepare('INSERT INTO personas (owner_id, name, prompt, visibility, team_id, tools, contract) VALUES (?, ?, ?, ?, ?, ?::jsonb, ?::jsonb) RETURNING id');
            $q->execute([$owner, $data['name'], $data['prompt'], $visibility, $team, json_encode($data['tools']), $data['contract'] === null ? null : json_encode((object)$data['contract'])]); $id = $q->fetchColumn();
        } else {
            $id = $original['id'];
            Database::connection()->prepare('UPDATE personas SET name = ?, prompt = ?, visibility = ?, team_id = ?, tools = ?::jsonb, contract = ?::jsonb WHERE id = ?')->execute([$data['name'], $data['prompt'], $visibility, $team, json_encode($data['tools']), $data['contract'] === null ? null : json_encode((object)$data['contract']), $id]);
        }
        return self::get($id);
    }

    public static function delete($userId, $id)
    {
        $persona = self::get($id);
        Access::personaEdit($userId, $persona);
        self::remove($persona['id']);
    }

    private static function remove($id)
    {
        Database::connection()->prepare('DELETE FROM personas WHERE id = ?')->execute([$id]);
    }
}
