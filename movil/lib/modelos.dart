/// Modelos de datos de la app (reflejan el JSON del API del sistema Web).
library;

int _entero(Object? v) => v is int ? v : int.parse(v.toString());
int? _enteroONulo(Object? v) => (v == null || v.toString().isEmpty) ? null : _entero(v);
double? _decimal(Object? v) =>
    v == null ? null : (v is num ? v.toDouble() : double.tryParse(v.toString()));
String? _texto(Object? v) {
  final s = v?.toString().trim();
  return (s == null || s.isEmpty) ? null : s;
}

class Usuario {
  const Usuario({
    required this.id,
    required this.nombre,
    required this.correo,
    required this.rol,
    this.token,
  });

  final int id;
  final String nombre;
  final String correo;
  final String rol;

  /// Token del API (nulo si la sesión venció: hay que ingresar con Internet).
  final String? token;

  bool get esSupervisor => rol == 'supervisor';

  factory Usuario.desdeJson(Map<String, dynamic> j, {String? token}) => Usuario(
        id: _entero(j['id']),
        nombre: j['nombre'].toString(),
        correo: j['correo'].toString(),
        rol: (j['rol'] ?? '').toString(),
        token: token,
      );

  factory Usuario.desdeFila(Map<String, Object?> f) => Usuario(
        id: f['id'] as int,
        nombre: f['nombre'] as String,
        correo: f['correo'] as String,
        rol: f['rol'] as String,
        token: f['token'] as String?,
      );
}

/// Catálogos que envía el servidor para los combos del registro.
enum TipoCatalogo { trabajo, observacion, operador }

/// Elemento de un catálogo: tipo de trabajo (con su color), observación u operador.
class Opcion {
  const Opcion({required this.id, required this.nombre, this.color});

  final int id;
  final String nombre;
  final String? color;

  factory Opcion.desdeJson(Map<String, dynamic> j) => Opcion(
        id: _entero(j['id']),
        nombre: j['nombre'].toString(),
        color: _texto(j['color']),
      );

  factory Opcion.desdeFila(Map<String, Object?> f) => Opcion(
        id: f['id'] as int,
        nombre: f['nombre'] as String,
        color: f['color'] as String?,
      );
}

class Catalogos {
  const Catalogos({this.trabajos = const [], this.observaciones = const [], this.operadores = const []});

  final List<Opcion> trabajos;
  final List<Opcion> observaciones;
  final List<Opcion> operadores;

  List<Opcion> de(TipoCatalogo t) => switch (t) {
        TipoCatalogo.trabajo => trabajos,
        TipoCatalogo.observacion => observaciones,
        TipoCatalogo.operador => operadores,
      };

  String? nombre(TipoCatalogo t, int? id) {
    if (id == null) return null;
    for (final o in de(t)) {
      if (o.id == id) return o.nombre;
    }
    return null;
  }
}

/// Estado de una orden en el teléfono: solo hay pendientes y finalizadas.
enum EstadoOrden {
  pendiente('pendiente', 'Pendiente', 'Pendientes'),
  finalizada('finalizada', 'Finalizada', 'Finalizadas');

  const EstadoOrden(this.codigo, this.etiqueta, this.plural);
  final String codigo;
  final String etiqueta;
  final String plural;

  static EstadoOrden desdeCodigo(String c) =>
      values.firstWhere((e) => e.codigo == c, orElse: () => pendiente);
}

class Orden {
  Orden({
    required this.id,
    required this.fecha,
    this.cuenta,
    required this.nombre,
    required this.direccion,
    this.telefono,
    required this.trabajoId,
    required this.trabajo,
    this.observacionId,
    this.observacion,
    this.latitud,
    this.longitud,
    this.estado = EstadoOrden.pendiente,
    this.fechaRegistro,
    this.operador1Id,
    this.operador2Id,
    this.trabajoRegId,
    this.observacionRegId,
    this.readoquinamiento = false,
    this.detalle,
    this.fechaCampo,
    this.latitudCampo,
    this.longitudCampo,
    this.foto,
    this.enviada = false,
    this.errorEnvio,
    this.avisoEnvio,
  });

  // --- Reclamo (viene del servidor) ---
  final int id;

  /// Fecha del reclamo, `2026-11-17`.
  final String fecha;
  final String? cuenta;
  final String nombre;
  final String direccion;
  final String? telefono;
  final int trabajoId;
  final String trabajo;
  final int? observacionId;
  final String? observacion;
  final double? latitud;
  final double? longitud;

  // --- Registro del trabajo realizado (supervisor, en el teléfono) ---
  EstadoOrden estado;

  /// Fecha en que se hizo el trabajo, `2026-11-20`.
  String? fechaRegistro;
  int? operador1Id;
  int? operador2Id;
  int? trabajoRegId;
  int? observacionRegId;
  bool readoquinamiento;
  String? detalle;

  // --- Datos de campo (opcionales) ---
  /// Fecha y hora local del registro en el teléfono, `2026-11-20T10:35:00`.
  String? fechaCampo;
  double? latitudCampo;
  double? longitudCampo;

