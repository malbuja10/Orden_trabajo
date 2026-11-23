<?php
// f) Resumen mensual y anual de Ordenes de trabajo (sin contar las anuladas).
declare(strict_types=1);
require __DIR__ . '/../src/web.php';
requerir_login();

$anios = q("SELECT DISTINCT EXTRACT(YEAR FROM fecha)::int FROM ordenes_trabajo WHERE estado <> 'anulada' ORDER BY 1 DESC")->fetchAll(PDO::FETCH_COLUMN);
$anio = (int) ($_GET['anio'] ?? date('Y'));
if (!in_array($anio, $anios, true)) {
    $anios[] = $anio;
    rsort($anios);
}

// Ordenes por mes del reclamo y estado
$porMes = array_fill(1, 12, ['finalizada' => 0, 'pendiente' => 0]);
foreach (q(
    "SELECT EXTRACT(MONTH FROM fecha)::int AS mes, estado, COUNT(*) AS n
     FROM ordenes_trabajo WHERE estado <> 'anulada' AND EXTRACT(YEAR FROM fecha) = ? GROUP BY 1, 2",
    [$anio]
) as $f) {
    $porMes[$f['mes']][$f['estado']] = $f['n'];
}

// Trabajos finalizados por tipo y mes de registro
$tipos = [];
foreach (q(
    "SELECT t.nombre, t.color, EXTRACT(MONTH FROM o.fecha_registro)::int AS mes, COUNT(*) AS n,
            COUNT(*) FILTER (WHERE o.readoquinamiento) AS readoq
     FROM ordenes_trabajo o JOIN trabajos t ON t.id = o.trabajo_id
     WHERE o.estado = 'finalizada' AND EXTRACT(YEAR FROM o.fecha_registro) = ?
     GROUP BY 1, 2, 3 ORDER BY 1",
    [$anio]
) as $f) {
    $tipos[$f['nombre']] ??= ['color' => $f['color'], 'meses' => array_fill(1, 12, 0), 'readoq' => 0];
    $tipos[$f['nombre']]['meses'][$f['mes']] = $f['n'];
    $tipos[$f['nombre']]['readoq'] += $f['readoq'];
}

// Resumen anual (todos los anios)
$anual = q(
    "SELECT EXTRACT(YEAR FROM fecha)::int AS anio, COUNT(*) AS total,
            COUNT(*) FILTER (WHERE estado='finalizada') AS finalizadas,
            COUNT(*) FILTER (WHERE estado='pendiente') AS pendientes,
            COUNT(*) FILTER (WHERE estado='finalizada' AND readoquinamiento) AS readoq
     FROM ordenes_trabajo WHERE estado <> 'anulada' GROUP BY 1 ORDER BY 1 DESC"
)->fetchAll();

// Graficos: trabajos finalizados por tipo en el mes elegido y en todo el anio (incluye tipos sin trabajos)
$mesGrafico = (int) ($_GET['mes'] ?? ($anio === (int) date('Y') ? date('n') : 12));
if ($mesGrafico < 1 || $mesGrafico > 12) {
    $mesGrafico = 1;
}
$porTipo = function (?int $mes) use ($anio): array {
    return q(
        "SELECT t.nombre, COUNT(o.id) AS n
         FROM trabajos t
         LEFT JOIN ordenes_trabajo o ON o.trabajo_id = t.id AND o.estado = 'finalizada'
              AND EXTRACT(YEAR FROM o.fecha_registro) = ? AND (?::int IS NULL OR EXTRACT(MONTH FROM o.fecha_registro) = ?)
         GROUP BY t.id, t.nombre, t.activo HAVING t.activo OR COUNT(o.id) > 0
         ORDER BY t.id",
        [$anio, $mes, $mes]
    )->fetchAll(PDO::FETCH_KEY_PAIR);
};
$graficoMes = $porTipo($mesGrafico);
$graficoAnio = $porTipo(null);

$tot = ['finalizada' => 0, 'pendiente' => 0];
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
  <input type="hidden" name="mes" value="<?= $mesGrafico ?>">
  <button type="button" onclick="window.print()">Imprimir</button>
</form>

