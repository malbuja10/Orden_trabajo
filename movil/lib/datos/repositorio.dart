import 'dart:convert';
import 'dart:io';
import 'dart:math';

import 'package:crypto/crypto.dart';
import 'package:flutter/foundation.dart';

import '../modelos.dart';
import 'api.dart';
import 'base_datos.dart';

class ResultadoLogin {
  ResultadoLogin(this.usuario, {required this.sinConexion});
  final Usuario usuario;

  /// Entró con los datos guardados en el teléfono (no había Internet).
  final bool sinConexion;
}

class ResultadoCarga {
  ResultadoCarga({required this.recibidas, required this.nuevas, required this.retiradas});
  final int recibidas;
  final int nuevas;
  final int retiradas;
}

class ResultadoDescarga {
  ResultadoDescarga({
    required this.enviadas,
    required this.rechazadas,
    this.yaRegistradas = 0,
    this.interrumpida = false,
  });
  final int enviadas;
  final int rechazadas;

  /// Ya estaban finalizadas en el servidor (registradas en la Web): no se modificaron.
  final int yaRegistradas;

  /// Se perdió la conexión a mitad de la descarga; lo no enviado queda guardado.
  final bool interrumpida;
}

/// Lógica de la app: sesión, órdenes locales y sincronización con el servidor.
class Repositorio extends ChangeNotifier {
  Repositorio(this.bd, this.api, {this.iteracionesClave = 60000});

  final BaseDatos bd;
  final Api api;
  final int iteracionesClave;

  /// Dirección del servidor por defecto: `flutter build apk --dart-define=API_URL=https://...`
  static const urlPorDefecto = String.fromEnvironment('API_URL');

  Usuario? usuario;
  List<Orden> ordenes = [];
  Catalogos catalogos = const Catalogos();

  int get porEnviar => ordenes.where((o) => o.porEnviar).length;

  // ---------- Configuración ----------

  Future<String> urlServidor() async => await bd.config('url_servidor') ?? urlPorDefecto;
  Future<String?> ultimoCorreo() => bd.config('ultimo_correo');

  // ---------- Sesión ----------

  /// Inicia sesión contra el API. Si no hay conexión, permite entrar con la
  /// contraseña del último ingreso con Internet de ese usuario en este teléfono.
  Future<ResultadoLogin> login(String url, String correo, String password) async {
    final base = Api.normalizarUrl(url);
    correo = correo.trim().toLowerCase();
    if (base.isNotEmpty) await bd.guardarConfig('url_servidor', base);

    try {
      final r = await api.login(base, correo, password);
      final sal = _salNueva();
      await bd.guardarUsuario(
        r.usuario,
        expira: r.expira,
        sal: sal,
        claveHash: await _derivar(password, sal),
      );
      await _abrirSesion(r.usuario);
      return ResultadoLogin(r.usuario, sinConexion: false);
    } on SinConexion {
      final fila = await bd.filaUsuario(correo);
      if (fila == null) {
        throw ErrorApi(
          'No hay conexión con el servidor. El primer ingreso de un usuario '
          'en este teléfono necesita Internet.',
        );
      }
      final hash = await _derivar(password, fila['sal'] as String);
      if (hash != fila['clave_hash']) {
        throw ErrorApi('Contraseña incorrecta (verificada sin conexión).');
      }
      final u = Usuario.desdeFila(fila);
      await _abrirSesion(u);
      return ResultadoLogin(u, sinConexion: true);
    }
  }

  Future<void> _abrirSesion(Usuario u) async {
    usuario = u;
    await bd.guardarConfig('ultimo_correo', u.correo);
    await _recargar();
  }

  void cerrarSesion() {
    usuario = null;
    ordenes = [];
    notifyListeners();
  }

  Future<void> _recargar() async {
    final u = usuario;
    if (u == null) return;
    ordenes = await bd.ordenes(u.id);
    catalogos = await bd.catalogos();
    notifyListeners();
  }

  String _tokenOError() {
    final t = usuario?.token;
    if (t == null) {
      throw ErrorApi('La sesión venció. Inicie sesión con Internet.', codigo: 401);
    }
    return t;
  }

  Future<T> _conSesion<T>(Future<T> Function(String base, String token) accion) async {
    final token = _tokenOError();
    try {
      return await accion(await urlServidor(), token);
    } on ErrorApi catch (e) {
      if (e.noAutorizado) {
        final u = usuario!;
        await bd.borrarToken(u.id);
        usuario = Usuario(id: u.id, nombre: u.nombre, correo: u.correo, rol: u.rol);
      }
      rethrow;
    }
  }

