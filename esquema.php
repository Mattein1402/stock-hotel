<?php
/**
 * Estructura de la base de datos (la misma que database.sql).
 * Está en PHP para no depender de que el hosting acepte archivos .sql.
 */
function esquema_sql(): array
{
    return [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS usuarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  usuario VARCHAR(50) NOT NULL UNIQUE,
  pass_hash VARCHAR(255) NOT NULL,
  rol ENUM('admin','cocina') NOT NULL DEFAULT 'cocina',
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS productos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(120) NOT NULL,
  categoria VARCHAR(60) NOT NULL DEFAULT '',
  unidad VARCHAR(20) NOT NULL DEFAULT 'unidad',
  stock DECIMAL(14,3) NOT NULL DEFAULT 0,
  stock_minimo DECIMAL(14,3) NOT NULL DEFAULT 0,
  costo_promedio DECIMAL(14,2) NOT NULL DEFAULT 0,
  peso_unidad DECIMAL(12,3) NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS compra_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  compra_id INT NOT NULL,
  producto_id INT NOT NULL,
  cantidad DECIMAL(14,3) NOT NULL,
  costo_unitario DECIMAL(14,2) NOT NULL DEFAULT 0,
  FOREIGN KEY (compra_id) REFERENCES compras(id) ON DELETE CASCADE,
  FOREIGN KEY (producto_id) REFERENCES productos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS sistema (
  clave VARCHAR(40) PRIMARY KEY,
  valor VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS contingentes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  numero VARCHAR(30) NOT NULL,
  fecha_llegada DATE NOT NULL,
  dias INT NOT NULL,
  fecha_salida DATE NOT NULL,
  personas INT NOT NULL,
  comida_llegada VARCHAR(20) NOT NULL DEFAULT 'Desayuno',
  comida_salida VARCHAR(20) NOT NULL DEFAULT 'Cena',
  observaciones VARCHAR(255) NOT NULL DEFAULT '',
  cancelado TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX (fecha_llegada),
  INDEX (fecha_salida),
  INDEX (numero)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS platos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(150) NOT NULL,
  categoria VARCHAR(30) NOT NULL DEFAULT 'Principal',
  descripcion VARCHAR(255) NOT NULL DEFAULT '',
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS plato_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  plato_id INT NOT NULL,
  producto_id INT NOT NULL,
  cantidad_por_persona DECIMAL(14,6) NOT NULL,
  cantidad_receta DECIMAL(14,4) NULL,
  unidad_receta VARCHAR(20) NULL,
  UNIQUE KEY plato_producto (plato_id, producto_id),
  FOREIGN KEY (plato_id) REFERENCES platos(id) ON DELETE CASCADE,
  FOREIGN KEY (producto_id) REFERENCES productos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS menu_ciclo (
  id INT AUTO_INCREMENT PRIMARY KEY,
  dia TINYINT NOT NULL,
  servicio VARCHAR(20) NOT NULL,
  plato_id INT NOT NULL,
  orden INT NOT NULL DEFAULT 0,
  UNIQUE KEY dia_servicio_plato (dia, servicio, plato_id),
  FOREIGN KEY (plato_id) REFERENCES platos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS contingente_menu (
  id INT AUTO_INCREMENT PRIMARY KEY,
  contingente_id INT NOT NULL,
  fecha DATE NOT NULL,
  servicio VARCHAR(20) NOT NULL,
  dia_menu TINYINT NOT NULL,
  UNIQUE KEY cont_fecha_servicio (contingente_id, fecha, servicio),
  FOREIGN KEY (contingente_id) REFERENCES contingentes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS contingente_compras (
  id INT AUTO_INCREMENT PRIMARY KEY,
  contingente_id INT NOT NULL,
  producto_id INT NOT NULL,
  necesario DECIMAL(14,3) NOT NULL,
  stock_antes DECIMAL(14,3) NOT NULL,
  comprar DECIMAL(14,3) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (contingente_id) REFERENCES contingentes(id) ON DELETE CASCADE,
  FOREIGN KEY (producto_id) REFERENCES productos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS servicios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  fecha DATE NOT NULL,
  servicio VARCHAR(20) NULL,
  dia_menu TINYINT NULL,
  menu_nombre VARCHAR(255) NOT NULL,
  comensales INT NOT NULL,
  costo_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  observaciones VARCHAR(255) NOT NULL DEFAULT '',
  usuario_id INT NULL,
  anulado TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS servicio_contingentes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  servicio_id INT NOT NULL,
  contingente_id INT NOT NULL,
  personas INT NOT NULL,
  FOREIGN KEY (servicio_id) REFERENCES servicios(id) ON DELETE CASCADE,
  FOREIGN KEY (contingente_id) REFERENCES contingentes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS servicio_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  servicio_id INT NOT NULL,
  producto_id INT NOT NULL,
  cantidad DECIMAL(14,3) NOT NULL,
  costo_unitario DECIMAL(14,2) NOT NULL DEFAULT 0,
  FOREIGN KEY (servicio_id) REFERENCES servicios(id) ON DELETE CASCADE,
  FOREIGN KEY (producto_id) REFERENCES productos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ];
}

/** Crea las tablas que falten (no toca las que ya existen) */
function crear_tablas(PDO $pdo): void
{
    foreach (esquema_sql() as $q) $pdo->exec($q);
}

/** Tablas que el sistema necesita */
function tablas_requeridas(): array
{
    $t = [];
    foreach (esquema_sql() as $q) {
        if (preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/', $q, $m)) $t[] = $m[1];
    }
    return $t;
}

/** Tablas y columnas que faltan en la base (vacío = está todo) */
function estructura_faltante(PDO $pdo): array
{
    $falta = [];
    $existentes = $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
    $existentes = array_map('strtolower', $existentes);
    foreach (tablas_requeridas() as $t) {
        if (!in_array(strtolower($t), $existentes, true)) $falta[] = 'tabla ' . $t;
    }
    $columnas = [
        'contingentes' => ['comida_llegada', 'comida_salida'],
        'plato_items' => ['cantidad_receta', 'unidad_receta'],
        'productos' => ['peso_unidad'],
        'servicios' => ['servicio', 'dia_menu'],
    ];
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    foreach ($columnas as $t => $cols) {
        if (!in_array($t, $existentes, true)) continue;
        foreach ($cols as $c) {
            $st->execute([$t, $c]);
            if (!(int)$st->fetchColumn()) $falta[] = 'columna ' . $t . '.' . $c;
        }
    }
    return $falta;
}
