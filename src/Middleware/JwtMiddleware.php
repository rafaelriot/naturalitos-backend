<?php

declare(strict_types=1);

namespace App\Middleware;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

/**
 * Middleware para validación de JWT.
 * Extrae el token del header Authorization: Bearer <token>,
 * lo valida y agrega user_id y rol al request.
 */
class JwtMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if (empty($authHeader)) {
            return $this->unauthorizedResponse('Token de autorización requerido');
        }

        // Extraer token del header "Bearer <token>"
        if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return $this->unauthorizedResponse('Formato de token inválido. Use: Bearer <token>');
        }

        $token = $matches[1];

        try {
            $secret    = $_ENV['JWT_SECRET'];
            $algorithm = $_ENV['JWT_ALGORITHM'] ?? 'HS256';

            $decoded = JWT::decode($token, new Key($secret, $algorithm));

            // Agregar datos del usuario al request para uso en controllers
            $request = $request
                ->withAttribute('jwt_user_id', $decoded->sub ?? null)
                ->withAttribute('jwt_user_email', $decoded->email ?? null)
                ->withAttribute('jwt_user_rol', $decoded->rol ?? null)
                ->withAttribute('jwt_user_nombre', $decoded->nombre ?? null);

            return $handler->handle($request);

        } catch (ExpiredException $e) {
            return $this->unauthorizedResponse('Token expirado. Inicie sesión nuevamente.');
        } catch (\UnexpectedValueException $e) {
            return $this->unauthorizedResponse('Token inválido: ' . $e->getMessage());
        } catch (\Exception $e) {
            return $this->unauthorizedResponse('Error de autenticación: ' . $e->getMessage());
        }
    }

    /**
     * Genera respuesta 401 Unauthorized en formato JSON
     */
    private function unauthorizedResponse(string $message): Response
    {
        $response = new SlimResponse();
        $response->getBody()->write(json_encode([
            'success' => false,
            'error'   => 'unauthorized',
            'message' => $message,
        ]));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(401);
    }
}
