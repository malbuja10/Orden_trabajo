<?php
// f) Resumen mensual y anual de Ordenes de trabajo.
declare(strict_types=1);
require __DIR__ . '/../src/web.php';
requerir_login();

$anios = q('SELECT DISTINCT EXTRACT(YEAR FROM fecha_asignacion)::int FROM ordenes_trabajo ORDER BY 1 DESC')->fetchAll(PDO::FETCH_COLUMN);
$anio = (int) ($_GET['anio'] ?? date('Y'));
if (!in_array($anio, $anios, true)) {
    $anios[] = $anio;
    rsort($anios);
}

// Ordenes por mes de asignacion y estado
$porMes = array_fill(1, 12, ['asignada' => 0, 'realizada' => 0, 'no_realizada' => 0]);
foreach (q(
    'SELECT EXTRACT(MONTH FROM fecha_asignacion)::int AS mes, estado, COUNT(*) AS n
     FROM ordenes_trabajo WHERE EXTRACT(YEAR FROM fecha_asignacion) = ? GROUP BY 1, 2',
    [$anio]
) as $f) {
    $porMes[$f['mes']][$f['estado']] = $f['n'];
}

// Trabajos realizados por tipo y mes de realizacion
$tipos = [];
foreach (q(
    "SELECT t.nombre, t.color, EXTRACT(MONTH FROM o.fecha_realizacion)::int AS mes, COUNT(*) AS n,
            COUNT(*) FILTER (WHERE o.readoquinamiento_real) AS readoq
     FROM ordenes_trabajo o JOIN trabajos t ON t.id = COALESCE(o.trabajo_realizado_id, o.trabajo_id)
     WHERE o.estado = 'realizada' AND EXTRACT(YEAR FROM o.fecha_realizacion) = ?
     GROUP BY 1, 2, 3 ORDER BY 1",
    [$anio]
) as $f) {
    $tipos[$f['nombre']] ??= ['color' => $f['color'], 'meses' => array_fill(1, 12, 0), 'readoq' => 0];
    $tipos[$f['nombre']]['meses'][$f['mes']] = $f['n'];
    $tipos[$f['nombre']]['readoq'] += $f['readoq'];
}

// Resumen anual (todos los anios)
$anual = q(
    "SELECT EXTRACT(YEAR FROM fecha_asignacion)::int AS anio, COUNT(*) AS total,
            COUNT(*) FILTER (WHERE estado='realizada') AS realizadas,
            COUNT(*) FILTER (WHERE estado='no_realizada') AS no_realizadas,
            COUNT(*) FILTER (WHERE estado='asignada') AS pendientes,
            COUNT(*) FILTER (WHERE readoquinamiento_real) AS readoq
     FROM ordenes_trabajo GROUP BY 1 ORDER BY 1 DESC"
)->fetchAll();

$tot = ['asignada' => 0, 'realizada' => 0, 'no_realizada' => 0];
foreach ($porMes as $m) {
    foreach ($tot as $k => $_) {
        $tot[$k] += $m[$k];
    }
}
$maxMes = max(1, ...array_map('array_sum', $porMes));
$pct = fn(int $a, int $b): string => $b ? round(100 * $a / $b) . '%' : '—';

cabecera('Resumen de Órdenes de Trabajo');
?>
<form method="get" class="tarjeta filtros no-imprimir">
  <label>Año <select name="anio" onchange="this.form.submit()"><?= opciones(array_combine($anios, $anios), $anio) ?></select></label>
  <button type="button" onclick="window.print()">Imprimir</button>
</form>

