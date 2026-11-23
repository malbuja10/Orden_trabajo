<?php
// b) Registrar Ordenes: los reclamos ya son Ordenes de Trabajo pendientes que cualquier operador
//    puede realizar. Cuando los operadores regresan a la oficina, el supervisor registra quien
//    hizo el trabajo (fecha de registro, operadores, readoquinamiento y detalle adicional).
declare(strict_types=1);
require __DIR__ . '/../src/web.php';
$u = requerir_login();

if (es_post()) {
    $accion = $_POST['accion'] ?? '';
    $id = (int) ($_POST['orden_id'] ?? 0);
    if ($accion === 'registrar') {
        try {
            registrar_orden($id, $_POST, (int) $u['id']);
            aviso("Orden de Trabajo N° $id registrada como finalizada.");
            // Se recuerdan fecha y operadores para agilizar el registro de las siguientes
            $_SESSION['ultimo_registro'] = [
                'fecha' => $_POST['fecha_registro'] ?? '',
                'op1' => (int) ($_POST['operador1_id'] ?? 0),
                'op2' => (int) ($_POST['operador2_id'] ?? 0) ?: '',
            ];
        } catch (RuntimeException $e) {
            aviso($e->getMessage(), 'error');
            redirigir('registrar.php?id=' . $id);
        }
    } elseif ($accion === 'reabrir') {
        $n = q(
            "UPDATE ordenes_trabajo SET estado='pendiente', fecha_registro=NULL, operador1_id=NULL, operador2_id=NULL,
                    readoquinamiento=FALSE, detalle=NULL, registrado_por=NULL, registrado_en=NULL
             WHERE id=? AND estado='finalizada'",
            [$id]
        )->rowCount();
        aviso($n ? "La Orden N° $id volvió a pendiente." : 'Solo se pueden reabrir órdenes finalizadas.', $n ? 'ok' : 'error');
    }
    redirigir('registrar.php');
}

$pendientes = consulta_ordenes("estado = 'pendiente'", [], 'fecha, id');
$registradasHoy = consulta_ordenes("estado = 'finalizada' AND registrado_en::date = CURRENT_DATE", [], 'registrado_en DESC');

// Orden solicitada por ?id= (para preseleccionar una pendiente o corregir una finalizada)
$seleccion = null;
if (!empty($_GET['id'])) {
    $seleccion = consulta_ordenes("id = ? AND estado <> 'anulada'", [(int) $_GET['id']])[0] ?? null;
}
$datos = $pendientes;
if ($seleccion && $seleccion['estado'] === 'finalizada') {
    $datos[] = $seleccion;
}

$ult = $_SESSION['ultimo_registro'] ?? ['fecha' => date('Y-m-d'), 'op1' => '', 'op2' => ''];
if (!fecha_valida($ult['fecha'])) {
    $ult['fecha'] = date('Y-m-d');
}
$operadores = lista_operadores();

$paraJs = array_map(fn($o) => [
    'id' => $o['id'],
    'fecha' => fecha_es($o['fecha']),
    'cuenta' => $o['cuenta'],
    'nombre' => $o['nombre'],
    'direccion' => $o['direccion'],
    'telefono' => $o['telefono'],
    'trabajo_id' => $o['trabajo_id'],
    'trabajo' => $o['trabajo'],
    'color' => $o['trabajo_color'],
    'observacion_id' => $o['observacion_id'],
    'observacion' => $o['observacion'],
    'lat' => num($o['lat']),
    'lng' => num($o['lng']),
    'estado' => $o['estado'],
    'fecha_registro' => $o['fecha_registro'],
    'operador1_id' => $o['operador1_id'],
    'operador2_id' => $o['operador2_id'],
    'readoquinamiento' => $o['readoquinamiento'],
    'detalle' => $o['detalle'],
    'campo' => $o['sincronizado_en'] === null ? null : [
        'fecha' => fecha_es($o['fecha_campo']),
        'detalle' => $o['detalle_campo'],
        'foto' => (bool) $o['foto'],
        'enviado' => $o['enviado_por_nombre'],
        'coords' => $o['latitud_campo'] !== null ? round((float) $o['latitud_campo'], 6) . ', ' . round((float) $o['longitud_campo'], 6) : null,
    ],
], $datos);

cabecera('Registrar Órdenes de Trabajo', true);
?>
<?php if (!$operadores): ?>
  <div class="aviso error">Antes de registrar debe crear los operadores en <a href="usuarios.php">Usuarios</a>.</div>
