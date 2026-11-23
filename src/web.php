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

/**
 * Grafico de barras verticales en SVG (una sola serie).
 * $datos: [etiqueta => valor]. Etiquetas inclinadas, valor sobre cada barra y
 * descripcion emergente (title) al pasar el mouse.
 */
function grafico_barras(array $datos, string $titulo): string
{
    $n = count($datos);
    if ($n === 0) {
        return '<p class="suave">No hay tipos de trabajo registrados.</p>';
    }
    $paso = 38;                     // ancho de cada columna
    $anchoBarra = 24;
    $altoPlot = 240;
    $angulo = 40;                   // inclinacion de las etiquetas
    $anchoLetra = 6.3;              // aproximado para 11px
    $cos = cos(deg2rad($angulo));
    $sin = sin(deg2rad($angulo));

    $etiquetas = array_map('strval', array_keys($datos));
    $largos = array_map(fn($e) => mb_strlen($e) * $anchoLetra, $etiquetas);
    // Margen izquierdo suficiente para que la etiqueta inclinada de las primeras barras no se corte
    $izq = 44;
    foreach ($largos as $i => $l) {
        $izq = max($izq, (int) ceil($l * $cos - $i * $paso - $paso / 2 + 6));
    }
    $sup = 22;
    $inf = (int) ceil(max($largos) * $sin) + 22;
    $ancho = $izq + $n * $paso + 12;
    $alto = $sup + $altoPlot + $inf;

    // Escala con un maximo "redondo"
    $max = max(1, ...array_values($datos));
    $mag = 10 ** floor(log10($max / 5));
    $pasoEje = (int) max(1, ceil($max / 5 / $mag) * $mag);
    foreach ([1, 2, 5, 10] as $f) {
        if ($f * $mag >= $max / 5) {
            $pasoEje = (int) max(1, $f * $mag);
            break;
        }
    }
    $tope = (int) (ceil($max / $pasoEje) * $pasoEje);
    $y = fn($v) => $sup + $altoPlot - $v / $tope * $altoPlot;
    $base = $sup + $altoPlot;

    $svg = sprintf('<svg class="grafico" viewBox="0 0 %d %d" width="%d" role="img" aria-label="%s">', $ancho, $alto, $ancho, h($titulo));
    for ($v = 0; $v <= $tope; $v += $pasoEje) {
        $yy = round($y($v), 1);
        $svg .= sprintf('<line class="g-rejilla" x1="%d" x2="%d" y1="%s" y2="%s"/>', $izq, $ancho - 8, $yy, $yy);
        $svg .= sprintf('<text class="g-eje" x="%d" y="%s" text-anchor="end" dy="4">%d</text>', $izq - 8, $yy, $v);
    }
    $i = 0;
    foreach ($datos as $etiqueta => $valor) {
        $cx = $izq + $i * $paso + $paso / 2;
        $x0 = $cx - $anchoBarra / 2;
        $svg .= '<g class="g-columna"><title>' . h($etiqueta) . ': ' . (int) $valor . '</title>';
        $svg .= sprintf('<rect class="g-hit" x="%s" y="%d" width="%d" height="%d"/>', $cx - $paso / 2, $sup, $paso, $altoPlot);
        if ($valor > 0) {
            $yt = $y($valor);
            $r = min(4, ($base - $yt) / 2, $anchoBarra / 2);
            // Barra con las esquinas superiores redondeadas y la base recta
            $svg .= sprintf(
                '<path class="g-barra" d="M%1$s %2$s V%3$s Q%1$s %4$s %5$s %4$s H%6$s Q%7$s %4$s %7$s %3$s V%2$s Z"/>',
                round($x0, 1), $base, round($yt + $r, 1), round($yt, 1), round($x0 + $r, 1), round($x0 + $anchoBarra - $r, 1), round($x0 + $anchoBarra, 1)
            );
        } else {
            $yt = $base;
        }
        $svg .= sprintf('<text class="g-valor" x="%s" y="%s" text-anchor="middle">%d</text>', $cx, round($yt - 6, 1), $valor);
        $svg .= sprintf(
            '<text class="g-etiqueta" text-anchor="end" transform="translate(%s %d) rotate(-%d)">%s</text></g>',
            $cx + 4, $base + 14, $angulo, h($etiqueta)
        );
        $i++;
    }
    $svg .= sprintf('<line class="g-base" x1="%d" x2="%d" y1="%d" y2="%d"/>', $izq, $ancho - 8, $base, $base);
    return '<div class="grafico-scroll">' . $svg . '</svg></div>';
}
