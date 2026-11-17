import 'dart:io';

import 'package:material_ui/material_ui.dart';
import 'package:image_picker/image_picker.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';

import '../datos/repositorio.dart';
import '../estilo.dart';
import '../modelos.dart';
import '../ubicacion.dart';

/// Detalle de una orden y registro de su resultado (realizada / no realizada).
/// Todo se guarda en el teléfono; se puede editar hasta que se descarga al servidor.
class PantallaOrden extends StatefulWidget {
  const PantallaOrden({super.key, required this.repo, required this.orden});
  final Repositorio repo;
  final Orden orden;

  @override
  State<PantallaOrden> createState() => _PantallaOrdenState();
}

class _PantallaOrdenState extends State<PantallaOrden> {
  final _form = GlobalKey<FormState>();
  late final Orden o = widget.orden;

  late EstadoOrden _estado = o.estado;
  late int? _trabajoId = o.trabajoRealizadoId ?? o.trabajoId;
  late bool _readoquinamiento = o.readoquinamientoReal ?? o.readoquinamiento;
  late final _detalle = TextEditingController(text: o.detalle);
  late final _motivo = TextEditingController(text: o.motivo);
  late double? _lat = o.latitudReal;
  late double? _lng = o.longitudReal;
  late String? _foto = o.foto;

  bool _buscandoGps = false;
  String? _avisoGps;
  bool _guardando = false;
  bool _cambios = false;

  bool get _soloLectura => o.enviada;

  @override
  void initState() {
    super.initState();
    if (!_soloLectura && _lat == null) _tomarUbicacion(silencioso: true);
  }

  @override
  void dispose() {
    _detalle.dispose();
    _motivo.dispose();
    super.dispose();
  }

  void _cambio(VoidCallback f) => setState(() {
        f();
        _cambios = true;
      });

  Future<void> _tomarUbicacion({bool silencioso = false}) async {
    if (_buscandoGps) return;
    setState(() {
      _buscandoGps = true;
      _avisoGps = null;
    });
    try {
      final u = await ubicacionActual();
      if (!mounted) return;
      setState(() {
        _lat = u.lat;
        _lng = u.lng;
        _avisoGps = 'Precisión ±${u.precision.round()} m';
        if (!silencioso) _cambios = true;
      });
    } on ErrorUbicacion catch (e) {
      if (mounted) setState(() => _avisoGps = e.mensaje);
    } catch (e) {
      if (mounted) setState(() => _avisoGps = 'No se pudo obtener la ubicación.');
    } finally {
      if (mounted) setState(() => _buscandoGps = false);
    }
  }

  Future<void> _tomarFoto(ImageSource origen) async {
    try {
      final x = await ImagePicker().pickImage(source: origen, maxWidth: 1600, imageQuality: 75);
      if (x == null) return;
      final dir = Directory(p.join((await getApplicationDocumentsDirectory()).path, 'fotos'));
      await dir.create(recursive: true);
      final ext = p.extension(x.path).isEmpty ? '.jpg' : p.extension(x.path);
      final destino =
          p.join(dir.path, 'orden_${o.id}_${DateTime.now().millisecondsSinceEpoch}$ext');
      await File(x.path).copy(destino);
      if (!mounted) return;
      final anterior = _foto;
      _cambio(() => _foto = destino);
      // Una foto tomada en esta misma edición y reemplazada ya no sirve.
      if (anterior != null && anterior != o.foto) _borrar(anterior);
    } catch (e) {
      if (mounted) avisar(context, 'No se pudo obtener la foto: $e', error: true);
    }
  }

  void _borrar(String ruta) {
    try {
      File(ruta).deleteSync();
    } on FileSystemException {
      // sin importancia
    }
  }

