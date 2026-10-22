<?php
declare(strict_types=1);

namespace App\Pos;

use App\Shared\Database;
use App\Shared\Auth;
use App\Shared\Audit;
use App\Shared\Response;
use PDO;
use Throwable;

class PosController
{
    /**
     * Búsqueda predictiva rápida de productos (< 300 ms)
     */
    public function searchProducts(): void
    {
        $q = trim($_GET['q'] ?? '');
        if ($q === '') {
            Response::json(['products' => []]);
        }

        $pdo = Database::getConnection();
        // Búsqueda por código de barra exacto o nombre ILIKE
        $sql = "
            SELECT p.id, p.codigo_interno, p.nombre, p.presentacion, p.unidad_medida,
                   p.precio_venta, p.costo_promedio, p.es_pesable, p.es_combo, p.maneja_lotes,
                   COALESCE(sb.cantidad, 0) as stock_actual,
                   cb.codigo_barra, imp.porcentaje as iva_porcentaje
            FROM productos p
            LEFT JOIN producto_codigos_barra cb ON cb.producto_id = p.id
            LEFT JOIN stock_bodega sb ON sb.producto_id = p.id AND sb.bodega_id = 1 -- Bodega Piso de Venta
            LEFT JOIN impuestos imp ON imp.id = p.impuesto_id
            WHERE p.activo = TRUE
              AND (
                  cb.codigo_barra = :q_exact
                  OR p.codigo_interno = :q_exact
                  OR p.nombre ILIKE :q_like
              )
            ORDER BY (cb.codigo_barra = :q_exact) DESC, p.nombre ASC
            LIMIT 25
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'q_exact' => $q,
            'q_like' => '%' . $q . '%'
        ]);

        $rows = $stmt->fetchAll();
        // Agrupar en caso de múltiples códigos de barra
        $products = [];
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            if (!isset($products[$id])) {
                $products[$id] = [
                    'id' => $id,
                    'codigo_interno' => $r['codigo_interno'],
                    'nombre' => $r['nombre'],
                    'presentacion' => $r['presentacion'],
                    'unidad_medida' => $r['unidad_medida'],
                    'precio_venta' => (float)$r['precio_venta'],
                    'costo_promedio' => (float)$r['costo_promedio'],
                    'es_pesable' => (bool)$r['es_pesable'],
                    'es_combo' => (bool)$r['es_combo'],
                    'maneja_lotes' => (bool)$r['maneja_lotes'],
                    'stock_actual' => (float)$r['stock_actual'],
                    'iva_porcentaje' => (float)$r['iva_porcentaje'],
                    'codigos' => []
                ];
            }
            if (!empty($r['codigo_barra'])) {
                $products[$id]['codigos'][] = $r['codigo_barra'];
            }
        }

        Response::json(['products' => array_values($products)]);
    }

    /**
     * Búsqueda rápida de clientes por nombre, NIT o DUI
     */
    public function searchClients(): void
    {
        $q = trim($_GET['q'] ?? '');
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("
            SELECT id, nombre, nit, nrc, dui, direccion, telefono, correo, aplica_credito, limite_credito, saldo_deudor
            FROM clientes
            WHERE activo = TRUE
              AND (nombre ILIKE :q OR nit ILIKE :q OR dui ILIKE :q OR nrc ILIKE :q)
            ORDER BY id ASC
            LIMIT 15
        ");
        $stmt->execute(['q' => '%' . $q . '%']);
        Response::json(['clients' => $stmt->fetchAll()]);
    }

    /**
     * Crear cliente rápido desde el POS
     */
    public function createClient(): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $nombre = trim($input['nombre'] ?? '');
        if ($nombre === '') {
            Response::error('El nombre del cliente es obligatorio');
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            INSERT INTO clientes (nombre, nit, nrc, dui, direccion, telefono, correo, aplica_credito, limite_credito)
            VALUES (:nombre, :nit, :nrc, :dui, :direccion, :telefono, :correo, :aplica_credito, :limite_credito)
            RETURNING id, nombre, nit, nrc, dui, aplica_credito, limite_credito, saldo_deudor
        ");
        $stmt->execute([
            'nombre' => $nombre,
            'nit' => !empty($input['nit']) ? trim($input['nit']) : null,
            'nrc' => !empty($input['nrc']) ? trim($input['nrc']) : null,
            'dui' => !empty($input['dui']) ? trim($input['dui']) : null,
            'direccion' => !empty($input['direccion']) ? trim($input['direccion']) : 'Ciudad',
            'telefono' => !empty($input['telefono']) ? trim($input['telefono']) : null,
            'correo' => !empty($input['correo']) ? trim($input['correo']) : null,
            'aplica_credito' => !empty($input['aplica_credito']) ? true : false,
            'limite_credito' => !empty($input['limite_credito']) ? (float)$input['limite_credito'] : 0.00
        ]);

        $client = $stmt->fetch();
        Audit::log('CREATE', 'clientes', (string)$client['id'], null, $client, "Cliente creado en POS");
        Response::success(['client' => $client], 'Cliente registrado exitosamente');
    }

    /**
     * Procesar una venta en el Punto de Venta (POS)
     */
    public function checkout(): void
    {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            Response::error('Datos de venta inválidos');
        }

        $items = $input['items'] ?? [];
        $pagos = $input['pagos'] ?? [];
        $clienteId = (int)($input['cliente_id'] ?? 1); // 1 = Consumidor Final
        $tipoDocumento = $input['tipo_documento'] ?? '01'; // 01 Factura, 03 CCF
        $descuentoGlobal = (float)($input['descuento_global'] ?? 0.00);
        $supervisorPin = $input['supervisor_pin'] ?? null;

        if (empty($items)) {
            Response::error('El carrito no puede estar vacío');
        }
        if (empty($pagos)) {
            Response::error('Debe registrar al menos un método de pago');
        }

        $userId = Auth::id();
        if (!$userId) {
            Response::error('Usuario no autenticado', 401);
        }

        // Obtener sesión de caja abierta
        $pdo = Database::getConnection();
        $stmtSesion = $pdo->prepare("
            SELECT id, caja_id FROM caja_sesiones
            WHERE usuario_id = :usuario_id AND estado = 'ABIERTA'
            ORDER BY id DESC LIMIT 1
        ");
        $stmtSesion->execute(['usuario_id' => $userId]);
        $sesion = $stmtSesion->fetch();

        if (!$sesion) {
            Response::error('No tienes ninguna sesión de caja abierta confirmada. Un Gerente o Administrador debe autorizar y aperturar tu turno de caja antes de realizar cobros o transacciones.', 403);
        }
        $sesionId = (int)$sesion['id'];
        $bodegaId = 1; // Bodega Piso de Venta / Góndola

        // Ejecutar venta dentro de transacción atómica
        try {
            $resultadoVenta = Database::transaction(function (PDO $pdo) use (
                $items, $pagos, $clienteId, $tipoDocumento, $descuentoGlobal,
                $supervisorPin, $userId, $sesionId, $bodegaId
            ) {
                // 1. Validar montos y calcular subtotal, IVA, total
                $subtotalGravado = 0.0000;
                $subtotalExento = 0.0000;
                $totalDescuentoItems = 0.0000;
                $totalCostoVenta = 0.0000;
                $itemsCalculados = [];

                foreach ($items as $item) {
                    $prodId = (int)$item['producto_id'];
                    $cant = (float)$item['cantidad'];
                    $descLine = (float)($item['descuento'] ?? 0.00);

                    if ($cant <= 0 || $cant > 5000) {
                        throw new \Exception("Cantidad no válida para producto ID {$prodId}. Rango permitido: 0.01 a 5,000");
                    }
                    if ($descLine < 0) {
                        throw new \Exception("El descuento no puede ser negativo");
                    }

                    // Bloquear fila del producto y stock para concurrencia estricta
                    $stmtProd = $pdo->prepare("
                        SELECT p.id, p.nombre, p.precio_venta, p.costo_promedio, imp.porcentaje as iva_porcentaje,
                               COALESCE(sb.cantidad, 0) as stock_actual
                        FROM productos p
                        LEFT JOIN impuestos imp ON imp.id = p.impuesto_id
                        LEFT JOIN stock_bodega sb ON sb.producto_id = p.id AND sb.bodega_id = :bodega_id
                        WHERE p.id = :id AND p.activo = TRUE
                        FOR UPDATE OF p
                    ");
                    $stmtProd->execute(['id' => $prodId, 'bodega_id' => $bodegaId]);
                    $prod = $stmtProd->fetch();

                    if (!$prod) {
                        throw new \Exception("Producto ID {$prodId} no existe o está inactivo");
                    }

                    $stockActual = (float)$prod['stock_actual'];
                    if ($stockActual < $cant) {
                        throw new \Exception("Stock insuficiente para '{$prod['nombre']}'. Disponible: {$stockActual}, Solicitado: {$cant}");
                    }

                    $precioUnit = (float)$prod['precio_venta'];
                    if ($precioUnit <= 0 || $precioUnit > 2500.00) {
                        throw new \Exception("El precio unitario de '{$prod['nombre']}' está fuera de rango permitido ($" . number_format($precioUnit, 2) . ")");
                    }
                    $costoUnit = (float)$prod['costo_promedio'];
                    $ivaPorc = (float)$prod['iva_porcentaje'];

                    $lineaBruta = $cant * $precioUnit;
                    $lineaNeta = max(0, $lineaBruta - $descLine);

                    // Si el precio ya incluye IVA (precio al público) o es gravado
                    // En El Salvador para Consumidor Final el precio mostrado ya incluye 13% de IVA
                    $subtotalGravado += $lineaNeta;
                    $totalDescuentoItems += $descLine;
                    $totalCostoVenta += ($cant * $costoUnit);

                    $itemsCalculados[] = [
                        'producto_id' => $prodId,
                        'nombre' => $prod['nombre'],
                        'cantidad' => $cant,
                        'precio_unitario' => $precioUnit,
                        'costo_unitario' => $costoUnit,
                        'descuento' => $descLine,
                        'total_linea' => $lineaNeta,
                        'stock_anterior' => $stockActual,
                        'stock_posterior' => $stockActual - $cant
                    ];
                }

                $descuentoTotal = $totalDescuentoItems + $descuentoGlobal;
                $totalVenta = max(0, $subtotalGravado - $descuentoGlobal);

                // Cálculo de IVA desglosado (en El Salvador: Base = Total / 1.13, IVA = Total - Base)
                $ivaCalculado = round($totalVenta - ($totalVenta / 1.13), 4);
                $netoGravado = round($totalVenta / 1.13, 4);

                // 2. Validar pagos
                $sumaPagos = 0.00;
                $totalRecibidoEfectivo = 0.00;
                $vueltoTotal = 0.00;

                foreach ($pagos as $p) {
                    $monto = (float)$p['monto'];
                    $metodo = strtoupper(trim($p['metodo_pago']));
                    $sumaPagos += $monto;

                    if ($metodo === 'EFECTIVO') {
                        $recibido = (float)($p['monto_recibido'] ?? $monto);
                        $totalRecibidoEfectivo += $recibido;
                        $vueltoTotal += max(0, $recibido - $monto);
                    } elseif ($metodo === 'CREDITO') {
                        // Validar crédito del cliente
                        $stmtCli = $pdo->prepare("SELECT aplica_credito, limite_credito, saldo_deudor FROM clientes WHERE id = :id FOR UPDATE");
                        $stmtCli->execute(['id' => $clienteId]);
                        $cli = $stmtCli->fetch();
                        if (!$cli || !$cli['aplica_credito']) {
                            throw new \Exception("El cliente seleccionado no tiene crédito autorizado");
                        }
                        $disponible = (float)$cli['limite_credito'] - (float)$cli['saldo_deudor'];
                        if ($monto > $disponible) {
                            throw new \Exception("Límite de crédito excedido. Disponible: $" . number_format($disponible, 2));
                        }
                        // Actualizar saldo deudor
                        $stmtUpdCli = $pdo->prepare("UPDATE clientes SET saldo_deudor = saldo_deudor + :monto WHERE id = :id");
                        $stmtUpdCli->execute(['monto' => $monto, 'id' => $clienteId]);
                    }
                }

                if (round($sumaPagos, 2) < round($totalVenta, 2)) {
                    throw new \Exception("Monto pagado ($" . number_format($sumaPagos, 2) . ") menor al total de la venta ($" . number_format($totalVenta, 2) . ")");
                }

                // 3. Generar número de venta y código DTE oficial (UUID v4)
                $numVenta = 'VTA-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
                $uuidDte = strtoupper(sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                    mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                    mt_rand(0, 0xffff),
                    mt_rand(0, 0x0fff) | 0x4000,
                    mt_rand(0, 0x3fff) | 0x8000,
                    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
                ));
                $numeroControl = "DTE-{$tipoDocumento}-M001P001-" . str_pad((string)time(), 15, '0', STR_PAD_LEFT);

                // 4. Insertar encabezado de venta
                $stmtInsVenta = $pdo->prepare("
                    INSERT INTO ventas (
                        numero_venta, sesion_caja_id, usuario_cajero_id, cliente_id,
                        tipo_documento, numero_control_mh, codigo_generacion_mh, estado_dte,
                        subtotal_gravado, subtotal_exento, descuento, iva, total, estado, fecha_hora
                    ) VALUES (
                        :num, :sesion, :cajero, :cliente,
                        :tipo, :num_control, :uuid, 'LOCAL',
                        :sub_grav, :sub_ex, :desc, :iva, :total, 'COMPLETADA', CURRENT_TIMESTAMP
                    ) RETURNING id, fecha_hora
                ");
                $stmtInsVenta->execute([
                    'num' => $numVenta,
                    'sesion' => $sesionId,
                    'cajero' => $userId,
                    'cliente' => $clienteId,
                    'tipo' => $tipoDocumento,
                    'num_control' => $numeroControl,
                    'uuid' => $uuidDte,
                    'sub_grav' => $netoGravado,
                    'sub_ex' => $subtotalExento,
                    'desc' => $descuentoTotal,
                    'iva' => $ivaCalculado,
                    'total' => $totalVenta
                ]);
                $ventaRow = $stmtInsVenta->fetch();
                $ventaId = (int)$ventaRow['id'];

                // 5. Insertar líneas de venta, actualizar stock y registrar Kardex inmutable
                $stmtInsItem = $pdo->prepare("
                    INSERT INTO venta_items (venta_id, producto_id, bodega_id, cantidad, precio_unitario, costo_unitario, descuento, iva_linea, total_linea)
                    VALUES (:venta_id, :prod_id, :bodega_id, :cant, :precio, :costo, :desc, :iva, :total)
                ");

                $stmtUpdStock = $pdo->prepare("
                    UPDATE stock_bodega
                    SET cantidad = cantidad - :cant, updated_at = CURRENT_TIMESTAMP
                    WHERE producto_id = :prod_id AND bodega_id = :bodega_id
                ");

                $stmtInsKardex = $pdo->prepare("
                    INSERT INTO kardex_movimientos (
                        producto_id, bodega_id, tipo_movimiento, cantidad, costo_unitario,
                        stock_anterior, stock_posterior, documento_referencia, usuario_id, notas
                    ) VALUES (
                        :prod_id, :bodega_id, 'VENTA', :cant_negativa, :costo,
                        :stock_ant, :stock_post, :doc_ref, :usuario_id, 'Salida por venta de mostrador'
                    )
                ");

                foreach ($itemsCalculados as $ic) {
                    $stmtInsItem->execute([
                        'venta_id' => $ventaId,
                        'prod_id' => $ic['producto_id'],
                        'bodega_id' => $bodegaId,
                        'cant' => $ic['cantidad'],
                        'precio' => $ic['precio_unitario'],
                        'costo' => $ic['costo_unitario'],
                        'desc' => $ic['descuento'],
                        'iva' => round($ic['total_linea'] - ($ic['total_linea'] / 1.13), 4),
                        'total' => $ic['total_linea']
                    ]);

                    $stmtUpdStock->execute([
                        'cant' => $ic['cantidad'],
                        'prod_id' => $ic['producto_id'],
                        'bodega_id' => $bodegaId
                    ]);

                    $stmtInsKardex->execute([
                        'prod_id' => $ic['producto_id'],
                        'bodega_id' => $bodegaId,
                        'cant_negativa' => -$ic['cantidad'],
                        'costo' => $ic['costo_unitario'],
                        'stock_ant' => $ic['stock_anterior'],
                        'stock_post' => $ic['stock_posterior'],
                        'doc_ref' => $numVenta,
                        'usuario_id' => $userId
                    ]);
                }

                // 6. Insertar formas de pago
                $stmtInsPago = $pdo->prepare("
                    INSERT INTO venta_pagos (venta_id, metodo_pago, monto, monto_recibido, vuelto, referencia)
                    VALUES (:venta_id, :metodo, :monto, :recibido, :vuelto, :referencia)
                ");

                foreach ($pagos as $p) {
                    $metodo = strtoupper(trim($p['metodo_pago']));
                    $monto = (float)$p['monto'];
                    $recibido = ($metodo === 'EFECTIVO') ? (float)($p['monto_recibido'] ?? $monto) : $monto;
                    $vuelto = ($metodo === 'EFECTIVO') ? max(0, $recibido - $monto) : 0.00;
                    $ref = !empty($p['referencia']) ? trim($p['referencia']) : null;

                    $stmtInsPago->execute([
                        'venta_id' => $ventaId,
                        'metodo' => $metodo,
                        'monto' => $monto,
                        'recibido' => $recibido,
                        'vuelto' => $vuelto,
                        'referencia' => $ref
                    ]);
                }

                // 7. Partida doble contable automática
                $stmtAsiento = $pdo->prepare("
                    INSERT INTO asientos_contables (numero_asiento, fecha, concepto, origen_modulo, referencia_id, usuario_id, total_debe, total_haber)
                    VALUES (:num, CURRENT_DATE, :concepto, 'VENTAS', :ref, :usuario, :debe, :haber)
                    RETURNING id
                ");
                $numAsiento = 'AST-VTA-' . $ventaId;
                $stmtAsiento->execute([
                    'num' => $numAsiento,
                    'concepto' => "Venta de mostrador ticket {$numVenta}",
                    'ref' => (string)$ventaId,
                    'usuario' => $userId,
                    'debe' => $totalVenta + $totalCostoVenta,
                    'haber' => $totalVenta + $totalCostoVenta
                ]);
                $asientoId = (int)$stmtAsiento->fetchColumn();

                $stmtLinea = $pdo->prepare("
                    INSERT INTO asiento_lineas (asiento_id, cuenta_id, debe, haber, concepto_linea)
                    VALUES (:asiento, :cuenta, :debe, :haber, :concepto)
                ");

                // Línea 1: Caja / Bancos / CxC (Debe = $totalVenta)
                // Cuentas: 1101 Caja (id 1), 1102 Bancos (id 2), 1103 CxC (id 3)
                $cuentaCobroId = 1; // Default Caja General
                foreach ($pagos as $p) {
                    $m = strtoupper(trim($p['metodo_pago']));
                    $cta = ($m === 'TARJETA' || $m === 'TRANSFERENCIA') ? 2 : (($m === 'CREDITO') ? 3 : 1);
                    $stmtLinea->execute([
                        'asiento' => $asientoId,
                        'cuenta' => $cta,
                        'debe' => (float)$p['monto'],
                        'haber' => 0.0000,
                        'concepto' => "Ingreso por cobro {$m}"
                    ]);
                }

                // Línea 2: Ingresos por Ventas (Haber = $netoGravado) Cuenta 4101 (id 9)
                $stmtLinea->execute([
                    'asiento' => $asientoId,
                    'cuenta' => 9,
                    'debe' => 0.0000,
                    'haber' => $netoGravado,
                    'concepto' => "Venta neta mercadería"
                ]);

                // Línea 3: IVA Débito Fiscal (Haber = $ivaCalculado) Cuenta 2105 (id 7)
                $stmtLinea->execute([
                    'asiento' => $asientoId,
                    'cuenta' => 7,
                    'debe' => 0.0000,
                    'haber' => $ivaCalculado,
                    'concepto' => "IVA Débito 13% ticket {$numVenta}"
                ]);

                // Línea 4: Costo de Ventas (Debe = $totalCostoVenta) Cuenta 5101 (id 10)
                $stmtLinea->execute([
                    'asiento' => $asientoId,
                    'cuenta' => 10,
                    'debe' => $totalCostoVenta,
                    'haber' => 0.0000,
                    'concepto' => "Costo de mercancía vendida"
                ]);

                // Línea 5: Inventario Mercaderías (Haber = $totalCostoVenta) Cuenta 1105 (id 4)
                $stmtLinea->execute([
                    'asiento' => $asientoId,
                    'cuenta' => 4,
                    'debe' => 0.0000,
                    'haber' => $totalCostoVenta,
                    'concepto' => "Descargo de inventario por venta"
                ]);

                Audit::log('CREATE', 'ventas', (string)$ventaId, null, [
                    'numero_venta' => $numVenta,
                    'total' => $totalVenta,
                    'items_count' => count($items)
                ], "Venta completada en POS");

                return [
                    'venta_id' => $ventaId,
                    'numero_venta' => $numVenta,
                    'codigo_generacion' => $uuidDte,
                    'numero_control' => $numeroControl,
                    'fecha_hora' => $ventaRow['fecha_hora'],
                    'total' => $totalVenta,
                    'iva' => $ivaCalculado,
                    'subtotal' => $netoGravado,
                    'vuelto' => $vueltoTotal,
                    'items' => $itemsCalculados
                ];
            });

            Response::success([
                'venta' => $resultadoVenta,
                'ticket_url' => "/pos/ticket?id={$resultadoVenta['venta_id']}"
            ], 'Venta procesada con éxito');

        } catch (Throwable $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    /**
     * Renderizado o salida para ticket ESC/POS 80mm
     */
    public function getTicket(): void
    {
        $ventaId = (int)($_GET['id'] ?? 0);
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("
            SELECT v.*, u.nombre_completo as cajero_nombre, c.nombre as cliente_nombre,
                   c.nit as cliente_nit, c.nrc as cliente_nrc, c.dui as cliente_dui
            FROM ventas v
            JOIN usuarios u ON u.id = v.usuario_cajero_id
            JOIN clientes c ON c.id = v.cliente_id
            WHERE v.id = :id
        ");
        $stmt->execute(['id' => $ventaId]);
        $venta = $stmt->fetch();

        if (!$venta) {
            die("Venta no encontrada");
        }

        $stmtItems = $pdo->prepare("
            SELECT vi.*, p.nombre, p.codigo_interno
            FROM venta_items vi
            JOIN productos p ON p.id = vi.producto_id
            WHERE vi.venta_id = :id
        ");
        $stmtItems->execute(['id' => $ventaId]);
        $items = $stmtItems->fetchAll();

        $stmtPagos = $pdo->prepare("SELECT * FROM venta_pagos WHERE venta_id = :id");
        $stmtPagos->execute(['id' => $ventaId]);
        $pagos = $stmtPagos->fetchAll();

        $empresa = $pdo->query("SELECT * FROM configuracion_empresa LIMIT 1")->fetch();

        Response::json([
            'venta' => $venta,
            'items' => $items,
            'pagos' => $pagos,
            'empresa' => $empresa
        ]);
    }
}
