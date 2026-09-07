-- ============================================
-- NATURALITOS ACUARIO - Base de Datos MySQL
-- Sistema de Gestión: Tickets, Facturas,
-- Inventario y Reportes de Ganancias
-- ============================================

DROP DATABASE IF EXISTS naturalitos_movil_db;
CREATE DATABASE naturalitos_movil_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE naturalitos_movil_db;

-- ============================================
-- TABLA: usuarios
-- Usuarios del sistema con roles
-- ============================================
CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    rol ENUM('admin', 'vendedor', 'almacenista') NOT NULL DEFAULT 'vendedor',
    telefono VARCHAR(20) DEFAULT NULL,
    avatar_url VARCHAR(500) DEFAULT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    ultimo_acceso DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_usuarios_email (email),
    INDEX idx_usuarios_rol (rol),
    INDEX idx_usuarios_activo (activo)
) ENGINE=InnoDB;

-- ============================================
-- TABLA: categorias
-- Categorías de productos del acuario
-- ============================================
CREATE TABLE IF NOT EXISTS categorias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL UNIQUE,
    descripcion TEXT DEFAULT NULL,
    icono VARCHAR(50) DEFAULT 'category',
    color VARCHAR(7) DEFAULT '#1F3F98',
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================
-- TABLA: productos
-- Catálogo completo del acuario
-- ============================================
CREATE TABLE IF NOT EXISTS productos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sku VARCHAR(50) NOT NULL UNIQUE,
    nombre VARCHAR(200) NOT NULL,
    descripcion TEXT DEFAULT NULL,
    categoria_id INT DEFAULT NULL,
    precio_compra DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    precio_venta DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    stock_actual DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    stock_minimo DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    unidad VARCHAR(20) NOT NULL DEFAULT 'pieza',
    imagen_url VARCHAR(500) DEFAULT NULL,
    codigo_barras VARCHAR(100) DEFAULT NULL,
    es_producto_vivo TINYINT(1) NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_productos_categoria (categoria_id),
    INDEX idx_productos_sku (sku),
    INDEX idx_productos_nombre (nombre),
    INDEX idx_productos_activo (activo),
    INDEX idx_productos_stock (stock_actual, stock_minimo),
    UNIQUE INDEX idx_productos_barras (codigo_barras),
    CONSTRAINT fk_productos_categoria
        FOREIGN KEY (categoria_id) REFERENCES categorias(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ============================================
-- TABLA: tickets_venta
-- Cabecera de tickets de venta
-- ============================================
CREATE TABLE IF NOT EXISTS tickets_venta (
    id INT AUTO_INCREMENT PRIMARY KEY,
    folio VARCHAR(20) NOT NULL UNIQUE,
    fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    usuario_id INT NOT NULL,
    cliente_nombre VARCHAR(200) DEFAULT 'Público General',
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    descuento_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    impuesto DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    metodo_pago ENUM('efectivo', 'tarjeta', 'transferencia', 'mixto') NOT NULL DEFAULT 'efectivo',
    estado ENUM('completado', 'cancelado', 'pendiente') NOT NULL DEFAULT 'completado',
    notas TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tickets_fecha (fecha),
    INDEX idx_tickets_usuario (usuario_id),
    INDEX idx_tickets_estado (estado),
    INDEX idx_tickets_folio (folio),
    CONSTRAINT fk_tickets_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ============================================
-- TABLA: ticket_detalle
-- Líneas / items de cada ticket
-- ============================================
CREATE TABLE IF NOT EXISTS ticket_detalle (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    producto_id INT NOT NULL,
    cantidad DECIMAL(12,3) NOT NULL DEFAULT 1.000,
    precio_unitario DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    descuento DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_detalle_ticket (ticket_id),
    INDEX idx_detalle_producto (producto_id),
    CONSTRAINT fk_detalle_ticket
        FOREIGN KEY (ticket_id) REFERENCES tickets_venta(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_detalle_producto
        FOREIGN KEY (producto_id) REFERENCES productos(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ============================================
-- TABLA: facturas_compra
-- Facturas de compra a proveedores
-- ============================================
CREATE TABLE IF NOT EXISTS facturas_compra (
    id INT AUTO_INCREMENT PRIMARY KEY,
    folio_proveedor VARCHAR(50) NOT NULL,
    proveedor_nombre VARCHAR(200) NOT NULL,
    proveedor_rfc VARCHAR(20) DEFAULT NULL,
    proveedor_telefono VARCHAR(20) DEFAULT NULL,
    fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_recepcion DATETIME DEFAULT NULL,
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    iva DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    estado ENUM('recibida', 'pendiente', 'cancelada') NOT NULL DEFAULT 'pendiente',
    notas TEXT DEFAULT NULL,
    usuario_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_facturas_fecha (fecha),
    INDEX idx_facturas_proveedor (proveedor_nombre),
    INDEX idx_facturas_estado (estado),
    INDEX idx_facturas_usuario (usuario_id),
    CONSTRAINT fk_facturas_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ============================================
-- TABLA: factura_detalle
-- Líneas de cada factura de compra
-- ============================================
CREATE TABLE IF NOT EXISTS factura_detalle (
    id INT AUTO_INCREMENT PRIMARY KEY,
    factura_id INT NOT NULL,
    producto_id INT NOT NULL,
    cantidad DECIMAL(12,3) NOT NULL DEFAULT 1.000,
    costo_unitario DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_fdetalle_factura (factura_id),
    INDEX idx_fdetalle_producto (producto_id),
    CONSTRAINT fk_fdetalle_factura
        FOREIGN KEY (factura_id) REFERENCES facturas_compra(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_fdetalle_producto
        FOREIGN KEY (producto_id) REFERENCES productos(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ============================================
-- TABLA: movimientos_inventario
-- Ledger de entradas/salidas de inventario
-- ============================================
CREATE TABLE IF NOT EXISTS movimientos_inventario (
    id INT AUTO_INCREMENT PRIMARY KEY,
    producto_id INT NOT NULL,
    tipo ENUM('entrada', 'salida', 'ajuste', 'devolucion') NOT NULL,
    cantidad DECIMAL(12,3) NOT NULL,
    stock_anterior DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    stock_nuevo DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    referencia_tipo ENUM('ticket', 'factura', 'ajuste_manual', 'devolucion') DEFAULT NULL,
    referencia_id INT DEFAULT NULL,
    motivo VARCHAR(500) DEFAULT NULL,
    usuario_id INT NOT NULL,
    fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mov_producto (producto_id),
    INDEX idx_mov_tipo (tipo),
    INDEX idx_mov_fecha (fecha),
    INDEX idx_mov_referencia (referencia_tipo, referencia_id),
    INDEX idx_mov_usuario (usuario_id),
    CONSTRAINT fk_mov_producto
        FOREIGN KEY (producto_id) REFERENCES productos(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_mov_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ============================================
-- TABLA: ingresos_gastos
-- Registro financiero de ingresos y gastos
-- ============================================
CREATE TABLE IF NOT EXISTS ingresos_gastos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo ENUM('ingreso', 'gasto') NOT NULL,
    concepto VARCHAR(300) NOT NULL,
    descripcion TEXT DEFAULT NULL,
    monto DECIMAL(12,2) NOT NULL,
    categoria VARCHAR(100) DEFAULT 'general',
    referencia_tipo ENUM('ticket', 'factura', 'manual') DEFAULT NULL,
    referencia_id INT DEFAULT NULL,
    fecha DATE NOT NULL,
    usuario_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ig_tipo (tipo),
    INDEX idx_ig_fecha (fecha),
    INDEX idx_ig_categoria (categoria),
    INDEX idx_ig_referencia (referencia_tipo, referencia_id),
    INDEX idx_ig_usuario (usuario_id),
    CONSTRAINT fk_ig_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ============================================
-- DATOS INICIALES
-- ============================================

-- Usuario admin por defecto (password: admin123)
INSERT INTO usuarios (nombre, email, password_hash, rol) VALUES
('Administrador', 'admin@naturalitos.com', '$2y$10$/VDWm659CdH5jBfPiUjiMOlcZCKzwU/ru3MzJAaz094EMxs2Gz6im', 'admin');

-- Categorías iniciales del acuario
INSERT INTO categorias (nombre, descripcion, icono, color) VALUES
('Peces Tropicales', 'Peces de agua dulce tropical', 'fish', '#FFF000'),
('Peces de Agua Fría', 'Peces de agua fría y templada', 'water', '#1F3F98'),
('Peces Marinos', 'Peces de agua salada', 'waves', '#99CC33'),
('Plantas Acuáticas', 'Plantas naturales para acuarios', 'leaf', '#99CC33'),
('Alimento', 'Alimento para peces y especies acuáticas', 'restaurant', '#FF00FF'),
('Accesorios', 'Filtros, bombas, calentadores y decoración', 'build', '#1F3F98'),
('Acuarios', 'Peceras y acuarios de distintos tamaños', 'aquarium', '#FFF000'),
('Medicamentos', 'Tratamientos y medicamentos para peces', 'medical', '#FF00FF'),
('Sustratos', 'Gravas, arenas y sustratos para acuario', 'layers', '#99CC33');

-- Productos del Catálogo Maestro del Acuario (50 productos)
INSERT INTO productos 
(sku, nombre, descripcion, categoria_id, precio_compra, precio_venta, stock_actual, stock_minimo, unidad, es_producto_vivo, activo) 
VALUES
-- Peces Tropicales (categoria_id = 1)
('PEZ-TRP-001', 'Guppy Macho Variedad Colores', 'Pez vivíparo pacífico de agua dulce tropical, colores surtidos.', 1, 12.00, 30.00, 60.000, 15.000, 'pieza', 1, 1),
('PEZ-TRP-002', 'Betta Macho Corona / Halfmoon', 'Pez combatiente de colores vivos en empaque individual.', 1, 35.00, 85.00, 20.000, 5.000, 'pieza', 1, 1),
('PEZ-TRP-003', 'Neon Tetra (Paracheirodon innesi)', 'Pez de cardumen brillante azul y rojo para acuario plantado.', 1, 7.00, 18.00, 120.000, 30.000, 'pieza', 1, 1),
('PEZ-TRP-004', 'Pez Ángel / Escalare Súper Yellow', 'Pez discoide clásico para acuarios medianos y grandes.', 1, 40.00, 95.00, 15.000, 5.000, 'pieza', 1, 1),
('PEZ-TRP-005', 'Molly Balón Surtido (Negro/Marmol)', 'Pez vivíparo pacífico de cuerpo redondeado.', 1, 15.00, 35.00, 40.000, 10.000, 'pieza', 1, 1),
('PEZ-TRP-006', 'Platy Surtido (Variatus/Mickey)', 'Pez muy resistente de vivos colores para principiantes.', 1, 14.00, 32.00, 40.000, 10.000, 'pieza', 1, 1),
('PEZ-TRP-007', 'Coridora Bronce (Pez Limpiador)', 'Pez gato de fondo pacífico limpiador de residuos.', 1, 18.00, 42.00, 30.000, 8.000, 'pieza', 1, 1),
('PEZ-TRP-008', 'Plecostomus Común 5-7cm', 'Pez ventosa consumidor de algas para acuarios comunitarios.', 1, 25.00, 60.00, 20.000, 5.000, 'pieza', 1, 1),
('PEZ-TRP-009', 'Barbo Tigre (Puntius tetrazona)', 'Pez hiperactivo de franjas negras para cardúmenes.', 1, 16.00, 38.00, 35.000, 10.000, 'pieza', 1, 1),
('PEZ-TRP-010', 'Cíclido Africano Labidochromis Yellow', 'Cíclido amarillo brillante del lago Malawi.', 1, 50.00, 120.00, 15.000, 4.000, 'pieza', 1, 1),

-- Peces Agua Fría (categoria_id = 2)
('PEZ-FRIA-001', 'Goldfish Oranda Cabeza de León', 'Pez de agua fría con capuchón desarrollado.', 2, 45.00, 110.00, 25.000, 6.000, 'pieza', 1, 1),
('PEZ-FRIA-002', 'Goldfish Común / Cometa Naranja', 'Variedad muy resistente ideal para estanques o peceras.', 2, 10.00, 25.00, 80.000, 20.000, 'pieza', 1, 1),
('PEZ-FRIA-003', 'Goldfish Telescopio Negro (Black Moor)', 'Pez de ojos saltones de color negro terciopelo.', 2, 38.00, 90.00, 20.000, 5.000, 'pieza', 1, 1),
('PEZ-FRIA-004', 'Pez Carpa Koi Selección 8-10cm', 'Carpa japonesa de estanque con hermosos patrones.', 2, 65.00, 150.00, 15.000, 4.000, 'pieza', 1, 1),
('PEZ-FRIA-005', 'Danio Cebra (Brachydanio rerio)', 'Pez de agua templada súper veloz y resistente.', 2, 6.00, 15.00, 100.000, 25.000, 'pieza', 1, 1),

-- Peces Marinos (categoria_id = 3)
('PEZ-MAR-001', 'Pez Payaso Ocellaris (Amphiprion)', 'Pez marino simbiótico con anémonas.', 3, 220.00, 480.00, 8.000, 2.000, 'pieza', 1, 1),
('PEZ-MAR-002', 'Cirujano Azul (Paracanthurus hepatus)', 'Pez marino arrecifal azul intenso.', 3, 650.00, 1350.00, 4.000, 1.000, 'pieza', 1, 1),
('PEZ-MAR-003', 'Camarón Limpiador Lysmata amboinensis', 'Invertebrado marino limpiador de parásitos.', 3, 280.00, 580.00, 6.000, 2.000, 'pieza', 1, 1),
('PEZ-MAR-004', 'Caracol Turbo Limpiador de Alga', 'Invertebrado marino devorador de algas en vidrios.', 3, 35.00, 80.00, 25.000, 8.000, 'pieza', 1, 1),

-- Plantas Acuáticas (categoria_id = 4)
('PLT-NAT-001', 'Elodea Densa (Manojo Acuático)', 'Planta oxigenadora de rápido crecimiento.', 4, 12.00, 30.00, 30.000, 10.000, 'manojo', 1, 1),
('PLT-NAT-002', 'Anubias Nana sobre Roca/Tronco', 'Planta de hoja dura ideal para poca luz.', 4, 65.00, 145.00, 12.000, 4.000, 'pieza', 1, 1),
('PLT-NAT-003', 'Espada Amazónica (Echinodorus)', 'Planta central de hojas anchas para fondos.', 4, 30.00, 75.00, 20.000, 5.000, 'pieza', 1, 1),
('PLT-NAT-004', 'Musgo de Java (Porción 5x5cm)', 'Musgo acuático para tapizar troncos y refugio de alevines.', 4, 25.00, 60.00, 15.000, 5.000, 'porcion', 1, 1),
('PLT-NAT-005', 'Vallisneria Spiralis (Manojo)', 'Planta de fondo con hojas acintadas.', 4, 15.00, 38.00, 25.000, 8.000, 'manojo', 1, 1),

-- Alimento (categoria_id = 5)
('ALM-NUT-001', 'TetraMin Flakes Hojuelas 52g', 'Alimento completo en hojuelas para peces tropicales.', 5, 65.00, 110.00, 30.000, 10.000, 'pieza', 0, 1),
('ALM-NUT-002', 'Wardley Pellet Goldfish 120g', 'Pellets flotantes de alta digestibilidad para goldfish.', 5, 45.00, 78.00, 25.000, 8.000, 'pieza', 0, 1),
('ALM-NUT-003', 'Hikari Betta Bio-Gold 20g', 'Pellets premium acentuadores de color para Bettas.', 5, 55.00, 95.00, 20.000, 6.000, 'pieza', 0, 1),
('ALM-NUT-004', 'Biomaa Alimento en Pastillas Fondo', 'Pastillas hundibles para coridoras y plecos.', 5, 35.00, 60.00, 25.000, 8.000, 'pieza', 0, 1),
('ALM-NUT-005', 'Artemia Liofilizada / Tubifex 10g', 'Golosina de proteína animal liofilizada.', 5, 40.00, 72.00, 18.000, 5.000, 'pieza', 0, 1),
('ALM-NUT-006', 'Ocean Nutrition Hojuela Marina 34g', 'Alimento enriquecido con ajo para peces marinos.', 5, 120.00, 198.00, 12.000, 4.000, 'pieza', 0, 1),

-- Accesorios y Equipos (categoria_id = 6)
('ACC-FLT-001', 'Filtro de Cascada (Hang-On) 300L/H', 'Filtro mochila silencioso con cartucho de carbón.', 6, 160.00, 275.00, 12.000, 4.000, 'pieza', 0, 1),
('ACC-FLT-002', 'Filtro Interno Sumergible 450L/H', 'Filtro compacto con esponja biológica y aireador.', 6, 110.00, 190.00, 15.000, 5.000, 'pieza', 0, 1),
('ACC-FLT-003', 'Calentador Automático Cristal 100W', 'Termostato ajustable sumergible para acuarios de 40-100L.', 6, 145.00, 240.00, 10.000, 3.000, 'pieza', 0, 1),
('ACC-FLT-004', 'Calentador Automático Acero 200W', 'Termostato inastillable de acero inoxidable.', 6, 210.00, 350.00, 8.000, 3.000, 'pieza', 0, 1),
('ACC-AIR-001', 'Bomba de Aire 2 Salidas Silenciosa', 'Compresor de aire electromagnético de bajo consumo.', 6, 95.00, 165.00, 15.000, 5.000, 'pieza', 0, 1),
('ACC-AIR-002', 'Piedra Difusora de Aire 10cm', 'Barra difusora de microburbujas de aire.', 6, 12.00, 25.00, 40.000, 10.000, 'pieza', 0, 1),
('ACC-AIR-003', 'Manguera de Silicon para Aire 2m', 'Tubo flexible atóxico transparente.', 6, 10.00, 22.00, 50.000, 15.000, 'pieza', 0, 1),

-- Acondicionadores y Medicamentos (categoria_id = 8)
('QUI-MED-001', 'Anticloro Acondicionador Pentamed 125ml', 'Neutralizador instantáneo de cloro y pesados.', 8, 25.00, 48.00, 40.000, 12.000, 'pieza', 0, 1),
('QUI-MED-002', 'Azul de Metileno Desinfectante 125ml', 'Prevención y tratamiento contra hongos e infecciones.', 8, 22.00, 42.00, 35.000, 10.000, 'pieza', 0, 1),
('QUI-MED-003', 'Seachem Prime Acondicionador 100ml', 'Eliminador concentrado de amonio, nitritos y nitratos.', 8, 140.00, 230.00, 15.000, 5.000, 'pieza', 0, 1),
('QUI-MED-004', 'Verde de Malaquita Verde-Med 60ml', 'Tratamiento efectivo contra el parásito del Ich.', 8, 20.00, 38.00, 25.000, 8.000, 'pieza', 0, 1),
('QUI-MED-005', 'Bacteria Nitrificante Biomaa 125ml', 'Cultivo bacteriano activo para maduración del filtro.', 8, 45.00, 85.00, 20.000, 6.000, 'pieza', 0, 1),
('QUI-MED-006', 'Kit de Tests pH / Amonio / Nitritos', 'Gota a gota para control de parámetros de agua.', 8, 210.00, 340.00, 10.000, 3.000, 'kit', 0, 1),

-- Sustratos y Decoración (categoria_id = 9)
('SUS-DEC-001', 'Grava de Río Natural Fina 5kg', 'Grava inerte lavada ideal para acuario dulce.', 9, 35.00, 68.00, 20.000, 6.000, 'bolsa', 0, 1),
('SUS-DEC-002', 'Sustrato Nutritivo Plantado 4kg', 'Sustrato fértil para acuarios holandeses y biotopos.', 9, 230.00, 380.00, 10.000, 3.000, 'bolsa', 0, 1),
('SUS-DEC-003', 'Sal Marina Sintética Instant Ocean 2kg', 'Mezcla para preparar agua salada sintética.', 9, 160.00, 260.00, 12.000, 4.000, 'bolsa', 0, 1),
('SUS-DEC-004', 'Adorno Barco Hundido Resina 20cm', 'Decoración de resina poliéster atóxica con orificios.', 9, 95.00, 175.00, 8.000, 3.000, 'pieza', 0, 1),

-- Acuarios y Kits (categoria_id = 7)
('ACU-KIT-001', 'Acuario Rectangular 40 Litros Nudo', 'Tanque de cristal flotado 50x25x30cm sellado con silicona.', 7, 280.00, 490.00, 6.000, 2.000, 'pieza', 0, 1),
('ACU-KIT-002', 'Kit Acuario Bettera Cristal 5L con Luz LED', 'Pecera completa de escritorio para pez Betta.', 7, 190.00, 340.00, 10.000, 3.000, 'kit', 0, 1),
('ACU-KIT-003', 'Kit Acuario Completo 80L + Luz + Filtro', 'Acuario panorámico listo para poblar con accesorios.', 7, 850.00, 1450.00, 4.000, 1.000, 'kit', 0, 1);
