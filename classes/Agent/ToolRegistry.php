<?php
namespace Agent;

class ToolRegistry
{
    private static $types = ['int' => 'integer', 'float' => 'number', 'bool' => 'boolean'];
    private $tools = [];

    /** Readable tool/field names for progress logs and saved activity alike. */
    public static function label($name)
    {
        $name = preg_replace('/^Agent[\\\\_:]+Tools[\\\\_:]+/', '', (string)$name);
        $name = trim(preg_replace('/[_:]+|(?<=[a-z])(?=[A-Z])/', ' ', $name));
        return ucfirst(preg_replace('/\b(\w+)\s+\1$/i', '$1', $name));
    }

    /** Tool policy and UI toggles are class-scoped even for method-scoped registrations. */
    public static function policies($tools)
    {
        return array_values(array_unique(array_map(function ($tool) { return explode('::', $tool)[0]; }, self::names($tools))));
    }

    /** Named tools from PHP lists, JSON lists or CSV; never executable expressions. */
    public static function names($tools)
    {
        if (is_string($tools) && strpos($tools, "\0") !== false) throw new \InvalidArgumentException('Tools must be callable names, not PHP expressions or NUL bytes');
        if (is_string($tools)) $tools = trim($tools);
        if ($tools === null || $tools === '' || $tools === 'none') return [];
        if (is_string($tools)) {
            if (substr(ltrim($tools), 0, 1) === '[') {
                $tmp = json_decode($tools, true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($tmp)) throw new \InvalidArgumentException('Tools must be a JSON list or callable names');
                $tools = $tmp; unset($tmp);
            } else $tools = explode(',', $tools);
        }
        if (!is_array($tools) || ($tools && array_keys($tools) !== range(0, count($tools) - 1))) throw new \InvalidArgumentException('Tools must be a list of names');
        static $builtins;
        if ($builtins === null) {
            $builtins = [];
            foreach (glob(Runtime::root() . '/classes/Agent/Tools/*.php') as $path) $builtins[strtolower(basename($path, '.php'))] = basename($path, '.php');
        }
        $result = [];
        foreach ($tools as $tool) {
            if (!is_string($tool)) throw new \InvalidArgumentException('Tools must be callable names, never closures or objects');
            if (preg_match('/[\x00-\x1f\x7f]/', $tool)) throw new \InvalidArgumentException('Tools must be callable names or class names, not PHP expressions or control bytes');
            $tool = trim($tool);
            if ($tool === '') continue;
            $original = $tool;
            while (strpos($tool, '\\\\') !== false) $tool = str_replace('\\\\', '\\', $tool);
            if ($tool !== $original) Log::writeWarning('Normalized repeated namespace separators in tool name: %s', $original);
            if (!preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*(?:::[A-Za-z_][A-Za-z0-9_]*)?$/D', $tool)) throw new \InvalidArgumentException('Tools must be callable names or class names, not PHP expressions');
            $parts = explode('::', ltrim($tool, '\\'), 2);
            if (isset($builtins[strtolower($parts[0])])) $parts[0] = 'Agent\\Tools\\' . $builtins[strtolower($parts[0])];
            $result[] = implode('::', $parts);
        }
        return array_values(array_unique($result));
    }

    public function register($tool)
    {
        if (is_string($tool) && class_exists($tool)) {
            foreach ((new \ReflectionClass($tool))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic()) $this->register([$tool, $method->getName()]);
            }
            return;
        }
        if (!is_callable($tool)) throw new \InvalidArgumentException('Tool must be callable or a class with public static methods');
        if (is_array($tool)) $reflection = new \ReflectionMethod($tool[0], $tool[1]);
        elseif (is_string($tool) && strpos($tool, '::') !== false) {
            list($class, $method) = explode('::', $tool, 2);
            $reflection = new \ReflectionMethod($class, $method);
        }
        elseif (is_object($tool) && !($tool instanceof \Closure)) $reflection = new \ReflectionMethod($tool, '__invoke');
        else $reflection = new \ReflectionFunction($tool);
        $name = $reflection instanceof \ReflectionMethod ? $reflection->getDeclaringClass()->getName() . '_' . $reflection->getName() : $reflection->getName();
        $name = $tool instanceof \Closure ? 'tool_' . count($this->tools) : preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
        if (strlen($name) > 64) $name = substr($name, 0, 51) . '_' . substr(sha1($name), 0, 12);
        if (isset($this->tools[$name])) throw new \InvalidArgumentException('Tool already registered: ' . $name);
        $description = '';
        $docs = [];
        foreach (explode("\n", (string)$reflection->getDocComment()) as $line) {
            $line = trim(preg_replace('/^\s*\/?\*+\/?\s?|\s*\*\/\s*$/', '', $line));
            if (preg_match('/^@param\s+(\S+)\s+(?:\.\.\.)?\$(\w+)\s*(.*)$/', $line, $matches)) $docs[$matches[2]] = ['type' => $matches[1], 'description' => $matches[3]];
            elseif ($line !== '' && $line[0] !== '@') $description .= ($description ? ' ' : '') . $line;
        }
        if (!$description) throw new \InvalidArgumentException('Tool requires a descriptive docblock: ' . $name);
        $properties = [];
        $required = [];
        foreach ($reflection->getParameters() as $parameter) {
            $key = $parameter->getName();
            $type = $parameter->hasType() ? ltrim((string)$parameter->getType(), '?') : (isset($docs[$key]) ? $docs[$key]['type'] : 'string');
            $type = isset(self::$types[$type]) ? self::$types[$type] : $type;
            $definition = ['description' => isset($docs[$key]) ? $docs[$key]['description'] : ''];
            if (in_array($type, ['string', 'integer', 'number', 'boolean', 'array', 'object'])) $definition['type'] = $type;
            if ($parameter->hasType() && $parameter->allowsNull() && isset($definition['type'])) $definition['type'] = [$type, 'null'];
            if ($type === 'array') $definition['items'] = new \stdClass();
            if ($parameter->isVariadic()) {
                $items = $definition; unset($items['description']);
                $definition = ['description' => $definition['description'], 'type' => 'array', 'items' => (object)$items];
            }
            $properties[$key] = $definition;
            if (!$parameter->isOptional()) $required[] = $key;
        }
        $definition = ['type' => 'function', 'function' => ['name' => $name, 'description' => $description,
            'parameters' => ['type' => 'object', 'properties' => (object)$properties, 'required' => $required, 'additionalProperties' => false]]];
        $this->tools[$name] = ['callable' => $tool, 'reflection' => $reflection, 'definition' => $definition];
    }