  /// Ruta local del archivo de la foto.
  String? foto;

  // --- Sincronización ---
  /// Ya se descargó al servidor (queda solo de consulta hasta la próxima carga).
  bool enviada;
  String? errorEnvio;

  /// Mensaje del servidor al recibirla (p. ej. ya estaba registrada en la Web).
  String? avisoEnvio;

  bool get pendiente => estado == EstadoOrden.pendiente;

  /// Finalizada en el teléfono y todavía no descargada al servidor.
  bool get porEnviar => !pendiente && !enviada;

  /// Ubicación para el mapa: la tomada en campo o, si no hay, la del reclamo.
  double? get lat => latitudCampo ?? latitud;
  double? get lng => longitudCampo ?? longitud;

  /// Texto para buscar en la lista.
  String get textoBusqueda =>
      '$id ${cuenta ?? ''} $nombre $direccion $trabajo ${observacion ?? ''}'.toLowerCase();

  factory Orden.desdeJson(Map<String, dynamic> j) => Orden(
        id: _entero(j['id']),
        fecha: (j['fecha'] ?? '').toString(),
        cuenta: _texto(j['cuenta']),
        nombre: (j['nombre'] ?? '').toString(),
        direccion: (j['direccion'] ?? '').toString(),
        telefono: _texto(j['telefono']),
        trabajoId: _entero(j['trabajo_id']),
        trabajo: (j['trabajo'] ?? '').toString(),
        observacionId: _enteroONulo(j['observacion_id']),
        observacion: _texto(j['observacion']),
        latitud: _decimal(j['latitud']),
        longitud: _decimal(j['longitud']),
      );

  factory Orden.desdeFila(Map<String, Object?> f) => Orden(
        id: f['id'] as int,
        fecha: f['fecha'] as String,
        cuenta: f['cuenta'] as String?,
        nombre: f['nombre'] as String,
        direccion: f['direccion'] as String,
        telefono: f['telefono'] as String?,
        trabajoId: f['trabajo_id'] as int,
        trabajo: f['trabajo'] as String,
        observacionId: f['observacion_id'] as int?,
        observacion: f['observacion'] as String?,
        latitud: f['latitud'] as double?,
        longitud: f['longitud'] as double?,
        estado: EstadoOrden.desdeCodigo(f['estado'] as String),
        fechaRegistro: f['fecha_registro'] as String?,
        operador1Id: f['operador1_id'] as int?,
        operador2Id: f['operador2_id'] as int?,
        trabajoRegId: f['trabajo_reg_id'] as int?,
        observacionRegId: f['observacion_reg_id'] as int?,
        readoquinamiento: f['readoquinamiento'] == 1,
        detalle: f['detalle'] as String?,
        fechaCampo: f['fecha_campo'] as String?,
        latitudCampo: f['latitud_campo'] as double?,
        longitudCampo: f['longitud_campo'] as double?,
        foto: f['foto'] as String?,
        enviada: f['enviada'] == 1,
        errorEnvio: f['error_envio'] as String?,
        avisoEnvio: f['aviso_envio'] as String?,
      );

  /// Columnas del reclamo (lo que envía el servidor).
  Map<String, Object?> filaReclamo() => {
        'fecha': fecha,
        'cuenta': cuenta,
        'nombre': nombre,
        'direccion': direccion,
        'telefono': telefono,
        'trabajo_id': trabajoId,
        'trabajo': trabajo,
        'observacion_id': observacionId,
        'observacion': observacion,
        'latitud': latitud,
        'longitud': longitud,
      };

  /// Columnas del registro (lo que ingresa el supervisor).
  Map<String, Object?> filaRegistro() => {
        'estado': estado.codigo,
        'fecha_registro': fechaRegistro,
        'operador1_id': operador1Id,
        'operador2_id': operador2Id,
        'trabajo_reg_id': trabajoRegId,
        'observacion_reg_id': observacionRegId,
        'readoquinamiento': readoquinamiento ? 1 : 0,
        'detalle': detalle,
        'fecha_campo': fechaCampo,
        'latitud_campo': latitudCampo,
        'longitud_campo': longitudCampo,
        'foto': foto,
        'enviada': enviada ? 1 : 0,
        'error_envio': errorEnvio,
        'aviso_envio': avisoEnvio,
      };

  /// Registro vacío: la orden vuelve a pendiente.
  static Map<String, Object?> filaPendiente() => Orden(
        id: 0, fecha: '', nombre: '', direccion: '', trabajoId: 0, trabajo: '',
      ).filaRegistro();

  /// Cuerpo para `POST /api/descargar.php` (la foto va aparte, como archivo).
  Map<String, Object?> aJsonDescarga() => {
        'id': id,
        'fecha_registro': fechaRegistro,
        'operador1_id': operador1Id,
        'operador2_id': operador2Id,
        'trabajo_id': trabajoRegId ?? trabajoId,
        'observacion_id': observacionRegId,
        'readoquinamiento': readoquinamiento,
        'detalle': detalle,
        'fecha': fechaCampo,
        'latitud': latitudCampo,
        'longitud': longitudCampo,
      };
}
