<?php
declare(strict_types=1);

namespace App\Shared;

use PDO;
use Throwable;

class Audit
{
    public static function log(
        string $accion,
        string $entidad,
        ?string $entidadId = null,
        ?array $valorAnterior = null,
        ?array $valorNuevo = null,
        ?string $detalles = null
    ): void {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("
                INSERT INTO bitacora_auditoria (usuario_id, accion, entidad, entidad_id, valor_anterior, valor_nuevo, ip_origen, detalles)
                VALUES (:usuario_id, :accion, :entidad, :entidad_id, :valor_anterior, :valor_nuevo, :ip_origen, :detalles)
            ");
            $stmt->execute([
                'usuario_id' => Auth::id(),
                'accion' => $accion,
                'entidad' => $entidad,
                'entidad_id' => $entidadId,
                'valor_anterior' => $valorAnterior ? json_encode($valorAnterior, JSON_UNESCAPED_UNICODE) : null,
                'valor_nuevo' => $valorNuevo ? json_encode($valorNuevo, JSON_UNESCAPED_UNICODE) : null,
                'ip_origen' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                'detalles' => $detalles
            ]);
        } catch (Throwable $e) {
            // Auditoría no debe interrumpir el flujo si hay error silencioso en logging
            error_log("Fallo al registrar auditoría: " . $e->getMessage());
        }
    }
}