<section class="tarjeta">
  <h2>Resumen mensual <?= $anio ?> — órdenes por mes de asignación</h2>
  <div class="tabla-scroll">
  <table class="numeros">
    <thead><tr><th>Mes</th><th>Total</th><th>Realizadas</th><th>No realizadas</th><th>Por realizar</th><th>% Cumplimiento</th><th class="izq">Distribución</th></tr></thead>
    <tbody>
    <?php foreach ($porMes as $mes => $m): $total = array_sum($m); ?>
      <tr>
        <th><a href="ordenes.php?desde=<?= sprintf('%d-%02d-01', $anio, $mes) ?>&amp;hasta=<?= date('Y-m-t', mktime(0, 0, 0, $mes, 1, $anio)) ?>"><?= MESES[$mes] ?></a></th>
        <td><b><?= $total ?></b></td>
        <td><?= $m['realizada'] ?></td>
        <td><?= $m['no_realizada'] ?></td>
        <td><?= $m['asignada'] ?></td>
        <td><?= $pct($m['realizada'], $total) ?></td>
        <td class="izq"><div class="barra-apilada" style="width:<?= round(100 * $total / $maxMes) ?>%">
          <?php foreach (['realizada' => 'verde', 'no_realizada' => 'rojo', 'asignada' => 'ambar'] as $k => $clase): if ($m[$k]): ?>
            <span class="f-<?= $clase ?>" style="flex:<?= $m[$k] ?>" title="<?= ESTADOS_ORDEN[$k] ?>: <?= $m[$k] ?>"></span>
          <?php endif; endforeach; ?>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><th>Total <?= $anio ?></th><td><b><?= array_sum($tot) ?></b></td><td><?= $tot['realizada'] ?></td><td><?= $tot['no_realizada'] ?></td><td><?= $tot['asignada'] ?></td><td><?= $pct($tot['realizada'], array_sum($tot)) ?></td><td></td></tr></tfoot>
  </table>
  </div>
  <p class="leyenda"><span><span class="punto f-verde"></span>Realizadas</span><span><span class="punto f-rojo"></span>No realizadas</span><span><span class="punto f-ambar"></span>Por realizar</span></p>
</section>

<section class="tarjeta">
  <h2>Trabajos realizados por tipo <?= $anio ?> — por mes de realización</h2>
  <div class="tabla-scroll">
  <table class="numeros">
    <thead><tr><th>Tipo de trabajo</th><?php foreach (MESES as $m): ?><th><?= substr($m, 0, 3) ?></th><?php endforeach; ?><th>Total</th><th>Con readoq.</th></tr></thead>
    <tbody>
    <?php $totMes = array_fill(1, 12, 0); $totReadoq = 0;
    foreach ($tipos as $nombre => $t): $totReadoq += $t['readoq']; ?>
      <tr>
        <th><span class="punto" style="background:<?= h($t['color']) ?>"></span><?= h($nombre) ?></th>
        <?php foreach ($t['meses'] as $mes => $n): $totMes[$mes] += $n; ?><td><?= $n ?: '<span class="suave">·</span>' ?></td><?php endforeach; ?>
        <td><b><?= array_sum($t['meses']) ?></b></td>
        <td><?= $t['readoq'] ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$tipos): ?><tr><td colspan="15" class="suave centro">No hay trabajos realizados en <?= $anio ?>.</td></tr><?php endif; ?>
    </tbody>
    <tfoot><tr><th>Total</th><?php foreach ($totMes as $n): ?><td><?= $n ?></td><?php endforeach; ?><td><b><?= array_sum($totMes) ?></b></td><td><?= $totReadoq ?></td></tr></tfoot>
  </table>
  </div>
</section>

<section class="tarjeta">
  <h2>Resumen anual</h2>
  <div class="tabla-scroll">
  <table class="numeros">
    <thead><tr><th>Año</th><th>Total órdenes</th><th>Realizadas</th><th>No realizadas</th><th>Por realizar</th><th>% Cumplimiento</th><th>Con readoquinamiento</th></tr></thead>
    <tbody>
    <?php foreach ($anual as $a): ?>
      <tr>
        <th><a href="resumen.php?anio=<?= $a['anio'] ?>"><?= $a['anio'] ?></a></th>
        <td><b><?= $a['total'] ?></b></td><td><?= $a['realizadas'] ?></td><td><?= $a['no_realizadas'] ?></td><td><?= $a['pendientes'] ?></td>
        <td><?= $pct($a['realizadas'], $a['total']) ?></td><td><?= $a['readoq'] ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$anual): ?><tr><td colspan="7" class="suave centro">Aún no hay órdenes registradas.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</section>
<?php pie();
