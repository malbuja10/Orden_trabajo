// Pruebas de la lógica sin conexión y de sincronización, con un servidor simulado.
import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:ordenes_trabajo/datos/api.dart';
import 'package:ordenes_trabajo/datos/base_datos.dart';
import 'package:ordenes_trabajo/datos/repositorio.dart';
import 'package:ordenes_trabajo/modelos.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

Map<String, dynamic> ordenJson(int id, {String direccion = 'Av. Amazonas N10-20', String fecha = '2026-11-14'}) => {
      'id': id,
      'fecha': fecha,
      'cuenta': '10$id',
      'nombre': 'Ana Torres',
      'direccion': direccion,
      'telefono': '0991234567',
      'trabajo_id': 2,
      'trabajo': 'Fuga',
      'observacion_id': 1,
      'observacion': 'Cliente ausente',
      'latitud': -0.219435,
      'longitud': -78.5288143,
    };

/// Servidor simulado con el mismo contrato que el API PHP (versión 2).
class Servidor {
  bool enLinea = true;
  bool tokenValido = true;
  List<Map<String, dynamic>> pendientes = [];
  final recibidas = <Map<String, dynamic>>[];
  final fotos = <String>[];
  final registradasEnWeb = <int>{};
  String? rechazar; // mensaje de error para todas las descargas

  http.Response _json(Object o, [int codigo = 200]) => http.Response.bytes(
        utf8.encode(jsonEncode(o)),
        codigo,
        headers: {'content-type': 'application/json; charset=utf-8'},
      );

  Future<http.Response> atender(http.Request r) async {
    if (!enLinea) throw const SocketException('sin red');
    final ruta = r.url.path;
    if (ruta.endsWith('/api/login.php')) {
      final c = jsonDecode(r.body) as Map;
      final usuarios = {
        'supervisor@demo.local': {'id': 1, 'nombre': 'Ana Supervisora', 'rol': 'supervisor'},
        'operador1@demo.local': {'id': 2, 'nombre': 'Juan Pérez', 'rol': 'operador'},
      };
      final u = usuarios[c['correo']];
      if (u == null || c['password'] != 'demo12345') {
        return _json({'ok': false, 'error': 'Correo o contraseña incorrectos.'}, 401);
      }
      if (u['rol'] != 'supervisor') {
        return _json({'ok': false, 'error': 'Solo los supervisores pueden usar la app móvil.'}, 403);
      }
      return _json({
        'ok': true,
        'token': 'tok123',
        'expira': '2026-12-14T16:12:03',
        'usuario': {...u, 'correo': c['correo']},
      });
    }
    if (r.headers['Authorization'] != 'Bearer tok123' || !tokenValido) {
      return _json({'ok': false, 'error': 'Token no válido o expirado.'}, 401);
    }
    if (ruta.endsWith('/api/ordenes.php')) {
      return _json({
        'ok': true,
        'ordenes': pendientes,
        'trabajos': [
          {'id': 1, 'nombre': 'Acometida', 'color': '#1f77b4'},
          {'id': 2, 'nombre': 'Fuga', 'color': '#d62728'},
        ],
        'observaciones': [
          {'id': 1, 'nombre': 'Cliente ausente'},
          {'id': 2, 'nombre': 'Daño en la calzada'},
        ],
        'operadores': [
          {'id': 2, 'nombre': 'Juan Pérez'},
          {'id': 3, 'nombre': 'Luis Andrade'},
        ],
      });
    }
    if (ruta.endsWith('/api/descargar.php')) {
      final cuerpo = latin1.decode(r.bodyBytes);
      final datos = RegExp(r'name="datos"\r\n(?:.*\r\n)*?\r\n(.*)\r\n').firstMatch(cuerpo)!.group(1)!;
      final ordenes = ((jsonDecode(utf8.decode(latin1.encode(datos))) as Map)['ordenes'] as List)
          .cast<Map<String, dynamic>>();
      fotos.addAll(RegExp(r'name="(foto_\d+)"').allMatches(cuerpo).map((m) => m.group(1)!));
      final resultados = [];
      for (final o in ordenes) {
        if (registradasEnWeb.contains(o['id'])) {
          resultados.add({'id': o['id'], 'ok': true, 'ya_registrada': true,
              'mensaje': 'La orden ya estaba registrada en la Web; no se modificó.'});
        } else if (rechazar != null) {
          resultados.add({'id': o['id'], 'ok': false, 'error': rechazar});
        } else {
          recibidas.add(o);
          pendientes.removeWhere((a) => a['id'] == o['id']);
          resultados.add({'id': o['id'], 'ok': true});
        }
      }
      return _json({'ok': true, 'resultados': resultados});
    }
    return http.Response('no existe', 404);
  }
}

