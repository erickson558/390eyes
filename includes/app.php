<?php

if (session_id() === '') {
    session_start();
}

date_default_timezone_set(app_config_value('timezone', 'UTC'));

function app_root_path()
{
    return dirname(__DIR__);
}

function app_version_path()
{
    return app_root_path() . '/VERSION';
}

function app_version()
{
    static $version = null;

    if ($version !== null) {
        return $version;
    }

    $version = 'V0.0.0';
    $path = app_version_path();

    if (!file_exists($path)) {
        return $version;
    }

    $content = trim((string) @file_get_contents($path));

    if ($content !== '' && preg_match('/^V\d+\.\d+\.\d+$/', $content)) {
        $version = $content;
    }

    return $version;
}

function app_release_version()
{
    return ltrim(app_version(), 'V');
}

function app_user_agent()
{
    return '390Eyes-LAN-Monitor/' . app_release_version();
}

function app_config()
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $defaults = array(
        'site_name' => '390Eyes LAN Monitor',
        'timezone' => 'UTC',
        'default_refresh_seconds' => 10,
        'status_timeout' => 2,
        'license_name' => 'Apache License 2.0',
        'repository_url' => '',
    );

    $configFile = dirname(__DIR__) . '/config/app.php';
    $loaded = array();

    if (file_exists($configFile)) {
        $candidate = require $configFile;
        if (is_array($candidate)) {
            $loaded = $candidate;
        }
    }

    $config = array_merge($defaults, $loaded);

    return $config;
}

function app_config_value($key, $default)
{
    $config = app_config();
    return isset($config[$key]) ? $config[$key] : $default;
}

function camera_storage_path()
{
    return app_root_path() . '/data/cameras.json';
}

