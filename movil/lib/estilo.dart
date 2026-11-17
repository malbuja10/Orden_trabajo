import 'package:material_ui/material_ui.dart';

import 'modelos.dart';

const colorPrimario = Color(0xFF0B5C8A);

Color colorEstado(EstadoOrden e) => switch (e) {
      EstadoOrden.pendiente => const Color(0xFFE08A00),
      EstadoOrden.realizada => const Color(0xFF2E8B57),
      EstadoOrden.noRealizada => const Color(0xFFC0392B),
    };

IconData iconoEstado(EstadoOrden e) => switch (e) {
      EstadoOrden.pendiente => Icons.schedule,
      EstadoOrden.realizada => Icons.check_circle,
      EstadoOrden.noRealizada => Icons.cancel,
    };

/// `2026-11-14` o `2026-11-14T10:35:00` → `14/11/2026` o `14/11/2026 10:35`.
String fechaCorta(String? iso) {
  if (iso == null || iso.length < 10) return iso ?? '';
  final f = '${iso.substring(8, 10)}/${iso.substring(5, 7)}/${iso.substring(0, 4)}';
  return iso.length >= 16 ? '$f ${iso.substring(11, 16)}' : f;
}

/// Fecha y hora local en el formato del API: `2026-11-14T10:35:00`.
String ahoraIso() => DateTime.now().toIso8601String().substring(0, 19);

void avisar(BuildContext context, String mensaje, {bool error = false}) {
  final m = ScaffoldMessenger.of(context);
  m.hideCurrentSnackBar();
  m.showSnackBar(SnackBar(
    content: Text(mensaje),
    backgroundColor: error ? Theme.of(context).colorScheme.error : null,
    duration: Duration(seconds: error ? 6 : 4),
  ));
}
