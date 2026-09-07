<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

// ── Cargar variables de entorno ──────────────────────
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// ── Container DI ─────────────────────────────────────
$containerBuilder = new ContainerBuilder();

$containerBuilder->addDefinitions([
    'db' => function () {
        return \App\Config\Database::getInstance()->getConnection();
    },
    'settings' => function () {
        return [
            'jwt_secret'     => $_ENV['JWT_SECRET'],
            'jwt_algorithm'  => $_ENV['JWT_ALGORITHM'] ?? 'HS256',
            'jwt_expiration' => (int) ($_ENV['JWT_EXPIRATION'] ?? 86400),
            'app_env'        => $_ENV['APP_ENV'] ?? 'production',
            'app_debug'      => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    },
]);

$container = $containerBuilder->build();

// ── Crear App Slim ───────────────────────────────────
AppFactory::setContainer($container);
$app = AppFactory::create();

// ── Base Path (para subdirectorio en XAMPP) ──────────
$app->setBasePath('/naturalitosAndroid/backend/public');

// ── Middleware Global ────────────────────────────────

// CORS Middleware
$app->add(new \App\Middleware\CorsMiddleware());

// Body Parsing Middleware (JSON)
$app->addBodyParsingMiddleware();

// Routing Middleware
$app->addRoutingMiddleware();

// Error Middleware (debe ir al final)
$errorMiddleware = $app->addErrorMiddleware(
    displayErrorDetails: filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
    logErrors: true,
    logErrorDetails: true
);

// ── Registrar Rutas ──────────────────────────────────
(require __DIR__ . '/../src/Routes/api.php')($app);

// ── Ejecutar ─────────────────────────────────────────
$app->run();
