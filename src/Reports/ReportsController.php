<?php
declare(strict_types=1);

namespace App\Reports;

use App\Shared\Database;
use App\Shared\Response;
use PDO;

class ReportsController
{
    /**
     * Métricas del Dashboard ejecutivo en tiempo real
     */
    public function dashboard(): void
    {
        $pdo = Database::getConnection();

        // 1. Ventas hoy
        $stmtHoy = $pdo->query("
            SELECT COALESCE(SUM(total), 0) as total_hoy, COUNT(id) as tickets_hoy
            FROM ventas
            WHERE fecha_hora::date = CURRENT_DATE AND estado = 'COMPLETADA'
        ");
        $hoy = $stmtHoy->fetch();
        $totalHoy = (float)$hoy['total_hoy'];
        $ticketsHoy = (int)$hoy['tickets_hoy'];
        $ticketPromedio = $ticketsHoy > 0 ? ($totalHoy / $ticketsHoy) : 0.00;

        // 2. Ventas ayer
        $stmtAyer = $pdo->query("
            SELECT COALESCE(SUM(total), 0) as total_ayer, COUNT(id) as tickets_ayer
            FROM ventas
            WHERE fecha_hora::date = CURRENT_DATE - INTERVAL '1 day' AND estado = 'COMPLETADA'
        ");
        $ayer = $stmtAyer->fetch();
        $totalAyer = (float)$ayer['total_ayer'];

        // Crecimiento porcentual vs ayer
        $crecimiento = $totalAyer > 0 ? ((($totalHoy - $totalAyer) / $totalAyer) * 100) : 0.0;

        // 3. Cajas activas
        $stmtCajas = $pdo->query("
            SELECT c.nombre as caja_nombre, c.numero_terminal, cs.id as sesion_id,
                   u.nombre_completo as cajero_nombre, cs.fecha_apertura, cs.monto_apertura
            FROM caja_sesiones cs
            JOIN cajas c ON c.id = cs.caja_id
            JOIN usuarios u ON u.id = cs.usuario_id
            WHERE cs.estado = 'ABIERTA'
            ORDER BY c.id ASC
        ");
        $cajasActivas = $stmtCajas->fetchAll();

        // 4. Alertas de Stock Bajo (< stock_minimo)
        $stmtStockBajo = $pdo->query("
            SELECT p.id, p.codigo_interno, p.nombre, p.stock_minimo,
                   COALESCE(SUM(sb.cantidad), 0) as stock_actual, p.unidad_medida
            FROM productos p
            LEFT JOIN stock_bodega sb ON sb.producto_id = p.id
            WHERE p.activo = TRUE
            GROUP BY p.id, p.codigo_interno, p.nombre, p.stock_minimo, p.unidad_medida
            HAVING COALESCE(SUM(sb.cantidad), 0) <= p.stock_minimo
            ORDER BY stock_actual ASC
            LIMIT 10
        ");
        $alertasStock = $stmtStockBajo->fetchAll();

        // 5. Alertas de Lotes próximos a vencer (< 30 días)
        $stmtLotes = $pdo->query("
            SELECT l.id, p.nombre as producto_nombre, l.numero_lote, l.fecha_vencimiento,
                   l.cantidad, (l.fecha_vencimiento - CURRENT_DATE) as dias_restantes
            FROM lotes l
            JOIN productos p ON p.id = l.producto_id
            WHERE l.activo = TRUE AND l.cantidad > 0
              AND l.fecha_vencimiento <= (CURRENT_DATE + INTERVAL '30 days')
            ORDER BY l.fecha_vencimiento ASC
            LIMIT 10
        ");
        $alertasVencimiento = $stmtLotes->fetchAll();

        // 6. Top 5 productos más vendidos hoy
        $stmtTop = $pdo->query("
            SELECT p.nombre, SUM(vi.cantidad) as total_unidades, SUM(vi.total_linea) as total_monto
            FROM venta_items vi
            JOIN productos p ON p.id = vi.producto_id
            JOIN ventas v ON v.id = vi.venta_id
            WHERE v.fecha_hora::date = CURRENT_DATE AND v.estado = 'COMPLETADA'
            GROUP BY p.id, p.nombre
            ORDER BY total_unidades DESC
            LIMIT 5
        ");
        $topProductos = $stmtTop->fetchAll();

        // 7. Desglose de formas de pago hoy
        $stmtPagos = $pdo->query("
            SELECT vp.metodo_pago, COALESCE(SUM(vp.monto), 0) as monto, COUNT(vp.id) as transacciones
            FROM venta_pagos vp
            JOIN ventas v ON v.id = vp.venta_id
            WHERE v.fecha_hora::date = CURRENT_DATE AND v.estado = 'COMPLETADA'
            GROUP BY vp.metodo_pago
        ");
        $desglosePagos = $stmtPagos->fetchAll();

        // Ganancia bruta hoy & Margen
        $stmtProfit = $pdo->query("
            SELECT COALESCE(SUM(vi.total_linea - (vi.cantidad * vi.costo_unitario)), 0) as ganancia_bruta,
                   COALESCE(SUM(vi.cantidad * vi.costo_unitario), 0) as costo_total
            FROM venta_items vi
            JOIN ventas v ON v.id = vi.venta_id
            WHERE v.fecha_hora::date = CURRENT_DATE AND v.estado = 'COMPLETADA'
        ");
        $profit = $stmtProfit->fetch();
        $gananciaBrutaHoy = (float)($profit['ganancia_bruta'] ?? 0.0);
        $costoTotalHoy = (float)($profit['costo_total'] ?? 0.0);
        $margenPorc = $totalHoy > 0 ? round(($gananciaBrutaHoy / $totalHoy) * 100, 1) : 0.0;

        // Métricas del mes
        $stmtMes = $pdo->query("
            SELECT COALESCE(SUM(total), 0) as total_mes, COUNT(id) as tickets_mes
            FROM ventas
            WHERE EXTRACT(MONTH FROM fecha_hora) = EXTRACT(MONTH FROM CURRENT_DATE)
              AND EXTRACT(YEAR FROM fecha_hora) = EXTRACT(YEAR FROM CURRENT_DATE)
              AND estado = 'COMPLETADA'
        ");
        $mesData = $stmtMes->fetch();
        $totalMes = (float)($mesData['total_mes'] ?? 0.0);
        $ticketsMes = (int)($mesData['tickets_mes'] ?? 0);

        // Inventario total valuado y conteo
        $stmtInv = $pdo->query("
            SELECT COUNT(DISTINCT p.id) as total_prods,
                   COALESCE(SUM(sb.cantidad * p.costo_promedio), 0) as valor_inventario
            FROM productos p
            LEFT JOIN stock_bodega sb ON sb.producto_id = p.id
            WHERE p.activo = TRUE
        ");
        $invData = $stmtInv->fetch();
        $totalProds = (int)($invData['total_prods'] ?? 0);
        $valorInventario = (float)($invData['valor_inventario'] ?? 0.0);

        Response::json([
            'kpis' => [
                'total_hoy' => $totalHoy,
                'tickets_hoy' => $ticketsHoy,
                'ticket_promedio' => $ticketPromedio,
                'total_ayer' => $totalAyer,
                'crecimiento_porcentaje' => round($crecimiento, 2),
                'ganancia_bruta_hoy' => $gananciaBrutaHoy,
                'costo_total_hoy' => $costoTotalHoy,
                'margen_bruto_porc' => $margenPorc,
                'total_mes' => $totalMes,
                'tickets_mes' => $ticketsMes,
                'total_productos' => $totalProds,
                'valor_inventario' => round($valorInventario, 2)
            ],
            'cajas_activas' => $cajasActivas,
            'alertas_stock' => $alertasStock,
            'alertas_vencimiento' => $alertasVencimiento,
            'top_productos' => $topProductos,
            'desglose_pagos' => $desglosePagos
        ]);
    }

    /**
     * Reporte Especializado de Ventas por Producto y Márgenes
     */
    public function salesReport(): void
    {
        $pdo = Database::getConnection();
        $desde = !empty($_GET['desde']) ? $_GET['desde'] : date('Y-m-01');
        $hasta = !empty($_GET['hasta']) ? $_GET['hasta'] : date('Y-m-d');
        $categoriaId = (int)($_GET['categoria_id'] ?? 0);

        $params = ['desde' => $desde, 'hasta' => $hasta];
        $catFilter = "";
        if ($categoriaId > 0) {
            $catFilter = "AND p.categoria_id = :cat";
            $params['cat'] = $categoriaId;
        }

        $stmt = $pdo->prepare("
            SELECT p.id, p.codigo_interno, p.nombre, c.nombre as categoria_nombre,
                   SUM(vi.cantidad) as unidades_vendidas,
                   SUM(vi.total_linea) as venta_total,
                   SUM(vi.cantidad * vi.costo_unitario) as costo_total,
                   SUM(vi.total_linea) - SUM(vi.cantidad * vi.costo_unitario) as ganancia_bruta,
                   CASE
                       WHEN SUM(vi.total_linea) > 0 THEN
                           ROUND(((SUM(vi.total_linea) - SUM(vi.cantidad * vi.costo_unitario)) / SUM(vi.total_linea) * 100), 2)
                       ELSE 0.00
                   END as margen_porcentaje
            FROM venta_items vi
            JOIN productos p ON p.id = vi.producto_id
            JOIN categorias c ON c.id = p.categoria_id
            JOIN ventas v ON v.id = vi.venta_id
            WHERE v.fecha_hora::date BETWEEN :desde AND :hasta
              AND v.estado = 'COMPLETADA'
              {$catFilter}
            GROUP BY p.id, p.codigo_interno, p.nombre, c.nombre
            ORDER BY venta_total DESC
        ");
        $stmt->execute($params);
        $items = $stmt->fetchAll();

        $totVenta = 0.0;
        $totCosto = 0.0;
        foreach ($items as $it) {
            $totVenta += (float)$it['venta_total'];
            $totCosto += (float)$it['costo_total'];
        }

        Response::json([
            'desde' => $desde,
            'hasta' => $hasta,
            'items' => $items,
            'resumen' => [
                'venta_total' => round($totVenta, 2),
                'costo_total' => round($totCosto, 2),
                'ganancia_bruta' => round($totVenta - $totCosto, 2),
                'margen_global' => $totVenta > 0 ? round((($totVenta - $totCosto) / $totVenta * 100), 2) : 0.00
            ]
        ]);
    }

    /**
     * Reporte de Kardex Valorizado (Valor Financiero del Inventario CPP)
     */
    public function kardexValuationReport(): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query("
            SELECT p.id, p.codigo_interno, p.nombre, p.presentacion, c.nombre as categoria_nombre,
                   p.costo_promedio, p.precio_venta,
                   COALESCE(MAX(CASE WHEN sb.bodega_id = 1 THEN sb.cantidad END), 0) as stock_piso,
                   COALESCE(MAX(CASE WHEN sb.bodega_id = 2 THEN sb.cantidad END), 0) as stock_bodega,
                   COALESCE(SUM(sb.cantidad), 0) as stock_total,
                   ROUND(COALESCE(SUM(sb.cantidad), 0) * p.costo_promedio, 4) as valor_total_costo,
                   ROUND(COALESCE(SUM(sb.cantidad), 0) * p.precio_venta, 4) as valor_total_venta
            FROM productos p
            LEFT JOIN categorias c ON c.id = p.categoria_id
            LEFT JOIN stock_bodega sb ON sb.producto_id = p.id
            WHERE p.activo = TRUE
            GROUP BY p.id, p.codigo_interno, p.nombre, p.presentacion, c.nombre, p.costo_promedio, p.precio_venta
            ORDER BY valor_total_costo DESC
        ");
        $items = $stmt->fetchAll();

        $totalCostoInventario = 0.0;
        $totalVentaPotencial = 0.0;
        $totalUnidades = 0.0;
        foreach ($items as $it) {
            $totalCostoInventario += (float)$it['valor_total_costo'];
            $totalVentaPotencial += (float)$it['valor_total_venta'];
            $totalUnidades += (float)$it['stock_total'];
        }

        Response::json([
            'items' => $items,
            'total_items' => count($items),
            'total_unidades' => round($totalUnidades, 2),
            'valor_total_inventario' => round($totalCostoInventario, 2),
            'totales' => [
                'total_articulos' => count($items),
                'total_unidades' => round($totalUnidades, 2),
                'total_valor_costo' => round($totalCostoInventario, 2),
                'total_valor_venta' => round($totalVentaPotencial, 2),
                'utilidad_proyectada' => round($totalVentaPotencial - $totalCostoInventario, 2)
            ]
        ]);
    }

    /**
     * Reporte Histórico de Cortes Z y Turnos de Caja
     */
    public function cashShiftsReport(): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query("
            SELECT cs.*, c.nombre as caja_nombre, u.nombre_completo as cajero_nombre,
                   sup.nombre_completo as supervisor_nombre
            FROM caja_sesiones cs
            JOIN cajas c ON c.id = cs.caja_id
            JOIN usuarios u ON u.id = cs.usuario_id
            LEFT JOIN usuarios sup ON sup.id = cs.supervisor_cierre_id
            ORDER BY cs.fecha_apertura DESC
            LIMIT 50
        ");
        Response::json(['shifts' => $stmt->fetchAll()]);
    }
}

