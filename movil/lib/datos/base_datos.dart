import 'package:path/path.dart' as p;
import 'package:sqflite/sqflite.dart';

import '../modelos.dart';

/// Base de datos local (SQLite): permite trabajar en el campo sin Internet.
class BaseDatos {
  BaseDatos._(this._db);
  final Database _db;

  static const _version = 1;

  static Future<BaseDatos> abrir({DatabaseFactory? fabrica, String? ruta}) async {
    final f = fabrica ?? databaseFactory;
    final archivo = ruta ?? p.join(await f.getDatabasesPath(), 'ordenes_trabajo.db');
    final db = await f.openDatabase(
      archivo,
      options: OpenDatabaseOptions(version: _version, onCreate: _crear),
    );
    return BaseDatos._(db);
  }

  static Future<void> _crear(Database db, int version) async {
    await db.execute('CREATE TABLE config (clave TEXT PRIMARY KEY, valor TEXT)');
    await db.execute('''
      CREATE TABLE usuarios (
        id INTEGER PRIMARY KEY,
        nombre TEXT NOT NULL,
        correo TEXT NOT NULL UNIQUE,
        rol TEXT NOT NULL,
        token TEXT,
        expira TEXT,
        sal TEXT NOT NULL,          -- para verificar la contraseña sin Internet
        clave_hash TEXT NOT NULL
      )''');
    await db.execute('''
      CREATE TABLE trabajos (
        id INTEGER PRIMARY KEY,
        nombre TEXT NOT NULL,
        color TEXT NOT NULL
      )''');
    await db.execute('''
      CREATE TABLE ordenes (
        id INTEGER NOT NULL,
        usuario_id INTEGER NOT NULL,
        -- asignación (servidor)
        fecha_asignacion TEXT NOT NULL,
        trabajo_id INTEGER NOT NULL,
        trabajo TEXT NOT NULL,
        grupo TEXT NOT NULL,
        observaciones TEXT,
        readoquinamiento INTEGER NOT NULL DEFAULT 0,
        latitud REAL,
        longitud REAL,
        reclamo_id INTEGER,
        reclamo_fecha TEXT,
        nombre TEXT NOT NULL,
        direccion TEXT NOT NULL,
        reclamo TEXT NOT NULL,
        telefono TEXT,
        medidor TEXT,
        reclamo_observaciones TEXT,
        -- ejecución (teléfono)
        estado TEXT NOT NULL DEFAULT 'pendiente',
        fecha_realizacion TEXT,
        trabajo_realizado_id INTEGER,
        detalle TEXT,
        readoquinamiento_real INTEGER,
        latitud_real REAL,
        longitud_real REAL,
        foto TEXT,
        motivo TEXT,
        enviada INTEGER NOT NULL DEFAULT 0,
        error_envio TEXT,
        PRIMARY KEY (id, usuario_id)
      )''');
  }

  Future<void> cerrar() => _db.close();

  // ---------- Configuración ----------

  Future<String?> config(String clave) async {
    final f = await _db.query('config', where: 'clave = ?', whereArgs: [clave]);
    return f.isEmpty ? null : f.first['valor'] as String?;
  }

  Future<void> guardarConfig(String clave, String valor) => _db.insert(
        'config',
        {'clave': clave, 'valor': valor},
        conflictAlgorithm: ConflictAlgorithm.replace,
      );

  // ---------- Usuarios ----------

  Future<Map<String, Object?>?> filaUsuario(String correo) async {
    final f = await _db.query(
      'usuarios',
      where: 'lower(correo) = ?',
      whereArgs: [correo.toLowerCase()],
    );
    return f.isEmpty ? null : f.first;
  }

  Future<void> guardarUsuario(
    Usuario u, {
    required String? expira,
    required String sal,
    required String claveHash,
  }) async {
    await _db.transaction((t) async {
      // El correo de un usuario puede cambiar en el servidor (y reutilizarse).
      await t.delete('usuarios', where: 'id = ? OR lower(correo) = ?', whereArgs: [u.id, u.correo.toLowerCase()]);
      await t.insert('usuarios', {
        'id': u.id,
        'nombre': u.nombre,
        'correo': u.correo,
        'rol': u.rol,
        'token': u.token,
        'expira': expira,
        'sal': sal,
        'clave_hash': claveHash,
      });
    });
  }

