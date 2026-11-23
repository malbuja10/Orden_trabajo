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
    'registrar.php'     => 'Registrar órdenes',
    'mapa_diario.php'   => 'Mapa diario',
    'ordenes.php'       => 'Reporte de órdenes',
    'mapa_tematico.php' => 'Mapa temático',
    'resumen.php'       => 'Resumen',
    'usuarios.php'      => 'Usuarios',
    'trabajos.php'      => 'Trabajos',
    'observaciones.php' => 'Observaciones',
];

/**
 * Filtro comun de los mapas: estado (todos / pendiente / finalizada) y rango de fechas.
 * Pendientes se filtran por la fecha del reclamo; finalizadas por la fecha de registro.
 * Devuelve [estado, desde, hasta, where, params] para consulta_ordenes().
 */
function filtro_mapa(): array
{
    $estado = in_array($_GET['estado'] ?? '', ['pendiente', 'finalizada'], true) ? $_GET['estado'] : 'todos';
    $desde = fecha_valida($_GET['desde'] ?? '') ?? date('Y-m-d');
    $hasta = fecha_valida($_GET['hasta'] ?? '') ?? date('Y-m-d');
    if ($hasta < $desde) {
        [$desde, $hasta] = [$hasta, $desde];
    }
    $where = ["estado <> 'anulada'", 'fecha_orden BETWEEN ? AND ?'];
    $params = [$desde, $hasta];
    if ($estado !== 'todos') {
        $where[] = 'estado = ?';
        $params[] = $estado;
    }
    return [$estado, $desde, $hasta, implode(' AND ', $where), $params];
}

function filtro_mapa_html(string $estado, string $desde, string $hasta): void
{
    $hoy = date('Y-m-d');
    $rapidos = [
        'Hoy' => [$hoy, $hoy],
        'Últimos 7 días' => [date('Y-m-d', strtotime('-6 days')), $hoy],
        'Este mes' => [date('Y-m-01'), $hoy],
        'Este año' => [date('Y-01-01'), $hoy],
    ];
    ?>
<form method="get" class="tarjeta filtros">
  <label>Mostrar
    <select name="estado" onchange="this.form.submit()">
      <?= opciones(['todos' => 'Todos', 'pendiente' => 'Pendientes', 'finalizada' => 'Finalizados'], $estado) ?>
    </select>
  </label>
  <label>Desde <input type="date" name="desde" value="<?= h($desde) ?>"></label>
  <label>Hasta <input type="date" name="hasta" value="<?= h($hasta) ?>"></label>
  <button type="submit" class="primario">Ver</button>
  <span class="rapidos">
    <?php foreach ($rapidos as $txt => [$d, $hst]): ?>
      <a href="?<?= h(http_build_query(['estado' => $estado, 'desde' => $d, 'hasta' => $hst])) ?>"<?= $d === $desde && $hst === $hasta ? ' class="activo"' : '' ?>><?= h($txt) ?></a>
    <?php endforeach; ?>
  </span>
  <span class="suave ancho-total">Pendientes por fecha del reclamo; finalizados por fecha de registro.</span>
</form>
<?php
}

/** Parametros de la busqueda de calles para el navegador. */
function config_busqueda(): array
{
    $b = config('busqueda') ?? [];
    $m = config('mapa');
    $r = (float) ($b['radio_grados'] ?? 0.15);
    return [
        'url' => $b['url'] ?? 'https://nominatim.openstreetmap.org/search',
        'pais' => $b['pais'] ?? '',
        // viewbox de Nominatim: oeste,norte,este,sur
        'viewbox' => implode(',', [$m['lng'] - $r, $m['lat'] + $r, $m['lng'] + $r, $m['lat'] - $r]),
    ];
}

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
<script>window.OT_MAPA = <?= json_encode(config('mapa')) ?>;
window.OT_BUSQUEDA = <?= json_encode(config_busqueda()) ?>;</script>
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
