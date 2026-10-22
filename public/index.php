<?php
declare(strict_types=1);

// Configuración de zona horaria y errores
date_default_timezone_set('America/El_Salvador');
ini_set('display_errors', '1');
error_reporting(E_ALL);

// Autoload simple PSR-4 para App\
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/../src/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

use App\Shared\Auth;
use App\Shared\Response;
use App\Pos\PosController;
use App\CashRegister\CashController;
use App\Inventory\InventoryController;
use App\Billing\BillingController;
use App\Accounting\AccountingController;
use App\Reports\ReportsController;
use App\Auth\UserController;
use App\Catalog\CatalogController;
use App\Sales\SalesHistoryController;
use App\Purchases\PurchasesController;

Auth::initSession();

$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// ==========================================
// API ROUTING
// ==========================================

// Autenticación pública y cambio de usuario
if ($requestUri === '/api/auth/login' && $method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $username = trim($input['username'] ?? '');
    $password = (string)($input['password'] ?? '');

    if (Auth::attempt($username, $password)) {
        Response::success(['user' => Auth::user()], 'Bienvenido al sistema');
    } else {
        Response::error('Usuario o contraseña incorrectos', 401);
    }
}

if ($requestUri === '/api/auth/switch-user' && $method === 'POST') {
    (new UserController())->switchUser();
}

if ($requestUri === '/logout' || $requestUri === '/api/auth/logout') {
    Auth::logout();
    if (str_starts_with($requestUri, '/api/')) {
        Response::success([], 'Sesión finalizada');
    } else {
        header('Location: /?nocache=' . time());
        exit;
    }
}

if ($requestUri === '/api/auth/me') {
    if (Auth::check()) {
        Response::json(['authenticated' => true, 'user' => Auth::user()]);
    } else {
        Response::json(['authenticated' => false, 'user' => null]);
    }
}

// Rutas protegidas de API
if (str_starts_with($requestUri, '/api/')) {
    if (!Auth::check()) {
        Response::error('No autorizado', 401);
    }

    // Helper de control de acceso por roles (RBAC estricto)
    $requireRole = function(array|string $roles, string $msg = 'Acceso denegado: no tienes permisos asignados para esta acción.') {
        if (!Auth::hasRole($roles)) {
            Response::error($msg, 403);
        }
    };

    // POS
    if ($requestUri === '/api/pos/search-products') {
        $requireRole(['ADMIN', 'GERENTE', 'CAJERO']);
        (new PosController())->searchProducts();
    }
    if ($requestUri === '/api/pos/search-clients') {
        $requireRole(['ADMIN', 'GERENTE', 'CAJERO']);
        (new PosController())->searchClients();
    }
    if ($requestUri === '/api/pos/create-client' && $method === 'POST') {
        $requireRole(['ADMIN', 'GERENTE', 'CAJERO']);
        (new PosController())->createClient();
    }
    if ($requestUri === '/api/pos/checkout' && $method === 'POST') {
        $requireRole(['ADMIN', 'GERENTE', 'CAJERO']);
        (new PosController())->checkout();
    }
    if ($requestUri === '/api/pos/ticket') {
        $requireRole(['ADMIN', 'GERENTE', 'CAJERO', 'CONTADOR']);
        (new PosController())->getTicket();
    }

    // Caja y Turnos
    if ($requestUri === '/api/cash/session') {
        $requireRole(['ADMIN', 'GERENTE', 'CAJERO']);
        (new CashController())->currentSession();
    }
    if ($requestUri === '/api/cash/open' && $method === 'POST') {
        // La autorización de supervisor y asignación de cajero se verifica dentro de openSession()
        (new CashController())->openSession();
    }
    if ($requestUri === '/api/cash/movement' && $method === 'POST') {
        $requireRole(['ADMIN', 'GERENTE', 'CAJERO']);
        (new CashController())->addMovement();
    }
    if ($requestUri === '/api/cash/close' && $method === 'POST') {
        $requireRole(['ADMIN', 'GERENTE', 'CAJERO']);
        (new CashController())->closeSession();
    }
    if ($requestUri === '/api/cash/active-sessions' && $method === 'GET') {
        $requireRole(['ADMIN', 'GERENTE']);
        (new CashController())->listActiveSessions();
    }
    if ($requestUri === '/api/cash/terminals' && $method === 'GET') {
        $requireRole(['ADMIN', 'GERENTE']);
        (new CashController())->listTerminals();
    }
    if ($requestUri === '/api/cash/terminals' && $method === 'POST') {
        $requireRole(['ADMIN', 'GERENTE'], 'Acceso denegado: solo Administradores o Gerentes pueden crear o modificar cajas.');
        (new CashController())->saveTerminal();
    }

    // Inventario
    if ($requestUri === '/api/inventory/products') {
        $requireRole(['ADMIN', 'GERENTE', 'BODEGUERO', 'CONTADOR', 'CAJERO']);
        (new InventoryController())->listProducts();
    }
    if ($requestUri === '/api/inventory/save-product' && $method === 'POST') {
        $requireRole(['ADMIN', 'GERENTE', 'BODEGUERO'], 'Acceso denegado: no puedes crear ni modificar productos de inventario.');
        (new InventoryController())->saveProduct();
    }
    if ($requestUri === '/api/inventory/adjust-stock' && $method === 'POST') {
        $requireRole(['ADMIN', 'GERENTE', 'BODEGUERO'], 'Acceso denegado: no puedes realizar ajustes de stock.');
        (new InventoryController())->adjustStock();
    }
    if ($requestUri === '/api/inventory/kardex') {
        $requireRole(['ADMIN', 'GERENTE', 'BODEGUERO', 'CONTADOR'], 'Acceso denegado: consulta de Kardex restringida.');
        (new InventoryController())->getKardex();
    }

    // Usuarios (CRUD exclusivo para Administradores)
    if ($requestUri === '/api/users' && $method === 'GET') {
        $requireRole(['ADMIN'], 'Acceso denegado: solo Administradores pueden ver el listado de usuarios.');
        (new UserController())->listUsers();
    }
    if ($requestUri === '/api/users' && $method === 'POST') {
        $requireRole(['ADMIN'], 'Acceso denegado: solo Administradores pueden crear o editar usuarios.');
        (new UserController())->saveUser();
    }

    // Catálogos (Categorías, Marcas, Proveedores)
    if ($requestUri === '/api/catalog/categories' && $method === 'GET') {
        (new CatalogController())->listCategories();
    }
    if ($requestUri === '/api/catalog/categories' && $method === 'POST') {
        $requireRole(['ADMIN', 'GERENTE', 'BODEGUERO'], 'Acceso denegado para modificar categorías.');
        (new CatalogController())->saveCategory();
    }
    if ($requestUri === '/api/catalog/brands' && $method === 'GET') {
        (new CatalogController())->listBrands();
    }
    if ($requestUri === '/api/catalog/brands' && $method === 'POST') {
        $requireRole(['ADMIN', 'GERENTE', 'BODEGUERO'], 'Acceso denegado para modificar marcas.');
        (new CatalogController())->saveBrand();
    }
    if ($requestUri === '/api/catalog/suppliers' && $method === 'GET') {
        $requireRole(['ADMIN', 'GERENTE', 'BODEGUERO', 'CONTADOR'], 'Acceso denegado: directorio de proveedores restringido.');
        (new CatalogController())->listSuppliers();
    }
    if ($requestUri === '/api/catalog/suppliers' && $method === 'POST') {
        $requireRole(['ADMIN', 'GERENTE', 'BODEGUERO'], 'Acceso denegado para registrar proveedores.');
        (new CatalogController())->saveSupplier();
    }

    // Historial de Ventas
    if ($requestUri === '/api/sales/history' && $method === 'GET') {
        $requireRole(['ADMIN', 'GERENTE', 'CONTADOR'], 'Acceso denegado: los cajeros no tienen acceso al historial global de ventas.');
        (new SalesHistoryController())->listSales();
    }
    if ($requestUri === '/api/sales/detail' && $method === 'GET') {
        $requireRole(['ADMIN', 'GERENTE', 'CONTADOR', 'CAJERO']);
        (new SalesHistoryController())->getSaleDetail();
    }
    if ($requestUri === '/api/sales/void' && $method === 'POST') {
        $requireRole(['ADMIN', 'GERENTE'], 'Acceso denegado: solo Gerentes o Administradores pueden anular ventas.');
        (new SalesHistoryController())->voidSale();
    }

    // Compras a Proveedores
    if (($requestUri === '/api/purchases' || $requestUri === '/api/purchases/list') && $method === 'GET') {
        $requireRole(['ADMIN', 'GERENTE', 'BODEGUERO', 'CONTADOR'], 'Acceso denegado al módulo de compras.');
        (new PurchasesController())->listPurchases();
    }
    if ($requestUri === '/api/purchases/detail' && $method === 'GET') {
        $requireRole(['ADMIN', 'GERENTE', 'BODEGUERO', 'CONTADOR'], 'Acceso denegado al detalle de compras.');
        (new PurchasesController())->getPurchaseDetail();
    }
    if (($requestUri === '/api/purchases' || $requestUri === '/api/purchases/create') && $method === 'POST') {
        $requireRole(['ADMIN', 'GERENTE', 'BODEGUERO'], 'Acceso denegado: no puedes registrar facturas de compras.');
        (new PurchasesController())->createPurchase();
    }

    // Inventario Lotes e Importación Masiva
    if ($requestUri === '/api/inventory/lots') {
        $requireRole(['ADMIN', 'GERENTE', 'BODEGUERO']);
        (new InventoryController())->listLots();
    }
    if ($requestUri === '/api/inventory/import-csv' && $method === 'POST') {
        $requireRole(['ADMIN', 'GERENTE', 'BODEGUERO'], 'Acceso denegado: importación masiva no permitida.');
        (new InventoryController())->importCsv();
    }

    // Reportes & Dashboard
    if ($requestUri === '/api/reports/dashboard') {
        $requireRole(['ADMIN', 'GERENTE', 'CONTADOR'], 'Acceso denegado: Dashboard ejecutivo reservado para Gerencia y Contabilidad.');
        (new ReportsController())->dashboard();
    }
    if ($requestUri === '/api/reports/sales-by-product') {
        $requireRole(['ADMIN', 'GERENTE', 'CONTADOR']);
        (new ReportsController())->salesReport();
    }
    if ($requestUri === '/api/reports/kardex-valuation') {
        $requireRole(['ADMIN', 'GERENTE', 'CONTADOR']);
        (new ReportsController())->kardexValuationReport();
    }
    if ($requestUri === '/api/reports/cash-shifts') {
        $requireRole(['ADMIN', 'GERENTE', 'CONTADOR']);
        (new ReportsController())->cashShiftsReport();
    }

    // Contabilidad & Libros de IVA
    if ($requestUri === '/api/accounting/entries') {
        $requireRole(['ADMIN', 'CONTADOR', 'GERENTE'], 'Acceso denegado al módulo de asientos contables.');
        (new AccountingController())->listEntries();
    }
    if ($requestUri === '/api/accounting/entry-lines') {
        $requireRole(['ADMIN', 'CONTADOR', 'GERENTE']);
        (new AccountingController())->entryLines();
    }
    if ($requestUri === '/api/accounting/libro-consumidor-final') {
        $requireRole(['ADMIN', 'CONTADOR', 'GERENTE']);
        (new AccountingController())->libroConsumidorFinal();
    }
    if ($requestUri === '/api/accounting/libro-contribuyentes') {
        $requireRole(['ADMIN', 'CONTADOR', 'GERENTE']);
        (new AccountingController())->libroContribuyentes();
    }
    if ($requestUri === '/api/accounting/libro-compras') {
        $requireRole(['ADMIN', 'CONTADOR', 'GERENTE']);
        (new AccountingController())->libroCompras();
    }
    if ($requestUri === '/api/accounting/balance-comprobacion') {
        $requireRole(['ADMIN', 'CONTADOR', 'GERENTE']);
        (new AccountingController())->balanceComprobacion();
    }

    // Facturación DTE
    if ($requestUri === '/api/billing/dtes') {
        $requireRole(['ADMIN', 'CONTADOR', 'GERENTE'], 'Acceso denegado: listado DTE restringido.');
        (new BillingController())->listDte();
    }
    if ($requestUri === '/api/billing/json-dte') {
        $requireRole(['ADMIN', 'CONTADOR', 'GERENTE']);
        (new BillingController())->getJsonDte();
    }

    Response::error('Ruta de API no encontrada', 404);
}

