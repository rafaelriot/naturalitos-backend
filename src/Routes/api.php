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

    // ── Página de Descarga Web ───────────────────────
    $app->get('/download', function (Request $request, Response $response) {
        $htmlPath = __DIR__ . '/../../index.html';
        if (file_exists($htmlPath)) {
            $response->getBody()->write(file_get_contents($htmlPath));
            return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
        }
        $response->getBody()->write("Página de descarga no disponible");
        return $response->withStatus(404);
    });

    $app->get('/', function (Request $request, Response $response) {
        $htmlPath = __DIR__ . '/../../index.html';
        if (file_exists($htmlPath)) {
            $response->getBody()->write(file_get_contents($htmlPath));
            return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
        }
        return $response->withHeader('Location', '/download')->withStatus(302);
    });

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

    $app->get('/api/health/db', function (Request $request, Response $response) {
        $hosts = ['localhost', '127.0.0.1'];
        $results = [];
        $dbName = $_ENV['DB_NAME'] ?? ($_ENV['DB_DATABASE'] ?? 'u980038333_natu_app');
        $dbUser = $_ENV['DB_USER'] ?? ($_ENV['DB_USERNAME'] ?? 'u980038333_natu_app_root');
        $dbPass = $_ENV['DB_PASS'] ?? ($_ENV['DB_PASSWORD'] ?? '');

        foreach ($hosts as $h) {
            try {
                $dsn = "mysql:host=$h;port=3306;dbname=$dbName;charset=utf8mb4";
                $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $cnt = $pdo->query("SELECT COUNT(*) FROM usuarios")->fetchColumn();
                $results[$h] = ['status' => 'CONNECTED', 'usuarios' => (int)$cnt];
            } catch (Throwable $e) {
                $results[$h] = ['status' => 'ERROR', 'message' => $e->getMessage()];
            }
        }

        $response->getBody()->write(json_encode([
            'db_name' => $dbName,
            'db_user' => $dbUser,
            'pass_length' => strlen($dbPass),
            'results' => $results
        ], JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // ── DEBUG TEMPORAL - ELIMINAR DESPUÉS DEL DIAGNÓSTICO ──────────
    // Endpoint 1: Verificar que tablas y datos existen en la DB
    $app->get('/api/debug/catalogo', function (Request $request, Response $response) {
        $results = ['timestamp' => date('Y-m-d H:i:s'), 'checks' => []];

        try {
            $db = \App\Config\Database::getInstance()->getConnection();
            $results['db_connection'] = 'OK';

            // Verificar que las tablas existen
            $tables = ['usuarios', 'categorias', 'productos', 'tickets_venta', 'ticket_detalle',
                        'facturas_compra', 'factura_detalle', 'movimientos_inventario', 'ingresos_gastos'];
            foreach ($tables as $table) {
                try {
                    $cnt = $db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
                    $results['checks'][$table] = ['exists' => true, 'count' => (int)$cnt];
                } catch (\Throwable $e) {
                    $results['checks'][$table] = ['exists' => false, 'error' => $e->getMessage()];
                }
            }

            // Muestra de categorías
            try {
                $cats = $db->query("SELECT id, nombre, activo FROM categorias ORDER BY id")->fetchAll();
                $results['categorias_sample'] = $cats;
            } catch (\Throwable $e) {
                $results['categorias_sample'] = ['error' => $e->getMessage()];
            }

            // Muestra de productos (primeros 5)
            try {
                $prods = $db->query("SELECT id, sku, nombre, categoria_id, precio_venta, stock_actual, activo FROM productos ORDER BY id LIMIT 5")->fetchAll();
                $results['productos_sample'] = $prods;
            } catch (\Throwable $e) {
                $results['productos_sample'] = ['error' => $e->getMessage()];
            }

        } catch (\Throwable $e) {
            $results['db_connection'] = 'ERROR: ' . $e->getMessage();
        }

        $response->getBody()->write(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Endpoint 2: Verificar URLs y base path
    $app->get('/api/debug/urls', function (Request $request, Response $response) {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        $basePath = $_ENV['APP_BASE_PATH'] ?? null;
        $calculatedBase = ($scriptDir !== '/' && $scriptDir !== '') ? $scriptDir : '';

        $results = [
            'server' => [
                'SCRIPT_NAME' => $_SERVER['SCRIPT_NAME'] ?? 'NOT SET',
                'SCRIPT_FILENAME' => $_SERVER['SCRIPT_FILENAME'] ?? 'NOT SET',
                'DOCUMENT_ROOT' => $_SERVER['DOCUMENT_ROOT'] ?? 'NOT SET',
                'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? 'NOT SET',
                'SERVER_NAME' => $_SERVER['SERVER_NAME'] ?? 'NOT SET',
                'HTTPS' => $_SERVER['HTTPS'] ?? 'NOT SET',
            ],
            'slim_basepath' => [
                'APP_BASE_PATH_env' => $basePath ?? 'NOT SET (using auto-detect)',
                'calculated_base' => $calculatedBase,
                'effective_base' => $basePath ?? $calculatedBase,
            ],
            'expected_urls' => [
                'login' => ($basePath ?? $calculatedBase) . '/api/auth/login',
                'productos' => ($basePath ?? $calculatedBase) . '/api/productos',
                'categorias' => ($basePath ?? $calculatedBase) . '/api/categorias',
            ],
            'android_base_url' => 'https://gold-gorilla-627982.hostingersite.com/public/api/',
            'android_would_call' => [
                'login' => 'https://gold-gorilla-627982.hostingersite.com/public/api/auth/login',
                'productos' => 'https://gold-gorilla-627982.hostingersite.com/public/api/productos',
                'categorias' => 'https://gold-gorilla-627982.hostingersite.com/public/api/categorias',
            ],
        ];

        $response->getBody()->write(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Endpoint 3: Test completo de flujo auth → catálogo
    $app->get('/api/debug/auth-test', function (Request $request, Response $response) {
        $results = ['timestamp' => date('Y-m-d H:i:s'), 'steps' => []];

        try {
            $db = \App\Config\Database::getInstance()->getConnection();

            // Step 1: Verificar usuario admin existe
            $stmt = $db->prepare("SELECT id, nombre, email, rol, password_hash FROM usuarios WHERE email = :email");
            $stmt->execute(['email' => 'admin@naturalitos.com']);
            $user = $stmt->fetch();

            if ($user) {
                $results['steps']['1_find_admin'] = [
                    'status' => 'OK',
                    'user_id' => $user['id'],
                    'nombre' => $user['nombre'],
                    'rol' => $user['rol'],
                    'hash_length' => strlen($user['password_hash']),
                ];

                // Step 2: Verificar password
                $passOk = password_verify('admin123', $user['password_hash']);
                $results['steps']['2_verify_password'] = [
                    'status' => $passOk ? 'OK' : 'FAIL',
                    'message' => $passOk ? 'Password admin123 es correcto' : 'Password admin123 NO coincide con el hash',
                ];

                // Step 3: Generar JWT
                if ($passOk) {
                    try {
                        $secret = $_ENV['JWT_SECRET'] ?? 'NOT_SET';
                        $algorithm = $_ENV['JWT_ALGORITHM'] ?? 'HS256';

                        $payload = [
                            'sub' => $user['id'],
                            'email' => $user['email'],
                            'nombre' => $user['nombre'],
                            'rol' => $user['rol'],
                            'iat' => time(),
                            'exp' => time() + 86400,
                        ];

                        $token = \Firebase\JWT\JWT::encode($payload, $secret, $algorithm);
                        $results['steps']['3_generate_jwt'] = [
                            'status' => 'OK',
                            'token_length' => strlen($token),
                            'token_preview' => substr($token, 0, 50) . '...',
                            'jwt_secret_length' => strlen($secret),
                        ];

                        // Step 4: Decodificar JWT para verificar
                        try {
                            $decoded = \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key($secret, $algorithm));
                            $results['steps']['4_decode_jwt'] = [
                                'status' => 'OK',
                                'sub' => $decoded->sub,
                                'email' => $decoded->email,
                                'rol' => $decoded->rol,
                            ];
                        } catch (\Throwable $e) {
                            $results['steps']['4_decode_jwt'] = ['status' => 'ERROR', 'message' => $e->getMessage()];
                        }

                    } catch (\Throwable $e) {
                        $results['steps']['3_generate_jwt'] = ['status' => 'ERROR', 'message' => $e->getMessage()];
                    }
                }
            } else {
                $results['steps']['1_find_admin'] = [
                    'status' => 'FAIL',
                    'message' => 'No se encontró usuario admin@naturalitos.com - ¿Se ejecutó el SQL seed?'
                ];

                // Listar usuarios que sí existen
                $allUsers = $db->query("SELECT id, email, rol FROM usuarios")->fetchAll();
                $results['steps']['1b_existing_users'] = $allUsers;
            }

            // Step 5: Query directa de productos (sin JWT)
            try {
                $prodCount = $db->query("SELECT COUNT(*) FROM productos WHERE activo = 1")->fetchColumn();
                $catCount = $db->query("SELECT COUNT(*) FROM categorias WHERE activo = 1")->fetchColumn();
                $results['steps']['5_direct_query'] = [
                    'status' => 'OK',
                    'productos_activos' => (int)$prodCount,
                    'categorias_activas' => (int)$catCount,
                ];
            } catch (\Throwable $e) {
                $results['steps']['5_direct_query'] = ['status' => 'ERROR', 'message' => $e->getMessage()];
            }

        } catch (\Throwable $e) {
            $results['db_error'] = $e->getMessage();
        }

        $response->getBody()->write(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    });
    // ── FIN DEBUG TEMPORAL ─────────────────────────────────────

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
