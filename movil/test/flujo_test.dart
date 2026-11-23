// Prueba de las pantallas: login → cargar → lista → registrar finalizada → editar → mapa → descargar.
// Con OT_CAPTURAS=/carpeta guarda una imagen PNG de cada paso.
import 'dart:io';
import 'dart:ui' as ui;

import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/testing.dart';
import 'package:material_ui/material_ui.dart';
import 'package:ordenes_trabajo/datos/api.dart';
import 'package:ordenes_trabajo/datos/base_datos.dart';
import 'package:ordenes_trabajo/datos/repositorio.dart';
import 'package:ordenes_trabajo/estilo.dart';
import 'package:ordenes_trabajo/main.dart';
import 'package:ordenes_trabajo/modelos.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

import 'repositorio_test.dart' show Servidor, ordenJson;

final _marco = GlobalKey();
final _capturas = Platform.environment['OT_CAPTURAS'];

Future<void> _fuentes() async {
  final raiz = Platform.environment['FLUTTER_ROOT'];
  if (raiz == null) return;
  for (final (familia, archivo) in [('Roboto', 'Roboto-Regular.ttf'), ('MaterialIcons', 'MaterialIcons-Regular.otf')]) {
    final bytes = File('$raiz/bin/cache/artifacts/material_fonts/$archivo').readAsBytesSync();
    await (FontLoader(familia)..addFont(Future.value(ByteData.sublistView(bytes)))).load();
  }
}

/// Deja terminar el trabajo real (base de datos, isolates) y redibuja.
Future<void> _esperar(WidgetTester t) async {
  for (var i = 0; i < 3; i++) {
    await t.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 80)));
    await t.pump(const Duration(milliseconds: 400));
  }
}

Future<void> _captura(WidgetTester t, String nombre) async {
  if (_capturas == null) return;
  await t.runAsync(() async {
    final r = _marco.currentContext!.findRenderObject()! as RenderRepaintBoundary;
    final img = await r.toImage(pixelRatio: 2);
    final png = await img.toByteData(format: ui.ImageByteFormat.png);
    File('$_capturas/$nombre.png').writeAsBytesSync(png!.buffer.asUint8List());
  });
}

/// Elige una opción en un combo identificado por su etiqueta.
Future<void> _combo(WidgetTester t, String etiqueta, String opcion) async {
  final combo = find.widgetWithText(DropdownButtonFormField<int?>, etiqueta);
  await t.ensureVisible(combo);
  await t.pumpAndSettle();
  await t.tap(combo);
  await t.pumpAndSettle();
  await t.tap(find.text(opcion).last);
  await t.pumpAndSettle();
}

