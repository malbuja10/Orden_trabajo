<?php
// c) Mapa de trabajos pendientes y finalizados en un rango de fechas.
declare(strict_types=1);
require __DIR__ . '/../src/web.php';
requerir_login();

[$estado, $desde, $hasta, $where, $params] = filtro_mapa();
$ordenes = consulta_ordenes($where, $params, 'fecha_orden, id');

$colores = ['pendiente' => '#f59e0b', 'finalizada' => '#16a34a'];
$conteo = array_count_values(array_column($ordenes, 'estado'));
$puntos = array_map(fn($o) => punto_mapa($o, $colores[$o['estado']]), $ordenes);

cabecera('Mapa de trabajos pendientes y finalizados', true);
filtro_mapa_html($estado, $desde, $hasta);
?>
<p class="leyenda">
  <?php if ($estado !== 'finalizada'): ?><span><span class="punto" style="background:<?= $colores['pendiente'] ?>"></span>Pendientes: <b><?= $conteo['pendiente'] ?? 0 ?></b></span><?php endif; ?>
  <?php if ($estado !== 'pendiente'): ?><span><span class="punto" style="background:<?= $colores['finalizada'] ?>"></span>Finalizados: <b><?= $conteo['finalizada'] ?? 0 ?></b></span><?php endif; ?>
</p>
<div id="mapa" class="mapa mapa-grande"></div>

<section class="tarjeta">
  <h2>Órdenes del <?= fecha_es($desde) ?><?= $hasta !== $desde ? ' al ' . fecha_es($hasta) : '' ?> <span class="contador"><?= count($ordenes) ?></span></h2>
  <div class="tabla-scroll">
  <table>
    <thead><tr><th>Orden</th><th>Fecha</th><th>Estado</th><th>Trabajo</th><th>Observación</th><th>Realizado por</th><th>Ciudadano</th><th>Dirección</th></tr></thead>
    <tbody>
    <?php foreach ($ordenes as $o): ?>
      <tr>
        <td><a href="orden.php?id=<?= h($o['id']) ?>">N° <?= h($o['id']) ?></a></td>
        <td class="nowrap"><?= fecha_es($o['fecha_orden']) ?></td>
        <td><span class="estado <?= h($o['estado']) ?>"><?= h(ESTADOS_ORDEN[$o['estado']]) ?></span></td>
        <td><span class="punto" style="background:<?= h($o['trabajo_color']) ?>"></span><?= h($o['trabajo']) ?></td>
        <td><?= h($o['observacion']) ?></td>
        <td><?= h(grupo_texto($o)) ?></td>
        <td><?= h($o['nombre']) ?></td>
        <td><?= h($o['direccion']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$ordenes): ?><tr><td colspan="8" class="suave centro">No hay órdenes con este filtro.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</section>
<script>
OT.dibujarOrdenes(OT.crearMapa('mapa'), <?= json_js($puntos) ?>);
</script>
<?php pie();
