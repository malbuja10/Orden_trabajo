<?php
// g) Administracion de tipos de trabajo (con el color que se usa en el mapa tematico).
declare(strict_types=1);
require __DIR__ . '/../src/web.php';
requerir_login();

if (es_post()) {
    $id = (int) ($_POST['id'] ?? 0);
    $nombre = texto($_POST['nombre'] ?? '');
    $color = strtolower((string) ($_POST['color'] ?? ''));
    $activo = !empty($_POST['activo']);

    if (!$nombre || !preg_match('/^#[0-9a-f]{6}$/', $color)) {
        aviso('Nombre y color son obligatorios.', 'error');
    } elseif (q('SELECT 1 FROM trabajos WHERE lower(nombre) = lower(?) AND id <> ?', [$nombre, $id])->fetch()) {
        aviso('Ya existe un trabajo con ese nombre.', 'error');
    } elseif ($id) {
        q('UPDATE trabajos SET nombre=?, color=?, activo=? WHERE id=?', [$nombre, $color, pgbool($activo), $id]);
        aviso("Trabajo «{$nombre}» actualizado.");
    } else {
        q('INSERT INTO trabajos (nombre, color, activo) VALUES (?,?,?)', [$nombre, $color, pgbool($activo)]);
        aviso("Trabajo «{$nombre}» creado.");
    }
    redirigir('trabajos.php');
}

$trabajos = q('SELECT t.*, (SELECT COUNT(*) FROM ordenes_trabajo o WHERE o.trabajo_id = t.id) AS usos
               FROM trabajos t ORDER BY t.nombre')->fetchAll();
$editar = !empty($_GET['editar']) ? (q('SELECT * FROM trabajos WHERE id = ?', [(int) $_GET['editar']])->fetch() ?: null) : null;

cabecera('Tipos de trabajo');
?>
<section class="tarjeta">
  <h2><?= $editar ? 'Editar trabajo' : 'Nuevo tipo de trabajo' ?></h2>
  <form method="post" class="rejilla">
    <?= csrf_campo() ?>
    <input type="hidden" name="id" value="<?= h($editar['id'] ?? '') ?>">
    <label class="ancho2">Nombre <input name="nombre" maxlength="80" value="<?= h($editar['nombre'] ?? '') ?>" required></label>
    <label>Color en el mapa <input type="color" name="color" value="<?= h($editar['color'] ?? '#1f77b4') ?>"></label>
    <label class="casilla"><input type="checkbox" name="activo" value="1" <?= ($editar['activo'] ?? true) ? 'checked' : '' ?>> Activo</label>
    <div class="acciones ancho4">
      <button type="submit" class="primario"><?= $editar ? 'Guardar cambios' : 'Crear trabajo' ?></button>
      <?php if ($editar): ?><a class="boton" href="trabajos.php">Cancelar</a><?php endif; ?>
    </div>
  </form>
</section>

<section class="tarjeta">
  <table>
    <thead><tr><th>Trabajo</th><th>Color</th><th>Órdenes</th><th>Estado</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($trabajos as $t): ?>
      <tr>
        <td><?= h($t['nombre']) ?></td>
        <td><span class="punto" style="background:<?= h($t['color']) ?>"></span><code><?= h($t['color']) ?></code></td>
        <td><?= h($t['usos']) ?></td>
        <td><span class="estado <?= $t['activo'] ? 'activo' : 'inactivo' ?>"><?= $t['activo'] ? 'Activo' : 'Inactivo' ?></span></td>
        <td><a href="trabajos.php?editar=<?= h($t['id']) ?>">Editar</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="suave">Los trabajos no se eliminan (para conservar el historial); desactívelos para que no aparezcan al registrar reclamos.</p>
</section>
<?php pie();
