# App móvil de Órdenes de Trabajo (Flutter / Android)

App para los operadores del [Sistema Web de Órdenes de Trabajo](../README.md). Trabaja contra el
API del sistema (`/api/login.php`, `/api/ordenes.php`, `/api/descargar.php`) y guarda todo en una
base SQLite local, de modo que en el campo se puede seguir registrando sin Internet.

## Funciones

| Función | Internet | Descripción |
|---|---|---|
| Login | Sí (la primera vez) | Llama al API. Sin conexión, entra con la contraseña del último ingreso en línea de ese usuario en el teléfono. |
| Cargar órdenes | Sí | Trae las órdenes asignadas al usuario y el catálogo de trabajos. No pisa lo ya registrado en el teléfono. |
| Lista de órdenes | No | Filtro por estado. Permite registrar una orden (realizada / no realizada) y **editarla** mientras no se haya descargado. |
| Mapa | No (*) | Órdenes por realizar, realizadas y no realizadas, con filtro por estado y «mi ubicación». |
| Descargar realizadas | Sí | Envía al servidor cada orden registrada con su foto. Si se corta la conexión, lo pendiente queda guardado. |

(*) Los marcadores salen de la base local. El fondo del mapa (OpenStreetMap) necesita conexión o
haber visto antes esa zona (queda en caché).

Al registrar una orden se guarda: trabajo realizado, detalle, readoquinamiento, ubicación GPS, foto
y fecha/hora; o el motivo si no se pudo realizar. Las órdenes ya descargadas quedan de consulta y
se retiran del teléfono en la siguiente carga.

## Estructura

```
lib/
  main.dart               arranque
  modelos.dart            Usuario, Trabajo, Orden
  datos/api.dart          cliente del API
  datos/base_datos.dart   SQLite (sqflite)
  datos/repositorio.dart  sesión, login sin conexión, cargar y descargar
  pantallas/              login, inicio (lista + mapa), orden
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

Contra un servidor real (registra como realizadas las órdenes asignadas a ese usuario; usar solo
con datos de prueba):

```bash
OT_API_URL=http://127.0.0.1:8080 OT_CORREO=operador1@demo.local OT_CLAVE=demo12345 flutter test test/integracion_test.dart
```
