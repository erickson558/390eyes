<?php

require_once __DIR__ . '/includes/app.php';

$cameraId = isset($_GET['camera']) ? trim($_GET['camera']) : '';
$mode = isset($_GET['mode']) ? trim($_GET['mode']) : 'snapshot';

if ($cameraId === '') {
    header('HTTP/1.1 400 Bad Request');
    echo 'Camara invalida.';
    exit;
}

$camera = find_camera($cameraId);

if ($camera === null || !$camera['enabled']) {
    header('HTTP/1.1 404 Not Found');
    echo 'Camara no encontrada.';
    exit;
}

if (!$camera['use_proxy']) {
    header('HTTP/1.1 403 Forbidden');
    echo 'El proxy esta deshabilitado para esta camara.';
    exit;
}

$mode = ($mode === 'stream') ? 'stream' : 'snapshot';
$targetUrl = camera_source_url($camera, $mode);

if ($targetUrl === '') {
    header('HTTP/1.1 404 Not Found');
    echo 'La fuente remota no esta configurada.';
    exit;
}

$timeout = ($mode === 'stream') ? 5 : 8;
$context = remote_stream_context($camera, $timeout);
$handle = @fopen($targetUrl, 'rb', false, $context);

if (!$handle) {
    header('HTTP/1.1 502 Bad Gateway');
    echo 'No fue posible abrir la fuente remota.';
    exit;
}

$metadata = stream_get_meta_data($handle);
$headers = remote_headers_from_meta($metadata);
$statusCode = remote_status_code($headers);
$contentType = remote_content_type($headers, $mode === 'stream' ? 'multipart/x-mixed-replace' : 'image/jpeg');

if ($statusCode >= 400) {
    header('HTTP/1.1 502 Bad Gateway');
    echo 'La camara respondio con error HTTP ' . $statusCode . '.';
    fclose($handle);
    exit;
}

header('Content-Type: ' . $contentType);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Accel-Buffering: no');

if ($mode === 'snapshot') {
    echo stream_get_contents($handle);
    fclose($handle);
    exit;
}

ignore_user_abort(true);
@set_time_limit(0);

while (!feof($handle)) {
    $chunk = fread($handle, 8192);

    if ($chunk === false) {
        break;
    }

    echo $chunk;

    if (function_exists('ob_flush')) {
        @ob_flush();
    }
    flush();

    if (connection_aborted()) {
        break;
    }
}

fclose($handle);
