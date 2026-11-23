<?php
// API a) Login (solo supervisores).  POST {"correo": "...", "password": "..."}  ->  {"ok": true, "token": "...", "usuario": {...}}
declare(strict_types=1);
require __DIR__ . '/../../src/api.php';

api_metodo('POST');
$in = api_cuerpo() ?: $_POST;
$correo = strtolower(trim((string) ($in['correo'] ?? '')));
$clave  = (string) ($in['password'] ?? '');
if ($correo === '' || $clave === '') {
    api_error(400, 'Se requieren correo y password.');
}

$u = q('SELECT * FROM usuarios WHERE lower(correo) = ? AND activo', [$correo])->fetch();
if (!$u || !password_verify($clave, $u['clave_hash'])) {
    api_error(401, 'Correo o contraseña incorrectos.');
}
api_solo_supervisor($u);

q('DELETE FROM api_tokens WHERE expira_en < now()');
$token = bin2hex(random_bytes(32));
$dias = (int) config('api_token_dias');
$expira = q(
    "INSERT INTO api_tokens (token_hash, usuario_id, expira_en) VALUES (?, ?, now() + make_interval(days => ?))
     RETURNING to_char(expira_en, 'YYYY-MM-DD\"T\"HH24:MI:SS') AS e",
    [hash('sha256', $token), $u['id'], $dias]
)->fetchColumn();

api_json([
    'ok' => true,
    'token' => $token,
    'expira' => $expira,
    'usuario' => ['id' => $u['id'], 'nombre' => $u['nombre'], 'correo' => $u['correo'], 'rol' => $u['rol']],
]);
