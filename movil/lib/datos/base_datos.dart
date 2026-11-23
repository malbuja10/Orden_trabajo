import 'package:path/path.dart' as p;
import 'package:sqflite/sqflite.dart';

import '../modelos.dart';

/// Base de datos local (SQLite): permite trabajar en el campo sin Internet.
class BaseDatos {
  BaseDatos._(this._db);
  final Database _db;

  /// 2: órdenes sin asignación (pendientes / finalizadas por el supervisor) y catálogos.
  static const _version = 2;

  static Future<BaseDatos> abrir({DatabaseFactory? fabrica, String? ruta}) async {
    final f = fabrica ?? databaseFactory;
    final archivo = ruta ?? p.join(await f.getDatabasesPath(), 'ordenes_trabajo.db');
    final db = await f.openDatabase(
      archivo,
      options: OpenDatabaseOptions(version: _version, onCreate: _crear, onUpgrade: _actualizar),
    );
    return BaseDatos._(db);
  }

  /// La versión 1 (órdenes asignadas a operadores) no es compatible con el sistema actual:
  /// se recrean las tablas. Se conservan la configuración (servidor, último correo).
  static Future<void> _actualizar(Database db, int anterior, int nueva) async {
    for (final t in ['usuarios', 'trabajos', 'ordenes', 'catalogos']) {
      await db.execute('DROP TABLE IF EXISTS $t');
    }
    await _crearTablas(db, conConfig: false);
  }

  static Future<void> _crear(Database db, int version) => _crearTablas(db);

  static Future<void> _crearTablas(Database db, {bool conConfig = true}) async {
    if (conConfig) {
      await db.execute('CREATE TABLE config (clave TEXT PRIMARY KEY, valor TEXT)');
    }
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
    // Trabajos, observaciones y operadores para los combos del registro
    await db.execute('''
      CREATE TABLE catalogos (
        tipo TEXT NOT NULL,
        id INTEGER NOT NULL,
        nombre TEXT NOT NULL,
        color TEXT,
        PRIMARY KEY (tipo, id)
      )''');
    await db.execute('''
      CREATE TABLE ordenes (
        id INTEGER NOT NULL,
        usuario_id INTEGER NOT NULL,     -- supervisor que trabaja con esta copia
        -- reclamo (servidor)
        fecha TEXT NOT NULL,
        cuenta TEXT,
        nombre TEXT NOT NULL,
        direccion TEXT NOT NULL,
        telefono TEXT,
        trabajo_id INTEGER NOT NULL,
        trabajo TEXT NOT NULL,
        observacion_id INTEGER,
        observacion TEXT,
        latitud REAL,
        longitud REAL,
        -- registro del trabajo realizado (supervisor)
        estado TEXT NOT NULL DEFAULT 'pendiente',
        fecha_registro TEXT,
        operador1_id INTEGER,
        operador2_id INTEGER,
        trabajo_reg_id INTEGER,
        observacion_reg_id INTEGER,
        readoquinamiento INTEGER NOT NULL DEFAULT 0,
        detalle TEXT,
        -- datos de campo
        fecha_campo TEXT,
        latitud_campo REAL,
        longitud_campo REAL,
        foto TEXT,
        -- sincronización
        enviada INTEGER NOT NULL DEFAULT 0,
        error_envio TEXT,
        aviso_envio TEXT,
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

  // ---------- Catálogos ----------

  Future<Catalogos> catalogos() async {
    Future<List<Opcion>> de(TipoCatalogo t) async => [
          for (final f in await _db.query('catalogos', where: 'tipo = ?', whereArgs: [t.name], orderBy: 'nombre'))
            Opcion.desdeFila(f),
        ];
    return Catalogos(
      trabajos: await de(TipoCatalogo.trabajo),
      observaciones: await de(TipoCatalogo.observacion),
      operadores: await de(TipoCatalogo.operador),
    );
  }

  // ---------- Órdenes ----------

  Future<List<Orden>> ordenes(int usuarioId) async => [
        for (final f in await _db.query(
          'ordenes',
          where: 'usuario_id = ?',
          whereArgs: [usuarioId],
          orderBy: 'fecha, id',
        ))
          Orden.desdeFila(f),
      ];

  /// Guarda lo que registró el supervisor para una orden.
  Future<void> guardarRegistro(int usuarioId, Orden o) => _db.update(
        'ordenes',
        o.filaRegistro(),
        where: 'id = ? AND usuario_id = ?',
        whereArgs: [o.id, usuarioId],
      );

  /// Aplica una carga del servidor:
  /// - reemplaza los catálogos (trabajos, observaciones, operadores);
  /// - agrega las órdenes pendientes nuevas y actualiza los datos del reclamo de las
  ///   existentes **sin tocar** lo ya registrado en el teléfono;
  /// - retira las pendientes que ya no están pendientes en el servidor (finalizadas en
  ///   la Web o anuladas) y las que ya fueron descargadas.
  ///
  /// Devuelve las rutas de las fotos de las órdenes retiradas (para borrarlas).
  Future<({int nuevas, int retiradas, List<String> fotos})> aplicarCarga(
    int usuarioId,
    List<Orden> delServidor,
    Catalogos catalogos,
  ) =>
      _db.transaction((t) async {
        await t.delete('catalogos');
        for (final tipo in TipoCatalogo.values) {
          for (final o in catalogos.de(tipo)) {
            await t.insert(
              'catalogos',
              {'tipo': tipo.name, 'id': o.id, 'nombre': o.nombre, 'color': o.color},
              conflictAlgorithm: ConflictAlgorithm.replace,
            );
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
              ...o.filaReclamo(),
            });
            nuevas++;
          } else {
            final cambios = o.filaReclamo();
            if (local['enviada'] == 1) {
              // Sigue pendiente en el servidor (p. ej. se reabrió en la Web): vuelve a pendiente.
              cambios.addAll(Orden.filaPendiente());
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
