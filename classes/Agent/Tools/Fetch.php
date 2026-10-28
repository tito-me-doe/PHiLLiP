<?php
namespace Agent\Tools;

class Fetch
{
    /**
     * Fetch a URL and return readable text, JSON, or page content with links. Use BrowseWeb for pages that require JavaScript.
     * @param string $url Absolute HTTP or HTTPS URL
     * @param int $maxCharacters Maximum returned text length, from 100 to 100000
     */
    public static function fetch(string $url, int $maxCharacters = 12000)
    {
        if (!in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'])) throw new \InvalidArgumentException('Fetch requires an HTTP or HTTPS URL');
        \Agent\Events::emit('tool.progress', ['message' => 'Fetching resource', 'url' => $url]);
        $body = '';
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_ENCODING => '', CURLOPT_USERAGENT => 'PHiLLiP/0.1',
            CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$body) {
                if (strlen($body) + strlen($chunk) > 4 * 1024 * 1024) return 0;
                $body .= $chunk;
                return strlen($chunk);
            }]);
        if (defined('CURLOPT_PROTOCOLS_STR')) curl_setopt_array($curl, [constant('CURLOPT_PROTOCOLS_STR') => 'http,https', constant('CURLOPT_REDIR_PROTOCOLS_STR') => 'http,https']);
        else curl_setopt_array($curl, [CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
        $cancelled = false;
        if (\Agent\ToolContext::get('control')) {
            $progressOption = defined('CURLOPT_XFERINFOFUNCTION') ? constant('CURLOPT_XFERINFOFUNCTION') : CURLOPT_PROGRESSFUNCTION;
            curl_setopt_array($curl, [CURLOPT_NOPROGRESS => false, $progressOption => function () use (&$cancelled) {
                $cancelled = call_user_func(\Agent\ToolContext::get('control')) >= 2; return $cancelled ? 1 : 0;
            }]);
        }
        curl_exec($curl);
        $info = curl_getinfo($curl);
        $error = curl_error($curl);
        if (is_resource($curl)) curl_close($curl);
        if ($cancelled) throw new \Agent\Cancelled('Fetch cancelled');
        if ($error) throw new \RuntimeException('Fetch failed (maximum resource size 4 MiB): ' . $error);
        if ($info['http_code'] < 200 || $info['http_code'] >= 300) throw new \RuntimeException('Fetch HTTP error: ' . $info['http_code']);
        $type = isset($info['content_type']) ? $info['content_type'] : '';
        $links = [];
        if (stripos($type, 'html') !== false || preg_match('/^\s*<!doctype html|^\s*<html/i', $body)) {
            $document = new \DOMDocument();
            $previous = libxml_use_internal_errors(true);
            $document->loadHTML('<?xml encoding="utf-8" ?>' . $body, LIBXML_NONET);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            $xpath = new \DOMXPath($document);
            foreach ($xpath->query('//script|//style|//noscript|//template') as $node) $node->parentNode->removeChild($node);
            foreach ($xpath->query('//a[@href]') as $node) {
                if (count($links) >= 50) break;
                $links[] = ['text' => trim($node->textContent), 'href' => $node->getAttribute('href')];
            }
            $text = preg_replace('/\s+/u', ' ', $document->textContent);
        } elseif (!$type || strpos($type, 'text/') === 0 || preg_match('/json|xml|javascript|yaml/', $type)) $text = $body;
        else throw new \RuntimeException('This resource needs attachment parsing or a compatible model: ' . $type);
        $limit = max(100, min(100000, $maxCharacters));
        return ['url' => $info['url'], 'content_type' => $type, 'text' => mb_substr(trim($text), 0, $limit), 'truncated' => mb_strlen(trim($text)) > $limit, 'links' => $links];
    }
}