    public function definitions() { return array_column($this->tools, 'definition'); }
    public function source($name) { return isset($this->tools[$name]) ? $this->tools[$name]['callable'] : null; }

    private function normalizeArgument($value, \ReflectionParameter $parameter)
    {
        if (!$parameter->hasType() || ($value === null && $parameter->allowsNull())) return $value;
        $type = ltrim((string)$parameter->getType(), '?');
        if (isset(self::$types[$type])) {
            $value = Utilities::cleanup($value, self::$types[$type], $issues);
            if ($issues['errors']) throw new \RuntimeException('Invalid ' . self::$types[$type] . ' tool argument: ' . $parameter->getName());
        } elseif ($type === 'array' && is_object($value)) $value = (array)$value;
        return $value;
    }

    public function execute(array $call)
    {
        if (empty($call['id'])) throw new \RuntimeException('Tool call has no ID');
        $name = isset($call['function']['name']) ? $call['function']['name'] : '';
        $failed = false;
        $started = microtime(true);
        Log::writeDebug('Tool invocation started', ['id' => $call['id'], 'name' => $name]);
        Log::trace('Tool call', $call);
        Events::emit('tool.start', ['id' => $call['id'], 'name' => $name, 'arguments' => isset($call['function']['arguments']) ? $call['function']['arguments'] : '{}']);
        try {
            if (!isset($this->tools[$name])) throw new \RuntimeException('Unknown tool: ' . $name);
            $json = isset($call['function']['arguments']) ? $call['function']['arguments'] : '{}';
            $object = json_decode($json);
            if (!is_object($object) || json_last_error() !== JSON_ERROR_NONE) throw new \RuntimeException('Tool arguments must be a JSON object');
            $arguments = (array)$object;
            $values = [];
            foreach ($this->tools[$name]['reflection']->getParameters() as $parameter) {
                $key = $parameter->getName();
                if ($parameter->isVariadic()) {
                    $extra = isset($arguments[$key]) ? $arguments[$key] : [];
                    if (!is_array($extra)) throw new \RuntimeException('Variadic argument must be an array: ' . $key);
                    foreach ($extra as $value) $values[] = $this->normalizeArgument($value, $parameter);
                } elseif (array_key_exists($key, $arguments)) {
                    $values[] = $this->normalizeArgument($arguments[$key], $parameter);
                } elseif ($parameter->isDefaultValueAvailable()) $values[] = $parameter->getDefaultValue();
                else throw new \RuntimeException('Missing tool argument: ' . $key);
                unset($arguments[$key]);
            }
            if ($arguments) throw new \RuntimeException('Unknown tool arguments: ' . implode(', ', array_keys($arguments)));
            $result = call_user_func_array($this->tools[$name]['callable'], $values);
            // Explicit message payloads retain native content parts; ordinary data stays JSON text.
            if (is_array($result) && isset($result['role']) && $result['role'] === 'tool') {
                $message = $result;
            } else {
                $content = is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($content === false) throw new \RuntimeException('Tool result is not JSON encodable');
                $message = ['role' => 'tool', 'content' => $content];
            }
            // Validate all content once, inside the recoverable tool-error boundary.
            $message = Message::normalize($message);
            $content = $message['content'] === null ? '' : $message['content'];
        } catch (\Throwable $e) {
            if ($e instanceof Cancelled) throw $e;
            $failed = true;
            Log::writeDebug('Tool invocation failed', ['id' => $call['id'], 'name' => $name, 'exception' => get_class($e)]);
            Log::trace('Tool exception', $e);
            $content = 'Tool error: ' . $e->getMessage();
        }
        Log::writeDebug('Tool invocation finished', ['id' => $call['id'], 'name' => $name, 'bytes' => strlen(is_array($content) ? json_encode($content) : $content), 'elapsed_ms' => round((microtime(true) - $started) * 1000, 2)]);
        Log::trace('Tool result', ['id' => $call['id'], 'name' => $name, 'content' => $content]);
        Events::emit('tool.result', ['id' => $call['id'], 'name' => $name, 'content' => $content, '_tool_error' => $failed]);
        return ['role' => 'tool', 'tool_call_id' => $call['id'], 'content' => $content, '_tool_error' => $failed];
    }
}
