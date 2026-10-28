<?php
namespace Agent;

/** Bounded, transport-independent normalization. Domain classes still own lookup and authorization. */
class Utilities
{
    const MAX_DEPTH = 32;
    const MAX_NODES = 10000;
    const MAX_BYTES = 1048576;
    const MAX_TOTAL_BYTES = 67108864;
    private static $keys = ['type', 'nullable', 'coerce', 'allow_guess', 'trim', 'case', 'decode_json', 'decode_empty', 'separator', 'wrap_scalar', 'properties', 'required', 'aliases', 'additionalProperties', 'items', 'enum', 'default', 'minimum', 'maximum', 'minLength', 'maxLength', 'minItems', 'maxItems', 'pattern', 'unique', 'sort', 'max_depth', 'max_bytes'];
    private static $types = ['any', 'json', 'string', 'boolean', 'integer', 'number', 'array', 'object', 'null'];

    /**
     * Return the best available value, never an exception. Errors retain the affected input;
     * valid sibling fields are still normalized. Optional diagnostics never contain input values.
     * Patterns are complete, delimited PCRE expressions. Unknown contract keywords are errors.
     */
    public static function cleanup($input, $contract, &$issues = null)
    {
        $issues = ['warnings' => [], 'errors' => []];
        $state = ['nodes' => 0, 'contract_nodes' => 0, 'issues' => &$issues, 'exhausted' => false, 'max_bytes' => self::MAX_BYTES, 'max_nodes' => self::MAX_NODES, 'comparisons' => self::MAX_NODES, 'bytes' => 0];
        try {
            $schema = self::contract($contract, '$', $state, 0);
            if ($issues['errors']) return $input;
            if (isset($schema['max_bytes'])) $state['max_bytes'] = $schema['max_bytes'];
            return self::node($input, $schema, '$', $state, 0, isset($schema['max_depth']) ? $schema['max_depth'] : self::MAX_DEPTH);
        } catch (\Throwable $e) {
            self::issue($state, 'errors', '$', 'Cleanup could not safely process this input');
            return $input;
        }
    }

    private static function issue(array &$state, $kind, $path, $message)
    {
        // Stop diagnostic floods as well as data floods. Never interpolate the input or exception.
        if (count($state['issues'][$kind]) >= 20) return;
        $state['issues'][$kind][] = ['path' => $path, 'message' => $message];
        if ($kind === 'errors') Log::writeError('Cleanup %s: %s', $path, $message);
        else Log::writeWarning('Cleanup %s: %s', $path, $message);
    }

    private static function path($parent, $key)
    {
        $key = substr((string)$key, 0, 64);
        return $parent . '.' . preg_replace('/[^a-zA-Z0-9_-]/', '?', $key);
    }

    private static function named(array $value)
    {
        foreach ($value as $key => $unused) if (!is_string($key)) return false;
        return true;
    }

    private static function listed(array $value)
    {
        $next = 0;
        foreach ($value as $key => $unused) if ($key !== $next++) return false;
        return true;
    }

    /** Strict, bounded structural equality: PHP's native recursive comparisons can fatally recurse. */
    private static function equal($left, $right, $depth = 0, &$budget = null)
    {
        if ($budget === null) $budget = self::MAX_NODES;
        if (--$budget < 0 || $depth > self::MAX_DEPTH) return false;
        if ($left instanceof \stdClass) $left = get_object_vars($left);
        if ($right instanceof \stdClass) $right = get_object_vars($right);
        if (!is_array($left) || !is_array($right)) return $left === $right;
        if (count($left) !== count($right) || array_keys($left) !== array_keys($right)) return false;
        foreach ($left as $key => $item) if (!self::equal($item, $right[$key], $depth + 1, $budget)) return false;
        return true;
    }

