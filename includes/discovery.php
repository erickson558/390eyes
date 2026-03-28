<?php

function safe_shell_output($command)
{
    if (!function_exists('shell_exec')) {
        return '';
    }

    $output = @shell_exec($command . ' 2>NUL');

    return is_string($output) ? $output : '';
}

function is_valid_ipv4($ip)
{
    return preg_match('/^(\d{1,3}\.){3}\d{1,3}$/', $ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
}

function ip_to_number($ip)
{
    $parts = explode('.', $ip);

    if (count($parts) !== 4) {
        return false;
    }

    return ((int) $parts[0] * 16777216)
        + ((int) $parts[1] * 65536)
        + ((int) $parts[2] * 256)
        + (int) $parts[3];
}

function number_to_ip($number)
{
    $number = (float) $number;
    $a = floor($number / 16777216);
    $number -= $a * 16777216;
    $b = floor($number / 65536);
    $number -= $b * 65536;
    $c = floor($number / 256);
    $number -= $c * 256;
    $d = floor($number);

    return $a . '.' . $b . '.' . $c . '.' . $d;
}

function mask_to_prefix($mask)
{
    $parts = explode('.', $mask);
    $prefix = 0;

    if (count($parts) !== 4) {
        return 24;
    }

    foreach ($parts as $part) {
        $part = (int) $part;
        while ($part > 0) {
            $prefix += $part & 1;
            $part = $part >> 1;
        }
    }

    return $prefix;
}

function prefix_to_mask($prefix)
{
    $prefix = (int) $prefix;
    if ($prefix < 0) {
        $prefix = 0;
    }
    if ($prefix > 32) {
        $prefix = 32;
    }

    $parts = array(0, 0, 0, 0);
    $remaining = $prefix;

    for ($i = 0; $i < 4; $i++) {
        if ($remaining >= 8) {
            $parts[$i] = 255;
            $remaining -= 8;
        } elseif ($remaining > 0) {
            $parts[$i] = 256 - pow(2, 8 - $remaining);
            $remaining = 0;
        }
    }

    return implode('.', $parts);
}

function network_from_ip_mask($ip, $mask)
{
    $ipParts = explode('.', $ip);
    $maskParts = explode('.', $mask);
    $result = array();

    if (count($ipParts) !== 4 || count($maskParts) !== 4) {
        return $ip;
    }

    for ($i = 0; $i < 4; $i++) {
        $result[] = ((int) $ipParts[$i]) & ((int) $maskParts[$i]);
    }

    return implode('.', $result);
}

function is_host_candidate_ip($ip)
{
    if (!is_valid_ipv4($ip)) {
        return false;
    }

    $parts = explode('.', $ip);
    $first = (int) $parts[0];
    $last = (int) $parts[3];

    if ($first === 0 || $first === 127 || $first >= 224) {
        return false;
    }

    if ($last === 0 || $last === 255) {
        return false;
    }

    return true;
}

function unique_append(&$items, $value)
{
    if (!in_array($value, $items, true)) {
        $items[] = $value;
    }
}

function build_network_info($ip, $mask)
{
    if (!is_valid_ipv4($ip) || !is_valid_ipv4($mask)) {
        return null;
    }

    if (substr($ip, 0, 4) === '127.' || $ip === '0.0.0.0') {
        return null;
    }

    $prefix = mask_to_prefix($mask);
    $network = network_from_ip_mask($ip, $mask);
    $scanPrefix = ($prefix < 24) ? 24 : $prefix;
    $scanMask = prefix_to_mask($scanPrefix);
    $scanNetwork = network_from_ip_mask($ip, $scanMask);

    return array(
        'ip' => $ip,
        'mask' => $mask,
        'prefix' => $prefix,
        'cidr' => $network . '/' . $prefix,
        'target' => $scanNetwork . '/' . $scanPrefix,
        'label' => $scanNetwork . '/' . $scanPrefix . ' sugerido para ' . $ip,
    );
}

function parse_arp_hosts()
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $output = safe_shell_output('arp -a');
    $lines = preg_split("/\r\n|\n|\r/", $output);
    $items = array();
    $interfaceIp = '';

    foreach ($lines as $line) {
        if (preg_match('/Interfaz:\s*(\d{1,3}(?:\.\d{1,3}){3})/i', $line, $matches)) {
            $interfaceIp = $matches[1];
            continue;
        }

        if (!preg_match('/(\d{1,3}(?:\.\d{1,3}){3})\s+([0-9a-f\-]{17})/i', $line, $matches)) {
            continue;
        }

        $ip = $matches[1];
        $mac = strtolower($matches[2]);

        if (!is_host_candidate_ip($ip)) {
            continue;
        }

        if ($mac === 'ff-ff-ff-ff-ff-ff') {
            continue;
        }

        if ($ip === $interfaceIp) {
            continue;
        }

        $items[] = array(
            'interface_ip' => $interfaceIp,
            'ip' => $ip,
            'mac' => $mac,
        );
    }

    $cache = $items;

    return $cache;
}

