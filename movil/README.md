# App móvil de Órdenes de Trabajo (Flutter / Android)

App para que el **supervisor** registre en el campo las Órdenes de Trabajo finalizadas, con el
[Sistema Web de Órdenes de Trabajo](../README.md). Trabaja contra el API del sistema
(`/api/login.php`, `/api/ordenes.php`, `/api/descargar.php`) y guarda todo en una base SQLite
local, de modo que en el campo se puede seguir registrando sin Internet.

Cada reclamo es una orden **pendiente**; no hay asignación y cualquier operador puede hacer el
trabajo. El supervisor la registra como **finalizada** (en la app o en la Web) indicando quién la hizo.

## Funciones

| Función | Internet | Descripción |
|---|---|---|
| Login | Sí (la primera vez) | Solo supervisores. Sin conexión, entra con la contraseña del último ingreso en línea de ese usuario en el teléfono. |
| Cargar pendientes | Sí | Trae todas las órdenes pendientes y los catálogos (trabajos, observaciones, operadores). No pisa lo ya registrado en el teléfono. |
| Lista de órdenes | No | Búsqueda (N.º, cuenta, nombre, dirección) y filtro Pendientes / Finalizadas. |
| Registrar finalizada | No | Fecha del trabajo, operador 1 y 2, trabajo realizado (combo), observación (combo), detalle (si existe) y readoquinamiento. Opcional: ubicación GPS y foto. Se puede **editar** o volver a pendiente mientras no se descargue. |
| Mapa | No (*) | Órdenes pendientes y finalizadas, con filtro por estado y «mi ubicación». |
| Descargar finalizadas | Sí | Envía al servidor cada orden finalizada; en el servidor queda finalizada igual que si se registrara en la Web. |

(*) Los marcadores salen de la base local. El fondo del mapa (OpenStreetMap) necesita conexión o
haber visto antes esa zona (queda en caché).

Detalles:

- La fecha del trabajo debe estar entre la fecha del reclamo y hoy (misma regla que la Web). La app
  propone la fecha y los operadores del último registro.
- Si la orden ya se registró en la Web antes de descargarla, el servidor no la modifica: la app la
  marca como descargada con ese aviso.
- Una orden rechazada (p. ej. anulada en la Web) queda con el error del servidor; se puede corregir
  y volver a descargar, o volver a pendiente para que se retire en la siguiente carga.
- Las órdenes ya descargadas quedan de consulta y se retiran del teléfono en la siguiente carga.

## Estructura

```
lib/
  main.dart               arranque
  modelos.dart            Usuario, Trabajo, Orden
  datos/api.dart          cliente del API
  datos/base_datos.dart   SQLite (sqflite)
  datos/repositorio.dart  sesión, login sin conexión, cargar y descargar
  pantallas/              login, inicio (lista + mapa), orden (registro)
test/                     pruebas de lógica, de pantallas y contra el servidor real
```

## Compilar

Requiere Flutter 3.50 o superior y el SDK de Android.

```bash
flutter pub get
```

```bash
flutter build apk --release --dart-define=API_URL=https://ordenes.empresa.gob.ec
```

`API_URL` es la dirección por defecto del sistema Web (la carpeta `public/`); también se puede
escribir o cambiar en la pantalla de login («Servidor»).

Antes de publicar: definir la firma de *release* en `android/app/build.gradle.kts` (ahora usa la
de depuración) y, si el servidor ya tiene HTTPS, quitar `android:usesCleartextTraffic="true"` de
`android/app/src/main/AndroidManifest.xml`.

## Pruebas

```bash
flutter test
```

Contra un servidor real (FINALIZA dos órdenes pendientes; usar solo con datos de prueba). El
teléfono y el servidor deben estar en la misma zona horaria:

```bash
TZ=America/Guayaquil OT_API_URL=http://127.0.0.1:8080 OT_CORREO=supervisor@demo.local OT_CLAVE=... OT_OPERADOR=operador1@demo.local OT_CLAVE_OPERADOR=demo12345 flutter test test/integracion_test.dart
```
