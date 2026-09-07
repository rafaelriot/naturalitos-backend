<?php

declare(strict_types=1);

namespace App\Config;

use PDO;
use PDOException;

/**
 * Singleton de conexión a MySQL con PDO.
 * Usa variables de entorno para la configuración.
 */
class Database
{
    private static ?Database $instance = null;
    private PDO $connection;

    private function __construct()
    {
        $host = $_ENV['DB_HOST'] ?? 'localhost';
        $port = $_ENV['DB_PORT'] ?? '3306';
        $name = $_ENV['DB_NAME'] ?? ($_ENV['DB_DATABASE'] ?? 'naturalitos_movil_db');
        $user = $_ENV['DB_USER'] ?? ($_ENV['DB_USERNAME'] ?? 'root');
        $pass = $_ENV['DB_PASS'] ?? ($_ENV['DB_PASSWORD'] ?? '');

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

        try {
            $this->connection = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            ]);
        } catch (PDOException $e) {
            throw new PDOException(
                "Error de conexión a la base de datos: " . $e->getMessage(),
                (int) $e->getCode()
            );
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection(): PDO
    {
        return $this->connection;
    }

    // Evitar clonación y deserialización
    private function __clone() {}
    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }
}
