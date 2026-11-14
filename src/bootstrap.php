<?php
declare(strict_types=1);

$GLOBALS['config'] = require __DIR__ . '/../config/config.php';
date_default_timezone_set($GLOBALS['config']['zona_horaria']);

function config(string $clave)
{
    $v = $GLOBALS['config'];
    foreach (explode('.', $clave) as $p) {
        $v = $v[$p] ?? null;
    }
    return $v;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = config('db');
        $pdo = new PDO(
            "pgsql:host={$c['host']};port={$c['port']};dbname={$c['name']}",
            $c['user'],
            $c['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        $pdo->exec('SET TIME ZONE ' . $pdo->quote(config('zona_horaria')));
    }
    return $pdo;
}

/** Ejecuta una consulta preparada y devuelve el statement. */
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

/** Convierte un valor PHP al literal booleano que espera PostgreSQL. */
function pgbool($v): string
{
    return $v ? 'true' : 'false';
}

/** PDO entrega los DOUBLE PRECISION como texto: se convierten a float (o null). */
function num($v): ?float
{
    return $v === null ? null : (float) $v;
}

function h($s): string
{
    return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES, 'UTF-8');
}

/** Texto del formulario: recortado, o null si viene vacio. */
function texto(?string $s): ?string
{
    $s = trim((string) $s);
    return $s === '' ? null : $s;
}

function fecha_valida(?string $s): ?string
{
    if ($s === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
        return null;
    }
    [$a, $m, $d] = array_map('intval', explode('-', $s));
    return checkdate($m, $d, $a) ? $s : null;
}

function fecha_es(?string $s): string
{
    if (!$s) {
        return '';
    }
    $t = strtotime($s);
    return strlen($s) > 10 ? date('d/m/Y H:i', $t) : date('d/m/Y', $t);
}

const ESTADOS_ORDEN = ['asignada' => 'Asignada', 'realizada' => 'Realizada', 'no_realizada' => 'No realizada'];
const ESTADOS_RECLAMO = ['pendiente' => 'Pendiente', 'asignado' => 'Asignado', 'atendido' => 'Atendido', 'anulado' => 'Anulado'];
const MESES = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
