<?php

declare(strict_types=1);

namespace App\Controllers;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Controller de Tickets de Venta.
 * Gestiona la creación, listado y cancelación de ventas.
 * Al crear un ticket, descuenta automáticamente el inventario.
 */
class TicketController
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * GET /api/tickets
     * Listar tickets con filtros
     */
    public function index(Request $request, Response $response): Response
    {
        $params   = $request->getQueryParams();
        $page     = max(1, (int) ($params['page'] ?? 1));
        $perPage  = min(500, max(1, (int) ($params['per_page'] ?? ($params['limit'] ?? 50))));
        $offset   = ($page - 1) * $perPage;
        $estado   = $params['estado'] ?? null;
        $fechaIni = $params['fecha_inicio'] ?? null;
        $fechaFin = $params['fecha_fin'] ?? null;

        $where = [];
        $binds = [];

        if ($estado) {
            $where[]          = 't.estado = :estado';
            $binds['estado']  = $estado;
        }
        if ($fechaIni) {
            $where[]              = 't.fecha >= :fecha_ini';
            $binds['fecha_ini']   = $fechaIni . ' 00:00:00';
        }
        if ($fechaFin) {
            $where[]              = 't.fecha <= :fecha_fin';
            $binds['fecha_fin']   = $fechaFin . ' 23:59:59';
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Count
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM tickets_venta t {$whereSQL}");
        $countStmt->execute($binds);
        $total = (int) $countStmt->fetchColumn();

        // Fetch
        $sql = "SELECT t.*, u.nombre as vendedor_nombre 
                FROM tickets_venta t 
                LEFT JOIN usuarios u ON t.usuario_id = u.id 
                {$whereSQL}
                ORDER BY t.fecha DESC 
                LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        foreach ($binds as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $tickets = $stmt->fetchAll();
        $ticketIds = !empty($tickets) ? array_column($tickets, 'id') : [];
        $detallesByTicket = [];
        if (!empty($ticketIds)) {
            $placeholders = implode(',', array_fill(0, count($ticketIds), '?'));
            $detStmt = $this->db->prepare("
                SELECT d.*, p.nombre as producto_nombre, p.sku 
                FROM ticket_detalle d 
                LEFT JOIN productos p ON d.producto_id = p.id 
                WHERE d.ticket_id IN ($placeholders)
            ");
            $detStmt->execute($ticketIds);
            $allDetalles = $detStmt->fetchAll();
            foreach ($allDetalles as $d) {
                $d['id']              = (int) $d['id'];
                $d['ticket_id']       = (int) $d['ticket_id'];
                $d['producto_id']     = (int) $d['producto_id'];
                $d['cantidad']        = (float) $d['cantidad'];
                $d['precio_unitario'] = (float) $d['precio_unitario'];
                $d['descuento']       = (float) $d['descuento'];
                $d['subtotal']        = (float) $d['subtotal'];
                $detallesByTicket[$d['ticket_id']][] = $d;
            }
        }

        foreach ($tickets as &$t) {
            $t['id']              = (int) $t['id'];
            $t['usuario_id']      = (int) $t['usuario_id'];
            $t['subtotal']        = (float) $t['subtotal'];
            $t['descuento_total'] = (float) $t['descuento_total'];
            $t['impuesto']        = (float) $t['impuesto'];
            $t['total']           = (float) $t['total'];
            $t['detalles']        = $detallesByTicket[$t['id']] ?? [];
        }

        return $this->jsonResponse($response, [
            'success' => true,
            'data'    => $tickets,
            'meta'    => [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => (int) ceil($total / $perPage),
            ],
        ]);
    }

    /**
     * GET /api/tickets/{id}
     * Detalle de ticket con sus líneas
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];

        $stmt = $this->db->prepare(
            "SELECT t.*, u.nombre as vendedor_nombre 
             FROM tickets_venta t 
             LEFT JOIN usuarios u ON t.usuario_id = u.id 
             WHERE t.id = :id"
        );
        $stmt->execute(['id' => $id]);
        $ticket = $stmt->fetch();

        if (!$ticket) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Ticket no encontrado',
            ], 404);
        }

        // Obtener líneas del ticket
        $detStmt = $this->db->prepare(
            "SELECT td.*, p.nombre as producto_nombre, p.sku 
             FROM ticket_detalle td 
             LEFT JOIN productos p ON td.producto_id = p.id 
             WHERE td.ticket_id = :ticket_id"
        );
        $detStmt->execute(['ticket_id' => $id]);
        $detalles = $detStmt->fetchAll();

        foreach ($detalles as &$d) {
            $d['id']              = (int) $d['id'];
            $d['ticket_id']       = (int) $d['ticket_id'];
            $d['producto_id']     = (int) $d['producto_id'];
            $d['cantidad']        = (float) $d['cantidad'];
            $d['precio_unitario'] = (float) $d['precio_unitario'];
            $d['descuento']       = (float) $d['descuento'];
            $d['subtotal']        = (float) $d['subtotal'];
        }

        $ticket['id']              = (int) $ticket['id'];
        $ticket['usuario_id']      = (int) $ticket['usuario_id'];
        $ticket['subtotal']        = (float) $ticket['subtotal'];
        $ticket['descuento_total'] = (float) $ticket['descuento_total'];
        $ticket['impuesto']        = (float) $ticket['impuesto'];
        $ticket['total']           = (float) $ticket['total'];
        $ticket['detalles']        = $detalles;

        return $this->jsonResponse($response, [
            'success' => true,
            'data'    => $ticket,
        ]);
    }

    /**
     * POST /api/tickets
     * Crear ticket de venta — descuenta inventario automáticamente.
     * 
     * Body esperado:
     * {
     *   "cliente_nombre": "Juan Pérez",
     *   "metodo_pago": "efectivo",
     *   "notas": "",
     *   "items": [
     *     { "producto_id": 1, "cantidad": 2, "descuento": 0 },
     *     { "producto_id": 5, "cantidad": 1, "descuento": 10.00 }
     *   ]
     * }
     */
    public function create(Request $request, Response $response): Response
    {
        $data      = $request->getParsedBody();
        $userId    = (int) $request->getAttribute('jwt_user_id');
        $items     = $data['items'] ?? [];

        if (empty($items)) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'El ticket debe tener al menos un producto',
            ], 400);
        }

        $this->db->beginTransaction();

        try {
            // Generar folio único
            $folio = $this->generateFolio();

            // Calcular totales y validar stock
            $subtotal       = 0;
            $descuentoTotal = 0;
            $lineas         = [];

            foreach ($items as $item) {
                $prodStmt = $this->db->prepare(
                    "SELECT id, nombre, precio_venta, stock_actual 
                     FROM productos 
                     WHERE id = :id AND activo = 1 
                     FOR UPDATE"
                );
                $prodStmt->execute(['id' => (int) $item['producto_id']]);
                $producto = $prodStmt->fetch();

                if (!$producto) {
                    throw new \RuntimeException("Producto ID {$item['producto_id']} no encontrado");
                }

                $cantidad  = (float) $item['cantidad'];
                $descuento = (float) ($item['descuento'] ?? 0);

                if ($cantidad <= 0) {
                    throw new \RuntimeException("Cantidad inválida para '{$producto['nombre']}'");
                }

                if ($producto['stock_actual'] < $cantidad) {
                    throw new \RuntimeException(
                        "Stock insuficiente para '{$producto['nombre']}'. " .
                        "Disponible: {$producto['stock_actual']}, Solicitado: {$cantidad}"
                    );
                }

                $precioUnit   = (float) $producto['precio_venta'];
                $lineSubtotal = ($precioUnit * $cantidad) - $descuento;

                $subtotal       += $lineSubtotal;
                $descuentoTotal += $descuento;

                $lineas[] = [
                    'producto_id'     => (int) $producto['id'],
                    'cantidad'        => $cantidad,
                    'precio_unitario' => $precioUnit,
                    'descuento'       => $descuento,
                    'subtotal'        => $lineSubtotal,
                    'stock_anterior'  => (float) $producto['stock_actual'],
                ];
            }

            $impuesto = 0; // Sin IVA por ahora, configurable
            $total    = $subtotal + $impuesto;

            // Insertar ticket
            $ticketStmt = $this->db->prepare(
                "INSERT INTO tickets_venta (folio, usuario_id, cliente_nombre, subtotal, 
                 descuento_total, impuesto, total, metodo_pago, estado, notas)
                 VALUES (:folio, :usuario_id, :cliente_nombre, :subtotal, 
                 :descuento_total, :impuesto, :total, :metodo_pago, 'completado', :notas)"
            );
            $ticketStmt->execute([
                'folio'           => $folio,
                'usuario_id'      => $userId,
                'cliente_nombre'  => $data['cliente_nombre'] ?? 'Público General',
                'subtotal'        => $subtotal,
                'descuento_total' => $descuentoTotal,
                'impuesto'        => $impuesto,
                'total'           => $total,
                'metodo_pago'     => $data['metodo_pago'] ?? 'efectivo',
                'notas'           => $data['notas'] ?? null,
            ]);

            $ticketId = (int) $this->db->lastInsertId();

            // Insertar detalles y descontar inventario
            $detStmt = $this->db->prepare(
                "INSERT INTO ticket_detalle (ticket_id, producto_id, cantidad, precio_unitario, descuento, subtotal)
                 VALUES (:ticket_id, :producto_id, :cantidad, :precio_unitario, :descuento, :subtotal)"
            );

            $invStmt = $this->db->prepare(
                "UPDATE productos SET stock_actual = stock_actual - :cantidad WHERE id = :id"
            );

            $movStmt = $this->db->prepare(
                "INSERT INTO movimientos_inventario (producto_id, tipo, cantidad, stock_anterior, stock_nuevo, 
                 referencia_tipo, referencia_id, usuario_id)
                 VALUES (:producto_id, 'salida', :cantidad, :stock_anterior, :stock_nuevo, 
                 'ticket', :referencia_id, :usuario_id)"
            );

            foreach ($lineas as $linea) {
                $detStmt->execute([
                    'ticket_id'       => $ticketId,
                    'producto_id'     => $linea['producto_id'],
                    'cantidad'        => $linea['cantidad'],
                    'precio_unitario' => $linea['precio_unitario'],
                    'descuento'       => $linea['descuento'],
                    'subtotal'        => $linea['subtotal'],
                ]);

                $invStmt->execute([
                    'cantidad' => $linea['cantidad'],
                    'id'       => $linea['producto_id'],
                ]);

                $movStmt->execute([
                    'producto_id'    => $linea['producto_id'],
                    'cantidad'       => $linea['cantidad'],
                    'stock_anterior' => $linea['stock_anterior'],
                    'stock_nuevo'    => $linea['stock_anterior'] - $linea['cantidad'],
                    'referencia_id'  => $ticketId,
                    'usuario_id'     => $userId,
                ]);
            }

            // Registrar ingreso financiero
            $igStmt = $this->db->prepare(
                "INSERT INTO ingresos_gastos (tipo, concepto, monto, categoria, referencia_tipo, referencia_id, fecha, usuario_id)
                 VALUES ('ingreso', :concepto, :monto, 'venta', 'ticket', :referencia_id, CURDATE(), :usuario_id)"
            );
            $igStmt->execute([
                'concepto'      => "Venta Ticket #{$folio}",
                'monto'         => $total,
                'referencia_id' => $ticketId,
                'usuario_id'    => $userId,
            ]);

            $this->db->commit();

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Ticket creado exitosamente',
                'data'    => [
                    'id'    => $ticketId,
                    'folio' => $folio,
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
                'message' => 'Error al crear ticket: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * PUT /api/tickets/{id}/cancel
     * Cancelar ticket — revierte inventario
     */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        $id     = (int) $args['id'];
        $userId = (int) $request->getAttribute('jwt_user_id');

        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM tickets_venta WHERE id = :id FOR UPDATE"
            );
            $stmt->execute(['id' => $id]);
            $ticket = $stmt->fetch();

            if (!$ticket) {
                $this->db->rollBack();
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Ticket no encontrado',
                ], 404);
            }

            if ($ticket['estado'] === 'cancelado') {
                $this->db->rollBack();
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'El ticket ya está cancelado',
                ], 400);
            }

            // Obtener detalles para revertir inventario
            $detStmt = $this->db->prepare(
                "SELECT * FROM ticket_detalle WHERE ticket_id = :ticket_id"
            );
            $detStmt->execute(['ticket_id' => $id]);
            $detalles = $detStmt->fetchAll();

            // Revertir stock
            foreach ($detalles as $det) {
                $prodStmt = $this->db->prepare(
                    "SELECT stock_actual FROM productos WHERE id = :id FOR UPDATE"
                );
                $prodStmt->execute(['id' => $det['producto_id']]);
                $prod = $prodStmt->fetch();
                $stockAnterior = (float) $prod['stock_actual'];

                $this->db->prepare(
                    "UPDATE productos SET stock_actual = stock_actual + :cantidad WHERE id = :id"
                )->execute([
                    'cantidad' => $det['cantidad'],
                    'id'       => $det['producto_id'],
                ]);

                $this->db->prepare(
                    "INSERT INTO movimientos_inventario (producto_id, tipo, cantidad, stock_anterior, stock_nuevo, 
                     referencia_tipo, referencia_id, motivo, usuario_id)
                     VALUES (:producto_id, 'devolucion', :cantidad, :stock_anterior, :stock_nuevo, 
                     'devolucion', :referencia_id, 'Cancelación de ticket', :usuario_id)"
                )->execute([
                    'producto_id'    => $det['producto_id'],
                    'cantidad'       => $det['cantidad'],
                    'stock_anterior' => $stockAnterior,
                    'stock_nuevo'    => $stockAnterior + (float) $det['cantidad'],
                    'referencia_id'  => $id,
                    'usuario_id'     => $userId,
                ]);
            }

            // Marcar ticket como cancelado
            $this->db->prepare(
                "UPDATE tickets_venta SET estado = 'cancelado' WHERE id = :id"
            )->execute(['id' => $id]);

            // Revertir ingreso financiero
            $this->db->prepare(
                "INSERT INTO ingresos_gastos (tipo, concepto, monto, categoria, referencia_tipo, referencia_id, fecha, usuario_id)
                 VALUES ('gasto', :concepto, :monto, 'cancelacion', 'ticket', :referencia_id, CURDATE(), :usuario_id)"
            )->execute([
                'concepto'      => "Cancelación Ticket #{$ticket['folio']}",
                'monto'         => $ticket['total'],
                'referencia_id' => $id,
                'usuario_id'    => $userId,
            ]);

            $this->db->commit();

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Ticket cancelado exitosamente. Inventario revertido.',
            ]);

        } catch (\Exception $e) {
            $this->db->rollBack();
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Error al cancelar ticket: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * DELETE /api/tickets/{id}
     * Eliminar físicamente un ticket, sus líneas y registros asociados de la BD.
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];

        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare("SELECT * FROM tickets_venta WHERE id = :id FOR UPDATE");
            $stmt->execute(['id' => $id]);
            $ticket = $stmt->fetch();

            if (!$ticket) {
                $this->db->rollBack();
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Ticket no encontrado',
                ], 404);
            }

            // Eliminar detalles del ticket
            $this->db->prepare("DELETE FROM ticket_detalle WHERE ticket_id = :id")->execute(['id' => $id]);

            // Eliminar ticket de venta
            $this->db->prepare("DELETE FROM tickets_venta WHERE id = :id")->execute(['id' => $id]);

            // Eliminar movimientos de inventario asociados
            $this->db->prepare("DELETE FROM movimientos_inventario WHERE referencia_id = :id AND referencia_tipo IN ('ticket', 'devolucion')")->execute(['id' => $id]);

            // Eliminar registros financieros asociados
            $this->db->prepare("DELETE FROM ingresos_gastos WHERE referencia_id = :id AND referencia_tipo = 'ticket'")->execute(['id' => $id]);

            $this->db->commit();

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => "Ticket Folio {$ticket['folio']} eliminado físicamente de la base de datos.",
            ]);

        } catch (\Exception $e) {
            $this->db->rollBack();
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Error al eliminar ticket: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generar folio único: NAT-YYYYMMDD-XXXX
     */
    private function generateFolio(): string
    {
        $date  = date('Ymd');
        $stmt  = $this->db->prepare(
            "SELECT COUNT(*) FROM tickets_venta WHERE DATE(fecha) = CURDATE()"
        );
        $stmt->execute();
        $count = (int) $stmt->fetchColumn() + 1;

        return sprintf('NAT-%s-%04d', $date, $count);
    }

    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
