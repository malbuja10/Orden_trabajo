<?php
// e) Mapa tematico: tipos de trabajo por colores, con filtro de estado y rango de fechas.
//    Finalizados = circulo relleno; pendientes = anillo.
declare(strict_types=1);
require __DIR__ . '/../src/web.php';
requerir_login();

[$estado, $desde, $hasta, $where, $params] = filtro_mapa();
$ordenes = consulta_ordenes($where, $params, 'fecha_orden, id');

$leyenda = [];
foreach ($ordenes as $o) {
    $leyenda[$o['trabajo']] ??= ['color' => $o['trabajo_color'], 'pendiente' => 0, 'finalizada' => 0];
    $leyenda[$o['trabajo']][$o['estado']]++;
}
ksort($leyenda);
$puntos = array_map(fn($o) => punto_mapa($o, null, $o['estado'] === 'pendiente'), $ordenes);

cabecera('Mapa temático por tipo de trabajo', true);
filtro_mapa_html($estado, $desde, $hasta);
?>
<div class="mapa-con-leyenda">
  <div id="mapa" class="mapa mapa-grande"></div>
  <aside class="tarjeta">
    <h2>Tipos de trabajo</h2>
    <div class="leyenda-fila suave"><span></span><span>
      <?= $estado !== 'pendiente' ? 'Fin.' : '' ?><?= $estado === 'todos' ? ' · ' : '' ?><?= $estado !== 'finalizada' ? 'Pend.' : '' ?></span></div>
    <?php foreach ($leyenda as $nombre => $l): ?>
      <div class="leyenda-fila"><span><span class="punto" style="background:<?= h($l['color']) ?>"></span><?= h($nombre) ?></span>
        <b><?= $estado !== 'pendiente' ? $l['finalizada'] : '' ?><?= $estado === 'todos' ? ' · ' : '' ?><?= $estado !== 'finalizada' ? $l['pendiente'] : '' ?></b></div>
    <?php endforeach; ?>
    <?php if (!$leyenda): ?><p class="suave">No hay órdenes con este filtro.</p><?php endif; ?>
    <p class="suave">● relleno: finalizado<br>○ anillo: pendiente<br>Total: <b><?= count($ordenes) ?></b></p>
    <p class="suave">Los colores se configuran en <a href="trabajos.php">Trabajos</a>.</p>
  </aside>
</div>
<script>
OT.dibujarOrdenes(OT.crearMapa('mapa'), <?= json_js($puntos) ?>);
</script>
<?php pie();
