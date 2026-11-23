<?php
// Soporte comun del API para la app movil (respuestas JSON y token Bearer).
declare(strict_types=1);

require_once __DIR__ . '/ordenes.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

set_exception_handler(function (Throwable $e): void {
    error_log('API: ' . $e);
    api_error(500, 'Error interno del servidor.');
});

function api_json(array $datos, int $codigo = 200): void
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_error(int $codigo, string $mensaje): void
{
    api_json(['ok' => false, 'error' => $mensaje], $codigo);
}

function api_metodo(string $metodo): void
{
    if ($_SERVER['REQUEST_METHOD'] !== $metodo) {
        header('Allow: ' . $metodo);
        api_error(405, "Método no permitido. Use $metodo.");
    }
}

/** Cuerpo JSON de la peticion como array (o [] si no es JSON). */
function api_cuerpo(): array
{
    $datos = json_decode(file_get_contents('php://input') ?: '', true);
    return is_array($datos) ? $datos : [];
}

/** La app movil es solo para supervisores. */
function api_solo_supervisor(array $u): void
{
    if ($u['rol'] !== 'supervisor') {
        api_error(403, 'Solo los supervisores pueden usar la app móvil.');
    }
}

/** Valida el token "Authorization: Bearer <token>" y devuelve el usuario. */
function api_usuario(): array
{
    $cab = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($cab === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) {
                $cab = $v;
            }
        }
    }
    $token = preg_match('/^Bearer\s+(\S+)$/i', $cab, $m) ? $m[1] : ($_SERVER['HTTP_X_AUTH_TOKEN'] ?? '');
    if ($token === '') {
        api_error(401, 'Falta el token de acceso.');
    }
    $u = q(
        'SELECT u.id, u.nombre, u.correo, u.rol FROM api_tokens k JOIN usuarios u ON u.id = k.usuario_id
         WHERE k.token_hash = ? AND k.expira_en > now() AND u.activo',
        [hash('sha256', $token)]
    )->fetch();
    if (!$u) {
        api_error(401, 'Token no válido o expirado. Inicie sesión nuevamente.');
    }
    api_solo_supervisor($u);
    return $u;
}
