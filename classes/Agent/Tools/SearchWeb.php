<?php
namespace Agent\Tools;

class SearchWeb
{
    /**
     * Search the web using the configured self-hosted SearXNG service.
     * @param string $query Search terms or question
     * @param int $limit Maximum number of results, from 1 to 20
     */
    public static function search(string $query, int $limit = 5)
    {
        if (trim($query) === '') throw new \InvalidArgumentException('Search query cannot be empty');
        try { $config = \Agent\Config::service('searxng'); }
        catch (\Exception $e) { throw new \RuntimeException('Configure SearXNG: ' . $e->getMessage()); }
        \Agent\Events::emit('tool.progress', ['message' => 'Searching the web', 'query' => $query]);
        $response = \Agent\Http::request(rtrim($config['url'], '/') . '/search?' . http_build_query(['q' => $query, 'format' => 'json']), null, [], 30);
        if ($response['status'] !== 200 || !isset($response['json']['results'])) throw new \RuntimeException('SearXNG unavailable or JSON search is disabled. Configure a working SearXNG service with JSON output enabled.');
        $results = [];
        foreach (array_slice($response['json']['results'], 0, max(1, min(20, $limit))) as $row) {
            $results[] = ['title' => $row['title'], 'url' => $row['url'], 'content' => isset($row['content']) ? $row['content'] : ''];
        }
        return ['query' => $query, 'results' => $results, 'unresponsive_engines' => isset($response['json']['unresponsive_engines']) ? $response['json']['unresponsive_engines'] : []];
    }
}
