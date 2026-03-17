<?php
// index.php

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    http_response_code(200); exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/config/Database.php';
require_once __DIR__ . '/utils/Response.php';
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/UsuarioController.php';
require_once __DIR__ . '/controllers/ProyectoController.php';
require_once __DIR__ . '/controllers/LoteController.php';
require_once __DIR__ . '/controllers/ComisionController.php';

try {
    $db = Database::get();
} catch (PDOException $e) {
    Response::error("Error de conexión: " . $e->getMessage(), 500);
}

// Parsear ruta
$uri         = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$base        = '/api';
$path        = trim(substr($uri, strlen($base)), '/');
$parts       = explode('/', $path);
$method      = $_SERVER['REQUEST_METHOD'];
$resource    = $parts[0] ?? '';
$sub         = $parts[1] ?? '';
$extra       = $parts[2] ?? '';

// ────────────────────────────────────────────────────
if ($resource === 'auth') {
    $c = new AuthController($db);
    switch ($sub) {
        case 'registro':         $method === 'POST' && $c->registro();        break;
        case 'login':            $method === 'POST' && $c->login();           break;
        case 'recuperar':        $method === 'POST' && $c->recuperar();       break;
        case 'nueva-contrasena': $method === 'POST' && $c->nuevaContrasena(); break;
        case 'cuentas':          $method === 'GET'  && $c->getCuentas();      break;
        default: Response::error('Ruta auth no encontrada', 404);
    }

} elseif ($resource === 'usuario') {
    $c = new UsuarioController($db);
    if      ($method === 'GET'  && $sub && !$extra)        $c->getPerfil($sub);
    elseif  ($method === 'PUT'  && $sub && !$extra)        $c->editarPerfil($sub);
    elseif  ($method === 'GET'  && $extra === 'equipo')    $c->getEquipo($sub);
    elseif  ($method === 'POST' && $extra === 'afiliar')   $c->afiliarExistente($sub);
    else Response::error('Ruta usuario no encontrada', 404);

} elseif ($resource === 'proyecto') {
    $c = new ProyectoController($db);
    if      ($method === 'GET' && !$sub)  $c->getAll();
    elseif  ($method === 'GET' && $sub)   $c->getDetalle($sub);
    else Response::error('Ruta proyecto no encontrada', 404);

} elseif ($resource === 'lote') {
    $c = new LoteController($db);
    if      ($method === 'POST' && $sub === 'reservar')                       $c->reservar();
    elseif  ($method === 'POST' && $sub === 'comprar')                        $c->comprar();
    elseif  ($method === 'GET'  && $sub === 'reservados' && $extra)           $c->getReservados($extra);
    elseif  ($method === 'GET'  && $sub === 'comprados'  && $extra)           $c->getComprados($extra);
    else Response::error('Ruta lote no encontrada', 404);

} elseif ($resource === 'comision') {
    $c = new ComisionController($db);
    if      ($method === 'GET'  && $sub === 'saldo'        && $extra)         $c->getSaldo($extra);
    elseif  ($method === 'POST' && $sub === 'recargar')                       $c->recargar();
    elseif  ($method === 'GET'  && $sub === 'recargas'     && $extra)         $c->getRecargas($extra);
    elseif  ($method === 'POST' && $sub === 'retirar')                        $c->retirar();
    elseif  ($method === 'GET'  && $sub === 'retiros'      && $extra)         $c->getRetiros($extra);
    elseif  ($method === 'GET'  && $sub === 'movimientos'  && $extra)         $c->getMovimientos($extra);
    else Response::error('Ruta comision no encontrada', 404);

} elseif ($resource === 'tarjeta') {
    $c = new ComisionController($db);
    if      ($method === 'POST'   && $sub === 'agregar')   $c->agregarTarjeta();
    elseif  ($method === 'GET'    && $sub)                 $c->getTarjetas($sub);
    elseif  ($method === 'PUT'    && $sub)                 $c->editarTarjeta($sub);
    elseif  ($method === 'DELETE' && $sub)                 $c->eliminarTarjeta($sub);
    else Response::error('Ruta tarjeta no encontrada', 404);

} else {
    Response::error('Endpoint no encontrado', 404);
}
