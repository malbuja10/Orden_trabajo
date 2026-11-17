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

Map<String, dynamic> ordenJson(int id, {String direccion = 'Av. Amazonas N10-20'}) => {
      'id': id,
      'fecha_asignacion': '2026-11-14',
      'trabajo_id': 2,
      'trabajo': 'Fuga',
      'grupo': ['Juan Pérez', 'Luis Andrade'],
      'observaciones': null,
      'readoquinamiento': true,
      'latitud': -0.219435,
      'longitud': -78.5288143,
      'reclamo': {
        'id': id + 100,
        'fecha': '2026-11-13',
        'nombre': 'Ana Torres',
        'direccion': direccion,
        'reclamo': 'Fuga de agua en la vereda',
        'telefono': null,
        'medidor': 'M-12345',
        'observaciones': null,
      },
    };

/// Servidor simulado con el mismo contrato que el API PHP.
class Servidor {
  bool enLinea = true;
  bool tokenValido = true;
  List<Map<String, dynamic>> asignadas = [];
  final recibidas = <Map<String, dynamic>>[];
  final fotos = <String>[];
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
      if (c['correo'] != 'operador1@demo.local' || c['password'] != 'demo12345') {
        return _json({'ok': false, 'error': 'Correo o contraseña incorrectos.'}, 401);
      }
      return _json({
        'ok': true,
        'token': 'tok123',
        'expira': '2026-12-14T16:12:03',
        'usuario': {'id': 2, 'nombre': 'Juan Pérez', 'correo': 'operador1@demo.local', 'rol': 'operador'},
      });
    }
    if (r.headers['Authorization'] != 'Bearer tok123' || !tokenValido) {
      return _json({'ok': false, 'error': 'Token no válido o expirado.'}, 401);
    }
    if (ruta.endsWith('/api/ordenes.php')) {
      return _json({
        'ok': true,
        'ordenes': asignadas,
        'trabajos': [
          {'id': 1, 'nombre': 'Acometida', 'color': '#1f77b4'},
          {'id': 2, 'nombre': 'Fuga', 'color': '#d62728'},
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
        if (rechazar != null) {
          resultados.add({'id': o['id'], 'ok': false, 'error': rechazar});
        } else {
          recibidas.add(o);
          asignadas.removeWhere((a) => a['id'] == o['id']);
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
    servidor = Servidor()..asignadas = [ordenJson(75), ordenJson(76)];
    bd = await BaseDatos.abrir(fabrica: databaseFactoryFfi, ruta: inMemoryDatabasePath);
    repo = Repositorio(bd, Api(cliente: MockClient(servidor.atender)), iteracionesClave: 50);
  });
  tearDown(() => bd.cerrar());

  Future<ResultadoLogin> entrar() => repo.login(url, 'operador1@demo.local', 'demo12345');

  test('normaliza la dirección del servidor', () {
    expect(Api.normalizarUrl(' ordenes.empresa.gob.ec/ '), 'https://ordenes.empresa.gob.ec');
    expect(Api.normalizarUrl('http://10.0.0.5:8080/api/'), 'http://10.0.0.5:8080');
  });

  group('login', () {
    test('con Internet guarda la sesión', () async {
      final r = await repo.login(url, ' Operador1@demo.local ', 'demo12345');
      expect(r.sinConexion, isFalse);
      expect(repo.usuario!.nombre, 'Juan Pérez');
      expect(await repo.ultimoCorreo(), 'operador1@demo.local');
      expect(await repo.urlServidor(), url);
    });

    test('contraseña incorrecta con Internet', () async {
      await expectLater(
        repo.login(url, 'operador1@demo.local', 'mala'),
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

      repo.cerrarSesion();
      await expectLater(
        repo.login(url, 'operador1@demo.local', 'otra'),
        throwsA(isA<ErrorApi>()),
      );
      expect(repo.usuario, isNull);
    });

    test('sin Internet y sin ingreso previo no entra', () async {
      servidor.enLinea = false;
      await expectLater(entrar(), throwsA(isA<ErrorApi>()));
    });
  });

  group('trabajo en campo y sincronización', () {
    test('carga, registro sin Internet, edición y descarga', () async {
      await entrar();
      final c = await repo.cargarOrdenes();
      expect((c.recibidas, c.nuevas), (2, 2));
      expect(repo.trabajos.map((t) => t.nombre), ['Acometida', 'Fuga']);
      expect(repo.ordenes.first.grupo, 'Juan Pérez / Luis Andrade');

      // En el campo, sin Internet
      servidor.enLinea = false;
      final o75 = repo.ordenes.firstWhere((o) => o.id == 75)
        ..estado = EstadoOrden.realizada
        ..fechaRealizacion = '2026-11-14T10:35:00'
        ..trabajoRealizadoId = 1
        ..detalle = 'Se reparó la fuga'
        ..readoquinamientoReal = true
        ..latitudReal = -0.21951
        ..longitudReal = -78.5287;
      await repo.guardarOrden(o75);
      final o76 = repo.ordenes.firstWhere((o) => o.id == 76)
        ..estado = EstadoOrden.noRealizada
        ..fechaRealizacion = '2026-11-14T11:00:00'
        ..motivo = 'No se encontró al propietario';
      await repo.guardarOrden(o76);
      expect(repo.porEnviar, 2);
      await expectLater(repo.descargarRealizadas(), throwsA(isA<SinConexion>()));
      await expectLater(repo.cargarOrdenes(), throwsA(isA<SinConexion>()));

      // Los datos sobreviven a cerrar la app (se releen de la base)
      repo.cerrarSesion();
      await entrar();
      expect(repo.porEnviar, 2);

      // Editar una orden ya realizada
      final editada = repo.ordenes.firstWhere((o) => o.id == 75)..detalle = 'Se cambió el collarín';
      await repo.guardarOrden(editada);

      // De vuelta con Internet: una carga no pisa lo registrado
      servidor.enLinea = true;
      servidor.asignadas[0] = ordenJson(75, direccion: 'Calle Sucre N1-1');
      await repo.cargarOrdenes();
      final tras = repo.ordenes.firstWhere((o) => o.id == 75);
      expect(tras.estado, EstadoOrden.realizada);
      expect(tras.detalle, 'Se cambió el collarín');
      expect(tras.direccion, 'Calle Sucre N1-1');

      final d = await repo.descargarRealizadas();
      expect((d.enviadas, d.rechazadas), (2, 0));
      expect(repo.porEnviar, 0);
      expect(servidor.recibidas.firstWhere((o) => o['id'] == 75), {
        'id': 75,
        'realizada': true,
        'fecha_realizacion': '2026-11-14T10:35:00',
        'trabajo_id': 1,
        'detalle': 'Se cambió el collarín',
        'readoquinamiento': true,
        'latitud': -0.21951,
        'longitud': -78.5287,
      });
      final no = servidor.recibidas.firstWhere((o) => o['id'] == 76);
      expect(no['realizada'], isFalse);
      expect(no['motivo_no_realizada'], 'No se encontró al propietario');

      // Una orden descargada ya no se puede modificar
      expect(() => repo.guardarOrden(tras), throwsStateError);

      // No se reenvía, y la siguiente carga limpia las descargadas
      expect((await repo.descargarRealizadas()).enviadas, 0);
      await repo.cargarOrdenes();
      expect(repo.ordenes, isEmpty);
    });

    test('la foto viaja como archivo foto_<id>', () async {
      final foto = File('${Directory.systemTemp.createTempSync().path}/f.jpg')..writeAsBytesSync([1, 2, 3]);
      await entrar();
      await repo.cargarOrdenes();
      await repo.guardarOrden(repo.ordenes.first
        ..estado = EstadoOrden.realizada
        ..foto = foto.path);
      await repo.descargarRealizadas();
      expect(servidor.fotos, ['foto_75']);
    });

    test('la carga retira las pendientes que ya no están asignadas, no las registradas', () async {
      await entrar();
      await repo.cargarOrdenes();
      await repo.guardarOrden(repo.ordenes.firstWhere((o) => o.id == 76)
        ..estado = EstadoOrden.realizada);
      servidor.asignadas = [ordenJson(80)];
      final c = await repo.cargarOrdenes();
      expect((c.nuevas, c.retiradas), (1, 1));
      expect(repo.ordenes.map((o) => o.id), [76, 80]);
    });

    test('una orden rechazada queda por enviar con el error', () async {
      await entrar();
      await repo.cargarOrdenes();
      await repo.guardarOrden(repo.ordenes.first..estado = EstadoOrden.realizada);
      servidor.rechazar = 'La orden no está asignada a este operador.';
      final d = await repo.descargarRealizadas();
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