  // ---------- Sincronización ----------

  /// Carga del servidor las órdenes pendientes y los catálogos (necesita Internet).
  Future<ResultadoCarga> cargarOrdenes() async {
    final r = await _conSesion((base, token) => api.ordenes(base, token));
    final c = await bd.aplicarCarga(usuario!.id, r.ordenes, r.catalogos);
    for (final f in c.fotos) {
      _borrarArchivo(f);
    }
    await _recargar();
    return ResultadoCarga(recibidas: r.ordenes.length, nuevas: c.nuevas, retiradas: c.retiradas);
  }

  /// Descarga al servidor las órdenes finalizadas en el teléfono (necesita Internet).
  /// Se envía una orden por petición: si se corta la conexión, lo ya enviado queda marcado.
  Future<ResultadoDescarga> descargarFinalizadas({void Function(int hechas, int total)? progreso}) async {
    final u = usuario!;
    final pendientes = ordenes.where((o) => o.porEnviar).toList();
    var enviadas = 0, rechazadas = 0, yaRegistradas = 0, interrumpida = false;
    try {
      for (var i = 0; i < pendientes.length; i++) {
        final o = pendientes[i];
        progreso?.call(i, pendientes.length);
        final ResultadoEnvio r;
        try {
          r = await _conSesion((base, token) => api.descargar(base, token, o));
        } on SinConexion {
          if (enviadas + rechazadas + yaRegistradas == 0) rethrow;
          interrumpida = true;
          break;
        }
        if (r.ok) {
          o
            ..enviada = true
            ..errorEnvio = null
            ..avisoEnvio = r.yaRegistrada ? r.mensaje : null;
          r.yaRegistrada ? yaRegistradas++ : enviadas++;
        } else {
          o.errorEnvio = r.error;
          rechazadas++;
        }
        await bd.guardarRegistro(u.id, o);
      }
    } finally {
      notifyListeners();
    }
    return ResultadoDescarga(
      enviadas: enviadas,
      rechazadas: rechazadas,
      yaRegistradas: yaRegistradas,
      interrumpida: interrumpida,
    );
  }

  // ---------- Registro en campo (sin Internet) ----------

  /// Guarda en el teléfono lo registrado para una orden.
  Future<void> guardarOrden(Orden o) async {
    if (o.enviada) {
      throw StateError('La orden ya fue descargada al servidor.');
    }
    o.errorEnvio = null;
    await bd.guardarRegistro(usuario!.id, o);
    if (!o.pendiente) {
      // Se recuerdan fecha y operadores para agilizar el registro de las siguientes.
      await bd.guardarConfig('ultimo_registro', jsonEncode({
        'fecha': o.fechaRegistro,
        'op1': o.operador1Id,
        'op2': o.operador2Id,
      }));
    }
    notifyListeners();
  }

  /// Fecha y operadores del último registro (para proponerlos en el siguiente).
  Future<({String? fecha, int? op1, int? op2})> ultimoRegistro() async {
    final t = await bd.config('ultimo_registro');
    if (t == null) return (fecha: null, op1: null, op2: null);
    final j = jsonDecode(t) as Map<String, dynamic>;
    return (fecha: j['fecha'] as String?, op1: j['op1'] as int?, op2: j['op2'] as int?);
  }

  void _borrarArchivo(String ruta) {
    try {
      final f = File(ruta);
      if (f.existsSync()) f.deleteSync();
    } on FileSystemException {
      // No es grave: queda un archivo huérfano en el almacenamiento de la app.
    }
  }

  // ---------- Contraseña sin conexión ----------

  String _salNueva() {
    final r = Random.secure();
    return base64Encode(List<int>.generate(16, (_) => r.nextInt(256)));
  }

  Future<String> _derivar(String password, String sal) {
    return compute(_pbkdf2, (password, sal, iteracionesClave));
  }
}

String _pbkdf2((String, String, int) a) => pbkdf2(a.$1, a.$2, a.$3);

/// PBKDF2-HMAC-SHA256 (un bloque, 32 bytes) en base64.
String pbkdf2(String password, String salBase64, int iteraciones) {
  final hmac = Hmac(sha256, utf8.encode(password));
  var u = hmac.convert([...base64Decode(salBase64), 0, 0, 0, 1]).bytes;
  final t = Uint8List.fromList(u);
  for (var i = 1; i < iteraciones; i++) {
    u = hmac.convert(u).bytes;
    for (var j = 0; j < t.length; j++) {
      t[j] ^= u[j];
    }
  }
  return base64Encode(t);
}
