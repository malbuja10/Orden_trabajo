<?php
// g) Administracion de usuarios (supervisores y operadores).
declare(strict_types=1);
require __DIR__ . '/../src/web.php';
$u = requerir_login();

if (es_post()) {
    $id = (int) ($_POST['id'] ?? 0);
    $nombre = texto($_POST['nombre'] ?? '');
    $correo = strtolower((string) texto($_POST['correo'] ?? ''));
    $rol = in_array($_POST['rol'] ?? '', ['supervisor', 'operador'], true) ? $_POST['rol'] : null;
    $activo = !empty($_POST['activo']);
    $clave = (string) ($_POST['password'] ?? '');

    $error = null;
    if (!$nombre || !$rol || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $error = 'Nombre, correo válido y rol son obligatorios.';
    } elseif (!$id && strlen($clave) < 8) {
        $error = 'La contraseña debe tener al menos 8 caracteres.';
    } elseif ($clave !== '' && strlen($clave) < 8) {
        $error = 'La contraseña debe tener al menos 8 caracteres.';
    } elseif (q('SELECT 1 FROM usuarios WHERE lower(correo) = ? AND id <> ?', [$correo, $id])->fetch()) {
        $error = 'Ya existe un usuario con ese correo.';
    } elseif ($id === (int) $u['id'] && (!$activo || $rol !== 'supervisor')) {
        $error = 'No puede desactivar ni cambiar el rol de su propio usuario.';
    }

    if ($error) {
        aviso($error, 'error');
        redirigir('usuarios.php' . ($id ? '?editar=' . $id : ''));
    }
    if ($id) {
        q('UPDATE usuarios SET nombre=?, correo=?, rol=?, activo=? WHERE id=?', [$nombre, $correo, $rol, pgbool($activo), $id]);
        if ($clave !== '') {
            q('UPDATE usuarios SET clave_hash=? WHERE id=?', [password_hash($clave, PASSWORD_DEFAULT), $id]);
            q('DELETE FROM api_tokens WHERE usuario_id=?', [$id]); // cierra sesiones de la app
        }
        if (!$activo) {
            q('DELETE FROM api_tokens WHERE usuario_id=?', [$id]);
        }
        aviso("Usuario «{$nombre}» actualizado.");
    } else {
        q('INSERT INTO usuarios (nombre, correo, clave_hash, rol, activo) VALUES (?,?,?,?,?)',
            [$nombre, $correo, password_hash($clave, PASSWORD_DEFAULT), $rol, pgbool($activo)]);
        aviso("Usuario «{$nombre}» creado.");
    }
    redirigir('usuarios.php');
}

$usuarios = q('SELECT * FROM usuarios ORDER BY rol DESC, nombre')->fetchAll();
$editar = !empty($_GET['editar']) ? (q('SELECT * FROM usuarios WHERE id = ?', [(int) $_GET['editar']])->fetch() ?: null) : null;

cabecera('Usuarios');
?>
<section class="tarjeta">
  <h2><?= $editar ? 'Editar usuario' : 'Nuevo usuario' ?></h2>
  <form method="post" class="rejilla" autocomplete="off">
    <?= csrf_campo() ?>
    <input type="hidden" name="id" value="<?= h($editar['id'] ?? '') ?>">
    <label>Nombre <input name="nombre" maxlength="120" value="<?= h($editar['nombre'] ?? '') ?>" required></label>
    <label>Correo <input type="email" name="correo" maxlength="160" value="<?= h($editar['correo'] ?? '') ?>" required></label>
    <label>Rol
      <select name="rol"><?= opciones(['operador' => 'Operador (app móvil)', 'supervisor' => 'Supervisor (Web)'], $editar['rol'] ?? 'operador') ?></select>
    </label>
    <label>Contraseña <?= $editar ? '(vacío = no cambiar)' : '(mín. 8 caracteres)' ?>
      <input type="password" name="password" minlength="8" autocomplete="new-password" <?= $editar ? '' : 'required' ?>>
    </label>
    <label class="casilla"><input type="checkbox" name="activo" value="1" <?= ($editar['activo'] ?? true) ? 'checked' : '' ?>> Activo</label>
    <div class="acciones ancho3">
      <button type="submit" class="primario"><?= $editar ? 'Guardar cambios' : 'Crear usuario' ?></button>
      <?php if ($editar): ?><a class="boton" href="usuarios.php">Cancelar</a><?php endif; ?>
    </div>
  </form>
</section>

<section class="tarjeta">
  <table>
    <thead><tr><th>Nombre</th><th>Correo</th><th>Rol</th><th>Estado</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($usuarios as $x): ?>
      <tr>
        <td><?= h($x['nombre']) ?></td>
        <td><?= h($x['correo']) ?></td>
        <td><?= $x['rol'] === 'supervisor' ? 'Supervisor' : 'Operador' ?></td>
        <td><span class="estado <?= $x['activo'] ? 'activo' : 'inactivo' ?>"><?= $x['activo'] ? 'Activo' : 'Inactivo' ?></span></td>
        <td><a href="usuarios.php?editar=<?= h($x['id']) ?>">Editar</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php pie();