function detect_local_networks()
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $networks = array();
    $ipconfig = safe_shell_output('ipconfig');

    if ($ipconfig !== '') {
        $lines = preg_split("/\r\n|\n|\r/", $ipconfig);
        $currentIp = '';

        foreach ($lines as $line) {
            if (preg_match('/IPv4[^:]*:\s*(\d{1,3}(?:\.\d{1,3}){3})/i', $line, $matches)) {
                $currentIp = $matches[1];
                continue;
            }

            if ($currentIp !== '' && preg_match('/(subred|mask)/i', $line) && preg_match('/(\d{1,3}(?:\.\d{1,3}){3})/', $line, $matches)) {
                $network = build_network_info($currentIp, $matches[1]);
                if ($network !== null) {
                    $networks[$network['target']] = $network;
                }
                $currentIp = '';
            }
        }
    }

    $arpHosts = parse_arp_hosts();

    foreach ($arpHosts as $host) {
        if (!is_valid_ipv4($host['interface_ip'])) {
            continue;
        }

        $network = build_network_info($host['interface_ip'], '255.255.255.0');
        if ($network !== null) {
            $networks[$network['target']] = $network;
        }
    }

    $fallbackIps = @gethostbynamel(gethostname());

    if (is_array($fallbackIps)) {
        foreach ($fallbackIps as $ip) {
            $network = build_network_info($ip, '255.255.255.0');
            if ($network !== null) {
                $networks[$network['target']] = $network;
            }
        }
    }

    $cache = array_values($networks);

    return $cache;
}

function default_discovery_target()
{
    $networks = detect_local_networks();

    if (!empty($networks)) {
        return $networks[0]['target'];
    }

    return '192.168.1.0/24';
}

function default_scan_ports_text()
{
    return '80,81,82,83,88,443,554,8000,8001,8080,8081,8086,8090,8443,8554,8899,10554';
}

function parse_scan_ports($text)
{
    $text = trim((string) $text);
    $pieces = preg_split('/\s*,\s*/', $text);
    $ports = array();

    foreach ($pieces as $piece) {
        if ($piece === '') {
            continue;
        }

        $port = (int) $piece;
        if ($port < 1 || $port > 65535) {
            continue;
        }

        if (!in_array($port, $ports, true)) {
            $ports[] = $port;
        }
    }

    if (empty($ports)) {
        $ports = array(80, 81, 88, 554, 8080, 8000, 8081, 8554, 10554);
    }

    return $ports;
}

function resolve_scan_target($target, $limit)
{
    $target = trim((string) $target);
    $limit = (int) $limit;

    if ($limit < 1) {
        $limit = 64;
    }

    if ($target === '') {
        $target = default_discovery_target();
    }

    $hosts = array();
    $rangeStart = null;
    $rangeEnd = null;
    $rangeLabel = $target;
    $truncated = false;
    $total = 0;

    if (strpos($target, '/') !== false) {
        list($baseIp, $prefix) = explode('/', $target, 2);
        $baseIp = trim($baseIp);
        $prefix = (int) trim($prefix);

        if (!is_valid_ipv4($baseIp)) {
            return array(
                'input' => $target,
                'hosts' => array(),
                'count' => 0,
                'start' => null,
                'end' => null,
                'label' => $rangeLabel,
                'truncated' => false,
            );
        }

        if ($prefix < 0) {
            $prefix = 0;
        }
        if ($prefix > 32) {
            $prefix = 32;
        }

        $mask = prefix_to_mask($prefix);
        $network = network_from_ip_mask($baseIp, $mask);
        $networkNumber = ip_to_number($network);
        $size = pow(2, 32 - $prefix);
        $first = $networkNumber;
        $last = $networkNumber + $size - 1;

        if ($prefix <= 30) {
            $first += 1;
            $last -= 1;
        }

        if ($last < $first) {
            $last = $first;
        }

        $rangeStart = $first;
        $rangeEnd = $last;
        $total = ($rangeEnd - $rangeStart) + 1;

        for ($i = $rangeStart; $i <= $rangeEnd && count($hosts) < $limit; $i++) {
            $hosts[] = number_to_ip($i);
        }

        $truncated = $total > count($hosts);
        $rangeLabel = $network . '/' . $prefix;
    } elseif (strpos($target, '-') !== false) {
        list($start, $end) = explode('-', $target, 2);
        $start = trim($start);
        $end = trim($end);

        if (strpos($end, '.') === false && is_valid_ipv4($start)) {
            $parts = explode('.', $start);
            $end = $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.' . (int) $end;
        }

        if (!is_valid_ipv4($start) || !is_valid_ipv4($end)) {
            return array(
                'input' => $target,
                'hosts' => array(),
                'count' => 0,
                'start' => null,
                'end' => null,
                'label' => $rangeLabel,
                'truncated' => false,
            );
        }

        $rangeStart = ip_to_number($start);
        $rangeEnd = ip_to_number($end);

        if ($rangeEnd < $rangeStart) {
            $temp = $rangeStart;
            $rangeStart = $rangeEnd;
            $rangeEnd = $temp;
        }

        $total = ($rangeEnd - $rangeStart) + 1;

        for ($i = $rangeStart; $i <= $rangeEnd && count($hosts) < $limit; $i++) {
            $candidate = number_to_ip($i);
            if (is_host_candidate_ip($candidate)) {
                $hosts[] = $candidate;
            }
        }

        $truncated = $total > count($hosts);
        $rangeLabel = number_to_ip($rangeStart) . ' - ' . number_to_ip($rangeEnd);
    } elseif (is_valid_ipv4($target)) {
        $number = ip_to_number($target);
        $hosts[] = $target;
        $rangeStart = $number;
        $rangeEnd = $number;
        $rangeLabel = $target;
        $total = 1;
    }

    return array(
        'input' => $target,
        'hosts' => $hosts,
        'count' => $total,
        'start' => $rangeStart,
        'end' => $rangeEnd,
        'label' => $rangeLabel,
        'truncated' => $truncated,
    );
}

