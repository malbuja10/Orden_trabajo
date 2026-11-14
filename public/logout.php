<?php
declare(strict_types=1);
require __DIR__ . '/../src/web.php';
$_SESSION = [];
session_destroy();
redirigir('login.php');
