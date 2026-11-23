import 'package:material_ui/material_ui.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';

import '../datos/repositorio.dart';
import '../estilo.dart';
import '../modelos.dart';
import '../ubicacion.dart';

/// Mapa con las órdenes pendientes y finalizadas.
///
/// Los marcadores salen de la base local y funcionan sin Internet; el fondo
/// (OpenStreetMap) solo se ve con conexión o en las zonas ya visitadas (caché).
class MapaOrdenes extends StatefulWidget {
  const MapaOrdenes({super.key, required this.repo, required this.alAbrir, required this.visible});
  final Repositorio repo;
  final void Function(Orden) alAbrir;
  final bool visible;

  @override
  State<MapaOrdenes> createState() => _MapaOrdenesState();
}

class _MapaOrdenesState extends State<MapaOrdenes> {
  static const _centroInicial = LatLng(-0.2202, -78.5123);

  final _mapa = MapController();
  final _ver = {...EstadoOrden.values};
  LatLng? _yo;
  bool _listo = false;
  bool _encuadrado = false;

  List<Orden> get _conUbicacion => [
        for (final o in widget.repo.ordenes)
          if (o.lat != null && o.lng != null && _ver.contains(o.estado)) o,
      ];

  @override
  void didUpdateWidget(MapaOrdenes anterior) {
    super.didUpdateWidget(anterior);
    // La primera vez que se muestra la pestaña se encuadran todas las órdenes.
    if (widget.visible && !_encuadrado) {
      WidgetsBinding.instance.addPostFrameCallback((_) => _encuadrar());
    }
  }

  void _encuadrar() {
    if (!_listo || !mounted) return;
    final puntos = [for (final o in _conUbicacion) LatLng(o.lat!, o.lng!)];
    if (puntos.isEmpty) return;
    _encuadrado = true;
    if (puntos.length == 1) {
      _mapa.move(puntos.first, 16);
    } else {
      _mapa.fitCamera(CameraFit.coordinates(
        coordinates: puntos,
        padding: const EdgeInsets.fromLTRB(40, 80, 40, 40),
        maxZoom: 17,
      ));
    }
  }

  Future<void> _miUbicacion() async {
    try {
      final p = await ubicacionActual();
      if (!mounted) return;
      setState(() => _yo = LatLng(p.lat, p.lng));
      _mapa.move(_yo!, 16);
    } on ErrorUbicacion catch (e) {
      if (mounted) avisar(context, e.mensaje, error: true);
    }
  }

  void _detalle(Orden o) {
    showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      builder: (c) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 0, 20, 20),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(children: [
                Icon(iconoEstado(o.estado), color: colorEstado(o.estado)),
                const SizedBox(width: 8),
                Expanded(
                  child: Text('Orden N.º ${o.id} · ${o.estado.etiqueta}',
                      style: Theme.of(c).textTheme.titleMedium),
                ),
              ]),
              const SizedBox(height: 8),
              Text(o.direccion),
              Text([o.trabajo, if (o.cuenta != null) 'Cta. ${o.cuenta}', o.nombre].join(' · ')),
              if (o.observacion != null) Text(o.observacion!),
              const SizedBox(height: 12),
              Align(
                alignment: Alignment.centerRight,
                child: FilledButton(
                  onPressed: () {
                    Navigator.pop(c);
                    widget.alAbrir(o);
                  },
                  child: Text(o.pendiente ? 'Registrar finalizada' : (o.enviada ? 'Ver' : 'Editar')),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  @override
  void dispose() {
    _mapa.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final ordenes = _conUbicacion;
    final sinUbicacion =
        widget.repo.ordenes.where((o) => o.lat == null || o.lng == null).length;
    return Stack(
      children: [
        FlutterMap(
          mapController: _mapa,
          options: MapOptions(
            initialCenter: _centroInicial,
            initialZoom: 13,
            maxZoom: 19,
            interactionOptions: const InteractionOptions(
              flags: InteractiveFlag.all & ~InteractiveFlag.rotate,
            ),
            onMapReady: () {
              _listo = true;
              if (widget.visible) _encuadrar();
            },
          ),
          children: [
            TileLayer(
              urlTemplate: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
              userAgentPackageName: 'ec.gob.aguapotable.ordenes_trabajo',
              maxNativeZoom: 19,
            ),
            MarkerLayer(
              markers: [
                for (final o in ordenes)
                  Marker(
                    point: LatLng(o.lat!, o.lng!),
                    width: 44,
                    height: 44,
                    alignment: Alignment.topCenter, // la punta del marcador en el punto
                    child: GestureDetector(
                      onTap: () => _detalle(o),
                      child: Icon(
                        Icons.location_on,
                        size: 44,
                        color: colorEstado(o.estado),
                        shadows: const [Shadow(blurRadius: 3, color: Colors.black54)],
                      ),
                    ),
                  ),
                if (_yo != null)
                  Marker(
                    point: _yo!,
                    width: 22,
                    height: 22,
                    child: Container(
                      decoration: BoxDecoration(
                        color: Colors.blue,
                        shape: BoxShape.circle,
                        border: Border.all(color: Colors.white, width: 3),
                        boxShadow: const [BoxShadow(blurRadius: 3, color: Colors.black45)],
                      ),
                    ),
                  ),
              ],
            ),
            const SimpleAttributionWidget(source: Text('OpenStreetMap')),
          ],
        ),
        // Leyenda y filtro por estado
        Positioned(
          top: 8,
          left: 8,
          right: 8,
          child: Wrap(
            spacing: 6,
            runSpacing: 4,
            children: [
              for (final e in EstadoOrden.values)
                FilterChip(
                  avatar: Icon(Icons.location_on, color: colorEstado(e), size: 18),
                  label: Text(e.etiqueta),
                  selected: _ver.contains(e),
                  showCheckmark: false,
                  visualDensity: VisualDensity.compact,
                  backgroundColor: Colors.white70,
                  onSelected: (s) => setState(() => s ? _ver.add(e) : _ver.remove(e)),
                ),
            ],
          ),
        ),
        if (sinUbicacion > 0)
          Positioned(
            left: 8,
            bottom: 8,
            child: Card(
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                child: Text('$sinUbicacion sin ubicación'),
              ),
            ),
          ),
        Positioned(
          right: 12,
          bottom: 36,
          child: Column(
            children: [
              FloatingActionButton.small(
                heroTag: 'encuadrar',
                tooltip: 'Ver todas',
                onPressed: _encuadrar,
                child: const Icon(Icons.zoom_out_map),
              ),
              const SizedBox(height: 8),
              FloatingActionButton.small(
                heroTag: 'yo',
                tooltip: 'Mi ubicación',
                onPressed: _miUbicacion,
                child: const Icon(Icons.my_location),
              ),
            ],
          ),
        ),
      ],
    );
  }
}
