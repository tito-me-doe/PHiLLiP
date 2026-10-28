<?php
namespace Agent\Tools;

use Agent\Config;
use Agent\Request;
use Agent\Runtime;
use Agent\SystemInfo;
use Agent\ToolContext;

/**
 * Bounded CLI-only health diagnostics for engineering and operations agents.
 * Returns read-only facts; never environment dumps, credentials, process arguments, or private chat content.
 */
class Diagnostics
{
    /**
     * Collect bounded local health facts for an identified unprivileged CLI process.
     *
     * Mirrors WorkspaceAccess authority (CLI only, not frontend, not root, identified UID)
     * without requiring a specific current working directory. Uses the current ToolContext
     * request's public URL/head when available, otherwise Config::resolve('default') when possible.
     *
     * @return array Bounded read-only host/PHP/model-registry/service health facts.
     */
    public static function health()
    {
        if (!Runtime::isCli() || ToolContext::get('frontend')) {
            throw new \RuntimeException('Diagnostics are only available to local CLI agents');
        }
        $unix = Runtime::unixIdentity();
        if ($unix['root'] === true || $unix['uid'] === null) {
            throw new \RuntimeException('Diagnostics require an identified unprivileged local CLI process');
        }
        $config = null;
        $request = ToolContext::get('request');
        if ($request instanceof Request) {
            $config = ['url' => $request->request['url'], 'head' => $request->request['head']];
        } else {
            try {
                $config = Config::resolve('default');
            } catch (\Throwable $e) {
                $config = null;
            }
        }
        return SystemInfo::collect($config);
    }

    /**
     * Return simple, secret-free PHP runtime facts: version, SAPI, extensions, PDO drivers.
     *
     * @return array {'php' => string, 'sapi' => string, 'extensions' => string[], 'pdo_drivers' => string[]}
     */
    public static function runtime()
    {
        $extensions = get_loaded_extensions();
        sort($extensions);
        $drivers = [];
        if (in_array('PDO', $extensions, true)) {
            $drivers = \PDO::getAvailableDrivers();
            sort($drivers);
        }
        return ['php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'extensions' => $extensions, 'pdo_drivers' => $drivers];
    }
}
