<?php
// a) Registro de reclamos. Cada reclamo registrado es directamente una Orden de Trabajo pendiente.
//    Se empieza por la cuenta: si existe, se llenan nombre, direccion y ubicacion.
declare(strict_types=1);
require __DIR__ . '/../src/web.php';
$u = requerir_login();

if (es_post()) {
    $accion = $_POST['accion'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    $volver = 'reclamos.php?' . http_build_query(['estado' => $_POST['filtro_estado'] ?? 'pendiente']);

    if ($accion === 'guardar') {
        $lat = is_numeric($_POST['latitud'] ?? '') ? (float) $_POST['latitud'] : null;
        $lng = is_numeric($_POST['longitud'] ?? '') ? (float) $_POST['longitud'] : null;
        $datos = [
            fecha_valida($_POST['fecha'] ?? '') ?? date('Y-m-d'),
            texto($_POST['cuenta'] ?? ''),
            texto($_POST['nombre'] ?? ''),
            texto($_POST['direccion'] ?? ''),
            texto($_POST['telefono'] ?? ''),
            (int) ($_POST['trabajo_id'] ?? 0),
            (int) ($_POST['observacion_id'] ?? 0) ?: null,
            $lat,
            $lng,
        ];
        $error = null;
        if (!$datos[2] || !$datos[3]) {
            $error = 'Nombre y dirección son obligatorios.';
        } elseif (!isset(lista_trabajos(!$id)[$datos[5]])) {
            $error = 'Seleccione el reclamo.';
        } elseif ($datos[6] !== null && !isset(lista_observaciones(false)[$datos[6]])) {
            $error = 'La observación no existe.';
        } elseif ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180) {
            $error = 'Ubique el reclamo en el mapa (haga clic en el mapa o use el buscador).';
        } elseif ($datos[0] > date('Y-m-d')) {
            $error = 'La fecha no puede ser futura.';
        }

        if ($error) {
            aviso($error, 'error');
            $_SESSION['form_reclamo'] = $_POST;   // para no perder lo digitado
            redirigir('reclamos.php' . ($id ? '?editar=' . $id : ''));
        }
        if ($id) {
            $n = q(
                "UPDATE ordenes_trabajo SET fecha=?, cuenta=?, nombre=?, direccion=?, telefono=?, trabajo_id=?, observacion_id=?, latitud=?, longitud=?
                 WHERE id=? AND estado='pendiente'",
                [...$datos, $id]
            )->rowCount();
            aviso($n ? "Reclamo de la Orden N° $id actualizado." : 'Solo se pueden editar reclamos pendientes.', $n ? 'ok' : 'error');
        } else {
            $nuevo = q(
                'INSERT INTO ordenes_trabajo (fecha, cuenta, nombre, direccion, telefono, trabajo_id, observacion_id, latitud, longitud, creado_por)
                 VALUES (?,?,?,?,?,?,?,?,?,?) RETURNING id',
                [...$datos, $u['id']]
            )->fetchColumn();
            aviso("Reclamo registrado como Orden de Trabajo N° $nuevo.");
        }
    } elseif ($accion === 'anular') {
        $n = q("UPDATE ordenes_trabajo SET estado='anulada' WHERE id=? AND estado='pendiente'", [$id])->rowCount();
        aviso($n ? "Orden N° $id anulada." : 'Solo se pueden anular órdenes pendientes.', $n ? 'ok' : 'error');
    } elseif ($accion === 'reactivar') {
        q("UPDATE ordenes_trabajo SET estado='pendiente' WHERE id=? AND estado='anulada'", [$id]);
        aviso("Orden N° $id reactivada.");
    }
    redirigir($volver);
}

$estado = $_GET['estado'] ?? 'pendiente';
if ($estado !== 'todos' && !isset(ESTADOS_ORDEN[$estado])) {
    $estado = 'pendiente';
}
$buscar = trim((string) ($_GET['buscar'] ?? ''));

$where = [];
$params = [];
if ($estado !== 'todos') {
    $where[] = 'estado = ?';
    $params[] = $estado;
}
if ($buscar !== '') {
    $where[] = "(nombre ILIKE ? OR direccion ILIKE ? OR COALESCE(cuenta,'') ILIKE ? OR COALESCE(telefono,'') ILIKE ?)";
    array_push($params, ...array_fill(0, 4, '%' . $buscar . '%'));
}
$lista = consulta_ordenes($where ? implode(' AND ', $where) : 'TRUE', $params, 'fecha DESC, id DESC LIMIT 500');