<section class="tarjeta">
  <form method="get" class="grafico-cab">
    <h2>Trabajos realizados en <?= MESES[$mesGrafico] ?> de <?= $anio ?> <span class="contador"><?= array_sum($graficoMes) ?></span></h2>
    <input type="hidden" name="anio" value="<?= $anio ?>">
    <label class="no-imprimir">Mes <select name="mes" onchange="this.form.submit()"><?= opciones(MESES, $mesGrafico) ?></select></label>
  </form>
  <?= grafico_barras($graficoMes, 'Trabajos realizados en ' . MESES[$mesGrafico] . ' de ' . $anio) ?>
</section>

<section class="tarjeta">
  <div class="grafico-cab">
    <h2>Trabajos realizados en el año <?= $anio ?> <span class="contador"><?= array_sum($graficoAnio) ?></span></h2>
  </div>
  <?= grafico_barras($graficoAnio, 'Trabajos realizados en el año ' . $anio) ?>
</section>

<section class="tarjeta">
  <h2>Resumen mensual <?= $anio ?> — órdenes por mes del reclamo</h2>
  <div class="tabla-scroll">
  <table class="numeros">
    <thead><tr><th>Mes</th><th>Total</th><th>Finalizadas</th><th>Pendientes</th><th>% Cumplimiento</th><th class="izq">Distribución</th></tr></thead>
    <tbody>
    <?php foreach ($porMes as $mes => $m): $total = array_sum($m); ?>
      <tr>
        <th><a href="ordenes.php?desde=<?= sprintf('%d-%02d-01', $anio, $mes) ?>&amp;hasta=<?= date('Y-m-t', mktime(0, 0, 0, $mes, 1, $anio)) ?>"><?= MESES[$mes] ?></a></th>
        <td><b><?= $total ?></b></td>
        <td><?= $m['finalizada'] ?></td>
        <td><?= $m['pendiente'] ?></td>
        <td><?= $pct($m['finalizada'], $total) ?></td>
        <td class="izq"><div class="barra-apilada" style="width:<?= round(100 * $total / $maxMes) ?>%">
          <?php foreach (['finalizada' => 'verde', 'pendiente' => 'ambar'] as $k => $clase): if ($m[$k]): ?>
            <span class="f-<?= $clase ?>" style="flex:<?= $m[$k] ?>" title="<?= ESTADOS_ORDEN[$k] ?>: <?= $m[$k] ?>"></span>
          <?php endif; endforeach; ?>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><th>Total <?= $anio ?></th><td><b><?= array_sum($tot) ?></b></td><td><?= $tot['finalizada'] ?></td><td><?= $tot['pendiente'] ?></td><td><?= $pct($tot['finalizada'], array_sum($tot)) ?></td><td></td></tr></tfoot>
  </table>
  </div>
  <p class="leyenda"><span><span class="punto f-verde"></span>Finalizadas</span><span><span class="punto f-ambar"></span>Pendientes</span></p>
</section>

<section class="tarjeta">
  <h2>Trabajos finalizados por tipo <?= $anio ?> — por mes de registro</h2>
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
    <?php if (!$tipos): ?><tr><td colspan="15" class="suave centro">No hay trabajos finalizados en <?= $anio ?>.</td></tr><?php endif; ?>
    </tbody>
    <tfoot><tr><th>Total</th><?php foreach ($totMes as $n): ?><td><?= $n ?></td><?php endforeach; ?><td><b><?= array_sum($totMes) ?></b></td><td><?= $totReadoq ?></td></tr></tfoot>
  </table>
  </div>
</section>

<section class="tarjeta">
  <h2>Resumen anual</h2>
  <div class="tabla-scroll">
  <table class="numeros">
    <thead><tr><th>Año</th><th>Total órdenes</th><th>Finalizadas</th><th>Pendientes</th><th>% Cumplimiento</th><th>Con readoquinamiento</th></tr></thead>
    <tbody>
    <?php foreach ($anual as $a): ?>
      <tr>
        <th><a href="resumen.php?anio=<?= $a['anio'] ?>&amp;mes=12"><?= $a['anio'] ?></a></th>
        <td><b><?= $a['total'] ?></b></td><td><?= $a['finalizadas'] ?></td><td><?= $a['pendientes'] ?></td>
        <td><?= $pct($a['finalizadas'], $a['total']) ?></td><td><?= $a['readoq'] ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$anual): ?><tr><td colspan="6" class="suave centro">Aún no hay órdenes registradas.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</section>
<?php pie();
