<?php
namespace Agent;

class StreamDecoder
{
    private $buffer = '';
    private $message = ['role' => 'assistant', 'content' => ''];
    private $calls = [];
    private $finish;
    private $usage;
    private $done = false;

    public function push($chunk)
    {
        $this->buffer .= $chunk;
        while (preg_match('/\r?\n\r?\n/', $this->buffer, $matches, PREG_OFFSET_CAPTURE)) {
            $offset = $matches[0][1];
            $frame = substr($this->buffer, 0, $offset);
            $this->buffer = substr($this->buffer, $offset + strlen($matches[0][0]));
            $lines = [];
            foreach (preg_split('/\r?\n/', $frame) as $line) if (strpos($line, 'data:') === 0) $lines[] = ltrim(substr($line, 5), ' ');
            if (!$lines) continue;
            $data = implode("\n", $lines);
            if ($data === '[DONE]') { $this->done = true; continue; }
            $json = json_decode($data, true);
            if (!is_array($json)) throw new \RuntimeException('Invalid JSON in model stream');
            if (isset($json['error'])) throw new \RuntimeException('Model stream error: ' . json_encode($json['error']));
            if (isset($json['usage'])) $this->usage = $json['usage'];
            if (!isset($json['choices'][0])) continue;
            $choice = $json['choices'][0];
            if (isset($choice['finish_reason'])) $this->finish = $choice['finish_reason'];
            $delta = isset($choice['delta']) ? $choice['delta'] : [];
            foreach (['content', 'reasoning', 'reasoning_content'] as $field) {
                if (!isset($delta[$field])) continue;
                if (!isset($this->message[$field])) $this->message[$field] = '';
                $this->message[$field] .= $delta[$field];
                Events::emit($field === 'content' ? 'text.delta' : 'reasoning.delta', ['content' => $delta[$field]]);
            }
            foreach (isset($delta['tool_calls']) ? $delta['tool_calls'] : [] as $call) {
                $index = isset($call['index']) ? $call['index'] : 0;
                if (!isset($this->calls[$index])) $this->calls[$index] = ['id' => '', 'type' => 'function', 'function' => ['name' => '', 'arguments' => '']];
                if (isset($call['id'])) $this->calls[$index]['id'] .= $call['id'];
                foreach (['name', 'arguments'] as $field) if (isset($call['function'][$field])) $this->calls[$index]['function'][$field] .= $call['function'][$field];
            }
        }
    }

    public function finish()
    {
        if (!$this->done && $this->finish === null) throw new \RuntimeException('Incomplete model stream');
        if ($this->calls) { ksort($this->calls); $this->message['tool_calls'] = array_values($this->calls); }
        return ['choices' => [['message' => $this->message, 'finish_reason' => $this->finish]], 'usage' => $this->usage];
    }
}
