<?php
declare(strict_types=1);
require __DIR__ . '/../src/web.php';

if (usuario_actual()) {
    redirigir('index.php');
}

$error = null;
$correo = '';
if (es_post()) {
    $correo = strtolower(trim((string) ($_POST['correo'] ?? '')));
    $u = q('SELECT * FROM usuarios WHERE lower(correo) = ? AND activo', [$correo])->fetch();
    if (!$u || !password_verify((string) ($_POST['password'] ?? ''), $u['clave_hash'])) {
        $error = 'Correo o contraseña incorrectos.';
    } elseif ($u['rol'] !== 'supervisor') {
        $error = 'El aplicativo Web es para supervisores. Los operadores usan la app móvil.';
    } else {
        session_regenerate_id(true);
        $_SESSION['usuario'] = ['id' => $u['id'], 'nombre' => $u['nombre'], 'correo' => $u['correo'], 'rol' => $u['rol']];
        redirigir('index.php');
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ingreso · Órdenes de Trabajo</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body class="login">
<form method="post" class="tarjeta login-caja">
  <div class="login-logo">💧</div>
  <h1>Órdenes de Trabajo</h1>
  <p class="suave"><?= h(config('empresa')) ?></p>
  <?php if ($error): ?><div class="aviso error"><?= h($error) ?></div><?php endif; ?>
  <?= csrf_campo() ?>
  <label>Correo
    <input type="email" name="correo" value="<?= h($correo) ?>" required autofocus autocomplete="username">
  </label>
  <label>Contraseña
    <input type="password" name="password" required autocomplete="current-password">
  </label>
  <button type="submit" class="primario">Ingresar</button>
</form>
</body>
</html>
