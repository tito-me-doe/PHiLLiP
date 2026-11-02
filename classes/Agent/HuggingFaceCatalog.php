<?php
namespace Agent;

/**
 * Lightweight Hugging Face Hub model catalog client.
 *
 * Official contract: GET https://huggingface.co/api/models
 *   - HfApi reference: https://huggingface.co/docs/huggingface_hub/en/package_reference/hf_api
 *   - Hub search guide: https://huggingface.co/docs/huggingface_hub/en/guides/search
 *
 * Default: top-100 trending (sort=trendingScore, direction=-1, limit=100).
 * A --sort override accepts only documented keys:
 *   author, downloads, downloadsAllTime, gated, lastModified, likes, trendingScore, usedStorage.
 * Requested fields are requested via expand[] so the Hub returns them.
 * Each entry is normalized to: id, author, pipeline_tag, downloads, likes, trendingScore,
 * lastModified, tags, gated, private, usedStorage; absent fields are tolerated (null).
 * Catalog entries are never stored as local runnable models or profiles.
 */
class HuggingFaceCatalog
{
    const HUB_URL = 'https://huggingface.co/api/models';
    const DEFAULT_LIMIT = 100;
    const DEFAULT_SORT = 'trendingScore';
    const DEFAULT_DIRECTION = -1;
    const MAX_LIMIT = 1000;

    private static $allowedSorts = [
        'author', 'downloads', 'downloadsAllTime', 'gated',
        'lastModified', 'likes', 'trendingScore', 'usedStorage',
    ];

    private static $expandFields = [
        'author', 'pipeline_tag', 'downloads', 'likes', 'trendingScore',
        'lastModified', 'tags', 'gated', 'private', 'usedStorage',
    ];

    /** @var callable(string):string */
    private $transport;

    /**
     * @param callable(string):string|null $transport Injectable HTTP fetch; defaults to curl.
     */
    public function __construct($transport = null)
    {
        $this->transport = $transport ?: [$this, 'defaultFetch'];
    }

    /**
     * Fetch and normalize a page of Hub models.
     *
     * @param string|null $sort Documented sort key or null for default.
     * @param int|null $limit Page size or null for default.
     * @return list<array{id:string|null, author:string|null, pipeline_tag:string|null, downloads:int|null, likes:int|null, trendingScore:float|null, lastModified:string|null, tags:list<string>, gated:bool|null, private:bool|null, usedStorage:int|null}>
     * @throws \InvalidArgumentException For an unsupported sort key or bad limit.
     * @throws \RuntimeException For transport, HTTP, JSON or schema errors.
     */
    public function fetch(?string $sort = null, ?int $limit = null): array
    {
        $sort = $sort === null ? self::DEFAULT_SORT : $sort;
        if (!in_array($sort, self::$allowedSorts, true)) {
            throw new \InvalidArgumentException('Unsupported Hugging Face Hub sort key: ' . $sort .
                '; allowed: ' . implode(', ', self::$allowedSorts));
        }
        $limit = $limit === null ? self::DEFAULT_LIMIT : (int)$limit;
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new \InvalidArgumentException('Limit must be between 1 and ' . self::MAX_LIMIT);
        }

        $query = [
            'sort' => $sort,
            'direction' => self::DEFAULT_DIRECTION,
            'limit' => (string)$limit,
        ];
        foreach (self::$expandFields as $field) {
            $query['expand'][] = $field;
        }
        $url = self::HUB_URL . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        $body = ($this->transport)($url);
        if ($body === false || $body === '') {
            throw new \RuntimeException('Hugging Face Hub returned an empty response');
        }
        $data = json_decode($body, true);
        if ($data === null) {
            throw new \RuntimeException('Hugging Face Hub returned invalid JSON: ' . json_last_error_msg());
        }
        if (!is_array($data)) {
            throw new \RuntimeException('Hugging Face Hub response is not a model list');
        }

        $normalized = [];
        foreach ($data as $model) {
            if (!is_array($model)) {
                continue;
            }
            $normalized[] = self::normalizeModel($model);
        }
        return $normalized;
    }

    private static function normalizeModel(array $model): array
    {
        return [
            'id' => isset($model['id']) && is_string($model['id']) ? $model['id'] : null,
            'author' => isset($model['author']) && is_string($model['author']) ? $model['author'] : null,
            'pipeline_tag' => isset($model['pipeline_tag']) && is_string($model['pipeline_tag']) ? $model['pipeline_tag'] : null,
            'downloads' => isset($model['downloads']) && is_int($model['downloads']) ? $model['downloads'] : null,
            'likes' => isset($model['likes']) && is_int($model['likes']) ? $model['likes'] : null,
            'trendingScore' => isset($model['trendingScore']) && is_numeric($model['trendingScore']) ? (float)$model['trendingScore'] : null,
            'lastModified' => isset($model['lastModified']) && is_string($model['lastModified']) ? $model['lastModified'] : null,
            'tags' => isset($model['tags']) && is_array($model['tags']) ? array_values(array_filter($model['tags'], 'is_string')) : [],
            'gated' => isset($model['gated']) && is_bool($model['gated']) ? $model['gated'] : null,
            'private' => isset($model['private']) && is_bool($model['private']) ? $model['private'] : null,
            'usedStorage' => isset($model['usedStorage']) && is_int($model['usedStorage']) ? $model['usedStorage'] : null,
        ];
    }

    private function defaultFetch(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_ENCODING => '',
        ]);
        $body = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new \RuntimeException('Hugging Face Hub request failed: ' . $curlError);
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \RuntimeException('Hugging Face Hub returned HTTP ' . $httpCode);
        }
        return $body;
    }
}
