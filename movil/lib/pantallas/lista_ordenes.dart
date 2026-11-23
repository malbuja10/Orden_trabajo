import 'package:material_ui/material_ui.dart';

import '../datos/repositorio.dart';
import '../estilo.dart';
import '../modelos.dart';

/// Lista de órdenes guardadas en el teléfono, con búsqueda y filtro por estado.
class ListaOrdenes extends StatefulWidget {
  const ListaOrdenes({super.key, required this.repo, required this.alAbrir, this.alCargar});
  final Repositorio repo;
  final void Function(Orden) alAbrir;
  final VoidCallback? alCargar;

  @override
  State<ListaOrdenes> createState() => _ListaOrdenesState();
}

class _ListaOrdenesState extends State<ListaOrdenes> {
  EstadoOrden? _filtro = EstadoOrden.pendiente; // null = todas
  final _buscar = TextEditingController();
  String _texto = '';

  @override
  void dispose() {
    _buscar.dispose();
    super.dispose();
  }

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
                label: const Text('Cargar órdenes pendientes'),
              ),
            ],
          ),
        ),
      );
    }

    int cuenta(EstadoOrden e) => todas.where((o) => o.estado == e).length;
    final palabras = _texto.toLowerCase().split(RegExp(r'\s+')).where((p) => p.isNotEmpty);
    final visibles = [
      for (final o in todas)
        if ((_filtro == null || o.estado == _filtro) &&
            palabras.every((p) => o.textoBusqueda.contains(p)))
          o,
    ];

    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 10, 12, 0),
          child: TextField(
            controller: _buscar,
            decoration: InputDecoration(
              hintText: 'Buscar N.º, cuenta, nombre, dirección…',
              prefixIcon: const Icon(Icons.search),
              isDense: true,
              suffixIcon: _texto.isEmpty
                  ? null
                  : IconButton(
                      tooltip: 'Borrar búsqueda',
                      icon: const Icon(Icons.close),
                      onPressed: () => setState(() {
                        _buscar.clear();
                        _texto = '';
                      }),
                    ),
            ),
            onChanged: (v) => setState(() => _texto = v),
          ),
        ),
        SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
          child: Row(
            children: [
              for (final e in EstadoOrden.values) ...[
                ChoiceChip(
                  avatar: Icon(iconoEstado(e), color: colorEstado(e), size: 18),
                  label: Text('${e.plural} (${cuenta(e)})'),
                  selected: _filtro == e,
                  onSelected: (_) => setState(() => _filtro = e),
                ),
                const SizedBox(width: 8),
              ],
              ChoiceChip(
                label: Text('Todas (${todas.length})'),
                selected: _filtro == null,
                onSelected: (_) => setState(() => _filtro = null),
              ),
            ],
          ),
        ),
        const Divider(height: 1),
        Expanded(
          child: visibles.isEmpty
              ? const Center(child: Text('No hay órdenes que coincidan.'))
              : ListView.separated(
                  itemCount: visibles.length,
                  separatorBuilder: (_, _) => const Divider(height: 1),
                  itemBuilder: (context, i) => _Fila(
                    orden: visibles[i],
                    catalogos: widget.repo.catalogos,
                    alAbrir: widget.alAbrir,
                  ),
                ),
        ),
      ],
    );
  }
}

class _Fila extends StatelessWidget {
  const _Fila({required this.orden, required this.catalogos, required this.alAbrir});
  final Orden orden;
  final Catalogos catalogos;
  final void Function(Orden) alAbrir;

  @override
  Widget build(BuildContext context) {
    final o = orden;
    final tema = Theme.of(context);
    final grupo = [
      catalogos.nombre(TipoCatalogo.operador, o.operador1Id),
      catalogos.nombre(TipoCatalogo.operador, o.operador2Id),
    ].nonNulls.join(' / ');
    final trabajo = o.pendiente
        ? o.trabajo
        : (catalogos.nombre(TipoCatalogo.trabajo, o.trabajoRegId) ?? o.trabajo);
    return ListTile(
      leading: Icon(iconoEstado(o.estado), color: colorEstado(o.estado), size: 32),
      title: Text(o.direccion, maxLines: 2, overflow: TextOverflow.ellipsis),
      subtitle: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('N.º ${o.id} · $trabajo · ${fechaCorta(o.fecha)}'),
          Text(
            [if (o.cuenta != null) 'Cta. ${o.cuenta}', o.nombre].join(' · '),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
          ),
          if (!o.pendiente && grupo.isNotEmpty)
            Text('${fechaCorta(o.fechaRegistro)} · $grupo',
                maxLines: 1, overflow: TextOverflow.ellipsis),
          if (o.errorEnvio != null)
            Text('Rechazada: ${o.errorEnvio}', style: TextStyle(color: tema.colorScheme.error)),
          if (o.avisoEnvio != null)
            Text(o.avisoEnvio!, style: TextStyle(color: tema.colorScheme.onSurfaceVariant)),
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
