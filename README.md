# Sistema Web de Órdenes de Trabajo

Aplicativo Web y API para la app móvil de Órdenes de Trabajo de una empresa pública de
agua potable (acometidas, fugas, adoquinamientos, etc.).

**Software de base:** PHP 8.3, PostgreSQL 17, Apache 2.4, Ubuntu 24.04 y Leaflet (incluido en
`public/assets/leaflet`, sin dependencias externas ni Composer).

## Proceso y páginas

1. El supervisor registra el reclamo empezando por la **cuenta**: si existe, se llenan el nombre, la
   dirección y la ubicación en el mapa. Si no existe, se llenan los datos a mano y se ubica el reclamo
   en el mapa (clic o buscador de calles). Cada reclamo es **directamente una Orden de Trabajo pendiente**.
2. Cualquier operador puede realizar las órdenes pendientes (no hay asignación).
3. El supervisor **registra** quién hizo el trabajo (fecha, operador 1 y 2, trabajo realizado,
   observación, readoquinamiento, detalle) y la orden queda **finalizada**. Lo puede hacer en la Web
   o en el campo con la [app móvil](movil/README.md) (solo supervisores), que funciona sin Internet;
   al regresar a la oficina descarga al servidor las órdenes finalizadas.

| Página | Archivo | Descripción |
|---|---|---|
| Login | `login.php` | Ingreso con correo y contraseña (supervisores). |
| a) Reclamos | `reclamos.php` | Cuenta, fecha, nombre, dirección, teléfono, reclamo (tipo de trabajo) y observación (catálogos), ubicación en el mapa. Editar o anular pendientes. |
| b) Registrar órdenes | `registrar.php` | Órdenes pendientes en lista y mapa; se registra fecha de registro, operadores, readoquinamiento y detalle. Muestra los datos de campo recibidos de la app. Permite corregir o reabrir lo registrado. |
| c) Mapa diario | `mapa_diario.php` | Trabajos pendientes (ámbar) y finalizados (verde); filtro todos / pendientes / finalizados y rango de fechas. |
| d) Reporte | `ordenes.php`, `orden.php` | Órdenes con observación, filtros, exportación CSV e impresión; detalle de cada orden con foto y mapa. |
| e) Mapa temático | `mapa_tematico.php` | Tipos de trabajo por color (relleno = finalizado, anillo = pendiente); filtro de estado y rango de fechas. |
| f) Resumen | `resumen.php` | Resumen mensual (por estado y por tipo de trabajo) y anual. |
| g) Administración | `usuarios.php`, `trabajos.php`, `observaciones.php` | Usuarios, tipos de trabajo (con su color) y observaciones. |

En los mapas de Reclamos y Registrar hay un buscador de calles y referencias (OpenStreetMap / Nominatim);
se configura en `busqueda` de `config.php`.

En los mapas, los pendientes se filtran por la fecha del reclamo y los finalizados por la fecha de registro.

## Base de datos

- `usuarios`: nombre, correo (único), contraseña cifrada, rol (`supervisor` / `operador`).
- `trabajos`: tipos de trabajo (son también los "reclamos") y color para el mapa temático.
- `observaciones`: catálogo de observaciones de los reclamos.
- `cuentas`: `cuenta`, `nombre`, `direccion`, `medidor`, `latitud`, `longitud`. Si una cuenta no tiene
  coordenadas, se usa la ubicación más reciente de su medidor en `ubicacion_medidor`.
- `ubicacion_medidor`: `medidor` (número de serie), `latitud`, `longitud`, `tiempo` (unix time).
- `ordenes_trabajo`: una fila por reclamo. Datos del reclamo; registro del trabajo realizado
  (fecha de registro, operadores, readoquinamiento, detalle); datos de campo de la app (fecha y hora,
  coordenadas, foto, detalle). Estado `pendiente`, `finalizada` o `anulada`.
- `api_tokens`: sesiones de la app móvil.

Esquema completo en [`database/schema.sql`](database/schema.sql).

### Carga de cuentas desde CSV

