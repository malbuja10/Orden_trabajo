<?php
// a) Registro de reclamos de los ciudadanos.
declare(strict_types=1);
require __DIR__ . '/../src/web.php';
$u = requerir_login();

if (es_post()) {
    $accion = $_POST['accion'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    if ($accion === 'guardar') {
        $datos = [
            fecha_valida($_POST['fecha'] ?? '') ?? date('Y-m-d'),
            texto($_POST['nombre'] ?? ''),
            texto($_POST['direccion'] ?? ''),
            texto($_POST['reclamo'] ?? ''),
            texto($_POST['telefono'] ?? ''),
            texto($_POST['medidor'] ?? ''),
            texto($_POST['observaciones'] ?? ''),
        ];
        if (!$datos[1] || !$datos[2] || !$datos[3]) {
            aviso('Nombre, dirección y reclamo son obligatorios.', 'error');
        } elseif ($id) {
            q('UPDATE reclamos SET fecha=?, nombre=?, direccion=?, reclamo=?, telefono=?, medidor=?, observaciones=? WHERE id=?', [...$datos, $id]);
            aviso("Reclamo N° $id actualizado.");
        } else {
            $nuevo = q('INSERT INTO reclamos (fecha, nombre, direccion, reclamo, telefono, medidor, observaciones, creado_por)
                        VALUES (?,?,?,?,?,?,?,?) RETURNING id', [...$datos, $u['id']])->fetchColumn();
            aviso("Reclamo N° $nuevo registrado.");
            if ($datos[5]) {
                aviso(ubicacion_medidor($datos[5])
                    ? "El medidor {$datos[5]} tiene ubicación registrada: el reclamo aparecerá ya ubicado en el mapa."
                    : "El medidor {$datos[5]} no tiene ubicación registrada: habrá que ubicar el reclamo en el mapa.", 'info');
            }
        }
    } elseif ($accion === 'anular') {
        $n = q("UPDATE reclamos SET estado='anulado' WHERE id=? AND estado='pendiente'", [$id])->rowCount();
        aviso($n ? "Reclamo N° $id anulado." : 'Solo se pueden anular reclamos pendientes.', $n ? 'ok' : 'error');
    } elseif ($accion === 'reactivar') {
        q("UPDATE reclamos SET estado='pendiente' WHERE id=? AND estado='anulado'", [$id]);
        aviso("Reclamo N° $id reactivado.");
    }
    redirigir('reclamos.php?' . http_build_query(['estado' => $_POST['filtro_estado'] ?? 'pendiente']));
}

$estado = $_GET['estado'] ?? 'pendiente';
if ($estado !== 'todos' && !isset(ESTADOS_RECLAMO[$estado])) {
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
    $where[] = "(nombre ILIKE ? OR direccion ILIKE ? OR reclamo ILIKE ? OR COALESCE(medidor,'') ILIKE ? OR COALESCE(telefono,'') ILIKE ?)";
    array_push($params, ...array_fill(0, 5, '%' . $buscar . '%'));
}
$reclamos = q('SELECT * FROM reclamos' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY fecha DESC, id DESC LIMIT 500', $params)->fetchAll();

$editar = null;
if (!empty($_GET['editar'])) {
    $editar = q('SELECT * FROM reclamos WHERE id = ?', [(int) $_GET['editar']])->fetch() ?: null;
}

cabecera('Reclamos');
?>
<section class="tarjeta">
  <h2><?= $editar ? 'Editar reclamo N° ' . h($editar['id']) : 'Registrar nuevo reclamo' ?></h2>
  <form method="post" class="rejilla">
    <?= csrf_campo() ?>
    <input type="hidden" name="accion" value="guardar">
    <input type="hidden" name="id" value="<?= h($editar['id'] ?? '') ?>">
    <input type="hidden" name="filtro_estado" value="<?= h($estado) ?>">
    <label>Fecha
      <input type="date" name="fecha" value="<?= h($editar['fecha'] ?? date('Y-m-d')) ?>" required>
    </label>
    <label class="ancho2">Nombre del ciudadano
      <input name="nombre" maxlength="120" value="<?= h($editar['nombre'] ?? '') ?>" required>
    </label>
    <label>Teléfono
      <input name="telefono" maxlength="30" value="<?= h($editar['telefono'] ?? '') ?>">
    </label>
    <label class="ancho3">Dirección
      <input name="direccion" maxlength="200" value="<?= h($editar['direccion'] ?? '') ?>" required>
    </label>
    <label>Medidor (si existe)
      <input name="medidor" maxlength="30" value="<?= h($editar['medidor'] ?? '') ?>">
    </label>
    <label class="ancho2">Reclamo
      <textarea name="reclamo" rows="2" required><?= h($editar['reclamo'] ?? '') ?></textarea>
    </label>
    <label class="ancho2">Observaciones
      <textarea name="observaciones" rows="2"><?= h($editar['observaciones'] ?? '') ?></textarea>
    </label>
    <div class="acciones ancho4">
      <button type="submit" class="primario"><?= $editar ? 'Guardar cambios' : 'Registrar reclamo' ?></button>
      <?php if ($editar): ?><a class="boton" href="reclamos.php?estado=<?= h($estado) ?>">Cancelar</a><?php endif; ?>
    </div>
  </form>
</section>

<section class="tarjeta">
  <form method="get" class="filtros">
    <label>Estado
      <select name="estado" onchange="this.form.submit()">
        <?= opciones(ESTADOS_RECLAMO + ['todos' => 'Todos'], $estado) ?>
      </select>
    </label>
    <label>Buscar
      <input name="buscar" value="<?= h($buscar) ?>" placeholder="Nombre, dirección, medidor…">
    </label>
    <button type="submit">Filtrar</button>
    <span class="suave"><?= count($reclamos) ?> reclamo(s)</span>
  </form>
  <div class="tabla-scroll">
  <table>
    <thead><tr><th>N°</th><th>Fecha</th><th>Nombre</th><th>Dirección</th><th>Reclamo</th><th>Teléfono</th><th>Medidor</th><th>Estado</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($reclamos as $r): ?>
      <tr>
        <td><?= h($r['id']) ?></td>
        <td class="nowrap"><?= fecha_es($r['fecha']) ?></td>
        <td><?= h($r['nombre']) ?></td>
        <td><?= h($r['direccion']) ?></td>
        <td><?= h($r['reclamo']) ?><?php if ($r['observaciones']): ?><br><small class="suave"><?= h($r['observaciones']) ?></small><?php endif; ?></td>
        <td><?= h($r['telefono']) ?></td>
        <td><?= h($r['medidor']) ?></td>
        <td><span class="estado <?= h($r['estado']) ?>"><?= h(ESTADOS_RECLAMO[$r['estado']]) ?></span></td>
        <td class="nowrap">
          <a href="reclamos.php?editar=<?= h($r['id']) ?>&amp;estado=<?= h($estado) ?>">Editar</a>
          <?php if ($r['estado'] === 'pendiente' || $r['estado'] === 'anulado'): ?>
          <form method="post" class="enlinea" onsubmit="return confirm('¿Confirma la acción sobre el reclamo N° <?= h($r['id']) ?>?')">
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
    <?php if (!$reclamos): ?><tr><td colspan="9" class="suave centro">No hay reclamos con este filtro.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</section>
<?php pie();