// Valores del formulario: lo digitado (si hubo error), el reclamo a editar o valores nuevos
$editar = null;
if (!empty($_GET['editar'])) {
    $editar = q("SELECT * FROM ordenes_trabajo WHERE id = ? AND estado = 'pendiente'", [(int) $_GET['editar']])->fetch() ?: null;
}
$f = $_SESSION['form_reclamo'] ?? $editar ?? ['fecha' => date('Y-m-d')];
unset($_SESSION['form_reclamo']);
$v = fn(string $k) => $f[$k] ?? '';

cabecera('Reclamos', true);
?>
<section class="tarjeta">
  <h2><?= $editar ? 'Editar reclamo de la Orden N° ' . h($editar['id']) : 'Registrar nuevo reclamo' ?></h2>
  <form method="post" id="form-reclamo" class="dos-columnas iguales">
    <?= csrf_campo() ?>
    <input type="hidden" name="accion" value="guardar">
    <input type="hidden" name="id" value="<?= h($editar['id'] ?? '') ?>">
    <input type="hidden" name="filtro_estado" value="<?= h($estado) ?>">
    <input type="hidden" name="latitud" value="<?= h($v('latitud')) ?>">
    <input type="hidden" name="longitud" value="<?= h($v('longitud')) ?>">
    <div class="rejilla dos">
      <label>Cuenta
        <span class="con-boton">
          <input name="cuenta" maxlength="30" value="<?= h($v('cuenta')) ?>" autocomplete="off" <?= $editar ? '' : 'autofocus' ?>>
          <button type="button" id="buscar-cuenta">Buscar</button>
        </span>
      </label>
      <label>Fecha
        <input type="date" name="fecha" value="<?= h($v('fecha')) ?>" max="<?= date('Y-m-d') ?>" required>
      </label>
      <div class="ancho2" id="estado-cuenta" hidden></div>
      <label class="ancho2">Nombre del ciudadano
        <input name="nombre" maxlength="160" value="<?= h($v('nombre')) ?>" required>
      </label>
      <label class="ancho2">Dirección
        <input name="direccion" maxlength="200" value="<?= h($v('direccion')) ?>" required>
      </label>
      <label>Teléfono
        <input name="telefono" maxlength="30" value="<?= h($v('telefono')) ?>">
      </label>
      <label>Reclamo
        <select name="trabajo_id" required><?= opciones(lista_trabajos(), $v('trabajo_id'), '— Seleccione —') ?></select>
      </label>
      <label class="ancho2">Observación
        <select name="observacion_id"><?= opciones(lista_observaciones(), $v('observacion_id'), '— Ninguna —') ?></select>
      </label>
      <div class="acciones ancho2">
        <button type="submit" class="primario"><?= $editar ? 'Guardar cambios' : 'Registrar reclamo' ?></button>
        <?php if ($editar): ?><a class="boton" href="reclamos.php?estado=<?= h($estado) ?>">Cancelar</a><?php endif; ?>
      </div>
      <div class="ancho2 suave" id="ayuda-ubicacion"></div>
    </div>
    <div id="mapa" class="mapa"></div>
  </form>
</section>

<section class="tarjeta">
  <form method="get" class="filtros">
    <label>Estado
      <select name="estado" onchange="this.form.submit()">
        <?= opciones(ESTADOS_ORDEN + ['todos' => 'Todos'], $estado) ?>
      </select>
    </label>
    <label>Buscar
      <input name="buscar" value="<?= h($buscar) ?>" placeholder="Cuenta, nombre, dirección…">
    </label>
    <button type="submit">Filtrar</button>
    <span class="suave"><?= count($lista) ?> reclamo(s)</span>
  </form>
  <div class="tabla-scroll">
  <table>
    <thead><tr><th>Orden</th><th>Fecha</th><th>Cuenta</th><th>Nombre</th><th>Dirección</th><th>Teléfono</th><th>Reclamo</th><th>Observación</th><th>Estado</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($lista as $r): ?>
      <tr>
        <td><a href="orden.php?id=<?= h($r['id']) ?>">N° <?= h($r['id']) ?></a></td>
        <td class="nowrap"><?= fecha_es($r['fecha']) ?></td>
        <td><?= h($r['cuenta']) ?></td>
        <td><?= h($r['nombre']) ?></td>
        <td><?= h($r['direccion']) ?></td>
        <td><?= h($r['telefono']) ?></td>
        <td><span class="punto" style="background:<?= h($r['trabajo_color']) ?>"></span><?= h($r['trabajo']) ?></td>
        <td><?= h($r['observacion']) ?></td>
        <td><span class="estado <?= h($r['estado']) ?>"><?= h(ESTADOS_ORDEN[$r['estado']]) ?></span></td>
        <td class="nowrap">
          <?php if ($r['estado'] === 'pendiente'): ?>
            <a href="reclamos.php?editar=<?= h($r['id']) ?>&amp;estado=<?= h($estado) ?>">Editar</a>
          <?php endif; ?>
          <?php if ($r['estado'] !== 'finalizada'): ?>
          <form method="post" class="enlinea" onsubmit="return confirm('¿Confirma la acción sobre la orden N° <?= h($r['id']) ?>?')">
            <?= csrf_campo() ?>
            <input type="hidden" name="id" value="<?= h($r['id']) ?>">
            <input type="hidden" name="filtro_estado" value="<?= h($estado) ?>">
            <button class="enlace" name="accion" value="<?= $r['estado'] === 'pendiente' ? 'anular' : 'reactivar' ?>">
              <?= $r['estado'] === 'pendiente' ? 'Anular' : 'Reactivar' ?></button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$lista): ?><tr><td colspan="10" class="suave centro">No hay reclamos con este filtro.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</section>

