<?php
// b) El supervisor ubica en el mapa los reclamos pendientes y genera las Ordenes de Trabajo.
declare(strict_types=1);
require __DIR__ . '/../src/web.php';
$u = requerir_login();

if (es_post()) {
    $accion = $_POST['accion'] ?? '';
    if ($accion === 'asignar') {
        $reclamoId = (int) ($_POST['reclamo_id'] ?? 0);
        $fecha = fecha_valida($_POST['fecha_asignacion'] ?? '');
        $trabajo = (int) ($_POST['trabajo_id'] ?? 0);
        $op1 = (int) ($_POST['operador1_id'] ?? 0);
        $op2 = (int) ($_POST['operador2_id'] ?? 0) ?: null;
        $lat = is_numeric($_POST['latitud'] ?? '') ? (float) $_POST['latitud'] : null;
        $lng = is_numeric($_POST['longitud'] ?? '') ? (float) $_POST['longitud'] : null;
        $operadores = lista_operadores();

        if (!$reclamoId || !$fecha || !isset(lista_trabajos()[$trabajo]) || !isset($operadores[$op1])) {
            aviso('Seleccione el reclamo, la fecha, el trabajo y al menos un operador.', 'error');
        } elseif ($op2 !== null && ($op2 === $op1 || !isset($operadores[$op2]))) {
            aviso('El segundo operador debe ser distinto del primero.', 'error');
        } elseif ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180) {
            aviso('Ubique el reclamo en el mapa antes de generar la orden.', 'error');
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            // Solo se asigna si el reclamo sigue pendiente (evita ordenes duplicadas)
            $ok = q("UPDATE reclamos SET estado='asignado', latitud=?, longitud=? WHERE id=? AND estado='pendiente'", [$lat, $lng, $reclamoId])->rowCount();
            if ($ok) {
                $ordenId = q(
                    'INSERT INTO ordenes_trabajo (reclamo_id, fecha_asignacion, trabajo_id, operador1_id, operador2_id, observaciones, readoquinamiento, supervisor_id)
                     VALUES (?,?,?,?,?,?,?,?) RETURNING id',
                    [$reclamoId, $fecha, $trabajo, $op1, $op2, texto($_POST['observaciones'] ?? ''), pgbool(!empty($_POST['readoquinamiento'])), $u['id']]
                )->fetchColumn();
                $pdo->commit();
                aviso("Orden de Trabajo N° $ordenId generada para el reclamo N° $reclamoId.");
                // Se recuerdan los valores para agilizar la siguiente asignacion
                $_SESSION['ultima_asignacion'] = ['fecha' => $fecha, 'op1' => $op1, 'op2' => $op2];
            } else {
                $pdo->rollBack();
                aviso('El reclamo ya no está pendiente.', 'error');
            }
        }
    } elseif ($accion === 'quitar') {
        $ordenId = (int) ($_POST['orden_id'] ?? 0);
        $pdo = db();
        $pdo->beginTransaction();
        $reclamoId = q("DELETE FROM ordenes_trabajo WHERE id=? AND estado='asignada' RETURNING reclamo_id", [$ordenId])->fetchColumn();
        if ($reclamoId) {
            q("UPDATE reclamos SET estado='pendiente' WHERE id=?", [$reclamoId]);
            aviso("Orden N° $ordenId eliminada; el reclamo N° $reclamoId vuelve a pendiente.");
        } else {
            aviso('Solo se pueden eliminar órdenes aún no realizadas.', 'error');
        }
        $pdo->commit();
    }
    redirigir('asignar.php');
}

