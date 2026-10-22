<?php
declare(strict_types=1);

namespace App\Catalog;

use App\Shared\Database;
use App\Shared\Auth;
use App\Shared\Audit;
use App\Shared\Response;
use PDO;
use Throwable;

class CatalogController
{
    // ==========================================
    // CATEGORÍAS
    // ==========================================
    public function listCategories(): void
    {
        $pdo = Database::getConnection();

        // Limpieza automática preventiva de categorías con nombres numéricos corruptos (ej. 2.50, 0.75)
        try {
            $stmtBad = $pdo->query("SELECT id FROM categorias WHERE nombre ~ '^[0-9]+(\.[0-9]+)?$'");
            $badIds = $stmtBad->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($badIds)) {
                $in = implode(',', array_map('intval', $badIds));
                $pdo->exec("UPDATE productos SET categoria_id = 1 WHERE categoria_id IN ($in)");
                $pdo->exec("DELETE FROM categorias WHERE id IN ($in)");
            }
        } catch (Throwable $e) {
            // Continuar si la consulta regex no aplica
        }

        $stmt = $pdo->query("
            SELECT c.*, p.nombre as padre_nombre,
                   (SELECT COUNT(pr.id) FROM productos pr WHERE pr.categoria_id = c.id AND pr.activo = TRUE) as total_productos
            FROM categorias c
            LEFT JOIN categorias p ON p.id = c.padre_id
            WHERE c.activo = TRUE
            ORDER BY c.nombre ASC
        ");
        Response::json(['categories' => $stmt->fetchAll()]);
    }

    public function saveCategory(): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $id = (int)($input['id'] ?? 0);
        $nombre = trim($input['nombre'] ?? '');
        $descripcion = trim($input['descripcion'] ?? '');
        $padreId = !empty($input['padre_id']) ? (int)$input['padre_id'] : null;

        if ($nombre === '') {
            Response::error('El nombre de la categoría es obligatorio.');
        }

        if (is_numeric($nombre) || is_numeric(str_replace(['$', ' '], '', $nombre))) {
            Response::error('El nombre de la categoría no puede ser un valor numérico.');
        }

        $pdo = Database::getConnection();
        try {
            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE categorias
                    SET nombre = :nombre, descripcion = :desc, padre_id = :padre, updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id
                ");
                $stmt->execute(['nombre' => $nombre, 'desc' => $descripcion, 'padre' => $padreId, 'id' => $id]);
                Audit::log('UPDATE', 'categorias', (string)$id, null, ['nombre' => $nombre], 'Categoría actualizada');
                Response::success(['id' => $id], 'Categoría actualizada con éxito');
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO categorias (nombre, descripcion, padre_id)
                    VALUES (:nombre, :desc, :padre)
                    RETURNING id
                ");
                $stmt->execute(['nombre' => $nombre, 'desc' => $descripcion, 'padre' => $padreId]);
                $newId = (int)$stmt->fetchColumn();
                Audit::log('CREATE', 'categorias', (string)$newId, null, ['nombre' => $nombre], 'Nueva categoría creada');
                Response::success(['id' => $newId], 'Categoría creada con éxito');
            }
        } catch (Throwable $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    // ==========================================
    // MARCAS
    // ==========================================
    public function listBrands(): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query("SELECT * FROM marcas WHERE activo = TRUE ORDER BY nombre ASC");
        Response::json(['brands' => $stmt->fetchAll()]);
    }

    public function saveBrand(): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $id = (int)($input['id'] ?? 0);
        $nombre = trim($input['nombre'] ?? '');

        if ($nombre === '') {
            Response::error('El nombre de la marca es obligatorio.');
        }

        $pdo = Database::getConnection();
        try {
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE marcas SET nombre = :nombre WHERE id = :id");
                $stmt->execute(['nombre' => $nombre, 'id' => $id]);
                Response::success(['id' => $id], 'Marca actualizada');
            } else {
                $stmt = $pdo->prepare("INSERT INTO marcas (nombre) VALUES (:nombre) RETURNING id");
                $stmt->execute(['nombre' => $nombre]);
                $newId = (int)$stmt->fetchColumn();
                Response::success(['id' => $newId], 'Marca registrada');
            }
        } catch (Throwable $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    // ==========================================
    // PROVEEDORES
    // ==========================================
    public function listSuppliers(): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query("
            SELECT pr.*,
                   (SELECT COUNT(cf.id) FROM compras_facturas cf WHERE cf.proveedor_id = pr.id) as total_facturas
            FROM proveedores pr
            WHERE pr.activo = TRUE
            ORDER BY pr.razon_social ASC
        ");
        Response::json(['suppliers' => $stmt->fetchAll()]);
    }

    public function saveSupplier(): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $id = (int)($input['id'] ?? 0);
        $razonSocial = trim($input['razon_social'] ?? '');
        $nombreComercial = trim($input['nombre_comercial'] ?? '');
        $nit = trim($input['nit'] ?? '');
        $nrc = trim($input['nrc'] ?? '');
        $contacto = trim($input['contacto'] ?? '');
        $telefono = trim($input['telefono'] ?? '');
        $correo = trim($input['correo'] ?? '');
        $direccion = trim($input['direccion'] ?? '');
        $diasCredito = (int)($input['dias_credito'] ?? 0);

        if ($razonSocial === '' || $nit === '') {
            Response::error('La razón social y el NIT del proveedor son obligatorios.');
        }

        $pdo = Database::getConnection();
        try {
            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE proveedores
                    SET razon_social = :rs, nombre_comercial = :nc, nit = :nit, nrc = :nrc,
                        contacto = :cont, telefono = :tel, correo = :cor, direccion = :dir,
                        dias_credito = :dias
                    WHERE id = :id
                ");
                $stmt->execute([
                    'rs' => $razonSocial, 'nc' => $nombreComercial, 'nit' => $nit, 'nrc' => $nrc,
                    'cont' => $contacto, 'tel' => $telefono, 'cor' => $correo, 'dir' => $direccion,
                    'dias' => $diasCredito, 'id' => $id
                ]);
                Audit::log('UPDATE', 'proveedores', (string)$id, null, ['razon_social' => $razonSocial], 'Proveedor actualizado');
                Response::success(['id' => $id], 'Proveedor actualizado');
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO proveedores (razon_social, nombre_comercial, nit, nrc, contacto, telefono, correo, direccion, dias_credito)
                    VALUES (:rs, :nc, :nit, :nrc, :cont, :tel, :cor, :dir, :dias)
                    RETURNING id
                ");
                $stmt->execute([
                    'rs' => $razonSocial, 'nc' => $nombreComercial, 'nit' => $nit, 'nrc' => $nrc,
                    'cont' => $contacto, 'tel' => $telefono, 'cor' => $correo, 'dir' => $direccion,
                    'dias' => $diasCredito
                ]);
                $newId = (int)$stmt->fetchColumn();
                Audit::log('CREATE', 'proveedores', (string)$newId, null, ['razon_social' => $razonSocial], 'Nuevo proveedor registrado');
                Response::success(['id' => $newId], 'Proveedor registrado con éxito');
            }
        } catch (Throwable $e) {
            Response::error($e->getMessage(), 400);
        }
    }
}
