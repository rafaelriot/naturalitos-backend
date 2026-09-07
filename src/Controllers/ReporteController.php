<?php

declare(strict_types=1);

namespace App\Controllers;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Controller de Reportes.
 * Genera reportes de ventas, ganancias, productos top e inventario bajo.
 */
class ReporteController
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * GET /api/reportes/ventas
     * Ventas agrupadas por período
     * Query params: periodo=dia|semana|mes, fecha_inicio, fecha_fin
     */
    public function ventas(Request $request, Response $response): Response
    {
        $params   = $request->getQueryParams();
        $periodo  = $params['periodo'] ?? 'dia';
        $fechaIni = $params['fecha_inicio'] ?? date('Y-m-01'); // Primer día del mes actual
        $fechaFin = $params['fecha_fin'] ?? date('Y-m-d');

        $groupBy = match ($periodo) {
            'semana' => "YEARWEEK(t.fecha, 1)",
            'mes'    => "DATE_FORMAT(t.fecha, '%Y-%m')",
            default  => "DATE(t.fecha)",
        };

        $labelSelect = match ($periodo) {
            'semana' => "CONCAT('Sem ', WEEK(t.fecha, 1)) as label",
            'mes'    => "DATE_FORMAT(t.fecha, '%Y-%m') as label",
            default  => "DATE(t.fecha) as label",
        };

        $stmt = $this->db->prepare(
            "SELECT 
                {$labelSelect},
                COUNT(*) as num_tickets,
                SUM(t.total) as total_ventas,
                SUM(t.descuento_total) as total_descuentos,
                AVG(t.total) as promedio_ticket
             FROM tickets_venta t
             WHERE t.estado = 'completado'
               AND t.fecha >= :fecha_ini
               AND t.fecha <= :fecha_fin
             GROUP BY {$groupBy}
             ORDER BY {$groupBy} ASC"
        );
        $stmt->execute([
            'fecha_ini' => $fechaIni . ' 00:00:00',
            'fecha_fin' => $fechaFin . ' 23:59:59',
        ]);

        $ventas = $stmt->fetchAll();
        foreach ($ventas as &$v) {
            $v['num_tickets']       = (int) $v['num_tickets'];
            $v['total_ventas']      = (float) $v['total_ventas'];
            $v['total_descuentos']  = (float) $v['total_descuentos'];
            $v['promedio_ticket']   = round((float) $v['promedio_ticket'], 2);
        }

        // Resumen del período
        $resumenStmt = $this->db->prepare(
            "SELECT 
                COUNT(*) as total_tickets,
                COALESCE(SUM(total), 0) as total_ventas,
                COALESCE(AVG(total), 0) as promedio_ticket,
                COALESCE(MAX(total), 0) as ticket_max,
                COALESCE(MIN(total), 0) as ticket_min
             FROM tickets_venta
             WHERE estado = 'completado'
               AND fecha >= :fecha_ini
               AND fecha <= :fecha_fin"
        );
        $resumenStmt->execute([
            'fecha_ini' => $fechaIni . ' 00:00:00',
            'fecha_fin' => $fechaFin . ' 23:59:59',
        ]);
        $resumen = $resumenStmt->fetch();

        return $this->jsonResponse($response, [
            'success' => true,
            'data'    => [
                'periodo'  => $periodo,
                'desde'    => $fechaIni,
                'hasta'    => $fechaFin,
                'series'   => $ventas,
                'resumen'  => [
                    'total_tickets'   => (int) $resumen['total_tickets'],
                    'total_ventas'    => (float) $resumen['total_ventas'],
                    'promedio_ticket' => round((float) $resumen['promedio_ticket'], 2),
                    'ticket_max'      => (float) $resumen['ticket_max'],
                    'ticket_min'      => (float) $resumen['ticket_min'],
                ],
            ],
        ]);
    }

    /**
     * GET /api/reportes/ganancias
     * Ingresos vs Gastos y ganancia neta
     */
    public function ganancias(Request $request, Response $response): Response
    {
        $params   = $request->getQueryParams();
        $fechaIni = $params['fecha_inicio'] ?? date('Y-m-01');
        $fechaFin = $params['fecha_fin'] ?? date('Y-m-d');

        // Ingresos y gastos por categoría
        $stmt = $this->db->prepare(
            "SELECT 
                tipo,
                categoria,
                SUM(monto) as total,
                COUNT(*) as num_registros
             FROM ingresos_gastos
             WHERE fecha >= :fecha_ini
               AND fecha <= :fecha_fin
             GROUP BY tipo, categoria
             ORDER BY tipo, total DESC"
        );
        $stmt->execute([
            'fecha_ini' => $fechaIni,
            'fecha_fin' => $fechaFin,
        ]);
        $detalle = $stmt->fetchAll();

        $ingresos = [];
        $gastos   = [];
        $totalIngresos = 0;
        $totalGastos   = 0;

        foreach ($detalle as $d) {
            $item = [
                'categoria'     => $d['categoria'],
                'total'         => (float) $d['total'],
                'num_registros' => (int) $d['num_registros'],
            ];

            if ($d['tipo'] === 'ingreso') {
                $ingresos[]     = $item;
                $totalIngresos += (float) $d['total'];
            } else {
                $gastos[]     = $item;
                $totalGastos += (float) $d['total'];
            }
        }

        // Tendencia diaria
        $tendenciaStmt = $this->db->prepare(
            "SELECT 
                fecha,
                SUM(CASE WHEN tipo = 'ingreso' THEN monto ELSE 0 END) as ingresos,
                SUM(CASE WHEN tipo = 'gasto' THEN monto ELSE 0 END) as gastos
             FROM ingresos_gastos
             WHERE fecha >= :fecha_ini
               AND fecha <= :fecha_fin
             GROUP BY fecha
             ORDER BY fecha ASC"
        );
        $tendenciaStmt->execute([
            'fecha_ini' => $fechaIni,
            'fecha_fin' => $fechaFin,
        ]);
        $tendencia = $tendenciaStmt->fetchAll();

        foreach ($tendencia as &$t) {
            $t['ingresos']  = (float) $t['ingresos'];
            $t['gastos']    = (float) $t['gastos'];
            $t['ganancia']  = $t['ingresos'] - $t['gastos'];
        }

        return $this->jsonResponse($response, [
            'success' => true,
            'data'    => [
                'desde'           => $fechaIni,
                'hasta'           => $fechaFin,
                'total_ingresos'  => $totalIngresos,
                'total_gastos'    => $totalGastos,
                'ganancia_neta'   => $totalIngresos - $totalGastos,
                'margen_porcentaje' => $totalIngresos > 0
                    ? round((($totalIngresos - $totalGastos) / $totalIngresos) * 100, 2)
                    : 0,
                'ingresos_por_categoria' => $ingresos,
                'gastos_por_categoria'   => $gastos,
                'tendencia_diaria'       => $tendencia,
            ],
        ]);
    }

    /**
     * GET /api/reportes/productos-top
     * Productos más vendidos
     */
    public function productosTop(Request $request, Response $response): Response
    {
        $params   = $request->getQueryParams();
        $limit    = min(50, max(5, (int) ($params['limit'] ?? 10)));
        $fechaIni = $params['fecha_inicio'] ?? date('Y-m-01');
        $fechaFin = $params['fecha_fin'] ?? date('Y-m-d');

        $stmt = $this->db->prepare(
            "SELECT 
                p.id, p.sku, p.nombre, p.precio_venta,
                c.nombre as categoria,
                SUM(td.cantidad) as total_vendido,
                SUM(td.subtotal) as total_ingresos,
                COUNT(DISTINCT td.ticket_id) as num_tickets
             FROM ticket_detalle td
             INNER JOIN productos p ON td.producto_id = p.id
             LEFT JOIN categorias c ON p.categoria_id = c.id
             INNER JOIN tickets_venta t ON td.ticket_id = t.id
             WHERE t.estado = 'completado'
               AND t.fecha >= :fecha_ini
               AND t.fecha <= :fecha_fin
             GROUP BY p.id, p.sku, p.nombre, p.precio_venta, c.nombre
             ORDER BY total_vendido DESC
             LIMIT :limit"
        );
        $stmt->bindValue('fecha_ini', $fechaIni . ' 00:00:00');
        $stmt->bindValue('fecha_fin', $fechaFin . ' 23:59:59');
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $productos = $stmt->fetchAll();
        foreach ($productos as &$p) {
            $p['id']              = (int) $p['id'];
            $p['precio_venta']    = (float) $p['precio_venta'];
            $p['total_vendido']   = (float) $p['total_vendido'];
            $p['total_ingresos']  = (float) $p['total_ingresos'];
            $p['num_tickets']     = (int) $p['num_tickets'];
        }

        return $this->jsonResponse($response, [
            'success' => true,
            'data'    => $productos,
        ]);
    }

    /**
     * GET /api/reportes/inventario-bajo
     * Productos con stock por debajo del mínimo
     */
    public function inventarioBajo(Request $request, Response $response): Response
    {
        $stmt = $this->db->query(
            "SELECT 
                p.id, p.sku, p.nombre, p.stock_actual, p.stock_minimo, p.unidad,
                p.precio_compra, p.es_producto_vivo,
                c.nombre as categoria,
                CASE 
                    WHEN p.stock_actual <= 0 THEN 'agotado'
                    ELSE 'bajo'
                END as alerta
             FROM productos p
             LEFT JOIN categorias c ON p.categoria_id = c.id
             WHERE p.activo = 1
               AND p.stock_actual <= p.stock_minimo
             ORDER BY p.stock_actual ASC, p.nombre ASC"
        );

        $productos = $stmt->fetchAll();
        foreach ($productos as &$p) {
            $p['id']               = (int) $p['id'];
            $p['stock_actual']     = (float) $p['stock_actual'];
            $p['stock_minimo']     = (float) $p['stock_minimo'];
            $p['precio_compra']    = (float) $p['precio_compra'];
            $p['es_producto_vivo'] = (bool) $p['es_producto_vivo'];
            // Cantidad necesaria para llegar al mínimo
            $p['cantidad_reorden'] = max(0, $p['stock_minimo'] - $p['stock_actual']);
            $p['costo_reorden']    = $p['cantidad_reorden'] * $p['precio_compra'];
        }

        return $this->jsonResponse($response, [
            'success' => true,
            'data'    => $productos,
            'stats'   => [
                'total_alertas'      => count($productos),
                'costo_total_reorden' => array_sum(array_column($productos, 'costo_reorden')),
            ],
        ]);
    }

    /**
     * GET /api/reportes/dashboard
     * Resumen general para el dashboard
     */
    public function dashboard(Request $request, Response $response): Response
    {
        // Ventas de hoy
        $ventasHoy = $this->db->query(
            "SELECT COUNT(*) as tickets, COALESCE(SUM(total), 0) as total
             FROM tickets_venta
             WHERE estado = 'completado' AND DATE(fecha) = CURDATE()"
        )->fetch();

        // Ventas del mes
        $ventasMes = $this->db->query(
            "SELECT COUNT(*) as tickets, COALESCE(SUM(total), 0) as total
             FROM tickets_venta
             WHERE estado = 'completado' 
               AND YEAR(fecha) = YEAR(CURDATE()) 
               AND MONTH(fecha) = MONTH(CURDATE())"
        )->fetch();

        // Productos activos
        $totalProductos = (int) $this->db->query(
            "SELECT COUNT(*) FROM productos WHERE activo = 1"
        )->fetchColumn();

        // Stock bajo
        $stockBajo = (int) $this->db->query(
            "SELECT COUNT(*) FROM productos WHERE activo = 1 AND stock_actual <= stock_minimo"
        )->fetchColumn();

        // Ganancias del mes
        $gananciasMes = $this->db->query(
            "SELECT 
                COALESCE(SUM(CASE WHEN tipo = 'ingreso' THEN monto ELSE 0 END), 0) as ingresos,
                COALESCE(SUM(CASE WHEN tipo = 'gasto' THEN monto ELSE 0 END), 0) as gastos
             FROM ingresos_gastos
             WHERE YEAR(fecha) = YEAR(CURDATE()) AND MONTH(fecha) = MONTH(CURDATE())"
        )->fetch();

        // Últimos 5 tickets
        $ultimosTickets = $this->db->query(
            "SELECT t.id, t.folio, t.total, t.estado, t.fecha, u.nombre as vendedor
             FROM tickets_venta t
             LEFT JOIN usuarios u ON t.usuario_id = u.id
             ORDER BY t.fecha DESC LIMIT 5"
        )->fetchAll();

        foreach ($ultimosTickets as &$t) {
            $t['id']    = (int) $t['id'];
            $t['total'] = (float) $t['total'];
        }

        return $this->jsonResponse($response, [
            'success' => true,
            'data'    => [
                'ventas_hoy' => [
                    'tickets' => (int) $ventasHoy['tickets'],
                    'total'   => (float) $ventasHoy['total'],
                ],
                'ventas_mes' => [
                    'tickets' => (int) $ventasMes['tickets'],
                    'total'   => (float) $ventasMes['total'],
                ],
                'total_productos' => $totalProductos,
                'stock_bajo'      => $stockBajo,
                'ganancias_mes'   => [
                    'ingresos'      => (float) $gananciasMes['ingresos'],
                    'gastos'        => (float) $gananciasMes['gastos'],
                    'ganancia_neta' => (float) $gananciasMes['ingresos'] - (float) $gananciasMes['gastos'],
                ],
                'ultimos_tickets' => $ultimosTickets,
            ],
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