function ip_matches_scan_target($ip, $targetInfo)
{
    if (!is_valid_ipv4($ip) || empty($targetInfo) || $targetInfo['start'] === null || $targetInfo['end'] === null) {
        return false;
    }

    $number = ip_to_number($ip);

    return $number >= $targetInfo['start'] && $number <= $targetInfo['end'];
}

function known_hosts_for_target($targetInfo)
{
    $hosts = array();
    $arpHosts = parse_arp_hosts();

    foreach ($arpHosts as $entry) {
        if (ip_matches_scan_target($entry['ip'], $targetInfo)) {
            $hosts[$entry['ip']] = $entry['ip'];
        }
    }

    $items = array_values($hosts);
    sort($items);

    return $items;
}

function sample_hosts_across_target($targetInfo, $limit)
{
    $limit = (int) $limit;

    if ($limit < 1 || empty($targetInfo) || $targetInfo['start'] === null || $targetInfo['end'] === null) {
        return array();
    }

    $start = (float) $targetInfo['start'];
    $end = (float) $targetInfo['end'];

    if ($end < $start) {
        return array();
    }

    $total = ($end - $start) + 1;
    $hosts = array();

    if ($total <= $limit) {
        for ($i = $start; $i <= $end; $i++) {
            $candidate = number_to_ip($i);
            if (is_host_candidate_ip($candidate)) {
                $hosts[$candidate] = $candidate;
            }
        }

        return array_values($hosts);
    }

    $step = ($limit > 1) ? (($total - 1) / ($limit - 1)) : 0;

    for ($i = 0; $i < $limit; $i++) {
        $number = round($start + ($step * $i));

        if ($number < $start) {
            $number = $start;
        } elseif ($number > $end) {
            $number = $end;
        }

        $candidate = number_to_ip($number);

        if (is_host_candidate_ip($candidate)) {
            $hosts[$candidate] = $candidate;
        }
    }

    return array_values($hosts);
}

function append_unique_hosts(&$hosts, $candidates)
{
    foreach ($candidates as $candidate) {
        if (!in_array($candidate, $hosts, true)) {
            $hosts[] = $candidate;
        }
    }
}

function tcp_port_probe($host, $port, $timeout)
{
    $errno = 0;
    $errstr = '';
    $start = microtime(true);
    $stream = @stream_socket_client('tcp://' . $host . ':' . (int) $port, $errno, $errstr, (float) $timeout);

    if (is_resource($stream)) {
        fclose($stream);

        return array(
            'open' => true,
            'latency_ms' => round((microtime(true) - $start) * 1000),
            'error' => '',
        );
    }

    return array(
        'open' => false,
        'latency_ms' => null,
        'error' => $errstr,
    );
}