<?php endif; ?>
<div class="dos-columnas">
  <section class="tarjeta lista-pendientes">
    <h2>Órdenes pendientes <span class="contador"><?= count($pendientes) ?></span></h2>
    <input type="search" id="filtrar" placeholder="Filtrar por N°, cuenta, nombre, dirección…" aria-label="Filtrar órdenes pendientes">
    <p class="suave">Seleccione la orden realizada (en la lista o en el mapa) y registre quién hizo el trabajo.</p>
    <div id="pendientes">
      <?php foreach ($pendientes as $o): ?>
      <button type="button" class="reclamo-item" data-id="<?= h($o['id']) ?>"
              data-texto="<?= h(mb_strtolower($o['id'] . ' ' . $o['cuenta'] . ' ' . $o['nombre'] . ' ' . $o['direccion'] . ' ' . $o['trabajo'])) ?>">
        <strong>N° <?= h($o['id']) ?> · <span class="punto" style="background:<?= h($o['trabajo_color']) ?>"></span><?= h($o['trabajo']) ?></strong>
        <span class="suave"><?= fecha_es($o['fecha']) ?><?= $o['cuenta'] ? ' · Cuenta ' . h($o['cuenta']) : '' ?><?= $o['sincronizado_en'] ? ' · 📷 datos de campo recibidos' : '' ?></span>
        <span><?= h($o['nombre']) ?></span>
        <span><?= h($o['direccion']) ?></span>
        <?php if ($o['observacion']): ?><span class="suave"><?= h($o['observacion']) ?></span><?php endif; ?>
      </button>
      <?php endforeach; ?>
      <?php if (!$pendientes): ?><p class="suave centro">No hay órdenes pendientes. <a href="reclamos.php">Registrar reclamo</a></p><?php endif; ?>
    </div>
  </section>

  <div>
    <div id="mapa" class="mapa"></div>
    <form method="post" class="tarjeta rejilla" id="form-registro">
      <?= csrf_campo() ?>
      <input type="hidden" name="accion" value="registrar">
      <input type="hidden" name="orden_id" id="orden_id">
      <div class="ancho4" id="seleccion"><span class="suave">Ninguna orden seleccionada.</span></div>
      <div class="ancho4" id="datos-campo" hidden></div>
      <label>Fecha de registro
        <input type="date" name="fecha_registro" id="fecha_registro" value="<?= h($ult['fecha']) ?>" max="<?= date('Y-m-d') ?>" required>
      </label>
      <label>Operador 1
        <select name="operador1_id" id="operador1_id" required><?= opciones($operadores, $ult['op1'], '— Seleccione —') ?></select>
      </label>
      <label>Operador 2 (opcional)
        <select name="operador2_id" id="operador2_id"><?= opciones($operadores, $ult['op2'], '— Ninguno —') ?></select>
      </label>
      <label class="casilla"><input type="checkbox" name="readoquinamiento" id="readoquinamiento" value="1"> Requiere readoquinamiento</label>
      <label class="ancho2">Trabajo (del reclamo)
        <select name="trabajo_id" id="trabajo_id" required><?= opciones(lista_trabajos(false), '', '— Seleccione —') ?></select>
      </label>
      <label class="ancho2">Observación (del reclamo)
        <select name="observacion_id" id="observacion_id"><?= opciones(lista_observaciones(false), '', '— Ninguna —') ?></select>
      </label>
      <label class="ancho4">Detalle adicional
        <textarea name="detalle" id="detalle" rows="2"></textarea>
      </label>
      <div class="acciones ancho4">
        <button type="submit" class="primario" id="btn-registrar" disabled>Registrar orden realizada</button>
        <a class="boton" href="registrar.php" id="btn-cancelar" hidden>Cancelar</a>
      </div>
    </form>
  </div>
</div>

<section class="tarjeta">
  <h2>Registradas hoy <span class="contador"><?= count($registradasHoy) ?></span></h2>
  <div class="tabla-scroll">
  <table>
    <thead><tr><th>Orden</th><th>Fecha registro</th><th>Trabajo</th><th>Observación</th><th>Realizado por</th><th>Readoq.</th><th>Ciudadano</th><th>Dirección</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($registradasHoy as $o): ?>
      <tr>
        <td><a href="orden.php?id=<?= h($o['id']) ?>">N° <?= h($o['id']) ?></a></td>
        <td class="nowrap"><?= fecha_es($o['fecha_registro']) ?></td>
        <td><span class="punto" style="background:<?= h($o['trabajo_color']) ?>"></span><?= h($o['trabajo']) ?></td>
        <td><?= h($o['observacion']) ?></td>
        <td><?= h(grupo_texto($o)) ?></td>
        <td><?= $o['readoquinamiento'] ? 'Sí' : 'No' ?></td>
        <td><?= h($o['nombre']) ?></td>
        <td><?= h($o['direccion']) ?></td>
        <td class="nowrap">
          <a href="registrar.php?id=<?= h($o['id']) ?>">Editar</a>
          <form method="post" class="enlinea" onsubmit="return confirm('¿Volver la orden N° <?= h($o['id']) ?> a pendiente? Se borrará lo registrado.')">
            <?= csrf_campo() ?>
            <input type="hidden" name="orden_id" value="<?= h($o['id']) ?>">
            <button class="enlace" name="accion" value="reabrir">Reabrir</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$registradasHoy): ?><tr><td colspan="9" class="suave centro">Hoy no se han registrado órdenes.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</section>

