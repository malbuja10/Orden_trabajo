<?php
// Soporte comun de las paginas Web: sesion, autenticacion, CSRF y plantilla.
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/ordenes.php';

session_name('OTSESSID');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

function usuario_actual(): ?array
{
    return $_SESSION['usuario'] ?? null;
}

/** Exige sesion iniciada (el aplicativo Web es para supervisores). */
function requerir_login(): array
{
    $u = usuario_actual();
    if (!$u) {
        header('Location: login.php');
        exit;
    }
    return $u;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_campo(): string
{
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

function csrf_verificar(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Solicitud no válida (token CSRF). Regrese e intente de nuevo.');
    }
}

function es_post(): bool
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return false;
    }
    csrf_verificar();
    return true;
}

function aviso(string $msg, string $tipo = 'ok'): void
{
    $_SESSION['avisos'][] = [$tipo, $msg];
}

function redirigir(string $url): void
{
    header('Location: ' . $url);
    exit;
}

const MENU = [
    'reclamos.php'      => 'Reclamos',
    'asignar.php'       => 'Asignar órdenes',
    'mapa_diario.php'   => 'Mapa diario',
    'ordenes.php'       => 'Reporte de órdenes',
    'mapa_tematico.php' => 'Mapa temático',
    'resumen.php'       => 'Resumen',
    'usuarios.php'      => 'Usuarios',
    'trabajos.php'      => 'Trabajos',
];

function cabecera(string $titulo, bool $conMapa = false): void
{
    $u = usuario_actual();
    $actual = basename($_SERVER['SCRIPT_NAME']);
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($titulo) ?> · Órdenes de Trabajo</title>
<?php if ($conMapa): ?>
<link rel="stylesheet" href="assets/leaflet/leaflet.css">
<?php endif; ?>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<?php if ($u): ?>
<header class="barra">
  <div class="marca">💧 Órdenes de Trabajo</div>
  <nav>
    <?php foreach (MENU as $url => $nombre): ?>
      <a href="<?= $url ?>"<?= $url === $actual ? ' class="activo"' : '' ?>><?= h($nombre) ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="sesion"><?= h($u['nombre']) ?> · <a href="logout.php">Salir</a></div>
</header>
<?php endif; ?>
<main>
<h1><?= h($titulo) ?></h1>
<?php foreach ($_SESSION['avisos'] ?? [] as [$tipo, $msg]): ?>
  <div class="aviso <?= h($tipo) ?>"><?= h($msg) ?></div>
<?php endforeach;
    unset($_SESSION['avisos']);
    if ($conMapa): ?>
<script src="assets/leaflet/leaflet.js"></script>
<script>window.OT_MAPA = <?= json_encode(config('mapa')) ?>;</script>
<script src="assets/app.js"></script>
<?php endif;
}

function pie(): void
{
    echo "</main>\n<footer>" . h(config('empresa')) . " · Sistema de Órdenes de Trabajo</footer>\n</body>\n</html>\n";
}

/** JSON seguro para incrustar dentro de <script>. */
function json_js($v): string
{
    return json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
}

function opciones(array $filas, $seleccion, string $vacio = ''): string
{
    $html = $vacio !== '' ? '<option value="">' . h($vacio) . '</option>' : '';
    foreach ($filas as $id => $nombre) {
        $sel = (string) $id === (string) $seleccion ? ' selected' : '';
        $html .= '<option value="' . h($id) . '"' . $sel . '>' . h($nombre) . '</option>';
    }
    return $html;
}