  Future<void> _guardar() async {
    if (_estado == EstadoOrden.pendiente) {
      avisar(context, 'Indique si la orden fue realizada o no.', error: true);
      return;
    }
    if (!_form.currentState!.validate()) return;
    setState(() => _guardando = true);
    final fotoAnterior = o.foto;
    final realizada = _estado == EstadoOrden.realizada;
    o
      ..estado = _estado
      ..fechaRealizacion = o.fechaRealizacion ?? ahoraIso()
      ..latitudReal = _lat
      ..longitudReal = _lng
      ..trabajoRealizadoId = realizada ? _trabajoId : null
      ..detalle = realizada ? _texto(_detalle) : null
      ..readoquinamientoReal = realizada ? _readoquinamiento : null
      ..foto = _foto
      ..motivo = realizada ? null : _texto(_motivo);
    await widget.repo.guardarOrden(o);
    if (fotoAnterior != null && fotoAnterior != _foto) _borrar(fotoAnterior);
    if (!mounted) return;
    avisar(context, 'Orden N.º ${o.id} guardada en el teléfono.');
    Navigator.pop(context);
  }

  Future<void> _volverAPendiente() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: const Text('Volver a «por realizar»'),
        content: const Text('Se borrará lo registrado para esta orden (incluida la foto).'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c, false), child: const Text('Cancelar')),
          FilledButton(onPressed: () => Navigator.pop(c, true), child: const Text('Borrar registro')),
        ],
      ),
    );
    if (ok != true) return;
    for (final f in {o.foto, _foto}.nonNulls) {
      _borrar(f);
    }
    o
      ..estado = EstadoOrden.pendiente
      ..fechaRealizacion = null
      ..latitudReal = null
      ..longitudReal = null
      ..trabajoRealizadoId = null
      ..detalle = null
      ..readoquinamientoReal = null
      ..foto = null
      ..motivo = null;
    await widget.repo.guardarOrden(o);
    if (mounted) Navigator.pop(context);
  }

  /// Al salir sin guardar: confirmar y descartar la foto nueva, si la hay.
  Future<void> _salir() async {
    if (_cambios && !_soloLectura) {
      final ok = await showDialog<bool>(
        context: context,
        builder: (c) => AlertDialog(
          title: const Text('¿Salir sin guardar?'),
          content: const Text('Se perderán los cambios de esta orden.'),
          actions: [
            TextButton(onPressed: () => Navigator.pop(c, false), child: const Text('Seguir editando')),
            FilledButton(onPressed: () => Navigator.pop(c, true), child: const Text('Salir')),
          ],
        ),
      );
      if (ok != true) return;
      if (_foto != null && _foto != o.foto) _borrar(_foto!);
    }
    if (mounted) Navigator.pop(context);
  }

  static String? _texto(TextEditingController c) {
    final s = c.text.trim();
    return s.isEmpty ? null : s;
  }

  @override
  Widget build(BuildContext context) {
    final tema = Theme.of(context);
    final trabajos = widget.repo.trabajos;
    // El trabajo asignado siempre debe poder elegirse, aunque ya no esté en el catálogo.
    final opciones = [
      if (!trabajos.any((t) => t.id == o.trabajoId))
        Trabajo(id: o.trabajoId, nombre: o.trabajo, color: '#888888'),
      ...trabajos,
    ];
    if (!opciones.any((t) => t.id == _trabajoId)) _trabajoId = o.trabajoId;

    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (salio, _) {
        if (!salio) _salir();
      },
      child: Scaffold(
        appBar: AppBar(
          title: Text('Orden N.º ${o.id}'),
          actions: [
            if (!_soloLectura && !o.pendiente)
              IconButton(
                tooltip: 'Volver a «por realizar»',
                icon: const Icon(Icons.restore),
                onPressed: _volverAPendiente,
              ),
          ],
        ),
        body: Form(
          key: _form,
          child: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              _DatosAsignacion(orden: o),
              const SizedBox(height: 20),
              Text('Resultado', style: tema.textTheme.titleMedium),
              const SizedBox(height: 8),
              if (_soloLectura)
                Card(
                  color: tema.colorScheme.surfaceContainerHighest,
                  child: const ListTile(
                    leading: Icon(Icons.cloud_done),
                    title: Text('Descargada al servidor'),
                    subtitle: Text('Ya no se puede modificar desde el teléfono.'),
                  ),
                ),
              if (o.errorEnvio != null)
                Card(
                  color: tema.colorScheme.errorContainer,
                  child: ListTile(
                    leading: const Icon(Icons.error_outline),
                    title: const Text('El servidor rechazó esta orden'),
                    subtitle: Text(o.errorEnvio!),
                  ),
                ),
              IgnorePointer(
                ignoring: _soloLectura,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    SegmentedButton<EstadoOrden>(
                      emptySelectionAllowed: true,
                      showSelectedIcon: false,
                      segments: [
                        for (final e in [EstadoOrden.realizada, EstadoOrden.noRealizada])
                          ButtonSegment(
                            value: e,
                            icon: Icon(iconoEstado(e), color: colorEstado(e)),
                            label: Text(e.etiqueta),
                          ),
                      ],
                      selected: {if (_estado != EstadoOrden.pendiente) _estado},
                      onSelectionChanged: (s) {
                        if (s.isNotEmpty) _cambio(() => _estado = s.first);
                      },
                    ),
                    const SizedBox(height: 16),
                    if (_estado == EstadoOrden.realizada) ...[
                      DropdownButtonFormField<int>(
                        initialValue: _trabajoId,
                        isExpanded: true,
                        decoration: const InputDecoration(labelText: 'Trabajo realizado'),
                        items: [
                          for (final t in opciones)
                            DropdownMenuItem(value: t.id, child: Text(t.nombre)),
                        ],
                        onChanged: (v) => _cambio(() => _trabajoId = v),
                      ),
                      const SizedBox(height: 16),
                      TextFormField(
                        controller: _detalle,
                        decoration: const InputDecoration(labelText: 'Detalle del trabajo'),
                        minLines: 2,
                        maxLines: 5,
                        textCapitalization: TextCapitalization.sentences,
                        onChanged: (_) => _cambios = true,
                      ),
                      SwitchListTile(
                        contentPadding: EdgeInsets.zero,
                        title: const Text('Requiere readoquinamiento'),
                        value: _readoquinamiento,
                        onChanged: (v) => _cambio(() => _readoquinamiento = v),
                      ),
                    ],
                    if (_estado == EstadoOrden.noRealizada) ...[
                      TextFormField(
                        controller: _motivo,
                        decoration: const InputDecoration(labelText: 'Motivo por el que no se realizó'),
                        minLines: 2,
                        maxLines: 5,
                        textCapitalization: TextCapitalization.sentences,
                        onChanged: (_) => _cambios = true,
                        validator: (v) =>
                            (v == null || v.trim().isEmpty) ? 'Indique el motivo' : null,
                      ),
                      const SizedBox(height: 8),
                    ],
                    if (_estado != EstadoOrden.pendiente) ...[
                      _ubicacion(tema),
                      const SizedBox(height: 8),
                      _fotoWidget(tema),
                      if (o.fechaRealizacion != null)
                        Padding(
                          padding: const EdgeInsets.only(top: 12),
                          child: Text('Registrada el ${fechaCorta(o.fechaRealizacion)}',
                              style: tema.textTheme.bodySmall),
                        ),
                    ],
                  ],
                ),
              ),
              const SizedBox(height: 16),
            ],
          ),
        ),
        bottomNavigationBar: _soloLectura
            ? null
            : SafeArea(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
                  child: FilledButton.icon(
                    onPressed: _guardando ? null : _guardar,
                    style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
                    icon: const Icon(Icons.save),
                    label: const Text('Guardar'),
                  ),
                ),
              ),
      ),
    );
  }

  Widget _ubicacion(ThemeData tema) {
    return ListTile(
      contentPadding: EdgeInsets.zero,
      leading: Icon(Icons.gps_fixed, color: _lat == null ? Colors.grey : colorPrimario),
      title: Text(_lat == null
          ? 'Ubicación sin registrar'
          : '${_lat!.toStringAsFixed(6)}, ${_lng!.toStringAsFixed(6)}'),
      subtitle: _buscandoGps
          ? const Text('Buscando señal GPS…')
          : (_avisoGps == null ? null : Text(_avisoGps!)),
      trailing: _soloLectura
          ? null
          : _buscandoGps
              ? const SizedBox(
                  width: 24, height: 24, child: CircularProgressIndicator(strokeWidth: 2))
              : IconButton(
                  tooltip: 'Tomar ubicación actual',
                  icon: const Icon(Icons.refresh),
                  onPressed: _tomarUbicacion,
                ),
    );
  }

  Widget _fotoWidget(ThemeData tema) {
    final foto = _foto;
    final existe = foto != null && File(foto).existsSync();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (existe)
          ClipRRect(
            borderRadius: BorderRadius.circular(8),
            child: Image.file(File(foto), height: 220, fit: BoxFit.cover),
          ),
        if (!_soloLectura)
          Row(
            children: [
              Expanded(
                child: OutlinedButton.icon(
                  onPressed: () => _tomarFoto(ImageSource.camera),
                  icon: const Icon(Icons.photo_camera),
                  label: Text(existe ? 'Cambiar foto' : 'Tomar foto'),
                ),
              ),
              const SizedBox(width: 8),
              IconButton(
                tooltip: 'Elegir de la galería',
                icon: const Icon(Icons.photo_library_outlined),
                onPressed: () => _tomarFoto(ImageSource.gallery),
              ),
              if (existe)
                IconButton(
                  tooltip: 'Quitar foto',
                  icon: const Icon(Icons.delete_outline),
                  onPressed: () {
                    final actual = _foto;
                    _cambio(() => _foto = null);
                    if (actual != null && actual != o.foto) _borrar(actual);
                  },
                ),
            ],
          ),
      ],
    );
  }
}

