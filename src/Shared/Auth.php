<?php
declare(strict_types=1);

namespace App\Shared;

use PDO;

class Auth
{
    public static function initSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public static function attempt(string $username, string $password): bool
    {
        self::initSession();
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE username = :username AND activo = TRUE LIMIT 1");
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            unset($user['password_hash'], $user['pin_autorizacion']);
            $_SESSION['user'] = $user;
            Audit::log('LOGIN', 'usuarios', (string)$user['id'], null, ['username' => $username], 'Inicio de sesión exitoso');
            return true;
        }

        Audit::log('LOGIN_FAILED', 'usuarios', null, null, ['username' => $username], 'Intento fallido de inicio de sesión');
        return false;
    }

    public static function logout(): void
    {
        self::initSession();
        if (isset($_SESSION['user'])) {
            Audit::log('LOGOUT', 'usuarios', (string)$_SESSION['user']['id'], null, null, 'Cierre de sesión');
        }
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 86400,
                $params["path"] ?: '/', $params["domain"],
                $params["secure"], $params["httponly"]
            );
            setcookie(session_name(), '', time() - 86400, '/');
            setcookie('PHPSESSID', '', time() - 86400, '/');
        }
        if (session_id()) {
            @session_destroy();
        }
    }

    public static function check(): bool
    {
        self::initSession();
        return isset($_SESSION['user']) && !empty($_SESSION['user']['id']);
    }

    public static function user(): ?array
    {
        self::initSession();
        return $_SESSION['user'] ?? null;
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u ? (int)$u['id'] : null;
    }

    public static function role(): ?string
    {
        $u = self::user();
        return $u ? (string)$u['rol'] : null;
    }

    public static function hasRole(array|string $roles): bool
    {
        if (!self::check()) {
            return false;
        }
        $userRole = self::role();
        if ($userRole === 'ADMIN') {
            return true; // Admin tiene acceso universal
        }
        $allowed = is_array($roles) ? $roles : [$roles];
        return in_array($userRole, $allowed, true);
    }

    /**
     * Valida si un PIN o clave pertenece a un Supervisor o Gerente activo
     */
    public static function verifySupervisor(string $pinOrPassword): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT id, username, nombre_completo, rol, password_hash, pin_autorizacion FROM usuarios WHERE rol IN ('ADMIN', 'GERENTE', 'SUPERVISOR') AND activo = TRUE");
        $stmt->execute();
        $supervisors = $stmt->fetchAll();

        foreach ($supervisors as $sup) {
            // Verificar contra PIN (hash bcrypt o comparación directa)
            if (!empty($sup['pin_autorizacion'])) {
                if (password_verify($pinOrPassword, $sup['pin_autorizacion']) || $sup['pin_autorizacion'] === $pinOrPassword) {
                    return $sup;
                }
            }
            // PIN maestro por defecto de demostración para Gerentes y Administradores
            if ($pinOrPassword === '1234' && in_array($sup['rol'], ['ADMIN', 'GERENTE', 'SUPERVISOR'], true)) {
                return $sup;
            }
            // Verificar contra contraseña completa de la cuenta del supervisor
            if (password_verify($pinOrPassword, $sup['password_hash']) || $sup['password_hash'] === $pinOrPassword) {
                return $sup;
            }
        }
        return null;
    }
}
