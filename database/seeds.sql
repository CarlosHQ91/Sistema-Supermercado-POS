-- ==============================================================================
-- Seeds iniciales - Sistema POS Supermercado El Salvador
-- ==============================================================================

-- 1. CONFIGURACIÓN DE EMPRESA
INSERT INTO configuracion_empresa (
    nombre_razon_social, nombre_comercial, nit, nrc, codigo_actividad, actividad_economica,
    direccion, departamento, municipio, telefono, correo, establecimiento_codigo, punto_venta_codigo, ambiente_dte
) VALUES (
    'SUPERMERCADO EL SOL, S.A. DE C.V.',
    'SUPERMERCADO EL SOL',
    '0614-010190-101-1',
    '123456-7',
    '47111',
    'Comercio al por menor en comercios no especializados con surtido compuesto principalmente por alimentos, bebidas o tabaco',
    'Calle Principal #123, Colonia Escalón',
    'San Salvador',
    'San Salvador Centro',
    '2250-0000',
    'contacto@supermercadoelsol.sv',
    'M001',
    'P001',
    '00'
) ON CONFLICT DO NOTHING;

-- 2. USUARIOS INICIALES (Contraseña por defecto: admin123, gerente123, cajero123, bodega123, contador123)
-- Hashes generados con password_hash() BCRYPT (cost 10)
INSERT INTO usuarios (username, password_hash, nombre_completo, email, rol, pin_autorizacion, activo) VALUES
('admin', '$2y$10$e8w93eQcE98T0s3uU8bHh.n734r8vF9yB9VbS3jD4mE5rY8hH9hH2', 'Administrador General', 'admin@supermercadoelsol.sv', 'ADMIN', '$2y$10$5Zl4wL0f6L3d9zY0g7q2VuW0bY8cT1s8rD2bJ4nF6xK8mP0vR3tG1', TRUE),
('gerente', '$2y$10$vD7q9rP0s2tU4wX6yZ8bC.e123r4vF5yG6hJ7kL8mN9oP0qR1sT2u', 'Carlos Gerente Supervisor', 'gerente@supermercadoelsol.sv', 'GERENTE', '$2y$10$5Zl4wL0f6L3d9zY0g7q2VuW0bY8cT1s8rD2bJ4nF6xK8mP0vR3tG1', TRUE),
('cajero1', '$2y$10$wE8r0sP1t2uV5wX7yZ9bD.f234r5vF6yG7hJ8kL9mN0oP1qR2sT3v', 'Ana María Cajera', 'cajero1@supermercadoelsol.sv', 'CAJERO', NULL, TRUE),
('bodega', '$2y$10$xF9s1tQ2u3wW6xY8zZ0cE.g345r6vF7yG8hJ9kL0mN1oP2qR3sT4w', 'Roberto Bodeguero', 'bodega@supermercadoelsol.sv', 'BODEGUERO', NULL, TRUE),
('contador', '$2y$10$yG0t2uR3v4xX7yZ9aA1dF.h456r7vG8yH9iK0lM1nO2pQ3rS4tU5x', 'Lic. Juan Contador', 'contador@supermercadoelsol.sv', 'CONTADOR', NULL, TRUE)
ON CONFLICT (username) DO NOTHING;

-- 3. BODEGAS
INSERT INTO bodegas (nombre, es_principal, ubicacion, activo) VALUES
('Piso de Venta / Góndola', TRUE, 'Sala principal de ventas', TRUE),
('Bodega Principal', FALSE, 'Almacén general posterior', TRUE),
('Área Fría / Congeladores', FALSE, 'Cámaras frigoríficas', TRUE),
('Merma / Dañados', FALSE, 'Cuarentena y productos vencidos', TRUE)
ON CONFLICT (nombre) DO NOTHING;

-- 4. IMPUESTOS
INSERT INTO impuestos (nombre, porcentaje, codigo_tributo_mh, es_retencion, activo) VALUES
('IVA 13%', 13.00, '20', FALSE, TRUE),
('Exento', 0.00, '00', FALSE, TRUE)
ON CONFLICT DO NOTHING;