function batch_open_ports($hosts, $ports, $timeout, $chunkSize)
{
    $results = array();
    $tasks = array();

    foreach ($hosts as $host) {
        foreach ($ports as $port) {
            $tasks[] = array(
                'host' => $host,
                'port' => (int) $port,
            );
        }
    }

    if (!function_exists('socket_create') || !function_exists('socket_select')) {
        foreach ($tasks as $task) {
            $probe = tcp_port_probe($task['host'], $task['port'], $timeout);
            if ($probe['open']) {
                if (!isset($results[$task['host']])) {
                    $results[$task['host']] = array();
                }
                $results[$task['host']][] = $task['port'];
            }
        }

        return $results;
    }

    $offset = 0;
    $chunkSize = (int) $chunkSize;
    if ($chunkSize < 1) {
        $chunkSize = 64;
    }

    while ($offset < count($tasks)) {
        $chunk = array_slice($tasks, $offset, $chunkSize);
        $offset += $chunkSize;
        $pending = array();

        foreach ($chunk as $task) {
            $socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

            if ($socket === false) {
                continue;
            }

            @socket_set_nonblock($socket);
            $connected = @socket_connect($socket, $task['host'], $task['port']);

            if ($connected) {
                if (!isset($results[$task['host']])) {
                    $results[$task['host']] = array();
                }
                $results[$task['host']][] = $task['port'];
                @socket_close($socket);
                continue;
            }

            $error = @socket_last_error($socket);

            if (!in_array($error, array(10035, 115, 114, 36), true)) {
                @socket_close($socket);
                continue;
            }

            $pending[(int) $socket] = array(
                'socket' => $socket,
                'host' => $task['host'],
                'port' => $task['port'],
            );
        }

        $batchStarted = microtime(true);

        while (!empty($pending) && (microtime(true) - $batchStarted) < (float) $timeout) {
            $read = null;
            $write = array();
            $except = array();

            foreach ($pending as $item) {
                $write[] = $item['socket'];
                $except[] = $item['socket'];
            }

            $remaining = (float) $timeout - (microtime(true) - $batchStarted);
            if ($remaining <= 0) {
                break;
            }

            $sec = (int) floor($remaining);
            $usec = (int) (($remaining - $sec) * 1000000);
            $changed = @socket_select($read, $write, $except, $sec, $usec);

            if ($changed === false) {
                break;
            }

            foreach ($write as $socket) {
                $id = (int) $socket;

                if (!isset($pending[$id])) {
                    continue;
                }

                $error = @socket_get_option($socket, SOL_SOCKET, SO_ERROR);

                if ((int) $error === 0) {
                    if (!isset($results[$pending[$id]['host']])) {
                        $results[$pending[$id]['host']] = array();
                    }
                    $results[$pending[$id]['host']][] = $pending[$id]['port'];
                }

                @socket_close($socket);
                unset($pending[$id]);
            }

            foreach ($except as $socket) {
                $id = (int) $socket;

                if (isset($pending[$id])) {
                    @socket_close($socket);
                    unset($pending[$id]);
                }
            }
        }

        foreach ($pending as $item) {
            @socket_close($item['socket']);
        }
    }

    foreach ($results as $host => $hostPorts) {
        $results[$host] = array_values(array_unique($hostPorts));
        sort($results[$host]);
    }

    return $results;
}

function simple_http_probe($url, $timeout, $maxBytes)
{
    $context = stream_context_create(array(
        'http' => array(
            'method' => 'GET',
            'timeout' => (float) $timeout,
            'ignore_errors' => true,
            'header' => "User-Agent: 390Eyes-LAN-Monitor/1.0\r\nConnection: close\r\n",
        ),
        'ssl' => array(
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ),
    ));

    $handle = @fopen($url, 'rb', false, $context);

    if (!is_resource($handle)) {
        return array(
            'ok' => false,
            'status' => 0,
            'content_type' => '',
            'headers' => array(),
            'body' => '',
        );
    }

    $metadata = stream_get_meta_data($handle);
    $headers = remote_headers_from_meta($metadata);
    $status = remote_status_code($headers);
    $contentType = remote_content_type($headers, '');
    $body = '';

    if ($maxBytes > 0) {
        while (!feof($handle) && strlen($body) < $maxBytes) {
            $chunk = fread($handle, min(1024, $maxBytes - strlen($body)));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $body .= $chunk;
        }
    }

    fclose($handle);

    return array(
        'ok' => true,
        'status' => $status,
        'content_type' => $contentType,
        'headers' => $headers,
        'body' => $body,
    );
}

function detect_vendor_hint($text)
{
    $text = strtolower((string) $text);
    $map = array(
        'nexht' => 'NexHT',
        'nexxt' => 'Nexxt',
        'hikvision' => 'Hikvision',
        'dahua' => 'Dahua',
        'netsurveillance' => 'NetSurveillance',
        'xmeye' => 'XMEye',
        'v380' => 'V380',
        'ycc365' => 'YCC365',
        'cloudedge' => 'CloudEdge',
        'ajcloud' => 'AJCloud',
        'ipc360' => 'IPC360',
        'tuya' => 'Tuya',
        'smart life' => 'Smart Life',
        'throughtek' => 'ThroughTek',
        'tutk' => 'ThroughTek',
        'hipcam' => 'HiP2P',
        'ipcam' => 'IPCam',
        'ip camera' => 'Camara IP',
        'network camera' => 'Network Camera',
        'baby monitor' => 'Baby Monitor',
        'onvif' => 'ONVIF',
        'uc-httpd' => 'Embedded Camera',
        'webcam' => 'Webcam',
    );

    foreach ($map as $needle => $label) {
        if (strpos($text, $needle) !== false) {
            return $label;
        }
    }

    return '';
}

function discovery_confidence_label($score)
{
    if ($score >= 8) {
        return 'Alta';
    }

    if ($score >= 5) {
        return 'Media';
    }

    return 'Baja';
}

function xml_tag_value($xml, $tag)
{
    $pattern = '/<(?:[a-z0-9_]+:)?' . preg_quote($tag, '/') . '>(.*?)<\/(?:[a-z0-9_]+:)?' . preg_quote($tag, '/') . '>/is';

    if (preg_match($pattern, $xml, $matches)) {
        return trim($matches[1]);
    }

    return '';
}