void main() {
  testWidgets('flujo de trabajo del supervisor', (t) async {
    await t.binding.setSurfaceSize(const Size(400, 820));
    addTearDown(() => t.binding.setSurfaceSize(null));
    await t.runAsync(_fuentes);
    // Carpeta de la caché de teselas del mapa (plugin path_provider)
    final temporal = Directory.systemTemp.createTempSync('ot_prueba').path;
    t.binding.defaultBinaryMessenger.setMockMethodCallHandler(
      const MethodChannel('plugins.flutter.io/path_provider'),
      (llamada) async => temporal,
    );

    sqfliteFfiInit();
    final servidor = Servidor()
      ..pendientes = [
        ordenJson(75),
        ordenJson(76, direccion: 'Calle Sucre N45-12')..['latitud'] = -0.2251,
        ordenJson(77, direccion: 'Av. Colón N33-08')..['longitud'] = -78.5192,
      ];
    late Repositorio repo;
    await t.runAsync(() async {
      final bd = await BaseDatos.abrir(fabrica: databaseFactoryFfiNoIsolate, ruta: inMemoryDatabasePath);
      repo = Repositorio(bd, Api(cliente: MockClient(servidor.atender)), iteracionesClave: 50);
    });

    await t.pumpWidget(RepaintBoundary(key: _marco, child: OrdenesApp(repo: repo)));
    await _esperar(t);

    // Login (primera vez: pide también el servidor)
    await t.enterText(find.widgetWithText(TextFormField, 'Correo'), 'supervisor@demo.local');
    await t.enterText(find.widgetWithText(TextFormField, 'Contraseña'), 'demo12345');
    await t.enterText(find.widgetWithText(TextFormField, 'Servidor'), 'http://servidor.prueba');
    await t.pump();
    await _captura(t, '1_login');
    await t.tap(find.text('Ingresar'));
    await _esperar(t);
    expect(find.text('No hay órdenes en el teléfono.'), findsOneWidget);

    // Cargar órdenes pendientes
    await t.tap(find.byTooltip('Cargar órdenes pendientes del servidor'));
    await _esperar(t);
    expect(find.text('Av. Amazonas N10-20'), findsOneWidget);
    expect(find.text('Pendientes (3)'), findsOneWidget);
    await _captura(t, '2_lista');

    // Búsqueda
    await t.enterText(find.byType(TextField).first, 'sucre');
    await t.pump();
    expect(find.text('Av. Amazonas N10-20'), findsNothing);
    expect(find.text('Calle Sucre N45-12'), findsOneWidget);
    await t.tap(find.byTooltip('Borrar búsqueda'));
    await t.pump();

    // Registrar la orden 75 como finalizada: el operador 1 es obligatorio
    await t.tap(find.text('Av. Amazonas N10-20'));
    await _esperar(t);
    expect(find.text('Orden N.º 75'), findsOneWidget);
    expect(find.text(fechaCorta(hoyIso())), findsOneWidget); // fecha propuesta: hoy
    await t.tap(find.text('Guardar como finalizada'));
    await _esperar(t);
    expect(find.text('Seleccione el operador 1'), findsOneWidget);

    await _combo(t, 'Operador 1', 'Juan Pérez');
    await _combo(t, 'Operador 2 (opcional)', 'Luis Andrade');
    await _combo(t, 'Trabajo realizado', 'Acometida');
    await _combo(t, 'Observación', 'Daño en la calzada');
    await t.enterText(find.widgetWithText(TextFormField, 'Detalle del trabajo (si existe)'), 'Se reparó la fuga');
    await t.tap(find.text('Requiere readoquinamiento'));
    await t.pump();
    await t.ensureVisible(find.text('Fecha del trabajo'));
    await t.pumpAndSettle();
    await _captura(t, '3_registro');
    await t.tap(find.text('Guardar como finalizada'));
    await _esperar(t);
    expect(find.text('Finalizadas (1)'), findsOneWidget);
    final o75 = repo.ordenes.firstWhere((o) => o.id == 75);
    expect((o75.operador1Id, o75.operador2Id, o75.trabajoRegId, o75.observacionRegId, o75.readoquinamiento),
        (2, 3, 1, 2, true));
    expect(o75.fechaRegistro, hoyIso());

    // La siguiente orden propone los mismos operadores
    await t.tap(find.text('Calle Sucre N45-12'));
    await _esperar(t);
    expect(find.text('Juan Pérez'), findsOneWidget);
    expect(find.text('Luis Andrade'), findsOneWidget);
    await t.tap(find.text('Guardar como finalizada'));
    await _esperar(t);
    expect(find.text('Finalizadas (2)'), findsOneWidget);
    await t.pump(const Duration(seconds: 5)); // se va el aviso

    // Editar una orden ya finalizada
    await t.tap(find.text('Finalizadas (2)'));
    await t.pump();
    await _captura(t, '4_finalizadas');
    await t.tap(find.text('Av. Amazonas N10-20'));
    await _esperar(t);
    expect(find.text('Se reparó la fuga'), findsOneWidget);
    await t.enterText(find.widgetWithText(TextFormField, 'Detalle del trabajo (si existe)'), 'Se cambió el collarín');
    await t.tap(find.text('Guardar cambios'));
    await _esperar(t);
    expect(repo.ordenes.firstWhere((o) => o.id == 75).detalle, 'Se cambió el collarín');
    expect(repo.porEnviar, 2);
    await t.pump(const Duration(seconds: 5));

    // Mapa: 3 marcadores (2 finalizadas, 1 pendiente)
    await t.tap(find.text('Mapa'));
    await _esperar(t);
    final marcadores = find.descendant(of: find.byType(MarkerLayer), matching: find.byIcon(Icons.location_on));
    expect(marcadores, findsNWidgets(3));
    await _captura(t, '5_mapa');
    await t.tap(marcadores.first);
    await _esperar(t);
    expect(find.textContaining('Orden N.º'), findsOneWidget);
    await _captura(t, '6_mapa_detalle');
    await t.tapAt(const Offset(200, 100));
    await _esperar(t);
    await t.tap(find.text('Órdenes'));
    await _esperar(t);

    // Descargar al servidor
    await t.tap(find.byTooltip('Descargar órdenes finalizadas al servidor'));
    await _esperar(t);
    expect(servidor.recibidas.map((o) => o['id']), [75, 76]);
    expect(servidor.recibidas.first['detalle'], 'Se cambió el collarín');
    expect(servidor.recibidas.first['operador1_id'], 2);
    expect(repo.porEnviar, 0);
    await _captura(t, '7_descargadas');

    // Una orden descargada es solo de consulta
    await t.tap(find.text('Av. Amazonas N10-20'));
    await _esperar(t);
    expect(find.text('Descargada al servidor'), findsOneWidget);
    expect(find.text('Guardar cambios'), findsNothing);
    await _captura(t, '8_solo_lectura');
    expect(repo.ordenes.where((o) => o.estado == EstadoOrden.pendiente), hasLength(1));
  });
}
