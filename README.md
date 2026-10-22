# 🛒 Sistema POS Supermercado (El Salvador)

[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://www.php.net/)
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-15%20%7C%2016-336791?style=for-the-badge&logo=postgresql&logoColor=white)](https://www.postgresql.org/)
[![DTE El Salvador](https://img.shields.io/badge/Facturación-DTE%20MH%20(El%20Salvador)-10b981?style=for-the-badge)](https://www.mh.gob.sv/)
[![Architecture](https://img.shields.io/badge/Architecture-Modular%20Domain%20MVC-0284c7?style=for-the-badge)](#arquitectura-del-software)
[![UI System](https://img.shields.io/badge/UI-Titanium%20White%20%26%20Emerald-059669?style=for-the-badge)](#diseño-visual-y-ergonomía)

Sistema integral de Punto de Venta (POS) y gestión operativa para supermercados minoristas y comercios de alto volumen en **El Salvador**. Diseñado bajo un modelo monolítico modular de alto rendimiento sin dependencias externas pesadas, garantizando operatividad continua en red de área local (LAN), cuadre estricto de caja, trazabilidad de inventarios (Kardex inmutable / FEFO), contabilidad básica integrada y cumplimiento tributario con el Ministerio de Hacienda (DTE).

---

## 📑 Tabla de Contenidos

- [Características Principales](#-características-principales)
- [Especificaciones Técnicas](#-especificaciones-técnicas)
- [Arquitectura del Software](#-arquitectura-del-software)
- [Matriz de Roles y Seguridad (RBAC)](#-matriz-de-roles-y-seguridad-rbac)
- [Módulos del Sistema](#-módulos-del-sistema)
- [Requisitos Previos](#-requisitos-previos)
- [Instalación y Puesta en Marcha](#-instalación-y-puesta-en-marcha)
- [Usuarios y Accesos Semilla](#-usuarios-y-accesos-semilla)
- [Especificación de la API RESTful](#-especificación-de-la-api-restful)
- [Hoja de Ruta y Próximos Módulos](#-hoja-de-ruta-y-próximos-módulos)
- [Licencia](#-licencia)

---

## 🌟 Características Principales

- **Cobro Rápido con Navegación 100% por Teclado (`F1`-`F12`):** Optimizado para operar con escáner láser USB sin necesidad de ratón.
- **Blindaje Estricto de Cajas:** Bloqueo físico y lógico de ventas si el cajero no tiene un turno formalmente abierto.
- **Control de Gaveta y Cuadre Inmutable:** Registro de denominaciones (billetes y monedas), movimientos de caja (ingresos/retiros) autorizados por PIN de supervisor y generación de **Corte Z** con asiento automático de faltantes o sobrantes.
- **Monitor Multiterminal en Vivo:** Supervisión gerencial en tiempo real de todas las terminales activas y capacidad de cierre remoto.
- **Inventario Multibodega y Kardex Inmutable:** Valuación oficial por **Costo Promedio Ponderado (CPP)**, trazabilidad de lotes y fechas de vencimiento con rotación **FEFO** (*First Expired, First Out*).
- **Contabilidad Integrada y Libros de IVA:** Generación automática de partidas contables de partida doble para compras, ventas y descuadres; emisión de libros oficiales de IVA (Consumidor Final, CCF y Compras).
- **Facturación Electrónica DTE (El Salvador):** Preparado para tipos de documento `01` (Factura Consumidor Final), `03` (Comprobante de Crédito Fiscal), `05` (Nota de Crédito) y `14` (Sujeto Excluido), con UUID v4 y QR tributario.
- **Impresión Térmica ESC/POS:** Salida directa para tickets de 80mm/58mm con comando de pulso RJ11 para apertura automática del cajón de dinero.

---

## 🔬 Especificaciones Técnicas

| Parámetro | Detalle de Implementación |
|-----------|---------------------------|
| **Backend** | PHP 8.2+ con tipado estricto (`declare(strict_types=1)`) |
| **Base de Datos** | PostgreSQL 15 / 16 (Motor ACID con tipos monetarios de alta precisión `NUMERIC(18,4)`) |
| **Driver de Datos** | PDO PostgreSQL (`pdo_pgsql`) con sentencias preparadas parametrizadas |
| **Frontend UI** | Vanilla HTML5 Semántico + Vanilla CSS (Design System *Titanium White & Emerald*) |
| **Lógica Frontend** | JavaScript nativo ES6+ (SPA ligera con estado centralizado y debounce de búsqueda) |
| **Seguridad de Acceso** | Hashing criptográfico `Argon2id` / `Bcrypt` para contraseñas y PINs de supervisores |
| **Protocolo de Red** | Operación offline-first en red local (LAN) con API RESTful JSON |
| **Zona Horaria y Localización** | `America/El_Salvador` (UTC-6), moneda USD (`$`), idioma 100% español |
| **Cálculo Tributario** | IVA 13% El Salvador ($\text{Base} = \text{Total}/1.13$, $\text{IVA} = \text{Total} - \text{Base}$) |

---

## 🏛 Arquitectura del Software

El código fuente sigue un patrón **Domain-Driven Controller** limpio, sin librerías pesadas ni compiladores intermediarios:

```
Sistema-Supermercado-POS/
├── config/
│   ├── app.php                       # Parámetros de negocio, IVA (13%) y emisor DTE
│   ├── database.php                  # Credenciales de conexión a PostgreSQL
│   └── database.example.php          # Plantilla pública de conexión
├── database/
│   ├── schema.sql                    # Definición de tablas, llaves foráneas e índices
│   ├── seeds.sql                     # Datos semilla (usuarios, cajas, bodegas, cuentas)
│   ├── init_db.php                   # Script CLI de aprovisionamiento automatizado
│   └── backup/                       # Directorio para volcados automáticos (pg_dump)
├── public/
│   ├── index.php                     # Front Controller y Enrutador central de la API
│   ├── css/
│   │   └── app.css                   # Sistema de diseño Titanium White & Emerald
│   └── js/
│       └── app.js                    # SPA, atajos de teclado, POS y renderizado
├── src/
│   ├── Accounting/
│   │   └── AccountingController.php  # Asientos contables y libros fiscales de IVA
│   ├── Auth/
│   │   └── UserController.php        # Gestión de cuentas y PINs de supervisor
│   ├── Billing/
│   │   └── BillingController.php     # Estructuración DTE, contingencia y correlativos
│   ├── CashRegister/
│   │   └── CashController.php        # Turnos, arqueo ciego, Corte Z y monitor de cajas
│   ├── Catalog/
│   │   └── CatalogController.php     # Categorías, marcas y proveedores
│   ├── Inventory/
│   │   └── InventoryController.php   # Catálogo, stock, kardex append-only y lotes FEFO
│   ├── Pos/
│   │   └── PosController.php         # Cobro atómico, validaciones y ticket térmico
│   ├── Purchases/
│   │   └── PurchasesController.php   # Recepción de mercaderías e ingreso de facturas
│   ├── Reports/
│   │   └── ReportsController.php     # Dashboard gerencial y KPIs en tiempo real
│   ├── Sales/
│   │   └── SalesHistoryController.php# Historial de ventas, reimpresión y anulaciones
│   └── Shared/
│       ├── Audit.php                 # Bitácora inmutable de eventos del sistema
│       ├── Auth.php                  # Manejo de sesiones PHP y validación RBAC
│       ├── Database.php              # Singleton PDO con helper de transacciones
│       └── Response.php              # Estandarización de respuestas JSON
├── tests/                            # Scripts de validación y pruebas de integración
├── .gitignore                        # Reglas de exclusión para Git
├── php.ini                           # Configuración PHP recomendada para producción
└── README.md                         # Documentación técnica oficial
```

---

## 🔐 Matriz de Roles y Seguridad (RBAC)

El acceso a las vistas de la SPA y a los endpoints de la API se valida rigurosamente en cada petición mediante el helper `$requireRole([...])`:

| Módulo / Función | ADMIN | GERENTE | CAJERO | BODEGUERO | CONTADOR |
|------------------|:-----:|:-------:|:------:|:---------:|:--------:|
| **Punto de Venta (Cobro)** | ✅ | ✅ | ✅ | ❌ | ❌ |
| **Control de Caja (Turno Propio)** | ✅ | ✅ | ✅ | ❌ | ❌ |
| **Monitor Cajas en Vivo y Cierre Remoto** | ✅ | ✅ | ❌ | ❌ | ❌ |
| **Administración de Terminales (CRUD)** | ✅ | ✅ | ❌ | ❌ | ❌ |
| **Historial y Reimpresión de Ventas** | ✅ | ✅ | ❌ | ❌ | ✅ |
| **Anulación Supervisada de Venta** | ✅ | ✅ | ❌ | ❌ | ❌ |
| **Inventario, Catálogo y Kardex** | ✅ | ✅ | ❌ | ✅ | ❌ |
| **Ajustes de Stock y Mermas** | ✅ | ✅ | ❌ | ✅ | ❌ |
| **Recepción de Compras a Proveedores** | ✅ | ✅ | ❌ | ✅ | ✅ |
| **Directorio de Proveedores y Marcas** | ✅ | ✅ | ❌ | ✅ | ❌ |
| **Administración de Usuarios y PINs** | ✅ | ❌ | ❌ | ❌ | ❌ |
| **Dashboard y Métricas Ejecutivas** | ✅ | ✅ | ❌ | ❌ | ✅ |
| **Facturación Electrónica (DTE)** | ✅ | ✅ | ❌ | ❌ | ✅ |
| **Contabilidad y Libros de IVA** | ✅ | ✅ | ❌ | ❌ | ✅ |

> **Validación con PIN de Supervisor:** Para operaciones críticas (autorización de descuentos mayores, anulaciones de ticket y apertura de caja para cajeros), el sistema exige el PIN de 4 dígitos de un `ADMIN` o `GERENTE`.

---

## 📦 Módulos del Sistema

### 1. Punto de Venta (POS)
- **Atajos Ergonómicos:** `Enter` (escaneo láser), `F1` (búsqueda catálogo con debounce de 150ms), `F2` (cambio cantidad), `F3` (descuento), `F4` (cobro), `F8` (poner/recuperar venta en espera), `F9` (registro de cliente), `Esc` (cerrar modal).
- **Manejo de Medios de Pago:** Efectivo con cálculo de vuelto en vivo, Tarjeta de Crédito/Débito (voucher), Transferencia Bancaria y Crédito con verificación de límite disponible.
- **Soporte para Artículos Pesables:** Admite cantidades fraccionadas (libras, kilogramos) para carnicería, verduras y embutidos.

### 2. Control de Cajas y Arqueo Físico
- **Apertura de Turno:** Fondo rápido o desglose físico de monedas ($0.01 a $0.25) y billetes ($1 a $50).
- **Cierre y Corte Z:** Arqueo ciego con cálculo automático del efectivo esperado frente al real contado. Asiento contable automático ante faltantes o sobrantes.

### 3. Inventario y Kardex Inmutable
- **Kardex Append-Only:** Registro inalterable de cada transacción (`COMPRA`, `VENTA`, `AJUSTE`, `MERMA`, `ANULACION`) con stock anterior, posterior y usuario responsable.
- **Valuación CPP:** Recálculo automático del costo unitario ponderado en cada ingreso de compra.
- **Lotes y Vencimientos:** Semáforo de advertencia FEFO para lotes próximos a vencer dentro de los siguientes 30 días.

### 4. Facturación DTE y Libros Fiscales
- **Libros de IVA:** Generación con formato conforme a la DGII de El Salvador (Libro de Ventas a Consumidor Final, Libro de Contribuyentes CCF y Libro de Compras).
- **Partida Doble:** Plan contable comercial integrado de 4 niveles con balance cuadrado obligatorio ($Debe = Haber$).

---

## ⚙ Requisitos Previos

- **PHP:** Versión 8.2 o superior con extensiones activadas:
  - `pdo_pgsql`, `pgsql`, `openssl`, `mbstring`, `fileinfo`, `curl`
- **Base de Datos:** PostgreSQL 15, 16 o superior.
- **Servidor Web:** Servidor integrado de PHP, Apache o Nginx.
- **Navegador Web Moderno:** Chrome, Edge o Firefox con soporte ES6+.

---

## 🚀 Instalación y Puesta en Marcha

### 1. Clonar el Repositorio
```bash
git clone https://github.com/CarlosHQ91/Sistema-Supermercado-POS.git
cd Sistema-Supermercado-POS
```

### 2. Configurar la Base de Datos
Crear la base de datos en PostgreSQL:
```sql
CREATE DATABASE pos_supermercado WITH ENCODING 'UTF8';
```

Copiar el archivo de configuración y definir las credenciales:
```bash
cp config/database.example.php config/database.php
```

Editar `config/database.php` con el usuario y contraseña de su motor local PostgreSQL:
```php
return [
    'host' => '127.0.0.1',
    'port' => 5432,
    'dbname' => 'pos_supermercado',
    'user' => 'postgres',
    'password' => 'tu_password',
];
```

### 3. Aprovisionar Tablas y Datos Semilla
Ejecutar el script automatizado de inicialización:
```bash
php database/init_db.php
```

### 4. Iniciar el Servidor de Desarrollo
Iniciar el servidor web local apuntando al directorio `public/`:
```bash
php -S 127.0.0.1:8000 -t public
```

Abrir en el navegador:
```
http://127.0.0.1:8000
```

---

## 👥 Usuarios y Accesos Semilla

El sistema incluye usuarios preconfigurados para cada perfil de rol:

| Usuario | Contraseña | Rol | PIN de Autorización | Módulos Autorizados |
|---------|------------|-----|:-------------------:|---------------------|
| `admin` | `admin123` | **ADMIN** | `1234` | Acceso total a todos los módulos y usuarios |
| `gerente` | `gerente123` | **GERENTE** | `1234` | POS, Monitor Cajas, Inventario, Compras, Ventas, Reportes |
| `cajero1` | `cajero123` | **CAJERO** | — | Punto de Venta y Control de Turno Propio |
| `bodega` | `bodega123` | **BODEGUERO** | — | Inventario, Stock, Catálogos, Lotes y Recepción de Compras |
| `contador`| `contador123` | **CONTADOR** | — | Contabilidad, Libros de IVA, DTEs, Historial de Ventas |

---

## 📡 Especificación de la API RESTful

Todas las rutas retornan `Content-Type: application/json` bajo el formato estándar `{"ok": true, ...}` o `{"ok": false, "error": "Mensaje"}`:

| Método | Endpoint | Roles | Descripción |
|:------:|----------|-------|-------------|
| `POST` | `/api/auth/login` | Público | Autenticación de credenciales de usuario |
| `GET` | `/api/auth/me` | Autenticado | Consulta de identidad y sesión activa |
| `POST` | `/api/auth/logout` | Autenticado | Invalida y destruye la sesión activa |
| `GET` | `/api/pos/search-products` | `ADMIN`, `GERENTE`, `CAJERO` | Búsqueda predictiva o por código de barras |
| `POST` | `/api/pos/checkout` | `ADMIN`, `GERENTE`, `CAJERO` | Cobro transaccional atómico de venta |
| `GET` | `/api/pos/ticket` | Todos | Genera vista de ticket térmico oficial ESC/POS |
| `GET` | `/api/cash/session` | `ADMIN`, `GERENTE`, `CAJERO` | Consulta sesión propia y terminal asignada |
| `GET` | `/api/cash/active-sessions` | `ADMIN`, `GERENTE` | Monitor en vivo de terminales operativas |
| `POST` | `/api/cash/open` | `ADMIN`, `GERENTE`, `CAJERO` | Apertura de turno (cajero requiere PIN) |
| `POST` | `/api/cash/movement` | `ADMIN`, `GERENTE`, `CAJERO` | Ingreso o retiro de gaveta con supervisor |
| `POST` | `/api/cash/close` | `ADMIN`, `GERENTE`, `CAJERO` | Arqueo físico, liquidación y emisión Corte Z |
| `GET` | `/api/inventory/products` | Personal Operativo | Catálogo de productos con costos y stock |
| `POST` | `/api/inventory/save-product` | `ADMIN`, `GERENTE`, `BODEGUERO` | Crear o actualizar artículo |
| `GET` | `/api/inventory/kardex` | `ADMIN`, `GERENTE`, `BODEGUERO`, `CONTADOR` | Historial inmutable de movimientos de stock |
| `POST` | `/api/purchases/create` | `ADMIN`, `GERENTE`, `BODEGUERO` | Recepción de factura de compra a proveedor |
| `POST` | `/api/sales/void` | `ADMIN`, `GERENTE` | Anulación formal de venta y reversión de stock |
| `GET` | `/api/reports/dashboard` | `ADMIN`, `GERENTE`, `CONTADOR` | KPIs del día y analítica de ventas |
| `GET` | `/api/accounting/vat-books` | `ADMIN`, `GERENTE`, `CONTADOR` | Libros fiscales de IVA (DGII El Salvador) |

---

## 🗺 Hoja de Ruta y Próximos Módulos

- [x] Arquitectura de Almacén y Piso de Venta Centralizado Unificado
- [ ] **Corte X:** Lectura parcial en caliente de caja sin cierre de turno (`GET /api/cash/corte-x`)
- [ ] **Lector Balanzas EAN-13:** Decodificación automática de etiquetas con prefijo 20/21 (PLU + peso/precio)
- [ ] **Motor de Promociones:** Configuración de 2x1, 3x2, combos y descuentos por volumen
- [ ] **Finanzas CxC / CxP:** Módulo de abonos de clientes y pagos a proveedores con partidas automáticas
- [ ] **Firmador DTE en Vivo:** Conexión con certificado criptográfico `.p12`, firmado JWS y envío al API del MH
- [ ] **Impresión Directa por Red:** Envío binario ESC/POS mediante sockets TCP nativos en puerto 9100

---

## 📄 Licencia

Este proyecto está bajo la Licencia **MIT**. Consulte el archivo `LICENSE` para más información.
Desarrollado para comercios y supermercados que demandan alta disponibilidad, precisión financiera y cumplimiento fiscal.