class _DatosAsignacion extends StatelessWidget {
  const _DatosAsignacion({required this.orden});
  final Orden orden;

  @override
  Widget build(BuildContext context) {
    final o = orden;
    final tema = Theme.of(context);
    Widget dato(IconData icono, String etiqueta, String? valor) => valor == null || valor.isEmpty
        ? const SizedBox.shrink()
        : Padding(
            padding: const EdgeInsets.symmetric(vertical: 4),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(icono, size: 18, color: tema.colorScheme.onSurfaceVariant),
                const SizedBox(width: 8),
                Expanded(
                  child: Text.rich(TextSpan(children: [
                    TextSpan(
                        text: '$etiqueta: ',
                        style: TextStyle(color: tema.colorScheme.onSurfaceVariant)),
                    TextSpan(text: valor),
                  ])),
                ),
              ],
            ),
          );

    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(children: [
              Icon(iconoEstado(o.estado), color: colorEstado(o.estado)),
              const SizedBox(width: 8),
              Expanded(child: Text(o.trabajo, style: tema.textTheme.titleLarge)),
              Text(fechaCorta(o.fechaAsignacion), style: tema.textTheme.bodySmall),
            ]),
            const SizedBox(height: 8),
            Text(o.reclamo, style: tema.textTheme.bodyLarge),
            const Divider(height: 24),
            dato(Icons.place_outlined, 'Dirección', o.direccion),
            dato(Icons.person_outline, 'Cliente', o.nombre),
            dato(Icons.phone_outlined, 'Teléfono', o.telefono),
            dato(Icons.speed_outlined, 'Medidor', o.medidor),
            dato(Icons.notes, 'Obs. del reclamo', o.reclamoObservaciones),
            dato(Icons.assignment_outlined, 'Obs. del supervisor', o.observaciones),
            dato(Icons.groups_outlined, 'Grupo', o.grupo),
            dato(Icons.grid_view, 'Readoquinamiento previsto', o.readoquinamiento ? 'Sí' : 'No'),
          ],
        ),
      ),
    );
  }
}