$pendientes = q("SELECT id, fecha, nombre, direccion, reclamo, telefono, medidor, observaciones, latitud, longitud
                 FROM reclamos WHERE estado='pendiente' ORDER BY fecha, id")->fetchAll();
foreach ($pendientes as &$p) {
    $p['latitud'] = num($p['latitud']);
    $p['longitud'] = num($p['longitud']);
}
unset($p);
$asignadas = q(SQL_ORDENES . " WHERE o.estado='asignada' ORDER BY o.fecha_asignacion DESC, o.id DESC")->fetchAll();
$trabajos = lista_trabajos();
$operadores = lista_operadores();
$ult = $_SESSION['ultima_asignacion'] ?? ['fecha' => date('Y-m-d'), 'op1' => '', 'op2' => ''];
if ($ult['fecha'] < date('Y-m-d')) {
    $ult['fecha'] = date('Y-m-d');
}

cabecera('Asignar Órdenes de Trabajo', true);
?>
<?php if (!$operadores || !$trabajos): ?>
  <div class="aviso error">Antes de asignar debe registrar operadores (<a href="usuarios.php">Usuarios</a>) y tipos de trabajo (<a href="trabajos.php">Trabajos</a>).</div>
<?php endif; ?>
<div class="dos-columnas">
  <section class="tarjeta lista-pendientes">
    <h2>Reclamos pendientes <span class="contador"><?= count($pendientes) ?></span></h2>
    <p class="suave">1. Seleccione un reclamo · 2. Haga clic en el mapa para ubicarlo · 3. Complete la orden.</p>
    <div id="pendientes">
      <?php foreach ($pendientes as $r): ?>
      <button type="button" class="reclamo-item" data-id="<?= h($r['id']) ?>">
        <strong>N° <?= h($r['id']) ?> · <?= h($r['nombre']) ?></strong>
        <span class="suave"><?= fecha_es($r['fecha']) ?><?= $r['latitud'] !== null ? ' · 📍 ubicado' : '' ?></span>
        <span><?= h($r['direccion']) ?></span>
        <span><?= h($r['reclamo']) ?></span>
      </button>
      <?php endforeach; ?>
      <?php if (!$pendientes): ?><p class="suave centro">No hay reclamos pendientes. <a href="reclamos.php">Registrar reclamo</a></p><?php endif; ?>
    </div>
  </section>

  <div>
    <div id="mapa" class="mapa"></div>
    <form method="post" class="tarjeta rejilla" id="form-orden">
      <?= csrf_campo() ?>
      <input type="hidden" name="accion" value="asignar">
      <input type="hidden" name="reclamo_id" id="reclamo_id">
      <input type="hidden" name="latitud" id="latitud">
      <input type="hidden" name="longitud" id="longitud">
      <div class="ancho4" id="seleccion"><span class="suave">Ningún reclamo seleccionado.</span></div>
      <label>Fecha de asignación
        <input type="date" name="fecha_asignacion" value="<?= h($ult['fecha']) ?>" required>
      </label>
      <label>Trabajo a realizar
        <select name="trabajo_id" required><?= opciones($trabajos, '', '— Seleccione —') ?></select>
      </label>
      <label>Operador 1
        <select name="operador1_id" required><?= opciones($operadores, $ult['op1'], '— Seleccione —') ?></select>
      </label>
      <label>Operador 2 (opcional)
        <select name="operador2_id"><?= opciones($operadores, $ult['op2'], '— Ninguno —') ?></select>
      </label>
      <label class="ancho3">Observaciones
        <input name="observaciones">
      </label>
      <label class="casilla"><input type="checkbox" name="readoquinamiento" value="1"> Requiere readoquinamiento</label>
      <div class="acciones ancho4">
        <button type="submit" class="primario" id="btn-asignar" disabled>Generar Orden de Trabajo</button>
        <span class="suave" id="ayuda-ubicacion"></span>
      </div>
    </form>
  </div>
</div>

<section class="tarjeta">
  <h2>Órdenes asignadas por realizar <span class="contador"><?= count($asignadas) ?></span></h2>
  <div class="tabla-scroll">
  <table>
    <thead><tr><th>Orden</th><th>Asignación</th><th>Trabajo</th><th>Grupo</th><th>Ciudadano</th><th>Dirección</th><th>Readoq.</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($asignadas as $o): ?>
      <tr>
        <td><a href="orden.php?id=<?= h($o['id']) ?>">N° <?= h($o['id']) ?></a></td>
        <td class="nowrap"><?= fecha_es($o['fecha_asignacion']) ?></td>
        <td><span class="punto" style="background:<?= h($o['trabajo_color']) ?>"></span><?= h($o['trabajo']) ?></td>
        <td><?= h(grupo_texto($o)) ?></td>
        <td><?= h($o['nombre']) ?></td>
        <td><?= h($o['direccion']) ?></td>
        <td><?= $o['readoquinamiento'] ? 'Sí' : 'No' ?></td>
        <td>
          <form method="post" class="enlinea" onsubmit="return confirm('¿Eliminar la orden N° <?= h($o['id']) ?>? El reclamo volverá a pendiente.')">
            <?= csrf_campo() ?>
            <input type="hidden" name="orden_id" value="<?= h($o['id']) ?>">
            <button class="enlace" name="accion" value="quitar">Eliminar</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$asignadas): ?><tr><td colspan="8" class="suave centro">No hay órdenes por realizar.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</section>

