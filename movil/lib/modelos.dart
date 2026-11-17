/// Modelos de datos de la app (reflejan el JSON del API del sistema Web).
library;

int _entero(Object? v) => v is int ? v : int.parse(v.toString());
int? _enteroONulo(Object? v) => v == null ? null : _entero(v);
double? _decimal(Object? v) =>
    v == null ? null : (v is num ? v.toDouble() : double.tryParse(v.toString()));
bool _logico(Object? v) => v == true || v == 1 || v == 't' || v == 'true' || v == '1';
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

  /// Token del API (nulo si aún no se ha iniciado sesión con Internet).
  final String? token;

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

/// Tipo de trabajo (acometida, fuga, ...) con el color del mapa temático.
class Trabajo {
  const Trabajo({required this.id, required this.nombre, required this.color});

  final int id;
  final String nombre;
  final String color;

  factory Trabajo.desdeJson(Map<String, dynamic> j) => Trabajo(
        id: _entero(j['id']),
        nombre: j['nombre'].toString(),
        color: (j['color'] ?? '#1f77b4').toString().trim(),
      );

  factory Trabajo.desdeFila(Map<String, Object?> f) => Trabajo(
        id: f['id'] as int,
        nombre: f['nombre'] as String,
        color: f['color'] as String,
      );

  Map<String, Object?> aFila() => {'id': id, 'nombre': nombre, 'color': color};
}

/// Estado local de una orden en el teléfono.
enum EstadoOrden {
  pendiente('pendiente', 'Por realizar'),
  realizada('realizada', 'Realizada'),
  noRealizada('no_realizada', 'No realizada');

  const EstadoOrden(this.codigo, this.etiqueta);
  final String codigo;
  final String etiqueta;

  static EstadoOrden desdeCodigo(String c) =>
      values.firstWhere((e) => e.codigo == c, orElse: () => pendiente);
}

class Orden {
  Orden({
    required this.id,
    required this.fechaAsignacion,
    required this.trabajoId,
    required this.trabajo,
    required this.grupo,
    this.observaciones,
    this.readoquinamiento = false,
    this.latitud,
    this.longitud,
    this.reclamoId,
    this.reclamoFecha,
    required this.nombre,
    required this.direccion,
    required this.reclamo,
    this.telefono,
    this.medidor,
    this.reclamoObservaciones,
    this.estado = EstadoOrden.pendiente,
    this.fechaRealizacion,
    this.trabajoRealizadoId,
    this.detalle,
    this.readoquinamientoReal,
    this.latitudReal,
    this.longitudReal,
    this.foto,
    this.motivo,
    this.enviada = false,
    this.errorEnvio,
  });

  // --- Asignación (viene del servidor) ---
  final int id;
  final String fechaAsignacion;
  final int trabajoId;
  final String trabajo;
  final String grupo;
  final String? observaciones;
  final bool readoquinamiento;
  final double? latitud;
  final double? longitud;
  final int? reclamoId;
  final String? reclamoFecha;
  final String nombre;
  final String direccion;
  final String reclamo;
  final String? telefono;
  final String? medidor;
  final String? reclamoObservaciones;

  // --- Ejecución (se registra en el teléfono) ---
  EstadoOrden estado;

  /// Fecha y hora local, formato `2026-11-14T10:35:00`.
  String? fechaRealizacion;
  int? trabajoRealizadoId;
  String? detalle;
  bool? readoquinamientoReal;
  double? latitudReal;
  double? longitudReal;

  /// Ruta local del archivo de la foto.
  String? foto;
  String? motivo;

  /// Ya se descargó al servidor (queda solo de consulta hasta la próxima carga).
  bool enviada;
  String? errorEnvio;

  bool get pendiente => estado == EstadoOrden.pendiente;

  /// Registrada en el teléfono y todavía no descargada al servidor.
  bool get porEnviar => !pendiente && !enviada;

  /// Ubicación para el mapa: la real tomada en campo o, si no hay, la del reclamo.
  double? get lat => latitudReal ?? latitud;
  double? get lng => longitudReal ?? longitud;

