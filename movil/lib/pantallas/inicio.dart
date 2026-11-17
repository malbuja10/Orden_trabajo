import 'package:material_ui/material_ui.dart';

import '../datos/api.dart';
import '../datos/repositorio.dart';
import '../estilo.dart';
import '../modelos.dart';
import 'lista_ordenes.dart';
import 'mapa.dart';
import 'orden.dart';

/// Pantalla principal: lista y mapa de órdenes, con las acciones de
/// cargar (servidor → teléfono) y descargar (teléfono → servidor).
class PantallaInicio extends StatefulWidget {
  const PantallaInicio({super.key, required this.repo});
  final Repositorio repo;

  @override
  State<PantallaInicio> createState() => _PantallaInicioState();
}

class _PantallaInicioState extends State<PantallaInicio> {
  int _pestana = 0;
  String? _ocupado; // texto de la operación en curso

  Repositorio get repo => widget.repo;

  Future<void> _sincronizar(String titulo, Future<String> Function() accion) async {
    if (_ocupado != null) return;
    setState(() => _ocupado = titulo);
    try {
      final mensaje = await accion();
      if (mounted) avisar(context, mensaje);
    } on SinConexion {
      if (mounted) {
        avisar(context, 'No hay conexión con el servidor. Intente cuando tenga Internet.',
            error: true);
      }
    } on ErrorApi catch (e) {
      if (!mounted) return;
      if (e.noAutorizado) {
        await showDialog<void>(
          context: context,
          builder: (c) => AlertDialog(
            title: const Text('Sesión vencida'),
            content: const Text(
                'Debe iniciar sesión nuevamente con Internet. '
                'Lo registrado en el teléfono se conserva.'),
            actions: [
              FilledButton(onPressed: () => Navigator.pop(c), child: const Text('Aceptar')),
            ],
          ),
        );
        repo.cerrarSesion();
        return;
      }
      avisar(context, e.mensaje, error: true);
    } finally {
      if (mounted) setState(() => _ocupado = null);
    }
  }

  Future<void> _cargar() => _sincronizar('Cargando órdenes…', () async {
        final r = await repo.cargarOrdenes();
        final extra = [
          if (r.nuevas > 0) '${r.nuevas} nuevas',
          if (r.retiradas > 0) '${r.retiradas} retiradas',
        ].join(', ');
        return 'Órdenes asignadas: ${r.recibidas}${extra.isEmpty ? '' : ' ($extra)'}.';
      });

  Future<void> _descargar() async {
    final n = repo.porEnviar;
    if (n == 0) {
      avisar(context, 'No hay órdenes registradas por descargar.');
      return;
    }
    await _sincronizar('Descargando $n…', () async {
      final r = await repo.descargarRealizadas(
        progreso: (hechas, total) {
          if (mounted) setState(() => _ocupado = 'Descargando ${hechas + 1} de $total…');
        },
      );
      return [
        'Descargadas: ${r.enviadas}.',
        if (r.rechazadas > 0) 'Rechazadas por el servidor: ${r.rechazadas} (ver detalle en la lista).',
        if (r.interrumpida) 'Se perdió la conexión; el resto queda pendiente.',
      ].join(' ');
    });
  }

  Future<void> _salir() async {
    final n = repo.porEnviar;
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: const Text('Cerrar sesión'),
        content: Text(n > 0
            ? 'Hay $n órdenes sin descargar. Quedan guardadas en el teléfono '
                'y podrá descargarlas al volver a ingresar.'
            : '¿Desea salir?'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c, false), child: const Text('Cancelar')),
          FilledButton(onPressed: () => Navigator.pop(c, true), child: const Text('Salir')),
        ],
      ),
    );
    if (ok == true) repo.cerrarSesion();
  }

  void _abrir(Orden o) {
    Navigator.of(context).push(
      MaterialPageRoute<void>(builder: (_) => PantallaOrden(repo: repo, orden: o)),
    );
  }

  @override
  Widget build(BuildContext context) {
    final ocupado = _ocupado != null;
    final porEnviar = repo.porEnviar;
    return Scaffold(
      appBar: AppBar(
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('Órdenes de Trabajo'),
            Text(repo.usuario?.nombre ?? '', style: Theme.of(context).textTheme.bodySmall),
          ],
        ),
        actions: [
          IconButton(
            tooltip: 'Cargar órdenes del servidor',
            icon: const Icon(Icons.cloud_download_outlined),
            onPressed: ocupado ? null : _cargar,
          ),
          IconButton(
            tooltip: 'Descargar órdenes realizadas al servidor',
            icon: Badge(
              isLabelVisible: porEnviar > 0,
              label: Text('$porEnviar'),
              child: const Icon(Icons.cloud_upload_outlined),
            ),
            onPressed: ocupado ? null : _descargar,
          ),
          IconButton(
            tooltip: 'Cerrar sesión',
            icon: const Icon(Icons.logout),
            onPressed: ocupado ? null : _salir,
          ),
        ],
        bottom: ocupado
            ? PreferredSize(
                preferredSize: const Size.fromHeight(28),
                child: Column(
                  children: [
                    Text(_ocupado!, style: Theme.of(context).textTheme.bodySmall),
                    const SizedBox(height: 4),
                    const LinearProgressIndicator(),
                  ],
                ),
              )
            : null,
      ),
      body: IndexedStack(
        index: _pestana,
        children: [
          ListaOrdenes(repo: repo, alAbrir: _abrir, alCargar: ocupado ? null : _cargar),
          MapaOrdenes(repo: repo, alAbrir: _abrir, visible: _pestana == 1),
        ],
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _pestana,
        onDestinationSelected: (i) => setState(() => _pestana = i),
        destinations: const [
          NavigationDestination(icon: Icon(Icons.list_alt), label: 'Órdenes'),
          NavigationDestination(icon: Icon(Icons.map_outlined), label: 'Mapa'),
        ],
      ),
    );
  }
}
