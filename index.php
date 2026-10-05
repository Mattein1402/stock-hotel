<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();
$pdo = db();

$mes = date('Y-m-01');
$totProd = (int)$pdo->query('SELECT COUNT(*) FROM productos WHERE activo = 1')->fetchColumn();
$bajos = $pdo->query('SELECT * FROM productos WHERE activo = 1 AND stock <= stock_minimo ORDER BY (stock - stock_minimo), nombre')->fetchAll();
$valor = (float)$pdo->query('SELECT COALESCE(SUM(GREATEST(stock, 0) * costo_promedio), 0) FROM productos WHERE activo = 1')->fetchColumn();

$st = $pdo->prepare('SELECT COALESCE(SUM(total), 0) FROM compras WHERE anulada = 0 AND fecha >= ?');
$st->execute([$mes]);
$comprasMes = (float)$st->fetchColumn();

$st = $pdo->prepare('SELECT COALESCE(SUM(comensales), 0) AS pax, COALESCE(SUM(costo_total), 0) AS costo FROM servicios WHERE anulado = 0 AND fecha >= ?');
$st->execute([$mes]);
$serv = $st->fetch();

$st = $pdo->prepare('SELECT menu_nombre, comensales FROM servicios WHERE anulado = 0 AND fecha = ? ORDER BY id');
$st->execute([date('Y-m-d')]);
$hoy = $st->fetchAll();

$movs = $pdo->query('SELECT m.*, p.nombre, p.unidad FROM movimientos m JOIN productos p ON p.id = m.producto_id ORDER BY m.id DESC LIMIT 12')->fetchAll();

$titulo = 'Inicio';
require __DIR__ . '/inc/header.php';
?>
<div class="titulo-acciones">
    <h1>Hola, <?= e(usuario()['nombre']) ?></h1>
    <div>
        <a class="btn" href="cocinar.php">Cocinar / servir menú</a>
        <?php if (es_admin()): ?><a class="btn sec" href="compras.php">Cargar compra</a><?php endif; ?>
    </div>
</div>

<div class="stats">
    <div class="stat"><span>Productos activos</span><strong><?= $totProd ?></strong></div>
    <div class="stat <?= $bajos ? 'alerta' : '' ?>"><span>Bajo stock mínimo</span><strong><?= count($bajos) ?></strong></div>
    <div class="stat"><span>Valor del stock</span><strong><?= dinero($valor) ?></strong></div>
    <div class="stat"><span>Compras del mes</span><strong><?= dinero($comprasMes) ?></strong></div>
    <div class="stat"><span>Comensales del mes</span><strong><?= (int)$serv['pax'] ?></strong></div>
    <div class="stat"><span>Costo por comensal (mes)</span><strong><?= $serv['pax'] ? dinero($serv['costo'] / $serv['pax']) : '—' ?></strong></div>
</div>

<div class="grid-2">
    <section class="card">
        <h2>Para reponer</h2>
        <?php if (!$bajos): ?>
            <p class="muted">Todo el stock está por encima del mínimo. 👌</p>
        <?php else: ?>
            <table class="tabla">
                <thead><tr><th>Producto</th><th class="num">Stock</th><th class="num">Mínimo</th></tr></thead>
                <tbody>
                <?php foreach ($bajos as $p): ?>
                    <tr><td><?= e($p['nombre']) ?></td><td class="num"><span class="badge <?= $p['stock'] < 0 ? 'b-err' : 'b-warn' ?>"><?= num($p['stock']) ?> <?= e($p['unidad']) ?></span></td><td class="num"><?= num($p['stock_minimo']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>Servido hoy</h2>
        <?php if (!$hoy): ?>
            <p class="muted">Todavía no se registró nada hoy.</p>
        <?php else: ?>
            <ul class="lista">
                <?php foreach ($hoy as $h): ?><li><?= e($h['menu_nombre']) ?> <span class="muted">× <?= (int)$h['comensales'] ?> pax</span></li><?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <h2>Últimos movimientos</h2>
        <table class="tabla compacta">
            <tbody>
            <?php foreach ($movs as $m): ?>
                <tr><td class="nowrap muted small"><?= fecha_ar($m['created_at']) ?></td><td><?= e($m['nombre']) ?></td><td class="num <?= $m['cantidad'] < 0 ? 'neg' : 'pos' ?>"><?= $m['cantidad'] > 0 ? '+' : '' ?><?= num($m['cantidad']) ?> <?= e($m['unidad']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p><a href="movimientos.php">Ver todos →</a></p>
    </section>
</div>
<?php require __DIR__ . '/inc/footer.php'; ?>