function is_likely_http_port($port)
{
    return in_array((int) $port, array(80, 81, 82, 83, 88, 443, 8000, 8001, 8080, 8081, 8086, 8090, 8443, 8888, 8899), true);
}

function build_base_url($scheme, $host, $port)
{
    $base = strtolower($scheme) . '://' . $host;

    if ((strtolower($scheme) === 'http' && (int) $port !== 80) || (strtolower($scheme) === 'https' && (int) $port !== 443)) {
        $base .= ':' . (int) $port;
    }

    return $base;
}

function http_probe_schemes_for_port($port)
{
    $port = (int) $port;

    if (in_array($port, array(443, 8443), true)) {
        return array('https', 'http');
    }

    return array('http', 'https');
}

function has_camera_http_marker($text)
{
    $text = strtolower((string) $text);

    if ($text === '') {
        return false;
    }

    $markers = array(
        'network camera',
        'ip camera',
        'ipcam',
        'onvif',
        'rtsp',
        'mjpeg',
        'videostream',
        'snapshot',
        'surveillance',
        'nvr',
        'dvr',
        'hikvision',
        'dahua',
        'netsurveillance',
        'xmeye',
        'v380',
        'ycc365',
        'cloudedge',
        'ajcloud',
        'ipc360',
        'hipcam',
        'throughtek',
    );

    foreach ($markers as $marker) {
        if (strpos($text, $marker) !== false) {
            return true;
        }
    }

    return false;
}

function has_router_http_marker($text)
{
    $text = strtolower((string) $text);

    if ($text === '') {
        return false;
    }

    $markers = array(
        'router',
        'wireless',
        'ssid',
        'broadband',
        'port forwarding',
        'dhcp server',
        'access point',
        'radio shack',
        'radioshack',
        'firmware upgrade',
        'wan setup',
        'internet setup',
    );

    foreach ($markers as $marker) {
        if (strpos($text, $marker) !== false) {
            return true;
        }
    }

    return false;
}