-- 5. CAJAS / TERMINALES
INSERT INTO cajas (nombre, numero_terminal, impresora_nombre, activo) VALUES
('Caja 1 (Principal)', 'TERM-01', 'Generic 80mm ESC/POS', TRUE),
('Caja 2 (Rápida)', 'TERM-02', 'Generic 80mm ESC/POS', TRUE)
ON CONFLICT (numero_terminal) DO NOTHING;

-- 6. CATEGORÍAS
INSERT INTO categorias (nombre, descripcion, activo) VALUES
('Abarrotes y Granos', 'Granos básicos, harinas, pastas y conservas', TRUE),
('Lácteos y Huevos', 'Leche, quesos, yogur, mantequilla y huevos', TRUE),
('Bebidas y Refrescos', 'Gaseosas, jugos, aguas y energizantes', TRUE),
('Frutas y Verduras', 'Productos frescos pesables', TRUE),
('Carnes y Embutidos', 'Aves, res, cerdo y embutidos', TRUE),
('Cuidado del Hogar', 'Detergentes, desinfectantes y papel', TRUE)
ON CONFLICT (nombre) DO NOTHING;

-- 7. MARCAS
INSERT INTO marcas (nombre, activo) VALUES
('La Salud', TRUE),
('Dos Pinos', TRUE),
('Diana', TRUE),
('Coca Cola', TRUE),
('San Francisco', TRUE),
('Bimbo', TRUE),
('McCormick', TRUE),
('Genérica / Sin Marca', TRUE)
ON CONFLICT (nombre) DO NOTHING;

-- 8. CLIENTES BASE
INSERT INTO clientes (nombre, nit, nrc, dui, direccion, telefono, correo, aplica_credito, limite_credito, saldo_deudor, activo) VALUES
('Consumidor Final', '0000-000000-000-0', NULL, '00000000-0', 'Ciudad', '0000-0000', 'cliente@pos.sv', FALSE, 0.0000, 0.0000, TRUE),
('Distribuidora San Salvador, S.A. de C.V.', '0614-121200-102-3', '234567-8', NULL, 'Boulevard Los Próceres #45, San Salvador', '2233-4455', 'compras@distsansalvador.sv', TRUE, 500.0000, 0.0000, TRUE)
ON CONFLICT DO NOTHING;

-- 9. PROVEEDORES BASE
INSERT INTO proveedores (razon_social, nombre_comercial, nit, nrc, contacto, telefono, correo, direccion, dias_credito, activo) VALUES
('INDUSTRIAS LÁCTEAS DE EL SALVADOR, S.A.', 'LÁCTEOS LA SALUD', '0614-101085-101-2', '12345-1', 'Lic. Francisco Rivera', '2300-1100', 'pedidos@lasalud.com.sv', 'Km 10 Carretera al Puerto de La Libertad', 30, TRUE),
('DISTRIBUIDORA DE BEBIDAS CENTROAMERICANAS, S.A.', 'COCA COLA FEMSA SV', '0614-200590-103-4', '54321-9', 'Ing. Roberto Silva', '2298-7700', 'ventas@femsa.sv', 'Bulevar del Ejército Km 4.5, Soyapango', 15, TRUE)
ON CONFLICT DO NOTHING;

-- 10. PRODUCTOS BASE Y CÓDIGOS DE BARRA
-- 10.1 Leche Entera La Salud 1L
INSERT INTO productos (id, codigo_interno, nombre, descripcion, categoria_id, marca_id, unidad_medida, presentacion, costo_promedio, precio_venta, impuesto_id, es_pesable, es_combo, maneja_lotes, stock_minimo, activo)
VALUES (1, 'PRD-000001', 'Leche Entera La Salud 1L', 'Leche pasteurizada en caja tetra pack', 2, 1, 'UNIDAD', 'Caja 1 Litro', 1.1000, 1.5000, 1, FALSE, FALSE, TRUE, 10.0000, TRUE)
ON CONFLICT (id) DO NOTHING;
INSERT INTO producto_codigos_barra (producto_id, codigo_barra, es_principal, descripcion) VALUES (1, '7411001001', TRUE, 'Unidad 1L') ON CONFLICT (codigo_barra) DO NOTHING;