// ==========================================
// FRONTEND SINGLE-PAGE VIEW RENDER
// ==========================================
$appName = "Sistema POS Supermercado";
$appConfig = require __DIR__ . '/../config/app.php';
$currentUser = Auth::user();
$userRole = $currentUser['rol'] ?? 'CAJERO';
$rolePermissions = [
    'ADMIN' => ['pos', 'cash', 'sales-history', 'inventory', 'purchases', 'catalog', 'users', 'dashboard', 'billing', 'accounting'],
    'GERENTE' => ['pos', 'cash', 'sales-history', 'inventory', 'purchases', 'catalog', 'dashboard', 'billing'],
    'CAJERO' => ['pos', 'cash'],
    'BODEGUERO' => ['inventory', 'purchases', 'catalog'],
    'CONTADOR' => ['accounting', 'billing', 'dashboard', 'sales-history']
];
$allowedTabs = $rolePermissions[$userRole] ?? ['pos', 'cash'];
$canShowTab = function(string $tab) use ($currentUser, $allowedTabs): bool {
    if (!$currentUser) return true;
    return in_array($tab, $allowedTabs, true);
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($appConfig['name']) ?> — El Salvador DTE</title>
    <!-- Google Fonts Plus Jakarta Sans & JetBrains Mono para números/código -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/app.css">
</head>
<body class="<?= $currentUser ? 'logged-in' : 'logged-out' ?>">

    <!-- PANTALLA DE LOGIN -->
    <div id="loginView" class="login-container <?= $currentUser ? 'hidden' : '' ?>">
        <div class="login-card">
            <div class="login-header">
                <div class="logo-badge-premium">
                    <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg>
                </div>
                <h2>Sistema POS Supermercado</h2>
                <p>El Salvador — Normativa DTE Ministerio de Hacienda</p>
            </div>
            <form id="loginForm" class="login-form">
                <div class="form-group">
                    <label for="username">Usuario</label>
                    <input type="text" id="username" name="username" class="form-control form-control-lg" placeholder="admin, cajero1, gerente..." required autofocus>
                </div>
                <div class="form-group">
                    <label for="password">Contraseña</label>
                    <input type="password" id="password" name="password" class="form-control form-control-lg" placeholder="••••••••" required>
                </div>
                <div id="loginError" class="alert alert-danger hidden"></div>
                <button type="submit" class="btn btn-primary btn-block btn-lg">
                    <span>Iniciar Sesión</span> ➔
                </button>
            </form>
            <div class="login-credentials-hint">
                <p><strong>Credenciales de prueba:</strong></p>
                <div class="demo-pills">
                    <span class="pill" onclick="quickFill('admin','admin123')">🔑 Admin: admin / admin123</span>
                    <span class="pill" onclick="quickFill('cajero1','cajero123')">💳 Cajero: cajero1 / cajero123</span>
                    <span class="pill" onclick="quickFill('gerente','gerente123')">👔 Gerente: gerente / gerente123</span>
                </div>
            </div>
        </div>
    </div>

    <!-- APLICACIÓN PRINCIPAL (POST-LOGIN) -->
    <div id="appView" class="app-container <?= !$currentUser ? 'hidden' : '' ?>">
        
        <!-- BARRA LATERAL / NAVEGACIÓN -->
        <aside class="app-sidebar">
            <div class="sidebar-brand">
                <div class="brand-icon">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg>
                </div>
                <div class="brand-info">
                    <h3>Supermercado POS</h3>
                    <span class="badge-sv">🇸🇻 DTE MH El Salvador</span>
                </div>
            </div>

            <nav class="sidebar-nav">
                <button class="nav-item active" data-tab="pos" style="<?= !$canShowTab('pos') ? 'display:none;' : '' ?>">
                    <span class="nav-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                    </span>
                    <span class="nav-label">Punto de Venta</span>
                    <span class="badge-shortcut">F1</span>
                </button>
                <button class="nav-item" data-tab="cash" style="<?= !$canShowTab('cash') ? 'display:none;' : '' ?>">
                    <span class="nav-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
                    </span>
                    <span class="nav-label">Control de Caja</span>
                </button>
                <button class="nav-item" data-tab="sales-history" style="<?= !$canShowTab('sales-history') ? 'display:none;' : '' ?>">
                    <span class="nav-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 17.5v-11"/></svg>
                    </span>
                    <span class="nav-label">Historial Ventas</span>
                </button>
                <button class="nav-item" data-tab="inventory" style="<?= !$canShowTab('inventory') ? 'display:none;' : '' ?>">
                    <span class="nav-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>
                    </span>
                    <span class="nav-label">Inventario & Kardex</span>
                </button>
                <button class="nav-item" data-tab="purchases" style="<?= !$canShowTab('purchases') ? 'display:none;' : '' ?>">
                    <span class="nav-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18.5" r="2.5"/><circle cx="7" cy="18.5" r="2.5"/></svg>
                    </span>
                    <span class="nav-label">Compras Proveedor</span>
                </button>
                <button class="nav-item" data-tab="catalog" style="<?= !$canShowTab('catalog') ? 'display:none;' : '' ?>">
                    <span class="nav-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2H2v10l9.29 9.29c.94.94 2.48.94 3.42 0l6.58-6.58c.94-.94.94-2.48 0-3.42L12 2Z"/><path d="M7 7h.01"/></svg>
                    </span>
                    <span class="nav-label">Categorías & Proveed.</span>
                </button>
                <button class="nav-item" data-tab="users" style="<?= !$canShowTab('users') ? 'display:none;' : '' ?>">
                    <span class="nav-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </span>
                    <span class="nav-label">Control Usuarios</span>
                </button>
                <button class="nav-item" data-tab="dashboard" style="<?= !$canShowTab('dashboard') ? 'display:none;' : '' ?>">
                    <span class="nav-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/></svg>
                    </span>
                    <span class="nav-label">Dashboard & Métricas</span>
                </button>
                <button class="nav-item" data-tab="billing" style="<?= !$canShowTab('billing') ? 'display:none;' : '' ?>">
                    <span class="nav-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><path d="m9 15 2 2 4-4"/></svg>
                    </span>
                    <span class="nav-label">Facturación DTE</span>
                </button>
                <button class="nav-item" data-tab="accounting" style="<?= !$canShowTab('accounting') ? 'display:none;' : '' ?>">
                    <span class="nav-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m16 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"/><path d="m2 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"/><path d="M7 21h10"/><path d="M12 3v18"/><path d="M3 7h2c2 0 5-1 7-2 2 1 5 2 7 2h2"/></svg>
                    </span>
                    <span class="nav-label">Contabilidad & IVA</span>
                </button>
            </nav>

            <div class="sidebar-user">
                <div class="user-avatar" id="userInitial">U</div>
                <div class="user-details">
                    <div class="user-name" id="userDisplayName"><?= htmlspecialchars($currentUser['nombre_completo'] ?? 'Usuario') ?></div>
                    <div class="user-role-badge" id="userDisplayRole"><?= htmlspecialchars($currentUser['rol'] ?? 'ROL') ?></div>
                </div>
                <button id="btnSwitchUserTop" class="btn-logout" title="Cambiar usuario en caliente">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 21h5v-5"/></svg>
                </button>
                <button id="btnLogout" class="btn-logout" title="Cerrar sesión">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></svg>
                </button>
            </div>
        </aside>

        <!-- CONTENIDO PRINCIPAL -->
        <main class="app-main">

            <!-- HEADER SUPERIOR -->
            <header class="app-header">
                <div class="header-left">
                    <div class="breadcrumb-trail">
                        <span class="bc-app">POS</span>
                        <span class="bc-sep">/</span>
                        <h2 id="pageTitle" class="bc-current">Punto de Venta</h2>
                    </div>
                    <span id="sessionStatusBadge" class="status-indicator">Consultando caja...</span>
                </div>
                <div class="header-right">
                    <button class="btn btn-secondary btn-sm" id="btnQuickSwitchUser" style="display:flex; align-items:center; gap:0.4rem;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        Cambiar Cajero
                    </button>
                    <button class="btn btn-outline btn-sm" id="btnHeaderLogout" style="display:flex; align-items:center; gap:0.4rem; color:#dc2626; border-color:#fca5a5; background:#fff;" title="Cerrar sesión actual">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></svg>
                        Cerrar Sesión
                    </button>
                    <div class="shortcut-strip">
                        <span class="chip"><kbd>F1</kbd> Buscar</span>
                        <span class="chip"><kbd>F2</kbd> Cantidad</span>
                        <span class="chip"><kbd>F3</kbd> Descuento</span>
                        <span class="chip chip-accent"><kbd>F4</kbd> Cobrar</span>
                        <span class="chip"><kbd>F8</kbd> En Espera</span>
                        <span class="chip"><kbd>F9</kbd> Cliente</span>
                    </div>
                    <div class="clock" id="liveClock">00:00:00</div>
                </div>
            </header>

            <div class="app-content-area">

                <!-- 1. TAB: PUNTO DE VENTA (POS) -->
                <section id="tab-pos" class="tab-pane active">
                    
                    <!-- BANNER BLOQUEO CUANDO LA CAJA ESTÁ CERRADA -->
                    <div id="posCashClosedOverlay" class="pos-locked-banner hidden">
                        <div class="pos-locked-card">
                            <div class="locked-left">
                                <span class="locked-icon">
                                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#f87171" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                </span>
                                <div class="locked-text">
                                    <h4>Terminal Bloqueada: Turno de Caja Cerrado</h4>
                                    <p>No hay un turno de caja abierto para registrar ventas. Realiza la apertura con fondo inicial en efectivo para habilitar el escáner y el cobro.</p>
                                </div>
                            </div>
                            <button class="btn btn-primary" onclick="openModal('modalOpenCash')" style="display:inline-flex; align-items:center; gap:0.5rem;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg>
                                Abrir Turno de Caja Ahora
                            </button>
                        </div>
                    </div>

                    <div class="pos-layout">
                        
                        <!-- Columna Izquierda: Escáner, Búsqueda y Carrito -->
                        <div class="pos-cart-panel">
                            
                            <!-- Barra de Escáner y Entrada Rápida -->
                            <div class="scanner-bar">
                                <div class="scanner-input-wrap">
                                    <span class="scanner-icon">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" x2="16.65" y1="21" y2="16.65"/></svg>
                                    </span>
                                    <input type="text" id="barcodeInput" placeholder="Escanear código de barras o presionar F1 para buscar..." autocomplete="off" autofocus>
                                    <button class="btn btn-secondary btn-sm" id="btnOpenSearchModal">F1 Búsqueda</button>
                                </div>
                                <div class="client-selector-bar">
                                    <span class="client-label">Cliente:</span>
                                    <span id="selectedClientName" class="client-name">Consumidor Final</span>
                                    <button class="btn btn-link btn-xs" id="btnChangeClient">F9 Cambiar</button>
                                </div>
                            </div>

                            <!-- Tabla del Carrito -->
                            <div class="cart-table-wrapper">
                                <table class="table cart-table" id="cartTable">
                                    <thead>
                                        <tr>
                                            <th style="width: 5%">#</th>
                                            <th style="width: 45%">Producto</th>
                                            <th style="width: 15%" class="text-right">Precio</th>
                                            <th style="width: 15%" class="text-center">Cant.</th>
                                            <th style="width: 15%" class="text-right">Total</th>
                                            <th style="width: 5%"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="cartTableBody">
                                        <tr class="empty-row">
                                            <td colspan="6">
                                                <div class="empty-cart-state">
                                                    <span class="empty-icon">🛒</span>
                                                    <h4>Carrito vacío</h4>
                                                    <p>Pasa un producto por el lector láser o presiona <kbd>F1</kbd> para buscar en el catálogo.</p>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Botonera Rápida de Acciones -->
                            <div class="pos-actions-bar">
                                <button class="btn btn-outline" id="btnHoldSale">
                                    <span>⏸️</span> F8 Poner en Espera (<span id="holdSalesCount">0</span>)
                                </button>
                                <button class="btn btn-outline text-danger" id="btnClearCart">
                                    <span>🗑️</span> Vaciar Carrito
                                </button>
                            </div>
                        </div>

                        <!-- Columna Derecha: Totales y Cobro -->
                        <div class="pos-checkout-panel">
                            <div class="totals-card">
                                <div class="total-row">
                                    <span>Subtotal Neto:</span>
                                    <span class="amount" id="summarySubtotal">$0.00</span>
                                </div>
                                <div class="total-row">
                                    <span>IVA (13% Incluido):</span>
                                    <span class="amount" id="summaryIva">$0.00</span>
                                </div>
                                <div class="total-row text-warning">
                                    <span>Descuento:</span>
                                    <span class="amount" id="summaryDiscount">-$0.00</span>
                                </div>
                                <div class="total-divider"></div>
                                <div class="grand-total-row">
                                    <span class="grand-label">TOTAL A PAGAR</span>
                                    <span class="grand-amount" id="summaryGrandTotal">$0.00</span>
                                </div>
                            </div>

                            <!-- Tipo de Documento Tributario -->
                            <div class="document-type-selector">
                                <label class="doc-radio">
                                    <input type="radio" name="docType" value="01" checked>
                                    <span class="radio-badge">Factura (01)</span>
                                </label>
                                <label class="doc-radio">
                                    <input type="radio" name="docType" value="03">
                                    <span class="radio-badge">Crédito Fiscal (03)</span>
                                </label>
                            </div>

                            <!-- Botón Principal Cobrar -->
                            <button id="btnPayNow" class="btn btn-success btn-lg btn-block btn-pay">
                                <span class="pay-icon">💳</span>
                                <div class="pay-text">
                                    <span class="title">F4 COBRAR VENTA</span>
                                    <span class="subtitle">Efectivo, Tarjeta, Transferencia, Mixto</span>
                                </div>
                            </button>
                        </div>

                    </div>
                </section>

                <!-- 2. TAB: CONTROL DE CAJA Y TURNOS -->
                <section id="tab-cash" class="tab-pane">
                    <div class="cash-register-view">
                        <div class="view-header">
                            <div class="view-title-block">
                                <h3>Arqueo y Sesión Activa</h3>
                                <p>Registro de movimientos de gaveta, arqueo ciego y emisión de Corte Z inmutable.</p>
                            </div>
                            <div class="view-actions" id="cashActionButtons">
                                <!-- Se llena dinámicamente según sesión abierta/cerrada -->
                            </div>
                        </div>

                        <!-- MONITOR DE CAJAS ACTIVAS (EXCLUSIVO PARA ADMIN Y GERENTE) -->
                        <div id="adminActiveCashPanel" class="card mb-4 hidden" style="border: 1.5px solid var(--border-color); background: #ffffff; border-radius: 12px; box-shadow: var(--shadow-sm); overflow: hidden;">
                            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; background:#f8fafc; border-bottom:1px solid var(--border-color); padding:1rem 1.25rem;">
                                <div>
                                    <h4 style="margin:0; font-size:1.05rem; display:flex; align-items:center; gap:0.6rem; color:var(--text-primary);">
                                        <span style="font-size:1.25rem;">🖥️</span> Monitor de Terminales y Cajas Activas en Sucursal
                                        <span class="badge badge-success font-mono" id="activeCashCountBadge" style="font-size:0.75rem; padding:0.25rem 0.6rem;">0 cajas activas</span>
                                    </h4>
                                    <p class="text-muted" style="margin:0.25rem 0 0 0; font-size:0.8125rem;">Supervisión en vivo de gavetas abiertas. Los administradores pueden forzar o registrar el Corte Z directamente desde esta tabla.</p>
                                </div>
                                <div style="display:flex; gap:0.5rem; align-items:center;">
                                    <button class="btn btn-secondary btn-sm" onclick="openModal('modalManageTerminals'); loadTerminalsCatalog();" style="display:inline-flex; align-items:center; gap:0.4rem;">
                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                                        ⚙️ Administrar Cajas
                                    </button>
                                    <button class="btn btn-outline btn-sm" id="btnRefreshActiveCash" onclick="loadActiveCashSessions()" title="Actualizar lista">
                                        🔄 Actualizar
                                    </button>
                                </div>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table" style="margin:0; font-size:0.875rem;">
                                        <thead>
                                            <tr style="background:#f1f5f9;">
                                                <th style="padding:0.75rem 1rem;">Terminal / Caja</th>
                                                <th style="padding:0.75rem 1rem;">Cajero Titular</th>
                                                <th style="padding:0.75rem 1rem;">Apertura</th>
                                                <th style="padding:0.75rem 1rem;" class="text-right">Fondo Inicial</th>
                                                <th style="padding:0.75rem 1rem;" class="text-right">Ventas Efectivo</th>
                                                <th style="padding:0.75rem 1rem;" class="text-right">Total Facturado</th>
                                                <th style="padding:0.75rem 1rem;" class="text-center">Tickets</th>
                                                <th style="padding:0.75rem 1rem;" class="text-right">Efectivo Gaveta</th>
                                                <th style="padding:0.75rem 1rem;" class="text-center">Estado</th>
                                                <th style="padding:0.75rem 1rem; width:140px;" class="text-center">Acción</th>
                                            </tr>
                                        </thead>
                                        <tbody id="activeCashTableBody">
                                            <tr>
                                                <td colspan="10" class="text-center text-muted p-4">Cargando cajas activas...</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <div id="personalCashSessionSection">
                            <h4 id="personalCashTitle" class="mb-2" style="font-size:0.95rem; color:var(--text-secondary); display:flex; align-items:center; gap:0.5rem;">
                                <span>👤</span> Mi Turno de Caja Personal
                            </h4>
                            <div id="cashSessionDetails" class="cash-cards-grid">
                                <!-- Métricas de caja en tiempo real -->
                            </div>
                        </div>

                        <!-- Historial de Movimientos de la sesión -->
                        <div class="card mt-4">
                            <div class="card-header">
                                <h4>Movimientos del Turno (Ingresos / Retiros)</h4>
                            </div>
                            <div class="card-body p-0">
                                <table class="table" id="cashMovementsTable">
                                    <thead>
                                        <tr>
                                            <th>Hora</th>
                                            <th>Tipo</th>
                                            <th>Monto</th>
                                            <th>Motivo</th>
                                            <th>Autorizado Por</th>
                                        </tr>
                                    </thead>
                                    <tbody id="cashMovementsTableBody">
                                        <tr><td colspan="5" class="text-center text-muted">No hay movimientos registrados en este turno.</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- 3. TAB: INVENTARIO Y KARDEX -->
                <section id="tab-inventory" class="tab-pane">
                    <div class="inventory-view">
                        <div class="view-header">
                            <div>
                                <h3>Catálogo de Productos y Existencias</h3>
                                <p>Control multisede (Piso de Venta / Bodega Principal) y trazabilidad Kardex.</p>
                            </div>
                            <div class="view-actions">
                                <button class="btn btn-primary" id="btnNewProduct">➕ Nuevo Producto</button>
                                <button class="btn btn-secondary" id="btnQuickAdjust">⚖️ Ajuste / Merma</button>
                                <button class="btn btn-outline" id="btnImportCsv">📥 Carga Masiva CSV</button>
                                <button class="btn btn-outline" id="btnViewLots">📅 Lotes & Vencimientos</button>
                            </div>
                        </div>

                        <!-- Filtros de Inventario -->
                        <div class="filter-bar">
                            <input type="text" id="inventorySearchInput" placeholder="Buscar por nombre, código o barra..." class="form-control" style="max-width: 350px;">
                            <select id="inventoryCategoryFilter" class="form-control" style="max-width: 200px;">
                                <option value="0">Todas las categorías</option>
                            </select>
                        </div>

                        <!-- Tabla de Catálogo -->
                        <div class="card mt-3">
                            <div class="card-body p-0">
                                <table class="table inventory-table" id="inventoryTable">
                                    <thead>
                                        <tr>
                                            <th>Código</th>
                                            <th>Barras</th>
                                            <th>Producto</th>
                                            <th>Categoría</th>
                                            <th class="text-right">Costo Prom.</th>
                                            <th class="text-right">Precio Venta</th>
                                            <th class="text-center">Piso Venta</th>
                                            <th class="text-center">Bodega</th>
                                            <th class="text-center">Total</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody id="inventoryTableBody">
                                        <tr><td colspan="10" class="text-center">Cargando inventario...</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- 4. TAB: DASHBOARD Y MÉTRICAS -->
                <section id="tab-dashboard" class="tab-pane">
                    <div class="dashboard-view">
                        <div class="dashboard-header-bar">
                            <div class="dashboard-header-title">
                                <h3>📊 Centro de Comando Ejecutivo en Tiempo Real</h3>
                                <p>Supervisión continua de ventas, rentabilidad, rotación de góndola y estado operativo de terminales.</p>
                            </div>
                            <div class="dashboard-header-actions">
                                <span class="live-badge">
                                    <span class="status-dot green"></span> EN VIVO: <span id="dashLastUpdate">—</span>
                                </span>
                                <button class="btn btn-outline btn-sm" id="btnRefreshDashboard">🔄 Refrescar Métricas</button>
                            </div>
                        </div>

                        <!-- 4 HERO KPIS EJECUTIVOS -->
                        <div class="kpi-grid" id="dashboardKpis">
                            <!-- Inyectado dinámicamente con app.js -->
                        </div>

                        <!-- SPLIT EJECUTIVO: MIX DE PAGO Y TOP PRODUCTOS -->
                        <div class="dashboard-split">
                            <!-- 1. Desglose de Medios de Pago -->
                            <div class="card">
                                <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                                    <h4>💳 Mix de Medios de Pago Hoy</h4>
                                    <span class="badge badge-success font-mono" id="dashTotalPagosHoy">$0.00</span>
                                </div>
                                <div class="payment-mix-list" id="dashPaymentMix">
                                    <!-- Barras de progreso de pago -->
                                </div>
                            </div>

                            <!-- 2. Top Productos Más Vendidos -->
                            <div class="card">
                                <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                                    <h4>🏆 Top 5 Productos de Mayor Desempeño</h4>
                                    <span class="text-muted" style="font-size:0.75rem;">Ventas completadas hoy</span>
                                </div>
                                <div class="ranking-list" id="dashTopProducts">
                                    <!-- Ranking con podio -->
                                </div>
                            </div>
                        </div>

                        <!-- ALERTA DE STOCK Y RIESGO DE GÓNDOLA -->
                        <div class="card mb-4">
                            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; background:#fffbeb; border-bottom:1px solid #fde68a;">
                                <h4 style="color:#92400e;">⚠️ Semáforo de Riesgo Operativo (Stock Mínimo y Vencimientos FEFO)</h4>
                                <button class="btn btn-outline btn-xs" onclick="switchTab('inventory')">📦 Ir a Inventario</button>
                            </div>
                            <div class="card-body p-0">
                                <table class="table" id="alertsTable">
                                    <thead>
                                        <tr>
                                            <th>Estado</th>
                                            <th>Producto</th>
                                            <th>Diagnóstico / Existencias</th>
                                            <th class="text-center">Acción</th>
                                        </tr>
                                    </thead>
                                    <tbody id="alertsTableBody">
                                        <tr><td colspan="4" class="text-center">Consultando alertas...</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- SECCIÓN REPORTES ESPECIALIZADOS -->
                        <div class="card mt-4">
                            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.5rem;">
                                <div>
                                    <h4>📈 Módulo de Reportes Especializados</h4>
                                    <p style="font-size:0.8125rem; color:var(--text-secondary); margin:0;">Márgenes de ganancia, auditoría de cajas y valuación oficial de inventario.</p>
                                </div>
                                <div style="display:flex; gap:0.5rem;">
                                    <button class="btn btn-outline btn-sm report-tab-btn active" data-report="sales">Ventas & Márgenes</button>
                                    <button class="btn btn-outline btn-sm report-tab-btn" data-report="kardex">Valuación Kardex (CPP)</button>
                                    <button class="btn btn-outline btn-sm report-tab-btn" data-report="shifts">Auditoría Turnos (Cortes Z)</button>
                                </div>
                            </div>
                            <div class="card-body p-0">
                                <!-- Reporte 1: Ventas por producto & márgenes -->
                                <div id="report-view-sales" class="report-pane p-3">
                                    <div class="filter-bar mb-3" style="display:flex; gap:0.5rem; align-items:center;">
                                        <label style="font-size:0.75rem;">Desde:</label>
                                        <input type="date" id="repSalesFrom" class="form-control form-control-sm" style="max-width:140px;">
                                        <label style="font-size:0.75rem;">Hasta:</label>
                                        <input type="date" id="repSalesTo" class="form-control form-control-sm" style="max-width:140px;">
                                        <button class="btn btn-primary btn-sm" id="btnFilterRepSales">Generar Reporte</button>
                                    </div>
                                    <table class="table" id="reportSalesTable">
                                        <thead>
                                            <tr>
                                                <th>Código</th>
                                                <th>Producto</th>
                                                <th>Categoría</th>
                                                <th class="text-center">Cant. Vendida</th>
                                                <th class="text-right">Venta Total</th>
                                                <th class="text-right">Costo Total</th>
                                                <th class="text-right">Ganancia Bruta</th>
                                                <th class="text-center">Margen %</th>
                                            </tr>
                                        </thead>
                                        <tbody id="reportSalesTableBody">
                                            <tr><td colspan="8" class="text-center">Seleccione un rango y presione Generar Reporte.</td></tr>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Reporte 2: Kardex Valorizado CPP -->
                                <div id="report-view-kardex" class="report-pane p-3 hidden">
                                    <div class="mb-3" style="display:flex; justify-content:space-between; align-items:center;">
                                        <h5>Valuación de Existencias al Costo Promedio Ponderado</h5>
                                        <button class="btn btn-secondary btn-sm" id="btnRefreshKardexVal">🔄 Actualizar Valuación</button>
                                    </div>
                                    <div class="kpi-grid mb-3" style="grid-template-columns: repeat(3, 1fr);" id="kardexValSummary">
                                        <!-- Resumen Valuación -->
                                    </div>
                                    <table class="table" id="reportKardexValTable">
                                        <thead>
                                            <tr>
                                                <th>Código</th>
                                                <th>Producto</th>
                                                <th>Categoría</th>
                                                <th>Bodega</th>
                                                <th class="text-center">Stock Actual</th>
                                                <th class="text-right">Costo Unit. CPP</th>
                                                <th class="text-right">Precio Venta</th>
                                                <th class="text-right">Valor Total en Libros</th>
                                            </tr>
                                        </thead>
                                        <tbody id="reportKardexValTableBody">
                                            <tr><td colspan="8" class="text-center">Presione Actualizar Valuación.</td></tr>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Reporte 3: Cortes Z y Turnos -->
                                <div id="report-view-shifts" class="report-pane p-3 hidden">
                                    <div class="mb-3" style="display:flex; justify-content:space-between; align-items:center;">
                                        <h5>Histórico de Turnos y Auditoría de Arqueos de Caja</h5>
                                        <button class="btn btn-secondary btn-sm" id="btnRefreshShiftsRep">🔄 Actualizar Turnos</button>
                                    </div>
                                    <table class="table" id="reportShiftsTable">
                                        <thead>
                                            <tr>
                                                <th>Turno #</th>
                                                <th>Caja</th>
                                                <th>Cajero</th>
                                                <th>Apertura</th>
                                                <th>Cierre</th>
                                                <th class="text-right">Fondo Inicial</th>
                                                <th class="text-right">Ventas Efectivo</th>
                                                <th class="text-right">Efectivo Esperado</th>
                                                <th class="text-right">Efectivo Real</th>
                                                <th class="text-center">Diferencia</th>
                                                <th>Supervisor Cierre</th>
                                            </tr>
                                        </thead>
                                        <tbody id="reportShiftsTableBody">
                                            <tr><td colspan="11" class="text-center">Presione Actualizar Turnos.</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- 5. TAB: FACTURACIÓN ELECTRÓNICA DTE -->
                <section id="tab-billing" class="tab-pane">
                    <div class="billing-view">
                        <div class="view-header">
                            <div>
                                <h3>Documentos Tributarios Electrónicos (DTE)</h3>
                                <p>Emisión bajo normativa del Ministerio de Hacienda de El Salvador.</p>
                            </div>
                            <span class="badge badge-success">Ambiente: Pruebas (MH SV)</span>
                        </div>

                        <div class="card mt-3">
                            <div class="card-body p-0">
                                <table class="table" id="dtesTable">
                                    <thead>
                                        <tr>
                                            <th>Fecha/Hora</th>
                                            <th>Tipo</th>
                                            <th>Número Control</th>
                                            <th>Código Generación (UUID)</th>
                                            <th>Cliente</th>
                                            <th class="text-right">Monto</th>
                                            <th class="text-center">Estado DTE</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody id="dtesTableBody">
                                        <tr><td colspan="8" class="text-center">Cargando documentos DTE...</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- 6. TAB: CONTABILIDAD Y LIBROS FISCALES -->
                <section id="tab-accounting" class="tab-pane">
                    <div class="accounting-view">
                        <div class="view-header">
                            <div>
                                <h3>Contabilidad Integrada y Libros Fiscales de IVA</h3>
                                <p>Partida doble automática bajo NIIF y generación de libros fiscales normados por el Ministerio de Hacienda.</p>
                            </div>
                            <div class="view-actions" style="display:flex; gap:0.5rem; flex-wrap:wrap;">
                                <button class="btn btn-primary btn-sm accounting-tab-btn active" data-acctab="entries">📑 Asientos Diarios</button>
                                <button class="btn btn-outline btn-sm accounting-tab-btn" data-acctab="consumidor">📖 Libro Consumidor Final</button>
                                <button class="btn btn-outline btn-sm accounting-tab-btn" data-acctab="ccf">📑 Libro Contribuyentes (CCF)</button>
                                <button class="btn btn-outline btn-sm accounting-tab-btn" data-acctab="compras">📥 Libro de Compras</button>
                                <button class="btn btn-outline btn-sm accounting-tab-btn" data-acctab="balance">⚖️ Balance Comprobación</button>
                            </div>
                        </div>

                        <!-- 6.1 Sub-panel: Asientos Diarios -->
                        <div id="acctab-content-entries" class="acctab-pane mt-3">
                            <div class="card">
                                <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                                    <h4>Diario General — Asientos Contables Generados Automáticamente</h4>
                                    <button class="btn btn-outline btn-xs" id="btnRefreshAccounting">🔄 Refrescar</button>
                                </div>
                                <div class="card-body p-0">
                                    <table class="table" id="accountingEntriesTable">
                                        <thead>
                                            <tr>
                                                <th>No. Asiento</th>
                                                <th>Fecha</th>
                                                <th>Concepto</th>
                                                <th>Módulo Origen</th>
                                                <th class="text-right">Total Debe</th>
                                                <th class="text-right">Total Haber</th>
                                                <th class="text-center">Estado</th>
                                                <th class="text-center">Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody id="accountingEntriesTableBody">
                                            <tr><td colspan="8" class="text-center">Cargando asientos contables...</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- 6.2 Sub-panel: Libro Consumidor Final -->
                        <div id="acctab-content-consumidor" class="acctab-pane mt-3 hidden">
                            <div class="card">
                                <div class="card-header bg-primary text-white">
                                    <h4 class="text-white">📖 Libro de Ventas a Consumidor Final (Art. 107 Cód. Tributario El Salvador)</h4>
                                </div>
                                <div class="card-body p-0">
                                    <table class="table" id="libroConsumidorTable">
                                        <thead>
                                            <tr>
                                                <th>Fecha</th>
                                                <th>Del No.</th>
                                                <th>Al No.</th>
                                                <th>Caja #</th>
                                                <th class="text-right">Ventas Exentas</th>
                                                <th class="text-right">Ventas Gravadas</th>
                                                <th class="text-right">Total Diario</th>
                                            </tr>
                                        </thead>
                                        <tbody id="libroConsumidorTableBody">
                                            <tr><td colspan="7" class="text-center">Sin registros fiscales en el período</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- 6.3 Sub-panel: Libro Contribuyentes CCF -->
                        <div id="acctab-content-ccf" class="acctab-pane mt-3 hidden">
                            <div class="card">
                                <div class="card-header bg-primary text-white">
                                    <h4 class="text-white">📑 Libro de Ventas a Contribuyentes (Comprobantes de Crédito Fiscal)</h4>
                                </div>
                                <div class="card-body p-0">
                                    <table class="table" id="libroCcfTable">
                                        <thead>
                                            <tr>
                                                <th>Fecha</th>
                                                <th>No. CCF</th>
                                                <th>Cliente / Razón Social</th>
                                                <th>NRC</th>
                                                <th class="text-right">Gravado</th>
                                                <th class="text-right">Débito Fiscal (13%)</th>
                                                <th class="text-right">Ret. 1%</th>
                                                <th class="text-right">Total Venta</th>
                                            </tr>
                                        </thead>
                                        <tbody id="libroCcfTableBody">
                                            <tr><td colspan="8" class="text-center">Sin comprobantes de crédito fiscal emitidos</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- 6.4 Sub-panel: Libro de Compras -->
                        <div id="acctab-content-compras" class="acctab-pane mt-3 hidden">
                            <div class="card">
                                <div class="card-header bg-primary text-white">
                                    <h4 class="text-white">📥 Libro de Compras (IVA Crédito Fiscal)</h4>
                                </div>
                                <div class="card-body p-0">
                                    <table class="table" id="libroComprasTable">
                                        <thead>
                                            <tr>
                                                <th>Fecha</th>
                                                <th>No. Factura/CCF</th>
                                                <th>Proveedor</th>
                                                <th>NRC</th>
                                                <th class="text-right">Compras Gravadas</th>
                                                <th class="text-right">Crédito Fiscal (13%)</th>
                                                <th class="text-right">Percepción 1%</th>
                                                <th class="text-right">Total Compra</th>
                                            </tr>
                                        </thead>
                                        <tbody id="libroComprasTableBody">
                                            <tr><td colspan="8" class="text-center">Sin compras registradas en el período</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- 6.5 Sub-panel: Balance de Comprobación -->
                        <div id="acctab-content-balance" class="acctab-pane mt-3 hidden">
                            <div class="card">
                                <div class="card-header bg-emerald text-white">
                                    <h4 class="text-white">⚖️ Balance de Comprobación de Sumas y Saldos</h4>
                                </div>
                                <div class="card-body p-0">
                                    <table class="table" id="balanceComprobacionTable">
                                        <thead>
                                            <tr>
                                                <th>Código Cuenta</th>
                                                <th>Nombre de Cuenta</th>
                                                <th>Tipo</th>
                                                <th class="text-right">Total Debe</th>
                                                <th class="text-right">Total Haber</th>
                                                <th class="text-right">Saldo Deudor</th>
                                                <th class="text-right">Saldo Acreedor</th>
                                            </tr>
                                        </thead>
                                        <tbody id="balanceComprobacionTableBody">
                                            <tr><td colspan="7" class="text-center">Generando balance...</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- 7. TAB: HISTORIAL Y AUDITORÍA DE VENTAS -->
                <section id="tab-sales-history" class="tab-pane">
                    <div class="sales-history-view">
                        <div class="view-header">
                            <div>
                                <h3>Historial y Auditoría de Ventas</h3>
                                <p>Consulta de tickets emitidos, reimpresión y anulación supervisada.</p>
                            </div>
                        </div>

                        <!-- Filtros de búsqueda de ventas -->
                        <div class="card mt-2 p-3">
                            <div class="filter-bar" style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center;">
                                <div>
                                    <label style="font-size: 0.75rem; color: var(--text-secondary); display:block;">Desde:</label>
                                    <input type="date" id="salesFilterDesde" class="form-control form-control-sm">
                                </div>
                                <div>
                                    <label style="font-size: 0.75rem; color: var(--text-secondary); display:block;">Hasta:</label>
                                    <input type="date" id="salesFilterHasta" class="form-control form-control-sm">
                                </div>
                                <div>
                                    <label style="font-size: 0.75rem; color: var(--text-secondary); display:block;">Cajero:</label>
                                    <select id="salesFilterCajero" class="form-control form-control-sm">
                                        <option value="0">Todos los cajeros</option>
                                    </select>
                                </div>
                                <div>
                                    <label style="font-size: 0.75rem; color: var(--text-secondary); display:block;">Estado:</label>
                                    <select id="salesFilterEstado" class="form-control form-control-sm">
                                        <option value="">Todos los estados</option>
                                        <option value="COMPLETADA">Completada</option>
                                        <option value="ANULADA">Anulada</option>
                                    </select>
                                </div>
                                <div style="flex: 1; min-width: 200px;">
                                    <label style="font-size: 0.75rem; color: var(--text-secondary); display:block;">Buscar Ticket / Cliente:</label>
                                    <input type="text" id="salesFilterQuery" class="form-control form-control-sm" placeholder="No. Venta, UUID o Cliente...">
                                </div>
                                <div style="align-self: flex-end; display: flex; gap: 0.5rem;">
                                    <button class="btn btn-primary btn-sm" id="btnFilterSales">🔍 Filtrar</button>
                                    <button class="btn btn-outline btn-sm" id="btnResetSalesFilter" title="Restablecer filtros">↺ Todos</button>
                                </div>
                            </div>
                        </div>

                        <!-- Tabla de Ventas -->
                        <div class="card mt-3">
                            <div class="card-body p-0">
                                <table class="table" id="salesHistoryTable">
                                    <thead>
                                        <tr>
                                            <th>Fecha/Hora</th>
                                            <th>No. Venta</th>
                                            <th>Doc</th>
                                            <th>Cajero</th>
                                            <th>Cliente</th>
                                            <th class="text-right">Total</th>
                                            <th class="text-center">Estado</th>
                                            <th class="text-center">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody id="salesHistoryTableBody">
                                        <tr><td colspan="8" class="text-center">Cargando ventas...</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- 8. TAB: COMPRAS Y ABASTECIMIENTO -->
                <section id="tab-purchases" class="tab-pane">
                    <div class="purchases-view">
                        <div class="view-header">
                            <div>
                                <h3>Compras y Abastecimiento de Mercaderías</h3>
                                <p>Ingreso de facturas de proveedores, costeo ponderado y aumento de existencias.</p>
                            </div>
                            <button class="btn btn-primary" id="btnOpenNewPurchaseModal">➕ Registrar Factura de Compra</button>
                        </div>

                        <div class="card mt-3">
                            <div class="card-header">
                                <h4>Facturas de Compra Registradas</h4>
                            </div>
                            <div class="card-body p-0">
                                <table class="table" id="purchasesTable">
                                    <thead>
                                        <tr>
                                            <th>Fecha</th>
                                            <th>No. Factura</th>
                                            <th>Tipo</th>
                                            <th>Proveedor</th>
                                            <th>Bodega Destino</th>
                                            <th class="text-right">Subtotal</th>
                                            <th class="text-right">IVA</th>
                                            <th class="text-right">Total</th>
                                            <th class="text-center">Condición</th>
                                            <th class="text-center">Acción</th>
                                        </tr>
                                    </thead>
                                    <tbody id="purchasesTableBody">
                                        <tr><td colspan="10" class="text-center">Cargando compras...</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- 9. TAB: CATEGORÍAS, MARCAS Y PROVEEDORES -->
                <section id="tab-catalog" class="tab-pane">
                    <div class="catalog-view">
                        <div class="view-header">
                            <div>
                                <h3>Maestros de Catálogo: Categorías, Marcas y Proveedores</h3>
                                <p>Administración de familias de productos y directorio de proveedores fiscales.</p>
                            </div>
                            <div class="view-actions">
                                <button class="btn btn-primary" id="btnOpenCategoryModal">➕ Nueva Categoría</button>
                                <button class="btn btn-secondary" id="btnOpenSupplierModal">🏢 Nuevo Proveedor</button>
                                <button class="btn btn-outline" id="btnOpenBrandModal">🏷️ Nueva Marca</button>
                            </div>
                        </div>

                        <div class="dashboard-split mt-3">
                            <!-- Categorías -->
                            <div class="card">
                                <div class="card-header">
                                    <h4>Categorías Jerárquicas</h4>
                                </div>
                                <div class="card-body p-0">
                                    <table class="table" id="categoriesTable">
                                        <thead>
                                            <tr>
                                                <th>Categoría</th>
                                                <th>Depende de</th>
                                                <th class="text-center">Productos</th>
                                                <th class="text-right">Acción</th>
                                            </tr>
                                        </thead>
                                        <tbody id="categoriesTableBody">
                                            <tr><td colspan="4" class="text-center">Cargando categorías...</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- Proveedores -->
                            <div class="card">
                                <div class="card-header">
                                    <h4>Directorio de Proveedores</h4>
                                </div>
                                <div class="card-body p-0">
                                    <table class="table" id="suppliersTable">
                                        <thead>
                                            <tr>
                                                <th>Razón Social</th>
                                                <th>NIT / NRC</th>
                                                <th>Contacto</th>
                                                <th class="text-center">Días Créd.</th>
                                                <th class="text-right">Acción</th>
                                            </tr>
                                        </thead>
                                        <tbody id="suppliersTableBody">
                                            <tr><td colspan="5" class="text-center">Cargando proveedores...</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- 10. TAB: CONTROL DE USUARIOS Y ROLES -->
                <section id="tab-users" class="tab-pane">
                    <div class="users-view">
                        <div class="view-header">
                            <div>
                                <h3>Control de Usuarios, Roles y Seguridad</h3>
                                <p>Gestión de cajeros, gerentes supervisores, bodegueros y credenciales.</p>
                            </div>
                            <button class="btn btn-primary" id="btnOpenUserModal">➕ Crear Nuevo Usuario</button>
                        </div>

                        <div class="card mt-3">
                            <div class="card-body p-0">
                                <table class="table" id="usersTable">
                                    <thead>
                                        <tr>
                                            <th>Usuario</th>
                                            <th>Nombre Completo</th>
                                            <th>Email</th>
                                            <th class="text-center">Rol Asignado</th>
                                            <th class="text-center">PIN Supervisor</th>
                                            <th class="text-center">Estado</th>
                                            <th class="text-right">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody id="usersTableBody">
                                        <tr><td colspan="7" class="text-center">Cargando usuarios...</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </section>

            </div>
        </main>
    </div>

    <!-- ======================================================== -->
    <!-- MODALES DE LA APLICACIÓN -->
    <!-- ======================================================== -->

    <!-- MODAL DE BÚSQUEDA RÁPIDA DE PRODUCTOS (F1) -->
    <div id="modalSearchProduct" class="modal-backdrop hidden">
        <div class="modal-box modal-lg">
            <div class="modal-header">
                <h3>🔍 Búsqueda Rápida de Productos (F1)</h3>
                <button class="modal-close" data-close="modalSearchProduct">✕</button>
            </div>
            <div class="modal-body">
                <input type="text" id="modalProductQuery" class="form-control form-control-lg" placeholder="Escribe el nombre o código del producto..." autocomplete="off">
                <div class="search-results-list" id="modalProductResults">
                    <!-- Resultados predictivos -->
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL DE COBRO / PAGO (F4) -->
    <div id="modalCheckout" class="modal-backdrop hidden">
        <div class="modal-box modal-md">
            <div class="modal-header bg-emerald">
                <h3 class="text-white">💳 Cobro de Venta (F4)</h3>
                <button class="modal-close text-white" data-close="modalCheckout">✕</button>
            </div>
            <div class="modal-body">
                <div class="pay-due-banner">
                    <span class="label">TOTAL A COBRAR:</span>
                    <span class="amount" id="modalPayTotalAmount">$0.00</span>
                </div>

                <div class="payment-methods-tabs">
                    <button class="pay-tab-btn active" data-method="EFECTIVO">💵 Efectivo</button>
                    <button class="pay-tab-btn" data-method="TARJETA">💳 Tarjeta</button>
                    <button class="pay-tab-btn" data-method="TRANSFERENCIA">📱 Transferencia</button>
                    <button class="pay-tab-btn" data-method="CREDITO">📑 Crédito</button>
                </div>

                <!-- Formulario Dinámico según método -->
                <div id="cashPaymentFields" class="payment-fields active">
                    <div class="form-group mt-3">
                        <label for="cashReceivedInput">Efectivo Recibido ($)</label>
                        <input type="number" step="0.01" id="cashReceivedInput" class="form-control form-control-lg text-right font-mono" placeholder="0.00">
                    </div>
                    <!-- Botones de billetes rápidos -->
                    <div class="quick-cash-pills">
                        <button class="pill-btn" onclick="addQuickCash(1)">+$1</button>
                        <button class="pill-btn" onclick="addQuickCash(5)">+$5</button>
                        <button class="pill-btn" onclick="addQuickCash(10)">+$10</button>
                        <button class="pill-btn" onclick="addQuickCash(20)">+$20</button>
                        <button class="pill-btn" onclick="addQuickCash(50)">+$50</button>
                        <button class="pill-btn pill-btn-exact" id="btnExactCash">Monto Exacto</button>
                    </div>

                    <div class="change-banner mt-3">
                        <span>VUELTO A ENTREGAR:</span>
                        <span class="change-amount" id="modalChangeAmount">$0.00</span>
                    </div>
                </div>

                <div id="cardPaymentFields" class="payment-fields hidden">
                    <div class="form-group mt-3">
                        <label for="cardReferenceInput">Número de Autorización / Voucher POS</label>
                        <input type="text" id="cardReferenceInput" class="form-control" placeholder="Ej. 984521">
                    </div>
                </div>

                <div id="transferPaymentFields" class="payment-fields hidden">
                    <div class="form-group mt-3">
                        <label for="transferReferenceInput">Número de Referencia Bancaria / Móvil</label>
                        <input type="text" id="transferReferenceInput" class="form-control" placeholder="Ej. REF-349812">
                    </div>
                </div>

                <div id="creditPaymentFields" class="payment-fields hidden">
                    <div class="alert alert-info mt-3" id="creditStatusInfo">
                        Validando crédito del cliente seleccionado...
                    </div>
                </div>

            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-close="modalCheckout">Cancelar (Esc)</button>
                <button class="btn btn-success btn-lg" id="btnConfirmPayment">
                    <span>Confirmar y Emitir Ticket</span> ➔
                </button>
            </div>
        </div>
    </div>

    <!-- MODAL DE APERTURA DE CAJA (MODERNO Y FLEXIBLE) -->
    <div id="modalOpenCash" class="modal-backdrop hidden">
        <div class="modal-box modal-md">
            <div class="modal-header bg-emerald text-white" style="display:flex; justify-content:space-between; align-items:center;">
                <h3 class="text-white" style="display:flex; align-items:center; gap:0.5rem;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
                    Apertura de Turno de Caja
                </h3>
                <button class="modal-close text-white" data-close="modalOpenCash">✕</button>
            </div>
            <div class="modal-body">
                <div class="form-group mb-3">
                    <label for="openCajaSelect" style="font-weight:600;">Terminal POS / Caja Asignada</label>
                    <select id="openCajaSelect" class="form-control font-bold"></select>
                </div>

                <!-- SELECTOR DE CAJERO ASIGNADO (Visible para Gerentes y Administradores) -->
                <div class="form-group mb-3" id="openCajeroGroup">
                    <label for="openCajeroSelect" style="font-weight:600;">Cajero Titular del Turno</label>
                    <select id="openCajeroSelect" class="form-control font-bold"></select>
                </div>

                <!-- BLOQUE DE AUTORIZACIÓN DE SUPERVISOR (Obligatorio si el usuario logueado es Cajero) -->
                <div id="openSupervisorAuthGroup" class="hidden mb-3" style="background:#fffbeb; border:1px solid #fde68a; border-radius:8px; padding:0.875rem;">
                    <div style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0.4rem; color:#b45309; font-weight:700; font-size:0.875rem;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        Autorización de Apertura Obligatoria
                    </div>
                    <p style="font-size:0.75rem; color:#92400e; margin-bottom:0.75rem; line-height:1.4;">
                        Los cajeros no tienen autorización para aperturar turnos sin validación. Un <strong>Gerente</strong> o <strong>Administrador</strong> debe autorizar ingresando su PIN supervisor.
                    </p>
                    <div class="form-group mb-0">
                        <label for="openCashSupervisorPin" style="font-size:0.8125rem; font-weight:600; color:#78350f;">PIN de Gerente o Administrador *</label>
                        <div style="position:relative;">
                            <input type="password" maxlength="10" id="openCashSupervisorPin" class="form-control font-mono" placeholder="PIN de 4 dígitos (Ej. 1234)" style="letter-spacing:0.2em; font-size:1.1rem; padding-left:2.5rem;" autocomplete="new-password">
                            <span style="position:absolute; left:0.75rem; top:50%; transform:translateY(-50%); font-size:1.1rem;">🔑</span>
                        </div>
                    </div>
                </div>

                <!-- SELECTOR DE MODO DE APERTURA -->
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:0.5rem; margin-bottom:1.25rem;">
                    <button type="button" class="btn btn-primary btn-sm open-mode-btn active" id="btnModeQuickOpen" data-mode="quick">
                        ⚡ Fondo Rápido
                    </button>
                    <button type="button" class="btn btn-outline btn-sm open-mode-btn" id="btnModeDetailedOpen" data-mode="detailed">
                        🔢 Desglose Denominación
                    </button>
                </div>

                <!-- MODO 1: MONTO DIRECTO CON PRESETS RÁPIDOS (DEFAULT) -->
                <div id="openCashQuickMode">
                    <label style="font-size:0.8125rem; color:var(--text-secondary); display:block; margin-bottom:0.35rem;">
                        Monto del Fondo Inicial en Gaveta:
                    </label>
                    <div class="amount-hero-input-wrap mb-3">
                        <span class="currency-prefix">$</span>
                        <input type="number" step="0.01" min="0" id="quickOpenAmountInput" class="amount-hero-input font-mono" placeholder="0.00" value="35.00">
                    </div>

                    <div style="margin-bottom:1rem;">
                        <span style="font-size:0.75rem; color:var(--text-muted); display:block; margin-bottom:0.4rem;">Fondos habituales rápidos:</span>
                        <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
                            <button type="button" class="btn btn-outline btn-xs btn-preset-amount" data-amount="20.00">$20.00</button>
                            <button type="button" class="btn btn-outline btn-xs btn-preset-amount" data-amount="30.00">$30.00</button>
                            <button type="button" class="btn btn-outline btn-xs btn-preset-amount" data-amount="35.00">$35.00</button>
                            <button type="button" class="btn btn-outline btn-xs btn-preset-amount" data-amount="50.00">$50.00</button>
                            <button type="button" class="btn btn-outline btn-xs btn-preset-amount" data-amount="100.00">$100.00</button>
                            <button type="button" class="btn btn-outline btn-xs btn-preset-amount" data-amount="150.00">$150.00</button>
                        </div>
                    </div>
                </div>

                <!-- MODO 2: DESGLOSE DETALLADO POR DENOMINACIÓN -->
                <div id="openCashDetailedMode" class="hidden">
                    <p class="text-muted" style="font-size:0.8125rem; margin-bottom:0.75rem;">Conteo físico por pieza:</p>
                    <div class="denominations-grid">
                        <div class="denom-row">
                            <span>Billetes $20:</span>
                            <input type="number" class="denom-input" data-val="20" data-tipo="BILLETE" min="0" value="0">
                        </div>
                        <div class="denom-row">
                            <span>Billetes $10:</span>
                            <input type="number" class="denom-input" data-val="10" data-tipo="BILLETE" min="0" value="0">
                        </div>
                        <div class="denom-row">
                            <span>Billetes $5:</span>
                            <input type="number" class="denom-input" data-val="5" data-tipo="BILLETE" min="0" value="0">
                        </div>
                        <div class="denom-row">
                            <span>Billetes $1:</span>
                            <input type="number" class="denom-input" data-val="1" data-tipo="BILLETE" min="0" value="0">
                        </div>
                        <div class="denom-row">
                            <span>Monedas $0.25:</span>
                            <input type="number" class="denom-input" data-val="0.25" data-tipo="MONEDA" min="0" value="0">
                        </div>
                        <div class="denom-row">
                            <span>Monedas $0.10:</span>
                            <input type="number" class="denom-input" data-val="0.10" data-tipo="MONEDA" min="0" value="0">
                        </div>
                    </div>
                </div>

                <div class="total-opening-banner mt-3" style="display:flex; justify-content:space-between; align-items:center; background:rgba(16,185,129,0.1); border:1px solid rgba(16,185,129,0.25); padding:0.75rem 1rem; border-radius:8px;">
                    <span style="font-size:0.875rem;">Total Apertura Confirmado:</span>
                    <strong class="font-mono text-emerald" style="font-size:1.35rem;" id="openCashTotalDisplay">$35.00</strong>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-close="modalOpenCash">Cancelar</button>
                <button class="btn btn-success" id="btnConfirmOpenCash">
                    <span>🔓 Confirmar e Iniciar Turno</span>
                </button>
            </div>
        </div>
    </div>

    <!-- MODAL DE CIERRE DE CAJA / CORTE Z -->
    <div id="modalCloseCash" class="modal-backdrop hidden">
        <div class="modal-box modal-md">
            <div class="modal-header bg-danger text-white">
                <h3 class="text-white">🔒 Arqueo Físico y Cierre de Turno (Corte Z)</h3>
                <button class="modal-close text-white" data-close="modalCloseCash">✕</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="closeTargetSessionId" value="">
                <div id="closeSessionHeaderInfo"></div>

                <div class="alert alert-warning mb-3">
                    <strong>Arqueo ciego:</strong> Cuenta el dinero físico presente en la gaveta e ingresa la cantidad de cada denominación.
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.75rem;">
                    <span style="font-weight:600; font-size:0.875rem;">Desglose de Efectivo:</span>
                    <button type="button" class="btn btn-outline btn-xs" id="btnFillExactCashClose" style="font-size:0.75rem;">
                        ⚡ Cuadrar con Efectivo Esperado
                    </button>
                </div>

                <div class="denominations-grid mt-2" id="closeDenominationsList">
                    <div class="denom-row"><span>Billetes $50:</span><input type="number" class="denom-close-input" data-val="50" min="0" value="0"></div>
                    <div class="denom-row"><span>Billetes $20:</span><input type="number" class="denom-close-input" data-val="20" min="0" value="0"></div>
                    <div class="denom-row"><span>Billetes $10:</span><input type="number" class="denom-close-input" data-val="10" min="0" value="0"></div>
                    <div class="denom-row"><span>Billetes $5:</span><input type="number" class="denom-close-input" data-val="5" min="0" value="0"></div>
                    <div class="denom-row"><span>Billetes $1:</span><input type="number" class="denom-close-input" data-val="1" min="0" value="0"></div>
                    <div class="denom-row"><span>Monedas $0.25:</span><input type="number" class="denom-close-input" data-val="0.25" min="0" value="0"></div>
                    <div class="denom-row"><span>Monedas $0.10:</span><input type="number" class="denom-close-input" data-val="0.10" min="0" value="0"></div>
                </div>

                <div class="total-opening-banner mt-3">
                    <span>Efectivo Total Contado:</span>
                    <strong class="font-mono text-primary" id="closeCashTotalDisplay">$0.00</strong>
                </div>

                <div class="form-group mt-3">
                    <label for="closeSupervisorPin">PIN / Clave de Supervisor (Requerida para Corte Z)</label>
                    <input type="password" id="closeSupervisorPin" class="form-control" placeholder="PIN supervisor (1234)">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-close="modalCloseCash">Cancelar</button>
                <button class="btn btn-danger" id="btnConfirmCloseCash">Confirmar Cierre y Generar Corte Z</button>
            </div>
        </div>
    </div>

    <!-- MODAL DE RECIBO / TICKET ESC/POS Y DTE -->
    <div id="modalTicketPreview" class="modal-backdrop hidden">
        <div class="modal-box modal-ticket">
            <div class="modal-header">
                <h3>Comprobante de Venta</h3>
                <button class="modal-close" data-close="modalTicketPreview">✕</button>
            </div>
            <div class="modal-body p-0">
                <div class="ticket-paper" id="printableTicket">
                    <!-- Contenido del ticket formateado estilo ESC/POS 80mm -->
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-close="modalTicketPreview">Cerrar</button>
                <button class="btn btn-primary" onclick="window.print()">🖨️ Imprimir Ticket (ESC/POS)</button>
            </div>
        </div>
    </div>

    <!-- MODAL CAMBIAR DE CAJERO / BLOQUEO -->
    <div id="modalSwitchUser" class="modal-backdrop hidden">
        <div class="modal-box modal-sm">
            <div class="modal-header bg-primary text-white">
                <h3 class="text-white">🔄 Cambiar Cajero / Usuario</h3>
                <button class="modal-close text-white" data-close="modalSwitchUser">✕</button>
            </div>
            <div class="modal-body">
                <p class="text-muted" style="font-size: 0.8125rem;">Ingresa las credenciales del cajero que toma el turno de la terminal.</p>
                <div class="form-group mt-2">
                    <label for="switchUsername">Usuario</label>
                    <input type="text" id="switchUsername" class="form-control" placeholder="cajero1, admin...">
                </div>
                <div class="form-group">
                    <label for="switchPassword">Contraseña</label>
                    <input type="password" id="switchPassword" class="form-control" placeholder="••••••••">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-close="modalSwitchUser">Cancelar</button>
                <button class="btn btn-primary" id="btnConfirmSwitchUser">Cambiar Usuario</button>
            </div>
        </div>
    </div>

    <!-- MODAL CREAR / EDITAR USUARIO -->
    <div id="modalUserEdit" class="modal-backdrop hidden">
        <div class="modal-box modal-md">
            <div class="modal-header">
                <h3 id="modalUserTitle">➕ Crear Nuevo Usuario</h3>
                <button class="modal-close" data-close="modalUserEdit">✕</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editUserId" value="0">
                <div class="form-group">
                    <label for="editUsername">Nombre de Usuario (Login)</label>
                    <input type="text" id="editUsername" class="form-control" placeholder="ej. cajero2">
                </div>
                <div class="form-group">
                    <label for="editUserFullName">Nombre Completo</label>
                    <input type="text" id="editUserFullName" class="form-control" placeholder="ej. María González">
                </div>
                <div class="form-group">
                    <label for="editUserEmail">Correo Electrónico (Opcional)</label>
                    <input type="email" id="editUserEmail" class="form-control" placeholder="cajero@supermercado.sv">
                </div>
                <div class="form-group">
                    <label for="editUserRole">Rol Operativo</label>
                    <select id="editUserRole" class="form-control">
                        <option value="CAJERO">Cajero (Operación exclusiva de POS y Caja)</option>
                        <option value="SUPERVISOR">Supervisor (Autorizaciones y Anulaciones de POS)</option>
                        <option value="GERENTE">Gerente de Tienda (Control total de tienda y arqueos)</option>
                        <option value="BODEGUERO">Bodeguero (Inventario, Compras, Traslados)</option>
                        <option value="CONTADOR">Contador (Libros fiscales IVA y Balances)</option>
                        <option value="ADMIN">Administrador General (Control Total)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="editUserPassword">Contraseña <span id="passOptionalLabel" class="text-muted" style="display:none;">(Dejar en blanco para no cambiar)</span></label>
                    <input type="password" id="editUserPassword" class="form-control" placeholder="••••••••">
                </div>
                <div class="form-group">
                    <label for="editUserPin">PIN de Supervisor (4 dígitos - Solo Gerentes y Admin)</label>
                    <input type="password" id="editUserPin" maxlength="6" class="form-control" placeholder="Ej. 1234">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-close="modalUserEdit">Cancelar</button>
                <button class="btn btn-primary" id="btnSaveUser">Guardar Usuario</button>
            </div>
        </div>
    </div>

    <!-- MODAL CREAR / EDITAR CATEGORÍA -->
    <div id="modalCategoryEdit" class="modal-backdrop hidden">
        <div class="modal-box modal-sm">
            <div class="modal-header">
                <h3 id="categoryModalTitle">🏷️ Categoría</h3>
                <button class="modal-close" data-close="modalCategoryEdit">✕</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="categoryIdInput" value="0">
                <div class="form-group">
                    <label for="categoryNameInput">Nombre de Categoría *</label>
                    <input type="text" id="categoryNameInput" class="form-control" placeholder="ej. Panadería y Repostería">
                </div>
                <div class="form-group">
                    <label for="categoryParentSelect">Categoría Padre (Opcional para subcategorías)</label>
                    <select id="categoryParentSelect" class="form-control">
                        <option value="">Ninguna (Categoría Principal)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="categoryDescInput">Descripción</label>
                    <input type="text" id="categoryDescInput" class="form-control" placeholder="Breve detalle">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-close="modalCategoryEdit">Cancelar</button>
                <button class="btn btn-primary" id="btnSaveCategory">Guardar Categoría</button>
            </div>
        </div>
    </div>

    <!-- MODAL CREAR PROVEEDOR -->
    <div id="modalSupplierEdit" class="modal-backdrop hidden">
        <div class="modal-box modal-md">
            <div class="modal-header">
                <h3>🏢 Nuevo Proveedor Comercial</h3>
                <button class="modal-close" data-close="modalSupplierEdit">✕</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label for="supplierRazonSocial">Razón Social</label>
                    <input type="text" id="supplierRazonSocial" class="form-control" placeholder="ej. DISTRIBUIDORA ALIMENTICIA S.A. DE C.V.">
                </div>
                <div class="form-group">
                    <label for="supplierNombreComercial">Nombre Comercial</label>
                    <input type="text" id="supplierNombreComercial" class="form-control" placeholder="ej. DIALSA">
                </div>
                <div class="form-group" style="display:grid; grid-template-columns: 1fr 1fr; gap:0.5rem;">
                    <div>
                        <label for="supplierNit">NIT</label>
                        <input type="text" id="supplierNit" class="form-control" placeholder="0614-010190-001-1">
                    </div>
                    <div>
                        <label for="supplierNrc">NRC</label>
                        <input type="text" id="supplierNrc" class="form-control" placeholder="12345-6">
                    </div>
                </div>
                <div class="form-group" style="display:grid; grid-template-columns: 1fr 1fr; gap:0.5rem;">
                    <div>
                        <label for="supplierTelefono">Teléfono</label>
                        <input type="text" id="supplierTelefono" class="form-control" placeholder="2200-0000">
                    </div>
                    <div>
                        <label for="supplierDiasCredito">Días de Crédito</label>
                        <input type="number" id="supplierDiasCredito" class="form-control" value="30">
                    </div>
                </div>
                <div class="form-group">
                    <label for="supplierContacto">Persona de Contacto / Vendedor</label>
                    <input type="text" id="supplierContacto" class="form-control" placeholder="Lic. Juan Pérez">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-close="modalSupplierEdit">Cancelar</button>
                <button class="btn btn-primary" id="btnSaveSupplier">Guardar Proveedor</button>
            </div>
        </div>
    </div>

    <!-- MODAL REGISTRAR COMPRA A PROVEEDOR -->
    <div id="modalPurchaseCreate" class="modal-backdrop hidden">
        <div class="modal-box modal-lg">
            <div class="modal-header bg-primary text-white">
                <h3 class="text-white">📥 Ingreso de Factura de Compra (Recepción de Mercadería)</h3>
                <button class="modal-close text-white" data-close="modalPurchaseCreate">✕</button>
            </div>
            <div class="modal-body">
                <div class="card p-3 mb-3" style="background:#0b1120;">
                    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:0.75rem;">
                        <div>
                            <label style="font-size:0.75rem; color:var(--text-secondary);">Proveedor:</label>
                            <select id="purchaseSupplierSelect" class="form-control form-control-sm"></select>
                        </div>
                        <div>
                            <label style="font-size:0.75rem; color:var(--text-secondary);">No. Factura Proveedor:</label>
                            <input type="text" id="purchaseInvoiceNum" class="form-control form-control-sm" placeholder="CCF-98234">
                        </div>
                        <div>
                            <label style="font-size:0.75rem; color:var(--text-secondary);">Bodega Destino:</label>
                            <select id="purchaseBodegaSelect" class="form-control form-control-sm"></select>
                        </div>
                    </div>
                </div>

                <!-- Agregar producto a la compra -->
                <div class="card p-3 mb-3">
                    <div style="display:grid; grid-template-columns: 2fr 1fr 1fr 1fr auto; gap:0.5rem; align-items:flex-end;">
                        <div>
                            <label style="font-size:0.75rem; color:var(--text-secondary);">Producto a recibir:</label>
                            <select id="purchaseProductSelect" class="form-control form-control-sm"></select>
                        </div>
                        <div>
                            <label style="font-size:0.75rem; color:var(--text-secondary);">Cantidad:</label>
                            <input type="number" step="0.01" id="purchaseQtyInput" class="form-control form-control-sm" placeholder="0">
                        </div>
                        <div>
                            <label style="font-size:0.75rem; color:var(--text-secondary);">Costo Unit. ($):</label>
                            <input type="number" step="0.0001" id="purchaseCostInput" class="form-control form-control-sm" placeholder="0.00">
                        </div>
                        <div>
                            <label style="font-size:0.75rem; color:var(--text-secondary);">Lote (Opcional):</label>
                            <input type="text" id="purchaseLotInput" class="form-control form-control-sm" placeholder="Lote #">
                        </div>
                        <div>
                            <button class="btn btn-secondary btn-sm" id="btnAddPurchaseLine">➕ Agregar</button>
                        </div>
                    </div>
                </div>

                <!-- Tabla de ítems de compra -->
                <table class="table" id="purchaseItemsTable">
                    <thead>
                        <tr>
                            <th>Producto</th>
                            <th class="text-center">Cant.</th>
                            <th class="text-right">Costo Unit.</th>
                            <th class="text-right">Total</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="purchaseItemsTableBody">
                        <tr><td colspan="5" class="text-center text-muted">No has agregado productos a la compra.</td></tr>
                    </tbody>
                </table>

                <div class="text-right mt-3 font-mono" style="font-size: 1.25rem;">
                    Total Compra: <strong class="text-emerald" id="purchaseTotalDisplay">$0.00</strong>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-close="modalPurchaseCreate">Cancelar</button>
                <button class="btn btn-success" id="btnConfirmPurchase">Confirmar e Ingresar a Inventario</button>
            </div>
        </div>
    </div>

    <!-- MODAL ANULAR VENTA -->
    <div id="modalVoidSale" class="modal-backdrop hidden">
        <div class="modal-box modal-sm">
            <div class="modal-header bg-danger text-white">
                <h3 class="text-white">⚠️ Anulación Supervisada de Venta</h3>
                <button class="modal-close text-white" data-close="modalVoidSale">✕</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="voidSaleId">
                <p>Estás a punto de anular el ticket <strong id="voidSaleNumDisplay"></strong>.</p>
                <p class="text-warning" style="font-size: 0.8125rem;">Esta acción devolverá los artículos al Kardex y revertirá el cobro.</p>
                
                <div class="form-group mt-3">
                    <label for="voidSaleMotivo">Motivo de Anulación</label>
                    <input type="text" id="voidSaleMotivo" class="form-control" placeholder="Ej. Error en marcación, cliente canceló">
                </div>
                <div class="form-group">
                    <label for="voidSupervisorPin">PIN / Clave de Gerente o Supervisor</label>
                    <input type="password" id="voidSupervisorPin" class="form-control" placeholder="PIN supervisor (1234)">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-close="modalVoidSale">Cancelar</button>
                <button class="btn btn-danger" id="btnConfirmVoidSale">Confirmar Anulación</button>
            </div>
        </div>
    </div>

    <!-- MODAL EDITAR / CREAR PRODUCTO -->
    <div id="modalProductEdit" class="modal-backdrop hidden">
        <div class="modal-box modal-lg">
            <div class="modal-header bg-primary text-white">
                <h3 class="text-white" id="productEditModalTitle">📦 Producto</h3>
                <button class="modal-close text-white" data-close="modalProductEdit">✕</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="prodEditId">
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:0.75rem;">
                    <div class="form-group">
                        <label for="prodEditCodigo">Código Interno / SKU *</label>
                        <input type="text" id="prodEditCodigo" class="form-control" placeholder="Ej. LAC-001">
                    </div>
                    <div class="form-group">
                        <label for="prodEditBarcode">Código de Barras (EAN-13 / UPC) *</label>
                        <input type="text" id="prodEditBarcode" class="form-control" placeholder="741000000001">
                    </div>
                </div>
                <div class="form-group">
                    <label for="prodEditNombre">Nombre / Descripción del Producto *</label>
                    <input type="text" id="prodEditNombre" class="form-control" placeholder="Ej. LECHE ENTERA 1L TETRAPAK">
                </div>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:0.75rem;">
                    <div class="form-group">
                        <label for="prodEditCategoria">Categoría *</label>
                        <select id="prodEditCategoria" class="form-control"></select>
                    </div>
                    <div class="form-group">
                        <label for="prodEditPresentacion">Presentación / Empaque</label>
                        <input type="text" id="prodEditPresentacion" class="form-control" placeholder="Ej. Botella 1L, Caja, Bolsa 500g">
                    </div>
                </div>
                <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:0.75rem;">
                    <div class="form-group">
                        <label for="prodEditPrecio">Precio Venta ($ con IVA) *</label>
                        <input type="number" step="0.01" id="prodEditPrecio" class="form-control" placeholder="1.50">
                    </div>
                    <div class="form-group">
                        <label for="prodEditCosto">Costo Unitario ($ sin IVA)</label>
                        <input type="number" step="0.0001" id="prodEditCosto" class="form-control" placeholder="1.10">
                    </div>
                    <div class="form-group">
                        <label for="prodEditStockMin">Stock Mínimo (Alerta)</label>
                        <input type="number" id="prodEditStockMin" class="form-control" value="10">
                    </div>
                </div>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:0.75rem; background:rgba(255,255,255,0.03); padding:0.75rem; border-radius:6px; margin-top:0.5rem;">
                    <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer;">
                        <input type="checkbox" id="prodEditPesable" style="width:18px; height:18px;">
                        <span>⚖️ Es Pesable (Venta en Báscula por Kg/Lb)</span>
                    </label>
                    <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer;">
                        <input type="checkbox" id="prodEditLotes" style="width:18px; height:18px;">
                        <span>📅 Maneja Lotes y Fechas de Vencimiento (FEFO)</span>
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-close="modalProductEdit">Cancelar</button>
                <button class="btn btn-primary" id="btnSaveProductModal">Guardar Producto</button>
            </div>
        </div>
    </div>

    <!-- MODAL AJUSTE DE STOCK Y MERMAS -->
    <div id="modalStockAdjust" class="modal-backdrop hidden">
        <div class="modal-box modal-md">
            <div class="modal-header bg-warning text-dark">
                <h3 class="text-dark">⚖️ Ajuste de Inventario / Registro de Merma</h3>
                <button class="modal-close text-dark" data-close="modalStockAdjust">✕</button>
            </div>
            <div class="modal-body">
                <p style="font-size:0.8125rem; color:var(--text-secondary);">
                    Los ajustes alteran directamente el Kardex y generan la correspondiente partida contable de pérdida o corrección. Requiere PIN de Supervisor.
                </p>
                <div class="form-group">
                    <label for="adjProductSelect">Producto *</label>
                    <select id="adjProductSelect" class="form-control"></select>
                </div>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:0.75rem;">
                    <div class="form-group">
                        <label for="adjBodegaSelect">Bodega Afectada *</label>
                        <select id="adjBodegaSelect" class="form-control"></select>
                    </div>
                    <div class="form-group">
                        <label for="adjTipoSelect">Tipo de Ajuste *</label>
                        <select id="adjTipoSelect" class="form-control">
                            <option value="MERMA">Merma / Desperdicio (-)</option>
                            <option value="VENCIMIENTO">Caducidad / Vencimiento (-)</option>
                            <option value="ROTURA">Rotura / Avería en Piso (-)</option>
                            <option value="AJUSTE_NEGATIVO">Faltante de Inventario Físico (-)</option>
                            <option value="AJUSTE_POSITIVO">Sobrante de Inventario Físico (+)</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="adjCantidad">Cantidad de Unidades *</label>
                    <input type="number" step="0.01" min="0.01" id="adjCantidad" class="form-control" placeholder="1.00">
                </div>
                <div class="form-group">
                    <label for="adjMotivo">Justificación / Motivo Detallado *</label>
                    <input type="text" id="adjMotivo" class="form-control" placeholder="Ej. Empaque roto en góndola de exhibición">
                </div>
                <div class="form-group">
                    <label for="adjSupervisorPin">PIN de Supervisor / Gerente *</label>
                    <input type="password" maxlength="6" id="adjSupervisorPin" class="form-control" placeholder="PIN supervisor">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-close="modalStockAdjust">Cancelar</button>
                <button class="btn btn-warning" id="btnConfirmStockAdjust">Aplicar Ajuste a Kardex</button>
            </div>
        </div>
    </div>

    <!-- MODAL CARGA MASIVA CSV -->
    <div id="modalProductImport" class="modal-backdrop hidden">
        <div class="modal-box modal-lg">
            <div class="modal-header bg-primary text-white">
                <h3 class="text-white">📥 Carga Masiva de Productos (Importación CSV)</h3>
                <button class="modal-close text-white" data-close="modalProductImport">✕</button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info" style="font-size:0.8125rem; line-height:1.4; background:rgba(59,130,246,0.1); border:1px solid rgba(59,130,246,0.3); padding:0.75rem; border-radius:6px; margin-bottom:1rem;">
                    <strong>Formato CSV requerido (separado por comas o punto y coma):</strong><br>
                    <code>codigo,nombre,categoria,precio_unitario,costo_unitario,codigo_barra,stock_inicial,lote,vencimiento</code><br>
                    <span class="text-muted">Si la categoría no existe, se creará automáticamente. Si se indica lote y vencimiento (YYYY-MM-DD), se registrará el stock y el Kardex inicial de inmediato.</span>
                </div>
                <div class="form-group mb-3">
                    <label style="font-weight:600;">Pegar contenido CSV directamente o subir archivo:</label>
                    <textarea id="importCsvTextarea" class="form-control font-mono" rows="8" placeholder="codigo,nombre,categoria,precio_unitario,costo_unitario,codigo_barra,stock_inicial,lote,vencimiento
BEB-010,Coca Cola 2.5L,Bebidas,2.50,1.90,741000000010,50,L-202610,2027-06-30
LAC-015,Queso Fresco 500g,Lacteos,3.25,2.40,741000000015,30,L-202609,2026-11-15"></textarea>
                </div>
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <label for="importCsvFile" class="btn btn-outline btn-sm" style="cursor:pointer;">📂 Cargar archivo .csv</label>
                        <input type="file" id="importCsvFile" accept=".csv,.txt" style="display:none;">
                        <span id="importCsvFileName" class="text-muted ml-2" style="font-size:0.75rem;"></span>
                    </div>
                    <button class="btn btn-secondary btn-sm" id="btnFillCsvSample">📋 Cargar Plantilla de Ejemplo</button>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-close="modalProductImport">Cancelar</button>
                <button class="btn btn-success" id="btnExecuteCsvImport">🚀 Procesar e Importar al Catálogo</button>
            </div>
        </div>
    </div>

    <!-- MODAL VISOR DE LOTES Y VENCIMIENTOS (FEFO) -->
    <div id="modalLotsExpirations" class="modal-backdrop hidden">
        <div class="modal-box modal-xl">
            <div class="modal-header bg-primary text-white">
                <h3 class="text-white">📅 Matriz de Rotación FEFO — Lotes y Fechas de Vencimiento</h3>
                <button class="modal-close text-white" data-close="modalLotsExpirations">✕</button>
            </div>
            <div class="modal-body">
                <div class="filter-bar mb-3" style="display:flex; justify-content:space-between; align-items:center;">
                    <div style="display:flex; gap:0.5rem; align-items:center;">
                        <span class="badge badge-danger">🔴 Vencido (&lt; 0 días)</span>
                        <span class="badge badge-warning">🟠 Crítico (&le; 15 días)</span>
                        <span class="badge badge-info">🟡 Próximo (&le; 45 días)</span>
                        <span class="badge badge-success">🟢 Óptimo (&gt; 45 días)</span>
                    </div>
                    <button class="btn btn-outline btn-sm" id="btnRefreshLotsModal">🔄 Actualizar Lotes</button>
                </div>
                <table class="table" id="lotsExpirationsTable">
                    <thead>
                        <tr>
                            <th>Producto</th>
                            <th>Código</th>
                            <th>Bodega</th>
                            <th>Número de Lote</th>
                            <th>Fecha Vencimiento</th>
                            <th class="text-center">Días Restantes</th>
                            <th class="text-center">Cantidad Actual</th>
                            <th class="text-center">Semáforo FEFO</th>
                        </tr>
                    </thead>
                    <tbody id="lotsExpirationsTableBody">
                        <tr><td colspan="8" class="text-center">Cargando lotes...</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-close="modalLotsExpirations">Cerrar</button>
            </div>
        </div>
    </div>

    <!-- MODAL VISOR DE DTE JSON OFICIAL HACIENDA -->
    <div id="modalDteViewer" class="modal-backdrop hidden">
        <div class="modal-box modal-lg">
            <div class="modal-header bg-primary text-white">
                <h3 class="text-white">📄 JSON Estructura DTE Ministerio de Hacienda (El Salvador)</h3>
                <button class="modal-close text-white" data-close="modalDteViewer">✕</button>
            </div>
            <div class="modal-body">
                <div style="display:flex; justify-content:space-between; margin-bottom:0.5rem; align-items:center;">
                    <span id="dteViewerInfo" class="font-mono text-sm"></span>
                    <button class="btn btn-outline btn-xs" id="btnCopyDteJson">📋 Copiar JSON</button>
                </div>
                <pre id="dteViewerJson" style="max-height: 400px; overflow-y:auto; background:#f8fafc; color:#0f172a; padding:1rem; border-radius:6px; font-size:0.75rem; border:1px solid #cbd5e1;"></pre>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-close="modalDteViewer">Cerrar</button>
            </div>
        </div>
    </div>

    <!-- MODAL DETALLE DE ASIENTO CONTABLE -->
    <div id="modalAccountingEntryViewer" class="modal-backdrop hidden">
        <div class="modal-box modal-md">
            <div class="modal-header bg-emerald text-white">
                <h3 class="text-white" id="acctEntryViewerTitle">📑 Partida Contable</h3>
                <button class="modal-close text-white" data-close="modalAccountingEntryViewer">✕</button>
            </div>
            <div class="modal-body">
                <p id="acctEntryViewerConcepto" style="font-size:0.875rem; font-weight:600;"></p>
                <table class="table" id="acctEntryLinesTable">
                    <thead>
                        <tr>
                            <th>Cuenta</th>
                            <th>Nombre Cuenta</th>
                            <th class="text-right">Debe</th>
                            <th class="text-right">Haber</th>
                        </tr>
                    </thead>
                    <tbody id="acctEntryLinesTableBody">
                    </tbody>
                </table>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-close="modalAccountingEntryViewer">Cerrar</button>
            </div>
        </div>
    </div>

    <!-- MODAL HISTORIAL Y TRAZABILIDAD KARDEX -->
    <div id="modalKardex" class="modal-backdrop hidden">
        <div class="modal-box modal-xl" style="max-width: 1050px; width:95%;">
            <div class="modal-header">
                <div>
                    <h3 id="kardexModalTitle">📊 Trazabilidad Kardex Físico-Financiero</h3>
                    <p id="kardexModalSubtitle" style="font-size:0.8125rem; color:var(--text-secondary); margin:0;">Registro cronológico inmutable de movimientos de inventario y Costo Promedio Ponderado (CPP).</p>
                </div>
                <button class="modal-close" data-close="modalKardex">✕</button>
            </div>
            <div class="modal-body">
                <!-- Tarjetas resumen del producto -->
                <div class="kpi-grid mb-3" style="grid-template-columns: repeat(4, 1fr); gap: 0.75rem;">
                    <div class="card p-2 text-center" style="box-shadow:none; border:1px solid var(--border-subtle);">
                        <span class="text-muted" style="font-size:0.75rem;">Código / SKU</span>
                        <div class="font-mono text-cyan" id="kardexProdSku" style="font-weight:700; font-size:1.1rem;">-</div>
                    </div>
                    <div class="card p-2 text-center" style="box-shadow:none; border:1px solid var(--border-subtle);">
                        <span class="text-muted" style="font-size:0.75rem;">Costo Promedio (CPP)</span>
                        <div class="font-mono text-emerald" id="kardexProdCpp" style="font-weight:700; font-size:1.1rem;">$0.0000</div>
                    </div>
                    <div class="card p-2 text-center" style="box-shadow:none; border:1px solid var(--border-subtle);">
                        <span class="text-muted" style="font-size:0.75rem;">Stock Total Actual</span>
                        <div class="font-mono" id="kardexProdStock" style="font-weight:700; font-size:1.1rem;">0.0</div>
                    </div>
                    <div class="card p-2 text-center" style="box-shadow:none; border:1px solid var(--border-subtle);">
                        <span class="text-muted" style="font-size:0.75rem;">Total Movimientos</span>
                        <div class="font-mono" id="kardexProdTotalMovs" style="font-weight:700; font-size:1.1rem;">0</div>
                    </div>
                </div>

                <!-- Barra de filtros del Kardex -->
                <div class="filter-bar mb-3" style="display:flex; justify-content:space-between; align-items:center; gap:0.5rem; flex-wrap:wrap;">
                    <input type="text" id="kardexSearchFilter" placeholder="Filtrar por referencia, bodega, notas o usuario..." class="form-control form-control-sm" style="max-width:340px;">
                    <div style="display:flex; gap:0.5rem; align-items:center;">
                        <label style="font-size:0.75rem; color:var(--text-secondary); margin:0;">Tipo:</label>
                        <select id="kardexTypeFilter" class="form-control form-control-sm" style="max-width:190px;">
                            <option value="">Todos los tipos</option>
                            <option value="VENTA">Ventas</option>
                            <option value="ANULACION_VENTA">Anulaciones de Venta</option>
                            <option value="COMPRA">Compras / Recepción</option>
                            <option value="AJUSTE">Ajustes (+ / -)</option>
                            <option value="MERMA">Mermas</option>
                            <option value="INVENTARIO_INICIAL">Inventario Inicial</option>
                        </select>
                    </div>
                </div>

                <!-- Tabla de Movimientos Kardex -->
                <div style="max-height: 400px; overflow-y: auto; border: 1px solid var(--border-subtle); border-radius: 8px;">
                    <table class="table" id="kardexTable">
                        <thead style="position: sticky; top: 0; background: #ffffff; z-index: 2; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                            <tr>
                                <th>Fecha / Hora</th>
                                <th>Tipo Movimiento</th>
                                <th>Bodega</th>
                                <th>Documento / Ref.</th>
                                <th class="text-right">Cantidad</th>
                                <th class="text-right">Costo Unit.</th>
                                <th class="text-center font-mono">Stock Ant.</th>
                                <th class="text-center font-mono">Saldo Resultante</th>
                                <th>Usuario</th>
                            </tr>
                        </thead>
                        <tbody id="kardexTableBody">
                            <tr><td colspan="9" class="text-center">Consultando movimientos del Kardex...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-close="modalKardex">Cerrar</button>
            </div>
        </div>
    </div>

    <!-- MODAL FACTURAS DE PROVEEDOR (HISTORIAL DE COMPRAS) -->
    <div id="modalSupplierPurchases" class="modal-backdrop hidden">
        <div class="modal-box modal-xl" style="max-width: 1050px; width:95%;">
            <div class="modal-header">
                <div>
                    <h3 id="supplierPurchasesModalTitle">🏢 Facturas Registradas: <span id="supplierPurchasesModalName">—</span></h3>
                    <p id="supplierPurchasesModalSubtitle" style="font-size:0.8125rem; color:var(--text-secondary); margin:0;">Histórico de compras recibidas, números de comprobante y montos fiscales.</p>
                </div>
                <button class="modal-close" data-close="modalSupplierPurchases">✕</button>
            </div>
            <div class="modal-body">
                <!-- Tarjetas resumen del proveedor -->
                <div class="kpi-grid mb-3" style="grid-template-columns: repeat(3, 1fr); gap: 0.75rem;">
                    <div class="card p-2 text-center" style="box-shadow:none; border:1px solid var(--border-subtle);">
                        <span class="text-muted" style="font-size:0.75rem;">Total Facturas</span>
                        <div class="font-mono text-cyan" id="supplierPurchasesCount" style="font-weight:700; font-size:1.1rem;">0</div>
                    </div>
                    <div class="card p-2 text-center" style="box-shadow:none; border:1px solid var(--border-subtle);">
                        <span class="text-muted" style="font-size:0.75rem;">Total Comprado ($)</span>
                        <div class="font-mono text-emerald" id="supplierPurchasesTotalAmount" style="font-weight:700; font-size:1.1rem;">$0.00</div>
                    </div>
                    <div class="card p-2 text-center" style="box-shadow:none; border:1px solid var(--border-subtle);">
                        <span class="text-muted" style="font-size:0.75rem;">Días de Crédito</span>
                        <div class="font-mono" id="supplierPurchasesCreditDays" style="font-weight:700; font-size:1.1rem;">-</div>
                    </div>
                </div>

                <div style="max-height: 420px; overflow-y: auto; border: 1px solid var(--border-subtle); border-radius: 8px;">
                    <table class="table" id="supplierPurchasesTable">
                        <thead style="position: sticky; top: 0; background: #ffffff; z-index: 2; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                            <tr>
                                <th>Fecha</th>
                                <th>No. Factura / CCF</th>
                                <th>Bodega Destino</th>
                                <th>Tipo Doc.</th>
                                <th class="text-right">Subtotal</th>
                                <th class="text-right">IVA (13%)</th>
                                <th class="text-right">Total</th>
                                <th class="text-center">Condición</th>
                                <th class="text-center">Acción</th>
                            </tr>
                        </thead>
                        <tbody id="supplierPurchasesTableBody">
                            <tr><td colspan="9" class="text-center">Cargando facturas del proveedor...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer" style="display:flex; justify-content:space-between; align-items:center;">
                <button class="btn btn-primary btn-sm" id="btnNewPurchaseForSupplier">➕ Registrar Nueva Factura a este Proveedor</button>
                <button class="btn btn-secondary" data-close="modalSupplierPurchases">Cerrar</button>
            </div>
        </div>
    </div>

    <!-- MODAL DETALLE DE FACTURA DE COMPRA -->
    <div id="modalPurchaseDetail" class="modal-backdrop hidden">
        <div class="modal-box modal-lg" style="max-width: 900px; width:95%;">
            <div class="modal-header bg-primary text-white">
                <div>
                    <h3 class="text-white">📄 Factura de Compra <span id="purchaseDetailNumber"></span></h3>
                    <p style="font-size:0.8125rem; color:rgba(255,255,255,0.85); margin:0;">Recepción de mercadería en bodega y desglose de renglones fiscales.</p>
                </div>
                <button class="modal-close text-white" data-close="modalPurchaseDetail">✕</button>
            </div>
            <div class="modal-body">
                <!-- Info de Cabecera -->
                <div class="card p-3 mb-3" style="background:#f8fafc; border:1px solid #e2e8f0;">
                    <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap: 0.75rem; font-size: 0.8125rem;">
                        <div>
                            <span class="text-muted" style="display:block;">Proveedor:</span>
                            <strong id="purchaseDetailSupplier">—</strong>
                        </div>
                        <div>
                            <span class="text-muted" style="display:block;">NIT / NRC:</span>
                            <span class="font-mono text-cyan" id="purchaseDetailNitNrc">—</span>
                        </div>
                        <div>
                            <span class="text-muted" style="display:block;">Fecha de Factura:</span>
                            <span class="font-mono" id="purchaseDetailDate">—</span>
                        </div>
                        <div>
                            <span class="text-muted" style="display:block;">Bodega de Recepción:</span>
                            <strong id="purchaseDetailWarehouse">—</strong>
                        </div>
                        <div>
                            <span class="text-muted" style="display:block;">Condición de Pago:</span>
                            <span id="purchaseDetailCondition">—</span>
                        </div>
                        <div>
                            <span class="text-muted" style="display:block;">Registrado por:</span>
                            <span id="purchaseDetailUser">—</span>
                        </div>
                    </div>
                </div>

                <!-- Tabla de renglones de producto -->
                <div style="max-height: 320px; overflow-y: auto; border: 1px solid var(--border-subtle); border-radius: 8px;">
                    <table class="table" id="purchaseDetailItemsTable">
                        <thead style="position: sticky; top: 0; background: #ffffff; z-index: 2; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                            <tr>
                                <th>#</th>
                                <th>Código</th>
                                <th>Producto / Descripción</th>
                                <th class="text-center">Cantidad</th>
                                <th class="text-right">Costo Unit.</th>
                                <th class="text-right">Total Renglón</th>
                                <th>Lote / Venc.</th>
                            </tr>
                        </thead>
                        <tbody id="purchaseDetailItemsTableBody">
                            <tr><td colspan="7" class="text-center">Cargando productos...</td></tr>
                        </tbody>
                    </table>
                </div>

                <!-- Resumen de Totales -->
                <div style="display:flex; justify-content:flex-end; margin-top:1rem;">
                    <div style="min-width:240px; font-size:0.875rem;">
                        <div style="display:flex; justify-content:space-between; padding:0.25rem 0;">
                            <span class="text-muted">Subtotal Compras:</span>
                            <strong class="font-mono" id="purchaseDetailSubtotal">$0.00</strong>
                        </div>
                        <div style="display:flex; justify-content:space-between; padding:0.25rem 0;">
                            <span class="text-muted">IVA Crédito Fiscal (13%):</span>
                            <strong class="font-mono text-cyan" id="purchaseDetailIva">$0.00</strong>
                        </div>
                        <div style="display:flex; justify-content:space-between; padding:0.4rem 0; border-top:2px solid var(--border-subtle); margin-top:0.25rem; font-size:1.1rem;">
                            <strong>Total Factura:</strong>
                            <strong class="font-mono text-emerald" id="purchaseDetailTotal">$0.00</strong>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-close="modalPurchaseDetail">Cerrar</button>
            </div>
        </div>
    </div>

    <!-- MODAL GESTIONAR CATÁLOGO DE CAJAS / TERMINALES -->
    <div id="modalManageTerminals" class="modal-backdrop hidden">
        <div class="modal-box modal-lg">
            <div class="modal-header bg-primary text-white" style="display:flex; justify-content:space-between; align-items:center;">
                <h3 class="text-white" style="display:flex; align-items:center; gap:0.5rem;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
                    Catálogo de Terminales y Cajas POS
                </h3>
                <button class="modal-close text-white" data-close="modalManageTerminals">✕</button>
            </div>
            <div class="modal-body">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
                    <div>
                        <p class="text-muted" style="margin:0; font-size:0.875rem;">Administra las estaciones físicas de cobro, impresoras térmicas ESC/POS y direcciones de red asociadas.</p>
                    </div>
                    <button class="btn btn-success btn-sm" onclick="openTerminalForm()" style="display:inline-flex; align-items:center; gap:0.4rem;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                        Nueva Terminal
                    </button>
                </div>

                <div class="card p-0" style="border:1px solid var(--border-color); border-radius:8px; overflow:hidden;">
                    <table class="table" style="margin:0; font-size:0.875rem;">
                        <thead>
                            <tr style="background:#f8fafc;">
                                <th>Terminal ID</th>
                                <th>Nombre de Caja</th>
                                <th>Impresora Térmica</th>
                                <th>IP Terminal</th>
                                <th class="text-center">Estado Operativo</th>
                                <th class="text-center">Turno en Vivo</th>
                                <th class="text-right">Acción</th>
                            </tr>
                        </thead>
                        <tbody id="terminalsTableBody">
                            <tr><td colspan="7" class="text-center p-3 text-muted">Cargando cajas...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-close="modalManageTerminals">Cerrar</button>
            </div>
        </div>
    </div>

    <!-- MODAL CREAR / EDITAR TERMINAL DE CAJA -->
    <div id="modalEditTerminal" class="modal-backdrop hidden">
        <div class="modal-box modal-md">
            <div class="modal-header bg-emerald text-white">
                <h3 class="text-white" id="modalEditTerminalTitle">➕ Registrar Nueva Terminal / Caja</h3>
                <button class="modal-close text-white" data-close="modalEditTerminal">✕</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="terminalFormId" value="0">
                <div class="form-group mb-3">
                    <label for="terminalFormNombre" style="font-weight:600;">Nombre Descriptivo de la Caja *</label>
                    <input type="text" id="terminalFormNombre" class="form-control" placeholder="Ej. Caja 3 (Rápida SnackBar) o Caja Principal">
                </div>
                <div class="form-group mb-3">
                    <label for="terminalFormNumero" style="font-weight:600;">Identificador Único de Terminal *</label>
                    <input type="text" id="terminalFormNumero" class="form-control font-mono" placeholder="Ej. TERM-03 o POS-03" style="text-transform:uppercase;">
                    <small class="text-muted">Código alfanumérico único para auditorías fiscales y Cortes Z.</small>
                </div>
                <div class="form-group mb-3">
                    <label for="terminalFormImpresora" style="font-weight:600;">Impresora de Tickets Asociada</label>
                    <input type="text" id="terminalFormImpresora" class="form-control" placeholder="Ej. Generic 80mm ESC/POS o Epson TM-T20III" value="Generic 80mm ESC/POS">
                </div>
                <div class="form-group mb-3">
                    <label for="terminalFormIp" style="font-weight:600;">IP o Red de la Terminal</label>
                    <input type="text" id="terminalFormIp" class="form-control font-mono" placeholder="Ej. 192.168.1.103 o 127.0.0.1" value="127.0.0.1">
                </div>
                <div class="form-group mb-2">
                    <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer;">
                        <input type="checkbox" id="terminalFormActivo" checked style="width:18px; height:18px;">
                        <span style="font-weight:600;">Terminal Activa y Habilitada para Turnos</span>
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-close="modalEditTerminal">Cancelar</button>
                <button class="btn btn-success" id="btnSaveTerminal">Guardar Terminal</button>
            </div>
        </div>
    </div>

    <script src="/js/app.js?v=<?= filemtime(__DIR__ . '/js/app.js') ?>"></script>
</body>
</html>
