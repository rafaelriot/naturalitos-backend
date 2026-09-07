<?php

declare(strict_types=1);

namespace App\Controllers;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Controller de Productos.
 * CRUD completo del catálogo del acuario.
 */
class ProductoController
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * GET /api/productos
     * Listar productos con paginación y filtros
     */
    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $page     = max(1, (int) ($params['page'] ?? 1));
        $perPage  = min(100, max(1, (int) ($params['per_page'] ?? $params['limit'] ?? 20)));
        $offset   = ($page - 1) * $perPage;
        $search   = $params['search'] ?? null;
        $catId    = $params['categoria_id'] ?? $params['categoria'] ?? null;
        $stockMin = isset($params['stock_bajo']) ? true : false;

        $where  = ['p.activo = 1'];
        $binds  = [];

        if ($search) {
            $where[]         = '(p.nombre LIKE :search OR p.sku LIKE :search_sku)';
            $binds['search']     = "%{$search}%";
            $binds['search_sku'] = "%{$search}%";
        }

        if ($catId) {
            $where[]            = 'p.categoria_id = :cat_id';
            $binds['cat_id']    = (int) $catId;
        }

        if ($stockMin) {
            $where[] = 'p.stock_actual <= p.stock_minimo';
        }

        $whereSQL = implode(' AND ', $where);

        // Count total
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM productos p WHERE {$whereSQL}");
        $countStmt->execute($binds);
        $total = (int) $countStmt->fetchColumn();

        // Fetch page
        $sql = "SELECT p.*, c.nombre as categoria_nombre 
                FROM productos p 
                LEFT JOIN categorias c ON p.categoria_id = c.id 
                WHERE {$whereSQL}
                ORDER BY p.nombre ASC 
                LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        foreach ($binds as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $productos = $stmt->fetchAll();

        // Cast numeric fields
        foreach ($productos as &$p) {
            $p['id']             = (int) $p['id'];
            $p['categoria_id']   = $p['categoria_id'] ? (int) $p['categoria_id'] : null;
            $p['precio_compra']  = (float) $p['precio_compra'];
            $p['precio_venta']   = (float) $p['precio_venta'];
            $p['stock_actual']   = (float) $p['stock_actual'];
            $p['stock_minimo']   = (float) $p['stock_minimo'];
            $p['es_producto_vivo'] = (bool) $p['es_producto_vivo'];
            $p['activo']         = (bool) $p['activo'];
        }

        return $this->jsonResponse($response, [
            'success' => true,
            'data'    => $productos,
            'meta'    => [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => (int) ceil($total / $perPage),
            ],
        ]);
    }

    /**
     * GET /api/productos/{id}
     * Obtener detalle de un producto
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];

        $stmt = $this->db->prepare(
            "SELECT p.*, c.nombre as categoria_nombre 
             FROM productos p 
             LEFT JOIN categorias c ON p.categoria_id = c.id 
             WHERE p.id = :id AND p.activo = 1"
        );
        $stmt->execute(['id' => $id]);
        $producto = $stmt->fetch();

        if (!$producto) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Producto no encontrado',
            ], 404);
        }

        $producto['id']              = (int) $producto['id'];
        $producto['categoria_id']    = $producto['categoria_id'] ? (int) $producto['categoria_id'] : null;
        $producto['precio_compra']   = (float) $producto['precio_compra'];
        $producto['precio_venta']    = (float) $producto['precio_venta'];
        $producto['stock_actual']    = (float) $producto['stock_actual'];
        $producto['stock_minimo']    = (float) $producto['stock_minimo'];
        $producto['es_producto_vivo'] = (bool) $producto['es_producto_vivo'];

        return $this->jsonResponse($response, [
            'success' => true,
            'data'    => $producto,
        ]);
    }

    /**
     * POST /api/productos
     * Crear nuevo producto (admin/almacenista)
     */
    public function create(Request $request, Response $response): Response
    {
        $rol = $request->getAttribute('jwt_user_rol');
        if (!in_array($rol, ['admin', 'almacenista'])) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'No tiene permisos para crear productos',
            ], 403);
        }

        $data = $request->getParsedBody();

        // Validar requeridos
        foreach (['sku', 'nombre', 'precio_venta'] as $field) {
            if (empty($data[$field])) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => "El campo '{$field}' es requerido",
                ], 400);
            }
        }

        // SKU único
        $check = $this->db->prepare("SELECT id FROM productos WHERE sku = :sku");
        $check->execute(['sku' => $data['sku']]);
        if ($check->fetch()) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'El SKU ya existe',
            ], 409);
        }

        $stmt = $this->db->prepare(
            "INSERT INTO productos (sku, nombre, descripcion, categoria_id, precio_compra, 
             precio_venta, stock_actual, stock_minimo, unidad, imagen_url, codigo_barras, es_producto_vivo)
             VALUES (:sku, :nombre, :descripcion, :categoria_id, :precio_compra, 
             :precio_venta, :stock_actual, :stock_minimo, :unidad, :imagen_url, :codigo_barras, :es_producto_vivo)"
        );

        $stmt->execute([
            'sku'              => $data['sku'],
            'nombre'           => $data['nombre'],
            'descripcion'      => $data['descripcion'] ?? null,
            'categoria_id'     => !empty($data['categoria_id']) ? (int) $data['categoria_id'] : null,
            'precio_compra'    => (float) ($data['precio_compra'] ?? 0),
            'precio_venta'     => (float) $data['precio_venta'],
            'stock_actual'     => (float) ($data['stock_actual'] ?? 0),
            'stock_minimo'     => (float) ($data['stock_minimo'] ?? 0),
            'unidad'           => $data['unidad'] ?? 'pieza',
            'imagen_url'       => $data['imagen_url'] ?? null,
            'codigo_barras'    => $data['codigo_barras'] ?? null,
            'es_producto_vivo' => (int) ($data['es_producto_vivo'] ?? 0),
        ]);

        $productId = (int) $this->db->lastInsertId();

        return $this->jsonResponse($response, [
            'success' => true,
            'message' => 'Producto creado exitosamente',
            'data'    => ['id' => $productId],
        ], 201);
    }

    /**
     * PUT /api/productos/{id}
     * Actualizar producto
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $rol = $request->getAttribute('jwt_user_rol');
        if (!in_array($rol, ['admin', 'almacenista'])) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'No tiene permisos para actualizar productos',
            ], 403);
        }

        $id   = (int) $args['id'];
        $data = $request->getParsedBody();

        // Verificar existencia
        $check = $this->db->prepare("SELECT id FROM productos WHERE id = :id AND activo = 1");
        $check->execute(['id' => $id]);
        if (!$check->fetch()) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Producto no encontrado',
            ], 404);
        }

        $fields = [];
        $binds  = ['id' => $id];

        $updatable = [
            'nombre', 'descripcion', 'categoria_id', 'precio_compra',
            'precio_venta', 'stock_minimo', 'unidad', 'imagen_url',
            'codigo_barras', 'es_producto_vivo',
        ];

        foreach ($updatable as $field) {
            if (array_key_exists($field, $data)) {
                $fields[]      = "{$field} = :{$field}";
                $binds[$field] = $data[$field];
            }
        }

        if (empty($fields)) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'No hay campos para actualizar',
            ], 400);
        }

        $sql = "UPDATE productos SET " . implode(', ', $fields) . " WHERE id = :id";
        $this->db->prepare($sql)->execute($binds);

        return $this->jsonResponse($response, [
            'success' => true,
            'message' => 'Producto actualizado exitosamente',
        ]);
    }

    /**
     * DELETE /api/productos/{id}
     * Soft delete (desactivar producto)
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $rol = $request->getAttribute('jwt_user_rol');
        if ($rol !== 'admin') {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Solo el administrador puede eliminar productos',
            ], 403);
        }

        $id = (int) $args['id'];

        $stmt = $this->db->prepare("UPDATE productos SET activo = 0 WHERE id = :id");
        $stmt->execute(['id' => $id]);

        if ($stmt->rowCount() === 0) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Producto no encontrado',
            ], 404);
        }

        return $this->jsonResponse($response, [
            'success' => true,
            'message' => 'Producto eliminado exitosamente',
        ]);
    }

    /**
     * GET /api/categorias
     * Listar categorías (por defecto solo activas, o todas si all=1)
     */
    public function categorias(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $all = !empty($params['all']);

        $sql = "SELECT id, nombre, descripcion, icono, color, activo 
                FROM categorias";
        if (!$all) {
            $sql .= " WHERE activo = 1";
        }
        $sql .= " ORDER BY nombre ASC";

        $stmt = $this->db->query($sql);
        $categorias = $stmt->fetchAll();

        foreach ($categorias as &$c) {
            $c['id']     = (int) $c['id'];
            $c['activo'] = (bool) $c['activo'];
        }

        return $this->jsonResponse($response, [
            'success' => true,
            'data'    => $categorias,
        ]);
    }

    /**
     * POST /api/categorias
     * Crear nueva categoría
     */
    public function createCategoria(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();

        if (empty($data['nombre'])) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => "El nombre de la categoría es requerido",
            ], 400);
        }

        // Nombre único
        $check = $this->db->prepare("SELECT id FROM categorias WHERE nombre = :nombre");
        $check->execute(['nombre' => trim($data['nombre'])]);
        if ($check->fetch()) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'La categoría ya existe',
            ], 409);
        }

        $stmt = $this->db->prepare(
            "INSERT INTO categorias (nombre, descripcion, icono, color, activo)
             VALUES (:nombre, :descripcion, :icono, :color, 1)"
        );

        $stmt->execute([
            'nombre'      => trim($data['nombre']),
            'descripcion' => $data['descripcion'] ?? null,
            'icono'       => $data['icono'] ?? 'category',
            'color'       => $data['color'] ?? '#1F3F98',
        ]);

        $catId = (int) $this->db->lastInsertId();

        return $this->jsonResponse($response, [
            'success' => true,
            'message' => 'Categoría creada exitosamente',
            'data'    => ['id' => $catId],
        ], 201);
    }

    /**
     * PUT /api/categorias/{id}
     * Actualizar categoría
     */
    public function updateCategoria(Request $request, Response $response, array $args): Response
    {
        $id   = (int) $args['id'];
        $data = $request->getParsedBody();

        $check = $this->db->prepare("SELECT id FROM categorias WHERE id = :id");
        $check->execute(['id' => $id]);
        if (!$check->fetch()) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Categoría no encontrada',
            ], 404);
        }

        $fields = [];
        $binds  = ['id' => $id];

        foreach (['nombre', 'descripcion', 'icono', 'color', 'activo'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[]      = "{$field} = :{$field}";
                $binds[$field] = $field === 'activo' ? (int) $data[$field] : $data[$field];
            }
        }

        if (empty($fields)) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'No hay datos para actualizar',
            ], 400);
        }

        $sql = "UPDATE categorias SET " . implode(', ', $fields) . " WHERE id = :id";
        $this->db->prepare($sql)->execute($binds);

        return $this->jsonResponse($response, [
            'success' => true,
            'message' => 'Categoría actualizada exitosamente',
        ]);
    }

    /**
     * DELETE /api/categorias/{id}
     * Desactivar / Eliminar categoría
     */
    public function deleteCategoria(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];

        $stmt = $this->db->prepare("UPDATE categorias SET activo = 0 WHERE id = :id");
        $stmt->execute(['id' => $id]);

        if ($stmt->rowCount() === 0) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Categoría no encontrada',
            ], 404);
        }

        return $this->jsonResponse($response, [
            'success' => true,
            'message' => 'Categoría desactivada exitosamente',
        ]);
    }

    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
