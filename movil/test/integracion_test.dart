// Prueba contra el sistema Web real (PHP + PostgreSQL). Se omite si no se indica el servidor:
//
//   OT_API_URL=http://127.0.0.1:8080 OT_CORREO=operador1@demo.local OT_CLAVE=demo12345 \
//   OT_FOTO=/ruta/foto.jpg flutter test test/integracion_test.dart
//
// OJO: registra como realizadas/no realizadas las órdenes asignadas a ese usuario.
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:ordenes_trabajo/datos/api.dart';
import 'package:ordenes_trabajo/datos/base_datos.dart';
import 'package:ordenes_trabajo/datos/repositorio.dart';
import 'package:ordenes_trabajo/modelos.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

void main() {
  final env = Platform.environment;
  final url = env['OT_API_URL'];

  test('flujo completo contra el servidor real', skip: url == null ? 'Falta OT_API_URL' : null,
      () async {
    sqfliteFfiInit();
    final bd = await BaseDatos.abrir(fabrica: databaseFactoryFfi, ruta: inMemoryDatabasePath);
    final repo = Repositorio(bd, Api(), iteracionesClave: 50);
    final correo = env['OT_CORREO']!, clave = env['OT_CLAVE']!;

    await expectLater(repo.login(url!, correo, 'incorrecta'), throwsA(isA<ErrorApi>()));
    final r = await repo.login(url, correo, clave);
    expect(r.sinConexion, isFalse);

    final c = await repo.cargarOrdenes();
    // ignore: avoid_print
    print('Cargadas ${c.recibidas} órdenes: ${repo.ordenes.map((o) => o.id).toList()}; '
        'trabajos: ${repo.trabajos.map((t) => t.nombre).toList()}');
    expect(repo.ordenes.length, greaterThanOrEqualTo(2));
    expect(repo.trabajos, isNotEmpty);

    final a = repo.ordenes[0], b = repo.ordenes[1];
    await repo.guardarOrden(a
      ..estado = EstadoOrden.realizada
      ..fechaRealizacion = '2026-11-16T10:35:00'
      ..trabajoRealizadoId = repo.trabajos.last.id
      ..detalle = 'Reparación hecha — prueba móvil ñ'
      ..readoquinamientoReal = true
      ..latitudReal = -0.21951
      ..longitudReal = -78.5287
      ..foto = env['OT_FOTO']);
    await repo.guardarOrden(b
      ..estado = EstadoOrden.noRealizada
      ..fechaRealizacion = '2026-11-16T11:00:00'
      ..motivo = 'No se encontró al propietario');

    final d = await repo.descargarRealizadas();
    expect((d.enviadas, d.rechazadas), (2, 0), reason: '${a.errorEnvio} / ${b.errorEnvio}');

    // Reenviar algo ya registrado no falla ni duplica
    expect(await repo.api.descargar(url, repo.usuario!.token!, a), isNull);

    final c2 = await repo.cargarOrdenes();
    expect(c2.recibidas, c.recibidas - 2);
    expect(repo.ordenes.any((o) => o.id == a.id || o.id == b.id), isFalse);
    // ignore: avoid_print
    print('ORDENES_PROBADAS=${a.id},${b.id}');
    await bd.cerrar();
  });
}
