<?php

require_once dirname(__DIR__) . '/includes/app.php';

header('Content-Type: application/json; charset=utf-8');

$target = isset($_GET['target']) ? trim($_GET['target']) : '';
$ports = isset($_GET['ports']) ? trim($_GET['ports']) : default_scan_ports_text();
$mode = isset($_GET['mode']) ? trim($_GET['mode']) : 'smart';

@set_time_limit(0);

$result = discover_cameras($target, $ports, $mode);

echo json_encode($result);
