import 'package:material_ui/material_ui.dart';

import '../datos/repositorio.dart';
import '../estilo.dart';
import '../modelos.dart';

/// Lista de órdenes guardadas en el teléfono, filtrable por estado.
class ListaOrdenes extends StatefulWidget {
  const ListaOrdenes({super.key, required this.repo, required this.alAbrir, this.alCargar});
  final Repositorio repo;
  final void Function(Orden) alAbrir;
  final VoidCallback? alCargar;

  @override
  State<ListaOrdenes> createState() => _ListaOrdenesState();
}

class _ListaOrdenesState extends State<ListaOrdenes> {
  EstadoOrden? _filtro; // null = todas

  @override
  Widget build(BuildContext context) {
    final todas = widget.repo.ordenes;
    if (todas.isEmpty) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.inbox_outlined, size: 56, color: Colors.grey),
              const SizedBox(height: 12),
              const Text('No hay órdenes en el teléfono.', textAlign: TextAlign.center),
              const SizedBox(height: 16),
              FilledButton.icon(
                onPressed: widget.alCargar,
                icon: const Icon(Icons.cloud_download_outlined),
                label: const Text('Cargar órdenes'),
              ),
            ],
          ),
        ),
      );
    }

    int cuenta(EstadoOrden e) => todas.where((o) => o.estado == e).length;
    final visibles = _filtro == null ? todas : todas.where((o) => o.estado == _filtro).toList();

    return Column(
      children: [
        SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
          child: Row(
            children: [
              ChoiceChip(
                label: Text('Todas (${todas.length})'),
                selected: _filtro == null,
                onSelected: (_) => setState(() => _filtro = null),
              ),
              for (final e in EstadoOrden.values) ...[
                const SizedBox(width: 8),
                ChoiceChip(
                  avatar: Icon(iconoEstado(e), color: colorEstado(e), size: 18),
                  label: Text('${e.etiqueta} (${cuenta(e)})'),
                  selected: _filtro == e,
                  onSelected: (_) => setState(() => _filtro = e),
                ),
              ],
            ],
          ),
        ),
        const Divider(height: 1),
        Expanded(
          child: visibles.isEmpty
              ? const Center(child: Text('No hay órdenes en este estado.'))
              : ListView.separated(
                  itemCount: visibles.length,
                  separatorBuilder: (_, _) => const Divider(height: 1),
                  itemBuilder: (context, i) => _Fila(orden: visibles[i], alAbrir: widget.alAbrir),
                ),
        ),
      ],
    );
  }
}

class _Fila extends StatelessWidget {
  const _Fila({required this.orden, required this.alAbrir});
  final Orden orden;
  final void Function(Orden) alAbrir;

  @override
  Widget build(BuildContext context) {
    final o = orden;
    final tema = Theme.of(context);
    return ListTile(
      leading: Icon(iconoEstado(o.estado), color: colorEstado(o.estado), size: 32),
      title: Text(o.direccion, maxLines: 2, overflow: TextOverflow.ellipsis),
      subtitle: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('N.º ${o.id} · ${o.trabajo} · ${fechaCorta(o.fechaAsignacion)}'),
          Text(o.reclamo, maxLines: 1, overflow: TextOverflow.ellipsis),
          if (o.errorEnvio != null)
            Text('Rechazada: ${o.errorEnvio}', style: TextStyle(color: tema.colorScheme.error)),
        ],
      ),
      isThreeLine: true,
      trailing: switch (o) {
        Orden(pendiente: true) => const Icon(Icons.chevron_right),
        Orden(enviada: true) => const Tooltip(
            message: 'Descargada al servidor',
            child: Icon(Icons.cloud_done, color: Colors.grey),
          ),
        _ => const Tooltip(
            message: 'Por descargar (se puede editar)',
            child: Icon(Icons.edit_note, color: colorPrimario),
          ),
      },
      onTap: () => alAbrir(o),
    );
  }
}