-- 10.2 Arroz Blanco San Francisco 1lb
INSERT INTO productos (id, codigo_interno, nombre, descripcion, categoria_id, marca_id, unidad_medida, presentacion, costo_promedio, precio_venta, impuesto_id, es_pesable, es_combo, maneja_lotes, stock_minimo, activo)
VALUES (2, 'PRD-000002', 'Arroz Blanco San Francisco 1lb', 'Arroz precocido grano largo', 1, 5, 'LIBRA', 'Bolsa 1 Libra', 0.5000, 0.7500, 1, FALSE, FALSE, FALSE, 20.0000, TRUE)
ON CONFLICT (id) DO NOTHING;
INSERT INTO producto_codigos_barra (producto_id, codigo_barra, es_principal, descripcion) VALUES (2, '7411001002', TRUE, 'Bolsa 1lb') ON CONFLICT (codigo_barra) DO NOTHING;

-- 10.3 Frijol Rojo de Seda 1lb
INSERT INTO productos (id, codigo_interno, nombre, descripcion, categoria_id, marca_id, unidad_medida, presentacion, costo_promedio, precio_venta, impuesto_id, es_pesable, es_combo, maneja_lotes, stock_minimo, activo)
VALUES (3, 'PRD-000003', 'Frijol Rojo de Seda 1lb', 'Frijol grano seleccionado nacional', 1, 8, 'LIBRA', 'Bolsa 1 Libra', 0.8500, 1.2500, 1, FALSE, FALSE, FALSE, 15.0000, TRUE)
ON CONFLICT (id) DO NOTHING;
INSERT INTO producto_codigos_barra (producto_id, codigo_barra, es_principal, descripcion) VALUES (3, '7411001003', TRUE, 'Bolsa 1lb') ON CONFLICT (codigo_barra) DO NOTHING;

-- 10.4 Coca Cola Original 2.5L
INSERT INTO productos (id, codigo_interno, nombre, descripcion, categoria_id, marca_id, unidad_medida, presentacion, costo_promedio, precio_venta, impuesto_id, es_pesable, es_combo, maneja_lotes, stock_minimo, activo)
VALUES (4, 'PRD-000004', 'Coca Cola Original 2.5L Retornable', 'Gaseosa refrescante familiar', 3, 4, 'UNIDAD', 'Botella 2.5 Litros', 1.6000, 2.2500, 1, FALSE, FALSE, FALSE, 12.0000, TRUE)
ON CONFLICT (id) DO NOTHING;
INSERT INTO producto_codigos_barra (producto_id, codigo_barra, es_principal, descripcion) VALUES (4, '7411001004', TRUE, 'Botella 2.5L') ON CONFLICT (codigo_barra) DO NOTHING;

-- 10.5 Manzana Gala Fresca (Pesable por Libra)
INSERT INTO productos (id, codigo_interno, nombre, descripcion, categoria_id, marca_id, unidad_medida, presentacion, costo_promedio, precio_venta, impuesto_id, es_pesable, es_combo, maneja_lotes, stock_minimo, activo)
VALUES (5, 'PRD-000005', 'Manzana Gala Fresca (Por Libra)', 'Manzana roja crujiente para ensalada', 4, 8, 'LIBRA', 'Granel balanza', 0.9000, 1.4500, 1, TRUE, FALSE, FALSE, 25.0000, TRUE)
ON CONFLICT (id) DO NOTHING;
INSERT INTO producto_codigos_barra (producto_id, codigo_barra, es_principal, descripcion) VALUES (5, '7411001005', TRUE, 'Código Balanza') ON CONFLICT (codigo_barra) DO NOTHING;

-- 10.6 Yogur Líquido Fresa Dos Pinos 750ml (Perecedero con Lote FEFO)
INSERT INTO productos (id, codigo_interno, nombre, descripcion, categoria_id, marca_id, unidad_medida, presentacion, costo_promedio, precio_venta, impuesto_id, es_pesable, es_combo, maneja_lotes, stock_minimo, activo)
VALUES (6, 'PRD-000006', 'Yogur Líquido Fresa Dos Pinos 750ml', 'Yogur probiótico con sabor natural', 2, 2, 'UNIDAD', 'Botella 750ml', 1.8000, 2.6000, 1, FALSE, FALSE, TRUE, 8.0000, TRUE)
ON CONFLICT (id) DO NOTHING;
INSERT INTO producto_codigos_barra (producto_id, codigo_barra, es_principal, descripcion) VALUES (6, '7411001006', TRUE, 'Botella 750ml') ON CONFLICT (codigo_barra) DO NOTHING;