<script>
(function () {
  const f = document.getElementById('form-reclamo');
  const caja = document.getElementById('estado-cuenta');
  const ayuda = document.getElementById('ayuda-ubicacion');
  const editarId = <?= json_js($editar['id'] ?? null) ?>;
  const mapa = OT.crearMapa('mapa');
  let marcador = null;

  function ubicar(lat, lng, centrar) {
    if (!marcador) {
      marcador = L.marker([lat, lng], {draggable: true}).addTo(mapa);
      marcador.on('dragend', () => { const p = marcador.getLatLng(); ubicar(p.lat, p.lng); });
    } else {
      marcador.setLatLng([lat, lng]);
    }
    f.latitud.value = lat.toFixed(7);
    f.longitud.value = lng.toFixed(7);
    ayuda.textContent = '📍 Ubicación: ' + lat.toFixed(5) + ', ' + lng.toFixed(5) + ' (puede arrastrar el marcador para corregir)';
    if (centrar) mapa.setView([lat, lng], 17);
  }

  function aviso(texto, tipo) {
    caja.hidden = !texto;
    caja.className = 'ancho2 aviso ' + (tipo || 'info');
    caja.textContent = texto || '';
  }

  const buscador = OT.buscador(mapa, (lat, lng) => ubicar(lat, lng));
  mapa.on('click', e => ubicar(e.latlng.lat, e.latlng.lng));
  if (f.latitud.value) ubicar(parseFloat(f.latitud.value), parseFloat(f.longitud.value), true);
  else ayuda.textContent = 'Ubique el reclamo: busque la calle o referencia en el mapa, o haga clic en el mapa.';

  let consultada = f.cuenta.value.trim();
  async function buscarCuenta(forzar) {
    const cuenta = f.cuenta.value.trim();
    if (!cuenta) { aviso(''); consultada = ''; return; }
    if (cuenta === consultada && !forzar) return;
    consultada = cuenta;
    aviso('Buscando la cuenta ' + cuenta + '…');
    try {
      const r = await fetch('cuenta.php?' + new URLSearchParams({cuenta: cuenta, excluir: editarId || ''}));
      const d = await r.json();
      const pendientes = d.pendientes.length
        ? ' Atención: esta cuenta ya tiene la(s) orden(es) pendiente(s) N° ' + d.pendientes.join(', ') + '.' : '';
      if (!d.encontrada) {
        aviso('La cuenta ' + cuenta + ' no existe: llene los datos manualmente y ubique el reclamo en el mapa.' + pendientes, 'error');
        f.nombre.focus();
        return;
      }
      f.nombre.value = d.nombre || '';
      f.direccion.value = d.direccion || '';
      if (d.latitud !== null) {
        ubicar(d.latitud, d.longitud, true);
        aviso('Cuenta encontrada: se llenaron el nombre, la dirección y la ubicación.' + pendientes, pendientes ? 'error' : 'ok');
      } else {
        buscador.ponerTexto(d.direccion);
        aviso('Cuenta encontrada, pero sin coordenadas: ubique el reclamo en el mapa (la dirección ya está en el buscador).' + pendientes, 'error');
      }
      f.telefono.focus();
    } catch (e) {
      aviso('No se pudo consultar la cuenta.', 'error');
    }
  }

  document.getElementById('buscar-cuenta').addEventListener('click', () => buscarCuenta(true));
  f.cuenta.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); buscarCuenta(true); } });
  f.cuenta.addEventListener('change', () => buscarCuenta(false));
  f.direccion.addEventListener('change', () => { if (!f.latitud.value) buscador.ponerTexto(f.direccion.value); });
  f.addEventListener('submit', e => {
    if (!f.latitud.value) {
      e.preventDefault();
      ayuda.textContent = '⚠ Falta ubicar el reclamo en el mapa.';
      document.getElementById('mapa').scrollIntoView({block: 'center'});
    }
  });
})();
</script>
<?php pie();
