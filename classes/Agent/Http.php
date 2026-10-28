<?php
namespace Agent;

class Http
{
    public static function request($url, $body = null, array $headers = [], $timeout = 120, $onChunk = null, $onTick = null)
    {
        if ($onTick === null && ToolContext::get('control')) $onTick = function () {
            if (call_user_func(ToolContext::get('control')) >= 2) throw new Cancelled('User cancelled model/tool execution');
        };
        $curl = curl_init($url);
        $response = '';
        $normalizedHeaders = [];
        foreach ($headers as $key => $value) $normalizedHeaders[] = is_int($key) ? $value : $key . ': ' . $value;
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout, CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $normalizedHeaders),
            CURLOPT_ENCODING => '', CURLOPT_FOLLOWLOCATION => false]);
        if ($body !== null) {
            $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) throw new \RuntimeException('JSON encode error: ' . json_last_error_msg());
            curl_setopt($curl, CURLOPT_POSTFIELDS, $json);
        }
        if ($onChunk) curl_setopt($curl, CURLOPT_WRITEFUNCTION, function ($handle, $chunk) use ($onChunk, &$response) {
            $response .= $chunk;
            $onChunk($chunk);
            return strlen($chunk);
        });
        $progressOption = defined('CURLOPT_XFERINFOFUNCTION') ? constant('CURLOPT_XFERINFOFUNCTION') : CURLOPT_PROGRESSFUNCTION;
        if ($onTick) curl_setopt_array($curl, [CURLOPT_NOPROGRESS => false, $progressOption => function ($handle, $total, $received, $uploadTotal, $uploaded) use ($onTick) {
            call_user_func($onTick);
            return 0;
        }]);
        $result = curl_exec($curl);
        $info = curl_getinfo($curl);
        $error = curl_error($curl);
        $errno = curl_errno($curl);
        if (!$onChunk) $response = (string)$result;
        if (is_resource($curl)) curl_close($curl);
        return ['status' => $info['http_code'], 'body' => $response, 'json' => json_decode($response, true),
            'error' => $error, 'errno' => $errno, 'info' => $info];
    }

    public static function batch(array $urls, $timeout = 3)
    {
        $multi = curl_multi_init();
        $handles = [];
        foreach ($urls as $key => $url) {
            $handle = curl_init($url);
            curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT_MS => (int)($timeout * 1000),
                CURLOPT_TIMEOUT_MS => (int)($timeout * 1000), CURLOPT_FOLLOWLOCATION => false, CURLOPT_ENCODING => '',
                CURLOPT_HTTPHEADER => ['Accept: application/json']]);
            curl_multi_add_handle($multi, $handle);
            $handles[$key] = $handle;
        }
        do {
            $code = curl_multi_exec($multi, $running);
            if ($running && curl_multi_select($multi, 0.2) === -1) usleep(1000);
        } while ($running && $code === CURLM_OK);
        $results = [];
        foreach ($handles as $key => $handle) {
            $body = curl_multi_getcontent($handle);
            $results[$key] = ['status' => curl_getinfo($handle, CURLINFO_HTTP_CODE), 'json' => json_decode($body, true), 'error' => curl_error($handle)];
            curl_multi_remove_handle($multi, $handle);
            if (is_resource($handle)) curl_close($handle);
        }
        if (is_resource($multi)) curl_multi_close($multi);
        return $results;
    }
}