Con las columnas en el orden `cuenta, nombre, direccion, medidor, latitud, longitud` y fila de encabezado
(las coordenadas pueden ir vacías y deben usar punto decimal):

```bash
psql -h localhost -U ordenes -d ordenes_trabajo -c "\copy cuentas (cuenta, nombre, direccion, medidor, latitud, longitud) FROM '/ruta/cuentas.csv' WITH (FORMAT csv, HEADER true)"
```

Para reemplazar todas las cuentas, vacíe antes la tabla con `TRUNCATE cuentas`.

### Migración desde la versión 1

La versión 1 tenía tablas separadas de reclamos y órdenes con asignación. La migración **borra todos
los reclamos, órdenes y fotos** (conserva usuarios, trabajos y ubicación de medidores):

```bash
php database/migrar_v2.php --borrar-datos
```

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

Solo los usuarios con rol `supervisor` pueden usar el API (un operador recibe `403`).
Todas las respuestas son JSON con `"ok": true|false` (y `"error"` cuando falla).
Las funciones b) y c) requieren la cabecera `Authorization: Bearer <token>`.

### a) Login — `POST /api/login.php`

```json
{"correo": "supervisor@empresa.gob.ec", "password": "…"}
```

Respuesta: `{"ok": true, "token": "…", "expira": "2026-12-14T16:12:03", "usuario": {"id": 1, "nombre": "…", "correo": "…", "rol": "supervisor"}}`

### b) Cargar órdenes pendientes — `GET /api/ordenes.php`

Todas las órdenes pendientes y los catálogos para registrarlas (trabajos, observaciones y operadores activos):

```json
{
  "ok": true,
  "fecha_servidor": "2026-11-20T16:12:03",
  "ordenes": [{
    "id": 126, "fecha": "2026-11-17", "cuenta": "1017", "nombre": "…", "direccion": "…",
    "telefono": "…", "trabajo_id": 4, "trabajo": "Cambio de medidor", "observacion_id": null,
    "observacion": null, "latitud": -0.22087, "longitud": -78.51774
  }],
  "trabajos": [{"id": 4, "nombre": "Cambio de medidor", "color": "#2ca02c"}],
  "observaciones": [{"id": 1, "nombre": "Cliente ausente"}],
  "operadores": [{"id": 2, "nombre": "Juan Pérez"}]
}
```

### c) Descargar órdenes finalizadas — `POST /api/descargar.php`

`application/json` con la foto (opcional) en base64:

```json
{"ordenes": [
  {"id": 126, "fecha_registro": "2026-11-20", "operador1_id": 2, "operador2_id": 3,
   "trabajo_id": 4, "observacion_id": null, "readoquinamiento": true, "detalle": "Se cambió el medidor",
   "fecha": "2026-11-20T10:35:00", "latitud": -0.22091, "longitud": -78.51770, "foto": "<JPEG/PNG/WebP en base64>"}
]}
```

O bien `multipart/form-data` con el campo `datos` (el mismo JSON, sin `foto`) y un archivo
`foto_<id>` por orden.

Cada orden queda **finalizada** con las mismas validaciones de la página Registrar órdenes
(`fecha_registro` entre la fecha del reclamo y hoy, operador 1 obligatorio y distinto del 2).
`fecha`, `latitud`, `longitud` y `foto` son los datos de campo (opcionales).
Respuesta, con el resultado de cada orden (la app puede borrar localmente las que tengan `ok: true`).
Si la orden ya estaba finalizada (reenvío, o se registró antes en la Web) no se modifica y se
responde `ok: true` con `"ya_registrada": true`:

```json
{"ok": true, "recibidas": 1, "registradas": 1, "resultados": [{"id": 126, "ok": true}]}
```

## Estructura

```
config/     configuración (config.local.php para valores propios)
database/   schema.sql, instalar.php, demo.php
public/     páginas Web, assets y api/  (DocumentRoot)
src/        código compartido (conexión, sesión, plantilla, lógica de órdenes, API)
storage/    fotos de los trabajos
```
