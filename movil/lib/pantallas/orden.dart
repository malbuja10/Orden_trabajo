import 'dart:io';

import 'package:image_picker/image_picker.dart';
import 'package:material_ui/material_ui.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';

import '../datos/repositorio.dart';
import '../estilo.dart';
import '../modelos.dart';
import '../ubicacion.dart';

/// Detalle de una orden y registro del trabajo realizado por el supervisor:
/// fecha, operadores, trabajo realizado, observación, detalle y readoquinamiento
/// (más ubicación GPS y foto, opcionales). Todo se guarda en el teléfono y se
/// puede editar hasta que se descarga al servidor.
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
  Catalogos get _cat => widget.repo.catalogos;

  late String? _fecha = o.fechaRegistro;
  late int? _op1 = o.operador1Id;
  late int? _op2 = o.operador2Id;
  late int? _trabajoId = o.trabajoRegId ?? o.trabajoId;
  late int? _observacionId = o.pendiente ? o.observacionId : o.observacionRegId;
  late bool _readoquinamiento = o.readoquinamiento;
  late final _detalle = TextEditingController(text: o.detalle);
  late double? _lat = o.latitudCampo;
  late double? _lng = o.longitudCampo;
  late String? _foto = o.foto;

  bool _buscandoGps = false;
  String? _avisoGps;
  bool _guardando = false;
  bool _cambios = false;

  bool get _soloLectura => o.enviada;

  /// La fecha del trabajo debe estar entre la fecha del reclamo y hoy (igual que en la Web).
  DateTime get _primerDia => DateTime.tryParse(o.fecha) ?? DateTime(2000);
  DateTime get _hoy {
    final h = DateTime.now();
    return DateTime(h.year, h.month, h.day);
  }

  bool _fechaValida(String? f) {
    final d = f == null ? null : DateTime.tryParse(f);
    return d != null && !d.isBefore(_primerDia) && !d.isAfter(_hoy);
  }

  @override
  void initState() {
    super.initState();
    if (_soloLectura) return;
    if (o.pendiente) _proponerUltimo();
    if (_lat == null) _tomarUbicacion(silencioso: true);
  }

  /// Propone la fecha y los operadores del último registro (suelen repetirse en el día).
  Future<void> _proponerUltimo() async {
    final u = await widget.repo.ultimoRegistro();
    if (!mounted) return;
    bool existe(int? id) => _cat.operadores.any((x) => x.id == id);
    setState(() {
      _fecha ??= _fechaValida(u.fecha) ? u.fecha : (_fechaValida(hoyIso()) ? hoyIso() : null);
      if (_op1 == null && existe(u.op1)) _op1 = u.op1;
      if (_op2 == null && existe(u.op2) && u.op2 != _op1) _op2 = u.op2;
    });
  }

  @override
  void dispose() {
    _detalle.dispose();
    super.dispose();
  }

  void _cambio(VoidCallback f) => setState(() {
        f();
        _cambios = true;
      });

  Future<void> _elegirFecha() async {
    final actual = _fecha == null ? null : DateTime.tryParse(_fecha!);
    final d = await showDatePicker(
      context: context,
      initialDate: (actual != null && _fechaValida(_fecha)) ? actual : _hoy,
      firstDate: _primerDia.isAfter(_hoy) ? _hoy : _primerDia,
      lastDate: _hoy,
      helpText: 'Fecha del trabajo',
    );
    if (d != null) _cambio(() => _fecha = fechaIso(d));
  }

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
    if (!_form.currentState!.validate()) return;
    setState(() => _guardando = true);
    final fotoAnterior = o.foto;
    o
      ..estado = EstadoOrden.finalizada
      ..fechaRegistro = _fecha
      ..operador1Id = _op1
      ..operador2Id = _op2
      ..trabajoRegId = _trabajoId
      ..observacionRegId = _observacionId
      ..readoquinamiento = _readoquinamiento
      ..detalle = _detalle.text.trim().isEmpty ? null : _detalle.text.trim()
      ..fechaCampo = o.fechaCampo ?? ahoraIso()
      ..latitudCampo = _lat
      ..longitudCampo = _lng
      ..foto = _foto;
    await widget.repo.guardarOrden(o);
    if (fotoAnterior != null && fotoAnterior != _foto) _borrar(fotoAnterior);
    if (!mounted) return;
    avisar(context, 'Orden N.º ${o.id} finalizada y guardada en el teléfono.');
    Navigator.pop(context);
  }

  Future<void> _volverAPendiente() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: const Text('Volver a pendiente'),
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
      ..fechaRegistro = null
      ..operador1Id = null
      ..operador2Id = null
      ..trabajoRegId = null
      ..observacionRegId = null
      ..readoquinamiento = false
      ..detalle = null
      ..fechaCampo = null
      ..latitudCampo = null
      ..longitudCampo = null
      ..foto = null;
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

  /// Opciones de un combo. Si el valor guardado ya no está en el catálogo (p. ej. un
  /// operador desactivado), se mantiene como opción para no perderlo.
  List<DropdownMenuItem<int?>> _opciones(TipoCatalogo tipo, int? actual,
      {String? vacio, String? nombreActual}) {
    final lista = _cat.de(tipo);
    return [
      if (vacio != null) DropdownMenuItem(value: null, child: Text(vacio)),
      if (actual != null && !lista.any((x) => x.id == actual))
        DropdownMenuItem(value: actual, child: Text(nombreActual ?? 'N.º $actual')),
      for (final x in lista) DropdownMenuItem(value: x.id, child: Text(x.nombre)),
    ];
  }

  @override
  Widget build(BuildContext context) {
    final tema = Theme.of(context);
    final sinOperadores = _cat.operadores.isEmpty;

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
                tooltip: 'Volver a pendiente',
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
              _DatosReclamo(orden: o),
              const SizedBox(height: 20),
              Text(o.pendiente ? 'Registrar trabajo realizado' : 'Trabajo realizado',
                  style: tema.textTheme.titleMedium),
              const SizedBox(height: 8),
              if (_soloLectura)
                Card(
                  color: tema.colorScheme.surfaceContainerHighest,
                  child: ListTile(
                    leading: const Icon(Icons.cloud_done),
                    title: const Text('Descargada al servidor'),
                    subtitle: Text(o.avisoEnvio ?? 'Ya no se puede modificar desde el teléfono.'),
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
              if (sinOperadores && !_soloLectura)
                Card(
                  color: tema.colorScheme.errorContainer,
                  child: const ListTile(
                    leading: Icon(Icons.warning_amber),
                    title: Text('No hay operadores en el teléfono'),
                    subtitle: Text('Cargue las órdenes con Internet para traer los catálogos.'),
                  ),
                ),
              IgnorePointer(
                ignoring: _soloLectura,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    const SizedBox(height: 8),
                    FormField<String>(
                      key: ValueKey(_fecha),
                      initialValue: _fecha,
                      validator: (_) => _fecha == null
                          ? 'Indique la fecha'
                          : (!_fechaValida(_fecha) && !_soloLectura
                              ? 'Debe estar entre ${fechaCorta(o.fecha)} y hoy'
                              : null),
                      builder: (campo) => InkWell(
                        onTap: _elegirFecha,
                        child: InputDecorator(
                          decoration: InputDecoration(
                            labelText: 'Fecha del trabajo',
                            prefixIcon: const Icon(Icons.event),
                            errorText: campo.errorText,
                          ),
                          child: Text(_fecha == null ? 'Seleccione' : fechaCorta(_fecha)),
                        ),
                      ),
                    ),
                    const SizedBox(height: 16),
                    DropdownButtonFormField<int?>(
                      key: ValueKey('op1-$_op1'),
                      initialValue: _op1,
                      isExpanded: true,
                      decoration: const InputDecoration(
                          labelText: 'Operador 1', prefixIcon: Icon(Icons.engineering_outlined)),
                      items: _opciones(TipoCatalogo.operador, _op1, vacio: '— Seleccione —'),
                      validator: (v) => v == null ? 'Seleccione el operador 1' : null,
                      onChanged: (v) => _cambio(() {
                        _op1 = v;
                        if (_op2 == v) _op2 = null;
                      }),
                    ),
                    const SizedBox(height: 16),
                    DropdownButtonFormField<int?>(
                      key: ValueKey('op2-$_op2'),
                      initialValue: _op2,
                      isExpanded: true,
                      decoration: const InputDecoration(
                          labelText: 'Operador 2 (opcional)',
                          prefixIcon: Icon(Icons.engineering_outlined)),
                      items: _opciones(TipoCatalogo.operador, _op2, vacio: '— Ninguno —'),
                      validator: (v) =>
                          v != null && v == _op1 ? 'Debe ser distinto del operador 1' : null,
                      onChanged: (v) => _cambio(() => _op2 = v),
                    ),
                    const SizedBox(height: 16),
                    DropdownButtonFormField<int?>(
                      initialValue: _trabajoId,
                      isExpanded: true,
                      decoration: const InputDecoration(
                          labelText: 'Trabajo realizado', prefixIcon: Icon(Icons.build_outlined)),
                      items: _opciones(TipoCatalogo.trabajo, _trabajoId,
                          nombreActual: _trabajoId == o.trabajoId ? o.trabajo : null),
                      validator: (v) => v == null ? 'Seleccione el trabajo' : null,
                      onChanged: (v) => _cambio(() => _trabajoId = v),
                    ),
                    const SizedBox(height: 16),
                    DropdownButtonFormField<int?>(
                      initialValue: _observacionId,
                      isExpanded: true,
                      decoration: const InputDecoration(
                          labelText: 'Observación', prefixIcon: Icon(Icons.notes)),
                      items: _opciones(TipoCatalogo.observacion, _observacionId,
                          vacio: '— Ninguna —',
                          nombreActual: _observacionId == o.observacionId ? o.observacion : null),
                      onChanged: (v) => _cambio(() => _observacionId = v),
                    ),
                    const SizedBox(height: 16),
                    TextFormField(
                      controller: _detalle,
                      readOnly: _soloLectura,
                      decoration: const InputDecoration(labelText: 'Detalle del trabajo (si existe)'),
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
                    const Divider(height: 24),
                    Text('Datos de campo (opcional)', style: tema.textTheme.titleSmall),
                    _ubicacion(),
                    _fotoWidget(),
                  ],
                ),
              ),
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
                    icon: const Icon(Icons.check_circle),
                    label: Text(o.pendiente ? 'Guardar como finalizada' : 'Guardar cambios'),
                  ),
                ),
              ),
      ),
    );
  }

  Widget _ubicacion() {
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

  Widget _fotoWidget() {
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

class _DatosReclamo extends StatelessWidget {
  const _DatosReclamo({required this.orden});
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
              Text(fechaCorta(o.fecha), style: tema.textTheme.bodySmall),
            ]),
            const Divider(height: 24),
            dato(Icons.place_outlined, 'Dirección', o.direccion),
            dato(Icons.badge_outlined, 'Cuenta', o.cuenta),
            dato(Icons.person_outline, 'Cliente', o.nombre),
            dato(Icons.phone_outlined, 'Teléfono', o.telefono),
            dato(Icons.notes, 'Observación del reclamo', o.observacion),
          ],
        ),
      ),
    );
  }
}