    private static function contract($value, $path, array &$state, $depth)
    {
        if (++$state['contract_nodes'] > 1000 || $depth > self::MAX_DEPTH) {
            self::issue($state, 'errors', $path, 'Contract is too large or deeply nested');
            return ['type' => 'any'];
        }
        if (is_string($value)) {
            if (strlen($value) > self::MAX_BYTES) {
                self::issue($state, 'errors', $path, 'Contract exceeds the size limit');
                return ['type' => 'any'];
            }
            $text = trim($value);
            if (in_array($text, self::$types, true)) $value = ['type' => $text];
            else {
                $value = json_decode($text, false, self::MAX_DEPTH);
                if (json_last_error() !== JSON_ERROR_NONE || !$value instanceof \stdClass) {
                    self::issue($state, 'errors', $path, 'Contract must be a supported type or named object');
                    return ['type' => 'any'];
                }
            }
        }
        if ($value instanceof \stdClass) $value = get_object_vars($value);
        if (!is_array($value) || count($value) > 1000 || !self::named($value)) {
            self::issue($state, 'errors', $path, 'Contract must be a named object');
            return ['type' => 'any'];
        }
        foreach ($value as $key => $unused) if (!in_array($key, self::$keys, true)) self::issue($state, 'errors', $path, 'Contract contains an unsupported keyword');
        if (!array_key_exists('type', $value)) $value['type'] = isset($value['properties']) ? 'object' : 'any';
        $types = is_array($value['type']) ? $value['type'] : [$value['type']];
        if (!$types || count($types) > count(self::$types)) self::issue($state, 'errors', $path, 'Contract requires a supported type');
        foreach ($types as $type) if (!is_string($type) || !in_array($type, self::$types, true)) self::issue($state, 'errors', $path, 'Contract requires a supported type');
        foreach (['nullable', 'coerce', 'allow_guess', 'trim', 'decode_json', 'decode_empty', 'wrap_scalar', 'unique'] as $key) if (array_key_exists($key, $value) && !is_bool($value[$key])) self::issue($state, 'errors', $path, 'Contract flags must be booleans');
        if (array_key_exists('case', $value) && !in_array($value['case'], ['lower', 'upper'], true)) self::issue($state, 'errors', $path, 'Contract case must be lower or upper');
        if (array_key_exists('sort', $value) && !in_array($value['sort'], ['asc', 'desc'], true)) self::issue($state, 'errors', $path, 'Contract sort must be asc or desc');
        if (array_key_exists('separator', $value) && (!is_string($value['separator']) || strlen($value['separator']) !== 1 || ord($value['separator']) < 32 || ord($value['separator']) > 126)) self::issue($state, 'errors', $path, 'CSV separator must be one printable byte');
        foreach (['minimum', 'maximum'] as $key) if (array_key_exists($key, $value) && ((!is_int($value[$key]) && !is_float($value[$key])) || !is_finite($value[$key]))) self::issue($state, 'errors', $path, 'Numeric bounds must be finite numbers');
        foreach (['minLength', 'maxLength', 'minItems', 'maxItems'] as $key) if (array_key_exists($key, $value) && (!is_int($value[$key]) || $value[$key] < 0)) self::issue($state, 'errors', $path, 'Length bounds must be nonnegative integers');
        foreach ([['minimum', 'maximum'], ['minLength', 'maxLength'], ['minItems', 'maxItems']] as $pair) if (isset($value[$pair[0]], $value[$pair[1]]) && $value[$pair[0]] > $value[$pair[1]]) self::issue($state, 'errors', $path, 'Contract minimum exceeds maximum');
        if (array_key_exists('max_depth', $value) && (!is_int($value['max_depth']) || $value['max_depth'] < 1 || $value['max_depth'] > self::MAX_DEPTH)) self::issue($state, 'errors', $path, 'Depth limit must be an integer from 1 to 32');
        if (array_key_exists('max_bytes', $value) && (!is_int($value['max_bytes']) || $value['max_bytes'] < 1 || $value['max_bytes'] > 67108864)) self::issue($state, 'errors', $path, 'Byte limit must be a positive integer within the 64 MiB hard safety ceiling');
        if ($depth !== 0 && array_key_exists('max_bytes', $value)) self::issue($state, 'errors', $path, 'Byte limits must be configured at the root of the contract');
        if (array_key_exists('pattern', $value) && (!is_string($value['pattern']) || strlen($value['pattern']) > 4096 || @preg_match($value['pattern'], '') === false)) self::issue($state, 'errors', $path, 'Pattern must be a valid bounded delimited PCRE expression');
        if (array_key_exists('enum', $value) && (!is_array($value['enum']) || count($value['enum']) > 1000 || !self::listed($value['enum']))) self::issue($state, 'errors', $path, 'Enum must be a bounded list');
        if (array_key_exists('properties', $value)) {
            if ($value['properties'] instanceof \stdClass) $value['properties'] = get_object_vars($value['properties']);
            if (!is_array($value['properties']) || count($value['properties']) > 1000 || !self::named($value['properties'])) self::issue($state, 'errors', $path, 'Properties must be a bounded named object');
            else foreach ($value['properties'] as $key => $child) $value['properties'][$key] = self::contract($child, self::path($path, $key), $state, $depth + 1);
        }
        if (array_key_exists('required', $value)) {
            if (!is_array($value['required']) || count($value['required']) > 1000 || !self::listed($value['required'])) self::issue($state, 'errors', $path, 'Required must be a bounded list of field names');
            else foreach ($value['required'] as $key) if (!is_string($key)) self::issue($state, 'errors', $path, 'Required must contain field names');
        }
        if (array_key_exists('aliases', $value)) {
            if ($value['aliases'] instanceof \stdClass) $value['aliases'] = get_object_vars($value['aliases']);
            if (!is_array($value['aliases']) || !self::named($value['aliases']) || count($value['aliases']) > 1000) self::issue($state, 'errors', $path, 'Aliases must map field names to canonical field names');
            else foreach ($value['aliases'] as $alias => $key) {
                if (!is_string($key) || $key === '') self::issue($state, 'errors', $path, 'Aliases require nonempty canonical field names');
                elseif ($key !== $alias && array_key_exists($key, $value['aliases']) && $value['aliases'][$key] !== $key) self::issue($state, 'errors', $path, 'Alias chains or cycles must be resolved to a single canonical field');
            }
        }
        if (array_key_exists('items', $value)) $value['items'] = self::contract($value['items'], $path . '[]', $state, $depth + 1);
        if (array_key_exists('additionalProperties', $value) && !is_bool($value['additionalProperties']) && $value['additionalProperties'] !== 'discard') $value['additionalProperties'] = self::contract($value['additionalProperties'], $path . '.*', $state, $depth + 1);
        return $value;
    }

