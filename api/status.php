<?php

require_once dirname(__DIR__) . '/includes/app.php';

header('Content-Type: application/json; charset=utf-8');

$requestedId = isset($_GET['id']) ? trim($_GET['id']) : '';
$timeout = (float) app_config_value('status_timeout', 2);
$cameras = load_cameras();
$results = array();

foreach ($cameras as $camera) {
    if (!$camera['enabled']) {
        continue;
    }

    if ($requestedId !== '' && $camera['id'] !== $requestedId) {
        continue;
    }

    list($host, $port) = camera_status_target($camera);

    if ($host === '' || $port <= 0) {
        $results[] = array(
            'id' => $camera['id'],
            'online' => false,
            'latency_ms' => null,
            'host' => '',
            'port' => 0,
            'message' => 'Sin host definido',
        );
        continue;
    }

    $start = microtime(true);
    $errno = 0;
    $errstr = '';
    $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
    $latency = round((microtime(true) - $start) * 1000);

    if (is_resource($socket)) {
        fclose($socket);
        $results[] = array(
            'id' => $camera['id'],
            'online' => true,
            'latency_ms' => $latency,
            'host' => $host,
            'port' => $port,
            'message' => 'Disponible',
        );
    } else {
        $results[] = array(
            'id' => $camera['id'],
            'online' => false,
            'latency_ms' => null,
            'host' => $host,
            'port' => $port,
            'message' => $errstr !== '' ? $errstr : 'Sin respuesta',
        );
    }
}

echo json_encode(array(
    'checked_at' => date('c'),
    'items' => $results,
));