function ensure_camera_storage()
{
    $path = camera_storage_path();
    $directory = dirname($path);

    if (!is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    if (!file_exists($path)) {
        file_put_contents($path, "[]\n");
    }
}

function camera_type_options()
{
    return array(
        'snapshot' => 'Snapshot (JPG/PNG)',
        'mjpeg' => 'MJPEG en vivo',
        'video' => 'Video HTTP compatible con navegador',
        'rtsp' => 'RTSP detectado (requiere conversion web)',
        'iframe' => 'Interfaz web embebida',
    );
}

function camera_type_label($type)
{
    $types = camera_type_options();
    return isset($types[$type]) ? $types[$type] : 'Desconocido';
}

function generate_camera_id()
{
    return 'cam-' . substr(sha1(uniqid(mt_rand(), true)), 0, 12);
}

function normalize_bool($value)
{
    if (is_bool($value)) {
        return $value;
    }

    $value = strtolower(trim((string) $value));

    return in_array($value, array('1', 'true', 'yes', 'on', 'si', 'sí'), true);
}

function normalize_camera($camera)
{
    $types = camera_type_options();
    $defaultRefresh = (int) app_config_value('default_refresh_seconds', 10);
    $type = isset($camera['type']) ? trim((string) $camera['type']) : 'snapshot';

    if (!isset($types[$type])) {
        $type = 'snapshot';
    }

    $refresh = isset($camera['refresh_seconds']) ? (int) $camera['refresh_seconds'] : $defaultRefresh;
    if ($refresh < 2) {
        $refresh = 2;
    }

    return array(
        'id' => !empty($camera['id']) ? trim((string) $camera['id']) : generate_camera_id(),
        'name' => !empty($camera['name']) ? trim((string) $camera['name']) : 'Camara sin nombre',
        'group' => !empty($camera['group']) ? trim((string) $camera['group']) : 'General',
        'location' => !empty($camera['location']) ? trim((string) $camera['location']) : 'LAN',
        'type' => $type,
        'snapshot_url' => isset($camera['snapshot_url']) ? trim((string) $camera['snapshot_url']) : '',
        'stream_url' => isset($camera['stream_url']) ? trim((string) $camera['stream_url']) : '',
        'embed_url' => isset($camera['embed_url']) ? trim((string) $camera['embed_url']) : '',
        'host' => isset($camera['host']) ? trim((string) $camera['host']) : '',
        'port' => isset($camera['port']) ? (int) $camera['port'] : 0,
        'username' => isset($camera['username']) ? trim((string) $camera['username']) : '',
        'password' => isset($camera['password']) ? (string) $camera['password'] : '',
        'refresh_seconds' => $refresh,
        'use_proxy' => isset($camera['use_proxy']) ? normalize_bool($camera['use_proxy']) : true,
        'enabled' => isset($camera['enabled']) ? normalize_bool($camera['enabled']) : true,
        'note' => isset($camera['note']) ? trim((string) $camera['note']) : '',
    );
}

function validate_camera($camera)
{
    $errors = array();

    if ($camera['name'] === '') {
        $errors[] = 'El nombre de la camara es obligatorio.';
    }

    if ($camera['type'] === 'snapshot' && $camera['snapshot_url'] === '' && $camera['stream_url'] === '') {
        $errors[] = 'Para tipo Snapshot debes indicar snapshot URL o stream URL.';
    }

    if (($camera['type'] === 'mjpeg' || $camera['type'] === 'video') && $camera['stream_url'] === '') {
        $errors[] = 'Para MJPEG o Video debes indicar stream URL.';
    }

    if ($camera['type'] === 'rtsp' && $camera['stream_url'] === '') {
        $errors[] = 'Para RTSP debes indicar stream URL.';
    }

    if ($camera['type'] === 'iframe' && $camera['embed_url'] === '') {
        $errors[] = 'Para Interfaz web embebida debes indicar embed URL.';
    }

    if ($camera['refresh_seconds'] < 2) {
        $errors[] = 'El refresco minimo es de 2 segundos.';
    }

    return $errors;
}

function sort_cameras($left, $right)
{
    $leftKey = strtolower($left['group'] . ' ' . $left['name']);
    $rightKey = strtolower($right['group'] . ' ' . $right['name']);

    if ($leftKey === $rightKey) {
        return 0;
    }

    return ($leftKey < $rightKey) ? -1 : 1;
}

function load_cameras()
{
    ensure_camera_storage();

    $content = file_get_contents(camera_storage_path());
    $decoded = json_decode($content, true);

    if (!is_array($decoded)) {
        $decoded = array();
    }

    $cameras = array();

    foreach ($decoded as $camera) {
        if (is_array($camera)) {
            $cameras[] = normalize_camera($camera);
        }
    }

    usort($cameras, 'sort_cameras');

    return $cameras;
}

function save_cameras($cameras)
{
    ensure_camera_storage();

    $normalized = array();
    foreach ($cameras as $camera) {
        if (is_array($camera)) {
            $normalized[] = normalize_camera($camera);
        }
    }

    $json = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    file_put_contents(camera_storage_path(), $json . PHP_EOL, LOCK_EX);
}

function upsert_camera(&$cameras, $camera)
{
    $replaced = false;

    foreach ($cameras as $index => $existing) {
        if ($existing['id'] === $camera['id']) {
            $cameras[$index] = $camera;
            $replaced = true;
            break;
        }
    }

    if (!$replaced) {
        $cameras[] = $camera;
    }
}

function delete_camera_by_id(&$cameras, $id)
{
    foreach ($cameras as $index => $camera) {
        if ($camera['id'] === $id) {
            unset($cameras[$index]);
            $cameras = array_values($cameras);
            return true;
        }
    }

    return false;
}

function find_camera($id)
{
    $cameras = load_cameras();

    foreach ($cameras as $camera) {
        if ($camera['id'] === $id) {
            return $camera;
        }
    }

    return null;
}

function blank_camera()
{
    $camera = normalize_camera(array(
        'id' => '',
        'name' => '',
        'group' => 'General',
        'location' => 'LAN',
        'type' => 'snapshot',
        'snapshot_url' => '',
        'stream_url' => '',
        'embed_url' => '',
        'host' => '',
        'port' => 0,
        'username' => '',
        'password' => '',
        'refresh_seconds' => app_config_value('default_refresh_seconds', 10),
        'use_proxy' => true,
        'enabled' => true,
        'note' => '',
    ));

    $camera['id'] = '';

    return $camera;
}

function camera_source_url($camera, $mode)
{
    if ($mode === 'snapshot') {
        if ($camera['snapshot_url'] !== '') {
            return $camera['snapshot_url'];
        }

        if ($camera['stream_url'] !== '') {
            return $camera['stream_url'];
        }

        return '';
    }

    if ($camera['type'] === 'iframe' && $camera['embed_url'] !== '') {
        return $camera['embed_url'];
    }

    if ($camera['stream_url'] !== '') {
        return $camera['stream_url'];
    }

    return $camera['snapshot_url'];
}

function camera_preview_kind($camera)
{
    if ($camera['type'] === 'iframe') {
        return 'iframe';
    }

    if ($camera['type'] === 'video') {
        return 'video';
    }

    if ($camera['type'] === 'rtsp') {
        return 'notice';
    }

    return 'image';
}

function camera_proxy_url($camera, $mode)
{
    return 'proxy.php?' . http_build_query(array(
        'camera' => $camera['id'],
        'mode' => $mode,
    ));
}

function camera_preview_url($camera)
{
    if ($camera['type'] === 'iframe') {
        return $camera['embed_url'];
    }

    if ($camera['type'] === 'video') {
        return $camera['stream_url'];
    }

    if ($camera['type'] === 'rtsp') {
        return $camera['stream_url'];
    }

    if ($camera['type'] === 'mjpeg') {
        return $camera['use_proxy'] ? camera_proxy_url($camera, 'stream') : $camera['stream_url'];
    }

    return $camera['use_proxy'] ? camera_proxy_url($camera, 'snapshot') : camera_source_url($camera, 'snapshot');
}

function camera_open_url($camera)
{
    if ($camera['type'] === 'iframe') {
        return $camera['embed_url'];
    }

    if ($camera['type'] === 'video') {
        return $camera['stream_url'];
    }

    if ($camera['type'] === 'rtsp') {
        return $camera['stream_url'];
    }

    if ($camera['type'] === 'mjpeg') {
        return $camera['use_proxy'] ? camera_proxy_url($camera, 'stream') : $camera['stream_url'];
    }

    return $camera['use_proxy'] ? camera_proxy_url($camera, 'snapshot') : camera_source_url($camera, 'snapshot');
}

function camera_status_target($camera)
{
    $host = $camera['host'];
    $port = (int) $camera['port'];

    if ($host !== '') {
        if ($port === 0) {
            $port = ($camera['type'] === 'rtsp') ? 554 : 80;
        }

        return array($host, $port);
    }

    $urls = array($camera['stream_url'], $camera['snapshot_url'], $camera['embed_url']);
    foreach ($urls as $url) {
        if ($url === '') {
            continue;
        }

        $parts = @parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            continue;
        }

        $host = $parts['host'];

        if ($port === 0) {
            if (!empty($parts['port'])) {
                $port = (int) $parts['port'];
            } elseif (!empty($parts['scheme']) && strtolower($parts['scheme']) === 'rtsp') {
                $port = 554;
            } elseif (!empty($parts['scheme']) && strtolower($parts['scheme']) === 'https') {
                $port = 443;
            } else {
                $port = 80;
            }
        }

        return array($host, $port);
    }

    return array('', 0);
}

