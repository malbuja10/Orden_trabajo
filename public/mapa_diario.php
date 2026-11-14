<?php
// c) Mapa de las Ordenes realizadas en un dia.
declare(strict_types=1);
require __DIR__ . '/../src/web.php';
requerir_login();

$fecha = fecha_valida($_GET['fecha'] ?? '') ?? date('Y-m-d');
$verTodas = !empty($_GET['todas']);

// Realizadas ese dia; opcionalmente tambien las no realizadas y las asignadas para ese dia.
$sql = SQL_ORDENES . " WHERE (o.estado = 'realizada' AND o.fecha_realizacion::date = ?)";
$params = [$fecha];
if ($verTodas) {
    $sql .= " OR (o.estado = 'no_realizada' AND o.fecha_realizacion::date = ?) OR (o.estado = 'asignada' AND o.fecha_asignacion = ?)";
    array_push($params, $fecha, $fecha);
}
$ordenes = q($sql . ' ORDER BY o.fecha_realizacion, o.id', $params)->fetchAll();

$colores = ['realizada' => '#16a34a', 'no_realizada' => '#dc2626', 'asignada' => '#f59e0b'];
$conteo = array_count_values(array_column($ordenes, 'estado'));
$puntos = array_map(fn($o) => punto_mapa($o, $colores[$o['estado']]), $ordenes);
$sinUbicacion = count(array_filter($ordenes, fn($o) => $o['lat'] === null));

cabecera('Mapa diario de órdenes realizadas', true);
?>
<form method="get" class="tarjeta filtros">
  <a class="boton" href="?fecha=<?= date('Y-m-d', strtotime($fecha . ' -1 day')) ?><?= $verTodas ? '&amp;todas=1' : '' ?>">◀</a>
  <label>Fecha <input type="date" name="fecha" value="<?= h($fecha) ?>" onchange="this.form.submit()"></label>
  <a class="boton" href="?fecha=<?= date('Y-m-d', strtotime($fecha . ' +1 day')) ?><?= $verTodas ? '&amp;todas=1' : '' ?>">▶</a>
  <label class="casilla"><input type="checkbox" name="todas" value="1" <?= $verTodas ? 'checked' : '' ?> onchange="this.form.submit()"> Mostrar también no realizadas y por realizar</label>
  <span class="leyenda">
    <span><span class="punto" style="background:<?= $colores['realizada'] ?>"></span>Realizadas: <b><?= $conteo['realizada'] ?? 0 ?></b></span>
    <?php if ($verTodas): ?>
    <span><span class="punto" style="background:<?= $colores['no_realizada'] ?>"></span>No realizadas: <b><?= $conteo['no_realizada'] ?? 0 ?></b></span>
    <span><span class="punto" style="background:<?= $colores['asignada'] ?>"></span>Por realizar: <b><?= $conteo['asignada'] ?? 0 ?></b></span>
    <?php endif; ?>
  </span>
</form>
<?php if ($sinUbicacion): ?><div class="aviso error"><?= $sinUbicacion ?> orden(es) no tienen coordenadas y no aparecen en el mapa.</div><?php endif; ?>
<div id="mapa" class="mapa mapa-grande"></div>

<section class="tarjeta">
  <h2>Órdenes del <?= fecha_es($fecha) ?></h2>
  <div class="tabla-scroll">
  <table>
    <thead><tr><th>Orden</th><th>Hora</th><th>Estado</th><th>Trabajo</th><th>Grupo</th><th>Ciudadano</th><th>Dirección</th></tr></thead>
    <tbody>
    <?php foreach ($ordenes as $o): ?>
      <tr>
        <td><a href="orden.php?id=<?= h($o['id']) ?>">N° <?= h($o['id']) ?></a></td>
        <td><?= $o['fecha_realizacion'] ? date('H:i', strtotime($o['fecha_realizacion'])) : '' ?></td>
        <td><span class="estado <?= h($o['estado']) ?>"><?= h(ESTADOS_ORDEN[$o['estado']]) ?></span></td>
        <td><?= h($o['trabajo_final']) ?></td>
        <td><?= h(grupo_texto($o)) ?></td>
        <td><?= h($o['nombre']) ?></td>
        <td><?= h($o['direccion']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$ordenes): ?><tr><td colspan="7" class="suave centro">No hay órdenes en esta fecha.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</section>
<script>
OT.dibujarOrdenes(OT.crearMapa('mapa'), <?= json_js($puntos) ?>);
</script>
<?php pie();
