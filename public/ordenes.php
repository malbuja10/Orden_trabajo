<?php
// d) Reporte de Ordenes de trabajo realizadas y no realizadas (con exportacion CSV).
declare(strict_types=1);
require __DIR__ . '/../src/web.php';
requerir_login();

$desde = fecha_valida($_GET['desde'] ?? '') ?? date('Y-m-01');
$hasta = fecha_valida($_GET['hasta'] ?? '') ?? date('Y-m-d');
$estado = isset(ESTADOS_ORDEN[$_GET['estado'] ?? '']) ? $_GET['estado'] : '';
$trabajo = (int) ($_GET['trabajo'] ?? 0);
$operador = (int) ($_GET['operador'] ?? 0);

$where = ['o.fecha_asignacion BETWEEN ? AND ?'];
$params = [$desde, $hasta];
if ($estado) {
    $where[] = 'o.estado = ?';
    $params[] = $estado;
}
if ($trabajo) {
    $where[] = '? IN (o.trabajo_id, o.trabajo_realizado_id)';
    $params[] = $trabajo;
}
if ($operador) {
    $where[] = '? IN (o.operador1_id, o.operador2_id)';
    $params[] = $operador;
}
$ordenes = q(SQL_ORDENES . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY o.fecha_asignacion, o.id', $params)->fetchAll();

if (($_GET['formato'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ordenes_' . $desde . '_' . $hasta . '.csv"');
    $f = fopen('php://output', 'w');
    fwrite($f, "\xEF\xBB\xBF"); // BOM para que Excel reconozca UTF-8
    fputcsv($f, ['Orden', 'Reclamo', 'Fecha reclamo', 'Ciudadano', 'Dirección', 'Teléfono', 'Medidor', 'Reclamo', 'Fecha asignación',
        'Trabajo asignado', 'Grupo', 'Readoquinamiento', 'Estado', 'Fecha realización', 'Trabajo realizado', 'Detalle',
        'Readoquinamiento realizado', 'Motivo no realizada', 'Latitud', 'Longitud'], ';');
    foreach ($ordenes as $o) {
        fputcsv($f, [$o['id'], $o['reclamo_id'], $o['reclamo_fecha'], $o['nombre'], $o['direccion'], $o['telefono'], $o['medidor'], $o['reclamo'],
            $o['fecha_asignacion'], $o['trabajo'], grupo_texto($o), $o['readoquinamiento'] ? 'Sí' : 'No', ESTADOS_ORDEN[$o['estado']],
            $o['fecha_realizacion'], $o['trabajo_realizado'], $o['detalle_realizado'],
            $o['readoquinamiento_real'] === null ? '' : ($o['readoquinamiento_real'] ? 'Sí' : 'No'), $o['motivo_no_realizada'], $o['lat'], $o['lng']], ';');
    }
    exit;
}

$conteo = array_count_values(array_column($ordenes, 'estado'));

cabecera('Reporte de Órdenes de Trabajo');
?>
<form method="get" class="tarjeta filtros">
  <label>Asignadas desde <input type="date" name="desde" value="<?= h($desde) ?>"></label>
  <label>hasta <input type="date" name="hasta" value="<?= h($hasta) ?>"></label>
  <label>Estado <select name="estado"><?= opciones(ESTADOS_ORDEN, $estado, 'Todas') ?></select></label>
  <label>Trabajo <select name="trabajo"><?= opciones(lista_trabajos(false), $trabajo, 'Todos') ?></select></label>
  <label>Operador <select name="operador"><?= opciones(lista_operadores(false), $operador, 'Todos') ?></select></label>
  <button type="submit" class="primario">Consultar</button>
  <button type="submit" name="formato" value="csv">Exportar CSV</button>
  <button type="button" onclick="window.print()">Imprimir</button>
</form>

<div class="indicadores">
  <div class="tarjeta"><span>Total</span><b><?= count($ordenes) ?></b></div>
  <div class="tarjeta"><span>Realizadas</span><b class="verde"><?= $conteo['realizada'] ?? 0 ?></b></div>
  <div class="tarjeta"><span>No realizadas</span><b class="rojo"><?= $conteo['no_realizada'] ?? 0 ?></b></div>
  <div class="tarjeta"><span>Por realizar</span><b class="ambar"><?= $conteo['asignada'] ?? 0 ?></b></div>
</div>

<section class="tarjeta">
  <div class="tabla-scroll">
  <table>
    <thead><tr><th>Orden</th><th>Asignación</th><th>Ciudadano</th><th>Dirección</th><th>Trabajo</th><th>Grupo</th><th>Estado</th><th>Realización</th><th>Detalle / motivo</th><th>Foto</th></tr></thead>
    <tbody>
    <?php foreach ($ordenes as $o): ?>
      <tr>
        <td class="nowrap"><a href="orden.php?id=<?= h($o['id']) ?>">N° <?= h($o['id']) ?></a></td>
        <td class="nowrap"><?= fecha_es($o['fecha_asignacion']) ?></td>
        <td><?= h($o['nombre']) ?></td>
        <td><?= h($o['direccion']) ?></td>
        <td><span class="punto" style="background:<?= h($o['color_final']) ?>"></span><?= h($o['trabajo_final']) ?></td>
        <td><?= h(grupo_texto($o)) ?></td>
        <td><span class="estado <?= h($o['estado']) ?>"><?= h(ESTADOS_ORDEN[$o['estado']]) ?></span></td>
        <td class="nowrap"><?= fecha_es($o['fecha_realizacion']) ?></td>
        <td><?= h($o['estado'] === 'no_realizada' ? $o['motivo_no_realizada'] : $o['detalle_realizado']) ?></td>
        <td><?= $o['foto'] ? '<a href="orden.php?id=' . h($o['id']) . '">📷</a>' : '' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$ordenes): ?><tr><td colspan="10" class="suave centro">No hay órdenes con estos filtros.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</section>
<?php pie();
