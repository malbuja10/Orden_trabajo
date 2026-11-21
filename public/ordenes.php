<?php
// d) Reporte de Ordenes de trabajo finalizadas y pendientes (con exportacion CSV).
declare(strict_types=1);
require __DIR__ . '/../src/web.php';
requerir_login();

$desde = fecha_valida($_GET['desde'] ?? '') ?? date('Y-m-01');
$hasta = fecha_valida($_GET['hasta'] ?? '') ?? date('Y-m-d');
if ($hasta < $desde) {
    [$desde, $hasta] = [$hasta, $desde];
}
$estado = isset(ESTADOS_ORDEN[$_GET['estado'] ?? '']) ? $_GET['estado'] : '';
$trabajo = (int) ($_GET['trabajo'] ?? 0);
$observacion = (int) ($_GET['observacion'] ?? 0);
$operador = (int) ($_GET['operador'] ?? 0);

$where = ['fecha BETWEEN ? AND ?'];
$params = [$desde, $hasta];
if ($estado) {
    $where[] = 'estado = ?';
    $params[] = $estado;
} else {
    $where[] = "estado <> 'anulada'";
}
if ($trabajo) {
    $where[] = 'trabajo_id = ?';
    $params[] = $trabajo;
}
if ($observacion) {
    $where[] = 'observacion_id = ?';
    $params[] = $observacion;
}
if ($operador) {
    $where[] = '? IN (operador1_id, operador2_id)';
    $params[] = $operador;
}
$ordenes = consulta_ordenes(implode(' AND ', $where), $params, 'fecha, id');

if (($_GET['formato'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ordenes_' . $desde . '_' . $hasta . '.csv"');
    $f = fopen('php://output', 'w');
    fwrite($f, "\xEF\xBB\xBF"); // BOM para que Excel reconozca UTF-8
    fputcsv($f, ['Orden', 'Fecha reclamo', 'Cuenta', 'Ciudadano', 'Dirección', 'Teléfono', 'Reclamo', 'Observación', 'Estado',
        'Fecha registro', 'Operador 1', 'Operador 2', 'Readoquinamiento', 'Detalle', 'Fecha campo', 'Detalle campo', 'Latitud', 'Longitud'], ';');
    foreach ($ordenes as $o) {
        fputcsv($f, [$o['id'], $o['fecha'], $o['cuenta'], $o['nombre'], $o['direccion'], $o['telefono'], $o['trabajo'], $o['observacion'],
            ESTADOS_ORDEN[$o['estado']], $o['fecha_registro'], $o['operador1'], $o['operador2'],
            $o['estado'] === 'finalizada' ? ($o['readoquinamiento'] ? 'Sí' : 'No') : '', $o['detalle'],
            $o['fecha_campo'], $o['detalle_campo'], $o['lat'], $o['lng']], ';');
    }
    exit;
}

$conteo = array_count_values(array_column($ordenes, 'estado'));
$total = count($ordenes);

cabecera('Reporte de Órdenes de Trabajo');
?>
<form method="get" class="tarjeta filtros">
  <label>Reclamos desde <input type="date" name="desde" value="<?= h($desde) ?>"></label>
  <label>hasta <input type="date" name="hasta" value="<?= h($hasta) ?>"></label>
  <label>Estado <select name="estado"><?= opciones(ESTADOS_ORDEN, $estado, 'Todas (sin anuladas)') ?></select></label>
  <label>Reclamo <select name="trabajo"><?= opciones(lista_trabajos(false), $trabajo, 'Todos') ?></select></label>
  <label>Observación <select name="observacion"><?= opciones(lista_observaciones(false), $observacion, 'Todas') ?></select></label>
  <label>Operador <select name="operador"><?= opciones(lista_operadores(false), $operador, 'Todos') ?></select></label>
  <button type="submit" class="primario">Consultar</button>
  <button type="submit" name="formato" value="csv">Exportar CSV</button>
  <button type="button" onclick="window.print()">Imprimir</button>
</form>

<div class="indicadores">
  <div class="tarjeta"><span>Total</span><b><?= $total ?></b></div>
  <div class="tarjeta"><span>Finalizadas</span><b class="verde"><?= $conteo['finalizada'] ?? 0 ?></b></div>
  <div class="tarjeta"><span>Pendientes</span><b class="ambar"><?= $conteo['pendiente'] ?? 0 ?></b></div>
  <div class="tarjeta"><span>% Cumplimiento</span><b><?= $total ? round(100 * ($conteo['finalizada'] ?? 0) / $total) . '%' : '—' ?></b></div>
</div>

<section class="tarjeta">
  <div class="tabla-scroll">
  <table>
    <thead><tr><th>Orden</th><th>Fecha reclamo</th><th>Cuenta</th><th>Ciudadano</th><th>Dirección</th><th>Reclamo</th><th>Observaciones</th><th>Estado</th><th>Fecha registro</th><th>Realizado por</th><th>Readoq.</th><th>Detalle</th><th>Foto</th></tr></thead>
    <tbody>
    <?php foreach ($ordenes as $o): ?>
      <tr>
        <td class="nowrap"><a href="orden.php?id=<?= h($o['id']) ?>">N° <?= h($o['id']) ?></a></td>
        <td class="nowrap"><?= fecha_es($o['fecha']) ?></td>
        <td><?= h($o['cuenta']) ?></td>
        <td><?= h($o['nombre']) ?></td>
        <td><?= h($o['direccion']) ?></td>
        <td><span class="punto" style="background:<?= h($o['trabajo_color']) ?>"></span><?= h($o['trabajo']) ?></td>
        <td><?= h($o['observacion']) ?></td>
        <td><span class="estado <?= h($o['estado']) ?>"><?= h(ESTADOS_ORDEN[$o['estado']]) ?></span></td>
        <td class="nowrap"><?= fecha_es($o['fecha_registro']) ?></td>
        <td><?= h(grupo_texto($o)) ?></td>
        <td><?= $o['estado'] === 'finalizada' ? ($o['readoquinamiento'] ? 'Sí' : 'No') : '' ?></td>
        <td><?= h($o['detalle'] ?? $o['detalle_campo']) ?></td>
        <td><?= $o['foto'] ? '<a href="orden.php?id=' . h($o['id']) . '">📷</a>' : '' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$ordenes): ?><tr><td colspan="13" class="suave centro">No hay órdenes con estos filtros.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</section>
<?php pie();
