<?php
/* =========================================================
   ACTUALIZADOR — pasa una base de la versión 1 a la versión 2
   (contingentes + platos + menú de 10 días) sin perder datos.
   Se puede ejecutar más de una vez sin problema.
   ========================================================= */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/menu_inicial.php';
require __DIR__ . '/inc/esquema.php';
require __DIR__ . '/inc/recetas_iniciales.php';
require_admin();
$pdo = db();

function version_actual(PDO $pdo): int
{
    try {
        return (int)$pdo->query("SELECT valor FROM sistema WHERE clave = 'version'")->fetchColumn() ?: 1;
    } catch (PDOException $ex) {
        return 1;
    }
}

function tabla_existe(PDO $pdo, string $t): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $st->execute([$t]);
    return (int)$st->fetchColumn() > 0;
}

function columna_existe(PDO $pdo, string $t, string $c): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$t, $c]);
    return (int)$st->fetchColumn() > 0;
}

$version = version_actual($pdo);
$faltante = estructura_faltante($pdo);
$necesita = $version < SCHEMA_VERSION || $faltante;
$log = [];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $necesita) {
    csrf_check();
    try {
        // 1) Tablas nuevas (CREATE IF NOT EXISTS no toca las existentes)
        crear_tablas($pdo);
        $log[] = 'Tablas verificadas y creadas las que faltaban.';

        // 2) Columnas nuevas en servicios
        if (!columna_existe($pdo, 'servicios', 'servicio')) {
            $pdo->exec('ALTER TABLE servicios ADD COLUMN servicio VARCHAR(20) NULL AFTER fecha');
        }
        if (!columna_existe($pdo, 'servicios', 'dia_menu')) {
            $pdo->exec('ALTER TABLE servicios ADD COLUMN dia_menu TINYINT NULL AFTER servicio');
        }
        $pdo->exec('ALTER TABLE servicios MODIFY menu_nombre VARCHAR(255) NOT NULL');
        $log[] = 'Historial de servicios adaptado (se conserva todo lo registrado).';

        // Versión 3: comida de llegada/salida y recetas en cualquier unidad
        if (!columna_existe($pdo, 'contingentes', 'comida_llegada')) {
            $pdo->exec("ALTER TABLE contingentes ADD COLUMN comida_llegada VARCHAR(20) NOT NULL DEFAULT 'Desayuno' AFTER personas");
        }
        if (!columna_existe($pdo, 'contingentes', 'comida_salida')) {
            $pdo->exec("ALTER TABLE contingentes ADD COLUMN comida_salida VARCHAR(20) NOT NULL DEFAULT 'Cena' AFTER comida_llegada");
        }
        $pdo->exec('ALTER TABLE plato_items MODIFY cantidad_por_persona DECIMAL(14,6) NOT NULL');
        if (!columna_existe($pdo, 'productos', 'peso_unidad')) {
            $pdo->exec('ALTER TABLE productos ADD COLUMN peso_unidad DECIMAL(12,3) NULL AFTER costo_promedio');
        }
        if (!columna_existe($pdo, 'plato_items', 'cantidad_receta')) {
            $pdo->exec('ALTER TABLE plato_items ADD COLUMN cantidad_receta DECIMAL(14,4) NULL AFTER cantidad_por_persona');
        }
        if (!columna_existe($pdo, 'plato_items', 'unidad_receta')) {
            $pdo->exec('ALTER TABLE plato_items ADD COLUMN unidad_receta VARCHAR(20) NULL AFTER cantidad_receta');
        }
        $pdo->exec('UPDATE plato_items pi JOIN productos p ON p.id = pi.producto_id
                    SET pi.cantidad_receta = pi.cantidad_por_persona, pi.unidad_receta = p.unidad
                    WHERE pi.unidad_receta IS NULL');
        $log[] = 'Contingentes con comida de llegada y salida; recetas en g, cc, cucharadas, etc.';

        // 3) Los menús viejos pasan a ser platos, con sus recetas
        $pdo->beginTransaction();
        if (tabla_existe($pdo, 'menus') && (int)$pdo->query('SELECT COUNT(*) FROM platos')->fetchColumn() === 0) {
            $viejos = $pdo->query('SELECT * FROM menus')->fetchAll();
            $insP = $pdo->prepare('INSERT INTO platos (nombre, categoria, descripcion, activo) VALUES (?,?,?,?)');
            $insI = $pdo->prepare('INSERT IGNORE INTO plato_items (plato_id, producto_id, cantidad_por_persona) SELECT ?, producto_id, cantidad_por_persona FROM menu_items WHERE menu_id = ?');
            foreach ($viejos as $m) {
                $insP->execute([$m['nombre'], 'Otro', 'Importado del sistema anterior', $m['activo']]);
                $insI->execute([(int)$pdo->lastInsertId(), $m['id']]);
            }
            if ($viejos) $log[] = count($viejos) . ' menú(s) anteriores pasaron a «Platos» con sus recetas (categoría «Otro»).';
        }

        // 4) Menú 2025/2026
        if ((int)$pdo->query('SELECT COUNT(*) FROM menu_ciclo')->fetchColumn() === 0) {
            $n = cargar_menu_inicial($pdo);
            $log[] = 'Menú 2025/2026 cargado: 10 días, almuerzo y cena (' . $n . ' platos nuevos).';
        }

        // 5) Recetas de las fichas de cocina (no pisa recetas ya cargadas)
        $r = cargar_recetas_iniciales($pdo);
        if ($r['recetas']) $log[] = 'Recetas cargadas (' . count($r['recetas']) . '): ' . implode(', ', $r['recetas']) . '.';
        if ($r['productos_nuevos']) $log[] = 'Productos nuevos creados con stock 0 (' . count($r['productos_nuevos']) . '): ' . implode(', ', $r['productos_nuevos']) . '.';
        if ($r['salteadas']) $log[] = 'Ya tenían receta, no se tocaron: ' . implode(', ', $r['salteadas']) . '.';
        foreach ($r['pendientes'] as $pe) $log[] = '⚠ ' . $pe;

        // Recetas importadas en este mismo paso: unidad = la del producto
        $pdo->exec('UPDATE plato_items pi JOIN productos p ON p.id = pi.producto_id
                    SET pi.cantidad_receta = pi.cantidad_por_persona, pi.unidad_receta = p.unidad
                    WHERE pi.unidad_receta IS NULL');

        // Control final: si falta algo, no se marca como actualizado
        $faltante = estructura_faltante($pdo);
        if ($faltante) throw new RuntimeException('La base quedó incompleta (' . implode(', ', $faltante) . ').');

        $pdo->prepare("REPLACE INTO sistema (clave, valor) VALUES ('version', ?)")->execute([SCHEMA_VERSION]);
        $pdo->commit();
        $_SESSION['schema'] = SCHEMA_VERSION;
        $version = SCHEMA_VERSION;
        $necesita = false;
        $log[] = 'Listo. Versión de la base: ' . SCHEMA_VERSION . '.';
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = 'Error al actualizar: ' . $ex->getMessage();
    }
}

$titulo = 'Actualizar sistema';
require __DIR__ . '/inc/header.php';
?>
<div class="card login" style="max-width:560px">
    <h1>Actualizar sistema</h1>
    <?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
    <?php foreach ($log as $l): ?><div class="flash"><?= e($l) ?></div><?php endforeach; ?>

    <?php if (!$necesita): ?>
        <p>La base de datos está al día. Por seguridad podés borrar <strong>actualizar.php</strong> del servidor.</p>
        <p><a class="btn" href="index.php">Ir al inicio</a></p>
    <?php else: ?>
        <?php if ($faltante && $version >= SCHEMA_VERSION): ?>
            <div class="flash warn">A la base le falta: <?= e(implode(', ', $faltante)) ?>. Tocá «Actualizar ahora» para repararla (no se borra nada).</div>
        <?php endif; ?>
        <p>Hay una versión nueva del sistema. Esta actualización:</p>
        <ul>
            <li>Al <strong>confirmar un contingente</strong> se descuenta solo del stock y la <strong>lista de compras</strong> queda guardada en su ficha.</li>
            <li>Agrega <strong>comida de llegada y de salida</strong> a los contingentes y el <strong>plan de menú</strong> de cada estadía con el cálculo de materia prima.</li>
            <li>Permite cargar las recetas en <strong>cualquier unidad</strong> (g, kg, cc, ml, l, cucharadas, unidades, docenas).</li>
            <li>Carga las <strong>recetas de las fichas de cocina</strong> (16 platos) y crea los productos que falten, con stock 0.</li>
            <li>Si venís de la primera versión: agrega contingentes, platos con receta y el menú 2025/2026 de 10 días.</li>
            <li><strong>No borra nada:</strong> stock, compras, movimientos y servicios anteriores se conservan, y los menús que ya tenías pasan a ser platos.</li>
        </ul>
        <form method="post" data-once>
            <?= csrf_field() ?>
            <button class="btn">Actualizar ahora</button>
        </form>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/inc/footer.php'; ?>
