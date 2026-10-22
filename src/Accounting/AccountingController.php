<?php
declare(strict_types=1);

namespace App\Accounting;

use App\Shared\Database;
use App\Shared\Response;
use App\Shared\Auth;
use PDO;
use Throwable;

class AccountingController
{
    /**
     * Cache de cuentas contables por código (1101, 2105, 4101, etc.)
     */
    private static function getAccountMap(PDO $pdo): array
    {
        $stmt = $pdo->query("SELECT id, codigo, nombre, tipo, naturaleza FROM cuentas_contables");
        $map = [];
        while ($row = $stmt->fetch()) {
            $map[$row['codigo']] = (int)$row['id'];
        }
        return $map;
    }

    /**
     * Sincronización y autocreación de asientos contables faltantes
     */
    private static function syncMissingEntries(PDO $pdo): void
    {
        $accountMap = self::getAccountMap($pdo);
        if (empty($accountMap)) {
            return;
        }

        // 1. Partida de Apertura inicial si no hay ningún asiento
        $totalAsientos = (int)$pdo->query("SELECT count(*) FROM asientos_contables")->fetchColumn();
        if ($totalAsientos === 0) {
            $invValor = (float)($pdo->query("
                SELECT COALESCE(SUM(sb.cantidad * p.costo_promedio), 0)
                FROM stock_bodega sb
                JOIN productos p ON p.id = sb.producto_id
            ")->fetchColumn() ?: 500.00);

            $invValor = max(500.00, round($invValor, 2));
            $cajaFondo = 1000.00;
            $capitalTotal = $invValor + $cajaFondo;

            $stmtApe = $pdo->prepare("
                INSERT INTO asientos_contables (numero_asiento, fecha, concepto, origen_modulo, referencia_id, total_debe, total_haber)
                VALUES ('AST-APE-001', CURRENT_DATE, 'Partida de Apertura: Inventario Inicial y Capital Social Fundacional', 'APERTURA', 'APE-001', :debe, :haber)
                RETURNING id
            ");
            $stmtApe->execute(['debe' => $capitalTotal, 'haber' => $capitalTotal]);
            $apeId = (int)$stmtApe->fetchColumn();

            $stmtLin = $pdo->prepare("INSERT INTO asiento_lineas (asiento_id, cuenta_id, debe, haber, concepto_linea) VALUES (:ast, :cta, :debe, :haber, :con)");

            // Debe: 1105 Inventario Mercaderías
            if (isset($accountMap['1105'])) {
                $stmtLin->execute(['ast' => $apeId, 'cta' => $accountMap['1105'], 'debe' => $invValor, 'haber' => 0.0, 'con' => 'Inventario inicial valorizado']);
            }
            // Debe: 1101 Caja General
            if (isset($accountMap['1101'])) {
                $stmtLin->execute(['ast' => $apeId, 'cta' => $accountMap['1101'], 'debe' => $cajaFondo, 'haber' => 0.0, 'con' => 'Fondo operativo disponible']);
            }
            // Haber: 3101 Capital Social
            if (isset($accountMap['3101'])) {
                $stmtLin->execute(['ast' => $apeId, 'cta' => $accountMap['3101'], 'debe' => 0.0, 'haber' => $capitalTotal, 'con' => 'Aporte y patrimonio inicial']);
            }
        }

        // 2. Sincronizar ventas completadas sin asiento
        $stmtVentas = $pdo->query("
            SELECT v.id, v.numero_venta, v.fecha_hora, v.total, v.subtotal_gravado, v.iva, v.usuario_cajero_id,
                   COALESCE(SUM(vi.cantidad * vi.costo_unitario), 0) as costo_total
            FROM ventas v
            LEFT JOIN venta_items vi ON vi.venta_id = v.id
            WHERE v.estado = 'COMPLETADA'
              AND NOT EXISTS (
                  SELECT 1 FROM asientos_contables a 
                  WHERE a.origen_modulo = 'VENTAS' AND a.referencia_id = v.id::text
              )
            GROUP BY v.id, v.numero_venta, v.fecha_hora, v.total, v.subtotal_gravado, v.iva, v.usuario_cajero_id
            LIMIT 50
        ");

        $missingSales = $stmtVentas->fetchAll();

        foreach ($missingSales as $v) {
            $ventaId = (int)$v['id'];
            $totalVenta = (float)$v['total'];
            $costoTotal = (float)$v['costo_total'];
            if ($costoTotal <= 0) {
                $costoTotal = round($totalVenta * 0.70, 2); // 70% estimado de costo si faltan items
            }
            $iva = (float)$v['iva'];
            $netoGravado = max(0.0, $totalVenta - $iva);

            $stmtAsiento = $pdo->prepare("
                INSERT INTO asientos_contables (numero_asiento, fecha, concepto, origen_modulo, referencia_id, usuario_id, total_debe, total_haber)
                VALUES (:num, :fecha, :concepto, 'VENTAS', :ref, :u, :debe, :haber)
                RETURNING id
            ");
            $docFecha = substr((string)$v['fecha_hora'], 0, 10) ?: date('Y-m-d');
            $numAsiento = 'AST-VTA-' . str_pad((string)$ventaId, 5, '0', STR_PAD_LEFT);

            $stmtAsiento->execute([
                'num' => $numAsiento,
                'fecha' => $docFecha,
                'concepto' => "Venta POS Mostrador ticket {$v['numero_venta']}",
                'ref' => (string)$ventaId,
                'u' => $v['usuario_cajero_id'],
                'debe' => $totalVenta + $costoTotal,
                'haber' => $totalVenta + $costoTotal
            ]);
            $asientoId = (int)$stmtAsiento->fetchColumn();

            $stmtLin = $pdo->prepare("INSERT INTO asiento_lineas (asiento_id, cuenta_id, debe, haber, concepto_linea) VALUES (:ast, :cta, :debe, :haber, :con)");

            // Debe: 1101 Caja General
            if (isset($accountMap['1101'])) {
                $stmtLin->execute(['ast' => $asientoId, 'cta' => $accountMap['1101'], 'debe' => $totalVenta, 'haber' => 0.0, 'con' => 'Ingreso cobro POS']);
            }
            // Haber: 4101 Ventas
            if (isset($accountMap['4101'])) {
                $stmtLin->execute(['ast' => $asientoId, 'cta' => $accountMap['4101'], 'debe' => 0.0, 'haber' => $netoGravado, 'con' => 'Venta neta mercadería']);
            }
            // Haber: 2105 IVA Débito Fiscal
            if (isset($accountMap['2105'])) {
                $stmtLin->execute(['ast' => $asientoId, 'cta' => $accountMap['2105'], 'debe' => 0.0, 'haber' => $iva, 'con' => "IVA Débito 13% ticket {$v['numero_venta']}"]);
            }
            // Debe: 5101 Costo de Ventas
            if (isset($accountMap['5101'])) {
                $stmtLin->execute(['ast' => $asientoId, 'cta' => $accountMap['5101'], 'debe' => $costoTotal, 'haber' => 0.0, 'con' => 'Costo de mercancía vendida']);
            }
            // Haber: 1105 Inventario Mercaderías
            if (isset($accountMap['1105'])) {
                $stmtLin->execute(['ast' => $asientoId, 'cta' => $accountMap['1105'], 'debe' => 0.0, 'haber' => $costoTotal, 'con' => 'Descargo de inventario']);
            }
        }
    }

    /**
     * Listado de asientos contables con sus líneas
     */
    public function listEntries(): void
    {
        $pdo = Database::getConnection();

        // Autocorrección y generación preventiva de asientos si faltan
        self::syncMissingEntries($pdo);

        $limit = 100;

        $stmt = $pdo->prepare("
            SELECT a.*, COALESCE(u.nombre_completo, 'Sistema Automático') as usuario_nombre
            FROM asientos_contables a
            LEFT JOIN usuarios u ON u.id = a.usuario_id
            ORDER BY a.fecha DESC, a.id DESC
            LIMIT :lim
        ");
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $asientos = $stmt->fetchAll();

        foreach ($asientos as &$ast) {
            $stmtLin = $pdo->prepare("
                SELECT al.*, c.codigo as cuenta_codigo, c.nombre as cuenta_nombre
                FROM asiento_lineas al
                JOIN cuentas_contables c ON c.id = al.cuenta_id
                WHERE al.asiento_id = :id
                ORDER BY al.debe DESC, al.id ASC
            ");
            $stmtLin->execute(['id' => $ast['id']]);
            $ast['lineas'] = $stmtLin->fetchAll();
        }

        Response::json(['asientos' => $asientos]);
    }

    /**
     * Consulta detallada de líneas de una partida contable
     */
    public function entryLines(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            Response::error('ID de asiento requerido');
        }

        $pdo = Database::getConnection();

        $stmtAst = $pdo->prepare("
            SELECT a.*, COALESCE(u.nombre_completo, 'Sistema') as usuario_nombre
            FROM asientos_contables a
            LEFT JOIN usuarios u ON u.id = a.usuario_id
            WHERE a.id = :id
        ");
        $stmtAst->execute(['id' => $id]);
        $asiento = $stmtAst->fetch();

        if (!$asiento) {
            Response::error('Asiento contable no encontrado', 404);
        }

        $stmtLin = $pdo->prepare("
            SELECT al.*, c.codigo as cuenta_codigo, c.nombre as cuenta_nombre
            FROM asiento_lineas al
            JOIN cuentas_contables c ON c.id = al.cuenta_id
            WHERE al.asiento_id = :id
            ORDER BY al.debe DESC, al.id ASC
        ");
        $stmtLin->execute(['id' => $id]);
        $lineas = $stmtLin->fetchAll();

        Response::json([
            'asiento' => $asiento,
            'lineas' => $lineas
        ]);
    }

    /**
     * Libro de Ventas a Consumidor Final (Requisito legal El Salvador)
     */
    public function libroConsumidorFinal(): void
    {
        $pdo = Database::getConnection();
        $mes = $_GET['mes'] ?? date('m');
        $anio = $_GET['anio'] ?? date('Y');

        $stmt = $pdo->prepare("
            SELECT v.fecha_hora::date as fecha,
                   MIN(v.numero_venta) as del_numero,
                   MAX(v.numero_venta) as al_numero,
                   1 as caja_id,
                   COUNT(v.id) as cantidad_tickets,
                   COALESCE(SUM(v.subtotal_exento), 0) as ventas_exentas,
                   COALESCE(SUM(v.subtotal_gravado), 0) as ventas_gravadas,
                   COALESCE(SUM(v.iva), 0) as debito_fiscal,
                   COALESCE(SUM(v.total), 0) as total_diario
            FROM ventas v
            WHERE v.tipo_documento = '01'
              AND v.estado = 'COMPLETADA'
            GROUP BY v.fecha_hora::date
            ORDER BY fecha DESC
            LIMIT 50
        ");
        $stmt->execute();
        $filas = $stmt->fetchAll();

        Response::json([
            'libro' => 'Libro de Ventas a Consumidor Final',
            'periodo' => "{$mes}/{$anio}",
            'filas' => $filas,
            'registros' => $filas
        ]);
    }

    /**
     * Libro de Ventas a Contribuyentes (Crédito Fiscal - DTE 03)
     */
    public function libroContribuyentes(): void
    {
        $pdo = Database::getConnection();
        $mes = $_GET['mes'] ?? date('m');
        $anio = $_GET['anio'] ?? date('Y');

        $stmt = $pdo->prepare("
            SELECT v.fecha_hora::date as fecha,
                   COALESCE(v.numero_control_mh, v.numero_venta) as numero_control,
                   v.numero_control_mh as numero_dte,
                   v.codigo_generacion_mh as codigo_generacion,
                   c.nombre as cliente_nombre,
                   c.nit as cliente_nit,
                   COALESCE(c.nrc, '00000-0') as nrc,
                   v.subtotal_gravado,
                   v.iva,
                   0.00 as retencion_1,
                   v.total
            FROM ventas v
            JOIN clientes c ON c.id = v.cliente_id
            WHERE v.tipo_documento = '03'
              AND v.estado = 'COMPLETADA'
            ORDER BY v.fecha_hora DESC
            LIMIT 50
        ");
        $stmt->execute();
        $filas = $stmt->fetchAll();

        Response::json([
            'libro' => 'Libro de Ventas a Contribuyentes (CCF)',
            'periodo' => "{$mes}/{$anio}",
            'filas' => $filas,
            'registros' => $filas
        ]);
    }

    /**
     * Libro de Compras e IVA Crédito Fiscal
     */
    public function libroCompras(): void
    {
        $pdo = Database::getConnection();
        $mes = $_GET['mes'] ?? date('m');
        $anio = $_GET['anio'] ?? date('Y');

        $stmt = $pdo->prepare("
            SELECT cf.fecha_factura as fecha,
                   cf.numero_factura as numero_documento,
                   p.razon_social as proveedor_nombre,
                   p.nit as proveedor_nit,
                   p.nrc as nrc,
                   cf.subtotal as gravado,
                   cf.subtotal as compras_gravadas,
                   cf.iva as credito_fiscal,
                   0.00 as percepcion_1,
                   cf.total
            FROM compras_facturas cf
            JOIN proveedores p ON p.id = cf.proveedor_id
            ORDER BY cf.fecha_factura DESC
            LIMIT 50
        ");
        $stmt->execute();
        $filas = $stmt->fetchAll();

        Response::json([
            'libro' => 'Libro de Compras (IVA Crédito Fiscal)',
            'periodo' => "{$mes}/{$anio}",
            'filas' => $filas,
            'registros' => $filas
        ]);
    }

    /**
     * Balance de Comprobación y Cuadre de Partida Doble
     */
    public function balanceComprobacion(): void
    {
        $pdo = Database::getConnection();

        self::syncMissingEntries($pdo);

        $stmt = $pdo->query("
            SELECT c.id, c.codigo, c.nombre, c.tipo, c.naturaleza,
                   COALESCE(SUM(al.debe), 0) as total_debe,
                   COALESCE(SUM(al.haber), 0) as total_haber,
                   CASE 
                       WHEN c.naturaleza = 'DEUDORA' AND COALESCE(SUM(al.debe), 0) >= COALESCE(SUM(al.haber), 0)
                           THEN (COALESCE(SUM(al.debe), 0) - COALESCE(SUM(al.haber), 0))
                       ELSE 0 
                   END as saldo_deudor,
                   CASE 
                       WHEN c.naturaleza = 'ACREEDORA' AND COALESCE(SUM(al.haber), 0) >= COALESCE(SUM(al.debe), 0)
                           THEN (COALESCE(SUM(al.haber), 0) - COALESCE(SUM(al.debe), 0))
                       WHEN c.naturaleza = 'DEUDORA' AND COALESCE(SUM(al.haber), 0) > COALESCE(SUM(al.debe), 0)
                           THEN (COALESCE(SUM(al.haber), 0) - COALESCE(SUM(al.debe), 0))
                       ELSE 0 
                   END as saldo_acreedor
            FROM cuentas_contables c
            LEFT JOIN asiento_lineas al ON al.cuenta_id = c.id
            GROUP BY c.id, c.codigo, c.nombre, c.tipo, c.naturaleza
            ORDER BY c.codigo ASC
        ");
        $cuentas = $stmt->fetchAll();

        $sumaDebe = 0.0;
        $sumaHaber = 0.0;
        $saldoDeudorTot = 0.0;
        $saldoAcreedorTot = 0.0;

        foreach ($cuentas as $c) {
            $sumaDebe += (float)$c['total_debe'];
            $sumaHaber += (float)$c['total_haber'];
            $saldoDeudorTot += (float)$c['saldo_deudor'];
            $saldoAcreedorTot += (float)$c['saldo_acreedor'];
        }

        Response::json([
            'cuentas' => $cuentas,
            'total_debe' => round($sumaDebe, 2),
            'total_haber' => round($sumaHaber, 2),
            'total_saldo_deudor' => round($saldoDeudorTot, 2),
            'total_saldo_acreedor' => round($saldoAcreedorTot, 2),
            'totales' => [
                'suma_debe' => round($sumaDebe, 2),
                'suma_haber' => round($sumaHaber, 2),
                'diferencia' => round($sumaDebe - $sumaHaber, 2),
                'cuadrado' => abs($sumaDebe - $sumaHaber) < 0.01
            ]
        ]);
    }
}