void main() {
  sqfliteFfiInit();
  late Servidor servidor;
  late BaseDatos bd;
  late Repositorio repo;
  const url = 'http://servidor.prueba';

  setUp(() async {
    servidor = Servidor()..pendientes = [ordenJson(75), ordenJson(76)];
    bd = await BaseDatos.abrir(fabrica: databaseFactoryFfi, ruta: inMemoryDatabasePath);
    repo = Repositorio(bd, Api(cliente: MockClient(servidor.atender)), iteracionesClave: 50);
  });
  tearDown(() => bd.cerrar());

  Future<ResultadoLogin> entrar() => repo.login(url, 'supervisor@demo.local', 'demo12345');

  /// Registra la orden como finalizada, como lo hace el formulario.
  Future<Orden> finalizar(int id, {String detalle = 'Se reparó la fuga'}) async {
    final o = repo.ordenes.firstWhere((o) => o.id == id)
      ..estado = EstadoOrden.finalizada
      ..fechaRegistro = '2026-11-20'
      ..operador1Id = 2
      ..operador2Id = 3
      ..trabajoRegId = 1
      ..observacionRegId = 2
      ..readoquinamiento = true
      ..detalle = detalle
      ..fechaCampo = '2026-11-20T10:35:00'
      ..latitudCampo = -0.21951
      ..longitudCampo = -78.5287;
    await repo.guardarOrden(o);
    return o;
  }

  test('actualiza una base de la versión 1 conservando la configuración', () async {
    final ruta = '${Directory.systemTemp.createTempSync('ot_v1').path}/v1.db';
    final v1 = await databaseFactoryFfi.openDatabase(ruta,
        options: OpenDatabaseOptions(version: 1, onCreate: (db, _) async {
      await db.execute('CREATE TABLE config (clave TEXT PRIMARY KEY, valor TEXT)');
      await db.execute('CREATE TABLE ordenes (id INTEGER, usuario_id INTEGER, fecha_asignacion TEXT)');
      await db.insert('config', {'clave': 'url_servidor', 'valor': url});
      await db.insert('ordenes', {'id': 1, 'usuario_id': 2, 'fecha_asignacion': '2026-11-01'});
    }));
    await v1.close();

    final v2 = await BaseDatos.abrir(fabrica: databaseFactoryFfi, ruta: ruta);
    expect(await v2.config('url_servidor'), url);
    expect(await v2.ordenes(2), isEmpty);
    expect((await v2.catalogos()).operadores, isEmpty);
    await v2.cerrar();
  });

  test('normaliza la dirección del servidor', () {
    expect(Api.normalizarUrl(' ordenes.empresa.gob.ec/ '), 'https://ordenes.empresa.gob.ec');
    expect(Api.normalizarUrl('http://10.0.0.5:8080/api/'), 'http://10.0.0.5:8080');
  });

  group('login', () {
    test('supervisor con Internet guarda la sesión', () async {
      final r = await repo.login(url, ' Supervisor@demo.local ', 'demo12345');
      expect(r.sinConexion, isFalse);
      expect(repo.usuario!.nombre, 'Ana Supervisora');
      expect(await repo.ultimoCorreo(), 'supervisor@demo.local');
      expect(await repo.urlServidor(), url);
    });

    test('un operador no puede ingresar', () async {
      await expectLater(
        repo.login(url, 'operador1@demo.local', 'demo12345'),
        throwsA(isA<ErrorApi>().having((e) => e.mensaje, 'mensaje', contains('supervisores'))),
      );
      expect(repo.usuario, isNull);
      // Y tampoco queda guardado para entrar sin conexión
      servidor.enLinea = false;
      await expectLater(repo.login(url, 'operador1@demo.local', 'demo12345'), throwsA(isA<ErrorApi>()));
    });

    test('contraseña incorrecta con Internet', () async {
      await expectLater(
        repo.login(url, 'supervisor@demo.local', 'mala'),
        throwsA(isA<ErrorApi>().having((e) => e.codigo, 'codigo', 401)),
      );
      expect(repo.usuario, isNull);
    });

    test('sin Internet entra con los datos guardados', () async {
      await entrar();
      await repo.cargarOrdenes();
      repo.cerrarSesion();
      servidor.enLinea = false;

      final r = await entrar();
      expect(r.sinConexion, isTrue);
      expect(repo.ordenes, hasLength(2));
      expect(repo.catalogos.operadores, hasLength(2));

      repo.cerrarSesion();
      await expectLater(repo.login(url, 'supervisor@demo.local', 'otra'), throwsA(isA<ErrorApi>()));
      expect(repo.usuario, isNull);
    });

    test('sin Internet y sin ingreso previo no entra', () async {
      servidor.enLinea = false;
      await expectLater(entrar(), throwsA(isA<ErrorApi>()));
    });
  });

  group('registro en campo y sincronización', () {
    test('carga, finalización sin Internet, edición y descarga', () async {
      await entrar();
      final c = await repo.cargarOrdenes();
      expect((c.recibidas, c.nuevas), (2, 2));
      expect(repo.catalogos.trabajos.map((t) => t.nombre), ['Acometida', 'Fuga']);
      expect(repo.catalogos.observaciones.map((t) => t.nombre), ['Cliente ausente', 'Daño en la calzada']);
      expect(repo.catalogos.operadores.map((t) => t.nombre), ['Juan Pérez', 'Luis Andrade']);
      expect(repo.ordenes.first.observacion, 'Cliente ausente');

      // En el campo, sin Internet
      servidor.enLinea = false;
      await finalizar(75);
      expect(repo.porEnviar, 1);
      expect(await repo.ultimoRegistro(), (fecha: '2026-11-20', op1: 2, op2: 3));
      await expectLater(repo.descargarFinalizadas(), throwsA(isA<SinConexion>()));
      await expectLater(repo.cargarOrdenes(), throwsA(isA<SinConexion>()));

      // Los datos sobreviven a cerrar la app (se releen de la base)
      repo.cerrarSesion();
      await entrar();
      expect(repo.porEnviar, 1);

      // Editar una orden ya finalizada
      final editada = repo.ordenes.firstWhere((o) => o.id == 75)
        ..detalle = 'Se cambió el collarín'
        ..operador2Id = null;
      await repo.guardarOrden(editada);

      // De vuelta con Internet: una carga no pisa lo registrado
      servidor.enLinea = true;
      servidor.pendientes[0] = ordenJson(75, direccion: 'Calle Sucre N1-1');
      await repo.cargarOrdenes();
      final tras = repo.ordenes.firstWhere((o) => o.id == 75);
      expect(tras.estado, EstadoOrden.finalizada);
      expect(tras.detalle, 'Se cambió el collarín');
      expect(tras.direccion, 'Calle Sucre N1-1');

      final d = await repo.descargarFinalizadas();
      expect((d.enviadas, d.rechazadas), (1, 0));
      expect(repo.porEnviar, 0);
      expect(servidor.recibidas.single, {
        'id': 75,
        'fecha_registro': '2026-11-20',
        'operador1_id': 2,
        'operador2_id': null,
        'trabajo_id': 1,
        'observacion_id': 2,
        'readoquinamiento': true,
        'detalle': 'Se cambió el collarín',
        'fecha': '2026-11-20T10:35:00',
        'latitud': -0.21951,
        'longitud': -78.5287,
      });

      // Una orden descargada ya no se puede modificar
      expect(() => repo.guardarOrden(tras), throwsStateError);

      // No se reenvía, y la siguiente carga retira la descargada
      expect((await repo.descargarFinalizadas()).enviadas, 0);
      await repo.cargarOrdenes();
      expect(repo.ordenes.map((o) => o.id), [76]);
    });

    test('la foto viaja como archivo foto_<id>', () async {
      final foto = File('${Directory.systemTemp.createTempSync().path}/f.jpg')..writeAsBytesSync([1, 2, 3]);
      await entrar();
      await repo.cargarOrdenes();
      final o = await finalizar(75);
      await repo.guardarOrden(o..foto = foto.path);
      await repo.descargarFinalizadas();
      expect(servidor.fotos, ['foto_75']);
    });

    test('la carga retira las pendientes que se finalizaron en la Web, no las registradas', () async {
      await entrar();
      await repo.cargarOrdenes();
      await finalizar(76);
      servidor.pendientes = [ordenJson(80)];
      final c = await repo.cargarOrdenes();
      expect((c.nuevas, c.retiradas), (1, 1));
      expect(repo.ordenes.map((o) => o.id), [76, 80]);
    });

    test('si ya se registró en la Web, se retira sin modificarla', () async {
      await entrar();
      await repo.cargarOrdenes();
      await finalizar(75);
      servidor.registradasEnWeb.add(75);
      final d = await repo.descargarFinalizadas();
      expect((d.enviadas, d.yaRegistradas, d.rechazadas), (0, 1, 0));
      final o = repo.ordenes.firstWhere((o) => o.id == 75);
      expect(o.enviada, isTrue);
      expect(o.avisoEnvio, contains('Web'));
      expect(servidor.recibidas, isEmpty);
    });

    test('una orden rechazada queda por enviar con el error', () async {
      await entrar();
      await repo.cargarOrdenes();
      await finalizar(75);
      servidor.rechazar = 'La orden está anulada.';
      final d = await repo.descargarFinalizadas();
      expect((d.enviadas, d.rechazadas), (0, 1));
      expect(repo.ordenes.first.errorEnvio, servidor.rechazar);
      expect(repo.porEnviar, 1);
    });

    test('token vencido: pide iniciar sesión y conserva los datos', () async {
      await entrar();
      await repo.cargarOrdenes();
      servidor.tokenValido = false;
      await expectLater(
        repo.cargarOrdenes(),
        throwsA(isA<ErrorApi>().having((e) => e.noAutorizado, 'noAutorizado', isTrue)),
      );
      expect(repo.ordenes, hasLength(2));
      servidor.tokenValido = true;
      repo.cerrarSesion();
      await entrar();
      expect((await repo.cargarOrdenes()).recibidas, 2);
    });
  });
}
