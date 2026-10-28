<?php
namespace Agent;

class Message
{
    /**
     * Canonical message boundary: text, message payload, JSON payload, or stored row.
     * A second argument supports (role, content) and (content, role), with no guessing
     * when both arguments could be roles. All unrecognised metadata is retained.
     */
    public static function normalize($input, $other = null)
    {
        $raw = self::input($input, $other, func_num_args() > 1);
        if (array_key_exists('attachments', $raw)) $raw['attachments'] = Attachments::normalize($raw['attachments']);
        return $raw;
    }

    /** A single message or a list/JSON list of messages, always returned as a list. */
    public static function many($input)
    {
        if ($input instanceof \stdClass) $input = (array)$input;
        if (is_string($input)) {
            $decoded = json_decode($input, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && substr(ltrim($input), 0, 1) === '[') $input = $decoded;
        }
        if ($input === []) return [];
        if (!is_array($input) || self::isPayload($input) || self::isRow($input) || self::isParts($input)) return [self::normalize($input)];
        if (array_keys($input) !== range(0, count($input) - 1)) throw new \InvalidArgumentException('Messages must be a message payload or a sequential list of messages');
        $result = [];
        foreach ($input as $message) $result[] = self::normalize($message);
        return $result;
    }

    /** Unwrap a database message row without losing source identity or raw metadata. */
    public static function fromRow(array $row)
    {
        if (!array_key_exists('raw', $row)) throw new \InvalidArgumentException('Message row requires raw');
        $raw = $row['raw'];
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($raw)) throw new \InvalidArgumentException('Message row raw must contain a JSON message object');
        }
        if ($raw instanceof \stdClass) $raw = (array)$raw;
        if (!is_array($raw) || !self::isPayload($raw)) throw new \InvalidArgumentException('Message row raw must contain a message payload');
        if (isset($row['role'])) {
            if (isset($raw['role']) && $raw['role'] !== $row['role']) throw new \InvalidArgumentException('Message row role conflicts with raw role');
            $raw['role'] = $row['role'];
        }
        if (isset($row['id'])) $raw['_id'] = (string)$row['id'];
        return self::input($raw, null, false);
    }

    private static function roles() { return ['system', 'assistant', 'tool', 'user', 'developer']; }
    private static function role($value) { return is_string($value) ? strtolower(trim($value)) : $value; }
    private static function isRole($value) { return is_string($value) && in_array(self::role($value), self::roles(), true); }
    private static function isPayload(array $value)
    {
        foreach (['role', 'content', 'tool_calls', 'tool_call_id', 'attachments', 'refusal'] as $key) if (array_key_exists($key, $value)) return true;
        return false;
    }
    private static function isRow(array $value) { return array_key_exists('raw', $value) && (isset($value['id']) || isset($value['thread_id'])); }
    private static function isParts(array $value)
    {
        if (!$value || array_keys($value) !== range(0, count($value) - 1)) return false;
        foreach ($value as $part) {
            if ($part instanceof \stdClass) $part = (array)$part;
            if (!is_array($part) || !isset($part['type']) || !is_string($part['type'])) return false;
        }
        return true;
    }
    /** Normalise JSON object nodes only inside known protocol fields, not metadata. */
    private static function protocolValue($value)
    {
        // Protocol strings are opaque (including serialized tool arguments and image
        // data URLs). Only object nodes are adapted; domain metadata is not traversed.
        $result = Utilities::cleanup($value, ['type' => 'json', 'max_depth' => 32, 'max_bytes' => 33554432], $issues);
        if ($issues['errors']) {
            foreach ($issues['errors'] as $error) if (strpos($error['message'], 'deeply nested') !== false) throw new \InvalidArgumentException('Message protocol fields are circular or too deeply nested');
            // Cleanup intentionally returns best-effort data. A model request is a
            // strict boundary and must never forward a partially invalid JSON tree.
            throw new \InvalidArgumentException('Message protocol fields must contain JSON-compatible values');
        }
        return $result;
    }
    private static function input($input, $other, $paired)
    {
        if ($paired) {
            if (self::isRole($input) === self::isRole($other)) throw new \InvalidArgumentException('Two message arguments require exactly one role; use a role/content payload when ambiguous');
            $input = self::isRole($input) ? ['role' => $input, 'content' => $other] : ['role' => $other, 'content' => $input];
        }
        if ($input instanceof \stdClass) $input = (array)$input;
        if (is_string($input) && substr(ltrim($input), 0, 1) === '{') {
            $decoded = json_decode($input, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && (self::isPayload($decoded) || self::isRow($decoded))) $input = $decoded;
        }
        if (is_array($input) && self::isRow($input)) return self::fromRow($input);
        if (is_array($input) && self::isParts($input)) $input = ['content' => $input];
        if ($input === []) $input = ['content' => null];
        if (is_string($input) || $input === null) $input = ['content' => $input];
        elseif (is_int($input) || is_float($input) || is_bool($input)) {
            Log::writeWarning('Converting scalar message content to text; pass explicit text to avoid this inference');
            $input = ['content' => json_encode($input)];
        }
        if (!is_array($input) || !self::isPayload($input)) throw new \InvalidArgumentException('Message requires text, a role/content payload, native content parts, or a database message row');
        if (!isset($input['role'])) $input['role'] = 'user';
        $input['role'] = self::role($input['role']);
        if (!self::isRole($input['role'])) throw new \InvalidArgumentException('Invalid message role');
        if (!array_key_exists('content', $input)) $input['content'] = null;
        foreach (['content', 'tool_calls'] as $field) if (isset($input[$field])) $input[$field] = self::protocolValue($input[$field]);
        if ($input['content'] !== null && !is_string($input['content']) && !(is_array($input['content']) && ($input['content'] === [] || self::isParts($input['content'])))) throw new \InvalidArgumentException('Message content must be text, null, or native content parts');
        if (isset($input['tool_calls']) && !is_array($input['tool_calls'])) throw new \InvalidArgumentException('Message tool_calls must be an array');
        return $input;
    }

    public static function text($raw)
    {
        $raw = self::input($raw, null, false);
        $content = isset($raw['content']) ? $raw['content'] : '';
        if (is_array($content)) {
            $parts = [];
            foreach ($content as $part) if (isset($part['text'])) $parts[] = $part['text'];
            $content = implode("\n", $parts);
        }
        foreach (['reasoning', 'reasoning_content'] as $key) if (!empty($raw[$key])) $content .= "\n" . $raw[$key];
        if (!empty($raw['tool_calls'])) $content .= "\n" . json_encode($raw['tool_calls']);
        if (!empty($raw['attachments'])) $content .= "\n" . Attachments::messageText($raw);
        return (string)$content;
    }

    public static function forModel($raw)
    {
        $raw = self::input($raw, null, false);
        return array_intersect_key($raw, array_flip(['role', 'content', 'tool_calls', 'tool_call_id', 'name', 'reasoning', 'reasoning_content', 'refusal']));
    }
}
