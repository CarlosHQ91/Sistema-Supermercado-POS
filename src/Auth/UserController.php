<?php
declare(strict_types=1);

namespace App\Auth;

use App\Shared\Database;
use App\Shared\Auth;
use App\Shared\Audit;
use App\Shared\Response;
use PDO;
use Throwable;

class UserController
{
    /**
     * Listar todos los usuarios
     */
    public function listUsers(): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query("
            SELECT id, username, nombre_completo, email, rol, activo, created_at,
                   (pin_autorizacion IS NOT NULL AND pin_autorizacion != '') as tiene_pin
            FROM usuarios
            ORDER BY id ASC
        ");
        Response::json(['users' => $stmt->fetchAll()]);
    }

    /**
     * Crear o actualizar usuario
     */
    public function saveUser(): void
    {
        // Solo Admin puede crear o modificar usuarios
        if (Auth::role() !== 'ADMIN') {
            Response::error('Solo el Administrador General puede gestionar usuarios.', 403);
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $id = (int)($input['id'] ?? 0);
        $username = strtolower(trim($input['username'] ?? ''));
        $nombre = trim($input['nombre_completo'] ?? '');
        $email = trim($input['email'] ?? '');
        $rol = strtoupper(trim($input['rol'] ?? 'CAJERO'));
        $password = (string)($input['password'] ?? '');
        $pin = trim((string)($input['pin'] ?? ''));
        $activo = isset($input['activo']) ? (bool)$input['activo'] : true;

        if ($username === '' || $nombre === '') {
            Response::error('El nombre de usuario y el nombre completo son obligatorios.');
        }

        $rolesPermitidos = ['ADMIN', 'GERENTE', 'SUPERVISOR', 'CAJERO', 'BODEGUERO', 'CONTADOR'];
        if (!in_array($rol, $rolesPermitidos, true)) {
            Response::error('Rol de usuario inválido.');
        }

        $pdo = Database::getConnection();

        try {
            if ($id > 0) {
                // Actualización
                $updates = [
                    'nombre_completo = :nombre',
                    'email = :email',
                    'rol = :rol',
                    'activo = :activo',
                    'updated_at = CURRENT_TIMESTAMP'
                ];
                $params = [
                    'nombre' => $nombre,
                    'email' => $email !== '' ? $email : null,
                    'rol' => $rol,
                    'activo' => $activo ? 1 : 0,
                    'id' => $id
                ];

                if ($password !== '') {
                    $updates[] = 'password_hash = :pass';
                    $params['pass'] = password_hash($password, PASSWORD_BCRYPT);
                }
                if ($pin !== '') {
                    $updates[] = 'pin_autorizacion = :pin';
                    $params['pin'] = password_hash($pin, PASSWORD_BCRYPT);
                }

                $sql = "UPDATE usuarios SET " . implode(', ', $updates) . " WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                Audit::log('UPDATE', 'usuarios', (string)$id, null, [
                    'username' => $username,
                    'rol' => $rol,
                    'activo' => $activo
                ], "Usuario actualizado");

                Response::success(['user_id' => $id], 'Usuario actualizado con éxito');
            } else {
                // Creación
                if ($password === '') {
                    Response::error('La contraseña inicial es obligatoria para nuevos usuarios.');
                }

                // Validar unicidad
                $check = $pdo->prepare("SELECT id FROM usuarios WHERE username = :u");
                $check->execute(['u' => $username]);
                if ($check->fetch()) {
                    Response::error("El nombre de usuario '{$username}' ya está registrado.");
                }

                $passHash = password_hash($password, PASSWORD_BCRYPT);
                $pinHash = $pin !== '' ? password_hash($pin, PASSWORD_BCRYPT) : null;

                $stmt = $pdo->prepare("
                    INSERT INTO usuarios (username, password_hash, nombre_completo, email, rol, pin_autorizacion, activo)
                    VALUES (:u, :pass, :nombre, :email, :rol, :pin, :activo)
                    RETURNING id
                ");
                $stmt->execute([
                    'u' => $username,
                    'pass' => $passHash,
                    'nombre' => $nombre,
                    'email' => $email !== '' ? $email : null,
                    'rol' => $rol,
                    'pin' => $pinHash,
                    'activo' => $activo ? 1 : 0
                ]);
                $newId = (int)$stmt->fetchColumn();

                Audit::log('CREATE', 'usuarios', (string)$newId, null, [
                    'username' => $username,
                    'rol' => $rol
                ], "Nuevo usuario creado");

                Response::success(['user_id' => $newId], 'Usuario creado con éxito');
            }
        } catch (Throwable $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    /**
     * Cambio de usuario en caliente (Switch User / Bloqueo de Terminal)
     */
    public function switchUser(): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $username = trim($input['username'] ?? '');
        $password = (string)($input['password'] ?? '');

        if (Auth::attempt($username, $password)) {
            Response::success(['user' => Auth::user()], 'Usuario cambiado con éxito');
        } else {
            Response::error('Usuario o contraseña incorrectos.', 401);
        }
    }
}
