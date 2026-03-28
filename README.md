# 390Eyes LAN Monitor

`390Eyes LAN Monitor` es un panel web en PHP para descubrir, registrar y visualizar cámaras IP dentro de una LAN sin base de datos ni framework externo.

Version actual: `V1.0.0`  
Licencia: `Apache License 2.0`  
Repositorio: <https://github.com/erickson558/390eyes>

## Qué hace el programa

- Descubre dispositivos de video en la red local por `ONVIF`, `RTSP`, `HTTP` y `HTTPS`.
- Permite registrar cámaras manualmente desde una interfaz de administración.
- Soporta `Snapshot`, `MJPEG`, `video HTTP`, `RTSP detectado` e `iframe` embebido.
- Valida disponibilidad de cada cámara por host y puerto.
- Guarda toda la configuración localmente en `data/cameras.json`.
- Usa un proxy PHP para ayudar con snapshots o streams HTTP cuando hay CORS o autenticación básica.
- Señala dispositivos con protocolo propietario como `Tuya/Smart Life` cuando están en la red pero no exponen video LAN estándar.

## Estado real del descubrimiento

El panel puede descubrir cámaras IP clásicas o equipos que publiquen alguna interfaz local estándar.

No puede reproducir directamente dispositivos que:

- solo trabajen con apps móviles propietarias
- no publiquen `RTSP`, `ONVIF`, `HTTP snapshot` o `MJPEG`
- dependan exclusivamente de nube o túneles del fabricante

En esos casos el escáner los puede marcar como `compatibilidad limitada`, pero no como cámara web reproducible.

## Requisitos

- Windows con EasyPHP o cualquier servidor PHP local equivalente
- PHP con funciones estándar de red habilitadas
- Extensión `sockets` recomendada para mejorar el descubrimiento ONVIF y el escaneo TCP
- Acceso a la misma subred que las cámaras

## Dependencias

El proyecto no usa Composer ni paquetes PHP externos.

Dependencias de ejecución:

- PHP
- navegador moderno
- acceso local a red LAN

Dependencias opcionales para automatización:

- Git
- GitHub CLI (`gh`) para flujos manuales de publicación
- GitHub Actions para releases automáticos al hacer push a `main`

## Estructura

- `index.php`: panel principal
- `admin.php`: alta, edición y escaneo LAN
- `proxy.php`: proxy local para snapshots y MJPEG
- `api/discover.php`: API JSON de descubrimiento
- `api/status.php`: API JSON de salud por host/puerto
- `includes/app.php`: utilidades, persistencia y helpers generales
- `includes/discovery.php`: lógica de descubrimiento de red
- `assets/css/app.css`: estilos
- `assets/js/app.js`: interacciones del dashboard
- `assets/js/admin.js`: interacciones del panel de administración
- `config/app.php`: configuración local
- `VERSION`: versión canónica del producto

## Uso local

1. Abre `admin.php`.
2. Ejecuta un escaneo sobre tu subred.
3. Si la cámara aparece, usa `Usar detección` o registra el equipo manualmente.
4. Si requiere autenticación, agrega usuario y contraseña.
5. Si es un equipo con `RTSP` puro, considera un bridge a `MJPEG`, `HLS` o `WebRTC`.

## Tipos de fuente soportados

- `Snapshot`: imagen JPG/PNG que refresca cada cierto tiempo
- `MJPEG`: stream HTTP multipart compatible con `<img>`
- `Video`: URL HTTP/HLS/MP4 si el navegador la soporta
- `RTSP`: detección y registro de stream, no reproducción nativa en navegador
- `Iframe`: interfaz web embebida del equipo

## Versionado

El proyecto usa versionado semántico con prefijo `V`:

- `Vx.y.z`
- `x`: cambios incompatibles o cambios mayores
- `y`: nuevas funcionalidades compatibles
- `z`: correcciones y ajustes compatibles

Reglas del repositorio:

- cada commit que vaya a `main` debe actualizar `VERSION`
- la versión mostrada en la app, en GitHub y en los releases debe salir del archivo `VERSION`
- el workflow de release falla si se intenta publicar una versión ya usada para otro commit

## Releases automáticos

El repositorio incluye `.github/workflows/release.yml`.

En cada `push` a `main`, el workflow:

1. valida el formato de `VERSION`
2. ejecuta lint de PHP
3. crea el tag de Git con el valor exacto de `VERSION`
4. publica o actualiza el release de GitHub correspondiente

## Desarrollo

- Mantén `VERSION` sincronizado con el cambio real
- Documenta cada release en `CHANGELOG.md`
- No subas `data/*.json` porque contiene datos locales del entorno

## Seguridad y operación

- Usa este panel solo en redes controladas
- No expongas `admin.php` a Internet sin autenticación adicional
- Deshabilita el proxy si no lo necesitas
- Trata credenciales de cámara como secretos de entorno local

## Licencia

Este proyecto se distribuye bajo `Apache License 2.0`. Revisa [LICENSE](LICENSE).
