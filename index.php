<?php

require_once __DIR__ . '/includes/app.php';

$config = app_config();
$allCameras = load_cameras();
$cameras = array();

foreach ($allCameras as $camera) {
    if ($camera['enabled']) {
        $cameras[] = $camera;
    }
}

$groups = camera_groups($cameras);
$enabledCount = count($cameras);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="application-name" content="<?php echo h($config['site_name']); ?>">
    <meta name="generator" content="<?php echo h($config['site_name'] . ' ' . app_version()); ?>">
    <title><?php echo h($config['site_name'] . ' ' . app_version()); ?></title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="app-shell">
    <div class="ambient ambient--one"></div>
    <div class="ambient ambient--two"></div>

    <header class="topbar">
        <div>
            <p class="eyebrow">Monitoreo LAN</p>
            <h1><?php echo h($config['site_name']); ?></h1>
            <p class="subtle">Panel local para visualizar camaras IP dentro de tu red. Version <?php echo h(app_version()); ?>.</p>
        </div>
        <div class="topbar__actions">
            <div class="metric">
                <span class="metric__label">Camaras activas</span>
                <strong class="metric__value"><?php echo $enabledCount; ?></strong>
            </div>
            <div class="metric">
                <span class="metric__label">Estado</span>
                <strong class="metric__value" id="summaryOnline">-</strong>
            </div>
            <div class="metric">
                <span class="metric__label">Version</span>
                <strong class="metric__value"><?php echo h(app_version()); ?></strong>
            </div>
            <a class="button button--ghost" href="admin.php">Admin</a>
        </div>
    </header>

    <section class="toolbar">
        <label class="search">
            <span>Buscar</span>
            <input type="search" id="searchInput" placeholder="Nombre, grupo o ubicacion">
        </label>
        <div class="groups" id="groupFilters">
            <button class="chip is-active" type="button" data-group="all">Todas</button>
            <?php foreach ($groups as $group): ?>
                <button class="chip" type="button" data-group="<?php echo h($group); ?>"><?php echo h($group); ?></button>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if (empty($cameras)): ?>
        <section class="empty-state">
            <h2>No hay camaras configuradas</h2>
            <p><?php echo h(render_empty_state_tip()); ?></p>
            <a class="button" href="admin.php">Ir a configuracion</a>
        </section>
    <?php else: ?>
        <main class="camera-grid" id="cameraGrid">
            <?php foreach ($cameras as $camera): ?>
                <?php
                $previewKind = camera_preview_kind($camera);
                $previewUrl = camera_preview_url($camera);
                $openUrl = camera_open_url($camera);
                list($host, $port) = camera_status_target($camera);
                ?>
                <article
                    class="camera-card"
                    data-name="<?php echo h(strtolower($camera['name'])); ?>"
                    data-group="<?php echo h($camera['group']); ?>"
                    data-location="<?php echo h(strtolower($camera['location'])); ?>"
                    data-camera-type="<?php echo h($camera['type']); ?>"
                    data-view-kind="<?php echo h($previewKind); ?>"
                    data-view-url="<?php echo h($previewUrl); ?>"
                    data-view-refresh="<?php echo (int) $camera['refresh_seconds']; ?>"
                    data-open-url="<?php echo h($openUrl); ?>"
                >
                    <div class="camera-card__header">
                        <div>
                            <p class="camera-card__group"><?php echo h($camera['group']); ?></p>
                            <h2><?php echo h($camera['name']); ?></h2>
                            <p class="camera-card__location"><?php echo h($camera['location']); ?></p>
                        </div>
                        <span class="status-badge status-badge--pending" data-status-id="<?php echo h($camera['id']); ?>">Comprobando</span>
                    </div>

                    <div class="camera-card__media">
                        <?php if ($previewKind === 'iframe'): ?>
                            <iframe src="<?php echo h($previewUrl); ?>" title="<?php echo h($camera['name']); ?>" loading="lazy"></iframe>
                        <?php elseif ($previewKind === 'video'): ?>
                            <video src="<?php echo h($previewUrl); ?>" muted controls playsinline preload="metadata"></video>
                        <?php elseif ($previewKind === 'notice'): ?>
                            <div class="camera-card__placeholder">
                                <strong>RTSP detectado</strong>
                                <span>Convierte a MJPEG, HLS o WebRTC para vista web.</span>
                            </div>
                        <?php elseif ($previewUrl !== ''): ?>
                            <img
                                class="camera-feed"
                                src="<?php echo h($previewUrl); ?>"
                                alt="<?php echo h($camera['name']); ?>"
                                data-base-src="<?php echo h($previewUrl); ?>"
                                data-refresh-seconds="<?php echo (int) $camera['refresh_seconds']; ?>"
                                data-feed-mode="<?php echo h($camera['type']); ?>"
                            >
                        <?php else: ?>
                            <div class="camera-card__placeholder">Sin fuente disponible</div>
                        <?php endif; ?>
                    </div>

                    <div class="camera-card__meta">
                        <span class="tag"><?php echo h(camera_type_label($camera['type'])); ?></span>
                        <?php if ($host !== ''): ?>
                            <span class="tag"><?php echo h($host . ':' . $port); ?></span>
                        <?php endif; ?>
                        <span class="tag">Refresh <?php echo (int) $camera['refresh_seconds']; ?>s</span>
                    </div>

                    <?php if ($camera['note'] !== ''): ?>
                        <p class="camera-card__note"><?php echo h($camera['note']); ?></p>
                    <?php endif; ?>

                    <div class="camera-card__actions">
                        <button class="button button--ghost" type="button" data-open-viewer>Ampliar</button>
                        <?php if ($openUrl !== ''): ?>
                            <a class="button button--ghost" href="<?php echo h($openUrl); ?>" target="_blank">Abrir fuente</a>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </main>
    <?php endif; ?>

    <div class="viewer" id="viewer" hidden>
        <div class="viewer__backdrop" data-close-viewer></div>
        <div class="viewer__dialog">
            <button class="viewer__close" type="button" data-close-viewer>&times;</button>
            <div class="viewer__content" id="viewerContent"></div>
        </div>
    </div>

    <script src="assets/js/app.js"></script>
    <footer class="app-footer">
        <span><?php echo h($config['site_name'] . ' ' . app_version()); ?></span>
        <span><?php echo h(app_config_value('license_name', 'Apache License 2.0')); ?></span>
        <?php if (app_config_value('repository_url', '') !== ''): ?>
            <a href="<?php echo h(app_config_value('repository_url', '')); ?>" target="_blank" rel="noopener">GitHub</a>
        <?php endif; ?>
    </footer>
</body>
</html>
