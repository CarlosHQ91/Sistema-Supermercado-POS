-- ==============================================================================
-- Sistema POS Supermercado - El Salvador (Normativa DTE / MH)
-- Motor: PostgreSQL 15+ / 16 / 18
-- Tipos monetarios: NUMERIC(18,4)
-- ==============================================================================

-- 1. CONFIGURACIÓN GENERAL Y EMPRESA (DTE)
CREATE TABLE IF NOT EXISTS configuracion_empresa (
    id SERIAL PRIMARY KEY,
    nombre_razon_social VARCHAR(250) NOT NULL,
    nombre_comercial VARCHAR(250) NOT NULL,
    nit VARCHAR(20) NOT NULL,
    nrc VARCHAR(20) NOT NULL,
    codigo_actividad VARCHAR(10) NOT NULL DEFAULT '47111', -- Venta al por menor en supermercados
    actividad_economica VARCHAR(250) NOT NULL DEFAULT 'Comercio al por menor en supermercados',
    direccion TEXT NOT NULL,
    departamento VARCHAR(50) NOT NULL DEFAULT 'San Salvador',
    municipio VARCHAR(50) NOT NULL DEFAULT 'San Salvador Centro',
    telefono VARCHAR(30) NOT NULL,
    correo VARCHAR(100) NOT NULL,
    establecimiento_codigo VARCHAR(10) NOT NULL DEFAULT 'M001',
    punto_venta_codigo VARCHAR(10) NOT NULL DEFAULT 'P001',
    ambiente_dte VARCHAR(10) NOT NULL DEFAULT '00', -- 00 = Pruebas, 01 = Producción
    api_mh_url VARCHAR(255) NOT NULL DEFAULT 'https://apitest.dtes.mh.gob.sv',
    api_mh_user VARCHAR(100) NULL,
    api_mh_token TEXT NULL,
    token_expira_en TIMESTAMP NULL,
    certificado_p12_path VARCHAR(255) NULL,
    certificado_p12_clave VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 2. USUARIOS Y ROLES (ADMIN, GERENTE, CAJERO, BODEGUERO, CONTADOR)
CREATE TABLE IF NOT EXISTS usuarios (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    nombre_completo VARCHAR(150) NOT NULL,
    email VARCHAR(100) UNIQUE NULL,
    rol VARCHAR(30) NOT NULL CHECK (rol IN ('ADMIN', 'GERENTE', 'CAJERO', 'BODEGUERO', 'CONTADOR')),
    pin_autorizacion VARCHAR(255) NULL, -- Hash de PIN rápido para autorizaciones en POS
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 3. BITÁCORA DE AUDITORÍA (INMUTABLE / APPEND-ONLY)
CREATE TABLE IF NOT EXISTS bitacora_auditoria (
    id BIGSERIAL PRIMARY KEY,
    usuario_id INT NULL REFERENCES usuarios(id) ON DELETE SET NULL,
    accion VARCHAR(50) NOT NULL, -- LOGIN, CREATE, UPDATE, DELETE, VOID, APPROVE
    entidad VARCHAR(80) NOT NULL,
    entidad_id VARCHAR(80) NULL,
    valor_anterior JSONB NULL,
    valor_nuevo JSONB NULL,
    ip_origen VARCHAR(45) NULL,
    detalles TEXT NULL,
    fecha_hora TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 4. BODEGAS Y ALMACENES
CREATE TABLE IF NOT EXISTS bodegas (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(100) UNIQUE NOT NULL,
    es_principal BOOLEAN NOT NULL DEFAULT FALSE,
    ubicacion VARCHAR(150) NULL,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 5. IMPUESTOS
CREATE TABLE IF NOT EXISTS impuestos (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(50) NOT NULL,
    porcentaje NUMERIC(5,2) NOT NULL DEFAULT 13.00,
    codigo_tributo_mh VARCHAR(10) NOT NULL DEFAULT '20', -- '20' = IVA 13% El Salvador
    es_retencion BOOLEAN NOT NULL DEFAULT FALSE,
    activo BOOLEAN NOT NULL DEFAULT TRUE
);

-- 6. CATEGORÍAS Y MARCAS
CREATE TABLE IF NOT EXISTS categorias (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(100) UNIQUE NOT NULL,
    descripcion TEXT NULL,
    padre_id INT NULL REFERENCES categorias(id) ON DELETE SET NULL,
    activo BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS marcas (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(100) UNIQUE NOT NULL,
    activo BOOLEAN NOT NULL DEFAULT TRUE
);

-- 7. PRODUCTOS Y CÓDIGOS DE BARRA
CREATE TABLE IF NOT EXISTS productos (
    id SERIAL PRIMARY KEY,
    codigo_interno VARCHAR(50) UNIQUE NOT NULL, -- ej. PRD-000001
    nombre VARCHAR(200) NOT NULL,
    descripcion TEXT NULL,
    categoria_id INT NULL REFERENCES categorias(id) ON DELETE SET NULL,
    marca_id INT NULL REFERENCES marcas(id) ON DELETE SET NULL,
    unidad_medida VARCHAR(20) NOT NULL DEFAULT 'UNIDAD', -- UNIDAD, KG, LIBRA, LITRO, etc.
    presentacion VARCHAR(100) NULL, -- 'Bolsa 500g', 'Botella 1L'
    costo_promedio NUMERIC(18,4) NOT NULL DEFAULT 0.0000,
    precio_venta NUMERIC(18,4) NOT NULL DEFAULT 0.0000,
    impuesto_id INT NOT NULL REFERENCES impuestos(id),
    es_pesable BOOLEAN NOT NULL DEFAULT FALSE,
    es_combo BOOLEAN NOT NULL DEFAULT FALSE,
    maneja_lotes BOOLEAN NOT NULL DEFAULT FALSE,
    stock_minimo NUMERIC(18,4) NOT NULL DEFAULT 5.0000,
    imagen_url VARCHAR(255) NULL,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS producto_codigos_barra (
    id SERIAL PRIMARY KEY,
    producto_id INT NOT NULL REFERENCES productos(id) ON DELETE CASCADE,
    codigo_barra VARCHAR(100) NOT NULL,
    es_principal BOOLEAN NOT NULL DEFAULT TRUE,
    descripcion VARCHAR(80) NULL, -- 'Unidad', 'Caja x12', 'Six-pack'
    CONSTRAINT uq_codigo_barra UNIQUE(codigo_barra)
);

-- 8. STOCK POR BODEGA
CREATE TABLE IF NOT EXISTS stock_bodega (
    id SERIAL PRIMARY KEY,
    producto_id INT NOT NULL REFERENCES productos(id) ON DELETE CASCADE,
    bodega_id INT NOT NULL REFERENCES bodegas(id) ON DELETE RESTRICT,
    cantidad NUMERIC(18,4) NOT NULL DEFAULT 0.0000,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_prod_bodega UNIQUE(producto_id, bodega_id)
);

-- 9. LOTES Y FECHAS DE VENCIMIENTO (FEFO)
CREATE TABLE IF NOT EXISTS lotes (
    id SERIAL PRIMARY KEY,
    producto_id INT NOT NULL REFERENCES productos(id) ON DELETE CASCADE,
    bodega_id INT NOT NULL REFERENCES bodegas(id) ON DELETE RESTRICT,
    numero_lote VARCHAR(80) NOT NULL,
    fecha_vencimiento DATE NOT NULL,
    cantidad NUMERIC(18,4) NOT NULL DEFAULT 0.0000,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_lote_prod_bodega UNIQUE(producto_id, bodega_id, numero_lote)
);

-- 10. COMBOS / KITS / RECETAS
CREATE TABLE IF NOT EXISTS combo_items (
    id SERIAL PRIMARY KEY,
    combo_producto_id INT NOT NULL REFERENCES productos(id) ON DELETE CASCADE,
    componente_producto_id INT NOT NULL REFERENCES productos(id) ON DELETE RESTRICT,
    cantidad NUMERIC(18,4) NOT NULL DEFAULT 1.0000,
    CONSTRAINT uq_combo_componente UNIQUE(combo_producto_id, componente_producto_id)
);

-- 11. KARDEX INMUTABLE (APPEND-ONLY)
CREATE TABLE IF NOT EXISTS kardex_movimientos (
    id BIGSERIAL PRIMARY KEY,
    fecha_hora TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    producto_id INT NOT NULL REFERENCES productos(id) ON DELETE RESTRICT,
    bodega_id INT NOT NULL REFERENCES bodegas(id) ON DELETE RESTRICT,
    tipo_movimiento VARCHAR(50) NOT NULL, -- COMPRA, VENTA, ANULACION_VENTA, TRASLADO_SALIDA, TRASLADO_ENTRADA, AJUSTE_POSITIVO, AJUSTE_NEGATIVO, MERMA, INVENTARIO_INICIAL
    cantidad NUMERIC(18,4) NOT NULL, -- Positivo para entrada, negativo para salida
    costo_unitario NUMERIC(18,4) NOT NULL,
    stock_anterior NUMERIC(18,4) NOT NULL,
    stock_posterior NUMERIC(18,4) NOT NULL,
    documento_referencia VARCHAR(100) NULL, -- 'FAC-00123', 'TKT-00456', 'TRS-0001'
    usuario_id INT NULL REFERENCES usuarios(id) ON DELETE SET NULL,
    notas TEXT NULL
);

-- 12. PROVEEDORES Y ÓRDENES DE COMPRA
CREATE TABLE IF NOT EXISTS proveedores (
    id SERIAL PRIMARY KEY,
    razon_social VARCHAR(200) NOT NULL,
    nombre_comercial VARCHAR(200) NULL,
    nit VARCHAR(20) NOT NULL,
    nrc VARCHAR(20) NULL,
    contacto VARCHAR(150) NULL,
    telefono VARCHAR(50) NULL,
    correo VARCHAR(100) NULL,
    direccion TEXT NULL,
    dias_credito INT NOT NULL DEFAULT 0,
    saldo_pendiente NUMERIC(18,4) NOT NULL DEFAULT 0.0000,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ordenes_compra (
    id SERIAL PRIMARY KEY,
    numero_orden VARCHAR(50) UNIQUE NOT NULL,
    proveedor_id INT NOT NULL REFERENCES proveedores(id),
    bodega_destino_id INT NOT NULL REFERENCES bodegas(id),
    usuario_id INT NOT NULL REFERENCES usuarios(id),
    fecha_emision DATE NOT NULL DEFAULT CURRENT_DATE,
    fecha_esperada DATE NULL,
    estado VARCHAR(30) NOT NULL DEFAULT 'PENDIENTE', -- PENDIENTE, APROBADA, RECIBIDA, CANCELADA
    subtotal NUMERIC(18,4) NOT NULL DEFAULT 0.0000,
    iva NUMERIC(18,4) NOT NULL DEFAULT 0.0000,
    total NUMERIC(18,4) NOT NULL DEFAULT 0.0000,
    notas TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ordenes_compra_detalle (
    id SERIAL PRIMARY KEY,
    orden_compra_id INT NOT NULL REFERENCES ordenes_compra(id) ON DELETE CASCADE,
    producto_id INT NOT NULL REFERENCES productos(id),
    cantidad_solicitada NUMERIC(18,4) NOT NULL,
    cantidad_recibida NUMERIC(18,4) NOT NULL DEFAULT 0.0000,
    costo_pactado NUMERIC(18,4) NOT NULL,
    total_linea NUMERIC(18,4) NOT NULL
);

-- FACTURAS DE COMPRAS Y PAGOS A PROVEEDORES (CxP)
CREATE TABLE IF NOT EXISTS compras_facturas (
    id SERIAL PRIMARY KEY,
    proveedor_id INT NOT NULL REFERENCES proveedores(id),
    bodega_id INT NOT NULL REFERENCES bodegas(id),
    usuario_id INT NOT NULL REFERENCES usuarios(id),
    orden_compra_id INT NULL REFERENCES ordenes_compra(id),
    numero_factura_proveedor VARCHAR(80) NOT NULL,
    tipo_documento VARCHAR(10) NOT NULL DEFAULT '03', -- 03 CCF, 01 Factura, 14 Sujeto Excluido
    fecha_factura DATE NOT NULL,
    es_credito BOOLEAN NOT NULL DEFAULT FALSE,
    dias_credito INT NOT NULL DEFAULT 0,
    fecha_vencimiento DATE NULL,
    subtotal NUMERIC(18,4) NOT NULL DEFAULT 0.0000,
    iva NUMERIC(18,4) NOT NULL DEFAULT 0.0000,
    total NUMERIC(18,4) NOT NULL DEFAULT 0.0000,
    saldo_pendiente NUMERIC(18,4) NOT NULL DEFAULT 0.0000,
    estado VARCHAR(20) NOT NULL DEFAULT 'PAGADA', -- PAGADA, PENDIENTE, ANULADA
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS compras_facturas_detalle (
    id SERIAL PRIMARY KEY,
    compra_id INT NOT NULL REFERENCES compras_facturas(id) ON DELETE CASCADE,
    producto_id INT NOT NULL REFERENCES productos(id),
    cantidad NUMERIC(18,4) NOT NULL,
    costo_unitario NUMERIC(18,4) NOT NULL,
    iva_linea NUMERIC(18,4) NOT NULL DEFAULT 0.0000,
    total_linea NUMERIC(18,4) NOT NULL,
    numero_lote VARCHAR(80) NULL,
    fecha_vencimiento DATE NULL
);

CREATE TABLE IF NOT EXISTS compras_pagos (
    id SERIAL PRIMARY KEY,
    compra_id INT NOT NULL REFERENCES compras_facturas(id) ON DELETE CASCADE,
    fecha_pago DATE NOT NULL DEFAULT CURRENT_DATE,
    monto NUMERIC(18,4) NOT NULL,
    metodo_pago VARCHAR(30) NOT NULL DEFAULT 'TRANSFERENCIA',
    referencia VARCHAR(100) NULL,
    usuario_id INT NOT NULL REFERENCES usuarios(id),
    notas TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 13. TRASLADOS ENTRE BODEGAS
CREATE TABLE IF NOT EXISTS traslados (
    id SERIAL PRIMARY KEY,
    numero_traslado VARCHAR(50) UNIQUE NOT NULL,
    bodega_origen_id INT NOT NULL REFERENCES bodegas(id),
    bodega_destino_id INT NOT NULL REFERENCES bodegas(id),
    usuario_envia_id INT NOT NULL REFERENCES usuarios(id),
    usuario_recibe_id INT NULL REFERENCES usuarios(id),
    fecha_envio TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_recepcion TIMESTAMP NULL,
    estado VARCHAR(30) NOT NULL DEFAULT 'COMPLETADO', -- COMPLETADO, EN_TRANSITO, CANCELADO
    motivo TEXT NULL
);

CREATE TABLE IF NOT EXISTS traslados_detalle (
    id SERIAL PRIMARY KEY,
    traslado_id INT NOT NULL REFERENCES traslados(id) ON DELETE CASCADE,
    producto_id INT NOT NULL REFERENCES productos(id),
    cantidad NUMERIC(18,4) NOT NULL,
    costo_unitario NUMERIC(18,4) NOT NULL
);

-- 14. TOMAS FÍSICAS DE INVENTARIO
CREATE TABLE IF NOT EXISTS tomas_fisicas (
    id SERIAL PRIMARY KEY,
    numero_toma VARCHAR(50) UNIQUE NOT NULL,
    bodega_id INT NOT NULL REFERENCES bodegas(id),
    usuario_apertura_id INT NOT NULL REFERENCES usuarios(id),
    usuario_cierre_id INT NULL REFERENCES usuarios(id),
    fecha_inicio TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_cierre TIMESTAMP NULL,
    estado VARCHAR(30) NOT NULL DEFAULT 'ABIERTA', -- ABIERTA, APROBADA, RECHAZADA
    notas TEXT NULL
);

CREATE TABLE IF NOT EXISTS tomas_fisicas_detalle (
    id SERIAL PRIMARY KEY,
    toma_id INT NOT NULL REFERENCES tomas_fisicas(id) ON DELETE CASCADE,
    producto_id INT NOT NULL REFERENCES productos(id),
    stock_sistema NUMERIC(18,4) NOT NULL,
    conteo_fisico NUMERIC(18,4) NOT NULL,
    diferencia NUMERIC(18,4) NOT NULL,
    costo_unitario NUMERIC(18,4) NOT NULL,
    ajuste_aplicado BOOLEAN NOT NULL DEFAULT FALSE
);

-- 15. CAJAS Y CONTROL DE TURNOS
CREATE TABLE IF NOT EXISTS cajas (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(80) NOT NULL, -- 'Caja 1', 'Caja 2'
    numero_terminal VARCHAR(20) UNIQUE NOT NULL, -- 'TERM-01', 'TERM-02'
    impresora_nombre VARCHAR(100) NULL,
    ip_terminal VARCHAR(45) NULL,
    activo BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS caja_sesiones (
    id SERIAL PRIMARY KEY,
    caja_id INT NOT NULL REFERENCES cajas(id),
    usuario_id INT NOT NULL REFERENCES usuarios(id), -- Cajero
    supervisor_apertura_id INT NULL REFERENCES usuarios(id),
    supervisor_cierre_id INT NULL REFERENCES usuarios(id),
    fecha_apertura TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    monto_apertura NUMERIC(18,4) NOT NULL DEFAULT 0.0000,
    fecha_cierre TIMESTAMP NULL,
    monto_esperado NUMERIC(18,4) NULL,
    monto_real NUMERIC(18,4) NULL,
    diferencia NUMERIC(18,4) NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'ABIERTA', -- ABIERTA, CERRADA
    notas_apertura TEXT NULL,
    notas_cierre TEXT NULL,
    corte_z_numero VARCHAR(50) NULL
);

CREATE TABLE IF NOT EXISTS caja_movimientos (
    id SERIAL PRIMARY KEY,
    sesion_id INT NOT NULL REFERENCES caja_sesiones(id) ON DELETE CASCADE,
    tipo VARCHAR(20) NOT NULL CHECK (tipo IN ('INGRESO', 'RETIRO')),
    monto NUMERIC(18,4) NOT NULL,
    motivo VARCHAR(200) NOT NULL,
    autorizado_por INT NOT NULL REFERENCES usuarios(id),
    fecha_hora TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS caja_arqueo_detalles (
    id SERIAL PRIMARY KEY,
    sesion_id INT NOT NULL REFERENCES caja_sesiones(id) ON DELETE CASCADE,
    momento VARCHAR(20) NOT NULL CHECK (momento IN ('APERTURA', 'CIERRE')),
    denominacion_tipo VARCHAR(20) NOT NULL, -- 'BILLETE', 'MONEDA', 'VOUCHER'
    denominacion_valor NUMERIC(10,2) NOT NULL, -- 100, 50, 20, 10, 5, 1, 0.25, 0.10, 0.05, 0.01
    cantidad INT NOT NULL DEFAULT 0,
    subtotal NUMERIC(18,4) NOT NULL DEFAULT 0.0000
);

-- 16. CLIENTES Y ABONOS (CxC)
CREATE TABLE IF NOT EXISTS clientes (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(200) NOT NULL,
    nit VARCHAR(20) NULL,
    nrc VARCHAR(20) NULL,
    dui VARCHAR(20) NULL,
    direccion TEXT NULL,
    telefono VARCHAR(50) NULL,
    correo VARCHAR(100) NULL,
    aplica_credito BOOLEAN NOT NULL DEFAULT FALSE,
    limite_credito NUMERIC(18,4) NOT NULL DEFAULT 0.0000,
    saldo_deudor NUMERIC(18,4) NOT NULL DEFAULT 0.0000,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS cliente_abonos (
    id SERIAL PRIMARY KEY,
    cliente_id INT NOT NULL REFERENCES clientes(id),
    sesion_caja_id INT NULL REFERENCES caja_sesiones(id),
    usuario_id INT NOT NULL REFERENCES usuarios(id),
    fecha_hora TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    monto NUMERIC(18,4) NOT NULL,
    metodo_pago VARCHAR(30) NOT NULL DEFAULT 'EFECTIVO',
    referencia VARCHAR(100) NULL,
    notas TEXT NULL
);

-- 17. VENTAS (POS)
CREATE TABLE IF NOT EXISTS ventas (
    id SERIAL PRIMARY KEY,
    numero_venta VARCHAR(50) UNIQUE NOT NULL, -- Correlativo local
    sesion_caja_id INT NOT NULL REFERENCES caja_sesiones(id),
    usuario_cajero_id INT NOT NULL REFERENCES usuarios(id),
    cliente_id INT NOT NULL REFERENCES clientes(id),
    tipo_documento VARCHAR(10) NOT NULL DEFAULT '01', -- 01: FCF (Factura), 03: CCF (Crédito Fiscal), 05: NC
    numero_control_mh VARCHAR(100) NULL, -- DTE-01-M001P001-000000000000001
    codigo_generacion_mh VARCHAR(100) NULL, -- UUID v4 en mayúsculas
    sello_recepcion_mh VARCHAR(150) NULL,
    estado_dte VARCHAR(30) NOT NULL DEFAULT 'LOCAL', -- LOCAL, TRANSMITIDO, RECHAZADO, CONTINGENCIA
    subtotal_gravado NUMERIC(18,4) NOT NULL DEFAULT 0.0000,
    subtotal_exento NUMERIC(18,4) NOT NULL DEFAULT 0.0000,
    descuento NUMERIC(18,4) NOT NULL DEFAULT 0.0000,
    iva NUMERIC(18,4) NOT NULL DEFAULT 0.0000,
    total NUMERIC(18,4) NOT NULL DEFAULT 0.0000,
    estado VARCHAR(20) NOT NULL DEFAULT 'COMPLETADA', -- COMPLETADA, ANULADA, EN_ESPERA
    motivo_anulacion TEXT NULL,
    anulado_por_usuario_id INT NULL REFERENCES usuarios(id),
    anulado_en TIMESTAMP NULL,
    fecha_hora TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS venta_items (
    id SERIAL PRIMARY KEY,
    venta_id INT NOT NULL REFERENCES ventas(id) ON DELETE CASCADE,
    producto_id INT NOT NULL REFERENCES productos(id),
    bodega_id INT NOT NULL REFERENCES bodegas(id),
    cantidad NUMERIC(18,4) NOT NULL,
    precio_unitario NUMERIC(18,4) NOT NULL,
    costo_unitario NUMERIC(18,4) NOT NULL, -- Para cálculo exacto de utilidad y COGS
    descuento NUMERIC(18,4) NOT NULL DEFAULT 0.0000,
    iva_linea NUMERIC(18,4) NOT NULL DEFAULT 0.0000,
    total_linea NUMERIC(18,4) NOT NULL,
    numero_lote VARCHAR(80) NULL
);

CREATE TABLE IF NOT EXISTS venta_pagos (
    id SERIAL PRIMARY KEY,
    venta_id INT NOT NULL REFERENCES ventas(id) ON DELETE CASCADE,
    metodo_pago VARCHAR(30) NOT NULL, -- EFECTIVO, TARJETA, TRANSFERENCIA, CREDITO
    monto NUMERIC(18,4) NOT NULL,
    monto_recibido NUMERIC(18,4) NOT NULL DEFAULT 0.0000,
    vuelto NUMERIC(18,4) NOT NULL DEFAULT 0.0000,
    referencia VARCHAR(100) NULL -- No. voucher tarjeta o No. comprobante banco
);

-- 18. DTE HISTORIAL Y COLA DE CONTINGENCIA
CREATE TABLE IF NOT EXISTS dte_documentos (
    id SERIAL PRIMARY KEY,
    venta_id INT NOT NULL REFERENCES ventas(id) ON DELETE CASCADE,
    tipo_dte VARCHAR(10) NOT NULL,
    codigo_generacion VARCHAR(100) UNIQUE NOT NULL,
    numero_control VARCHAR(100) NOT NULL,
    json_firmado TEXT NOT NULL,
    sello_recepcion VARCHAR(150) NULL,
    estado_envio VARCHAR(30) NOT NULL DEFAULT 'PENDIENTE', -- PENDIENTE, TRANSMITIDO, RECHAZADO, CONTINGENCIA
    intentos_envio INT NOT NULL DEFAULT 0,
    respuesta_mh JSONB NULL,
    fecha_generacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_transmision TIMESTAMP NULL
);

-- 19. CONTABILIDAD INTEGRADA (CATÁLOGO, PARTIDA DOBLE Y LIBROS FISCALES)
CREATE TABLE IF NOT EXISTS cuentas_contables (
    id SERIAL PRIMARY KEY,
    codigo VARCHAR(50) UNIQUE NOT NULL, -- 1101, 1102, 2101, 4101, 5101
    nombre VARCHAR(150) NOT NULL,
    tipo VARCHAR(30) NOT NULL, -- ACTIVO, PASIVO, PATRIMONIO, INGRESO, COSTO, GASTO
    padre_id INT NULL REFERENCES cuentas_contables(id) ON DELETE SET NULL,
    naturaleza VARCHAR(10) NOT NULL DEFAULT 'DEUDORA', -- DEUDORA, ACREEDORA
    permite_movimientos BOOLEAN NOT NULL DEFAULT TRUE,
    activo BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS asientos_contables (
    id BIGSERIAL PRIMARY KEY,
    numero_asiento VARCHAR(50) UNIQUE NOT NULL,
    fecha DATE NOT NULL DEFAULT CURRENT_DATE,
    concepto TEXT NOT NULL,
    origen_modulo VARCHAR(50) NOT NULL, -- VENTAS, COMPRAS, CAJA, MERMA, AJUSTE
    referencia_id VARCHAR(80) NULL,
    usuario_id INT NULL REFERENCES usuarios(id),
    total_debe NUMERIC(18,4) NOT NULL DEFAULT 0.0000,
    total_haber NUMERIC(18,4) NOT NULL DEFAULT 0.0000,
    estado VARCHAR(20) NOT NULL DEFAULT 'CONFIRMADO',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS asiento_lineas (
    id BIGSERIAL PRIMARY KEY,
    asiento_id BIGINT NOT NULL REFERENCES asientos_contables(id) ON DELETE CASCADE,
    cuenta_id INT NOT NULL REFERENCES cuentas_contables(id),
    debe NUMERIC(18,4) NOT NULL DEFAULT 0.0000,
    haber NUMERIC(18,4) NOT NULL DEFAULT 0.0000,
    concepto_linea VARCHAR(200) NULL
);

-- ÍNDICES ESTRATÉGICOS PARA BÚSQUEDA SUB-300MS
CREATE INDEX IF NOT EXISTS idx_productos_nombre ON productos (nombre);
CREATE INDEX IF NOT EXISTS idx_productos_codigo ON productos (codigo_interno);
CREATE INDEX IF NOT EXISTS idx_codigos_barra_busqueda ON producto_codigos_barra (codigo_barra);
CREATE INDEX IF NOT EXISTS idx_kardex_producto_bodega ON kardex_movimientos (producto_id, bodega_id, fecha_hora);
CREATE INDEX IF NOT EXISTS idx_ventas_sesion ON ventas (sesion_caja_id);
CREATE INDEX IF NOT EXISTS idx_ventas_fecha ON ventas (fecha_hora);
CREATE INDEX IF NOT EXISTS idx_asientos_fecha ON asientos_contables (fecha);