function camera_groups($cameras)
{
    $groups = array();

    foreach ($cameras as $camera) {
        $groups[$camera['group']] = true;
    }

    $names = array_keys($groups);
    sort($names);

    return $names;
}

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function flash_set($message, $type)
{
    $_SESSION['flash_message'] = array(
        'message' => $message,
        'type' => $type,
    );
}

function flash_get()
{
    if (empty($_SESSION['flash_message'])) {
        return null;
    }

    $flash = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);

    return $flash;
}

function csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = sha1(uniqid(mt_rand(), true));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field()
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function verify_csrf_request()
{
    $token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';

    if ($token === '' || !isset($_SESSION['csrf_token']) || $_SESSION['csrf_token'] !== $token) {
        header('HTTP/1.1 400 Bad Request');
        echo 'Token CSRF invalido.';
        exit;
    }
}

function redirect_to($path)
{
    header('Location: ' . $path);
    exit;
}

function remote_auth_header($camera)
{
    if ($camera['username'] === '') {
        return '';
    }

    return 'Authorization: Basic ' . base64_encode($camera['username'] . ':' . $camera['password']);
}

function remote_stream_context($camera, $timeout)
{
    $headers = array(
        'User-Agent: ' . app_user_agent(),
        'Connection: close',
    );

    $authHeader = remote_auth_header($camera);
    if ($authHeader !== '') {
        $headers[] = $authHeader;
    }

    return stream_context_create(array(
        'http' => array(
            'method' => 'GET',
            'timeout' => (int) $timeout,
            'ignore_errors' => true,
            'header' => implode("\r\n", $headers) . "\r\n",
        ),
        'ssl' => array(
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ),
    ));
}

function remote_headers_from_meta($metadata)
{
    if (!is_array($metadata) || empty($metadata['wrapper_data']) || !is_array($metadata['wrapper_data'])) {
        return array();
    }

    return $metadata['wrapper_data'];
}

function remote_status_code($headers)
{
    foreach ($headers as $line) {
        if (stripos($line, 'HTTP/') === 0 && preg_match('/\s(\d{3})\s/', $line, $matches)) {
            return (int) $matches[1];
        }
    }

    return 200;
}

function remote_content_type($headers, $fallback)
{
    foreach ($headers as $line) {
        if (stripos($line, 'Content-Type:') === 0) {
            return trim(substr($line, strlen('Content-Type:')));
        }
    }

    return $fallback;
}

function render_empty_state_tip()
{
    return 'Agrega camaras desde Admin y usa URLs HTTP, JPEG, MJPEG, RTSP detectado o interfaces web del equipo.';
}

require_once __DIR__ . '/discovery.php';
