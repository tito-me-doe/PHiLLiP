<?php
namespace Agent;

/** Read-only adapter for Unsloth Studio's documented authenticated OpenAI-compatible API. */
class UnslothStudio
{
    private $baseUrl;
    private $apiKey;
    private $request;

    public function __construct($baseUrl = null, $apiKey = null, callable $request = null)
    {
        $baseUrl = $baseUrl === null ? getenv('UNSLOTH_STUDIO_URL') : $baseUrl;
        $apiKey = $apiKey === null ? getenv('UNSLOTH_STUDIO_API_KEY') : $apiKey;
        if (!is_string($baseUrl) || trim($baseUrl) === '') throw new \InvalidArgumentException('Set UNSLOTH_STUDIO_URL to the operator-configured Unsloth Studio address');
        $parts = parse_url($baseUrl);
        if (!$parts || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('Unsloth Studio URL must be an http(s) origin without credentials, query, or fragment');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = is_string($apiKey) ? trim($apiKey) : '';
        $this->request = $request ?: function ($url, $body, $headers, $timeout) { return Http::request($url, $body, $headers, $timeout); };
    }

    /** Validate a model reference selected from the configured Studio API. */
    public static function validateModelId($model)
    {
        if (!is_string($model) || trim($model) === '' || strlen($model) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $model))
            throw new \InvalidArgumentException('Use a model ID of at most 4096 bytes without control characters');
        return $model;
    }

    /** List models reported by Unsloth's documented API. This never loads or changes a model. */
    public function status()
    {
        return $this->call('GET', '/v1/models', null, 30);
    }

    /** The public REST contract has no documented load operation. */
    public function load($model)
    {
        self::validateModelId($model);
        throw self::controlUnavailable('load');
    }

    /** The public REST contract has no documented unload operation. */
    public function unload($modelPath)
    {
        self::validateModelId($modelPath);
        throw self::controlUnavailable('unload');
    }

    public static function controlUnavailable($action)
    {
        if ($action === 'load') return new \RuntimeException('Unsloth Studio publishes no documented REST load operation. Load the model in Studio or with its documented `unsloth run --model MODEL` command, then inspect it with status.');
        return new \RuntimeException('Unsloth Studio publishes no documented REST unload operation. Unload the model in Studio, or stop the `unsloth run` process that you started.');
    }

    private function call($method, $path, $body, $timeout)
    {
        $headers = ['Accept' => 'application/json'];
        if ($this->apiKey !== '') $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        $response = call_user_func($this->request, $this->baseUrl . $path, $body, $headers, $timeout);
        if (!is_array($response) || !isset($response['status'])) throw new \RuntimeException('Unsloth Studio returned an invalid HTTP response');
        if ($response['status'] < 200 || $response['status'] >= 300 || !empty($response['errno'])) {
            throw new \RuntimeException('Unsloth Studio ' . strtolower($method) . ' failed (HTTP ' . (int)$response['status'] . '). Check the Studio URL, API key, and model request.');
        }
        if (!isset($response['json']) || !is_array($response['json'])) throw new \RuntimeException('Unsloth Studio returned invalid JSON');
        return $response['json'];
    }
}
