<?php

declare(strict_types=1);

namespace App\Controllers;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Controller de Facturas de Compra.
 * Gestiona facturas de proveedores e incrementa inventario al recibir.
 */
class FacturaController
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * GET /api/facturas
     * Listar facturas con filtros
     */
    public function index(Request $request, Response $response): Response
    {
        $params   = $request->getQueryParams();
        $page     = max(1, (int) ($params['page'] ?? 1));
        $perPage  = min(100, max(1, (int) ($params['per_page'] ?? 20)));
        $offset   = ($page - 1) * $perPage;
        $estado   = $params['estado'] ?? null;
        $prov     = $params['proveedor'] ?? null;
        $fechaIni = $params['fecha_inicio'] ?? null;
        $fechaFin = $params['fecha_fin'] ?? null;

        $where = [];
        $binds = [];

        if ($estado) {
            $where[]         = 'f.estado = :estado';
            $binds['estado'] = $estado;
        }
        if ($prov) {
            $where[]       = 'f.proveedor_nombre LIKE :prov';
            $binds['prov'] = "%{$prov}%";
        }
        if ($fechaIni) {
            $where[]              = 'f.fecha >= :fecha_ini';
            $binds['fecha_ini']   = $fechaIni . ' 00:00:00';
        }
        if ($fechaFin) {
            $where[]              = 'f.fecha <= :fecha_fin';
            $binds['fecha_fin']   = $fechaFin . ' 23:59:59';
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Count
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM facturas_compra f {$whereSQL}");
        $countStmt->execute($binds);
        $total = (int) $countStmt->fetchColumn();

        // Fetch
        $sql = "SELECT f.*, u.nombre as registrado_por 
                FROM facturas_compra f 
                LEFT JOIN usuarios u ON f.usuario_id = u.id 
                {$whereSQL}
                ORDER BY f.fecha DESC 
                LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        foreach ($binds as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $facturas = $stmt->fetchAll();
        foreach ($facturas as &$f) {
            $f['id']         = (int) $f['id'];
            $f['usuario_id'] = (int) $f['usuario_id'];
            $f['subtotal']   = (float) $f['subtotal'];
            $f['iva']        = (float) $f['iva'];
            $f['total']      = (float) $f['total'];
        }

        return $this->jsonResponse($response, [
            'success' => true,
            'data'    => $facturas,
            'meta'    => [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => (int) ceil($total / $perPage),
            ],
        ]);
    }

    /**
     * GET /api/facturas/{id}
     * Detalle de factura con sus líneas
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];

        $stmt = $this->db->prepare(
            "SELECT f.*, u.nombre as registrado_por 
             FROM facturas_compra f 
             LEFT JOIN usuarios u ON f.usuario_id = u.id 
             WHERE f.id = :id"
        );
        $stmt->execute(['id' => $id]);
        $factura = $stmt->fetch();

        if (!$factura) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Factura no encontrada',
            ], 404);
        }

        // Obtener detalles
        $detStmt = $this->db->prepare(
            "SELECT fd.*, p.nombre as producto_nombre, p.sku 
             FROM factura_detalle fd 
             LEFT JOIN productos p ON fd.producto_id = p.id 
             WHERE fd.factura_id = :factura_id"
        );
        $detStmt->execute(['factura_id' => $id]);
        $detalles = $detStmt->fetchAll();

        foreach ($detalles as &$d) {
            $d['id']             = (int) $d['id'];
            $d['factura_id']     = (int) $d['factura_id'];
            $d['producto_id']    = (int) $d['producto_id'];
            $d['cantidad']       = (float) $d['cantidad'];
            $d['costo_unitario'] = (float) $d['costo_unitario'];
            $d['subtotal']       = (float) $d['subtotal'];
        }

        $factura['id']         = (int) $factura['id'];
        $factura['usuario_id'] = (int) $factura['usuario_id'];
        $factura['subtotal']   = (float) $factura['subtotal'];
        $factura['iva']        = (float) $factura['iva'];
        $factura['total']      = (float) $factura['total'];
        $factura['detalles']   = $detalles;

        return $this->jsonResponse($response, [
            'success' => true,
            'data'    => $factura,
        ]);
    }

    /**
     * POST /api/facturas
     * Crear factura de compra — incrementa inventario y actualiza precios de compra.
     *
     * Body esperado:
     * {
     *   "folio_proveedor": "FAC-001",
     *   "proveedor_nombre": "Acuarios del Pacífico",
     *   "proveedor_rfc": "APM123456789",
     *   "fecha": "2024-01-15",
     *   "notas": "",
     *   "items": [
     *     { "producto_id": 1, "cantidad": 50, "costo_unitario": 15.00 }
     *   ]
     * }
     */
    public function create(Request $request, Response $response): Response
    {
        $rol = $request->getAttribute('jwt_user_rol');
        if (!in_array($rol, ['admin', 'almacenista'])) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'No tiene permisos para registrar facturas',
            ], 403);
        }

        $data   = $request->getParsedBody();
        $userId = (int) $request->getAttribute('jwt_user_id');
        $items  = $data['items'] ?? [];

        // Validaciones
        if (empty($data['folio_proveedor']) || empty($data['proveedor_nombre'])) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Folio de proveedor y nombre son requeridos',
            ], 400);
        }

        if (empty($items)) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'La factura debe tener al menos un producto',
            ], 400);
        }

        $this->db->beginTransaction();

        try {
            $subtotal = 0;
            $lineas   = [];

            foreach ($items as $item) {
                $prodStmt = $this->db->prepare(
                    "SELECT id, nombre, stock_actual FROM productos WHERE id = :id AND activo = 1 FOR UPDATE"
                );
                $prodStmt->execute(['id' => (int) $item['producto_id']]);
                $producto = $prodStmt->fetch();

                if (!$producto) {
                    throw new \RuntimeException("Producto ID {$item['producto_id']} no encontrado");
                }

                $cantidad      = (float) $item['cantidad'];
                $costoUnitario = (float) $item['costo_unitario'];
                $lineSubtotal  = $costoUnitario * $cantidad;
                $subtotal     += $lineSubtotal;

                $lineas[] = [
                    'producto_id'    => (int) $producto['id'],
                    'cantidad'       => $cantidad,
                    'costo_unitario' => $costoUnitario,
                    'subtotal'       => $lineSubtotal,
                    'stock_anterior' => (float) $producto['stock_actual'],
                ];
            }

            $iva   = $subtotal * 0.16; // IVA 16%
            $total = $subtotal + $iva;

            // Insertar factura
            $facStmt = $this->db->prepare(
                "INSERT INTO facturas_compra (folio_proveedor, proveedor_nombre, proveedor_rfc, 
                 proveedor_telefono, fecha, subtotal, iva, total, estado, notas, usuario_id)
                 VALUES (:folio_proveedor, :proveedor_nombre, :proveedor_rfc, 
                 :proveedor_telefono, :fecha, :subtotal, :iva, :total, 'recibida', :notas, :usuario_id)"
            );
            $facStmt->execute([
                'folio_proveedor'    => $data['folio_proveedor'],
                'proveedor_nombre'   => $data['proveedor_nombre'],
                'proveedor_rfc'      => $data['proveedor_rfc'] ?? null,
                'proveedor_telefono' => $data['proveedor_telefono'] ?? null,
                'fecha'              => $data['fecha'] ?? date('Y-m-d H:i:s'),
                'subtotal'           => $subtotal,
                'iva'                => $iva,
                'total'              => $total,
                'notas'              => $data['notas'] ?? null,
                'usuario_id'         => $userId,
            ]);

            $facturaId = (int) $this->db->lastInsertId();

            // Insertar detalles, incrementar stock y actualizar precio de compra
            foreach ($lineas as $linea) {
                $this->db->prepare(
                    "INSERT INTO factura_detalle (factura_id, producto_id, cantidad, costo_unitario, subtotal)
                     VALUES (:factura_id, :producto_id, :cantidad, :costo_unitario, :subtotal)"
                )->execute([
                    'factura_id'     => $facturaId,
                    'producto_id'    => $linea['producto_id'],
                    'cantidad'       => $linea['cantidad'],
                    'costo_unitario' => $linea['costo_unitario'],
                    'subtotal'       => $linea['subtotal'],
                ]);

                // Incrementar stock
                $this->db->prepare(
                    "UPDATE productos SET stock_actual = stock_actual + :cantidad, 
                     precio_compra = :precio_compra WHERE id = :id"
                )->execute([
                    'cantidad'      => $linea['cantidad'],
                    'precio_compra' => $linea['costo_unitario'],
                    'id'            => $linea['producto_id'],
                ]);

                // Registro de movimiento
                $this->db->prepare(
                    "INSERT INTO movimientos_inventario (producto_id, tipo, cantidad, stock_anterior, stock_nuevo, 
                     referencia_tipo, referencia_id, usuario_id)
                     VALUES (:producto_id, 'entrada', :cantidad, :stock_anterior, :stock_nuevo, 
                     'factura', :referencia_id, :usuario_id)"
                )->execute([
                    'producto_id'    => $linea['producto_id'],
                    'cantidad'       => $linea['cantidad'],
                    'stock_anterior' => $linea['stock_anterior'],
                    'stock_nuevo'    => $linea['stock_anterior'] + $linea['cantidad'],
                    'referencia_id'  => $facturaId,
                    'usuario_id'     => $userId,
                ]);
            }

            // Registrar gasto financiero
            $this->db->prepare(
                "INSERT INTO ingresos_gastos (tipo, concepto, monto, categoria, referencia_tipo, referencia_id, fecha, usuario_id)
                 VALUES ('gasto', :concepto, :monto, 'compra', 'factura', :referencia_id, CURDATE(), :usuario_id)"
            )->execute([
                'concepto'      => "Compra Factura #{$data['folio_proveedor']} - {$data['proveedor_nombre']}",
                'monto'         => $total,
                'referencia_id' => $facturaId,
                'usuario_id'    => $userId,
            ]);

            $this->db->commit();

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Factura registrada exitosamente. Inventario actualizado.',
                'data'    => [
                    'id'    => $facturaId,
                    'total' => $total,
                ],
            ], 201);

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
                'message' => 'Error al registrar factura: ' . $e->getMessage(),
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
