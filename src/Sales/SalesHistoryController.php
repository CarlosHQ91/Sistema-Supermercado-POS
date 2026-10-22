<?php
declare(strict_types=1);

namespace App\Sales;

use App\Shared\Database;
use App\Shared\Auth;
use App\Shared\Audit;
use App\Shared\Response;
use PDO;
use Throwable;

class SalesHistoryController
{
    /**
     * Listado con filtros avanzados de ventas
     */
    public function listSales(): void
    {
        $pdo = Database::getConnection();

        $fechaDesde = trim($_GET['fecha_desde'] ?? '');
        $fechaHasta = trim($_GET['fecha_hasta'] ?? '');
        $cajeroId = (int)($_GET['cajero_id'] ?? 0);
        $estado = trim($_GET['estado'] ?? '');
        $q = trim($_GET['q'] ?? '');

        $where = ["1=1"];
        $params = [];

        if ($fechaDesde !== '') {
            $where[] = "v.fecha_hora::date >= :desde";
            $params['desde'] = $fechaDesde;
        }
        if ($fechaHasta !== '') {
            $where[] = "v.fecha_hora::date <= :hasta";
            $params['hasta'] = $fechaHasta;
        }
        if ($cajeroId > 0) {
            $where[] = "v.usuario_cajero_id = :cajero";
            $params['cajero'] = $cajeroId;
        }
        if ($estado !== '') {
            $where[] = "v.estado = :estado";
            $params['estado'] = $estado;
        }
        if ($q !== '') {
            $where[] = "(v.numero_venta ILIKE :q OR v.numero_control_mh ILIKE :q OR c.nombre ILIKE :q)";
            $params['q'] = '%' . $q . '%';
        }

        $whereSql = implode(' AND ', $where);

        $sql = "
            SELECT v.id, v.numero_venta, v.tipo_documento, v.numero_control_mh,
                   v.codigo_generacion_mh, v.subtotal_gravado, v.iva, v.total,
                   v.estado, v.fecha_hora, u.nombre_completo as cajero_nombre,
                   c.nombre as cliente_nombre, c.nit as cliente_nit,
                   cs.corte_z_numero, ca.nombre as caja_nombre
            FROM ventas v
            JOIN usuarios u ON u.id = v.usuario_cajero_id
            JOIN clientes c ON c.id = v.cliente_id
            JOIN caja_sesiones cs ON cs.id = v.sesion_caja_id
            JOIN cajas ca ON ca.id = cs.caja_id
            WHERE {$whereSql}
            ORDER BY v.fecha_hora DESC, v.id DESC
            LIMIT 100
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $sales = $stmt->fetchAll();

        // Lista de cajeros para filtro
        $cajeros = $pdo->query("SELECT id, nombre_completo FROM usuarios WHERE rol IN ('CAJERO', 'ADMIN', 'GERENTE') ORDER BY nombre_completo ASC")->fetchAll();

        Response::json([
            'sales' => $sales,
            'cajeros' => $cajeros
        ]);
    }

