<?php
// e) Mapa tematico diario y mensual: tipos de trabajo realizados, por colores.
declare(strict_types=1);
require __DIR__ . '/../src/web.php';
requerir_login();

$periodo = ($_GET['periodo'] ?? 'dia') === 'mes' ? 'mes' : 'dia';
$fecha = fecha_valida($_GET['fecha'] ?? '') ?? date('Y-m-d');
$mes = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $_GET['mes'] ?? '') ? $_GET['mes'] : substr($fecha, 0, 7);

if ($periodo === 'mes') {
    $desde = $mes . '-01';
    $hasta = date('Y-m-d', strtotime($desde . ' +1 month'));
    $titulo = MESES[(int) substr($mes, 5, 2)] . ' de ' . substr($mes, 0, 4);
} else {
    $desde = $fecha;
    $hasta = date('Y-m-d', strtotime($fecha . ' +1 day'));
    $titulo = fecha_es($fecha);
}

$ordenes = q(
    SQL_ORDENES . " WHERE o.estado = 'realizada' AND o.fecha_realizacion >= ? AND o.fecha_realizacion < ?
     ORDER BY o.fecha_realizacion, o.id",
    [$desde, $hasta]
)->fetchAll();

// Leyenda: cantidad por tipo de trabajo realmente ejecutado
$leyenda = [];
foreach ($ordenes as $o) {
    $leyenda[$o['trabajo_final']] ??= ['color' => $o['color_final'], 'n' => 0];
    $leyenda[$o['trabajo_final']]['n']++;
}
ksort($leyenda);

cabecera('Mapa temático por tipo de trabajo', true);
?>
<form method="get" class="tarjeta filtros">
  <label>Período
    <select name="periodo" onchange="this.form.submit()">
      <?= opciones(['dia' => 'Diario', 'mes' => 'Mensual'], $periodo) ?>
    </select>
  </label>
  <?php if ($periodo === 'dia'): ?>
    <label>Fecha <input type="date" name="fecha" value="<?= h($fecha) ?>" onchange="this.form.submit()"></label>
  <?php else: ?>
    <label>Mes <input type="month" name="mes" value="<?= h($mes) ?>" onchange="this.form.submit()"></label>
  <?php endif; ?>
  <button type="submit">Ver</button>
  <span class="suave">Trabajos realizados · <?= h($titulo) ?>: <b><?= count($ordenes) ?></b></span>
</form>
<div class="mapa-con-leyenda">
  <div id="mapa" class="mapa mapa-grande"></div>
  <aside class="tarjeta">
    <h2>Tipos de trabajo</h2>
    <?php foreach ($leyenda as $nombre => $l): ?>
      <div class="leyenda-fila"><span><span class="punto" style="background:<?= h($l['color']) ?>"></span><?= h($nombre) ?></span><b><?= $l['n'] ?></b></div>
    <?php endforeach; ?>
    <?php if (!$leyenda): ?><p class="suave">No hay trabajos realizados en este período.</p><?php endif; ?>
    <p class="suave">Los colores se configuran en <a href="trabajos.php">Trabajos</a>.</p>
  </aside>
</div>
<script>
OT.dibujarOrdenes(OT.crearMapa('mapa'), <?= json_js(array_map('punto_mapa', $ordenes)) ?>);
</script>
<?php pie();
