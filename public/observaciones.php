<?php
// g) Administracion del catalogo de observaciones de los reclamos.
declare(strict_types=1);
require __DIR__ . '/../src/web.php';
requerir_login();

if (es_post()) {
    $id = (int) ($_POST['id'] ?? 0);
    $nombre = texto($_POST['nombre'] ?? '');
    $activo = !empty($_POST['activo']);

    if (!$nombre) {
        aviso('El texto de la observación es obligatorio.', 'error');
    } elseif (q('SELECT 1 FROM observaciones WHERE lower(nombre) = lower(?) AND id <> ?', [$nombre, $id])->fetch()) {
        aviso('Ya existe esa observación.', 'error');
    } elseif ($id) {
        q('UPDATE observaciones SET nombre=?, activo=? WHERE id=?', [$nombre, pgbool($activo), $id]);
        aviso("Observación «{$nombre}» actualizada.");
    } else {
        q('INSERT INTO observaciones (nombre, activo) VALUES (?,?)', [$nombre, pgbool($activo)]);
        aviso("Observación «{$nombre}» creada.");
    }
    redirigir('observaciones.php');
}

$lista = q('SELECT b.*, (SELECT COUNT(*) FROM ordenes_trabajo o WHERE o.observacion_id = b.id) AS usos
            FROM observaciones b ORDER BY b.nombre')->fetchAll();
$editar = !empty($_GET['editar']) ? (q('SELECT * FROM observaciones WHERE id = ?', [(int) $_GET['editar']])->fetch() ?: null) : null;

cabecera('Observaciones');
?>
<section class="tarjeta">
  <h2><?= $editar ? 'Editar observación' : 'Nueva observación' ?></h2>
  <form method="post" class="rejilla">
    <?= csrf_campo() ?>
    <input type="hidden" name="id" value="<?= h($editar['id'] ?? '') ?>">
    <label class="ancho3">Observación <input name="nombre" maxlength="120" value="<?= h($editar['nombre'] ?? '') ?>" required autofocus></label>
    <label class="casilla"><input type="checkbox" name="activo" value="1" <?= ($editar['activo'] ?? true) ? 'checked' : '' ?>> Activa</label>
    <div class="acciones ancho4">
      <button type="submit" class="primario"><?= $editar ? 'Guardar cambios' : 'Crear observación' ?></button>
      <?php if ($editar): ?><a class="boton" href="observaciones.php">Cancelar</a><?php endif; ?>
    </div>
  </form>
</section>

<section class="tarjeta">
  <table>
    <thead><tr><th>Observación</th><th>Órdenes</th><th>Estado</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($lista as $b): ?>
      <tr>
        <td><?= h($b['nombre']) ?></td>
        <td><?= h($b['usos']) ?></td>
        <td><span class="estado <?= $b['activo'] ? 'activo' : 'inactivo' ?>"><?= $b['activo'] ? 'Activa' : 'Inactiva' ?></span></td>
        <td><a href="observaciones.php?editar=<?= h($b['id']) ?>">Editar</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$lista): ?><tr><td colspan="4" class="suave centro">Aún no hay observaciones registradas.</td></tr><?php endif; ?>
    </tbody>
  </table>
  <p class="suave">Las observaciones no se eliminan (para conservar el historial); desactívelas para que no aparezcan al registrar reclamos.</p>
</section>
<?php pie();
