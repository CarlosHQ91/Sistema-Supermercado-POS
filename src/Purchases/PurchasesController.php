<?php
declare(strict_types=1);

namespace App\Purchases;

use App\Shared\Database;
use App\Shared\Auth;
use App\Shared\Audit;
use App\Shared\Response;
use PDO;
use Throwable;

class PurchasesController
{
    /**
     * Listar facturas de compra a proveedores (permite filtro por proveedor_id)
     */
    public function listPurchases(): void
    {
        $pdo = Database::getConnection();
        $proveedorId = (int)($_GET['proveedor_id'] ?? 0);
        $where = "";
        $params = [];
        if ($proveedorId > 0) {
            $where = "WHERE cf.proveedor_id = :prov";
            $params['prov'] = $proveedorId;
        }

        $stmt = $pdo->prepare("
            SELECT cf.*, p.razon_social as proveedor_nombre, p.nit as proveedor_nit,
                   b.nombre as bodega_nombre, u.nombre_completo as usuario_nombre
            FROM compras_facturas cf
            JOIN proveedores p ON p.id = cf.proveedor_id
            JOIN bodegas b ON b.id = cf.bodega_id
            JOIN usuarios u ON u.id = cf.usuario_id
            {$where}
            ORDER BY cf.fecha_factura DESC, cf.id DESC
            LIMIT 100
        ");
        $stmt->execute($params);
        Response::json(['purchases' => $stmt->fetchAll()]);
    }

    /**
     * Detalle de factura de compra con sus líneas de productos
     */
    public function getPurchaseDetail(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            Response::error('ID de compra requerido');
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT cf.*, p.razon_social as proveedor_nombre, p.nit as proveedor_nit, p.nrc as proveedor_nrc,
                   b.nombre as bodega_nombre, u.nombre_completo as usuario_nombre
            FROM compras_facturas cf
            JOIN proveedores p ON p.id = cf.proveedor_id
            JOIN bodegas b ON b.id = cf.bodega_id
            JOIN usuarios u ON u.id = cf.usuario_id
            WHERE cf.id = :id
        ");
        $stmt->execute(['id' => $id]);
        $compra = $stmt->fetch();
        if (!$compra) {
            Response::error('Factura de compra no encontrada', 404);
        }

        $stmtDet = $pdo->prepare("
            SELECT cfd.*, p.codigo_interno, p.nombre as producto_nombre
            FROM compras_facturas_detalle cfd
            JOIN productos p ON p.id = cfd.producto_id
            WHERE cfd.compra_id = :id
            ORDER BY cfd.id ASC
        ");
        $stmtDet->execute(['id' => $id]);
        $items = $stmtDet->fetchAll();

        Response::json([
            'compra' => $compra,
            'items' => $items
        ]);
    }

    /**
     * Registrar factura de compra y recepción de mercadería
     */
    public function createPurchase(): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $proveedorId = (int)($input['proveedor_id'] ?? 0);
        $bodegaId = (int)($input['bodega_id'] ?? 2); // Default Bodega Principal
        $numeroFactura = trim($input['numero_factura'] ?? '');
        $tipoDocumento = $input['tipo_documento'] ?? '03'; // 03 CCF
        $fechaFactura = $input['fecha_factura'] ?? date('Y-m-d');
        $esCredito = !empty($input['es_credito']);
        $diasCredito = (int)($input['dias_credito'] ?? 0);
        $items = $input['items'] ?? [];

        if ($proveedorId <= 0 || $numeroFactura === '') {
            Response::error('El proveedor y el número de factura son obligatorios.');
        }
        if (empty($items)) {
            Response::error('Debe ingresar al menos un producto en la compra.');
        }

        $userId = Auth::id();
        $pdo = Database::getConnection();

        try {
            $compraId = Database::transaction(function (PDO $pdo) use (
                $proveedorId, $bodegaId, $numeroFactura, $tipoDocumento,
                $fechaFactura, $esCredito, $diasCredito, $items, $userId
            ) {
                $subtotal = 0.0000;
                $totalIva = 0.0000;
                $totalCompra = 0.0000;
                $itemsCalculados = [];

                foreach ($items as $it) {
                    $prodId = (int)$it['producto_id'];
                    $cant = (float)$it['cantidad'];
                    $costoUnit = (float)$it['costo_unitario'];
                    $lote = !empty($it['numero_lote']) ? trim($it['numero_lote']) : null;
                    $vence = !empty($it['fecha_vencimiento']) ? trim($it['fecha_vencimiento']) : null;

                    if ($cant <= 0 || $costoUnit <= 0) {
                        throw new \Exception("Cantidad y costo deben ser mayores a cero.");
                    }

                    $lineaTotal = $cant * $costoUnit;
                    $ivaLinea = ($tipoDocumento === '03') ? round($lineaTotal * 0.13, 4) : 0.0000;

                    $subtotal += $lineaTotal;
                    $totalIva += $ivaLinea;

                    $itemsCalculados[] = [
                        'producto_id' => $prodId,
                        'cantidad' => $cant,
                        'costo_unitario' => $costoUnit,
                        'iva_linea' => $ivaLinea,
                        'total_linea' => $lineaTotal + $ivaLinea,
                        'numero_lote' => $lote,
                        'fecha_vencimiento' => $vence
                    ];
                }

                $totalCompra = $subtotal + $totalIva;
                $fechaVence = $esCredito ? date('Y-m-d', strtotime("+$diasCredito days")) : null;

                // 1. Insertar factura de compra
                $stmtIns = $pdo->prepare("
                    INSERT INTO compras_facturas (
                        proveedor_id, bodega_id, usuario_id, numero_factura_proveedor, tipo_documento,
                        fecha_factura, es_credito, dias_credito, fecha_vencimiento, subtotal, iva, total,
                        saldo_pendiente, estado
                    ) VALUES (
                        :prov, :bodega, :user, :num, :tipo,
                        :fecha, :credito, :dias, :vence, :sub, :iva, :total,
                        :saldo, :estado
                    ) RETURNING id
                ");
                $stmtIns->execute([
                    'prov' => $proveedorId,
                    'bodega' => $bodegaId,
                    'user' => $userId,
                    'num' => $numeroFactura,
                    'tipo' => $tipoDocumento,
                    'fecha' => $fechaFactura,
                    'credito' => $esCredito ? 1 : 0,
                    'dias' => $diasCredito,
                    'vence' => $fechaVence,
                    'sub' => $subtotal,
                    'iva' => $totalIva,
                    'total' => $totalCompra,
                    'saldo' => $esCredito ? $totalCompra : 0.0000,
                    'estado' => $esCredito ? 'PENDIENTE' : 'PAGADA'
                ]);
                $cId = (int)$stmtIns->fetchColumn();

                // 2. Procesar ítems: Insertar detalle, actualizar stock, Kardex y Costo Promedio Ponderado
                $stmtDet = $pdo->prepare("
                    INSERT INTO compras_facturas_detalle (
                        compra_id, producto_id, cantidad, costo_unitario, iva_linea, total_linea,
                        numero_lote, fecha_vencimiento
                    ) VALUES (
                        :cid, :pid, :cant, :costo, :iva, :tot, :lote, :vence
                    )
                ");

                $stmtProd = $pdo->prepare("SELECT costo_promedio, (SELECT COALESCE(SUM(cantidad), 0) FROM stock_bodega WHERE producto_id = :p) as stock_total FROM productos WHERE id = :p FOR UPDATE");
                $stmtUpdProdCost = $pdo->prepare("UPDATE productos SET costo_promedio = :nuevo_costo, updated_at = CURRENT_TIMESTAMP WHERE id = :p");

                $stmtStock = $pdo->prepare("SELECT cantidad FROM stock_bodega WHERE producto_id = :p AND bodega_id = :b FOR UPDATE");
                $stmtUpdStock = $pdo->prepare("
                    INSERT INTO stock_bodega (producto_id, bodega_id, cantidad, updated_at)
                    VALUES (:p, :b, :cant, CURRENT_TIMESTAMP)
                    ON CONFLICT (producto_id, bodega_id)
                    DO UPDATE SET cantidad = stock_bodega.cantidad + :cant, updated_at = CURRENT_TIMESTAMP
                ");

                $stmtKardex = $pdo->prepare("
                    INSERT INTO kardex_movimientos (
                        producto_id, bodega_id, tipo_movimiento, cantidad, costo_unitario,
                        stock_anterior, stock_posterior, documento_referencia, usuario_id, notas
                    ) VALUES (
                        :p, :b, 'COMPRA', :cant, :costo,
                        :ant, :post, :doc, :u, :notas
                    )
                ");

                foreach ($itemsCalculados as $ic) {
                    $pid = $ic['producto_id'];
                    $cantComp = $ic['cantidad'];
                    $costoComp = $ic['costo_unitario'];

                    $stmtDet->execute([
                        'cid' => $cId, 'pid' => $pid, 'cant' => $cantComp, 'costo' => $costoComp,
                        'iva' => $ic['iva_linea'], 'tot' => $ic['total_linea'],
                        'lote' => $ic['numero_lote'], 'vence' => $ic['fecha_vencimiento']
                    ]);

                    // Recalcular Costo Promedio Ponderado
                    // Nuevo Costo = ((StockTotal * CostoActual) + (CantidadComprada * CostoCompra)) / (StockTotal + CantidadComprada)
                    $stmtProd->execute(['p' => $pid]);
                    $prodRow = $stmtProd->fetch();
                    $stockTotal = (float)$prodRow['stock_total'];
                    $costoActual = (float)$prodRow['costo_promedio'];

                    $nuevoStockTotal = $stockTotal + $cantComp;
                    $nuevoCostoProm = ($nuevoStockTotal > 0)
                        ? (($stockTotal * $costoActual) + ($cantComp * $costoComp)) / $nuevoStockTotal
                        : $costoComp;

                    $stmtUpdProdCost->execute(['nuevo_costo' => round($nuevoCostoProm, 4), 'p' => $pid]);

                    // Obtener stock de la bodega específica para Kardex
                    $stmtStock->execute(['p' => $pid, 'b' => $bodegaId]);
                    $stockBodegaAnt = (float)$stmtStock->fetchColumn();
                    $stockBodegaPost = $stockBodegaAnt + $cantComp;

                    // Incrementar stock en bodega
                    $stmtUpdStock->execute(['p' => $pid, 'b' => $bodegaId, 'cant' => $cantComp]);

                    // Inserción inmutable en Kardex
                    $stmtKardex->execute([
                        'p' => $pid, 'b' => $bodegaId, 'cant' => $cantComp, 'costo' => $costoComp,
                        'ant' => $stockBodegaAnt, 'post' => $stockBodegaPost,
                        'doc' => 'COMP-' . $numeroFactura, 'u' => $userId,
                        'notas' => "Recepción de compra Factura #{$numeroFactura}"
                    ]);

                    // Si trae lote, registrarlo
                    if (!empty($ic['numero_lote']) && !empty($ic['fecha_vencimiento'])) {
                        $stmtLote = $pdo->prepare("
                            INSERT INTO lotes (producto_id, bodega_id, numero_lote, fecha_vencimiento, cantidad, activo)
                            VALUES (:p, :b, :lote, :vence, :cant, TRUE)
                            ON CONFLICT (producto_id, bodega_id, numero_lote)
                            DO UPDATE SET cantidad = lotes.cantidad + :cant, activo = TRUE
                        ");
                        $stmtLote->execute([
                            'p' => $pid, 'b' => $bodegaId, 'lote' => $ic['numero_lote'],
                            'vence' => $ic['fecha_vencimiento'], 'cant' => $cantComp
                        ]);
                    }
                }

                // 3. Asiento contable de compra
                $stmtAst = $pdo->prepare("
                    INSERT INTO asientos_contables (numero_asiento, fecha, concepto, origen_modulo, referencia_id, usuario_id, total_debe, total_haber)
                    VALUES (:num, :fecha, :concepto, 'COMPRAS', :ref, :u, :debe, :haber)
                    RETURNING id
                ");
                $numAst = 'AST-COMP-' . $cId;
                $stmtAst->execute([
                    'num' => $numAst,
                    'fecha' => $fechaFactura,
                    'concepto' => "Compra de mercaderías Factura #{$numeroFactura}",
                    'ref' => (string)$cId,
                    'u' => $userId,
                    'debe' => $totalCompra,
                    'haber' => $totalCompra
                ]);
                $astId = (int)$stmtAst->fetchColumn();

                $stmtLin = $pdo->prepare("INSERT INTO asiento_lineas (asiento_id, cuenta_id, debe, haber, concepto_linea) VALUES (:ast, :cta, :debe, :haber, :con)");
                // Inventario Cuenta 1105 (id 4)
                $stmtLin->execute(['ast' => $astId, 'cta' => 4, 'debe' => $subtotal, 'haber' => 0.0, 'con' => 'Ingreso de inventario al costo']);
                // IVA Crédito Fiscal Cuenta 1108 (id 5) si aplica
                if ($totalIva > 0) {
                    $stmtLin->execute(['ast' => $astId, 'cta' => 5, 'debe' => $totalIva, 'haber' => 0.0, 'con' => 'IVA Crédito Fiscal soportado']);
                }
                // Proveedores (2101 / id 6) si es crédito, o Bancos/Caja (id 2 / 1) si es contado
                $ctaHaber = $esCredito ? 6 : 2;
                $stmtLin->execute(['ast' => $astId, 'cta' => $ctaHaber, 'debe' => 0.0, 'haber' => $totalCompra, 'con' => $esCredito ? 'Cuenta por pagar proveedor' : 'Pago de factura al contado']);

                Audit::log('CREATE', 'compras_facturas', (string)$cId, null, [
                    'factura' => $numeroFactura,
                    'total' => $totalCompra
                ], "Compra de mercadería ingresada a inventario");

                return $cId;
            });

            Response::success(['compra_id' => $compraId], 'Factura de compra registrada con éxito e inventario actualizado');
        } catch (Throwable $e) {
            Response::error($e->getMessage(), 400);
        }
    }
}