  /// Borra el token (venció o fue rechazado): obliga a iniciar sesión con Internet.
  Future<void> borrarToken(int usuarioId) =>
      _db.update('usuarios', {'token': null}, where: 'id = ?', whereArgs: [usuarioId]);

  // ---------- Trabajos ----------

  Future<List<Trabajo>> trabajos() async =>
      [for (final f in await _db.query('trabajos', orderBy: 'nombre')) Trabajo.desdeFila(f)];

  // ---------- Órdenes ----------

  Future<List<Orden>> ordenes(int usuarioId) async => [
        for (final f in await _db.query(
          'ordenes',
          where: 'usuario_id = ?',
          whereArgs: [usuarioId],
          orderBy: 'fecha_asignacion, id',
        ))
          Orden.desdeFila(f),
      ];

  /// Guarda lo que registró el operador para una orden.
  Future<void> guardarEjecucion(int usuarioId, Orden o) => _db.update(
        'ordenes',
        o.filaEjecucion(),
        where: 'id = ? AND usuario_id = ?',
        whereArgs: [o.id, usuarioId],
      );

  /// Aplica una carga del servidor:
  /// - actualiza el catálogo de trabajos;
  /// - agrega las órdenes nuevas y actualiza la asignación de las existentes
  ///   **sin tocar** lo ya registrado en el teléfono;
  /// - retira las órdenes pendientes que el servidor ya no asigna a este operador
  ///   y las que ya fueron descargadas.
  ///
  /// Devuelve las rutas de las fotos de las órdenes retiradas (para borrarlas).
  Future<({int nuevas, int retiradas, List<String> fotos})> aplicarCarga(
    int usuarioId,
    List<Orden> delServidor,
    List<Trabajo> trabajos,
  ) =>
      _db.transaction((t) async {
        if (trabajos.isNotEmpty) {
          await t.delete('trabajos');
          for (final tr in trabajos) {
            await t.insert('trabajos', tr.aFila());
          }
        }

        final locales = {
          for (final f in await t.query(
            'ordenes',
            columns: ['id', 'estado', 'enviada', 'foto'],
            where: 'usuario_id = ?',
            whereArgs: [usuarioId],
          ))
            f['id'] as int: f,
        };

        var nuevas = 0;
        final idsServidor = <int>{};
        for (final o in delServidor) {
          idsServidor.add(o.id);
          final local = locales[o.id];
          if (local == null) {
            await t.insert('ordenes', {
              'id': o.id,
              'usuario_id': usuarioId,
              ...o.filaAsignacion(),
            });
            nuevas++;
          } else {
            final cambios = o.filaAsignacion();
            if (local['enviada'] == 1) {
              // El servidor la volvió a asignar: vuelve a quedar por realizar.
              cambios.addAll(Orden.desdeJson({'id': o.id, 'trabajo_id': o.trabajoId}).filaEjecucion());
            }
            await t.update(
              'ordenes',
              cambios,
              where: 'id = ? AND usuario_id = ?',
              whereArgs: [o.id, usuarioId],
            );
          }
        }

        var retiradas = 0;
        final fotos = <String>[];
        for (final e in locales.entries) {
          if (idsServidor.contains(e.key)) continue;
          final f = e.value;
          final porEnviar = f['estado'] != 'pendiente' && f['enviada'] != 1;
          if (porEnviar) continue; // trabajo de campo aún no descargado: nunca se borra
          await t.delete(
            'ordenes',
            where: 'id = ? AND usuario_id = ?',
            whereArgs: [e.key, usuarioId],
          );
          if (f['estado'] == 'pendiente') retiradas++;
          final foto = f['foto'] as String?;
          if (foto != null) fotos.add(foto);
        }
        return (nuevas: nuevas, retiradas: retiradas, fotos: fotos);
      });
}
