# 390Eyes LAN Monitor

Panel web en PHP para ver camaras IP dentro de una red local, sin base de datos y sin dependencias externas.

## Lo que incluye

- Dashboard tipo muro de camaras.
- Filtros por grupo y busqueda.
- Vista ampliada por camara.
- CRUD basico desde `admin.php`.
- Escaneo LAN por ONVIF, ARP, puertos HTTP comunes y servicios RTSP.
- Almacenamiento local en `data/cameras.json`.
- Proxy PHP para snapshots y MJPEG HTTP.
- Comprobacion de disponibilidad por host y puerto.

## Tipos de fuente soportados

- `Snapshot`: una imagen JPG/PNG que se refresca cada cierto tiempo.
- `MJPEG`: stream HTTP multipart que el navegador puede mostrar en un `<img>`.
- `Video`: URL HTTP/MP4/HLS solo si el navegador la soporta directamente.
- `RTSP`: deteccion y registro de streams RTSP aunque el navegador no los reproduzca directo.
- `Iframe`: interfaz web del NVR o de la camara embebida.

## Configuracion

1. Abre `admin.php`.
2. Registra cada camara con su IP local y el tipo de fuente que expone.
3. Si la camara requiere usuario y clave, agrégalos en el formulario.
4. Activa `Usar proxy PHP` cuando necesites evitar problemas de autenticacion o CORS para snapshots/MJPEG.
5. Desde `admin.php` puedes usar el escaneo automatico para detectar equipos ONVIF, interfaces HTTP probables y servicios RTSP como los que suelen exponer camaras NexHT y similares.

## Limitacion importante

RTSP puro no funciona de forma nativa en el navegador. El panel ahora puede detectarlo y guardarlo, pero si tu dispositivo solo expone RTSP necesitas un servicio local que lo convierta a MJPEG, HLS o WebRTC para verlo en la web.

## Archivos clave

- `index.php`: panel principal.
- `admin.php`: administracion de camaras.
- `proxy.php`: proxy local para snapshots/MJPEG.
- `api/status.php`: estado online/offline.
- `includes/app.php`: funciones comunes y persistencia.
