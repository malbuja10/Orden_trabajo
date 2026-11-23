import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;

import '../modelos.dart';

/// El servidor respondió con un error (credenciales, token vencido, datos no válidos...).
class ErrorApi implements Exception {
  ErrorApi(this.mensaje, {this.codigo = 0});
  final String mensaje;
  final int codigo;

  bool get noAutorizado => codigo == 401;

  @override
  String toString() => mensaje;
}

/// No se pudo llegar al servidor (sin Internet, servidor caído, tiempo agotado).
class SinConexion implements Exception {
  @override
  String toString() => 'No hay conexión con el servidor.';
}

class RespuestaLogin {
  RespuestaLogin(this.usuario, this.expira);
  final Usuario usuario;
  final String? expira;
}

class RespuestaOrdenes {
  RespuestaOrdenes(this.ordenes, this.catalogos);
  final List<Orden> ordenes;
  final Catalogos catalogos;
}

/// Resultado del servidor para una orden descargada.
class ResultadoEnvio {
  const ResultadoEnvio({this.error, this.yaRegistrada = false, this.mensaje});

  /// Mensaje si el servidor la rechazó (`null` = recibida).
  final String? error;

  /// Ya estaba finalizada en el servidor (p. ej. registrada en la Web): no se modificó.
  final bool yaRegistrada;
  final String? mensaje;

  bool get ok => error == null;
}

/// Cliente del API del sistema Web (`/api/login.php`, `/api/ordenes.php`, `/api/descargar.php`).
class Api {
  Api({http.Client? cliente}) : _http = cliente ?? http.Client();

  final http.Client _http;

  static const _espera = Duration(seconds: 20);
  static const _esperaFoto = Duration(seconds: 90);

  /// Normaliza la dirección escrita por el usuario: `servidor.gob.ec/ordenes/` →
  /// `https://servidor.gob.ec/ordenes`.
  static String normalizarUrl(String url) {
    var u = url.trim();
    if (u.isEmpty) return u;
    if (!u.contains('://')) u = 'https://$u';
    while (u.endsWith('/')) {
      u = u.substring(0, u.length - 1);
    }
    for (final sufijo in ['/api/login.php', '/api']) {
      if (u.endsWith(sufijo)) u = u.substring(0, u.length - sufijo.length);
    }
    return u;
  }

  Uri _uri(String base, String ruta) {
    if (base.isEmpty) {
      throw ErrorApi('Indique la dirección del servidor.');
    }
    final uri = Uri.tryParse('$base/api/$ruta');
    if (uri == null || uri.host.isEmpty) {
      throw ErrorApi('La dirección del servidor no es válida.');
    }
    return uri;
  }

  Future<Map<String, dynamic>> _enviar(
    Future<http.Response> Function() peticion,
    Duration espera,
  ) async {
    final http.Response r;
    try {
      r = await peticion().timeout(espera);
    } on SocketException {
      throw SinConexion();
    } on TimeoutException {
      throw SinConexion();
    } on HandshakeException {
      throw SinConexion();
    } on http.ClientException {
      throw SinConexion();
    }
    Object? cuerpo;
    try {
      cuerpo = jsonDecode(utf8.decode(r.bodyBytes));
    } on FormatException {
      cuerpo = null;
    }
    if (cuerpo is! Map<String, dynamic>) {
      throw ErrorApi(
        'Respuesta inesperada del servidor (HTTP ${r.statusCode}). '
        'Revise la dirección del servidor.',
        codigo: r.statusCode,
      );
    }
    if (r.statusCode >= 400 || cuerpo['ok'] != true) {
      throw ErrorApi(
        (cuerpo['error'] ?? 'Error del servidor (HTTP ${r.statusCode}).').toString(),
        codigo: r.statusCode,
      );
    }
    return cuerpo;
  }

  /// a) Login (solo supervisores).
  Future<RespuestaLogin> login(String base, String correo, String password) async {
    final uri = _uri(base, 'login.php');
    final j = await _enviar(
      () => _http.post(
        uri,
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode({'correo': correo, 'password': password}),
      ),
      _espera,
    );
    final usuario = Usuario.desdeJson(
      (j['usuario'] as Map).cast<String, dynamic>(),
      token: j['token'].toString(),
    );
    if (!usuario.esSupervisor) {
      throw ErrorApi('Solo los supervisores pueden usar la app móvil.', codigo: 403);
    }
    return RespuestaLogin(usuario, j['expira']?.toString());
  }

  /// b) Órdenes pendientes y catálogos (trabajos, observaciones, operadores).
  Future<RespuestaOrdenes> ordenes(String base, String token) async {
    final uri = _uri(base, 'ordenes.php');
    final j = await _enviar(
      () => _http.get(uri, headers: {'Authorization': 'Bearer $token'}),
      _espera,
    );
    List<Opcion> lista(String clave) => [
          for (final t in (j[clave] as List? ?? const []))
            Opcion.desdeJson((t as Map).cast<String, dynamic>()),
        ];
    return RespuestaOrdenes(
      [
        for (final o in j['ordenes'] as List)
          Orden.desdeJson((o as Map).cast<String, dynamic>()),
      ],
      Catalogos(
        trabajos: lista('trabajos'),
        observaciones: lista('observaciones'),
        operadores: lista('operadores'),
      ),
    );
  }

  /// c) Descarga al servidor una orden finalizada (con su foto, si tiene).
  Future<ResultadoEnvio> descargar(String base, String token, Orden orden) async {
    final uri = _uri(base, 'descargar.php');
    final j = await _enviar(() async {
      final p = http.MultipartRequest('POST', uri)
        ..headers['Authorization'] = 'Bearer $token'
        ..fields['datos'] = jsonEncode({
          'ordenes': [orden.aJsonDescarga()],
        });
      final foto = orden.foto;
      if (foto != null && File(foto).existsSync()) {
        p.files.add(await http.MultipartFile.fromPath('foto_${orden.id}', foto));
      }
      return http.Response.fromStream(await _http.send(p));
    }, _esperaFoto);

    final resultados = j['resultados'];
    if (resultados is List) {
      for (final r in resultados) {
        if (r is Map && r['id'].toString() == orden.id.toString()) {
          return r['ok'] == true
              ? ResultadoEnvio(yaRegistrada: r['ya_registrada'] == true, mensaje: r['mensaje']?.toString())
              : ResultadoEnvio(error: (r['error'] ?? 'El servidor rechazó la orden.').toString());
        }
      }
    }
    return const ResultadoEnvio(error: 'El servidor no confirmó la orden.');
  }
}
