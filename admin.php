<?php

require_once __DIR__ . '/includes/app.php';

$config = app_config();
$flash = flash_get();
$formErrors = array();
$editingId = isset($_GET['edit']) ? trim($_GET['edit']) : '';
$cameras = load_cameras();
$formCamera = blank_camera();
$discoveryNetworks = detect_local_networks();
$defaultDiscoveryTarget = default_discovery_target();
$defaultDiscoveryPorts = default_scan_ports_text();

if ($editingId !== '') {
    $found = find_camera($editingId);
    if ($found !== null) {
        $formCamera = $found;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_request();

    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $cameras = load_cameras();

    if ($action === 'save') {
        $formInput = $_POST;
        $formInput['use_proxy'] = isset($_POST['use_proxy']) ? '1' : '0';
        $formInput['enabled'] = isset($_POST['enabled']) ? '1' : '0';
        $formCamera = normalize_camera($formInput);
        $formErrors = validate_camera($formCamera);

        if (empty($formErrors)) {
            upsert_camera($cameras, $formCamera);
            save_cameras($cameras);
            flash_set('Camara guardada correctamente.', 'success');
            redirect_to('admin.php');
        }
    } elseif ($action === 'delete') {
        $deleteId = isset($_POST['id']) ? trim($_POST['id']) : '';

        if ($deleteId !== '' && delete_camera_by_id($cameras, $deleteId)) {
            save_cameras($cameras);
            flash_set('Camara eliminada.', 'success');
        } else {
            flash_set('No se encontro la camara solicitada.', 'error');
        }

        redirect_to('admin.php');
    }
}

$cameras = load_cameras();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="application-name" content="<?php echo h($config['site_name']); ?>">
    <meta name="generator" content="<?php echo h($config['site_name'] . ' ' . app_version()); ?>">
    <title>Admin | <?php echo h($config['site_name'] . ' ' . app_version()); ?></title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="app-shell app-shell--admin">
    <div class="ambient ambient--one"></div>
    <div class="ambient ambient--two"></div>

    <header class="topbar">
        <div>
            <p class="eyebrow">Configuracion</p>
            <h1>Administrar camaras</h1>
            <p class="subtle">Alta, edicion y prueba de fuentes HTTP y RTSP dentro de tu LAN. Version <?php echo h(app_version()); ?>.</p>
        </div>
        <div class="topbar__actions">
            <div class="metric">
                <span class="metric__label">Version</span>
                <strong class="metric__value"><?php echo h(app_version()); ?></strong>
            </div>
            <a class="button button--ghost" href="index.php">Volver al panel</a>
        </div>
    </header>

    <?php if ($flash !== null): ?>
        <div class="alert alert--<?php echo h($flash['type']); ?>"><?php echo h($flash['message']); ?></div>
    <?php endif; ?>

    <?php if (!empty($formErrors)): ?>
        <div class="alert alert--error">
            <?php foreach ($formErrors as $error): ?>
                <div><?php echo h($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <main class="admin-layout">
        <section class="panel panel--span">
            <div class="panel__header">
                <h2>Escaneo automatico LAN</h2>
                <p>Busca camaras por ONVIF, hosts vistos en ARP y una muestra amplia de la subred en puertos HTTP, HTTPS y RTSP comunes.</p>
            </div>

            <form class="discover-form" id="discoverForm">
                <div class="form-grid">
                    <label>
                        <span>Rango o subred</span>
                        <input type="text" name="target" value="<?php echo h($defaultDiscoveryTarget); ?>" placeholder="192.168.1.0/24 o 192.168.1.10-60">
                    </label>
                    <label>
                        <span>Puertos HTTP/RTSP</span>
                        <input type="text" name="ports" value="<?php echo h($defaultDiscoveryPorts); ?>" placeholder="80,81,88,443,554,6668,7000,8000,8080,8443">
                    </label>
                </div>

                <label>
                    <span>Modo de busqueda</span>
                    <select name="mode">
                        <option value="smart">ONVIF + ARP + HTTP/RTSP comunes</option>
                        <option value="full">Barrido HTTP/RTSP del rango</option>
                        <option value="onvif">Solo ONVIF multicast</option>
                    </select>
                </label>

                <?php if (!empty($discoveryNetworks)): ?>
                    <div class="discover-network-list">
                        <?php foreach ($discoveryNetworks as $network): ?>
                            <button class="chip" type="button" data-discovery-target="<?php echo h($network['target']); ?>"><?php echo h($network['target']); ?></button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="form-help">
                    <strong>Consejo:</strong> primero usa el modo recomendado. Si no aparece la camara, agrega su puerto web o RTSP manualmente y luego prueba el barrido completo. Si ves dispositivos propietarios tipo Tuya o Smart Life, el equipo esta en la LAN pero probablemente no expone video web directo y necesitara RTSP, ONVIF o un bridge adicional para verse desde este panel.
                </div>

                <div class="form-actions">
                    <button class="button" type="submit" id="discoverSubmit">Escanear camaras</button>
                </div>
            </form>

            <div class="discover-summary" id="discoverSummary">Sin escaneo ejecutado.</div>
            <div class="discover-results" id="discoverResults"></div>
        </section>

        <section class="panel">
            <div class="panel__header">
                <h2 id="cameraFormTitle"><?php echo $formCamera['id'] !== '' ? 'Editar camara' : 'Nueva camara'; ?></h2>
                <p>Compatible con snapshots, MJPEG, video HTTP, RTSP detectado y paginas web del dispositivo.</p>
            </div>

            <form method="post" class="camera-form" id="cameraForm">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?php echo h($formCamera['id']); ?>">

                <label>
                    <span>Nombre</span>
                    <input type="text" name="name" value="<?php echo h($formCamera['name']); ?>" required>
                </label>

                <div class="form-grid">
                    <label>
                        <span>Grupo</span>
                        <input type="text" name="group" value="<?php echo h($formCamera['group']); ?>" placeholder="Exterior, Bodega, Recepcion">
                    </label>
                    <label>
                        <span>Ubicacion</span>
                        <input type="text" name="location" value="<?php echo h($formCamera['location']); ?>" placeholder="Pasillo norte">
                    </label>
                </div>

                <label>
                    <span>Tipo de fuente</span>
                    <select name="type">
                        <?php foreach (camera_type_options() as $type => $label): ?>
                            <option value="<?php echo h($type); ?>"<?php echo $formCamera['type'] === $type ? ' selected' : ''; ?>><?php echo h($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    <span>Snapshot URL</span>
                    <input type="url" name="snapshot_url" value="<?php echo h($formCamera['snapshot_url']); ?>" placeholder="http://192.168.1.20/snapshot.jpg">
                </label>

                <label>
                    <span>Stream URL</span>
                    <input type="text" name="stream_url" value="<?php echo h($formCamera['stream_url']); ?>" placeholder="http://192.168.1.20/mjpeg o rtsp://192.168.1.20:554/">
                </label>

                <label>
                    <span>Embed URL</span>
                    <input type="url" name="embed_url" value="<?php echo h($formCamera['embed_url']); ?>" placeholder="http://192.168.1.20/">
                </label>

                <div class="form-grid">
                    <label>
                        <span>Host</span>
                        <input type="text" name="host" value="<?php echo h($formCamera['host']); ?>" placeholder="192.168.1.20">
                    </label>
                    <label>
                        <span>Puerto</span>
                        <input type="number" name="port" value="<?php echo (int) $formCamera['port']; ?>" min="0" max="65535" placeholder="80">
                    </label>
                </div>

                <div class="form-grid">
                    <label>
                        <span>Usuario</span>
                        <input type="text" name="username" value="<?php echo h($formCamera['username']); ?>" placeholder="admin">
                    </label>
                    <label>
                        <span>Clave</span>
                        <input type="password" name="password" value="<?php echo h($formCamera['password']); ?>" placeholder="123456">
                    </label>
                </div>

                <div class="form-grid">
                    <label>
                        <span>Refresh (segundos)</span>
                        <input type="number" name="refresh_seconds" value="<?php echo (int) $formCamera['refresh_seconds']; ?>" min="2" max="120">
                    </label>
                    <label class="toggle">
                        <span>Opciones</span>
                        <span class="toggle__row">
                            <input type="checkbox" name="use_proxy" value="1"<?php echo $formCamera['use_proxy'] ? ' checked' : ''; ?>>
                            <strong>Usar proxy PHP</strong>
                        </span>
                        <span class="toggle__row">
                            <input type="checkbox" name="enabled" value="1"<?php echo $formCamera['enabled'] ? ' checked' : ''; ?>>
                            <strong>Camara activa</strong>
                        </span>
                    </label>
                </div>

                <label>
                    <span>Nota</span>
                    <textarea name="note" rows="3" placeholder="Ejemplo: entrada principal, lente gran angular"><?php echo h($formCamera['note']); ?></textarea>
                </label>

                <div class="form-help">
                    <strong>Importante:</strong> RTSP puro no se reproduce en el navegador. Ahora el panel si puede detectarlo y guardarlo, pero para verlo necesitas convertirlo a MJPEG, HLS, WebRTC o abrirlo con un reproductor externo.
                </div>

                <div class="form-actions">
                    <button class="button" type="submit">Guardar camara</button>
                    <a class="button button--ghost" href="admin.php">Limpiar</a>
                </div>
            </form>
        </section>

        <section class="panel">
            <div class="panel__header">
                <h2>Camaras registradas</h2>
                <p><?php echo count($cameras); ?> elemento(s) almacenados en JSON local.</p>
            </div>

            <?php if (empty($cameras)): ?>
                <p class="muted">Aun no hay camaras registradas.</p>
            <?php else: ?>
                <div class="camera-list">
                    <?php foreach ($cameras as $camera): ?>
                        <article class="camera-row">
                            <div>
                                <h3><?php echo h($camera['name']); ?></h3>
                                <p><?php echo h($camera['group']); ?> | <?php echo h($camera['location']); ?> | <?php echo h(camera_type_label($camera['type'])); ?></p>
                            </div>
                            <div class="camera-row__actions">
                                <a class="button button--ghost" href="admin.php?edit=<?php echo h($camera['id']); ?>">Editar</a>
                                <form method="post" onsubmit="return confirm('Eliminar esta camara?');">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo h($camera['id']); ?>">
                                    <button class="button button--danger" type="submit">Eliminar</button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <script src="assets/js/admin.js"></script>
    <footer class="app-footer">
        <span><?php echo h($config['site_name'] . ' ' . app_version()); ?></span>
        <span><?php echo h(app_config_value('license_name', 'Apache License 2.0')); ?></span>
        <?php if (app_config_value('repository_url', '') !== ''): ?>
            <a href="<?php echo h(app_config_value('repository_url', '')); ?>" target="_blank" rel="noopener">GitHub</a>
        <?php endif; ?>
    </footer>
</body>
</html>