<script>
(function () {
  const ordenes = <?= json_js($paraJs) ?>;
  const inicial = <?= json_js($seleccion['id'] ?? null) ?>;
  const porId = {};
  const $ = id => document.getElementById(id);
  const mapa = OT.crearMapa('mapa');
  const puntos = [];
  let marcador = null;

  ordenes.forEach(o => {
    porId[o.id] = o;
    if (o.lat === null || o.estado !== 'pendiente') return;
    OT.circulo(o.lat, o.lng, o.color, true).addTo(mapa)
      .bindTooltip('N° ' + o.id + ' · ' + OT.esc(o.trabajo) + '<br>' + OT.esc(o.direccion))
      .on('click', () => seleccionar(o.id));
    puntos.push([o.lat, o.lng]);
  });
  if (puntos.length) mapa.fitBounds(puntos, {padding: [40, 40], maxZoom: 16});
  OT.buscador(mapa);

  function seleccionar(id) {
    const o = porId[id];
    if (!o) return;
    document.querySelectorAll('.reclamo-item').forEach(b => b.classList.toggle('activo', Number(b.dataset.id) === Number(id)));
    $('orden_id').value = o.id;
    const editando = o.estado === 'finalizada';
    $('seleccion').className = 'ancho4' + (editando ? ' editando' : '');
    $('seleccion').innerHTML =
      (editando ? '<b>Corrigiendo el registro de la Orden N° ' + o.id + ' (finalizada)</b><br>' : '<b>Orden N° ' + o.id + '</b> · ') +
      OT.esc(o.fecha) + (o.cuenta ? ' · Cuenta ' + OT.esc(o.cuenta) : '') + ' · ' + OT.esc(o.nombre) + ' · ' + OT.esc(o.direccion) +
      (o.telefono ? ' · Tel. ' + OT.esc(o.telefono) : '');
    const c = o.campo;
    $('datos-campo').hidden = !c;
    if (c) {
      $('datos-campo').innerHTML = '<div class="campo-recibido">' +
        (c.foto ? '<a href="orden.php?id=' + o.id + '" target="_blank"><img class="foto-mini" src="foto.php?id=' + o.id + '" alt="Foto"></a>' : '') +
        '<div><b>Datos de campo (app móvil)</b><br>' + OT.esc(c.fecha) + (c.enviado ? ' · enviado por ' + OT.esc(c.enviado) : '') +
        (c.coords ? '<br>Coordenadas: ' + OT.esc(c.coords) : '') + (c.detalle ? '<br><i>' + OT.esc(c.detalle) + '</i>' : '') + '</div></div>';
    }
    $('trabajo_id').value = o.trabajo_id;
    $('observacion_id').value = o.observacion_id || '';
    $('readoquinamiento').checked = editando ? o.readoquinamiento : false;
    $('detalle').value = editando ? (o.detalle || '') : '';
    if (editando) {
      $('fecha_registro').value = o.fecha_registro;
      $('operador1_id').value = o.operador1_id || '';
      $('operador2_id').value = o.operador2_id || '';
    }
    $('btn-registrar').disabled = false;
    $('btn-registrar').textContent = editando ? 'Guardar cambios' : 'Registrar orden realizada';
    $('btn-cancelar').hidden = !editando;
    if (o.lat !== null) {
      if (marcador) marcador.setLatLng([o.lat, o.lng]); else marcador = L.marker([o.lat, o.lng]).addTo(mapa);
      mapa.setView([o.lat, o.lng], Math.max(mapa.getZoom(), 16));
    }
  }

  document.querySelectorAll('.reclamo-item').forEach(b => b.addEventListener('click', () => seleccionar(b.dataset.id)));
  $('filtrar').addEventListener('input', e => {
    const t = e.target.value.trim().toLowerCase();
    document.querySelectorAll('.reclamo-item').forEach(b => { b.hidden = t !== '' && !b.dataset.texto.includes(t); });
  });
  $('form-registro').addEventListener('submit', e => {
    if ($('operador2_id').value && $('operador2_id').value === $('operador1_id').value) {
      e.preventDefault();
      alert('El operador 2 debe ser distinto del operador 1.');
    }
  });
  if (inicial) seleccionar(inicial);
})();
</script>
<?php pie();
