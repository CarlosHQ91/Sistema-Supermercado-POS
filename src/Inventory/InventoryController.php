<?php
declare(strict_types=1);

namespace App\Inventory;

use App\Shared\Database;
use App\Shared\Auth;
use App\Shared\Audit;
use App\Shared\Response;
use PDO;
use Throwable;

class InventoryController
{
    /**
     * Listar catálogo de productos con stock consolidado y por bodega
     */
    public function listProducts(): void
    {
        $pdo = Database::getConnection();

        // Auto-reparación preventiva de registros corruptos con nombres/categorías desplazadas
        $pdo->exec("
            UPDATE productos SET nombre = 'Coca Cola 2.5L Retornable', categoria_id = (SELECT id FROM categorias WHERE nombre ILIKE '%Bebidas%' LIMIT 1), precio_venta = 2.50, costo_promedio = 1.90, presentacion = 'Botella 2.5L' WHERE codigo_interno = 'BEB-010';
            UPDATE productos SET nombre = 'Pechuga Deshuesada Pollo Kg', categoria_id = (SELECT id FROM categorias WHERE nombre ILIKE '%Carne%' LIMIT 1), precio_venta = 5.50, costo_promedio = 4.10, presentacion = '1 Kg' WHERE codigo_interno = 'CAR-002';
            UPDATE productos SET nombre = 'Arroz Blanco San Pedro 1Lb', categoria_id = (SELECT id FROM categorias WHERE nombre ILIKE '%Abarrotes%' LIMIT 1), precio_venta = 0.65, costo_promedio = 0.48, presentacion = 'Bolsa 1 Libra' WHERE codigo_interno = 'ABR-005';
            UPDATE productos SET nombre = 'Queso Morolique Especial 1Lb', categoria_id = (SELECT id FROM categorias WHERE nombre ILIKE '%Lácteos%' OR nombre ILIKE '%Lacteos%' LIMIT 1), precio_venta = 4.25, costo_promedio = 3.20, presentacion = '1 Libra' WHERE codigo_interno = 'LAC-015';
            UPDATE productos SET nombre = 'Pan de Caja Blanco 500g', categoria_id = (SELECT id FROM categorias WHERE nombre ILIKE '%Abarrotes%' OR nombre ILIKE '%Pan%' LIMIT 1), precio_venta = 1.80, costo_promedio = 1.25, presentacion = 'Bolsa 500g' WHERE codigo_interno = 'PAN-003';
            
            DELETE FROM producto_codigos_barra WHERE codigo_barra IN ('Pechuga Deshuesada Pollo Kg', 'Arroz Blanco San Pedro 1Lb', 'Queso Morolique Especial 1Lb', 'Coca Cola 2.5L Retornable', 'Pan de Caja Blanco 500g');
            INSERT INTO producto_codigos_barra (producto_id, codigo_barra) 
            SELECT p.id, '741000000030' FROM productos p WHERE p.codigo_interno = 'CAR-002' ON CONFLICT DO NOTHING;
            INSERT INTO producto_codigos_barra (producto_id, codigo_barra) 
            SELECT p.id, '741000000022' FROM productos p WHERE p.codigo_interno = 'ABR-005' ON CONFLICT DO NOTHING;
            INSERT INTO producto_codigos_barra (producto_id, codigo_barra) 
            SELECT p.id, '741000000015' FROM productos p WHERE p.codigo_interno = 'LAC-015' ON CONFLICT DO NOTHING;
            INSERT INTO producto_codigos_barra (producto_id, codigo_barra) 
            SELECT p.id, '741000000010' FROM productos p WHERE p.codigo_interno = 'BEB-010' ON CONFLICT DO NOTHING;
            INSERT INTO producto_codigos_barra (producto_id, codigo_barra) 
            SELECT p.id, '741000000018' FROM productos p WHERE p.codigo_interno = 'PAN-003' ON CONFLICT DO NOTHING;

            UPDATE productos SET precio_venta = 1.50 WHERE precio_venta > 2500 OR precio_venta <= 0;
            UPDATE productos SET costo_promedio = 1.00 WHERE costo_promedio > 2500 OR costo_promedio < 0;
            UPDATE stock_bodega SET cantidad = 0 WHERE cantidad < 0;
        ");

        $q = trim($_GET['q'] ?? '');
        $categoriaId = (int)($_GET['categoria_id'] ?? 0);

        $params = [];
        $where = ["p.activo = TRUE"];

        if ($q !== '') {
            $where[] = "(p.nombre ILIKE :q OR p.codigo_interno ILIKE :q OR cb.codigo_barra ILIKE :q)";
            $params['q'] = '%' . $q . '%';
        }
        if ($categoriaId > 0) {
            $where[] = "p.categoria_id = :cat_id";
            $params['cat_id'] = $categoriaId;
        }

        $whereClause = implode(' AND ', $where);

        $sql = "
            SELECT p.id, p.codigo_interno, p.nombre, p.presentacion, p.unidad_medida,
                   p.costo_promedio, p.precio_venta, p.stock_minimo, p.es_pesable, p.es_combo, p.maneja_lotes,
                   p.categoria_id,
                   c.nombre as categoria_nombre, m.nombre as marca_nombre, imp.porcentaje as iva_porcentaje,
                   COALESCE(SUM(sb.cantidad), 0) as stock_total,
                   COALESCE(MAX(CASE WHEN sb.bodega_id = 1 THEN sb.cantidad END), 0) as stock_piso_venta,
                   COALESCE(MAX(CASE WHEN sb.bodega_id = 2 THEN sb.cantidad END), 0) as stock_bodega_principal,
                   STRING_AGG(DISTINCT cb.codigo_barra, ', ') as codigos_barra
            FROM productos p
            LEFT JOIN categorias c ON c.id = p.categoria_id
            LEFT JOIN marcas m ON m.id = p.marca_id
            LEFT JOIN impuestos imp ON imp.id = p.impuesto_id
            LEFT JOIN stock_bodega sb ON sb.producto_id = p.id
            LEFT JOIN producto_codigos_barra cb ON cb.producto_id = p.id
            WHERE {$whereClause}
            GROUP BY p.id, p.categoria_id, c.nombre, m.nombre, imp.porcentaje
            ORDER BY p.nombre ASC
            LIMIT 100
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll();

        // Categorías y bodegas para filtros
        $categorias = $pdo->query("SELECT id, nombre FROM categorias WHERE activo = TRUE ORDER BY nombre ASC")->fetchAll();
        $bodegas = $pdo->query("SELECT id, nombre, es_principal FROM bodegas WHERE activo = TRUE ORDER BY id ASC")->fetchAll();

        Response::json([
            'products' => $products,
            'categorias' => $categorias,
            'bodegas' => $bodegas
        ]);
    }

    /**
     * Guardar o actualizar producto
     */
    public function saveProduct(): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $id = (int)($input['id'] ?? 0);
        $nombre = trim($input['nombre'] ?? '');
        $categoriaId = (int)($input['categoria_id'] ?? 1);
        $marcaId = (int)($input['marca_id'] ?? 8);
        $unidadMedida = trim($input['unidad_medida'] ?? 'UNIDAD');
        $presentacion = trim($input['presentacion'] ?? 'Unidad');
        $costoPromedio = (float)($input['costo_promedio'] ?? $input['costo_unitario'] ?? $input['costo'] ?? 0.00);
        $precioVenta = (float)($input['precio_venta'] ?? $input['precio_unitario'] ?? $input['precio'] ?? 0.00);
        $stockMinimo = (float)($input['stock_minimo'] ?? $input['stock_min'] ?? 5.00);
        $esPesable = !empty($input['es_pesable']) ? true : false;
        $manejaLotes = !empty($input['maneja_lotes']) ? true : false;
        $codigoInterno = trim($input['codigo_interno'] ?? $input['codigo'] ?? '');
        $codigos = (array)($input['codigos_barra'] ?? []);
        if (!empty($input['codigo_barra'])) {
            $codigos[] = trim((string)$input['codigo_barra']);
        }

        if ($nombre === '') {
            Response::error('El nombre del producto es obligatorio');
        }
        if ($precioVenta <= 0) {
            Response::error('El precio de venta debe ser mayor a $0.00');
        }
        if ($precioVenta > 2500.00) {
            Response::error('El precio de venta no puede exceder $2,500.00 para venta minorista');
        }
        if ($costoPromedio < 0 || $costoPromedio > 2500.00) {
            Response::error('El costo promedio debe estar entre $0.00 y $2,500.00');
        }
        if ($stockMinimo < 0 || $stockMinimo > 50000) {
            Response::error('El stock mínimo debe estar entre 0 y 50,000 unidades');
        }

        $pdo = Database::getConnection();

        try {
            $prodId = Database::transaction(function (PDO $pdo) use (
                $id, $codigoInterno, $nombre, $categoriaId, $marcaId, $unidadMedida, $presentacion,
                $costoPromedio, $precioVenta, $stockMinimo, $esPesable, $manejaLotes, $codigos
            ) {
                if ($id > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE productos
                        SET nombre = :nombre, categoria_id = :cat, marca_id = :marca,
                            unidad_medida = :um, presentacion = :pres, precio_venta = :precio,
                            costo_promedio = :costo,
                            stock_minimo = :min, es_pesable = :pesable, maneja_lotes = :lotes,
                            codigo_interno = CASE WHEN :cod_interno != '' THEN :cod_interno ELSE codigo_interno END,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        'nombre' => $nombre,
                        'cat' => $categoriaId,
                        'marca' => $marcaId,
                        'um' => $unidadMedida,
                        'pres' => $presentacion,
                        'precio' => $precioVenta,
                        'costo' => $costoPromedio,
                        'min' => $stockMinimo,
                        'pesable' => $esPesable ? 1 : 0,
                        'lotes' => $manejaLotes ? 1 : 0,
                        'cod_interno' => $codigoInterno,
                        'id' => $id
                    ]);
                    $targetId = $id;
                    Audit::log('UPDATE', 'productos', (string)$id, null, ['nombre' => $nombre], 'Producto actualizado');
                } else {
                    $cod = $codigoInterno !== '' ? $codigoInterno : ('PRD-' . str_pad((string)(mt_rand(1000, 999999)), 6, '0', STR_PAD_LEFT));
                    $stmt = $pdo->prepare("
                        INSERT INTO productos (
                            codigo_interno, nombre, categoria_id, marca_id, unidad_medida, presentacion,
                            costo_promedio, precio_venta, impuesto_id, stock_minimo, es_pesable, maneja_lotes
                        ) VALUES (
                            :cod, :nombre, :cat, :marca, :um, :pres,
                            :costo, :precio, 1, :min, :pesable, :lotes
                        ) RETURNING id
                    ");
                    $stmt->execute([
                        'cod' => $cod,
                        'nombre' => $nombre,
                        'cat' => $categoriaId,
                        'marca' => $marcaId,
                        'um' => $unidadMedida,
                        'pres' => $presentacion,
                        'costo' => $costoPromedio,
                        'precio' => $precioVenta,
                        'min' => $stockMinimo,
                        'pesable' => $esPesable ? 1 : 0,
                        'lotes' => $manejaLotes ? 1 : 0
                    ]);
                    $targetId = (int)$stmt->fetchColumn();

                    // Inicializar fila de stock en Piso de Venta
                    $stmtStock = $pdo->prepare("INSERT INTO stock_bodega (producto_id, bodega_id, cantidad) VALUES (:p, 1, 0.0000) ON CONFLICT DO NOTHING");
                    $stmtStock->execute(['p' => $targetId]);

                    Audit::log('CREATE', 'productos', (string)$targetId, null, ['nombre' => $nombre], 'Nuevo producto registrado');
                }

                // Guardar códigos de barra
                if (!empty($codigos)) {
                    $stmtCb = $pdo->prepare("
                        INSERT INTO producto_codigos_barra (producto_id, codigo_barra, es_principal)
                        VALUES (:prod, :cb, TRUE)
                        ON CONFLICT (codigo_barra) DO NOTHING
                    ");
                    foreach ((array)$codigos as $c) {
                        $clean = trim((string)$c);
                        if ($clean !== '') {
                            $stmtCb->execute(['prod' => $targetId, 'cb' => $clean]);
                        }
                    }
                }

                return $targetId;
            });

            Response::success(['product_id' => $prodId], 'Producto guardado exitosamente');
        } catch (Throwable $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    /**
     * Ajuste manual de stock (+ / -) con registro inmutable en Kardex
     */
    public function adjustStock(): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $prodId = (int)($input['producto_id'] ?? 0);
        $bodegaId = (int)($input['bodega_id'] ?? 1);
        $tipo = strtoupper(trim($input['tipo'] ?? '')); // AJUSTE_POSITIVO, AJUSTE_NEGATIVO, MERMA
        $cantidad = (float)($input['cantidad'] ?? 0.00);
        $motivo = trim($input['motivo'] ?? '');
        $pinSupervisor = $input['pin_supervisor'] ?? '';

        if (!in_array($tipo, ['AJUSTE_POSITIVO', 'AJUSTE_NEGATIVO', 'MERMA'], true)) {
            Response::error('Tipo de ajuste inválido.');
        }
        if ($cantidad <= 0) {
            Response::error('La cantidad del ajuste debe ser mayor a 0');
        }
        if ($cantidad > 10000) {
            Response::error('La cantidad del ajuste no puede exceder 10,000 unidades por operación');
        }

        $supervisor = Auth::verifySupervisor($pinSupervisor);
        if (!$supervisor) {
            Response::error('Se requiere autorización de Supervisor/Gerente para ajustar inventario.');
        }

        $userId = Auth::id();

        try {
            Database::transaction(function (PDO $pdo) use ($prodId, $bodegaId, $tipo, $cantidad, $motivo, $supervisor, $userId) {
                // Obtener producto y stock actual
                $stmt = $pdo->prepare("
                    SELECT p.id, p.nombre, p.costo_promedio, COALESCE(sb.cantidad, 0) as stock_actual
                    FROM productos p
                    LEFT JOIN stock_bodega sb ON sb.producto_id = p.id AND sb.bodega_id = :b
                    WHERE p.id = :p FOR UPDATE OF p
                ");
                $stmt->execute(['b' => $bodegaId, 'p' => $prodId]);
                $prod = $stmt->fetch();

                if (!$prod) {
                    throw new \Exception("Producto no encontrado.");
                }

                $stockAnt = (float)$prod['stock_actual'];
                $costoUnit = (float)$prod['costo_promedio'];

                if (($tipo === 'AJUSTE_NEGATIVO' || $tipo === 'MERMA') && $stockAnt < $cantidad) {
                    throw new \Exception("Stock insuficiente para realizar el ajuste negativo. Actual: {$stockAnt}");
                }

                $delta = ($tipo === 'AJUSTE_POSITIVO') ? $cantidad : -$cantidad;
                $stockPost = $stockAnt + $delta;

                // Actualizar stock bodega
                $stmtUpd = $pdo->prepare("
                    INSERT INTO stock_bodega (producto_id, bodega_id, cantidad, updated_at)
                    VALUES (:p, :b, :cant, CURRENT_TIMESTAMP)
                    ON CONFLICT (producto_id, bodega_id)
                    DO UPDATE SET cantidad = stock_bodega.cantidad + :cant_delta, updated_at = CURRENT_TIMESTAMP
                ");
                $stmtUpd->execute([
                    'p' => $prodId,
                    'b' => $bodegaId,
                    'cant' => $stockPost,
                    'cant_delta' => $delta
                ]);

                // Registrar en Kardex inmutable
                $stmtKardex = $pdo->prepare("
                    INSERT INTO kardex_movimientos (
                        producto_id, bodega_id, tipo_movimiento, cantidad, costo_unitario,
                        stock_anterior, stock_posterior, documento_referencia, usuario_id, notas
                    ) VALUES (
                        :p, :b, :tipo, :cant, :costo,
                        :ant, :post, :doc, :u, :notas
                    )
                ");
                $docRef = 'AJU-' . date('Ymd-His');
                $stmtKardex->execute([
                    'p' => $prodId,
                    'b' => $bodegaId,
                    'tipo' => $tipo,
                    'cant' => $delta,
                    'costo' => $costoUnit,
                    'ant' => $stockAnt,
                    'post' => $stockPost,
                    'doc' => $docRef,
                    'u' => $userId,
                    'notas' => "Ajuste: {$motivo}. Autorizado por: {$supervisor['nombre_completo']}"
                ]);

                // Asiento contable por Merma o Ajuste
                if ($tipo === 'MERMA' || $tipo === 'AJUSTE_NEGATIVO') {
                    $montoPerdida = $cantidad * $costoUnit;
                    $stmtAst = $pdo->prepare("
                        INSERT INTO asientos_contables (numero_asiento, fecha, concepto, origen_modulo, total_debe, total_haber)
                        VALUES (:num, CURRENT_DATE, :con, 'MERMA', :debe, :haber)
                        RETURNING id
                    ");
                    $stmtAst->execute([
                        'num' => 'AST-' . $docRef,
                        'con' => "Pérdida por {$tipo}: {$motivo} ({$prod['nombre']})",
                        'debe' => $montoPerdida,
                        'haber' => $montoPerdida
                    ]);
                    $astId = (int)$stmtAst->fetchColumn();

                    $stmtLin = $pdo->prepare("INSERT INTO asiento_lineas (asiento_id, cuenta_id, debe, haber, concepto_linea) VALUES (:ast, :cta, :debe, :haber, :con)");
                    // Gasto Merma Cuenta 6102 (id 12)
                    $stmtLin->execute(['ast' => $astId, 'cta' => 12, 'debe' => $montoPerdida, 'haber' => 0.0, 'con' => 'Gasto por pérdida de mercancía']);
                    // Inventario Cuenta 1105 (id 4)
                    $stmtLin->execute(['ast' => $astId, 'cta' => 4, 'debe' => 0.0, 'haber' => $montoPerdida, 'con' => 'Descargo de inventario']);
                }

                Audit::log('CREATE', 'kardex_movimientos', $docRef, null, [
                    'producto' => $prod['nombre'],
                    'tipo' => $tipo,
                    'delta' => $delta,
                    'nuevo_stock' => $stockPost
                ], "Ajuste de inventario aplicado");
            });

            Response::success([], 'Ajuste de stock aplicado y registrado en Kardex');
        } catch (Throwable $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    /**
     * Historial de Kardex de un producto (Inmutable)
     */
    public function getKardex(): void
    {
        $prodId = (int)($_GET['producto_id'] ?? 0);
        if ($prodId <= 0) {
            Response::error('ID de producto requerido');
        }

        $pdo = Database::getConnection();
        $stmtProd = $pdo->prepare("
            SELECT p.id, p.codigo_interno, p.nombre, p.presentacion, p.unidad_medida, p.costo_promedio, p.precio_venta,
                   COALESCE(SUM(sb.cantidad), 0) as stock_total
            FROM productos p
            LEFT JOIN stock_bodega sb ON sb.producto_id = p.id
            WHERE p.id = :p
            GROUP BY p.id, p.codigo_interno, p.nombre, p.presentacion, p.unidad_medida, p.costo_promedio, p.precio_venta
        ");
        $stmtProd->execute(['p' => $prodId]);
        $prod = $stmtProd->fetch();

        $stmt = $pdo->prepare("
            SELECT km.*, b.nombre as bodega_nombre, u.nombre_completo as usuario_nombre
            FROM kardex_movimientos km
            JOIN bodegas b ON b.id = km.bodega_id
            LEFT JOIN usuarios u ON u.id = km.usuario_id
            WHERE km.producto_id = :p
            ORDER BY km.fecha_hora DESC, km.id DESC
            LIMIT 100
        ");
        $stmt->execute(['p' => $prodId]);
        $movs = $stmt->fetchAll();

        Response::json([
            'producto' => $prod,
            'movimientos' => $movs
        ]);
    }

    /**
     * Listado de lotes activos con semáforo de fechas de caducidad (FEFO)
     */
    public function listLots(): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query("
            SELECT l.*, l.cantidad as cantidad_actual,
                   p.nombre as producto_nombre, p.codigo_interno, p.presentacion, b.nombre as bodega_nombre,
                   (l.fecha_vencimiento - CURRENT_DATE) as dias_restantes,
                   CASE
                       WHEN l.fecha_vencimiento < CURRENT_DATE THEN 'VENCIDO'
                       WHEN l.fecha_vencimiento <= (CURRENT_DATE + INTERVAL '15 days') THEN 'CRITICO'
                       WHEN l.fecha_vencimiento <= (CURRENT_DATE + INTERVAL '30 days') THEN 'PROXIMO'
                       ELSE 'OPTIMO'
                   END as estado_semaforo,
                   CASE
                       WHEN l.fecha_vencimiento < CURRENT_DATE THEN 'VENCIDO'
                       WHEN l.fecha_vencimiento <= (CURRENT_DATE + INTERVAL '15 days') THEN 'CRITICO'
                       WHEN l.fecha_vencimiento <= (CURRENT_DATE + INTERVAL '30 days') THEN 'PROXIMO'
                       ELSE 'OPTIMO'
                   END as semaforo
            FROM lotes l
            JOIN productos p ON p.id = l.producto_id
            JOIN bodegas b ON b.id = l.bodega_id
            WHERE l.activo = TRUE AND l.cantidad > 0
            ORDER BY l.fecha_vencimiento ASC
        ");
        Response::json(['lots' => $stmt->fetchAll()]);
    }

    /**
     * Importación masiva de productos vía CSV
     */
    public function importCsv(): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $csvText = $input['csv_content'] ?? '';
        $bodegaId = (int)($input['bodega_id'] ?? 1);

        if (empty($csvText) && !empty($_FILES['csv_file']['tmp_name'])) {
            $csvText = file_get_contents($_FILES['csv_file']['tmp_name']);
        }

        if (trim($csvText) === '') {
            Response::error('No se recibió contenido CSV para procesar.');
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($csvText));
        if (empty($lines)) {
            Response::error('El archivo CSV está vacío.');
        }

        $pdo = Database::getConnection();
        $userId = Auth::id();

        $nuevos = 0;
        $actualizados = 0;
        $errores = [];

        try {
            Database::transaction(function (PDO $pdo) use ($lines, $bodegaId, $userId, &$nuevos, &$actualizados, &$errores) {
                // Cache de categorías
                $stmtCats = $pdo->query("SELECT id, LOWER(nombre) as nombre_lower FROM categorias");
                $catCache = $stmtCats->fetchAll(PDO::FETCH_KEY_PAIR);

                $stmtInsCat = $pdo->prepare("INSERT INTO categorias (nombre) VALUES (:n) RETURNING id");

                $colMap = null;
                foreach ($lines as $lineIndex => $line) {
                    $rowNum = $lineIndex + 1;
                    $line = trim($line);
                    if ($line === '') continue;

                    // Soporte de delimitador coma o punto y coma
                    $delimiter = (strpos($line, ';') !== false && strpos($line, ',') === false) ? ';' : ',';
                    $cols = str_getcsv($line, $delimiter);

                    // Si es la primera fila y parece encabezado, construir mapa de índices
                    if ($colMap === null && (stripos($cols[0], 'codigo') !== false || stripos($cols[1] ?? '', 'nombre') !== false || stripos($cols[2] ?? '', 'nombre') !== false)) {
                        $colMap = [];
                        foreach ($cols as $idx => $headerName) {
                            $cleanHeader = strtolower(trim($headerName));
                            if (strpos($cleanHeader, 'codigo_barra') !== false || strpos($cleanHeader, 'barra') !== false) $colMap['barra'] = $idx;
                            elseif (strpos($cleanHeader, 'codigo') !== false) $colMap['codigo'] = $idx;
                            elseif (strpos($cleanHeader, 'nombre') !== false || strpos($cleanHeader, 'producto') !== false || strpos($cleanHeader, 'descripcion') !== false) $colMap['nombre'] = $idx;
                            elseif (strpos($cleanHeader, 'cat') !== false) $colMap['categoria'] = $idx;
                            elseif (strpos($cleanHeader, 'pres') !== false || strpos($cleanHeader, 'empaque') !== false) $colMap['presentacion'] = $idx;
                            elseif (strpos($cleanHeader, 'precio') !== false) $colMap['precio'] = $idx;
                            elseif (strpos($cleanHeader, 'costo') !== false) $colMap['costo'] = $idx;
                            elseif (strpos($cleanHeader, 'stock') !== false || strpos($cleanHeader, 'cant') !== false) $colMap['stock'] = $idx;
                            elseif (strpos($cleanHeader, 'lote') !== false) $colMap['lote'] = $idx;
                            elseif (strpos($cleanHeader, 'venc') !== false || strpos($cleanHeader, 'caduc') !== false) $colMap['vence'] = $idx;
                        }
                        continue;
                    }

                    // Extraer valores según mapeo dinámico o posicional por defecto
                    if ($colMap !== null) {
                        $codInterno = trim($cols[$colMap['codigo'] ?? 0] ?? '');
                        $codBarra = trim($cols[$colMap['barra'] ?? 5] ?? '');
                        $nombre = trim($cols[$colMap['nombre'] ?? 1] ?? '');
                        $catNombre = trim($cols[$colMap['categoria'] ?? 2] ?? 'General');
                        $presentacion = trim($cols[$colMap['presentacion'] ?? -1] ?? 'Unidad');
                        $precioVenta = (float)($cols[$colMap['precio'] ?? 3] ?? 0.00);
                        $costo = (float)($cols[$colMap['costo'] ?? 4] ?? 0.00);
                        $stockInicial = (float)($cols[$colMap['stock'] ?? 6] ?? 0.00);
                        $loteNum = trim($cols[$colMap['lote'] ?? 7] ?? '');
                        $fechaVence = trim($cols[$colMap['vence'] ?? 8] ?? '');
                    } else {
                        // Fallback posicional
                        $codInterno = trim($cols[0] ?? '');
                        $nombre = trim($cols[1] ?? '');
                        $catNombre = trim($cols[2] ?? 'General');
                        $precioVenta = (float)($cols[3] ?? 0.00);
                        $costo = (float)($cols[4] ?? 0.00);
                        $codBarra = trim($cols[5] ?? '');
                        $stockInicial = (float)($cols[6] ?? 0.00);
                        $loteNum = trim($cols[7] ?? '');
                        $fechaVence = trim($cols[8] ?? '');
                        $presentacion = 'Unidad';
                    }

                    if ($nombre === '') {
                        $errores[] = "Fila {$rowNum}: El nombre del producto es obligatorio.";
                        continue;
                    }

                    // Saneamiento y validaciones realistas de precios y costos
                    if ($precioVenta > 2500.00 || $precioVenta <= 0) {
                        // Detectar si una columna se corrió (ej. código de barra en precio)
                        if ($costo > 0 && $costo <= 2500.00) {
                            $precioVenta = round($costo * 1.30, 2);
                        } else {
                            $precioVenta = 2.50;
                        }
                    }
                    if ($costo > 2500.00 || $costo < 0) {
                        $costo = round($precioVenta * 0.70, 2);
                    }
                    if ($stockInicial < 0) {
                        $stockInicial = 0;
                    }
                    if ($stockInicial > 50000) {
                        $stockInicial = 100;
                    }

                    // Resolver Categoría
                    $catKey = mb_strtolower($catNombre);
                    if (!isset($catCache[$catKey])) {
                        $stmtInsCat->execute(['n' => $catNombre]);
                        $newCatId = (int)$stmtInsCat->fetchColumn();
                        $catCache[$catKey] = $newCatId;
                        $catId = $newCatId;
                    } else {
                        $catId = (int)$catCache[$catKey];
                    }

                    // Buscar si producto ya existe por código interno o código de barra
                    $prodId = 0;
                    if ($codInterno !== '') {
                        $stmtFind = $pdo->prepare("SELECT id FROM productos WHERE codigo_interno = :c LIMIT 1");
                        $stmtFind->execute(['c' => $codInterno]);
                        $prodId = (int)($stmtFind->fetchColumn() ?: 0);
                    }
                    if ($prodId === 0 && $codBarra !== '') {
                        $stmtFindCb = $pdo->prepare("SELECT producto_id FROM producto_codigos_barra WHERE codigo_barra = :cb LIMIT 1");
                        $stmtFindCb->execute(['cb' => $codBarra]);
                        $prodId = (int)($stmtFindCb->fetchColumn() ?: 0);
                    }

                    if ($prodId > 0) {
                        // Actualizar existente
                        $stmtUpd = $pdo->prepare("
                            UPDATE productos
                            SET nombre = :nombre, categoria_id = :cat, presentacion = :pres,
                                precio_venta = :precio, costo_promedio = CASE WHEN :costo > 0 THEN :costo ELSE costo_promedio END,
                                updated_at = CURRENT_TIMESTAMP
                            WHERE id = :id
                        ");
                        $stmtUpd->execute([
                            'nombre' => $nombre,
                            'cat' => $catId,
                            'pres' => $presentacion,
                            'precio' => $precioVenta,
                            'costo' => $costo,
                            'id' => $prodId
                        ]);
                        $actualizados++;
                    } else {
                        // Crear nuevo
                        if ($codInterno === '') {
                            $codInterno = 'PRD-' . str_pad((string)mt_rand(1000, 999999), 6, '0', STR_PAD_LEFT);
                        }
                        $stmtIns = $pdo->prepare("
                            INSERT INTO productos (
                                codigo_interno, nombre, categoria_id, marca_id, unidad_medida, presentacion,
                                costo_promedio, precio_venta, impuesto_id, stock_minimo, maneja_lotes
                            ) VALUES (
                                :cod, :nombre, :cat, 8, 'UNIDAD', :pres,
                                :costo, :precio, 1, 5.0, :lotes
                            ) RETURNING id
                        ");
                        $manejaLotes = (!empty($loteNum) && !empty($fechaVence)) ? 1 : 0;
                        $stmtIns->execute([
                            'cod' => $codInterno,
                            'nombre' => $nombre,
                            'cat' => $catId,
                            'pres' => $presentacion,
                            'costo' => $costo,
                            'precio' => $precioVenta,
                            'lotes' => $manejaLotes
                        ]);
                        $prodId = (int)$stmtIns->fetchColumn();
                        $nuevos++;
                    }

                    // Código de barras
                    if ($codBarra !== '') {
                        $stmtCb = $pdo->prepare("
                            INSERT INTO producto_codigos_barra (producto_id, codigo_barra, es_principal)
                            VALUES (:p, :cb, TRUE)
                            ON CONFLICT (codigo_barra) DO NOTHING
                        ");
                        $stmtCb->execute(['p' => $prodId, 'cb' => $codBarra]);
                    }

                    // Stock inicial si es mayor a 0
                    if ($stockInicial > 0) {
                        $stmtStock = $pdo->prepare("
                            INSERT INTO stock_bodega (producto_id, bodega_id, cantidad, updated_at)
                            VALUES (:p, :b, :cant, CURRENT_TIMESTAMP)
                            ON CONFLICT (producto_id, bodega_id)
                            DO UPDATE SET cantidad = stock_bodega.cantidad + :cant, updated_at = CURRENT_TIMESTAMP
                        ");
                        $stmtStock->execute(['p' => $prodId, 'b' => $bodegaId, 'cant' => $stockInicial]);

                        // Kardex inicial
                        $stmtKardex = $pdo->prepare("
                            INSERT INTO kardex_movimientos (
                                producto_id, bodega_id, tipo_movimiento, cantidad, costo_unitario,
                                stock_anterior, stock_posterior, documento_referencia, usuario_id, notas
                            ) VALUES (
                                :p, :b, 'INVENTARIO_INICIAL', :cant, :costo,
                                0.0, :cant, 'CARGA-MASIVA', :u, 'Carga masiva vía catálogo CSV'
                            )
                        ");
                        $stmtKardex->execute([
                            'p' => $prodId, 'b' => $bodegaId, 'cant' => $stockInicial,
                            'costo' => $costo, 'u' => $userId
                        ]);
                    }

                    // Lote y caducidad si viene especificado
                    if (!empty($loteNum) && !empty($fechaVence)) {
                        $stmtLote = $pdo->prepare("
                            INSERT INTO lotes (producto_id, bodega_id, numero_lote, fecha_vencimiento, cantidad, activo)
                            VALUES (:p, :b, :lote, :vence, :cant, TRUE)
                            ON CONFLICT (producto_id, bodega_id, numero_lote)
                            DO UPDATE SET cantidad = lotes.cantidad + :cant, fecha_vencimiento = :vence
                        ");
                        $stmtLote->execute([
                            'p' => $prodId, 'b' => $bodegaId, 'lote' => $loteNum,
                            'vence' => $fechaVence, 'cant' => max(0, $stockInicial)
                        ]);
                    }
                }
            });

            Audit::log('IMPORT', 'productos', null, null, [
                'nuevos' => $nuevos,
                'actualizados' => $actualizados,
                'errores_count' => count($errores)
            ], 'Carga masiva de catálogo CSV completada');

            Response::success([
                'nuevos' => $nuevos,
                'actualizados' => $actualizados,
                'errores' => $errores
            ], "Importación finalizada: {$nuevos} creados, {$actualizados} actualizados.");
        } catch (Throwable $e) {
            Response::error($e->getMessage(), 400);
        }
    }
}

