<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\ProductoController;
use App\Controllers\TicketController;
use App\Controllers\FacturaController;
use App\Controllers\InventarioController;
use App\Controllers\ReporteController;
use App\Middleware\JwtMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Definición de rutas de la API.
 * Rutas públicas: /api/auth/login
 * Rutas protegidas: todo lo demás (requiere JWT)
 */
return function (App $app) {

    // ── Health Check ─────────────────────────────────
    $app->get('/api/health', function (Request $request, Response $response) {
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => '🐠 Naturalitos Acuario API funcionando correctamente',
            'version' => '1.0.0',
            'timestamp' => date('Y-m-d H:i:s'),
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // ── Rutas Públicas (sin JWT) ─────────────────────
    $app->group('/api/auth', function (RouteCollectorProxy $group) {
        $group->post('/login', function (Request $request, Response $response) {
            $db       = $this->get('db');
            $settings = $this->get('settings');
            $controller = new AuthController($db, $settings);
            return $controller->login($request, $response);
        });
    });

    // ── Rutas Protegidas (requieren JWT) ─────────────
    $app->group('/api', function (RouteCollectorProxy $group) {

        // ── Auth (protegidas) ────────────────────────
        $group->post('/auth/register', function (Request $request, Response $response) {
            $db       = $this->get('db');
            $settings = $this->get('settings');
            $controller = new AuthController($db, $settings);
            return $controller->register($request, $response);
        });

        $group->get('/auth/me', function (Request $request, Response $response) {
            $db       = $this->get('db');
            $settings = $this->get('settings');
            $controller = new AuthController($db, $settings);
            return $controller->me($request, $response);
        });

        // ── Productos ────────────────────────────────
        $group->get('/productos', function (Request $request, Response $response) {
            $controller = new ProductoController($this->get('db'));
            return $controller->index($request, $response);
        });

        $group->get('/productos/{id}', function (Request $request, Response $response, array $args) {
            $controller = new ProductoController($this->get('db'));
            return $controller->show($request, $response, $args);
        });

        $group->post('/productos', function (Request $request, Response $response) {
            $controller = new ProductoController($this->get('db'));
            return $controller->create($request, $response);
        });

        $group->put('/productos/{id}', function (Request $request, Response $response, array $args) {
            $controller = new ProductoController($this->get('db'));
            return $controller->update($request, $response, $args);
        });

        $group->delete('/productos/{id}', function (Request $request, Response $response, array $args) {
            $controller = new ProductoController($this->get('db'));
            return $controller->delete($request, $response, $args);
        });

        $group->get('/categorias', function (Request $request, Response $response) {
            $controller = new ProductoController($this->get('db'));
            return $controller->categorias($request, $response);
        });

        $group->post('/categorias', function (Request $request, Response $response) {
            $controller = new ProductoController($this->get('db'));
            return $controller->createCategoria($request, $response);
        });

        $group->put('/categorias/{id}', function (Request $request, Response $response, array $args) {
            $controller = new ProductoController($this->get('db'));
            return $controller->updateCategoria($request, $response, $args);
        });

        $group->delete('/categorias/{id}', function (Request $request, Response $response, array $args) {
            $controller = new ProductoController($this->get('db'));
            return $controller->deleteCategoria($request, $response, $args);
        });

        // ── Tickets de Venta ─────────────────────────
        $group->get('/tickets', function (Request $request, Response $response) {
            $controller = new TicketController($this->get('db'));
            return $controller->index($request, $response);
        });

        $group->get('/tickets/{id}', function (Request $request, Response $response, array $args) {
            $controller = new TicketController($this->get('db'));
            return $controller->show($request, $response, $args);
        });

        $group->post('/tickets', function (Request $request, Response $response) {
            $controller = new TicketController($this->get('db'));
            return $controller->create($request, $response);
        });

        $group->put('/tickets/{id}/cancel', function (Request $request, Response $response, array $args) {
            $controller = new TicketController($this->get('db'));
            return $controller->cancel($request, $response, $args);
        });

        $group->delete('/tickets/{id}', function (Request $request, Response $response, array $args) {
            $controller = new TicketController($this->get('db'));
            return $controller->delete($request, $response, $args);
        });

        // ── Facturas de Compra ───────────────────────
        $group->get('/facturas', function (Request $request, Response $response) {
            $controller = new FacturaController($this->get('db'));
            return $controller->index($request, $response);
        });

        $group->get('/facturas/{id}', function (Request $request, Response $response, array $args) {
            $controller = new FacturaController($this->get('db'));
            return $controller->show($request, $response, $args);
        });

        $group->post('/facturas', function (Request $request, Response $response) {
            $controller = new FacturaController($this->get('db'));
            return $controller->create($request, $response);
        });

        // ── Inventario ───────────────────────────────
        $group->get('/inventario', function (Request $request, Response $response) {
            $controller = new InventarioController($this->get('db'));
            return $controller->index($request, $response);
        });

        $group->get('/inventario/movimientos', function (Request $request, Response $response) {
            $controller = new InventarioController($this->get('db'));
            return $controller->movimientos($request, $response);
        });

        $group->post('/inventario/ajuste', function (Request $request, Response $response) {
            $controller = new InventarioController($this->get('db'));
            return $controller->ajuste($request, $response);
        });

        // ── Reportes ─────────────────────────────────
        $group->get('/reportes/dashboard', function (Request $request, Response $response) {
            $controller = new ReporteController($this->get('db'));
            return $controller->dashboard($request, $response);
        });

        $group->get('/reportes/ventas', function (Request $request, Response $response) {
            $controller = new ReporteController($this->get('db'));
            return $controller->ventas($request, $response);
        });

        $group->get('/reportes/ganancias', function (Request $request, Response $response) {
            $controller = new ReporteController($this->get('db'));
            return $controller->ganancias($request, $response);
        });

        $group->get('/reportes/productos-top', function (Request $request, Response $response) {
            $controller = new ReporteController($this->get('db'));
            return $controller->productosTop($request, $response);
        });

        $group->get('/reportes/inventario-bajo', function (Request $request, Response $response) {
            $controller = new ReporteController($this->get('db'));
            return $controller->inventarioBajo($request, $response);
        });

    })->add(new JwtMiddleware());
};
