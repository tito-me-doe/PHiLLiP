<?php
namespace Agent;

class Network
{
    /** A single host/CIDR, CSV string, or list -> validated unique ranges. */
    public static function cidrs($cidrs)
    {
        if (is_string($cidrs)) $cidrs = explode(',', $cidrs);
        if (!is_array($cidrs) || !$cidrs) throw new \InvalidArgumentException('Provide at least one IPv4 host or CIDR');
        $result = [];
        foreach ($cidrs as $cidr) {
            if (!is_string($cidr)) throw new \InvalidArgumentException('IPv4 ranges must be strings');
            $cidr = trim($cidr);
            $parts = explode('/', trim($cidr));
            $address = ip2long($parts[0]);
            $prefix = isset($parts[1]) ? filter_var($parts[1], FILTER_VALIDATE_INT) : 32;
            if (count($parts) > 2 || $address === false || $prefix === false || $prefix < 0 || $prefix > 32) throw new \InvalidArgumentException('Invalid IPv4 CIDR: ' . $cidr);
            $result[$cidr] = $cidr;
        }
        return array_values($result);
    }

    /** A port, CSV string, or list -> validated unique integer ports. */
    public static function ports($ports)
    {
        if (is_int($ports)) $ports = [$ports];
        elseif (is_string($ports)) $ports = explode(',', $ports);
        if (!is_array($ports) || !$ports) throw new \InvalidArgumentException('Provide at least one port');
        $result = [];
        foreach ($ports as $port) {
            if (is_string($port)) $port = trim($port);
            if ((!is_int($port) && (!is_string($port) || !preg_match('/^\d+$/D', $port))) || $port < 1 || $port > 65535) throw new \InvalidArgumentException('Ports must be integers from 1 through 65535');
            $result[(int)$port] = (int)$port;
        }
        return array_values($result);
    }

    public static function hosts($cidrs)
    {
        foreach (self::cidrs($cidrs) as $cidr) {
            $parts = explode('/', $cidr);
            $address = ip2long($parts[0]); $prefix = isset($parts[1]) ? (int)$parts[1] : 32;
            $count = pow(2, 32 - $prefix);
            $start = (int)(floor((float)sprintf('%u', $address) / $count) * $count);
            $skip = $prefix <= 30 ? 1 : 0;
            for ($offset = $skip; $offset < $count - $skip; $offset++) yield long2ip($start + $offset);
        }
    }

    public static function openPorts($cidrs, $ports, $concurrency = 192, $timeout = 0.6, $progress = null)
    {
        $cidrs = self::cidrs($cidrs); $ports = self::ports($ports);
        $targets = (function () use ($cidrs, $ports) {
            foreach (self::hosts($cidrs) as $host) foreach ($ports as $port) yield [$host, (int)$port];
        })();
        $active = [];
        $found = [];
        $checked = 0;
        while ($targets->valid() || $active) {
            while ($targets->valid() && count($active) < max(1, $concurrency)) {
                list($host, $port) = $targets->current();
                $socket = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $error, $timeout, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
                if ($socket) {
                    stream_set_blocking($socket, false);
                    $active[(int)$socket] = ['socket' => $socket, 'host' => $host, 'port' => $port, 'started' => microtime(true)];
                } else { $checked++; }
                $targets->next();
            }
            if (!$active) continue;
            $write = array_column($active, 'socket');
            $read = null;
            $except = $write;
            @stream_select($read, $write, $except, 0, 50000);
            foreach ($active as $id => $item) {
                $ready = in_array($item['socket'], $write, true) || in_array($item['socket'], $except, true);
                if (!$ready && microtime(true) - $item['started'] < $timeout) continue;
                if ($ready && @stream_socket_get_name($item['socket'], true) !== false) $found[] = ['host' => $item['host'], 'port' => $item['port']];
                fclose($item['socket']);
                unset($active[$id]);
                $checked++;
            }
            if ($progress) $progress($checked, count($found));
        }
        return $found;
    }
}
