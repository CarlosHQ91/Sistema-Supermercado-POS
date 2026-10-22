<?php
declare(strict_types=1);

namespace App\CashRegister;

use App\Shared\Database;
use App\Shared\Auth;
use App\Shared\Audit;
use App\Shared\Response;
use PDO;
use Throwable;

class CashController
{
    /**
     * Consulta el estado de la sesión de caja del usuario actual
     */
    public function currentSession(): void
    {
        $userId = Auth::id();
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("
            SELECT cs.*, c.nombre as caja_nombre, c.numero_terminal, u.nombre_completo as cajero_nombre
            FROM caja_sesiones cs
            JOIN cajas c ON c.id = cs.caja_id
            JOIN usuarios u ON u.id = cs.usuario_id
            WHERE cs.usuario_id = :user_id AND cs.estado = 'ABIERTA'
            ORDER BY cs.id DESC LIMIT 1
        ");
        $stmt->execute(['user_id' => $userId]);
        $session = $stmt->fetch();

        $activeSessions = [];
        if (in_array(Auth::role(), ['ADMIN', 'GERENTE', 'SUPERVISOR', 'CONTADOR'], true)) {
            $activeSessions = $this->getActiveSessionsData($pdo);
        }

        if (!$session) {
            // Verificar si hay cajas disponibles para apertura y listar cajeros para que un Admin/Gerente pueda aperturarles
            $cajas = $pdo->query("SELECT * FROM cajas WHERE activo = TRUE ORDER BY id ASC")->fetchAll();
            $cajeros = $pdo->query("SELECT id, username, nombre_completo, rol FROM usuarios WHERE rol IN ('CAJERO', 'ADMIN', 'GERENTE') AND activo = TRUE ORDER BY nombre_completo ASC")->fetchAll();
            Response::json([
                'has_active_session' => false,
                'session' => null,
                'available_cajas' => $cajas,
                'available_cajeros' => $cajeros,
                'active_sessions' => $activeSessions,
                'user_role' => Auth::role()
            ]);
        }

        // Calcular totales en tiempo real para la sesión abierta
        $sesionId = (int)$session['id'];

        // Ventas por método de pago
        $stmtVentas = $pdo->prepare("
            SELECT vp.metodo_pago, COALESCE(SUM(vp.monto), 0) as total_monto
            FROM ventas v
            JOIN venta_pagos vp ON vp.venta_id = v.id
            WHERE v.sesion_caja_id = :sesion_id AND v.estado = 'COMPLETADA'
            GROUP BY vp.metodo_pago
        ");
        $stmtVentas->execute(['sesion_id' => $sesionId]);
        $ventasPorMetodo = $stmtVentas->fetchAll(PDO::FETCH_KEY_PAIR);

        // Movimientos (Ingresos y Retiros)
        $stmtMov = $pdo->prepare("
            SELECT tipo, COALESCE(SUM(monto), 0) as total_monto
            FROM caja_movimientos
            WHERE sesion_id = :sesion_id
            GROUP BY tipo
        ");
        $stmtMov->execute(['sesion_id' => $sesionId]);
        $movs = $stmtMov->fetchAll(PDO::FETCH_KEY_PAIR);

        $montoApertura = (float)$session['monto_apertura'];
        $ventasEfectivo = (float)($ventasPorMetodo['EFECTIVO'] ?? 0.00);
        $ventasTarjeta = (float)($ventasPorMetodo['TARJETA'] ?? 0.00);
        $ventasTransferencia = (float)($ventasPorMetodo['TRANSFERENCIA'] ?? 0.00);
        $ventasCredito = (float)($ventasPorMetodo['CREDITO'] ?? 0.00);

        $ingresos = (float)($movs['INGRESO'] ?? 0.00);
        $retiros = (float)($movs['RETIRO'] ?? 0.00);

        $efectivoEsperado = $montoApertura + $ventasEfectivo + $ingresos - $retiros;

        Response::json([
            'has_active_session' => true,
            'session' => $session,
            'active_sessions' => $activeSessions,
            'metrics' => [
                'monto_apertura' => $montoApertura,
                'ventas_efectivo' => $ventasEfectivo,
                'ventas_tarjeta' => $ventasTarjeta,
                'ventas_transferencia' => $ventasTransferencia,
                'ventas_credito' => $ventasCredito,
                'total_ventas' => $ventasEfectivo + $ventasTarjeta + $ventasTransferencia + $ventasCredito,
                'ingresos' => $ingresos,
                'retiros' => $retiros,
                'efectivo_esperado' => $efectivoEsperado
            ]
        ]);
    }

    /**
     * Obtiene el listado completo de sesiones activas en la sucursal (para administradores y gerentes)
     */
    public function getActiveSessionsData(PDO $pdo): array
    {
        $stmt = $pdo->query("
            SELECT 
                cs.id,
                cs.caja_id,
                cs.usuario_id,
                cs.monto_apertura,
                cs.fecha_apertura,
                cs.estado,
                cs.notas_apertura,
                c.nombre as caja_nombre,
                c.numero_terminal,
                u.nombre_completo as cajero_nombre,
                u.username as cajero_username,
                sup.nombre_completo as supervisor_apertura_nombre
            FROM caja_sesiones cs
            JOIN cajas c ON c.id = cs.caja_id
            JOIN usuarios u ON u.id = cs.usuario_id
            LEFT JOIN usuarios sup ON sup.id = cs.supervisor_apertura_id
            WHERE cs.estado = 'ABIERTA'
            ORDER BY cs.id DESC
        ");
        $sessions = $stmt->fetchAll();

        $result = [];
        foreach ($sessions as $s) {
            $sesId = (int)$s['id'];

            // Ventas por método de pago para esta sesión
            $stmtV = $pdo->prepare("
                SELECT vp.metodo_pago, COALESCE(SUM(vp.monto), 0) as tot
                FROM ventas v
                JOIN venta_pagos vp ON vp.venta_id = v.id
                WHERE v.sesion_caja_id = :sid AND v.estado = 'COMPLETADA'
                GROUP BY vp.metodo_pago
            ");
            $stmtV->execute(['sid' => $sesId]);
            $pagos = $stmtV->fetchAll();

            $ventasEf = 0.00;
            $ventasTar = 0.00;
            $ventasTrans = 0.00;
            $ventasCred = 0.00;
            $totalVentas = 0.00;

            foreach ($pagos as $p) {
                $m = (float)$p['tot'];
                $totalVentas += $m;
                if ($p['metodo_pago'] === 'EFECTIVO') $ventasEf += $m;
                if ($p['metodo_pago'] === 'TARJETA') $ventasTar += $m;
                if ($p['metodo_pago'] === 'TRANSFERENCIA') $ventasTrans += $m;
                if ($p['metodo_pago'] === 'CREDITO') $ventasCred += $m;
            }

            // Conteo total de tickets
            $stmtTickets = $pdo->prepare("SELECT COUNT(*) FROM ventas WHERE sesion_caja_id = :sid AND estado = 'COMPLETADA'");
            $stmtTickets->execute(['sid' => $sesId]);
            $numTickets = (int)$stmtTickets->fetchColumn();

            // Movimientos
            $stmtM = $pdo->prepare("
                SELECT tipo, COALESCE(SUM(monto), 0) as tot
                FROM caja_movimientos WHERE sesion_id = :sid GROUP BY tipo
            ");
            $stmtM->execute(['sid' => $sesId]);
            $movs = $stmtM->fetchAll(PDO::FETCH_KEY_PAIR);

            $ingresos = (float)($movs['INGRESO'] ?? 0.00);
            $retiros = (float)($movs['RETIRO'] ?? 0.00);
            $montoApertura = (float)$s['monto_apertura'];
            $efectivoEsperado = $montoApertura + $ventasEf + $ingresos - $retiros;

            $s['monto_apertura'] = $montoApertura;
            $s['ventas_efectivo'] = $ventasEf;
            $s['ventas_tarjeta'] = $ventasTar;
            $s['ventas_transferencia'] = $ventasTrans;
            $s['ventas_credito'] = $ventasCred;
            $s['total_ventas'] = $totalVentas;
            $s['total_transacciones'] = $numTickets;
            $s['ingresos'] = $ingresos;
            $s['retiros'] = $retiros;
            $s['efectivo_esperado'] = $efectivoEsperado;

            $result[] = $s;
        }
        return $result;
    }

    /**
     * Endpoint API para listar todas las cajas activas
     */
    public function listActiveSessions(): void
    {
        $pdo = Database::getConnection();
        $sessions = $this->getActiveSessionsData($pdo);
        Response::json(['ok' => true, 'active_sessions' => $sessions]);
    }

    /**
     * Apertura de Turno de Caja con desglose de denominaciones
     */
    public function openSession(): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $cajaId = (int)($input['caja_id'] ?? 1);
        $notas = trim($input['notas'] ?? '');
        $denominaciones = $input['denominaciones'] ?? []; // Array de {valor, cantidad, tipo}

        $currentRole = Auth::role();
        $currentUserId = Auth::id();
        $pdo = Database::getConnection();

        $cajeroId = $currentUserId;
        $supervisorId = null;

        if ($currentRole === 'CAJERO') {
            // Un cajero no puede aperturar una caja por sí mismo: requiere PIN de Gerente o Administrador
            $pinSupervisor = trim($input['pin_supervisor'] ?? '');
            if ($pinSupervisor === '') {
                Response::error('La apertura de caja debe ser autorizada por un Gerente o Administrador. Ingrese el PIN de supervisor.', 403);
            }
            $supervisor = Auth::verifySupervisor($pinSupervisor);
            if (!$supervisor) {
                Response::error('PIN de supervisor incorrecto o usuario no autorizado para autorizar apertura de caja.', 403);
            }
            $supervisorId = (int)$supervisor['id'];
            $cajeroId = $currentUserId;
        } elseif (in_array($currentRole, ['ADMIN', 'GERENTE', 'SUPERVISOR'], true)) {
            // Un Gerente o Administrador puede abrir la caja y asignarla a un cajero o a sí mismo
            $supervisorId = $currentUserId;
            if (!empty($input['cajero_id'])) {
                $cajeroId = (int)$input['cajero_id'];
            }
        } else {
            Response::error('No tienes permisos para aperturar cajas.', 403);
        }

        // Validar que la caja no tenga otra sesión abierta
        $stmtCheck = $pdo->prepare("SELECT id FROM caja_sesiones WHERE caja_id = :caja_id AND estado = 'ABIERTA'");
        $stmtCheck->execute(['caja_id' => $cajaId]);
        if ($stmtCheck->fetch()) {
            Response::error('La caja seleccionada ya tiene una sesión abierta por otro cajero.');
        }

        // Validar que el cajero asignado no tenga otra sesión abierta
        $stmtCheckUser = $pdo->prepare("SELECT id FROM caja_sesiones WHERE usuario_id = :user_id AND estado = 'ABIERTA'");
        $stmtCheckUser->execute(['user_id' => $cajeroId]);
        if ($stmtCheckUser->fetch()) {
            Response::error('El cajero seleccionado ya tiene un turno de caja abierto en otra terminal.');
        }

        try {
            $session = Database::transaction(function (PDO $pdo) use ($cajaId, $cajeroId, $supervisorId, $notas, $denominaciones, $input) {
                // Calcular monto inicial sumando denominaciones o por monto directo rápido
                $montoInicial = isset($input['monto_inicial']) ? (float)$input['monto_inicial'] : 0.0000;
                if ($montoInicial <= 0 && !empty($denominaciones)) {
                    foreach ($denominaciones as $d) {
                        $montoInicial += ((float)$d['valor'] * (int)$d['cantidad']);
                    }
                }

                if ($montoInicial < 0) {
                    $montoInicial = 0.00;
                }

                $stmt = $pdo->prepare("
                    INSERT INTO caja_sesiones (caja_id, usuario_id, supervisor_apertura_id, monto_apertura, estado, notas_apertura, fecha_apertura)
                    VALUES (:caja_id, :user_id, :supervisor_id, :monto, 'ABIERTA', :notas, CURRENT_TIMESTAMP)
                    RETURNING id, fecha_apertura
                ");
                $stmt->execute([
                    'caja_id' => $cajaId,
                    'user_id' => $cajeroId,
                    'supervisor_id' => $supervisorId,
                    'monto' => $montoInicial,
                    'notas' => $notas
                ]);
                $sesionRow = $stmt->fetch();
                $sesionId = (int)$sesionRow['id'];

                // Guardar desglose de denominaciones de apertura
                $stmtDet = $pdo->prepare("
                    INSERT INTO caja_arqueo_detalles (sesion_id, momento, denominacion_tipo, denominacion_valor, cantidad, subtotal)
                    VALUES (:sesion_id, 'APERTURA', :tipo, :valor, :cant, :subtotal)
                ");
                foreach ($denominaciones as $d) {
                    $val = (float)$d['valor'];
                    $cant = (int)$d['cantidad'];
                    if ($cant > 0) {
                        $stmtDet->execute([
                            'sesion_id' => $sesionId,
                            'tipo' => $d['tipo'] ?? ($val >= 1 ? 'BILLETE' : 'MONEDA'),
                            'valor' => $val,
                            'cant' => $cant,
                            'subtotal' => $val * $cant
                        ]);
                    }
                }

                Audit::log('CREATE', 'caja_sesiones', (string)$sesionId, null, [
                    'caja_id' => $cajaId,
                    'usuario_id' => $cajeroId,
                    'supervisor_apertura_id' => $supervisorId,
                    'monto_apertura' => $montoInicial
                ], "Apertura de turno de caja (Cajero ID: {$cajeroId}, Supervisor ID: {$supervisorId})");

                return [
                    'id' => $sesionId,
                    'monto_apertura' => $montoInicial,
                    'fecha_apertura' => $sesionRow['fecha_apertura'],
                    'cajero_id' => $cajeroId,
                    'supervisor_apertura_id' => $supervisorId
                ];
            });

            Response::success(['session' => $session], 'Turno de caja abierto correctamente');
        } catch (Throwable $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    /**
     * Registrar Movimiento de Caja (Ingreso o Retiro de efectivo)
     */
    public function addMovement(): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $tipo = strtoupper(trim($input['tipo'] ?? ''));
        $monto = (float)($input['monto'] ?? 0.00);
        $motivo = trim($input['motivo'] ?? '');
        $pinSupervisor = $input['pin_supervisor'] ?? '';

        if (!in_array($tipo, ['INGRESO', 'RETIRO'], true)) {
            Response::error('Tipo de movimiento inválido. Debe ser INGRESO o RETIRO.');
        }
        if ($monto <= 0) {
            Response::error('El monto debe ser mayor a cero.');
        }
        if ($motivo === '') {
            Response::error('Debe justificar el motivo del movimiento.');
        }

        // Validar autorización de supervisor
        $supervisor = Auth::verifySupervisor($pinSupervisor);
        if (!$supervisor) {
            Response::error('PIN o contraseña de supervisor inválida.');
        }

        $userId = Auth::id();
        $pdo = Database::getConnection();

        $stmtSesion = $pdo->prepare("SELECT id FROM caja_sesiones WHERE usuario_id = :u AND estado = 'ABIERTA' ORDER BY id DESC LIMIT 1");
        $stmtSesion->execute(['u' => $userId]);
        $sesion = $stmtSesion->fetch();
        if (!$sesion) {
            Response::error('No hay turno de caja abierto para registrar movimientos.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO caja_movimientos (sesion_id, tipo, monto, motivo, autorizado_por, fecha_hora)
            VALUES (:sesion_id, :tipo, :monto, :motivo, :autorizado_por, CURRENT_TIMESTAMP)
            RETURNING id, fecha_hora
        ");
        $stmt->execute([
            'sesion_id' => $sesion['id'],
            'tipo' => $tipo,
            'monto' => $monto,
            'motivo' => $motivo,
            'autorizado_por' => $supervisor['id']
        ]);
        $mov = $stmt->fetch();

        Audit::log('CREATE', 'caja_movimientos', (string)$mov['id'], null, [
            'tipo' => $tipo,
            'monto' => $monto,
            'motivo' => $motivo,
            'autorizado_por' => $supervisor['username']
        ], "Movimiento de caja registrado");

        Response::success(['movimiento' => $mov], 'Movimiento de caja registrado exitosamente');
    }

    /**
     * Cierre de Caja y Arqueo Ciego (Corte Z)
     */
    public function closeSession(): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $sesionId = (int)($input['sesion_id'] ?? 0);
        $denominaciones = $input['denominaciones'] ?? [];
        $pinSupervisor = trim($input['pin_supervisor'] ?? '');
        $notasCierre = trim($input['notas'] ?? '');

        if ($sesionId <= 0) {
            Response::error('ID de sesión de caja no válido para el cierre.');
        }

        // Validar supervisor
        $supervisor = null;
        if ($pinSupervisor !== '') {
            $supervisor = Auth::verifySupervisor($pinSupervisor);
        } elseif (in_array(Auth::role(), ['ADMIN', 'GERENTE'], true)) {
            $supervisor = Auth::user();
        }

        if (!$supervisor) {
            Response::error('Se requiere autorización o PIN de Gerente/Supervisor para el cierre de caja (Corte Z).', 403);
        }

        $pdo = Database::getConnection();
        $stmtSesion = $pdo->prepare("SELECT * FROM caja_sesiones WHERE id = :id AND estado = 'ABIERTA'");
        $stmtSesion->execute(['id' => $sesionId]);
        $session = $stmtSesion->fetch();

        if (!$session) {
            Response::error('La sesión de caja no existe o ya fue cerrada.');
        }

        try {
            $corteZ = Database::transaction(function (PDO $pdo) use ($session, $denominaciones, $supervisor, $notasCierre, $input) {
                $sesionId = (int)$session['id'];

                // 1. Calcular monto real contado por el cajero / administrador
                $montoReal = 0.0000;
                $stmtDet = $pdo->prepare("
                    INSERT INTO caja_arqueo_detalles (sesion_id, momento, denominacion_tipo, denominacion_valor, cantidad, subtotal)
                    VALUES (:sesion_id, 'CIERRE', :tipo, :valor, :cant, :subtotal)
                ");

                $hasDenom = false;
                foreach ($denominaciones as $d) {
                    $val = (float)$d['valor'];
                    $cant = (int)$d['cantidad'];
                    $sub = $val * $cant;
                    $montoReal += $sub;

                    if ($cant > 0) {
                        $hasDenom = true;
                        $stmtDet->execute([
                            'sesion_id' => $sesionId,
                            'tipo' => $d['tipo'] ?? ($val >= 1 ? 'BILLETE' : 'MONEDA'),
                            'valor' => $val,
                            'cant' => $cant,
                            'subtotal' => $sub
                        ]);
                    }
                }

                if (!$hasDenom && isset($input['monto_real'])) {
                    $montoReal = (float)$input['monto_real'];
                }

                // 2. Calcular ventas en efectivo del turno
                $stmtVentasEf = $pdo->prepare("
                    SELECT COALESCE(SUM(vp.monto), 0)
                    FROM ventas v
                    JOIN venta_pagos vp ON vp.venta_id = v.id
                    WHERE v.sesion_caja_id = :s AND v.estado = 'COMPLETADA' AND vp.metodo_pago = 'EFECTIVO'
                ");
                $stmtVentasEf->execute(['s' => $sesionId]);
                $ventasEf = (float)$stmtVentasEf->fetchColumn();

                // 3. Movimientos
                $stmtMovs = $pdo->prepare("
                    SELECT tipo, COALESCE(SUM(monto), 0) as tot
                    FROM caja_movimientos WHERE sesion_id = :s GROUP BY tipo
                ");
                $stmtMovs->execute(['s' => $sesionId]);
                $movMap = $stmtMovs->fetchAll(PDO::FETCH_KEY_PAIR);

                $montoApertura = (float)$session['monto_apertura'];
                $ingresos = (float)($movMap['INGRESO'] ?? 0.00);
                $retiros = (float)($movMap['RETIRO'] ?? 0.00);

                $montoEsperado = $montoApertura + $ventasEf + $ingresos - $retiros;
                $diferencia = $montoReal - $montoEsperado;

                $numeroCorteZ = 'CORTE-Z-' . date('Ymd') . '-' . str_pad((string)$sesionId, 4, '0', STR_PAD_LEFT);

                // 4. Actualizar sesión a CERRADA (Inmutable)
                $stmtUpd = $pdo->prepare("
                    UPDATE caja_sesiones
                    SET estado = 'CERRADA',
                        fecha_cierre = CURRENT_TIMESTAMP,
                        monto_esperado = :esp,
                        monto_real = :real,
                        diferencia = :dif,
                        supervisor_cierre_id = :sup,
                        notas_cierre = :notas,
                        corte_z_numero = :corte_z
                    WHERE id = :id
                ");
                $stmtUpd->execute([
                    'esp' => $montoEsperado,
                    'real' => $montoReal,
                    'dif' => $diferencia,
                    'sup' => $supervisor['id'],
                    'notas' => $notasCierre,
                    'corte_z' => $numeroCorteZ,
                    'id' => $sesionId
                ]);

                // 5. Asiento contable por descuadre de caja si diferencia != 0
                if (abs($diferencia) >= 0.01) {
                    $stmtAsiento = $pdo->prepare("
                        INSERT INTO asientos_contables (numero_asiento, fecha, concepto, origen_modulo, referencia_id, total_debe, total_haber)
                        VALUES (:num, CURRENT_DATE, :concepto, 'CAJA', :ref, :debe, :haber)
                        RETURNING id
                    ");
                    $numAst = 'AST-CZ-' . $sesionId;
                    $absDif = abs($diferencia);
                    $stmtAsiento->execute([
                        'num' => $numAst,
                        'concepto' => "Ajuste por " . ($diferencia < 0 ? "Faltante" : "Sobrante") . " en Corte Z {$numeroCorteZ}",
                        'ref' => (string)$sesionId,
                        'debe' => $absDif,
                        'haber' => $absDif
                    ]);
                    $astId = (int)$stmtAsiento->fetchColumn();

                    $stmtLin = $pdo->prepare("
                        INSERT INTO asiento_lineas (asiento_id, cuenta_id, debe, haber, concepto_linea)
                        VALUES (:ast, :cta, :debe, :haber, :con)
                    ");

                    if ($diferencia < 0) {
                        // Faltante: Debe = Gasto Faltante (Cta 6103 / id 13), Haber = Caja (Cta 1101 / id 1)
                        $stmtLin->execute(['ast' => $astId, 'cta' => 13, 'debe' => $absDif, 'haber' => 0.0, 'con' => 'Pérdida por faltante de caja']);
                        $stmtLin->execute(['ast' => $astId, 'cta' => 1, 'debe' => 0.0, 'haber' => $absDif, 'con' => 'Ajuste saldo caja por faltante']);
                    } else {
                        // Sobrante: Debe = Caja (Cta 1101 / id 1), Haber = Otros Ingresos / Faltantes y Sobrantes (Cta 6103 / id 13)
                        $stmtLin->execute(['ast' => $astId, 'cta' => 1, 'debe' => $absDif, 'haber' => 0.0, 'con' => 'Ingreso por sobrante de caja']);
                        $stmtLin->execute(['ast' => $astId, 'cta' => 13, 'debe' => 0.0, 'haber' => $absDif, 'con' => 'Ganancia por sobrante de arqueo']);
                    }
                }

                Audit::log('UPDATE', 'caja_sesiones', (string)$sesionId, null, [
                    'corte_z' => $numeroCorteZ,
                    'monto_esperado' => $montoEsperado,
                    'monto_real' => $montoReal,
                    'diferencia' => $diferencia,
                    'supervisor' => $supervisor['username']
                ], "Cierre de turno y Corte Z confirmado");

                return [
                    'sesion_id' => $sesionId,
                    'corte_z_numero' => $numeroCorteZ,
                    'monto_apertura' => $montoApertura,
                    'ventas_efectivo' => $ventasEf,
                    'ingresos' => $ingresos,
                    'retiros' => $retiros,
                    'monto_esperado' => $montoEsperado,
                    'monto_real' => $montoReal,
                    'diferencia' => $diferencia,
                    'supervisor' => $supervisor['nombre_completo'] ?? $supervisor['username'] ?? 'Supervisor'
                ];
            });

            Response::success(['corte_z' => $corteZ], 'Cierre de caja y Corte Z generado con éxito');
        } catch (Throwable $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    /**
     * Lista todas las terminales/cajas registradas
     */
    public function listTerminals(): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query("
            SELECT c.*,
                   (SELECT COUNT(*) FROM caja_sesiones cs WHERE cs.caja_id = c.id AND cs.estado = 'ABIERTA') as tiene_sesion_abierta,
                   (SELECT u.nombre_completo FROM caja_sesiones cs JOIN usuarios u ON u.id = cs.usuario_id WHERE cs.caja_id = c.id AND cs.estado = 'ABIERTA' ORDER BY cs.id DESC LIMIT 1) as cajero_actual
            FROM cajas c
            ORDER BY c.id ASC
        ");
        Response::json(['ok' => true, 'terminals' => $stmt->fetchAll()]);
    }

    /**
     * Crear o editar una caja/terminal
     */
    public function saveTerminal(): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $id = (int)($input['id'] ?? 0);
        $nombre = trim($input['nombre'] ?? '');
        $numeroTerminal = strtoupper(trim($input['numero_terminal'] ?? ''));
        $impresora = trim($input['impresora_nombre'] ?? 'Generic 80mm ESC/POS');
        $ip = trim($input['ip_terminal'] ?? '127.0.0.1');
        $activo = isset($input['activo']) ? (bool)$input['activo'] : true;

        if ($nombre === '') {
            Response::error('El nombre de la caja o terminal es obligatorio (ej. Caja 3 - Rápida).');
        }
        if ($numeroTerminal === '') {
            Response::error('El identificador de terminal es obligatorio (ej. TERM-03 o POS-03).');
        }

        $pdo = Database::getConnection();

        // Validar unicidad de numero_terminal
        if ($id > 0) {
            $stmtCheck = $pdo->prepare("SELECT id FROM cajas WHERE numero_terminal = :t AND id != :id");
            $stmtCheck->execute(['t' => $numeroTerminal, 'id' => $id]);
        } else {
            $stmtCheck = $pdo->prepare("SELECT id FROM cajas WHERE numero_terminal = :t");
            $stmtCheck->execute(['t' => $numeroTerminal]);
        }
        if ($stmtCheck->fetch()) {
            Response::error("El código de terminal '{$numeroTerminal}' ya se encuentra registrado.");
        }

        if ($id > 0) {
            $stmt = $pdo->prepare("
                UPDATE cajas
                SET nombre = :nom, numero_terminal = :term, impresora_nombre = :imp, ip_terminal = :ip, activo = :act
                WHERE id = :id
            ");
            $stmt->execute([
                'nom' => $nombre,
                'term' => $numeroTerminal,
                'imp' => $impresora,
                'ip' => $ip,
                'act' => $activo ? 1 : 0,
                'id' => $id
            ]);
            Audit::log('UPDATE', 'cajas', (string)$id, null, ['nombre' => $nombre, 'numero_terminal' => $numeroTerminal], "Caja/terminal modificada");
            Response::success(['id' => $id], 'Terminal de caja actualizada con éxito');
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO cajas (nombre, numero_terminal, impresora_nombre, ip_terminal, activo)
                VALUES (:nom, :term, :imp, :ip, :act)
                RETURNING id
            ");
            $stmt->execute([
                'nom' => $nombre,
                'term' => $numeroTerminal,
                'imp' => $impresora,
                'ip' => $ip,
                'act' => $activo ? 1 : 0
            ]);
            $newId = (int)$stmt->fetchColumn();
            Audit::log('CREATE', 'cajas', (string)$newId, null, ['nombre' => $nombre, 'numero_terminal' => $numeroTerminal], "Nueva caja/terminal creada");
            Response::success(['id' => $newId], 'Nueva caja o terminal POS creada exitosamente');
        }
    }
}
