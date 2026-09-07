<?php

declare(strict_types=1);

namespace App\Controllers;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Controller de Inventario.
 * Stock actual, movimientos, ajustes manuales.
 */
class InventarioController
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * GET /api/inventario
     * Stock actual de todos los productos con alertas
     */
    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $search = $params['search'] ?? null;
        $alerta = isset($params['alerta']); // solo stock bajo

        $where = ['p.activo = 1'];
        $binds = [];

        if ($search) {
            $where[]         = '(p.nombre LIKE :search OR p.sku LIKE :search2)';
            $binds['search']  = "%{$search}%";
            $binds['search2'] = "%{$search}%";
        }
        if ($alerta) {
            $where[] = 'p.stock_actual <= p.stock_minimo';
        }

        $whereSQL = implode(' AND ', $where);

        $stmt = $this->db->prepare(
            "SELECT p.id, p.sku, p.nombre, p.stock_actual, p.stock_minimo, p.unidad,
                    p.precio_compra, p.precio_venta, p.es_producto_vivo,
                    c.nombre as categoria_nombre,
                    CASE 
                        WHEN p.stock_actual <= 0 THEN 'agotado'
                        WHEN p.stock_actual <= p.stock_minimo THEN 'bajo'
                        ELSE 'normal'
                    END as estado_stock
             FROM productos p
             LEFT JOIN categorias c ON p.categoria_id = c.id
             WHERE {$whereSQL}
             ORDER BY 
                CASE 
                    WHEN p.stock_actual <= 0 THEN 0
                    WHEN p.stock_actual <= p.stock_minimo THEN 1
                    ELSE 2
                END,
                p.nombre ASC"
        );
        $stmt->execute($binds);
        $productos = $stmt->fetchAll();

        foreach ($productos as &$p) {
            $p['id']               = (int) $p['id'];
            $p['stock_actual']     = (float) $p['stock_actual'];
            $p['stock_minimo']     = (float) $p['stock_minimo'];
            $p['precio_compra']    = (float) $p['precio_compra'];
            $p['precio_venta']     = (float) $p['precio_venta'];
            $p['es_producto_vivo'] = (bool) $p['es_producto_vivo'];
        }

        // Estadísticas resumen
        $statsStmt = $this->db->query(
            "SELECT 
                COUNT(*) as total_productos,
                SUM(CASE WHEN stock_actual <= 0 THEN 1 ELSE 0 END) as agotados,
                SUM(CASE WHEN stock_actual > 0 AND stock_actual <= stock_minimo THEN 1 ELSE 0 END) as stock_bajo,
                SUM(stock_actual * precio_compra) as valor_inventario
             FROM productos WHERE activo = 1"
        );
        $stats = $statsStmt->fetch();

        return $this->jsonResponse($response, [
            'success' => true,
            'data'    => $productos,
            'stats'   => [
                'total_productos'  => (int) $stats['total_productos'],
                'agotados'         => (int) $stats['agotados'],
                'stock_bajo'       => (int) $stats['stock_bajo'],
                'valor_inventario' => (float) $stats['valor_inventario'],
            ],
        ]);
    }

    /**
     * GET /api/inventario/movimientos
     * Historial de movimientos de inventario
     */
    public function movimientos(Request $request, Response $response): Response
    {
        $params   = $request->getQueryParams();
        $page     = max(1, (int) ($params['page'] ?? 1));
        $perPage  = min(100, max(1, (int) ($params['per_page'] ?? 30)));
        $offset   = ($page - 1) * $perPage;
        $prodId   = $params['producto_id'] ?? null;
        $tipo     = $params['tipo'] ?? null;

        $where = [];
        $binds = [];

        if ($prodId) {
            $where[]             = 'm.producto_id = :prod_id';
            $binds['prod_id']    = (int) $prodId;
        }
        if ($tipo) {
            $where[]         = 'm.tipo = :tipo';
            $binds['tipo']   = $tipo;
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM movimientos_inventario m {$whereSQL}");
        $countStmt->execute($binds);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT m.*, p.nombre as producto_nombre, p.sku, u.nombre as usuario_nombre
                FROM movimientos_inventario m
                LEFT JOIN productos p ON m.producto_id = p.id
                LEFT JOIN usuarios u ON m.usuario_id = u.id
                {$whereSQL}
                ORDER BY m.fecha DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        foreach ($binds as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $movimientos = $stmt->fetchAll();
        foreach ($movimientos as &$m) {
            $m['id']              = (int) $m['id'];
            $m['producto_id']     = (int) $m['producto_id'];
            $m['cantidad']        = (float) $m['cantidad'];
            $m['stock_anterior']  = (float) $m['stock_anterior'];
            $m['stock_nuevo']     = (float) $m['stock_nuevo'];
            $m['usuario_id']      = (int) $m['usuario_id'];
        }

        return $this->jsonResponse($response, [
            'success' => true,
            'data'    => $movimientos,
            'meta'    => [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => (int) ceil($total / $perPage),
            ],
        ]);
    }

    /**
     * POST /api/inventario/ajuste
     * Ajuste manual de inventario
     *
     * Body:
     * {
     *   "producto_id": 1,
     *   "cantidad": 5,
     *   "tipo": "entrada|salida",
     *   "motivo": "Conteo físico"
     * }
     */
    public function ajuste(Request $request, Response $response): Response
    {
        $rol = $request->getAttribute('jwt_user_rol');
        if (!in_array($rol, ['admin', 'almacenista'])) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'No tiene permisos para ajustar inventario',
            ], 403);
        }

        $data   = $request->getParsedBody();
        $userId = (int) $request->getAttribute('jwt_user_id');

        if (empty($data['producto_id']) || empty($data['cantidad']) || empty($data['tipo'])) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'producto_id, cantidad y tipo son requeridos',
            ], 400);
        }

        $this->db->beginTransaction();

        try {
            $prodStmt = $this->db->prepare(
                "SELECT id, nombre, stock_actual FROM productos WHERE id = :id AND activo = 1 FOR UPDATE"
            );
            $prodStmt->execute(['id' => (int) $data['producto_id']]);
            $producto = $prodStmt->fetch();

            if (!$producto) {
                throw new \RuntimeException('Producto no encontrado');
            }

            $cantidad       = abs((float) $data['cantidad']);
            $tipo           = $data['tipo']; // entrada o salida
            $stockAnterior  = (float) $producto['stock_actual'];

            if ($tipo === 'salida' && $stockAnterior < $cantidad) {
                throw new \RuntimeException(
                    "Stock insuficiente. Actual: {$stockAnterior}, Ajuste: -{$cantidad}"
                );
            }

            $stockNuevo = $tipo === 'entrada'
                ? $stockAnterior + $cantidad
                : $stockAnterior - $cantidad;

            // Actualizar stock
            $this->db->prepare(
                "UPDATE productos SET stock_actual = :stock WHERE id = :id"
            )->execute([
                'stock' => $stockNuevo,
                'id'    => $producto['id'],
            ]);

            // Registrar movimiento
            $this->db->prepare(
                "INSERT INTO movimientos_inventario (producto_id, tipo, cantidad, stock_anterior, stock_nuevo, 
                 referencia_tipo, motivo, usuario_id)
                 VALUES (:producto_id, 'ajuste', :cantidad, :stock_anterior, :stock_nuevo, 
                 'ajuste_manual', :motivo, :usuario_id)"
            )->execute([
                'producto_id'    => $producto['id'],
                'cantidad'       => $cantidad,
                'stock_anterior' => $stockAnterior,
                'stock_nuevo'    => $stockNuevo,
                'motivo'         => $data['motivo'] ?? 'Ajuste manual de inventario',
                'usuario_id'     => $userId,
            ]);

            $this->db->commit();

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => "Inventario ajustado. '{$producto['nombre']}': {$stockAnterior} → {$stockNuevo}",
                'data'    => [
                    'producto_id'    => (int) $producto['id'],
                    'stock_anterior' => $stockAnterior,
                    'stock_nuevo'    => $stockNuevo,
                ],
            ]);

        } catch (\RuntimeException $e) {
            $this->db->rollBack();
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            $this->db->rollBack();
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Error al ajustar inventario: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