    /**
     * Detalle completo de una venta
     */
    public function getSaleDetail(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("
            SELECT v.*, u.nombre_completo as cajero_nombre,
                   c.nombre as cliente_nombre, c.nit as cliente_nit, c.nrc as cliente_nrc, c.dui as cliente_dui, c.direccion as cliente_dir,
                   ua.nombre_completo as anulado_por_nombre
            FROM ventas v
            JOIN usuarios u ON u.id = v.usuario_cajero_id
            JOIN clientes c ON c.id = v.cliente_id
            LEFT JOIN usuarios ua ON ua.id = v.anulado_por_usuario_id
            WHERE v.id = :id
        ");
        $stmt->execute(['id' => $id]);
        $venta = $stmt->fetch();

        if (!$venta) {
            Response::error('Venta no encontrada.');
        }

        $stmtItems = $pdo->prepare("
            SELECT vi.*, p.nombre as producto_nombre, p.codigo_interno
            FROM venta_items vi
            JOIN productos p ON p.id = vi.producto_id
            WHERE vi.venta_id = :id
        ");
        $stmtItems->execute(['id' => $id]);
        $items = $stmtItems->fetchAll();

        $stmtPagos = $pdo->prepare("SELECT * FROM venta_pagos WHERE venta_id = :id");
        $stmtPagos->execute(['id' => $id]);
        $pagos = $stmtPagos->fetchAll();

        Response::json([
            'venta' => $venta,
            'items' => $items,
            'pagos' => $pagos
        ]);
    }

    /**
     * Anulación supervisada de venta con reversión en Kardex
     */
    public function voidSale(): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $ventaId = (int)($input['venta_id'] ?? 0);
        $motivo = trim($input['motivo'] ?? '');
        $pinSupervisor = $input['pin_supervisor'] ?? '';

        if ($motivo === '') {
            Response::error('Debe justificar el motivo de la anulación.');
        }

        $supervisor = Auth::verifySupervisor($pinSupervisor);
        if (!$supervisor) {
            Response::error('Se requiere autorización válida de Gerente/Supervisor para anular una venta.');
        }

        $pdo = Database::getConnection();

        try {
            Database::transaction(function (PDO $pdo) use ($ventaId, $motivo, $supervisor) {
                // 1. Obtener venta activa
                $stmt = $pdo->prepare("SELECT * FROM ventas WHERE id = :id FOR UPDATE");
                $stmt->execute(['id' => $ventaId]);
                $venta = $stmt->fetch();

                if (!$venta) {
                    throw new \Exception('Venta no encontrada.');
                }
                if ($venta['estado'] === 'ANULADA') {
                    throw new \Exception('Esta venta ya se encuentra anulada.');
                }

                // 2. Obtener ítems para devolver stock al Kardex
                $stmtItems = $pdo->prepare("SELECT * FROM venta_items WHERE venta_id = :id");
                $stmtItems->execute(['id' => $ventaId]);
                $items = $stmtItems->fetchAll();

                $stmtStock = $pdo->prepare("
                    SELECT cantidad FROM stock_bodega WHERE producto_id = :p AND bodega_id = :b FOR UPDATE
                ");
                $stmtUpdStock = $pdo->prepare("
                    UPDATE stock_bodega SET cantidad = cantidad + :cant, updated_at = CURRENT_TIMESTAMP
                    WHERE producto_id = :p AND bodega_id = :b
                ");
                $stmtKardex = $pdo->prepare("
                    INSERT INTO kardex_movimientos (
                        producto_id, bodega_id, tipo_movimiento, cantidad, costo_unitario,
                        stock_anterior, stock_posterior, documento_referencia, usuario_id, notas
                    ) VALUES (
                        :p, :b, 'ANULACION_VENTA', :cant, :costo,
                        :ant, :post, :doc, :u, :notas
                    )
                ");

                foreach ($items as $it) {
                    $stmtStock->execute(['p' => $it['producto_id'], 'b' => $it['bodega_id']]);
                    $stockAnt = (float)$stmtStock->fetchColumn();
                    $cantDevuelta = (float)$it['cantidad'];
                    $stockPost = $stockAnt + $cantDevuelta;

                    $stmtUpdStock->execute([
                        'cant' => $cantDevuelta,
                        'p' => $it['producto_id'],
                        'b' => $it['bodega_id']
                    ]);

                    $stmtKardex->execute([
                        'p' => $it['producto_id'],
                        'b' => $it['bodega_id'],
                        'cant' => $cantDevuelta,
                        'costo' => $it['costo_unitario'],
                        'ant' => $stockAnt,
                        'post' => $stockPost,
                        'doc' => 'ANUL-' . $venta['numero_venta'],
                        'u' => $supervisor['id'],
                        'notas' => "Anulación de venta {$venta['numero_venta']}: {$motivo}"
                    ]);
                }

                // 3. Revertir saldo de cliente si fue venta al crédito
                $stmtPagos = $pdo->prepare("SELECT monto FROM venta_pagos WHERE venta_id = :id AND metodo_pago = 'CREDITO'");
                $stmtPagos->execute(['id' => $ventaId]);
                $pagoCredito = (float)$stmtPagos->fetchColumn();
                if ($pagoCredito > 0) {
                    $stmtCli = $pdo->prepare("UPDATE clientes SET saldo_deudor = GREATEST(0, saldo_deudor - :monto) WHERE id = :id");
                    $stmtCli->execute(['monto' => $pagoCredito, 'id' => $venta['cliente_id']]);
                }

                // 4. Actualizar estado de la venta
                $stmtVoid = $pdo->prepare("
                    UPDATE ventas
                    SET estado = 'ANULADA',
                        motivo_anulacion = :motivo,
                        anulado_por_usuario_id = :sup,
                        anulado_en = CURRENT_TIMESTAMP
                    WHERE id = :id
                ");
                $stmtVoid->execute([
                    'motivo' => $motivo,
                    'sup' => $supervisor['id'],
                    'id' => $ventaId
                ]);

                // 5. Asiento contable de reversión
                $stmtAsiento = $pdo->prepare("
                    INSERT INTO asientos_contables (numero_asiento, fecha, concepto, origen_modulo, referencia_id, usuario_id, total_debe, total_haber)
                    VALUES (:num, CURRENT_DATE, :con, 'VENTAS', :ref, :u, :tot, :tot)
                ");
                $numAst = 'AST-ANUL-' . $ventaId;
                $totVenta = (float)$venta['total'];
                $stmtAsiento->execute([
                    'num' => $numAst,
                    'con' => "Reversión por anulación de venta {$venta['numero_venta']}: {$motivo}",
                    'ref' => (string)$ventaId,
                    'u' => $supervisor['id'],
                    'tot' => $totVenta
                ]);

                Audit::log('VOID', 'ventas', (string)$ventaId, null, [
                    'numero_venta' => $venta['numero_venta'],
                    'motivo' => $motivo,
                    'anulado_por' => $supervisor['username']
                ], "Venta anulada por supervisor");
            });

            Response::success([], 'Venta anulada con éxito y stock devuelto al inventario.');
        } catch (Throwable $e) {
            Response::error($e->getMessage(), 400);
        }
    }
}
