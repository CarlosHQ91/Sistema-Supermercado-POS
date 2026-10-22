# 🛒 Sistema POS Supermercado — Documento de Requerimientos y Especificaciones Técnicas

> **Versión del Sistema:** 2.5.0 (Producción Local / Multi-Terminal)  
> **País y Normativa Fiscal:** El Salvador — Facturación Electrónica DTE (Ministerio de Hacienda)  
> **Moneda de Curso:** USD ($ - Dólar estadounidense)  
> **Impuesto al Valor Agregado (IVA):** 13.00% (Tributo MH código 20)  
> **Arquitectura:** Monolito modular de alto rendimiento (PHP 8.2+ con PostgreSQL 15/16 local)  
> **Diseño de Interfaz:** Titanium White & Emerald Design System (Vanilla CSS + JS nativo, sin dependencias externas pesadas)  
> **Fecha de Actualización:** Octubre 2026  

---

## 📋 Tabla de Contenidos

1. [Contexto, Misión y Alcance](#1-contexto-misión-y-alcance)
2. [Stack Tecnológico y Despliegue Local](#2-stack-tecnológico-y-despliegue-local)
3. [Arquitectura del Software y Directorios](#3-arquitectura-del-software-y-directorios)
4. [Diseño Visual y Experiencia de Usuario (UI/UX)](#4-diseño-visual-y-experiencia-de-usuario-uiux)
5. [Seguridad, Autenticación y Matriz de Roles (RBAC)](#5-seguridad-autenticación-y-matriz-de-roles-rbac)
6. [Módulo de Control de Cajas, Terminales y Turnos](#6-módulo-de-control-de-cajas-terminales-y-turnos)
   - 6.1 Catálogo y Administración de Terminales Físicas (CRUD de Cajas)
   - 6.2 Apertura de Turnos y Restricción de Cajeros por PIN
   - 6.3 Movimientos de Gaveta (Ingresos y Retiros)
   - 6.4 Arqueo Físico y Emisión Inmutable de Corte Z
   - 6.5 Monitor Ejecutivo de Cajas Activas en Vivo y Cierre Remoto
7. [Punto de Venta (POS) y Flujo de Cobro](#7-punto-de-venta-pos-y-flujo-de-cobro)
   - 7.1 Blindaje contra Transacciones sin Apertura Confirmada
   - 7.2 Lector Láser y Navegación 100% por Atajos de Teclado (F1-F12)
   - 7.3 Carrito, Balanzas, Pesaje y Descuentos
   - 7.4 Métodos de Pago y Manejo de Vuelto
   - 7.5 Ventas en Espera (Hold) y Reversión
8. [Inventario, Multibodega y Kardex Inmutable](#8-inventario-multibodega-y-kardex-inmutable)
   - 8.1 Catálogo Maestro, Códigos de Barras y Clasificación
   - 8.2 Valuación por Costo Promedio Ponderado (CPP)
   - 8.3 Trazabilidad de Lotes y Vencimientos (FEFO)
   - 8.4 Módulo de Kardex y Auditoría de Movimientos
   - 8.5 Ajustes de Stock, Mermas y Carga Masiva CSV
9. [Compras, Abastecimiento y Directorio de Proveedores](#9-compras-abastecimiento-y-directorio-de-proveedores)
   - 9.1 Directorio Comercial de Proveedores y Condiciones de Crédito
   - 9.2 Ingreso de Facturas de Compra (Recepción de Mercaderías)
   - 9.3 Visor Detallado de Facturas y Afectación Contable/Inventario
10. [Historial, Auditoría de Ventas y Anulaciones](#10-historial-auditoría-de-ventas-y-anulaciones)
11. [Facturación Electrónica DTE (El Salvador)](#11-facturación-electrónica-dte-el-salvador)
12. [Contabilidad Básica Integrada y Libros Fiscales de IVA](#12-contabilidad-básica-integrada-y-libros-fiscales-de-iva)
    - 12.1 Catálogo de Cuentas y Partida Doble Automática
    - 12.2 Modal y Visor de Asientos Contables / Partidas
    - 12.3 Libros de IVA (Consumidor Final, CCF y Compras)
13. [Dashboard Ejecutivo y Analítica en Tiempo Real](#13-dashboard-ejecutivo-y-analítica-en-tiempo-real)
14. [Modelo de Base de Datos Relacional (PostgreSQL)](#14-modelo-de-base-de-datos-relacional-postgresql)
15. [Especificación de la API RESTful Local](#15-especificación-de-la-api-restful-local)
16. [Requerimientos No Funcionales y Resiliencia](#16-requerimientos-no-funcionales-y-resiliencia)
17. [Hoja de Ruta y Próximos Módulos a Desarrollar (Backlog Oficial)](#17-hoja-de-ruta-y-próximos-módulos-a-desarrollar-backlog-oficial)
    - 17.1 Arquitectura Operativa Descartada (Exclusión de Traslados Multibodega)
    - 17.2 Corte X (Arqueo Parcial y Lectura de Turno en Caliente)
    - 17.3 Lector de Códigos de Balanza Embebidos (EAN-13 Carnicería y Perecederos)
    - 17.4 Motor de Promociones, Combos y Descuentos Automáticos
    - 17.5 Módulo de Finanzas: Cuentas por Cobrar (CxC) y Cuentas por Pagar (CxP)
    - 17.6 Facturación DTE El Salvador: Conexión en Vivo y Firmador Electrónico
    - 17.7 Automatización de Hardware y Respaldos del Sistema

---

## 1. Contexto, Misión y Alcance

### 1.1 Contexto
Supermercado minorista con alta rotación de productos de consumo masivo, abarrotes, perecederos, carnes, lácteos y bebidas. Opera en un establecimiento central con múltiples terminales físicas de cobro y diversas bodegas de almacenamiento (Bodega Principal, Piso de Venta/Góndolas, Cuartos Fríos, Bodega de Merma).

### 1.2 Misión
Proveer una solución integral de software de punto de venta y gestión empresarial que elimine errores humanos, garantice el cuadre exacto de caja, cumpla con las normativas tributarias de la Dirección General de Impuestos Internos (DGII) y el Ministerio de Hacienda de El Salvador (DTE), y automatice la contabilidad y el costeo de inventarios sin depender de la nube para su operación diaria.

### 1.3 Alcance Operativo
- **Establecimiento:** 1 tienda física con soporte para ilimitadas cajas/terminales (inicialmente configuradas 3 terminales: Principal, Rápida y SnackBar).
- **Almacenes:** 2 bodegas operativas por defecto (Bodega Principal ID 2 y Piso de Venta ID 1).
- **Régimen Tributario:** Grandes o Medianos Contribuyentes bajo normativa DTE de El Salvador.
- **Rendimiento:** Respuesta sub-segundo en red local LAN con catálogo de más de 25,000 referencias.

---

## 2. Stack Tecnológico y Despliegue Local

El sistema está diseñado bajo el principio de **cero fricción de mantenimiento**, prescindiendo de dependencias complejas de NodeJS en ejecución o contenedores pesados para simplificar el soporte técnico in situ.

| Componente | Tecnología Seleccionada | Justificación Técnica y Función |
|------------|------------------------|---------------------------------|
| **Lenguaje Backend** | PHP 8.2+ Nativo | Tipado estricto (`declare(strict_types=1)`), alto rendimiento, manejo robusto de excepciones y ejecución nativa en Windows/Linux. |
| **Base de Datos** | PostgreSQL 15 / 16 | Motor relacional transaccional ACID, soporte nativo de tipos `NUMERIC(18,4)`, secuencias `BIGSERIAL`, cláusulas `RETURNING`, JSONB y triggers de integridad. |
| **Driver de DB** | PDO PostgreSQL (`pdo_pgsql`) | Sentencias preparadas parametrizadas que impiden inyecciones SQL y garantizan transacciones atómicas seguras (`beginTransaction`, `commit`, `rollBack`). |
| **Frontend UI** | HTML5 Semántico + Vanilla CSS | Sistema de diseño propietario sin frameworks como Tailwind o Bootstrap. CSS puro modular con variables personalizadas y animaciones por GPU. |
| **Lógica Frontend** | JavaScript Vanilla ES6+ | SPA (*Single Page Application*) ligera sin compiladores intermedios. Manejo de estado centralizado (`state`), peticiones asíncronas vía `fetch` y debounce en búsquedas. |
| **Cifrado de Claves** | Argon2id / Bcrypt | Cifrado criptográfico con `password_hash()` y `password_verify()` para contraseñas y PINs de supervisores. |
| **Impresión Térmica** | ESC/POS (80mm / 58mm) | Generación de tickets limpios con apertura de cajón monedero por pulso RJ11 (`\x1b\x70\x00\x19\xfa`). |
| **Servidor Web** | Built-in Server / Nginx / Apache | Despliegue en red de área local (LAN) escuchando peticiones en puerto configurado (ej. `8000`). |

---

## 3. Arquitectura del Software y Directorios

El backend sigue un patrón MVC orientado a dominios de negocio (*Domain-Driven Controller Pattern*), con enrutamiento frontal centralizado en `public/index.php`.

```
Sistema Pos/
├── config/
│   ├── app.php                   # Parámetros fiscales, emisor DTE, moneda e IVA
│   └── database.php              # Configuración de credenciales PostgreSQL
├── database/
│   ├── schema.sql                # Estructura completa de tablas, llaves y constraints
│   ├── seeds.sql                 # Datos semilla (usuarios, cajas, bodegas, cuentas)
│   ├── init_db.php               # Script PHP de aprovisionamiento automatizado
│   └── backup/                   # Respaldos generados por pg_dump
├── public/
│   ├── index.php                 # Front Controller y Enrutador de API RESTful
│   ├── css/
│   │   └── app.css               # Design System Titanium White & Emerald
│   ├── js/
│   │   └── app.js                # Cliente SPA, atajos de teclado, POS, modales
│   └── assets/                   # Iconos SVG y recursos estáticos
├── src/
│   ├── Accounting/
│   │   └── AccountingController.php  # Partida doble, asientos contables y libros de IVA
│   ├── Auth/
│   │   └── UserController.php        # CRUD de usuarios, roles y PINs de supervisor
│   ├── Billing/
│   │   └── DteController.php         # Generación DTE, JSON MH, contingencia y QR
│   ├── CashRegister/
│   │   └── CashController.php        # Sesiones, arqueos, Corte Z, monitor en vivo y CRUD cajas
│   ├── Catalog/
│   │   └── CatalogController.php     # Categorías jerárquicas, marcas y proveedores
│   ├── Inventory/
│   │   └── InventoryController.php   # Catálogo, kardex, stock multibodega, lotes y CSV
│   ├── Pos/
│   │   └── PosController.php         # Carrito, validaciones, cobro atómico y tickets
│   ├── Purchases/
│   │   └── PurchasesController.php   # Recepción de mercaderías y visor de facturas
│   ├── Reports/
│   │   └── ReportsController.php     # Dashboard ejecutivo, rotación y márgenes
│   ├── Sales/
│   │   └── SalesHistoryController.php # Auditoría de ventas, reimpresión y anulación
│   └── Shared/
│       ├── Audit.php                 # Bitácora inmutable de eventos del sistema
│       ├── Auth.php                  # Manejo de sesión PHP, RBAC y PIN supervisor
│       ├── Database.php              # Singleton PDO con helper de transacciones
│       └── Response.php              # Estandarización de respuestas JSON (ok, error)
└── REQUIREMENTS.md               # Especificación técnica oficial del sistema
```

---

## 4. Diseño Visual y Experiencia de Usuario (UI/UX)

El sistema implementa el tema oficial **Titanium White & Emerald**, desarrollado bajo estrictos principios ergonómicos para cajeros y supervisores que trabajan jornadas completas frente a la pantalla:

- **Paleta de Colores Curada:**
  - Fondo de lienzo: `#f8fafc` (Slate 50) y blanco puro `#ffffff` en tarjetas, eliminando el brillo excesivo y el fondo negro genérico.
  - Color Primario / Acento de Éxito: `#10b981` (Emerald 500) y `#059669` (Emerald 600) para acciones seguras, totales y aperturas.
  - Alertas y Bloqueos: `#ef4444` (Rose 500) y fondos sutiles `#fee2e2` para bloqueos de caja y anulaciones.
  - Alertas de Auditoría: `#f59e0b` (Amber 500) para diferencias de arqueo y productos bajo stock mínimo.
  - Detalles Informativos: `#0ea5e9` (Sky 500) para códigos de terminal, vouchers y subtotales.
- **Tipografía de Grado Financiero:** Uso de fuentes Google Fonts (`Plus Jakarta Sans` para textos e interfaz; fuentes monoespaciadas optimizadas para montos, precios y códigos de generación UUID).
- **Controles y Campos de Entrada:** Bordes en `#cbd5e1`, radios redondeados de 8px a 12px, y anillos de foco en verde esmeralda translúcido (`rgba(16, 185, 129, 0.18)`).
- **Notificaciones Toast No Bloqueantes:** Mensajes flotantes con iconos SVG animados (éxito, error, advertencia) que se desvanecen automáticamente tras 4 segundos sin congelar la entrada por teclado del cajero.
- **Microinteracciones y Feedback Visual:** Estados `:hover`, `:active` y `transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1)` en botones, filas de tablas y tabs de navegación.

---

## 5. Seguridad, Autenticación y Matriz de Roles (RBAC)

### 5.1 Roles y Permisos Granulares
El sistema no otorga accesos planos; restringe la navegación visual y el acceso a los endpoints de la API mediante la matriz de control de acceso basada en roles (RBAC):

| Capacidad / Módulo | ADMIN | GERENTE | CAJERO | BODEGUERO | CONTADOR |
|--------------------|:-----:|:-------:|:------:|:---------:|:--------:|
| **Punto de Venta (POS)** | ✅ | ✅ | ✅ | ❌ | ❌ |
| **Control de Caja (Turno Propio)** | ✅ | ✅ | ✅ | ❌ | ❌ |
| **Monitor Cajas Activas y Cierre Remoto** | ✅ | ✅ | ❌ | ❌ | ❌ |
| **Administrar Terminales (CRUD Cajas)** | ✅ | ✅ | ❌ | ❌ | ❌ |
| **Historial y Auditoría de Ventas** | ✅ | ✅ | ❌ | ❌ | ✅ |
| **Anular Venta Emitida** | ✅ | ✅ | ❌ | ❌ | ❌ |
| **Inventario, Catálogo y Kardex** | ✅ | ✅ | ❌ | ✅ | ❌ |
| **Ajustes de Stock y Mermas** | ✅ | ✅ | ❌ | ✅ | ❌ |
| **Compras a Proveedores** | ✅ | ✅ | ❌ | ✅ | ✅ |
| **Catálogos (Categorías, Marcas, Proveed.)** | ✅ | ✅ | ❌ | ✅ | ❌ |
| **Control de Usuarios y PINs** | ✅ | ❌ | ❌ | ❌ | ❌ |
| **Dashboard y Centro de Comando** | ✅ | ✅ | ❌ | ❌ | ✅ |
| **Facturación Electrónica (DTE)** | ✅ | ✅ | ❌ | ❌ | ✅ |
| **Contabilidad y Libros de IVA** | ✅ | ✅ | ❌ | ❌ | ✅ |

### 5.2 Mecanismo de Validación Dual (Sesión + PIN Supervisor)
1. **Protección de Endpoints:** Cada ruta en `public/index.php` ejecuta la función closure `$requireRole([...])`. Si el usuario carece del rol, se responde de inmediato con HTTP 403 Forbidden: `{"ok": false, "error": "Acceso denegado: rol no autorizado."}`.
2. **PIN de Supervisor de 4 Dígitos:** Usuarios con rol `ADMIN` o `GERENTE` disponen de un PIN numérico encriptado (`pin_autorizacion`). Este PIN se exige para autorizaciones en caliente en el POS (descuentos mayores a umbrales, anulaciones de venta, y apertura de caja para cajeros).
3. **Cierre de Sesión Seguro (`performLogout`):** Invalida la sesión activa en el servidor (`session_destroy`), expira la cookie `PHPSESSID` en el cliente con fecha retroactiva, resetea el objeto `state` en JavaScript y redirige a la pantalla de login.

---

## 6. Módulo de Control de Cajas, Terminales y Turnos

### 6.1 Catálogo y Administración de Terminales Físicas (CRUD de Cajas)
Accesible por Administradores y Gerentes desde **Control de Caja -> ⚙️ Administrar Cajas**:
- **Creación y Edición de Terminales:** Formulario modal con validación de:
  - `nombre`: Nombre descriptivo (ej. *Caja 1 (Principal)*, *Caja 3 (SnackBar & Express)*).
  - `numero_terminal`: Código identificador alfanumérico único para auditorías y DTE (ej. `TERM-01`, `TERM-03`).
  - `impresora_nombre`: Nombre de la cola de impresión térmica asociada (ej. `Generic 80mm ESC/POS`, `Epson TM-T20III`).
  - `ip_terminal`: Dirección IP de la estación de trabajo dentro de la LAN (ej. `192.168.1.103`).
  - `activo`: Estado booleano para habilitar o deshabilitar la terminal de cobro.
- **Disponibilidad Inmediata:** Toda caja registrada queda inmediatamente disponible en los selectores de apertura de turno de los cajeros.

### 6.2 Apertura de Turnos y Restricción de Cajeros por PIN
- **Restricción Estricta:** Un usuario con rol `CAJERO` **no puede auto-aperturarse** una caja; la interfaz le exige el PIN del Gerente o Administrador.
- **Apertura Delegada por Supervisor:** Un Admin o Gerente puede abrir la caja seleccionando directamente qué cajero titular tomará el turno.
- **Modalidades de Fondo Inicial:**
  1. *Fondo Rápido:* Ingreso de monto directo con botones de importe preestablecido ($20, $35, $50, $100).
  2. *Desglose de Denominaciones:* Conteo físico detallado de billetes ($50, $20, $10, $5, $1) y monedas ($0.25, $0.10, $0.05, $0.01), registrado en `caja_arqueo_detalles` con momento `APERTURA`.
- **Integridad:** La base de datos impide abrir una caja que ya tenga otra sesión abierta, o asignar un cajero que ya posea un turno activo en otra terminal.

### 6.3 Movimientos de Gaveta (Ingresos y Retiros)
- Registro de entradas (cambio o reposición) y salidas de efectivo (gastos menores o sangrías hacia caja fuerte).
- Exige tipo (`INGRESO` / `RETIRO`), monto positivo, motivo justificado y PIN de supervisor.
- Almacenado en `caja_movimientos` con timestamp inmutable y cálculo automático en el efectivo esperado.

### 6.4 Arqueo Físico y Emisión Inmutable de Corte Z
1. **Arqueo Ciego:** Conteo del efectivo presente en gaveta ingresado por denominación física o por total contado.
2. **Fórmula de Conciliación Matemática:**
   $$\text{Efectivo Esperado} = \text{Monto Apertura} + \text{Ventas Efectivo} + \text{Ingresos} - \text{Retiros}$$
   $$\text{Diferencia} = \text{Efectivo Real Contado} - \text{Efectivo Esperado}$$
3. **Botón "⚡ Cuadrar con Efectivo Esperado":** Permite al supervisor en cierres de auditoría auto-desglosar el importe exacto esperado en denominaciones de billetes y monedas.
4. **Cierre Inmutable:** Actualiza la sesión a `estado = 'CERRADA'`, asigna el número oficial `corte_z_numero` (ej. `CORTE-Z-20261016-0004`) y bloquea la sesión para evitar transacciones extemporáneas.
5. **Asiento Contable de Descuadre:** Si $\text{Diferencia} \neq 0$, se genera automáticamente una partida contable con balance cuadrado (Debe/Haber):
   - Faltante ($\text{Diferencia} < 0$): Cargo a cuenta de pérdida por faltante (Cta. 6103) y abono a Caja (Cta. 1101).
   - Sobrante ($\text{Diferencia} > 0$): Cargo a Caja (Cta. 1101) y abono a Otros Ingresos por arqueo (Cta. 6103).

### 6.5 Monitor Ejecutivo de Cajas Activas en Vivo y Cierre Remoto
Panel exclusivo para Gerencia y Administración en la parte superior del módulo de caja:
- **Supervisión Multiterminal:** Visualiza en tiempo real todas las terminales operativas de la tienda: Terminal/Caja, Cajero Titular, Hora de Apertura, Fondo Inicial, Ventas en Efectivo, Total Facturado, Conteo de Tickets y Efectivo Estimado en Gaveta.
- **Cierre Remoto Forzado:** Botón **"🔒 Cerrar Caja"** que abre el modal de arqueo para esa sesión específica, permitiendo al Administrador liquidar y emitir el Corte Z de cualquier estación directamente desde su pantalla.

---

## 7. Punto de Venta (POS) y Flujo de Cobro

### 7.1 Blindaje contra Transacciones sin Apertura Confirmada
Para garantizar la fiabilidad del control de caja:
1. **Bloqueo a Nivel de Base de Datos y Backend ([PosController.php](file:///c:/Users/carlo/OneDrive/Documentos/Antigravity/Sistema%20Pos/src/Pos/PosController.php)):**
   - El endpoint `/api/pos/checkout` consulta estrictamente la sesión del usuario autenticado:
     ```sql
     SELECT id, caja_id FROM caja_sesiones
     WHERE usuario_id = :usuario_id AND estado = 'ABIERTA'
     ORDER BY id DESC LIMIT 1
     ```
   - Si no existe un turno abierto para ese usuario específico, rechaza de inmediato la petición con **HTTP 403 Forbidden**, sin permitir que una sesión abierta por otro usuario o supervisor sea usurpada.
2. **Bloqueo Visual y Físico en Frontend:**
   - Si `state.cashSession` es nulo, se activa la clase CSS `.pos-layout.pos-disabled-controls` (`opacity: 0.4; pointer-events: none !important; user-select: none; filter: grayscale(0.6);`).
   - Se despliega el banner superior rojo de advertencia **"Terminal Bloqueada: Turno de Caja Cerrado"**.
   - El campo del escáner se inhabilita con el placeholder: `🔒 Caja cerrada. Abre turno para escanear productos...`.
   - Las funciones `addToCart`, `selectProductFromSearch`, `btnPayNow`, `toggleHoldSale` y `btnConfirmPayment` tienen guardas estrictas que impiden la inserción de productos o cobro, abriendo en su lugar el modal de apertura con PIN de supervisor.

### 7.2 Lector Láser y Navegación 100% por Atajos de Teclado (F1-F12)
El cajero puede operar la jornada completa sin tocar el ratón:
- `Enter` en input de escáner: Lectura automática del código de barras emitido por el lector óptico USB.
- `F1`: Abre modal de búsqueda predictiva en el catálogo (búsqueda por nombre, marca o código con debounce de 150ms).
- `F2`: Modificar cantidad del renglón seleccionado (validado en rango de `0.01` a `5000.00`).
- `F3`: Aplicar descuento en dólares al renglón (validado que no sea negativo ni igual o superior al total de la línea).
- `F4`: Apertura instantánea del modal de cobro y pago.
- `F8`: Poner venta activa en espera (*Hold*) / Recuperar venta en espera.
- `F9`: Búsqueda o registro exprés de cliente por nombre o NIT/DUI.
- `Esc`: Cierra cualquier ventana modal activa y devuelve el foco inmediatamente al lector de barras.

### 7.3 Carrito, Balanzas, Pesaje y Descuentos
- **Validaciones de Racionalidad de Precios:** Los precios, cantidades y subtotales se validan tanto en el cliente como en el backend. Precios irreales, montos negativos o superiores a $5,000 en artículos estándar son bloqueados.
- **Soporte para Pesables:** Admite fracciones decimales para productos vendidos por libra o kilogramo (ej. carnes, verduras, embutidos).
- **Cálculo Automático de IVA El Salvador:** Desglose exacto en base al precio final al consumidor:
  $$\text{Base Gravada} = \frac{\text{Total}}{1.13}, \quad \text{IVA (13\%)} = \text{Total} - \text{Base Gravada}$$

### 7.4 Métodos de Pago y Manejo de Vuelto
- **Efectivo:** Campo con cálculo automático de vuelto en tiempo real. Botones de monto rápido (+$1, +$5, +$10, +$20, +$50) y botón "Monto Exacto". Enter confirma la transacción.
- **Tarjeta de Débito / Crédito:** Registro de número de autorización o voucher del POS bancario físico.
- **Transferencia Bancaria:** Captura de código de referencia bancaria o comprobante móvil.
- **Crédito al Cliente:** Valida en tiempo real si el cliente tiene crédito autorizado (`aplica_credito = true`) y si el disponible (`limite_credito - saldo_deudor`) cubre la compra.

### 7.5 Ventas en Espera (Hold) y Reversión
Pila de ventas en espera en memoria que permite apartar temporalmente los artículos del cliente en turno para atender al siguiente en la fila, reanudando la venta posteriormente con su desglose intacto.

---

## 8. Inventario, Multibodega y Kardex Inmutable

### 8.1 Catálogo Maestro, Códigos de Barras y Clasificación
- **Identificadores Únicos:** Código interno del producto (`codigo_interno`, ej. `AB-001`, `LACT-005`).
- **Soporte Multicódigo de Barras:** Mapeado en `producto_codigos_barra` con bandera `es_principal` para permitir múltiples empaques por artículo.
- **Estructura Jerárquica:** Categorías y subcategorías jerárquicas con validación contra nombres numéricos corruptos.
- **Directorio de Marcas:** Clasificación por fabricante y distribuidor.

### 8.2 Valuación por Costo Promedio Ponderado (CPP)
Cada compra a proveedor recalcula automáticamente el costo unitario oficial:
$$\text{Nuevo CPP} = \frac{(\text{Stock Actual} \times \text{Costo Actual}) + (\text{Cantidad Ingresada} \times \text{Costo Compra})}{\text{Stock Actual} + \text{Cantidad Ingresada}}$$

### 8.3 Trazabilidad de Lotes y Vencimientos (FEFO)
- Registro de número de lote y fecha de vencimiento al ingresar compras en `lotes`.
- Alerta visual en Dashboard y reporte de lotes con caducidad en los próximos 30 días.
- Salida de inventario bajo principio FEFO (*First Expired, First Out*).

### 8.4 Módulo de Kardex y Auditoría de Movimientos
- **Modal de Kardex Interactivo:** Accesible desde la tabla de inventario mediante el botón **"📜 Ver Kardex"**.
- Muestra el historial cronológico completo de entradas, salidas y saldos del producto con: Fecha y hora, Bodega, Tipo de movimiento (`COMPRA`, `VENTA`, `AJUSTE`, `MERMA`, `ANULACION`), Cantidad, Costo Unitario, Stock Anterior, Stock Posterior, Usuario responsable y Notas explicativas.
- **Inmutabilidad Absoluta:** La tabla `kardex_movimientos` es estrictamente *Append-Only* (solo inserciones; prohibidos los updates o deletes).

### 8.5 Ajustes de Stock, Mermas y Carga Masiva CSV
- **Modal de Ajuste Rápido y Merma:** Permite registrar ajustes positivos (sobrantes físicos), negativos (faltantes) o bajas por merma/avería con justificación obligatoria y selección de bodega.
- **Importación Masiva CSV:** Carga por lotes de catálogo con validación de encabezados, unicidad de códigos y reporte previo de registros procesados.

---

## 9. Compras, Abastecimiento y Directorio de Proveedores

### 9.1 Directorio Comercial de Proveedores y Condiciones de Crédito
- Registro de Razón Social, Nombre Comercial, NIT, NRC, Persona de Contacto, Teléfonos, Correo y Dirección.
- Configuración de condiciones de compra: Días de Crédito otorgados (ej. 15, 30, 60 días) y límite comercial.

### 9.2 Ingreso de Facturas de Compra (Recepción de Mercaderías)
Formulario integral para recibir pedidos de proveedores:
- Captura de Número de Factura o CCF del Proveedor.
- Selección de proveedor y bodega destino (Bodega Principal o Piso de Venta).
- Condición de pago: Contado o Crédito.
- Renglones de compra: Selección de producto, cantidad recibida, costo unitario de compra y lote/vencimiento opcional.
- **Automatización Transaccional en Base de Datos:**
  1. Inserta encabezado en `compras` y renglones en `compra_items`.
  2. Aumenta el stock en `stock_bodega` de la bodega destino.
  3. Inserta movimientos de entrada en `kardex_movimientos`.
  4. Recalcula el Costo Promedio Ponderado en `productos`.
  5. Genera asiento contable automático con balance cuadrado en `asientos_contables` (Débito a Inventario e IVA Crédito Fiscal / Crédito a Cuentas por Pagar o Bancos).

### 9.3 Visor Detallado de Facturas
Accesible desde **Directorio de Proveedores -> 📄 Facturas**:
- Modal interactivo (`modalPurchaseDetail`) que muestra la factura formal con encabezado, proveedor, NIT, NRC, fecha, condición de pago, usuario que registró, tabla de productos recibidos con sus costos y lotes, y desglose de Subtotal, IVA Crédito Fiscal (13%) y Total de la factura.

---

## 10. Historial, Auditoría de Ventas y Anulaciones

- **Bandeja de Ventas con Filtros Dinámicos:** Búsqueda por rango de fechas (Desde/Hasta), cajero responsable, estado (`COMPLETADA` / `ANULADA`) y búsqueda por número de ticket, UUID o cliente.
- **Visor de Ticket ESC/POS:** Modal que renderiza el comprobante térmico oficial de 80mm con datos de la empresa, resolución DTE, tabla de artículos, desglose de impuestos y botón para impresión física directa.
- **Anulación Supervisada de Venta:**
  - Requiere motivo de anulación y contraseña/PIN de Gerente o Administrador.
  - Ejecutada en transacción atómica: actualiza la venta a `ANULADA`, reingresa los artículos al inventario generando entradas de reversión en el Kardex inmutable y ajusta los saldos del turno de caja.

---

## 11. Facturación Electrónica DTE (El Salvador)

Especificación diseñada bajo la normativa oficial del Ministerio de Hacienda de El Salvador:
- **Tipos de Documento Implementados:**
  - `01`: Factura Electrónica a Consumidor Final (FCF).
  - `03`: Comprobante de Crédito Fiscal Electrónico (CCF).
  - `05`: Nota de Crédito Electrónica (Devoluciones).
  - `14`: Factura de Sujeto Excluido (Compras informales).
- **Parámetros Tributarios Oficiales:**
  - Código de Generación: UUID v4 en mayúsculas (ej. `B347201D-218C-4B1C-BF2E-6E85C2F6FE1A`).
  - Número de Control: Formato normalizado `DTE-01-M001P001-000000000000123`.
  - Sello de Recepción: Cadena oficial devuelta por la API del MH tras la validación.
  - Generación de Código QR: Enlace oficial a la consulta pública de DTEs de la DGII.
- **Manejo de Contingencia Local:** Si la conexión a internet no responde, el documento se emite bajo estado de contingencia con serie local, registrándose en cola para transmisión automática en segundo plano.

---

## 12. Contabilidad Básica Integrada y Libros Fiscales de IVA

### 12.1 Catálogo de Cuentas y Partida Doble Automática
Estructura oficial de 4 niveles en `cuentas_contables`:
- `1101`: Caja General (Activo)
- `1102`: Bancos y Cuentas por Cobrar Tarjetas (Activo)
- `1103`: Cuentas por Cobrar Clientes (Activo)
- `1104`: Inventario de Mercaderías (Activo)
- `1105`: IVA Crédito Fiscal 13% (Activo)
- `2101`: Cuentas por Pagar Proveedores (Pasivo)
- `2102`: IVA Débito Fiscal 13% (Pasivo)
- `4101`: Ingresos por Ventas de Supermercado (Ingresos)
- `5101`: Costo de Venta / Costo de Mercancías Vendidas (Costos)
- `6101`: Gastos de Venta y Operación (Gastos)
- `6102`: Gastos por Merma y Pérdida de Inventario (Gastos)
- `6103`: Faltantes y Sobrantes de Caja (Gastos/Ingresos)

### 12.2 Modal y Visor de Asientos Contables / Partidas
- Accesible desde **Contabilidad & IVA -> Ver Asiento** en el Libro Diario:
- Modal interactivo (`modalAccountingEntryDetail`) que consulta `/api/accounting/entry-lines?asiento_id=X` y muestra:
  - Encabezado: Número de Partida (ej. `AST-VTA-104`), Fecha, Módulo de Origen (`VENTA`, `COMPRA`, `CAJA`, `AJUSTE`) y Concepto General.
  - Tabla de Cuentas: Código de Cuenta, Nombre de la Cuenta, Renglón/Concepto y columnas numéricas de **Debe** y **Haber**.
  - Comprobación de Cuadre: Sumas iguales obligatorias al pie del comprobante con badge verde **"Partida Cuadrada"**.

### 12.3 Libros Fiscales de IVA (Exigencia DGII El Salvador)
- **Libro de Ventas a Consumidor Final:** Agrupación diaria por fecha y caja con correlativo inicial, correlativo final, ventas exentas, ventas gravadas e IVA incluido.
- **Libro de Ventas a Contribuyentes (CCF):** Detalle de facturas tipo 03 con fecha, número de CCF, nombre del cliente, NRC, NIT, base gravada, débito fiscal (13%), retención (1%) y total.
- **Libro de Compras:** Compras a proveedores con fecha, número de factura, razón social, NRC, compras gravadas, crédito fiscal (13%) y percepción (1%).
- **Balance de Comprobación:** Sumas del debe, haber y saldos deudor/acreedor calculados en tiempo real.

---

## 13. Dashboard Ejecutivo y Analítica en Tiempo Real

Panel de control para Gerencia y Administración con actualización continua:
- **Hero KPIs:**
  - *Ventas Totales Hoy:* Monto facturado del día con comparativa de crecimiento respecto al día anterior.
  - *Tickets Emitidos:* Conteo de ventas completadas hoy.
  - *Ticket Promedio:* Gasto promedio por cliente ($\text{Total Ventas} / \text{Tickets}$).
  - *Margen Bruto Estimado:* Rentabilidad neta del día ($\text{Ventas Netas} - \text{Costo de Mercadería Vendida}$).
- **Mix de Medios de Pago:** Barras de distribución porcentual y montos absolutos en Efectivo, Tarjeta, Transferencia y Crédito.
- **Top 5 Productos de Mayor Desempeño:** Podio de productos con unidades vendidas e ingresos generados hoy.
- **Semáforo de Riesgo Operativo:** Alertas inmediatas de artículos con stock por debajo del mínimo y productos próximos a caducar bajo regla FEFO.

---

## 14. Modelo de Base de Datos Relacional (PostgreSQL)

El esquema implementa llaves foráneas con integridad referencial estricta, índices B-Tree en campos de búsqueda y tipos de precisión fija:

```sql
-- 1. USUARIOS Y AUDITORÍA
CREATE TABLE usuarios (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    pin_autorizacion VARCHAR(255) NULL,
    nombre_completo VARCHAR(120) NOT NULL,
    email VARCHAR(100) UNIQUE NULL,
    rol VARCHAR(20) NOT NULL CHECK (rol IN ('ADMIN', 'GERENTE', 'CAJERO', 'BODEGUERO', 'CONTADOR')),
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE bitacora_auditoria (
    id SERIAL PRIMARY KEY,
    usuario_id INT NULL REFERENCES usuarios(id) ON DELETE SET NULL,
    accion VARCHAR(50) NOT NULL,
    entidad VARCHAR(50) NOT NULL,
    entidad_id VARCHAR(50) NULL,
    valor_anterior JSONB NULL,
    valor_nuevo JSONB NULL,
    descripcion TEXT NULL,
    ip VARCHAR(45) NULL,
    fecha_hora TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 2. CAJAS Y TURNOS
CREATE TABLE cajas (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(80) NOT NULL,
    numero_terminal VARCHAR(20) UNIQUE NOT NULL,
    impresora_nombre VARCHAR(100) NULL DEFAULT 'Generic 80mm ESC/POS',
    ip_terminal VARCHAR(45) NULL DEFAULT '127.0.0.1',
    activo BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE caja_sesiones (
    id SERIAL PRIMARY KEY,
    caja_id INT NOT NULL REFERENCES cajas(id),
    usuario_id INT NOT NULL REFERENCES usuarios(id),
    supervisor_apertura_id INT NULL REFERENCES usuarios(id),
    supervisor_cierre_id INT NULL REFERENCES usuarios(id),
    fecha_apertura TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    monto_apertura NUMERIC(18,4) NOT NULL DEFAULT 0.0000,
    fecha_cierre TIMESTAMP NULL,
    monto_esperado NUMERIC(18,4) NULL,
    monto_real NUMERIC(18,4) NULL,
    diferencia NUMERIC(18,4) NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'ABIERTA' CHECK (estado IN ('ABIERTA', 'CERRADA')),
    notas_apertura TEXT NULL,
    notas_cierre TEXT NULL,
    corte_z_numero VARCHAR(50) NULL
);

CREATE TABLE caja_arqueo_detalles (
    id SERIAL PRIMARY KEY,
    sesion_id INT NOT NULL REFERENCES caja_sesiones(id) ON DELETE CASCADE,
    momento VARCHAR(20) NOT NULL CHECK (momento IN ('APERTURA', 'CIERRE')),
    denominacion_tipo VARCHAR(10) NOT NULL CHECK (denominacion_tipo IN ('BILLETE', 'MONEDA')),
    denominacion_valor NUMERIC(18,4) NOT NULL,
    cantidad INT NOT NULL,
    subtotal NUMERIC(18,4) NOT NULL
);

CREATE TABLE caja_movimientos (
    id SERIAL PRIMARY KEY,
    sesion_id INT NOT NULL REFERENCES caja_sesiones(id) ON DELETE CASCADE,
    usuario_id INT NOT NULL REFERENCES usuarios(id),
    supervisor_id INT NULL REFERENCES usuarios(id),
    tipo VARCHAR(10) NOT NULL CHECK (tipo IN ('INGRESO', 'RETIRO')),
    monto NUMERIC(18,4) NOT NULL,
    motivo VARCHAR(255) NOT NULL,
    fecha_hora TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 3. INVENTARIO Y PRODUCTOS
CREATE TABLE bodegas (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(80) NOT NULL,
    es_principal BOOLEAN NOT NULL DEFAULT FALSE,
    ubicacion VARCHAR(150) NULL,
    activo BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE categorias (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(80) NOT NULL,
    descripcion TEXT NULL,
    padre_id INT NULL REFERENCES categorias(id),
    activo BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE marcas (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(80) UNIQUE NOT NULL,
    activo BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE productos (
    id SERIAL PRIMARY KEY,
    codigo_interno VARCHAR(50) UNIQUE NOT NULL,
    nombre VARCHAR(150) NOT NULL,
    descripcion TEXT NULL,
    categoria_id INT NOT NULL REFERENCES categorias(id),
    marca_id INT NULL REFERENCES marcas(id),
    unidad_medida VARCHAR(20) NOT NULL DEFAULT 'UNIDAD',
    presentacion VARCHAR(80) NULL,
    costo_promedio NUMERIC(18,4) NOT NULL DEFAULT 0.0000,
    precio_venta NUMERIC(18,4) NOT NULL,
    aplica_iva BOOLEAN NOT NULL DEFAULT TRUE,
    es_pesable BOOLEAN NOT NULL DEFAULT FALSE,
    es_combo BOOLEAN NOT NULL DEFAULT FALSE,
    maneja_lotes BOOLEAN NOT NULL DEFAULT FALSE,
    stock_minimo NUMERIC(18,4) NOT NULL DEFAULT 5.0000,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE producto_codigos_barra (
    id SERIAL PRIMARY KEY,
    producto_id INT NOT NULL REFERENCES productos(id) ON DELETE CASCADE,
    codigo_barra VARCHAR(80) UNIQUE NOT NULL,
    es_principal BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE stock_bodega (
    id SERIAL PRIMARY KEY,
    producto_id INT NOT NULL REFERENCES productos(id) ON DELETE CASCADE,
    bodega_id INT NOT NULL REFERENCES bodegas(id),
    cantidad NUMERIC(18,4) NOT NULL DEFAULT 0.0000,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_prod_bodega UNIQUE (producto_id, bodega_id)
);

CREATE TABLE kardex_movimientos (
    id SERIAL PRIMARY KEY,
    fecha_hora TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    producto_id INT NOT NULL REFERENCES productos(id),
    bodega_id INT NOT NULL REFERENCES bodegas(id),
    tipo_movimiento VARCHAR(20) NOT NULL,
    cantidad NUMERIC(18,4) NOT NULL,
    costo_unitario NUMERIC(18,4) NOT NULL,
    stock_anterior NUMERIC(18,4) NOT NULL,
    stock_posterior NUMERIC(18,4) NOT NULL,
    documento_referencia VARCHAR(80) NULL,
    usuario_id INT NOT NULL REFERENCES usuarios(id),
    notas TEXT NULL
);

-- 4. VENTAS Y FACTURACIÓN
CREATE TABLE clientes (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    nit VARCHAR(20) NULL,
    nrc VARCHAR(20) NULL,
    dui VARCHAR(15) NULL,
    direccion TEXT NULL,
    telefono VARCHAR(30) NULL,
    correo VARCHAR(100) NULL,
    aplica_credito BOOLEAN NOT NULL DEFAULT FALSE,
    limite_credito NUMERIC(18,4) NOT NULL DEFAULT 0.0000,
    saldo_deudor NUMERIC(18,4) NOT NULL DEFAULT 0.0000,
    activo BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE ventas (
    id SERIAL PRIMARY KEY,
    sesion_caja_id INT NOT NULL REFERENCES caja_sesiones(id),
    usuario_id INT NOT NULL REFERENCES usuarios(id),
    cliente_id INT NOT NULL REFERENCES clientes(id),
    tipo_documento VARCHAR(5) NOT NULL DEFAULT '01',
    numero_control VARCHAR(50) NOT NULL,
    codigo_generacion VARCHAR(50) NOT NULL,
    sello_recepcion VARCHAR(100) NULL,
    subtotal NUMERIC(18,4) NOT NULL,
    descuento NUMERIC(18,4) NOT NULL DEFAULT 0.0000,
    iva NUMERIC(18,4) NOT NULL,
    total NUMERIC(18,4) NOT NULL,
    vuelto NUMERIC(18,4) NOT NULL DEFAULT 0.0000,
    estado VARCHAR(20) NOT NULL DEFAULT 'COMPLETADA' CHECK (estado IN ('COMPLETADA', 'ANULADA')),
    motivo_anulacion TEXT NULL,
    anulado_por_id INT NULL REFERENCES usuarios(id),
    fecha_hora TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE venta_items (
    id SERIAL PRIMARY KEY,
    venta_id INT NOT NULL REFERENCES ventas(id) ON DELETE CASCADE,
    producto_id INT NOT NULL REFERENCES productos(id),
    cantidad NUMERIC(18,4) NOT NULL,
    precio_unitario NUMERIC(18,4) NOT NULL,
    costo_unitario NUMERIC(18,4) NOT NULL,
    descuento NUMERIC(18,4) NOT NULL DEFAULT 0.0000,
    total_linea NUMERIC(18,4) NOT NULL
);

CREATE TABLE venta_pagos (
    id SERIAL PRIMARY KEY,
    venta_id INT NOT NULL REFERENCES ventas(id) ON DELETE CASCADE,
    metodo_pago VARCHAR(20) NOT NULL CHECK (metodo_pago IN ('EFECTIVO', 'TARJETA', 'TRANSFERENCIA', 'CREDITO')),
    monto NUMERIC(18,4) NOT NULL,
    monto_recibido NUMERIC(18,4) NULL,
    referencia VARCHAR(100) NULL
);

-- 5. COMPRAS Y PROVEEDORES
CREATE TABLE proveedores (
    id SERIAL PRIMARY KEY,
    razon_social VARCHAR(150) NOT NULL,
    nombre_comercial VARCHAR(150) NULL,
    nit VARCHAR(20) NULL,
    nrc VARCHAR(20) NULL,
    contacto VARCHAR(100) NULL,
    telefono VARCHAR(30) NULL,
    correo VARCHAR(100) NULL,
    dias_credito INT NOT NULL DEFAULT 30,
    limite_credito NUMERIC(18,4) NOT NULL DEFAULT 5000.0000,
    activo BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE compras (
    id SERIAL PRIMARY KEY,
    proveedor_id INT NOT NULL REFERENCES proveedores(id),
    usuario_id INT NOT NULL REFERENCES usuarios(id),
    bodega_id INT NOT NULL REFERENCES bodegas(id),
    numero_factura_proveedor VARCHAR(50) NOT NULL,
    fecha_factura DATE NOT NULL,
    fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    es_credito BOOLEAN NOT NULL DEFAULT FALSE,
    subtotal NUMERIC(18,4) NOT NULL,
    iva NUMERIC(18,4) NOT NULL,
    total NUMERIC(18,4) NOT NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'RECIBIDA'
);

CREATE TABLE compra_items (
    id SERIAL PRIMARY KEY,
    compra_id INT NOT NULL REFERENCES compras(id) ON DELETE CASCADE,
    producto_id INT NOT NULL REFERENCES productos(id),
    cantidad NUMERIC(18,4) NOT NULL,
    costo_unitario NUMERIC(18,4) NOT NULL,
    total_linea NUMERIC(18,4) NOT NULL,
    numero_lote VARCHAR(50) NULL,
    fecha_vencimiento DATE NULL
);

-- 6. CONTABILIDAD INTEGRADA
CREATE TABLE cuentas_contables (
    id SERIAL PRIMARY KEY,
    codigo VARCHAR(20) UNIQUE NOT NULL,
    nombre VARCHAR(120) NOT NULL,
    tipo VARCHAR(20) NOT NULL CHECK (tipo IN ('ACTIVO', 'PASIVO', 'PATRIMONIO', 'INGRESOS', 'COSTOS', 'GASTOS')),
    padre_id INT NULL REFERENCES cuentas_contables(id),
    es_debe BOOLEAN NOT NULL DEFAULT TRUE,
    activo BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE asientos_contables (
    id SERIAL PRIMARY KEY,
    numero_asiento VARCHAR(50) UNIQUE NOT NULL,
    fecha DATE NOT NULL DEFAULT CURRENT_DATE,
    concepto TEXT NOT NULL,
    origen_modulo VARCHAR(30) NOT NULL,
    referencia_id VARCHAR(50) NULL,
    total_debe NUMERIC(18,4) NOT NULL DEFAULT 0.0000,
    total_haber NUMERIC(18,4) NOT NULL DEFAULT 0.0000,
    estado VARCHAR(20) NOT NULL DEFAULT 'PUBLICADO'
);

CREATE TABLE asiento_lineas (
    id SERIAL PRIMARY KEY,
    asiento_id INT NOT NULL REFERENCES asientos_contables(id) ON DELETE CASCADE,
    cuenta_id INT NOT NULL REFERENCES cuentas_contables(id),
    debe NUMERIC(18,4) NOT NULL DEFAULT 0.0000,
    haber NUMERIC(18,4) NOT NULL DEFAULT 0.0000,
    concepto_linea VARCHAR(150) NULL
);
```

---

## 15. Especificación de la API RESTful Local

Todas las respuestas retornan cabecera `Content-Type: application/json; charset=utf-8` estructuradas en `{"ok": true, ...}` o `{"ok": false, "error": "Mensaje"}`.

| Método | Endpoint | Roles Autorizados | Función / Acción |
|:------:|----------|-------------------|------------------|
| `POST` | `/api/auth/login` | Público | Autenticación con usuario y contraseña |
| `GET` | `/api/auth/me` | Público | Consulta de sesión de usuario activa |
| `POST` | `/api/auth/logout` | Autenticado | Invalida y destruye sesión activa |
| `POST` | `/api/auth/switch-user` | Autenticado | Cambio de cajero en caliente |
| `GET` | `/api/pos/search-products` | `ADMIN`, `GERENTE`, `CAJERO` | Búsqueda predictiva o por código de barras |
| `GET` | `/api/pos/search-clients` | `ADMIN`, `GERENTE`, `CAJERO` | Búsqueda de clientes por nombre o NIT |
| `POST` | `/api/pos/create-client` | `ADMIN`, `GERENTE`, `CAJERO` | Creación rápida de cliente en mostrador |
| `POST` | `/api/pos/checkout` | `ADMIN`, `GERENTE`, `CAJERO` | Cobro transaccional (exige sesión propia abierta) |
| `GET` | `/api/pos/ticket` | `ADMIN`, `GERENTE`, `CAJERO`, `CONTADOR` | Obtención de ticket formateado para reimpresión |
| `GET` | `/api/cash/session` | `ADMIN`, `GERENTE`, `CAJERO` | Consulta sesión activa propia y cajas de la tienda |
| `GET` | `/api/cash/active-sessions` | `ADMIN`, `GERENTE` | Listado en vivo de todas las cajas operativas |
| `POST` | `/api/cash/open` | `ADMIN`, `GERENTE`, `CAJERO` | Apertura de turno (cajero exige PIN supervisor) |
| `POST` | `/api/cash/movement` | `ADMIN`, `GERENTE`, `CAJERO` | Ingreso o retiro con autorización de supervisor |
| `POST` | `/api/cash/close` | `ADMIN`, `GERENTE`, `CAJERO` | Cierre de turno, arqueo y emisión de Corte Z |
| `GET` | `/api/cash/terminals` | `ADMIN`, `GERENTE` | Catálogo maestro de terminales y cajas físicas |
| `POST` | `/api/cash/terminals` | `ADMIN`, `GERENTE` | Crear o modificar configuración de terminal |
| `GET` | `/api/sales/history` | `ADMIN`, `GERENTE`, `CONTADOR` | Listado y filtros de ventas históricas |
| `GET` | `/api/sales/detail` | `ADMIN`, `GERENTE`, `CAJERO`, `CONTADOR` | Consulta de renglones y pagos de una venta |
| `POST` | `/api/sales/void` | `ADMIN`, `GERENTE` | Anulación formal de venta y reversión de stock |
| `GET` | `/api/inventory/products` | `ADMIN`, `GERENTE`, `BODEGUERO`, `CONTADOR`, `CAJERO` | Catálogo completo con existencias y costos |
| `POST` | `/api/inventory/save-product` | `ADMIN`, `GERENTE`, `BODEGUERO` | Crear o modificar producto |
| `POST` | `/api/inventory/adjust-stock` | `ADMIN`, `GERENTE`, `BODEGUERO` | Ajustes manuales y mermas de inventario |
| `GET` | `/api/inventory/kardex` | `ADMIN`, `GERENTE`, `BODEGUERO`, `CONTADOR` | Historial de movimientos inmutables de un producto |
| `GET` | `/api/inventory/lots` | `ADMIN`, `GERENTE`, `BODEGUERO` | Reporte FEFO de lotes y fechas de vencimiento |
| `POST` | `/api/inventory/import-csv` | `ADMIN`, `GERENTE`, `BODEGUERO` | Carga masiva de catálogo vía archivo CSV |
| `GET` | `/api/purchases` | `ADMIN`, `GERENTE`, `BODEGUERO`, `CONTADOR` | Listado de facturas de compra recibidas |
| `GET` | `/api/purchases/detail` | `ADMIN`, `GERENTE`, `BODEGUERO`, `CONTADOR` | Detalle renglón por renglón de compra |
| `POST` | `/api/purchases/create` | `ADMIN`, `GERENTE`, `BODEGUERO` | Registro de recepción de factura de proveedor |
| `GET` | `/api/catalog/categories` | Todos los roles | Catálogo de categorías y subcategorías |
| `POST` | `/api/catalog/categories` | `ADMIN`, `GERENTE`, `BODEGUERO` | Guardar categoría |
| `GET` | `/api/catalog/brands` | Todos los roles | Catálogo de marcas de productos |
| `POST` | `/api/catalog/brands` | `ADMIN`, `GERENTE`, `BODEGUERO` | Guardar marca comercial |
| `GET` | `/api/catalog/suppliers` | `ADMIN`, `GERENTE`, `BODEGUERO`, `CONTADOR` | Directorio de proveedores |
| `POST` | `/api/catalog/suppliers` | `ADMIN`, `GERENTE`, `BODEGUERO` | Guardar ficha de proveedor |
| `GET` | `/api/users` | `ADMIN` | Listado de cuentas de usuario y roles |
| `POST` | `/api/users` | `ADMIN` | Crear o editar credenciales, rol y PIN |
| `GET` | `/api/reports/dashboard` | `ADMIN`, `GERENTE`, `CONTADOR` | Métricas y KPIs ejecutivos en tiempo real |
| `GET` | `/api/reports/sales-by-product`| `ADMIN`, `GERENTE`, `CONTADOR` | Reporte analítico de márgenes brutos |
| `GET` | `/api/reports/kardex-valuation` | `ADMIN`, `GERENTE`, `CONTADOR` | Valuación oficial de existencias al CPP |
| `GET` | `/api/reports/cash-shifts` | `ADMIN`, `GERENTE`, `CONTADOR` | Auditoría de turnos y Cortes Z históricos |
| `GET` | `/api/accounting/entries` | `ADMIN`, `CONTADOR`, `GERENTE` | Libro Diario de asientos contables |
| `GET` | `/api/accounting/entry-lines` | `ADMIN`, `CONTADOR`, `GERENTE` | Detalle Debe/Haber de una partida contable |
| `GET` | `/api/accounting/vat-books` | `ADMIN`, `CONTADOR`, `GERENTE` | Libros oficiales de IVA (Consumidor, CCF, Compras) |
| `GET` | `/api/dtes` | `ADMIN`, `GERENTE`, `CONTADOR` | Listado de DTEs generados y estados MH |

---

## 16. Requerimientos No Funcionales y Resiliencia

1. **Integridad Transaccional (ACID):** Todo cobro, recepción de compra, anulación o cierre de turno se encapsula en `Database::transaction(function(PDO $pdo) { ... })`. Un fallo en cualquier paso cancela la operación sin dejar registros huérfanos.
2. **Precisión Financiera:** Todas las operaciones de montos monetarios, costos, impuestos y cantidades se realizan en tipo `NUMERIC(18,4)`. Se prohíbe el uso de tipos de coma flotante (`float`/`double`) en base de datos.
3. **Resiliencia Operativa:** La arquitectura no depende de servidores en la nube para registrar transacciones locales de caja o inventario. Ante caídas de red externa, el supermercado opera con total normalidad.
4. **Protección Criptográfica:** Contraseñas de acceso y PINs de autorización almacenados bajo hashes unidireccionales `Argon2id` / `Bcrypt`.
5. **Auditoría Inmutable:** La tabla `bitacora_auditoria` registra de manera indeleble usuario, IP, acción, valores previos y nuevos para cualquier cambio crítico en el sistema.
6. **Localización y Ergonomía:** Interfaz, reportes y comprobantes configurados al 100% en idioma español, zona horaria oficial `America/El_Salvador` (`UTC-6`) y teclado con atajos directos sin dependencia obligatoria del mouse.

---

## 17. Hoja de Ruta y Próximos Módulos a Desarrollar (Backlog Oficial)

Esta sección consolida las especificaciones técnicas de los módulos acordados para desarrollo en las siguientes fases del sistema, manteniendo el estándar de arquitectura modular, rendimiento local y cumplimiento normativo salvadoreño.

### 17.1 Arquitectura Operativa Descartada (Exclusión de Traslados Multibodega)
- **Decisión de Diseño:** Se **descarta formalmente** la implementación de guías y flujos de traslado interno entre múltiples bodegas.
- **Justificación Operativa:** Para evitar trabas burocráticas y cuellos de botella en la atención de cajas de cobro, el inventario del supermercado opera bajo un modelo de **Almacén y Piso de Venta Unificado Centralizado**. Todas las compras recepcionadas incrementan el stock vendible de inmediato y todas las ventas descargan directamente de esta existencia consolidada.

---

### 17.2 Corte X (Arqueo Parcial y Lectura de Turno en Caliente)
- **Propósito:** Permitir a Gerentes y Administradores auditar en cualquier momento la gaveta de un cajero sin cerrar el turno, sin interrumpir la operación y sin emitir un número fiscal definitivo de Corte Z.
- **Especificación Técnica:**
  - **Endpoint REST:** `GET /api/cash/corte-x?sesion_id={id}` (Roles: `ADMIN`, `GERENTE`).
  - **Cálculo en Vivo:**
    - Fondo de apertura inicial.
    - Sumatoria de ventas desglosadas por medio de pago (Efectivo, Tarjeta, Transferencia, Crédito).
    - Sumatoria de ingresos y retiros parciales de gaveta.
    - Efectivo total esperado en caja al segundo exacto de la consulta.
    - Conteo de transacciones y tickets emitidos en el turno.
  - **Impresión Térmica ESC/POS:** Generación de tirilla de control titulada `*** CORTE X — LECTURA PARCIAL DE TURNO ***` con marca de agua informativa `(DOCUMENTO NO FISCAL — TURNO PERMANECE ABIERTO)`.

---

### 17.3 Lector de Códigos de Balanza Embebidos (EAN-13 Carnicería y Perecederos)
- **Propósito:** Agilizar el cobro de productos pesables (carnes, quesos, embutidos, frutas y verduras) etiquetados previamente en básculas electrónicas de mostrador (ej. Torrey, Dibal, Toledo, CAS).
- **Especificación del Código EAN-13 (Estándar Retail Prefijo 20/21):**
  $$\underbrace{\text{20}}_{\text{Prefijo Balanza}} - \underbrace{\text{PPPPP}}_{\text{Código PLU del Producto}} - \underbrace{\text{WWWWW}}_{\text{Peso o Importe}} - \underbrace{\text{C}}_{\text{Check Digit}}$$
- **Comportamiento en Punto de Venta:**
  1. El cajero pasa el lector láser sobre la etiqueta generada por la balanza.
  2. El frontend (`app.js`) y backend detectan si la cadena de 13 dígitos inicia con `20`, `21` o `22`.
  3. Se extrae el código PLU (`PPPPP`) para localizar el producto en el catálogo.
  4. Los 5 dígitos de peso (`WWWWW`) se convierten automáticamente a formato decimal (ej. `01450` $\rightarrow 1.450\text{ Kg/Lb}$).
  5. Se inserta automáticamente el renglón al carrito con su peso y cálculo exacto de precio sin requerir digitación manual.

---

### 17.4 Motor de Promociones, Combos y Descuentos Automáticos
- **Propósito:** Ejecutar reglas de negocio promocionales de forma automatizada al escanear productos en el carrito.
- **Tipos de Promoción Soportados:**
  - `2X1` / `3X2`: Descuento automático de la unidad de menor valor al alcanzar el múltiplo configurado.
  - `COMBO`: Pack cerrado (ej. Paquete Parrillero: Carbón + Carne + Salsa) con precio global promocional que descuenta simultáneamente las existencias individuales de cada componente.
  - `DESCUENTO_VOLUMEN`: Precios diferenciados por escala de compra (ej. 1 a 5 unidades a $1.50 c/u; 6 o más a $1.25 c/u).
- **Esquema de Tablas Propuesto:**
  ```sql
  CREATE TABLE promociones (
      id SERIAL PRIMARY KEY,
      nombre VARCHAR(120) NOT NULL,
      tipo VARCHAR(30) NOT NULL CHECK (tipo IN ('2X1', '3X2', 'COMBO', 'VOLUMEN', 'PORCENTAJE')),
      fecha_inicio DATE NOT NULL,
      fecha_fin DATE NOT NULL,
      hora_inicio TIME NULL,
      hora_fin TIME NULL,
      dias_semana JSONB NULL, -- [1,2,3,4,5,6,7]
      activo BOOLEAN NOT NULL DEFAULT TRUE
  );

  CREATE TABLE promocion_items (
      id SERIAL PRIMARY KEY,
      promocion_id INT NOT NULL REFERENCES promociones(id) ON DELETE CASCADE,
      producto_id INT NOT NULL REFERENCES productos(id),
      cantidad_requerida NUMERIC(18,4) NOT NULL DEFAULT 1.0000,
      precio_promocional NUMERIC(18,4) NULL,
      descuento_porcentaje NUMERIC(5,2) NULL
  );
  ```

---

### 17.5 Módulo de Finanzas: Cuentas por Cobrar (CxC) y Cuentas por Pagar (CxP)

#### 17.5.1 Cuentas por Cobrar (Cobro y Abonos de Clientes)
- **Problemática Actual:** Las ventas con método `CREDITO` ya validan el `limite_credito` y aumentan el `saldo_deudor` del cliente, pero falta registrar sus pagos posteriores.
- **Flujo Operativo:**
  1. Búsqueda de cliente por DUI, NIT o Nombre.
  2. Consulta de estado de cuenta con detalle de ventas a crédito pendientes y saldo adeudado.
  3. Registro de Abono (Total o Parcial) con método de pago (Efectivo, Cheque o Transferencia Bancaria).
  4. Impresión de **Recibo Oficial de Ingreso / Comprobante de Abono** en formato ticket 80mm.
  5. Disminución automática del `saldo_deudor` en `clientes`.
  6. Generación de Asiento Contable automático:
     - **Debe:** Cuenta `1101` (Caja General) o `1102` (Bancos).
     - **Haber:** Cuenta `1103` (Cuentas por Cobrar Clientes).
- **Tabla Relacional:**
  ```sql
  CREATE TABLE cliente_abonos (
      id SERIAL PRIMARY KEY,
      cliente_id INT NOT NULL REFERENCES clientes(id),
      usuario_id INT NOT NULL REFERENCES usuarios(id),
      monto NUMERIC(18,4) NOT NULL,
      metodo_pago VARCHAR(20) NOT NULL CHECK (metodo_pago IN ('EFECTIVO', 'TRANSFERENCIA', 'CHEQUE')),
      numero_recibo VARCHAR(50) UNIQUE NOT NULL,
      referencia_pago VARCHAR(100) NULL,
      notas TEXT NULL,
      fecha_hora TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
  );
  ```

#### 17.5.2 Cuentas por Pagar (Pagos y Liquidación a Proveedores)
- **Flujo Operativo:**
  1. Bandeja de facturas de compra a crédito con semáforo de vencimiento según `dias_credito`.
  2. Programación de pago y emisión de comprobante de egreso.
  3. Actualización de estado de la compra de `PENDIENTE` a `PAGADA`.
  4. Asiento Contable automático:
     - **Debe:** Cuenta `2101` (Cuentas por Pagar Proveedores).
     - **Haber:** Cuenta `1102` (Bancos) o `1101` (Caja General).
- **Tabla Relacional:**
  ```sql
  CREATE TABLE proveedor_pagos (
      id SERIAL PRIMARY KEY,
      proveedor_id INT NOT NULL REFERENCES proveedores(id),
      compra_id INT NOT NULL REFERENCES compras(id),
      usuario_id INT NOT NULL REFERENCES usuarios(id),
      monto NUMERIC(18,4) NOT NULL,
      metodo_pago VARCHAR(20) NOT NULL,
      comprobante_banco VARCHAR(100) NULL,
      fecha_pago DATE NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
  );
  ```

---

### 17.6 Facturación DTE El Salvador: Conexión en Vivo y Firmador Electrónico
- **Componente Firmador Digital (.p12):**
  - Implementación del servicio de firmado digital de Hacienda utilizando la llave privada y certificado criptográfico `.p12` emitido al contribuyente.
  - Empaquetado del JSON del DTE bajo el estándar **JSON Web Signature (JWS)** con algoritmo `RS512` / `SHA512withRSA`.
- **Transmisión REST en Tiempo Real:**
  - Envío seguro vía TLS 1.3 al endpoint oficial del Ministerio de Hacienda (`/fesv/recepciondte`).
  - Procesamiento de la respuesta síncrona: captura del `selloRecibido`, fecha/hora de validación y estado oficial `RECIBIDO`.
- **Cola de Contingencia y Reintentos Automáticos:**
  - Si la conexión externa falla o Hacienda devuelve error temporal de servicio (código 500/503), el DTE se encola en `dte_cola_transmision` con bandera de contingencia activa.
  - Un proceso en background (worker local) reintenta la transmisión de forma desatendida respetando la ventana de horas estipulada por la DGII.
- **Evento de Invalidación Electrónica:**
  - Generación del documento de invalidación formal ante el MH cuando se anula una venta, vinculando el UUID original, motivo tributario y firma del responsable.

---

### 17.7 Automatización de Hardware y Respaldos del Sistema

#### 17.7.1 Impresión Térmica Directa por Red (Raw ESC/POS vía Socket TCP)
- **Objetivo:** Eliminar por completo el cuadro de diálogo de impresión del navegador web para lograr tiempos de cobro de 1 segundo por cliente.
- **Arquitectura:**
  - Apertura de socket TCP nativo en PHP (`fsockopen($ipTerminal, 9100, $errno, $errstr, 2)`).
  - Envío de flujo binario de comandos ESC/POS:
    - Inicialización de impresora: `\x1B\x40`
    - Alineación y fuentes en negrita: `\x1B\x61\x01`, `\x1B\x45\x01`
    - Pulso de apertura inmediata de cajón de dinero: `\x1B\x70\x00\x19\xFA`
    - Corte parcial de papel: `\x1D\x56\x01`

#### 17.7.2 Script Automatizado de Respaldo Diario de PostgreSQL
- **Plan de Continuidad de Negocio:**
  - Script programado para el Programador de Tareas de Windows (*Windows Task Scheduler*) o Cron.
  - Ejecución diaria desatendida a las 23:00 hrs utilizando la utilidad nativa `pg_dump`:
    ```bat
    pg_dump -U postgres -d pos_supermercado -F c -b -v -f "C:\Users\carlo\OneDrive\Documentos\Antigravity\Sistema Pos\database\backup\pos_supermercado_%date:~-4,4%%date:~-7,2%%date:~-10,2%.backup"
    ```
  - Purga y retención automática de respaldos con antigüedad mayor a 30 días para optimizar espacio en disco.