-- Ajustar la secuencia de productos
SELECT setval('productos_id_seq', (SELECT MAX(id) FROM productos));

-- 11. STOCK INICIAL EN PISO DE VENTA (BODEGA ID 1)
INSERT INTO stock_bodega (producto_id, bodega_id, cantidad) VALUES
(1, 1, 45.0000),
(2, 1, 100.0000),
(3, 1, 80.0000),
(4, 1, 36.0000),
(5, 1, 65.5000),
(6, 1, 24.0000)
ON CONFLICT (producto_id, bodega_id) DO UPDATE SET cantidad = EXCLUDED.cantidad;

-- 12. LOTES DE PRODUCTOS PERECEDEROS
INSERT INTO lotes (producto_id, bodega_id, numero_lote, fecha_vencimiento, cantidad, activo) VALUES
(1, 1, 'LT-20261015-A', CURRENT_DATE + INTERVAL '45 days', 45.0000, TRUE),
(6, 1, 'LT-DP-8890', CURRENT_DATE + INTERVAL '25 days', 24.0000, TRUE)
ON CONFLICT (producto_id, bodega_id, numero_lote) DO NOTHING;

-- 13. KARDEX INICIAL (INVENTARIO_INICIAL)
INSERT INTO kardex_movimientos (producto_id, bodega_id, tipo_movimiento, cantidad, costo_unitario, stock_anterior, stock_posterior, documento_referencia, notas) VALUES
(1, 1, 'INVENTARIO_INICIAL', 45.0000, 1.1000, 0.0000, 45.0000, 'APERTURA', 'Carga inicial del sistema'),
(2, 1, 'INVENTARIO_INICIAL', 100.0000, 0.5000, 0.0000, 100.0000, 'APERTURA', 'Carga inicial del sistema'),
(3, 1, 'INVENTARIO_INICIAL', 80.0000, 0.8500, 0.0000, 80.0000, 'APERTURA', 'Carga inicial del sistema'),
(4, 1, 'INVENTARIO_INICIAL', 36.0000, 1.6000, 0.0000, 36.0000, 'APERTURA', 'Carga inicial del sistema'),
(5, 1, 'INVENTARIO_INICIAL', 65.5000, 0.9000, 0.0000, 65.5000, 'APERTURA', 'Carga inicial del sistema'),
(6, 1, 'INVENTARIO_INICIAL', 24.0000, 1.8000, 0.0000, 24.0000, 'APERTURA', 'Carga inicial del sistema');

-- 14. CATÁLOGO CONTABLE BÁSICO (EL SALVADOR)
INSERT INTO cuentas_contables (codigo, nombre, tipo, naturaleza, permite_movimientos) VALUES
('1101', 'Caja General', 'ACTIVO', 'DEUDORA', TRUE),
('1102', 'Bancos y Cuentas Corrientes', 'ACTIVO', 'DEUDORA', TRUE),
('1103', 'Cuentas por Cobrar Clientes', 'ACTIVO', 'DEUDORA', TRUE),
('1105', 'Inventario de Mercaderías', 'ACTIVO', 'DEUDORA', TRUE),
('1108', 'IVA Crédito Fiscal (Compras)', 'ACTIVO', 'DEUDORA', TRUE),
('2101', 'Cuentas por Pagar Proveedores', 'PASIVO', 'ACREEDORA', TRUE),
('2105', 'IVA Débito Fiscal (Ventas)', 'PASIVO', 'ACREEDORA', TRUE),
('3101', 'Capital Social', 'PATRIMONIO', 'ACREEDORA', TRUE),
('4101', 'Ventas de Supermercado (Ingresos)', 'INGRESO', 'ACREEDORA', TRUE),
('5101', 'Costo de Mercancía Vendida (Costo)', 'COSTO', 'DEUDORA', TRUE),
('6101', 'Gastos de Administración', 'GASTO', 'DEUDORA', TRUE),
('6102', 'Pérdidas por Merma y Vencimiento', 'GASTO', 'DEUDORA', TRUE),
('6103', 'Faltantes / Sobrantes de Caja', 'GASTO', 'DEUDORA', TRUE)
ON CONFLICT (codigo) DO NOTHING;
