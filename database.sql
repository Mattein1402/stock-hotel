-- Sistema de control de stock para hotel
-- install.php ejecuta este archivo automaticamente (tambien se puede importar a mano)

CREATE TABLE IF NOT EXISTS usuarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  usuario VARCHAR(50) NOT NULL UNIQUE,
  pass_hash VARCHAR(255) NOT NULL,
  rol ENUM('admin','cocina') NOT NULL DEFAULT 'cocina',
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS productos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(120) NOT NULL,
  categoria VARCHAR(60) NOT NULL DEFAULT '',
  unidad VARCHAR(20) NOT NULL DEFAULT 'unidad',
  stock DECIMAL(14,3) NOT NULL DEFAULT 0,
  stock_minimo DECIMAL(14,3) NOT NULL DEFAULT 0,
  costo_promedio DECIMAL(14,2) NOT NULL DEFAULT 0,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS compras (
  id INT AUTO_INCREMENT PRIMARY KEY,
  fecha DATE NOT NULL,
  proveedor VARCHAR(120) NOT NULL DEFAULT '',
  comprobante VARCHAR(60) NOT NULL DEFAULT '',
  total DECIMAL(14,2) NOT NULL DEFAULT 0,
  observaciones TEXT,
  usuario_id INT NULL,
  anulada TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS compra_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  compra_id INT NOT NULL,
  producto_id INT NOT NULL,
  cantidad DECIMAL(14,3) NOT NULL,
  costo_unitario DECIMAL(14,2) NOT NULL DEFAULT 0,
  FOREIGN KEY (compra_id) REFERENCES compras(id) ON DELETE CASCADE,
  FOREIGN KEY (producto_id) REFERENCES productos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS menus (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(120) NOT NULL,
  tipo VARCHAR(30) NOT NULL DEFAULT '',
  descripcion TEXT,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS menu_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  menu_id INT NOT NULL,
  producto_id INT NOT NULL,
  cantidad_por_persona DECIMAL(14,4) NOT NULL,
  UNIQUE KEY menu_producto (menu_id, producto_id),
  FOREIGN KEY (menu_id) REFERENCES menus(id) ON DELETE CASCADE,
  FOREIGN KEY (producto_id) REFERENCES productos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS servicios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  fecha DATE NOT NULL,
  menu_id INT NULL,
  menu_nombre VARCHAR(120) NOT NULL,
  comensales INT NOT NULL,
  costo_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  observaciones VARCHAR(255) NOT NULL DEFAULT '',
  usuario_id INT NULL,
  anulado TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX (fecha),
  FOREIGN KEY (menu_id) REFERENCES menus(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS servicio_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  servicio_id INT NOT NULL,
  producto_id INT NOT NULL,
  cantidad DECIMAL(14,3) NOT NULL,
  costo_unitario DECIMAL(14,2) NOT NULL DEFAULT 0,
  FOREIGN KEY (servicio_id) REFERENCES servicios(id) ON DELETE CASCADE,
  FOREIGN KEY (producto_id) REFERENCES productos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS movimientos (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  producto_id INT NOT NULL,
  tipo ENUM('compra','consumo','ajuste','anulacion') NOT NULL,
  cantidad DECIMAL(14,3) NOT NULL,
  stock_resultante DECIMAL(14,3) NOT NULL,
  ref_tipo VARCHAR(20) NULL,
  ref_id INT NULL,
  usuario_id INT NULL,
  nota VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX prod_fecha (producto_id, created_at),
  INDEX (created_at),
  FOREIGN KEY (producto_id) REFERENCES productos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
