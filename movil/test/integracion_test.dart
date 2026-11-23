// Prueba contra el sistema Web real (PHP + PostgreSQL). Se omite si no se indica el servidor:
//
//   OT_API_URL=http://127.0.0.1:8080 OT_CORREO=supervisor@demo.local OT_CLAVE=... \
//   OT_OPERADOR=operador1@demo.local OT_CLAVE_OPERADOR=demo12345 OT_FOTO=/ruta/foto.jpg \
//   flutter test test/integracion_test.dart
//
// OJO: FINALIZA en el servidor dos órdenes pendientes. Usar solo con datos de prueba.
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:ordenes_trabajo/datos/api.dart';
import 'package:ordenes_trabajo/datos/base_datos.dart';
import 'package:ordenes_trabajo/datos/repositorio.dart';
import 'package:ordenes_trabajo/estilo.dart';
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

    // Un operador no puede usar la app
    if (env['OT_OPERADOR'] != null) {
      await expectLater(
        repo.login(url!, env['OT_OPERADOR']!, env['OT_CLAVE_OPERADOR']!),
        throwsA(isA<ErrorApi>().having((e) => e.codigo, 'codigo', 403)),
      );
    }
    await expectLater(repo.login(url!, env['OT_CORREO']!, 'incorrecta'), throwsA(isA<ErrorApi>()));
    expect((await repo.login(url, env['OT_CORREO']!, env['OT_CLAVE']!)).sinConexion, isFalse);

    final c = await repo.cargarOrdenes();
    final cat = repo.catalogos;
    // ignore: avoid_print
    print('Pendientes: ${c.recibidas}; trabajos ${cat.trabajos.length}, '
        'observaciones ${cat.observaciones.length}, operadores ${cat.operadores.map((o) => o.nombre).toList()}');
    expect(repo.ordenes.length, greaterThanOrEqualTo(2));
    expect(cat.operadores.length, greaterThanOrEqualTo(2));

    final a = repo.ordenes[0], b = repo.ordenes[1];
    await repo.guardarOrden(a
      ..estado = EstadoOrden.finalizada
      ..fechaRegistro = hoyIso()
      ..operador1Id = cat.operadores[0].id
      ..operador2Id = cat.operadores[1].id
      ..trabajoRegId = cat.trabajos.last.id
      ..observacionRegId = cat.observaciones.isEmpty ? null : cat.observaciones.first.id
      ..readoquinamiento = true
      ..detalle = 'Reparación hecha — prueba móvil ñ'
      ..fechaCampo = ahoraIso()
      ..latitudCampo = -0.21951
      ..longitudCampo = -78.5287
      ..foto = env['OT_FOTO']);
    // La segunda con una fecha anterior al reclamo: el servidor la rechaza
    await repo.guardarOrden(b
      ..estado = EstadoOrden.finalizada
      ..fechaRegistro = '2000-01-01'
      ..operador1Id = cat.operadores[0].id);

    var d = await repo.descargarFinalizadas();
    expect((d.enviadas, d.rechazadas), (1, 1), reason: '${a.errorEnvio} / ${b.errorEnvio}');
    expect(b.errorEnvio, contains('fecha'));
    // ignore: avoid_print
    print('Rechazo esperado: ${b.errorEnvio}');

    // Se corrige y se vuelve a enviar
    await repo.guardarOrden(b..fechaRegistro = hoyIso());
    d = await repo.descargarFinalizadas();
    expect((d.enviadas, d.rechazadas), (1, 0));

    // Reenviar algo ya registrado no lo modifica
    final r = await repo.api.descargar(url, repo.usuario!.token!, a);
    expect((r.ok, r.yaRegistrada), (true, true));

    final c2 = await repo.cargarOrdenes();
    expect(c2.recibidas, c.recibidas - 2);
    expect(repo.ordenes.any((o) => o.id == a.id || o.id == b.id), isFalse);
    // ignore: avoid_print
    print('ORDENES_PROBADAS=${a.id},${b.id}');
    await bd.cerrar();
  });
}