    private static function node($input, array $schema, $path, array &$state, $depth, $limit)
    {
        if (++$state['nodes'] > $state['max_nodes'] || $depth > $limit) {
            if (!$state['exhausted']) { self::issue($state, 'errors', $path, 'Input is recursive, too large, or too deeply nested'); $state['exhausted'] = true; }
            return $input;
        }
        if (isset($schema['max_depth'])) $limit = min($limit, $depth + $schema['max_depth']);
        if (is_string($input) && strlen($input) > $state['max_bytes']) { self::issue($state, 'errors', $path, 'Input string exceeds the size limit'); return $input; }
        if (is_string($input)) {
            $state['bytes'] += strlen($input);
            if ($state['bytes'] > self::MAX_TOTAL_BYTES) {
                if (!$state['exhausted']) self::issue($state, 'errors', $path, 'Input exceeds the total byte processing budget');
                $state['exhausted'] = true;
                return $input;
            }
        }
        if (is_array($input) && count($input) > $state['max_nodes']) { self::issue($state, 'errors', $path, 'Input container exceeds the item limit'); return $input; }
        $value = $input;
        $types = is_array($schema['type']) ? $schema['type'] : [$schema['type']];
        if (!empty($schema['nullable']) && !in_array('null', $types, true)) $types[] = 'null';
        if (is_string($value) && !empty($schema['trim'])) $value = trim($value);
        $decode = !empty($schema['decode_json']);
        if (is_string($value) && !array_key_exists('decode_json', $schema) && (!isset($schema['coerce']) || $schema['coerce'])) {
            $native = false;
            foreach ($types as $type) {
                if (self::native($value, $type)) { $native = true; break; }
            }
            $decode = !$native && (in_array('object', $types, true) || in_array('array', $types, true))
                && in_array(substr(ltrim($value), 0, 1), ['{', '['], true);
        }
        if (is_string($value) && $decode) {
            $tmp = json_decode($value, true, $limit + 1);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($tmp) || (!$tmp && empty($schema['decode_empty']))) {
                unset($tmp);
                self::issue($state, 'errors', $path, 'Input is not a usable bounded JSON container'); return $value;
            }
            $kind = substr(ltrim($value), 0, 1) === '[' ? 'array' : 'object';
            if (!in_array($kind, $types, true) && !in_array('any', $types, true) && !in_array('json', $types, true)) {
                unset($tmp);
                self::issue($state, 'errors', $path, 'JSON container does not match the required type'); return $value;
            }
            $value = $tmp;
            unset($tmp);
        }
        // Favor a native representation before considering any conversion in a union.
        $selected = null;
        foreach ($types as $type) {
            if (self::native($value, $type)) { $selected = $type; break; }
        }
        if ($selected === null && (!isset($schema['coerce']) || $schema['coerce'])) {
            foreach ($types as $type) {
                $converted = self::convert($value, $type, $schema, $ok, $guess);
                if ($ok) {
                    $value = $converted; $selected = $type;
                    if ($guess) self::issue($state, 'warnings', $path, 'A permitted lossy conversion was used');
                    break;
                }
            }
        }
        if ($selected === null) { self::issue($state, 'errors', $path, 'Input does not match the required type'); return $value; }
        if (in_array($selected, ['object', 'array', 'any', 'json'], true)) $value = self::container($value, $selected, $schema, $path, $state, $depth, $limit);
        if ($state['exhausted']) return $value;
        if (is_string($value)) {
            if (isset($schema['case'])) $value = $schema['case'] === 'lower' ? strtolower($value) : strtoupper($value);
            // Lengths are Unicode code points when valid UTF-8, otherwise bytes. No hidden encoding conversion.
            $length = function_exists('mb_strlen') && preg_match('//u', $value) === 1 ? mb_strlen($value, 'UTF-8') : strlen($value);
            if (isset($schema['minLength']) && $length < $schema['minLength']) self::issue($state, 'errors', $path, 'String is shorter than the permitted minimum');
            if (isset($schema['maxLength']) && $length > $schema['maxLength']) self::issue($state, 'errors', $path, 'String exceeds the permitted maximum');
            if (isset($schema['pattern']) && @preg_match($schema['pattern'], $value) !== 1) self::issue($state, 'errors', $path, 'String does not match the required pattern');
        }
        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) self::issue($state, 'errors', $path, 'Number is below the permitted minimum');
            if (isset($schema['maximum']) && $value > $schema['maximum']) self::issue($state, 'errors', $path, 'Number exceeds the permitted maximum');
        }
        if (array_key_exists('enum', $schema)) {
            $match = false;
            foreach ($schema['enum'] as $member) { if (self::equal($value, $member, 0, $state['comparisons'])) { $match = true; break; } if ($state['comparisons'] < 0) break; }
            if (!$match) self::issue($state, 'errors', $path, $state['comparisons'] < 0 ? 'Value comparison exceeds the safety limit' : 'Value is not an allowed enum member');
        }
        return $value;
    }

    private static function native($value, $type)
    {
        if ($type === 'any' || $type === 'json') return true;
        if ($type === 'null') return $value === null;
        if ($type === 'string') return is_string($value);
        if ($type === 'boolean') return is_bool($value);
        if ($type === 'integer') return is_int($value);
        if ($type === 'number') return is_int($value) || (is_float($value) && is_finite($value));
        if ($type === 'object') return ($value instanceof \stdClass && self::named(get_object_vars($value))) || (is_array($value) && self::named($value));
        return $type === 'array' && is_array($value) && self::listed($value);
    }

    private static function convert($value, $type, array $schema, &$ok, &$guess)
    {
        $ok = false; $guess = false;
        if ($type === 'string') {
            if (is_int($value)) { $ok = true; return (string)$value; }
            if (!empty($schema['allow_guess']) && (is_bool($value) || (is_float($value) && is_finite($value)))) {
                $ok = true; $guess = true; return is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
            }
        } elseif ($type === 'boolean') {
            if (is_int($value) && ($value === 0 || $value === 1)) { $ok = true; return $value === 1; }
            if (is_string($value) && trim($value) !== '') {
                $result = filter_var(trim($value), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($result !== null) { $ok = true; return $result; }
            }
        } elseif ($type === 'integer') {
            if (is_string($value)) $value = trim($value);
            if (is_float($value) && is_finite($value) && floor($value) === $value && $value >= PHP_INT_MIN && $value < (float)PHP_INT_MAX) { $ok = true; return (int)$value; }
            if (is_string($value) && preg_match('/^[+-]?[0-9]+$/D', $value)) {
                $negative = $value[0] === '-'; $digits = ltrim(ltrim($value, '+-'), '0'); $digits = $digits === '' ? '0' : $digits;
                $maximum = $negative ? substr((string)PHP_INT_MIN, 1) : (string)PHP_INT_MAX;
                if (strlen($digits) < strlen($maximum) || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) <= 0)) { $ok = true; return (int)($negative ? '-' . $digits : $digits); }
            }
        } elseif ($type === 'number') {
            if (is_string($value)) $value = trim($value);
            if (is_string($value) && preg_match('/^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?$/D', $value)) {
                $number = $value + 0;
                if (is_finite($number)) { $ok = true; return $number; }
            }
        } elseif ($type === 'array') {
            if (is_string($value) && isset($schema['separator'])) { $ok = true; return str_getcsv($value, $schema['separator'], '"', '\\'); }
            if (!empty($schema['wrap_scalar']) && (is_scalar($value) || $value === null)) { $ok = true; $guess = true; return [$value]; }
        }
        return $value;
    }

    private static function container($value, $type, array $schema, $path, array &$state, $depth, $limit)
    {
        if ($type === 'json') {
            if ((is_float($value) && !is_finite($value)) || is_resource($value) || (is_object($value) && !$value instanceof \stdClass) || (is_string($value) && preg_match('//u', $value) !== 1)) {
                self::issue($state, 'errors', $path, 'Input contains a value that cannot be represented as JSON'); return $value;
            }
        }
        if ($value instanceof \stdClass) $value = get_object_vars($value);
        if (!is_array($value)) return $value;
        if (count($value) > $state['max_nodes']) { self::issue($state, 'errors', $path, 'Input container exceeds the item limit'); return $value; }
        if ($type === 'object') {
            if (isset($schema['aliases'])) {
                foreach ($schema['aliases'] as $alias => $canonical) {
                    if (!array_key_exists($alias, $value) || $alias === $canonical) continue;
                    if (array_key_exists($canonical, $value) && !self::equal($value[$canonical], $value[$alias], 0, $state['comparisons'])) { self::issue($state, 'errors', self::path($path, $canonical), $state['comparisons'] < 0 ? 'Value comparison exceeds the safety limit' : 'Alias conflicts with the canonical field'); continue; }
                    $value[$canonical] = $value[$alias]; unset($value[$alias]);
                }
            }
            $properties = isset($schema['properties']) ? $schema['properties'] : [];
            foreach ($properties as $key => $child) {
                if (!array_key_exists($key, $value) && array_key_exists('default', $child)) $value[$key] = $child['default'];
                if (array_key_exists($key, $value)) $value[$key] = self::node($value[$key], $child, self::path($path, $key), $state, $depth + 1, $limit);
            }
            foreach ($value as $key => $item) {
                if (array_key_exists($key, $properties)) continue;
                $additional = array_key_exists('additionalProperties', $schema) ? $schema['additionalProperties'] : true;
                if ($additional === 'discard') { unset($value[$key]); continue; }
                if ($additional === false) self::issue($state, 'errors', $path, 'Object contains an unexpected field');
                else $value[$key] = self::node($item, is_array($additional) ? $additional : ['type' => 'any'], $path . '.*', $state, $depth + 1, $limit);
            }
            if (isset($schema['required'])) foreach ($schema['required'] as $key) if (!array_key_exists($key, $value)) self::issue($state, 'errors', self::path($path, $key), 'Required field is missing');
        } else {
            $child = isset($schema['items']) ? $schema['items'] : ['type' => $type === 'json' ? 'json' : 'any'];
            foreach ($value as $key => $item) {
                if ($state['exhausted']) break;
                if ($type === 'json' && is_string($key) && (strlen($key) > $state['max_bytes'] || preg_match('//u', $key) !== 1)) self::issue($state, 'errors', $path, 'Object has an invalid JSON field name');
                $value[$key] = self::node($item, $child, is_int($key) ? $path . '[' . $key . ']' : $path . '.*', $state, $depth + 1, $limit);
            }
        }
        if ($type === 'array') {
            if ($state['exhausted']) return $value;
            if (!empty($schema['unique'])) {
                // Equality is strict and bounded; no serialization or magic methods on arbitrary values.
                if (count($value) > 1000) self::issue($state, 'errors', $path, 'Unique normalization exceeds the comparison limit');
                else {
                    $unique = [];
                    foreach ($value as $item) {
                        $found = false;
                        foreach ($unique as $previous) { if (self::equal($item, $previous, 0, $state['comparisons'])) { $found = true; break; } if ($state['comparisons'] < 0) break; }
                        if (!$found) $unique[] = $item;
                        if ($state['comparisons'] < 0) break;
                    }
                    if ($state['comparisons'] < 0) self::issue($state, 'errors', $path, 'Unique normalization exceeds the comparison limit');
                    else $value = $unique;
                }
            }
            if (isset($schema['sort'])) {
                $safe = true; $kind = null;
                foreach ($value as $item) {
                    $next = is_string($item) ? 'string' : ((is_int($item) || (is_float($item) && is_finite($item))) ? 'number' : null);
                    if ($next === null || ($kind !== null && $kind !== $next)) { $safe = false; break; } $kind = $next;
                }
                if (!$safe) self::issue($state, 'errors', $path, 'Sorting requires homogeneous strings or finite numbers');
                else {
                    $direction = $schema['sort'] === 'asc' ? 1 : -1;
                    usort($value, function ($left, $right) use ($kind, $direction) {
                        return $direction * ($kind === 'string' ? strcmp($left, $right) : ($left <=> $right));
                    });
                }
            }
            if (isset($schema['minItems']) && count($value) < $schema['minItems']) self::issue($state, 'errors', $path, 'Array has fewer items than permitted');
            if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) self::issue($state, 'errors', $path, 'Array has more items than permitted');
        }
        return $value;
    }
}