function discover_onvif_devices($timeoutSeconds)
{
    $results = array();

    if (!function_exists('socket_create')) {
        return $results;
    }

    $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

    if ($socket === false) {
        return $results;
    }

    @socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
    if (defined('IP_MULTICAST_TTL')) {
        @socket_set_option($socket, IPPROTO_IP, IP_MULTICAST_TTL, 2);
    }
    @socket_bind($socket, '0.0.0.0', 0);

    $messageId = 'uuid:' . uniqid('', true);
    $probe = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<e:Envelope xmlns:e="http://www.w3.org/2003/05/soap-envelope"'
        . ' xmlns:w="http://schemas.xmlsoap.org/ws/2004/08/addressing"'
        . ' xmlns:d="http://schemas.xmlsoap.org/ws/2005/04/discovery"'
        . ' xmlns:dn="http://www.onvif.org/ver10/network/wsdl">'
        . '<e:Header>'
        . '<w:MessageID>' . $messageId . '</w:MessageID>'
        . '<w:To>urn:schemas-xmlsoap-org:ws:2005:04:discovery</w:To>'
        . '<w:Action>http://schemas.xmlsoap.org/ws/2005/04/discovery/Probe</w:Action>'
        . '</e:Header>'
        . '<e:Body><d:Probe><d:Types>dn:NetworkVideoTransmitter</d:Types></d:Probe></e:Body>'
        . '</e:Envelope>';

    @socket_sendto($socket, $probe, strlen($probe), 0, '239.255.255.250', 3702);

    $start = microtime(true);

    while ((microtime(true) - $start) < (float) $timeoutSeconds) {
        $read = array($socket);
        $write = null;
        $except = null;
        $remaining = (float) $timeoutSeconds - (microtime(true) - $start);

        if ($remaining <= 0) {
            break;
        }

        $sec = (int) floor($remaining);
        $usec = (int) (($remaining - $sec) * 1000000);
        $changed = @socket_select($read, $write, $except, $sec, $usec);

        if ($changed === false || $changed === 0) {
            continue;
        }

        $buffer = '';
        $from = '';
        $port = 0;
        $bytes = @socket_recvfrom($socket, $buffer, 8192, 0, $from, $port);

        if ($bytes === false || $bytes <= 0 || !is_valid_ipv4($from)) {
            continue;
        }

        $xAddrs = xml_tag_value($buffer, 'XAddrs');
        $types = xml_tag_value($buffer, 'Types');
        $urls = array();

        if ($xAddrs !== '') {
            preg_match_all('/https?:\/\/[^\s<]+/i', $xAddrs, $matches);
            if (!empty($matches[0])) {
                $urls = $matches[0];
            }
        }

        $embedUrl = '';
        $resolvedPort = 80;
        $scheme = 'http';

        if (!empty($urls)) {
            $parts = @parse_url($urls[0]);
            if (is_array($parts) && !empty($parts['host'])) {
                $scheme = !empty($parts['scheme']) ? $parts['scheme'] : 'http';
                $resolvedPort = !empty($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
                $embedUrl = build_base_url($scheme, $parts['host'], $resolvedPort) . '/';
            }
        }

        if ($embedUrl === '') {
            $embedUrl = 'http://' . $from . '/';
        }

        $vendor = detect_vendor_hint($buffer . ' ' . $xAddrs . ' ' . $types);
        $key = $from;
        $item = array(
            'ip' => $from,
            'port' => $resolvedPort,
            'vendor' => $vendor !== '' ? $vendor : 'ONVIF',
            'name' => ($vendor !== '' ? $vendor : 'ONVIF') . ' ' . $from,
            'type' => 'iframe',
            'snapshot_url' => '',
            'stream_url' => '',
            'embed_url' => $embedUrl,
            'host' => $from,
            'source' => 'ONVIF',
            'score' => 8,
            'confidence' => 'Alta',
            'evidence' => array(
                'Respuesta ONVIF detectada',
                $types !== '' ? 'Tipos: ' . $types : 'Servicio ONVIF disponible',
            ),
            'note' => 'Dispositivo ONVIF detectado automaticamente.',
        );

        if (isset($results[$key])) {
            $item = merge_discovery_item($results[$key], $item);
        }

        $results[$key] = $item;
    }

    @socket_close($socket);

    return array_values($results);
}

function detect_http_camera_on_port($ip, $port, $timeout)
{
    $schemes = http_probe_schemes_for_port($port);
    $defaultScheme = !empty($schemes) ? $schemes[0] : 'http';
    $defaultBase = build_base_url($defaultScheme, $ip, $port);

    $candidate = array(
        'ip' => $ip,
        'port' => (int) $port,
        'vendor' => '',
        'name' => 'Camara ' . $ip,
        'type' => 'iframe',
        'snapshot_url' => '',
        'stream_url' => '',
        'embed_url' => $defaultBase . '/',
        'host' => $ip,
        'source' => 'HTTP',
        'score' => 0,
        'confidence' => 'Baja',
        'evidence' => array(),
        'note' => '',
    );

    $paths = array(
        array('path' => '/', 'kind' => 'root'),
        array('path' => '/snapshot.jpg', 'kind' => 'snapshot'),
        array('path' => '/ISAPI/Streaming/channels/101/picture', 'kind' => 'snapshot'),
        array('path' => '/cgi-bin/snapshot.cgi', 'kind' => 'snapshot'),
        array('path' => '/image/jpeg.cgi', 'kind' => 'snapshot'),
        array('path' => '/tmpfs/auto.jpg', 'kind' => 'snapshot'),
        array('path' => '/web/mainpage.html', 'kind' => 'root'),
        array('path' => '/doc/page/login.asp', 'kind' => 'root'),
        array('path' => '/super.htm', 'kind' => 'root'),
        array('path' => '/mjpeg', 'kind' => 'stream'),
        array('path' => '/videostream.cgi', 'kind' => 'stream'),
        array('path' => '/cgi-bin/mjpg/video.cgi', 'kind' => 'stream'),
    );

    $interfaceDetected = false;

    foreach ($schemes as $scheme) {
        $base = build_base_url($scheme, $ip, $port);

        foreach ($paths as $pathInfo) {
            $url = $base . $pathInfo['path'];
            $response = simple_http_probe($url, $timeout, 4096);

            if (!$response['ok'] || $response['status'] === 0) {
                continue;
            }

            $contentType = strtolower($response['content_type']);
            $blob = strtolower(implode("\n", $response['headers']) . "\n" . strip_tags($response['body']));
            $vendor = detect_vendor_hint($blob);

            if ($vendor !== '' && $candidate['vendor'] === '') {
                $candidate['vendor'] = $vendor;
                $candidate['name'] = $vendor . ' ' . $ip;
                $candidate['score'] += 2;
                unique_append($candidate['evidence'], 'Firma detectada: ' . $vendor);
            }

            if ($response['status'] === 401 || $response['status'] === 403) {
                unique_append($candidate['evidence'], 'Endpoint protegido por autenticacion');
            }

            if (strpos($contentType, 'image/') !== false) {
                $candidate['snapshot_url'] = $url;
                $candidate['embed_url'] = $base . '/';
                $candidate['type'] = 'snapshot';
                $candidate['score'] += 6;
                unique_append($candidate['evidence'], 'Snapshot HTTP encontrado');
                break 2;
            }

            if (strpos($contentType, 'multipart/') !== false) {
                $candidate['stream_url'] = $url;
                $candidate['embed_url'] = $base . '/';
                $candidate['type'] = 'mjpeg';
                $candidate['score'] += 6;
                unique_append($candidate['evidence'], 'Stream MJPEG encontrado');
                break 2;
            }

            if ($pathInfo['kind'] === 'root' && ($response['status'] === 200 || $response['status'] === 401 || $response['status'] === 403)) {
                $hasCameraMarker = ($vendor !== '') || has_camera_http_marker($blob);
                $hasRouterMarker = ($vendor === '') && has_router_http_marker($blob);

                if ($hasCameraMarker && !$hasRouterMarker) {
                    $candidate['type'] = 'iframe';
                    $candidate['embed_url'] = $url;
                    if (!$interfaceDetected) {
                        $candidate['score'] += 3;
                        $interfaceDetected = true;
                    }
                    unique_append($candidate['evidence'], 'Interfaz web de camara encontrada');
                }
            }
        }
    }

    if ($candidate['score'] < 3) {
        return null;
    }

    if ($candidate['snapshot_url'] === '' && $candidate['stream_url'] === '' && $candidate['embed_url'] !== '') {
        $candidate['type'] = 'iframe';
    }

    if ($candidate['vendor'] === '') {
        $candidate['vendor'] = 'Camara IP';
        $candidate['name'] = 'Camara IP ' . $ip;
    }

    $candidate['confidence'] = discovery_confidence_label($candidate['score']);
    $candidate['note'] = implode(' | ', $candidate['evidence']);

    return $candidate;
}

function rtsp_status_rank($status)
{
    $status = (int) $status;

    if ($status === 200) {
        return 5;
    }

    if ($status === 401 || $status === 403) {
        return 4;
    }

    if ($status === 404 || $status === 454) {
        return 3;
    }

    if ($status > 0) {
        return 2;
    }

    return 0;
}

function probe_rtsp_endpoint($host, $port, $path, $timeout)
{
    $errno = 0;
    $errstr = '';
    $stream = @fsockopen($host, (int) $port, $errno, $errstr, (float) $timeout);

    if (!is_resource($stream)) {
        return array(
            'ok' => false,
            'status' => 0,
            'body' => '',
        );
    }

    $timeoutSeconds = (int) ceil((float) $timeout);
    if ($timeoutSeconds < 1) {
        $timeoutSeconds = 1;
    }

    @stream_set_timeout($stream, $timeoutSeconds);

    $path = trim((string) $path);
    if ($path === '') {
        $path = '/';
    }

    $uri = 'rtsp://' . $host . ':' . (int) $port . $path;
    $request = "OPTIONS " . $uri . " RTSP/1.0\r\n"
        . "CSeq: 1\r\n"
        . "User-Agent: 390Eyes-LAN-Monitor/1.0\r\n"
        . "\r\n";

    @fwrite($stream, $request);

    $body = '';
    while (!feof($stream) && strlen($body) < 4096) {
        $line = fgets($stream, 512);
        if ($line === false) {
            break;
        }

        $body .= $line;

        if (strpos($body, "\r\n\r\n") !== false || strpos($body, "\n\n") !== false) {
            break;
        }
    }

    fclose($stream);

    $status = 0;
    if (preg_match('/RTSP\/1\.0\s+(\d{3})/i', $body, $matches)) {
        $status = (int) $matches[1];
    }

    return array(
        'ok' => $status > 0 || stripos($body, 'RTSP/') !== false,
        'status' => $status,
        'body' => $body,
        'url' => $uri,
    );
}

function detect_rtsp_camera_on_port($ip, $port, $timeout)
{
    $paths = array(
        '/',
        '/live/ch00_1',
        '/stream1',
        '/11',
        '/cam/realmonitor?channel=1&subtype=0',
        '/h264Preview_01_main',
        '/Streaming/Channels/101',
    );
    $best = null;
    $path = '';

    foreach ($paths as $candidatePath) {
        $probe = probe_rtsp_endpoint($ip, $port, $candidatePath, $timeout);

        if (!$probe['ok']) {
            continue;
        }

        if ($best === null || rtsp_status_rank($probe['status']) > rtsp_status_rank($best['status'])) {
            $best = $probe;
            $path = $candidatePath;
        }

        if (in_array($probe['status'], array(200, 401, 403), true)) {
            break;
        }
    }

    if ($best === null) {
        return null;
    }

    $vendor = detect_vendor_hint($best['body']);
    $score = 5;
    $evidence = array('Servicio RTSP detectado');

    if ($vendor !== '') {
        $score += 2;
        $evidence[] = 'Firma detectada: ' . $vendor;
    }

    if (in_array($best['status'], array(200, 401, 403), true)) {
        $score += 2;
    } elseif ($best['status'] > 0) {
        $score += 1;
    }

    if ($best['status'] === 401 || $best['status'] === 403) {
        $evidence[] = 'RTSP requiere autenticacion';
    }

    if ($path !== '/' && $path !== '') {
        $evidence[] = 'Ruta RTSP candidata: ' . $path;
    } else {
        $evidence[] = 'La ruta exacta del stream puede variar segun el modelo';
    }

    return array(
        'ip' => $ip,
        'port' => (int) $port,
        'vendor' => $vendor !== '' ? $vendor : 'Camara RTSP',
        'name' => ($vendor !== '' ? $vendor : 'Camara RTSP') . ' ' . $ip,
        'type' => 'rtsp',
        'snapshot_url' => '',
        'stream_url' => $best['url'],
        'embed_url' => '',
        'host' => $ip,
        'source' => 'RTSP',
        'score' => $score,
        'confidence' => discovery_confidence_label($score),
        'evidence' => $evidence,
        'note' => 'RTSP detectado. Para verlo en navegador necesitas MJPEG, HLS, WebRTC o un reproductor externo.',
    );
}

function merge_discovery_item($left, $right)
{
    $merged = $left;

    if ($right['score'] > $merged['score']) {
        $merged['type'] = $right['type'];
        $merged['port'] = $right['port'];
        $merged['vendor'] = $right['vendor'];
        $merged['name'] = $right['name'];
        $merged['confidence'] = $right['confidence'];
    }

    foreach (array('snapshot_url', 'stream_url', 'embed_url', 'host', 'note') as $field) {
        if ((empty($merged[$field]) || $field === 'note') && !empty($right[$field])) {
            $merged[$field] = $right[$field];
        }
    }

    if (strpos($merged['source'], $right['source']) === false) {
        $merged['source'] .= ' + ' . $right['source'];
    }

    foreach ($right['evidence'] as $evidence) {
        unique_append($merged['evidence'], $evidence);
    }

    if ($right['score'] > $merged['score']) {
        $merged['score'] = $right['score'];
    }

    $merged['confidence'] = discovery_confidence_label($merged['score']);
    $merged['note'] = implode(' | ', $merged['evidence']);

    return $merged;
}

function sort_discovery_items($left, $right)
{
    if ($left['score'] === $right['score']) {
        return strcmp($left['ip'], $right['ip']);
    }

    return ($left['score'] > $right['score']) ? -1 : 1;
}

function discover_cameras($target, $portsText, $mode)
{
    $mode = in_array($mode, array('smart', 'full', 'onvif'), true) ? $mode : 'smart';
    $ports = parse_scan_ports($portsText);
    $scanLimit = ($mode === 'full') ? 256 : 128;
    $targetInfo = resolve_scan_target($target, $scanLimit);
    $items = array();
    $startedAt = microtime(true);
    $onvifCount = 0;
    $rtspCount = 0;

    if ($mode === 'smart' || $mode === 'onvif') {
        $onvifDevices = discover_onvif_devices(2);

        foreach ($onvifDevices as $device) {
            if ($targetInfo['start'] !== null && !ip_matches_scan_target($device['ip'], $targetInfo)) {
                continue;
            }

            $items[$device['ip']] = $device;
            $onvifCount += 1;
        }
    }

    $knownHosts = ($targetInfo['start'] !== null) ? known_hosts_for_target($targetInfo) : array();
    $hostsToScan = array();

    if ($mode === 'full') {
        $hostsToScan = $targetInfo['hosts'];
    } elseif ($mode === 'smart') {
        $hostsToScan = $knownHosts;
        append_unique_hosts($hostsToScan, sample_hosts_across_target($targetInfo, $scanLimit));
    }

    foreach ($items as $device) {
        append_unique_hosts($hostsToScan, array($device['ip']));
    }

    $openPorts = batch_open_ports($hostsToScan, $ports, 0.6, 96);

    foreach ($hostsToScan as $ip) {
        if (empty($openPorts[$ip])) {
            continue;
        }

        foreach ($ports as $port) {
            if (!in_array($port, $openPorts[$ip], true)) {
                continue;
            }

            if (is_likely_http_port($port)) {
                $candidate = detect_http_camera_on_port($ip, $port, 0.8);
                if ($candidate === null) {
                    $candidate = detect_rtsp_camera_on_port($ip, $port, 0.8);
                }
            } else {
                $candidate = detect_rtsp_camera_on_port($ip, $port, 0.8);
                if ($candidate === null) {
                    $candidate = detect_http_camera_on_port($ip, $port, 0.8);
                }
            }

            if ($candidate === null) {
                continue;
            }

            if ($candidate['type'] === 'rtsp') {
                $rtspCount += 1;
            }

            if (isset($items[$ip])) {
                $items[$ip] = merge_discovery_item($items[$ip], $candidate);
            } else {
                $items[$ip] = $candidate;
            }

            if ($items[$ip]['score'] >= 8) {
                break;
            }
        }
    }

    $results = array_values($items);
    usort($results, 'sort_discovery_items');

    return array(
        'requested_target' => $targetInfo['input'],
        'resolved_target' => $targetInfo['label'],
        'mode' => $mode,
        'ports' => $ports,
        'items' => $results,
        'meta' => array(
            'hosts_requested' => $targetInfo['count'],
            'hosts_scanned' => count($hostsToScan),
            'known_hosts' => count($knownHosts),
            'onvif_items' => $onvifCount,
            'rtsp_items' => $rtspCount,
            'truncated' => $targetInfo['truncated'] && $mode !== 'onvif',
            'elapsed_ms' => round((microtime(true) - $startedAt) * 1000),
        ),
    );
}
