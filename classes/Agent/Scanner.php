<?php
namespace Agent;

class Scanner
{
    /** Canonical queued-scan payload shared by HTTP settings and background execution. */
    public static function configuration($input)
    {
        $input = Config::object($input, 'Scan configuration');
        $cidrs = Network::cidrs(isset($input['cidr']) ? $input['cidr'] : getenv('SCAN_CIDRS'));
        $ports = Network::ports(isset($input['ports']) ? $input['ports'] : getenv('SCAN_PORTS'));
        $all = isset($input['all']) ? Config::boolean($input['all'], 'All-port selection') : false;
        return ['cidr' => implode(',', $cidrs), 'ports' => implode(',', $ports), 'all' => $all];
    }
    public function inspectBatch(array $bases)
    {
        $urls = [];
        foreach ($bases as $base) foreach (['/v1/models', '/api/tags', '/api/version', '/api/ps', '/props', '/version', '/health'] as $path) $urls[$base . '|' . $path] = rtrim($base, '/') . $path;
        $responses = Http::batch($urls, 3);
        $results = [];
        foreach ($bases as $base) {
            $set = [];
            foreach (['/v1/models', '/api/tags', '/api/version', '/api/ps', '/props', '/version', '/health'] as $path) $set[$path] = $responses[$base . '|' . $path];
            $results[$base] = $this->inspect($base, $set);
        }
        return $results;
    }

    public function inspect($base, $responses = null)
    {
        $base = rtrim($base, '/');
        $paths = ['/v1/models', '/api/tags', '/api/version', '/api/ps', '/props', '/version', '/health'];
        $urls = [];
        foreach ($paths as $path) $urls[$path] = $base . $path;
        if ($responses === null) $responses = Http::batch($urls, 3);
        $metadata = [];
        foreach ($responses as $path => $response) if (is_array($response['json'])) $metadata[$path] = $response['json'];
        $engine = 'openai-compatible';
        $rows = isset($metadata['/v1/models']['data']) && is_array($metadata['/v1/models']['data']) ? $metadata['/v1/models']['data'] : null;
        $tags = isset($metadata['/api/tags']['models']) && is_array($metadata['/api/tags']['models']) ? $metadata['/api/tags']['models'] : null;
        if ($tags !== null) { $engine = 'ollama'; $rows = $tags; }
        elseif (isset($metadata['/props']['default_generation_settings']) || isset($metadata['/props']['model_path'])) $engine = 'llama.cpp';
        if ($rows === null && $engine !== 'llama.cpp') {
            if (in_array($responses['/v1/models']['status'], [401, 403]) && isset($metadata['/v1/models']['error'])) {
                return ['endpoint' => ['url' => $base, 'engine' => $engine, 'status' => 'authentication-required', 'metadata' => $metadata], 'models' => []];
            }
            return null;
        }
        $rows = $rows ?: [];
        if (!$rows && isset($metadata['/props']['model_path'])) $rows[] = ['id' => $metadata['/props']['model_path']];
        $loaded = [];
        foreach (isset($metadata['/api/ps']['models']) ? $metadata['/api/ps']['models'] : [] as $running) $loaded[] = $running['name'];
        $models = [];
        foreach ($rows as $row) {
            $name = isset($row['id']) ? $row['id'] : (isset($row['name']) ? $row['name'] : null);
            if (!$name) continue;
            if (isset($row['owned_by']) && $row['owned_by'] === 'vllm') $engine = 'vllm';
            if (isset($row['owned_by']) && $row['owned_by'] === 'llamacpp') $engine = 'llama.cpp';
            $caps = array_fill_keys(array_keys(Registry::CAPABILITIES), null);
            if ($engine === 'ollama') {
                $show = Http::request($base . '/api/show', ['model' => $name], [], 5);
                if ($show['status'] === 200 && is_array($show['json'])) {
                    $row['show'] = $show['json'];
                    if (isset($show['json']['capabilities'])) foreach (['chat' => 'completion', 'tools' => 'tools', 'vision' => 'vision', 'embeddings' => 'embedding'] as $key => $cap) {
                        $caps['supports_' . $key] = in_array($cap, $show['json']['capabilities']);
                    }
                }
            }
            if (isset($metadata['/props']['modalities']['vision'])) $caps['supports_vision'] = (bool)$metadata['/props']['modalities']['vision'];
            $status = $engine === 'ollama' ? (in_array($name, $loaded) ? 'loaded' : 'available')
                : (in_array($engine, ['vllm', 'llama.cpp']) ? 'loaded' : 'available');
            $models[] = array_merge(['model' => $name, 'status' => $status, 'metadata' => $row], $caps);
        }
        $status = $responses['/health']['status'] === 503 ? 'loading' : 'online';
        return ['endpoint' => ['url' => $base, 'engine' => $engine, 'status' => $status, 'metadata' => $metadata], 'models' => $models];
    }
}
