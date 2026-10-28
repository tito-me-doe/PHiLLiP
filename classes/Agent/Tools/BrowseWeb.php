<?php
namespace Agent\Tools;

class BrowseWeb
{
    /**
     * Open a web page in the remote Camoufox browser. Returns a session ID, rendered text, links, and visible controls with usable selectors. Use returned selectors instead of guessing IDs. Supports JavaScript. Browser network is remote: localhost/127.0.0.1/::1 in $url refers to the remote browser machine or container, not the PHP host; use a URL reachable from the remote browser.
     * @param string $url Absolute HTTP or HTTPS URL
     */
    public static function open(string $url) { return self::call('open', ['url' => $url]); }

    /**
     * Navigate an existing browser session, preserving its cookies and sign-in. Browser network is remote: localhost/127.0.0.1/::1 in $url refers to the remote browser machine or container, not the PHP host; use a URL reachable from the remote browser.
     * @param string $session Browser session ID
     * @param string $url Absolute HTTP or HTTPS URL
     */
    public static function navigate(string $session, string $url) { return self::call('navigate', ['session' => $session, 'url' => $url]); }

    /**
     * Read the current rendered page or a selected element in an existing browser session.
     * @param string $session Browser session ID returned by open
     * @param string $selector CSS selector, normally body
     */
    public static function read(string $session, string $selector = 'body') { return self::call('read', ['session' => $session, 'selector' => $selector]); }

    /**
     * Click an element on a browser page and return the updated rendered page.
     * @param string $session Browser session ID
     * @param string $selector CSS selector for the element
     */
    public static function click(string $session, string $selector) { return self::call('click', ['session' => $session, 'selector' => $selector]); }

    /**
     * Fill an input or textarea on a browser page.
     * @param string $session Browser session ID
     * @param string $selector CSS selector for the input
     * @param string $value Text to enter
     */
    public static function type(string $session, string $selector, string $value) { return self::call('type', ['session' => $session, 'selector' => $selector, 'value' => $value]); }

    /**
     * Choose a dropdown option by its value, using a selector from the returned controls.
     * @param string $session Browser session ID
     * @param string $selector CSS selector for a select element
     * @param string $value Option value from the control's options
     */
    public static function select(string $session, string $selector, string $value) { return self::call('select', ['session' => $session, 'selector' => $selector, 'value' => $value]); }

    /**
     * Scroll a browser page vertically.
     * @param string $session Browser session ID
     * @param int $distance Vertical distance in pixels, negative to scroll up
     */
    public static function scroll(string $session, int $distance = 800) { return self::call('scroll', ['session' => $session, 'distance' => $distance]); }

    /**
     * Capture the current viewport as an image for a vision-capable model, with page metadata.
     * @param string $session Browser session ID returned by open
     */
    public static function screenshot(string $session)
    {
        $result = self::call('screenshot', ['session' => $session]);
        return ['role' => 'tool', 'content' => [
            ['type' => 'text', 'text' => json_encode(array_intersect_key($result, array_flip(['session', 'url', 'title'])), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
            ['type' => 'image_url', 'image_url' => ['url' => $result['image']]],
        ]];
    }

    /**
     * Close a browser session when it is no longer needed.
     * @param string $session Browser session ID
     */
    public static function close(string $session) { return self::call('close', ['session' => $session]); }

    private static function call($action, array $arguments)
    {
        $config = \Agent\Config::service('camoufox');
        $token = isset($config['token_env']) ? getenv($config['token_env']) : (isset($config['token']) ? $config['token'] : '');
        \Agent\Events::emit('tool.progress', ['message' => 'Browser: ' . $action]);
        $arguments['action'] = $action;
        $arguments['scope'] = (string)\Agent\ToolContext::get('thread_id', 'application');
        $response = \Agent\Http::request(rtrim($config['url'], '/') . '/browse', $arguments, ['Authorization: Bearer ' . $token], 45);
        $loopbackHint = '';
        $host = isset($arguments['url']) ? strtolower((string)parse_url($arguments['url'], PHP_URL_HOST)) : '';
        if (in_array($host, ['127.0.0.1', '[::1]', '::1', 'localhost'], true)) {
            $loopbackHint = ' If the URL targets localhost/127.0.0.1/::1, it refers to the remote browser machine or container, not the PHP host; use a URL reachable from the remote browser.';
        }
        if ($response['errno'] !== 0) {
            throw new \RuntimeException('Camoufox service is unavailable. Configure a working remote Camoufox service. ' . $response['error'] . $loopbackHint);
        }
        if ($response['status'] !== 200 || !is_array($response['json'])) {
            $detail = mb_strcut($response['body'] . $response['error'], 0, 2048, 'UTF-8');
            throw new \RuntimeException('Remote browser ' . $action . ' failed (HTTP ' . $response['status'] . '). ' . $detail . $loopbackHint);
        }
        return $response['json'];
    }
}
