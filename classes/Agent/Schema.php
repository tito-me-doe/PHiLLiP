<?php
namespace Agent;

class Schema
{
    public static function input($schema)
    {
        if (is_string($schema) && substr(ltrim($schema), 0, 1) !== '{') {
            if (!is_file($schema) || !is_readable($schema) || filesize($schema) > 1048576) throw new \InvalidArgumentException('Contract must be a JSON object or readable JSON file of at most 1 MiB');
            $schema = file_get_contents($schema);
        }
        $schema = Utilities::cleanup($schema, ['type' => 'object', 'decode_json' => true, 'decode_empty' => true, 'additionalProperties' => ['type' => 'json']], $issues);
        if ($issues['errors'] || !is_array($schema)) throw new \InvalidArgumentException('Contract must be a JSON object or an array with named keys, not a list');
        // Contracts supplied by users must not turn validation into file/network retrieval.
        $pending = [$schema];
        while ($pending) {
            foreach (array_pop($pending) as $key => $value) {
                if ($key === '$ref' && is_string($value) && $value !== '' && $value[0] !== '#') throw new \InvalidArgumentException('Contracts must be self-contained; only local # references are supported');
                if (is_array($value) || $value instanceof \stdClass) $pending[] = (array)$value;
            }
        }
        return $schema;
    }
    public static function normalize($schema)
    {
        $schema = self::input($schema);
        if(isset($schema['type']) || isset($schema['$ref'])) return $schema;
        foreach($schema as &$field) if(is_string($field)) {
            $aliases=['int'=>'integer','float'=>'number','bool'=>'boolean'];
            $type=isset($aliases[$field])?$aliases[$field]:$field;
            if(!in_array($type,['string','integer','number','boolean','array','object','null'],true))throw new \InvalidArgumentException('Contract shorthand needs known JSON types');
            $field=['type'=>$type];
        }
        unset($field);
        return [
            'type' => 'object', 'properties' => (object)$schema, 'required' => array_keys($schema), 'additionalProperties' => false
        ];
    }

    public static function errors($text, $schema)
    {
        $schema = self::normalize($schema);
        $data = json_decode($text);
        if (json_last_error() !== JSON_ERROR_NONE) return ['Invalid JSON: ' . json_last_error_msg()];
        $validator = new \JsonSchema\Validator();
        $validator->validate($data, json_decode(json_encode($schema)));
        $errors = [];
        foreach ($validator->getErrors() as $error) $errors[] = $error['property'] . ': ' . $error['message'];
        return $errors;
    }
}