<script>
(function () {
  const reclamos = <?= json_js($pendientes) ?>;
  const asignadas = <?= json_js(array_map(fn($o) => ['id' => $o['id'], 'lat' => num($o['lat']), 'lng' => num($o['lng']), 'trabajo' => $o['trabajo'], 'color' => $o['trabajo_color'], 'direccion' => $o['direccion'], 'grupo' => grupo_texto($o)], $asignadas)) ?>;
  const mapa = OT.crearMapa('mapa');
  const porId = {};
  const puntos = [];
  let marcador = null;

  // Ordenes ya asignadas (referencia para agrupar trabajos cercanos)
  asignadas.forEach(o => {
    if (o.lat === null) return;
    OT.circulo(o.lat, o.lng, o.color).addTo(mapa)
      .bindPopup('<b>Orden N° ' + o.id + '</b> (asignada)<br>' + OT.esc(o.trabajo) + '<br>' + OT.esc(o.direccion) + '<br>' + OT.esc(o.grupo));
    puntos.push([o.lat, o.lng]);
  });

  // Reclamos pendientes que ya tienen ubicacion
  reclamos.forEach(r => {
    porId[r.id] = r;
    if (r.latitud === null) return;
    r.marca = OT.circulo(r.latitud, r.longitud, '#6b7280').addTo(mapa)
      .bindTooltip('Reclamo N° ' + r.id + ' (pendiente)')
      .on('click', () => seleccionar(r.id));
    puntos.push([r.latitud, r.longitud]);
  });
  if (puntos.length) mapa.fitBounds(puntos, {padding: [40, 40], maxZoom: 16});

  function ubicar(lat, lng) {
    if (!document.getElementById('reclamo_id').value) {
      document.getElementById('ayuda-ubicacion').textContent = 'Primero seleccione un reclamo de la lista.';
      return;
    }
    if (!marcador) {
      marcador = L.marker([lat, lng], {draggable: true}).addTo(mapa);
      marcador.on('dragend', () => { const p = marcador.getLatLng(); ubicar(p.lat, p.lng); });
    } else {
      marcador.setLatLng([lat, lng]);
    }
    document.getElementById('latitud').value = lat.toFixed(7);
    document.getElementById('longitud').value = lng.toFixed(7);
    document.getElementById('btn-asignar').disabled = false;
    document.getElementById('ayuda-ubicacion').textContent = 'Ubicación: ' + lat.toFixed(5) + ', ' + lng.toFixed(5) + ' (puede arrastrar el marcador)';
  }

  function seleccionar(id) {
    const r = porId[id];
    document.querySelectorAll('.reclamo-item').forEach(b => b.classList.toggle('activo', Number(b.dataset.id) === Number(id)));
    document.getElementById('reclamo_id').value = id;
    document.getElementById('latitud').value = '';
    document.getElementById('longitud').value = '';
    document.getElementById('btn-asignar').disabled = true;
    document.getElementById('seleccion').innerHTML =
      '<b>Reclamo N° ' + r.id + '</b> · ' + OT.esc(r.nombre) + ' · ' + OT.esc(r.direccion) +
      (r.telefono ? ' · Tel. ' + OT.esc(r.telefono) : '') + (r.medidor ? ' · Medidor ' + OT.esc(r.medidor) : '') +
      '<br>' + OT.esc(r.reclamo) + (r.observaciones ? '<br><span class="suave">' + OT.esc(r.observaciones) + '</span>' : '');
    if (marcador) { mapa.removeLayer(marcador); marcador = null; }
    if (r.latitud !== null) {
      ubicar(r.latitud, r.longitud);
      mapa.setView([r.latitud, r.longitud], Math.max(mapa.getZoom(), 16));
    } else {
      document.getElementById('ayuda-ubicacion').textContent = 'Haga clic en el mapa para ubicar el reclamo.';
    }
  }

  document.querySelectorAll('.reclamo-item').forEach(b => b.addEventListener('click', () => seleccionar(b.dataset.id)));
  mapa.on('click', e => ubicar(e.latlng.lat, e.latlng.lng));
})();
</script>
<?php pie();
