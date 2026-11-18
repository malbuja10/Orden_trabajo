# Sistema Web de Órdenes de Trabajo

Aplicativo Web y API para la app móvil de Órdenes de Trabajo de una empresa pública de
agua potable (acometidas, fugas, adoquinamientos, etc.).

**Software de base:** PHP 8.3, PostgreSQL 17, Apache 2.4, Ubuntu 24.04 y Leaflet (incluido en
`public/assets/leaflet`, sin dependencias externas ni Composer).

## Proceso y páginas

| Página | Archivo | Descripción |
|---|---|---|
| Login | `login.php` | Ingreso con correo y contraseña (supervisores). |
| a) Reclamos | `reclamos.php` | Registro de reclamos: fecha, nombre, dirección, reclamo, teléfono, medidor, observaciones. |
| b) Asignar órdenes | `asignar.php` | Lista de reclamos pendientes + mapa: el supervisor ubica el reclamo y genera la Orden (fecha de asignación, trabajo, grupo de 1 o 2 operadores, observaciones, readoquinamiento). |
| | | En el mapa de asignación hay un buscador de calles y referencias (OpenStreetMap / Nominatim); se configura en `busqueda` de `config.php`. |
| c) Mapa diario | `mapa_diario.php` | Órdenes realizadas en un día (opcionalmente no realizadas y por realizar). |
| d) Reporte | `ordenes.php`, `orden.php` | Órdenes realizadas y no realizadas con filtros, exportación CSV e impresión; detalle de cada orden con foto y mapa. |
| e) Mapa temático | `mapa_tematico.php` | Diario o mensual; tipos de trabajo realizados por color, con leyenda. |
| f) Resumen | `resumen.php` | Resumen mensual (por estado y por tipo de trabajo) y anual. |
| g) Administración | `usuarios.php`, `trabajos.php` | Usuarios (supervisores/operadores) y tipos de trabajo con su color. |

## Base de datos

Se decidió usar **tablas separadas para Reclamos y Órdenes de trabajo**: el reclamo es lo que
reporta el ciudadano y la orden es la asignación a un grupo. Si una orden no se pudo realizar,
el reclamo vuelve a "pendiente" y se genera una nueva orden, conservando el historial.

- `usuarios`: nombre, correo (único), contraseña cifrada, rol (`supervisor` / `operador`).
- `trabajos`: tipos de trabajo y color para el mapa temático.
- `reclamos`: datos del ciudadano, ubicación aproximada y estado (`pendiente`, `asignado`, `atendido`, `anulado`).
- `ordenes_trabajo`: asignación (fecha, trabajo, operadores, observaciones, readoquinamiento) y
  ejecución en campo (fecha/hora, trabajo realizado, detalle, readoquinamiento, coordenadas, foto);
  estado `asignada`, `realizada` o `no_realizada`.
- `ubicacion_medidor`: `medidor` (número de serie), `latitud`, `longitud`, `tiempo` (unix time). Si un reclamo
  indica un medidor que está en esta tabla, aparece ya ubicado en el mapa de asignación con la ubicación
  más reciente de ese medidor (el supervisor puede corregirla arrastrando el marcador).
- `api_tokens`: sesiones de la app móvil.

Esquema completo en [`database/schema.sql`](database/schema.sql).

## Instalación

```bash
sudo apt install apache2 php libapache2-mod-php php-pgsql php-gd postgresql
```

```bash
sudo -u postgres psql -c "CREATE ROLE ordenes LOGIN PASSWORD 'CAMBIAR'" -c "CREATE DATABASE ordenes_trabajo OWNER ordenes"
```

Crear `config/config.local.php` con los datos propios (no se sube a Git):

```php
<?php
return [
    'db' => ['password' => 'CAMBIAR'],
    'empresa' => 'Nombre de la empresa',
    'mapa' => ['lat' => -0.2202, 'lng' => -78.5123, 'zoom' => 14], // centro de la ciudad
];
```

Crear tablas, tipos de trabajo iniciales y el primer supervisor:

```bash
php database/instalar.php supervisor@empresa.gob.ec "Nombre Supervisor" "contraseña-segura"
```

Opcional, solo para pruebas (operadores y órdenes de ejemplo):

```bash
php database/demo.php
```

Apache: el `DocumentRoot` debe apuntar a la carpeta `public/` (las fotos y la configuración
quedan fuera del alcance del navegador) y el usuario de Apache debe poder escribir en `storage/fotos`:

```apache
<VirtualHost *:80>
    DocumentRoot /var/www/Orden_trabajo/public
    <Directory /var/www/Orden_trabajo/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

```bash
sudo chown -R www-data /var/www/Orden_trabajo/storage/fotos
```

Para la descarga de fotos conviene subir en `php.ini` `post_max_size` y `upload_max_filesize`
(p. ej. `64M`). En producción usar HTTPS.

Para desarrollo: `php -S localhost:8080 -t public`.

## API para la app móvil

Todas las respuestas son JSON con `"ok": true|false` (y `"error"` cuando falla).
Las funciones b) y c) requieren la cabecera `Authorization: Bearer <token>`.

### a) Login — `POST /api/login.php`

```json
{"correo": "operador1@demo.local", "password": "demo12345"}
```

Respuesta: `{"ok": true, "token": "…", "expira": "2026-12-14T16:12:03", "usuario": {"id": 2, "nombre": "…", "correo": "…", "rol": "operador"}}`

### b) Cargar órdenes asignadas — `GET /api/ordenes.php`

Devuelve las órdenes en estado `asignada` del operador (como operador 1 o 2) y el catálogo de trabajos:

```json
{
  "ok": true,
  "fecha_servidor": "2026-11-14T16:12:03",
  "ordenes": [{
    "id": 75, "fecha_asignacion": "2026-11-14", "trabajo_id": 2, "trabajo": "Fuga",
    "grupo": ["Juan Pérez", "Luis Andrade"], "observaciones": null, "readoquinamiento": true,
    "latitud": -0.219435, "longitud": -78.5288143,
    "reclamo": {"id": 77, "fecha": "2026-11-14", "nombre": "…", "direccion": "…", "reclamo": "…",
                "telefono": null, "medidor": null, "observaciones": null}
  }],
  "trabajos": [{"id": 1, "nombre": "Acometida", "color": "#1f77b4"}]
}
```

### c) Descargar trabajos realizados — `POST /api/descargar.php`

`application/json` con la foto en base64:

```json
{"ordenes": [
  {"id": 75, "realizada": true, "fecha_realizacion": "2026-11-14T10:35:00", "trabajo_id": 2,
   "detalle": "Se reparó la fuga", "readoquinamiento": true,
   "latitud": -0.21951, "longitud": -78.5287, "foto": "<JPEG/PNG/WebP en base64>"},
  {"id": 76, "realizada": false, "motivo_no_realizada": "No se encontró al propietario"}
]}
```

O bien `multipart/form-data` con el campo `datos` (el mismo JSON, sin `foto`) y un archivo
`foto_<id>` por orden.

Respuesta, con el resultado de cada orden (la app puede borrar localmente las que tengan `ok: true`;
reenviar una orden ya registrada no la duplica):

```json
{"ok": true, "recibidas": 2, "registradas": 2, "resultados": [{"id": 75, "ok": true}, {"id": 76, "ok": true}]}
```

## Estructura

```
config/     configuración (config.local.php para valores propios)
database/   schema.sql, instalar.php, demo.php
public/     páginas Web, assets y api/  (DocumentRoot)
src/        código compartido (conexión, sesión, plantilla, lógica de órdenes, API)
storage/    fotos de los trabajos
```