  factory Orden.desdeJson(Map<String, dynamic> j) {
    final r = (j['reclamo'] as Map?)?.cast<String, dynamic>() ?? const {};
    final grupo = j['grupo'];
    return Orden(
      id: _entero(j['id']),
      fechaAsignacion: (j['fecha_asignacion'] ?? '').toString(),
      trabajoId: _entero(j['trabajo_id']),
      trabajo: (j['trabajo'] ?? '').toString(),
      grupo: grupo is List ? grupo.join(' / ') : (grupo ?? '').toString(),
      observaciones: _texto(j['observaciones']),
      readoquinamiento: _logico(j['readoquinamiento']),
      latitud: _decimal(j['latitud']),
      longitud: _decimal(j['longitud']),
      reclamoId: _enteroONulo(r['id']),
      reclamoFecha: _texto(r['fecha']),
      nombre: (r['nombre'] ?? '').toString(),
      direccion: (r['direccion'] ?? '').toString(),
      reclamo: (r['reclamo'] ?? '').toString(),
      telefono: _texto(r['telefono']),
      medidor: _texto(r['medidor']),
      reclamoObservaciones: _texto(r['observaciones']),
    );
  }

  factory Orden.desdeFila(Map<String, Object?> f) => Orden(
        id: f['id'] as int,
        fechaAsignacion: f['fecha_asignacion'] as String,
        trabajoId: f['trabajo_id'] as int,
        trabajo: f['trabajo'] as String,
        grupo: f['grupo'] as String,
        observaciones: f['observaciones'] as String?,
        readoquinamiento: f['readoquinamiento'] == 1,
        latitud: f['latitud'] as double?,
        longitud: f['longitud'] as double?,
        reclamoId: f['reclamo_id'] as int?,
        reclamoFecha: f['reclamo_fecha'] as String?,
        nombre: f['nombre'] as String,
        direccion: f['direccion'] as String,
        reclamo: f['reclamo'] as String,
        telefono: f['telefono'] as String?,
        medidor: f['medidor'] as String?,
        reclamoObservaciones: f['reclamo_observaciones'] as String?,
        estado: EstadoOrden.desdeCodigo(f['estado'] as String),
        fechaRealizacion: f['fecha_realizacion'] as String?,
        trabajoRealizadoId: f['trabajo_realizado_id'] as int?,
        detalle: f['detalle'] as String?,
        readoquinamientoReal:
            f['readoquinamiento_real'] == null ? null : f['readoquinamiento_real'] == 1,
        latitudReal: f['latitud_real'] as double?,
        longitudReal: f['longitud_real'] as double?,
        foto: f['foto'] as String?,
        motivo: f['motivo'] as String?,
        enviada: f['enviada'] == 1,
        errorEnvio: f['error_envio'] as String?,
      );

  /// Columnas de la asignación (lo que envía el servidor).
  Map<String, Object?> filaAsignacion() => {
        'fecha_asignacion': fechaAsignacion,
        'trabajo_id': trabajoId,
        'trabajo': trabajo,
        'grupo': grupo,
        'observaciones': observaciones,
        'readoquinamiento': readoquinamiento ? 1 : 0,
        'latitud': latitud,
        'longitud': longitud,
        'reclamo_id': reclamoId,
        'reclamo_fecha': reclamoFecha,
        'nombre': nombre,
        'direccion': direccion,
        'reclamo': reclamo,
        'telefono': telefono,
        'medidor': medidor,
        'reclamo_observaciones': reclamoObservaciones,
      };

  /// Columnas de la ejecución (lo que registra el operador).
  Map<String, Object?> filaEjecucion() => {
        'estado': estado.codigo,
        'fecha_realizacion': fechaRealizacion,
        'trabajo_realizado_id': trabajoRealizadoId,
        'detalle': detalle,
        'readoquinamiento_real':
            readoquinamientoReal == null ? null : (readoquinamientoReal! ? 1 : 0),
        'latitud_real': latitudReal,
        'longitud_real': longitudReal,
        'foto': foto,
        'motivo': motivo,
        'enviada': enviada ? 1 : 0,
        'error_envio': errorEnvio,
      };

  /// Cuerpo para `POST /api/descargar.php` (la foto va aparte, como archivo).
  Map<String, Object?> aJsonDescarga() => estado == EstadoOrden.realizada
      ? {
          'id': id,
          'realizada': true,
          'fecha_realizacion': fechaRealizacion,
          'trabajo_id': trabajoRealizadoId ?? trabajoId,
          'detalle': detalle,
          'readoquinamiento': readoquinamientoReal ?? false,
          'latitud': latitudReal,
          'longitud': longitudReal,
        }
      : {
          'id': id,
          'realizada': false,
          'fecha_realizacion': fechaRealizacion,
          'motivo_no_realizada': motivo,
          'latitud': latitudReal,
          'longitud': longitudReal,
        };
}
