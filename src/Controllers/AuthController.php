<?php

declare(strict_types=1);

namespace App\Controllers;

use Firebase\JWT\JWT;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Controller de Autenticación.
 * Maneja login, registro y perfil de usuario.
 */
class AuthController
{
    private PDO $db;
    private array $settings;

    public function __construct(PDO $db, array $settings)
    {
        $this->db       = $db;
        $this->settings = $settings;
    }

    /**
     * POST /api/auth/login
     * Autenticar usuario y retornar JWT
     */
    public function login(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();

        if (empty($data['email']) || empty($data['password'])) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Email y contraseña son requeridos',
            ], 400);
        }

        $stmt = $this->db->prepare(
            "SELECT id, nombre, email, password_hash, rol, activo 
             FROM usuarios 
             WHERE email = :email 
             LIMIT 1"
        );
        $stmt->execute(['email' => $data['email']]);
        $user = $stmt->fetch();

        if (!$user) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Credenciales inválidas',
            ], 401);
        }

        if (!$user['activo']) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Usuario desactivado. Contacte al administrador.',
            ], 403);
        }

        if (!password_verify($data['password'], $user['password_hash'])) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Credenciales inválidas',
            ], 401);
        }

        // Actualizar último acceso
        $this->db->prepare(
            "UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = :id"
        )->execute(['id' => $user['id']]);

        // Generar JWT
        $token = $this->generateToken($user);

        return $this->jsonResponse($response, [
            'success' => true,
            'message' => 'Login exitoso',
            'data'    => [
                'token' => $token,
                'user'  => [
                    'id'     => (int) $user['id'],
                    'nombre' => $user['nombre'],
                    'email'  => $user['email'],
                    'rol'    => $user['rol'],
                ],
            ],
        ]);
    }

    /**
     * POST /api/auth/register
     * Registrar nuevo usuario (solo admin)
     */
    public function register(Request $request, Response $response): Response
    {
        // Verificar que el solicitante sea admin
        $rolActual = $request->getAttribute('jwt_user_rol');
        if ($rolActual !== 'admin') {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Solo el administrador puede crear usuarios',
            ], 403);
        }

        $data = $request->getParsedBody();

        // Validar campos requeridos
        $required = ['nombre', 'email', 'password', 'rol'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => "El campo '{$field}' es requerido",
                ], 400);
            }
        }

        // Validar rol
        $rolesValidos = ['admin', 'vendedor', 'almacenista'];
        if (!in_array($data['rol'], $rolesValidos)) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Rol inválido. Use: ' . implode(', ', $rolesValidos),
            ], 400);
        }

        // Verificar email único
        $stmt = $this->db->prepare("SELECT id FROM usuarios WHERE email = :email");
        $stmt->execute(['email' => $data['email']]);
        if ($stmt->fetch()) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'El email ya está registrado',
            ], 409);
        }

        // Crear usuario
        $stmt = $this->db->prepare(
            "INSERT INTO usuarios (nombre, email, password_hash, rol, telefono) 
             VALUES (:nombre, :email, :password_hash, :rol, :telefono)"
        );
        $stmt->execute([
            'nombre'        => $data['nombre'],
            'email'         => $data['email'],
            'password_hash' => password_hash($data['password'], PASSWORD_BCRYPT),
            'rol'           => $data['rol'],
            'telefono'      => $data['telefono'] ?? null,
        ]);

        $userId = (int) $this->db->lastInsertId();

        return $this->jsonResponse($response, [
            'success' => true,
            'message' => 'Usuario creado exitosamente',
            'data'    => [
                'id'     => $userId,
                'nombre' => $data['nombre'],
                'email'  => $data['email'],
                'rol'    => $data['rol'],
            ],
        ], 201);
    }

    /**
     * GET /api/auth/me
     * Obtener perfil del usuario autenticado
     */
    public function me(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('jwt_user_id');

        $stmt = $this->db->prepare(
            "SELECT id, nombre, email, rol, telefono, avatar_url, ultimo_acceso, created_at 
             FROM usuarios 
             WHERE id = :id"
        );
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Usuario no encontrado',
            ], 404);
        }

        $user['id'] = (int) $user['id'];

        return $this->jsonResponse($response, [
            'success' => true,
            'data'    => $user,
        ]);
    }

    /**
     * Generar token JWT con datos del usuario
     */
    private function generateToken(array $user): string
    {
        $now = time();
        $payload = [
            'iss'    => 'naturalitos-acuario',
            'sub'    => (int) $user['id'],
            'email'  => $user['email'],
            'nombre' => $user['nombre'],
            'rol'    => $user['rol'],
            'iat'    => $now,
            'exp'    => $now + $this->settings['jwt_expiration'],
        ];

        return JWT::encode(
            $payload,
            $this->settings['jwt_secret'],
            $this->settings['jwt_algorithm']
        );
    }

    /**
     * Helper: respuesta JSON estandarizada
     */
    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
