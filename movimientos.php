<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();
$pdo = db();

$f = [
    'producto' => (int)($_GET['producto'] ?? 0),
    'tipo' => in_array($_GET['tipo'] ?? '', ['compra', 'consumo', 'ajuste', 'anulacion'], true) ? $_GET['tipo'] : '',
    'desde' => valid_fecha($_GET['desde'] ?? '') ?: date('Y-m-01'),
    'hasta' => valid_fecha($_GET['hasta'] ?? '') ?: date('Y-m-d'),
];
$where = ['m.created_at >= ?', 'm.created_at < DATE_ADD(?, INTERVAL 1 DAY)'];
$params = [$f['desde'], $f['hasta']];
if ($f['producto']) { $where[] = 'm.producto_id = ?'; $params[] = $f['producto']; }
if ($f['tipo']) { $where[] = 'm.tipo = ?'; $params[] = $f['tipo']; }
$w = implode(' AND ', $where);

$st = $pdo->prepare("SELECT m.*, p.nombre, p.unidad, u.nombre AS usuario
    FROM movimientos m JOIN productos p ON p.id = m.producto_id LEFT JOIN usuarios u ON u.id = m.usuario_id
    WHERE $w ORDER BY m.id DESC LIMIT 1000");
$st->execute($params);
$movs = $st->fetchAll();

/* Exportar a Excel (CSV) */
if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="movimientos_' . $f['desde'] . '_' . $f['hasta'] . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Fecha', 'Producto', 'Tipo', 'Cantidad', 'Unidad', 'Stock resultante', 'Detalle', 'Usuario'], ';');
    foreach ($movs as $m) {
        fputcsv($out, [fecha_ar($m['created_at']), $m['nombre'], $m['tipo'], num($m['cantidad']), $m['unidad'], num($m['stock_resultante']), $m['nota'], $m['usuario']], ';');
    }
    exit;
}

/* Resumen por producto en el período */
$st = $pdo->prepare("SELECT p.nombre, p.unidad,
        SUM(CASE WHEN m.tipo = 'compra' THEN m.cantidad ELSE 0 END) AS comprado,
        SUM(CASE WHEN m.tipo = 'consumo' THEN -m.cantidad ELSE 0 END) AS consumido,
        SUM(CASE WHEN m.tipo IN ('ajuste','anulacion') THEN m.cantidad ELSE 0 END) AS ajustes
    FROM movimientos m JOIN productos p ON p.id = m.producto_id
    WHERE $w GROUP BY p.id ORDER BY consumido DESC, p.nombre");
$st->execute($params);
$resumen = $st->fetchAll();

$productos = $pdo->query('SELECT id, nombre FROM productos ORDER BY nombre')->fetchAll();

$titulo = 'Movimientos';
require __DIR__ . '/inc/header.php';
?>
<div class="titulo-acciones">
    <h1>Movimientos de stock</h1>
    <a class="btn sec" href="?<?= e(http_build_query($f + ['csv' => 1])) ?>">Exportar a Excel</a>
</div>
<form method="get" class="filtros card">
    <label>Desde<input type="date" name="desde" value="<?= e($f['desde']) ?>"></label>
    <label>Hasta<input type="date" name="hasta" value="<?= e($f['hasta']) ?>"></label>
    <select name="producto"><option value="">Todos los productos</option><?php foreach ($productos as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $f['producto'] === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['nombre']) ?></option><?php endforeach; ?></select>
    <select name="tipo">
        <option value="">Todos los tipos</option>
        <?php foreach (['compra' => 'Compras', 'consumo' => 'Consumos', 'ajuste' => 'Ajustes', 'anulacion' => 'Anulaciones'] as $k => $v): ?>
            <option value="<?= $k ?>" <?= $f['tipo'] === $k ? 'selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn sec">Filtrar</button>
</form>

<?php if ($resumen): ?>
<details class="card" <?= $f['producto'] ? '' : 'open' ?>>
    <summary><strong>Resumen del período por producto</strong></summary>
    <div class="scroll">
    <table class="tabla">
        <thead><tr><th>Producto</th><th class="num">Comprado</th><th class="num">Consumido en cocina</th><th class="num">Ajustes / bajas</th></tr></thead>
        <tbody>
        <?php foreach ($resumen as $r): ?>
            <tr><td><?= e($r['nombre']) ?></td><td class="num"><?= num($r['comprado']) ?> <?= e($r['unidad']) ?></td><td class="num"><strong><?= num($r['consumido']) ?></strong> <?= e($r['unidad']) ?></td><td class="num"><?= num($r['ajustes']) ?> <?= e($r['unidad']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</details>
<?php endif; ?>

<div class="card">
    <div class="scroll">
    <table class="tabla">
        <thead><tr><th>Fecha</th><th>Producto</th><th>Tipo</th><th class="num">Cantidad</th><th class="num">Stock después</th><th>Detalle</th><th>Usuario</th></tr></thead>
        <tbody>
        <?php foreach ($movs as $m):
            $link = $m['ref_tipo'] === 'compra' ? 'compras.php?ver=' . (int)$m['ref_id'] : ($m['ref_tipo'] === 'servicio' ? 'cocinar.php#s' . (int)$m['ref_id'] : ''); ?>
            <tr>
                <td class="nowrap"><?= fecha_ar($m['created_at']) ?></td>
                <td><?= e($m['nombre']) ?></td>
                <td><?= badge_tipo($m['tipo']) ?></td>
                <td class="num <?= $m['cantidad'] < 0 ? 'neg' : 'pos' ?>"><?= $m['cantidad'] > 0 ? '+' : '' ?><?= num($m['cantidad']) ?> <?= e($m['unidad']) ?></td>
                <td class="num"><?= num($m['stock_resultante']) ?></td>
                <td><?= $link ? '<a href="' . e($link) . '">' . e($m['nota']) . '</a>' : e($m['nota']) ?></td>
                <td><?= e($m['usuario'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$movs): ?><tr><td colspan="7" class="muted">Sin movimientos en el período.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
    <?php if (count($movs) === 1000): ?><p class="muted small">Se muestran los últimos 1000. Acotá las fechas o exportá.</p><?php endif; ?>
</div>
<?php require __DIR__ . '/inc/footer.php'; ?>
