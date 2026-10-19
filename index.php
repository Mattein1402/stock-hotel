<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();
$pdo = db();

$hoy = date('Y-m-d');
$activos = contingentes_activos($pdo, $hoy);
$paxHoy = array_sum(array_column($activos, 'personas'));
[$diaMenu, $explicacion] = dia_menu_sugerido($activos);

$st = $pdo->prepare('SELECT * FROM contingentes WHERE cancelado = 0 AND fecha_llegada > ? ORDER BY fecha_llegada LIMIT 5');
$st->execute([$hoy]);
$proximos = $st->fetchAll();

// Qué comidas de hoy ya se registraron
$st = $pdo->prepare('SELECT servicio, SUM(comensales) AS pax FROM servicios WHERE anulado = 0 AND fecha = ? GROUP BY servicio');
$st->execute([$hoy]);
$hechas = $st->fetchAll(PDO::FETCH_KEY_PAIR);

// Comidas del día que tienen platos en el menú
$comidasHoy = [];
$paxComida = [];
foreach (SERVICIOS as $sv) {
    $act = contingentes_activos($pdo, $hoy, $sv);
    [$dSv] = dia_menu_sugerido($act);
    $pl = $act ? platos_del_menu($pdo, $dSv, $sv) : [];
    if ($pl) {
        $comidasHoy[$sv] = $pl;
        $paxComida[$sv] = array_sum(array_column($act, 'personas'));
    }
}

$bajos = $pdo->query('SELECT * FROM productos WHERE activo = 1 AND ((stock_minimo > 0 AND stock <= stock_minimo) OR stock < 0) ORDER BY (stock - stock_minimo), nombre')->fetchAll();
$mes = date('Y-m-01');
$st = $pdo->prepare('SELECT COALESCE(SUM(comensales), 0) AS pax, COALESCE(SUM(costo_total), 0) AS costo FROM servicios WHERE anulado = 0 AND fecha >= ?');
$st->execute([$mes]);
$serv = $st->fetch();
$valor = (float)$pdo->query('SELECT COALESCE(SUM(GREATEST(stock, 0) * costo_promedio), 0) FROM productos WHERE activo = 1')->fetchColumn();
$sinReceta = (int)$pdo->query('SELECT COUNT(DISTINCT p.id) FROM platos p JOIN menu_ciclo mc ON mc.plato_id = p.id WHERE NOT EXISTS (SELECT 1 FROM plato_items pi WHERE pi.plato_id = p.id)')->fetchColumn();

// Planificador de un contingente nuevo (desde el formulario del inicio)
$planificando = es_admin() && !empty($_GET['planificar']);
if ($planificando) {
    require_once __DIR__ . '/inc/planificador.php';
    $plan = plan_desde_datos($pdo, $_GET, null);
}

$titulo = 'Inicio';
require __DIR__ . '/inc/header.php';
?>
<?php if ($planificando): ?>
<div class="titulo-acciones no-print" id="plan">
    <h1>Nuevo contingente</h1>
    <a class="btn sec" href="index.php">Cancelar</a>
</div>
<?php render_planificador($pdo, $plan, 'index.php', ['planificar' => 1], 'index.php'); ?>
<?php else: ?>
<div>
<h1>Hoy, <?= fecha_ar($hoy) ?></h1>

<div class="stats">
    <div class="stat destacado"><span>Personas en el hotel</span><strong><?= $paxHoy ?></strong></div>
    <div class="stat"><span>Contingentes</span><strong><?= count($activos) ?></strong></div>
    <div class="stat"><span>Menú de hoy</span><strong><?= $comidasHoy ? 'Menú ' . $diaMenu : '—' ?></strong></div>
    <div class="stat <?= $bajos ? 'alerta' : '' ?>"><span>Productos para reponer</span><strong><?= count($bajos) ?></strong></div>
</div>

<?php if ($sinReceta && es_admin()): ?>
    <div class="flash warn"><?= $sinReceta ?> plato(s) del menú todavía no tienen receta y no van a descontar stock. <a href="platos.php?sin_receta=1">Cargar recetas</a></div>
<?php endif; ?>

<div class="grid-2">
    <section class="card">
        <h2>Contingentes en el hotel</h2>
        <?php if (!$activos): ?>
            <p class="muted">No hay contingentes en el hotel hoy.</p>
        <?php else: ?>
            <table class="tabla">
                <thead><tr><th>N°</th><th class="num">Personas</th><th>Estadía</th><th>Se va</th></tr></thead>
                <tbody>
                <?php foreach ($activos as $c): $de = (int)$c['dia_estadia']; ?>
                    <tr>
                        <td><a href="contingentes.php?ver=<?= (int)$c['id'] ?>"><strong><?= e($c['numero']) ?></strong></a></td>
                        <td class="num"><strong><?= (int)$c['personas'] ?></strong></td>
                        <td>Día <?= $de ?> de <?= (int)$c['dias'] ?><?= $de === 1 ? ' <span class="badge b-info">Llegó hoy</span>' : '' ?></td>
                        <td class="nowrap"><?= $de === (int)$c['dias'] ? '<span class="badge b-warn">Hoy</span>' : fecha_ar($c['fecha_salida']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php if ($proximos): ?>
            <h3>Próximos</h3>
            <ul class="lista">
                <?php foreach ($proximos as $c): ?>
                    <li>N° <?= e($c['numero']) ?> · <?= (int)$c['personas'] ?> personas · llega el <?= fecha_ar($c['fecha_llegada']) ?> (<?= (int)$c['dias'] ?> días)</li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <p><a href="contingentes.php">Ver todos los contingentes →</a></p>
    </section>

    <?php if (es_admin() && !$planificando):
        $c = ['numero' => '', 'personas' => '', 'fecha_llegada' => $hoy,
              'fecha_salida' => date('Y-m-d', strtotime('+2 days')), 'observaciones' => '']; ?>
    <section class="card">
        <h2>Nuevo contingente</h2>
        <form method="get" action="index.php#plan">
            <input type="hidden" name="planificar" value="1">
            <input type="hidden" name="armado" value="1">
            <?php $autofocus = false; include __DIR__ . '/inc/form_contingente.php'; ?>
            <button class="btn">Elegir menús y calcular materia prima →</button>
        </form>
    </section>
    <?php endif; ?>
</div>

<section class="card">
    <h2>Menú de hoy</h2>
    <?php if (!$comidasHoy): ?>
        <p class="muted">Hoy no hay contingentes con menú en el hotel.</p>
    <?php else: ?>
    <p class="muted small"><?= e($explicacion) ?> Ya está descontado del stock (se descuenta al confirmar el contingente).</p>
    <div class="comidas-hoy">
        <?php foreach ($comidasHoy as $sv => $pl): ?>
            <div class="comida">
                <h3><?= $sv ?> <?= !empty($paxComida[$sv]) ? '<span class="muted small">· ' . (int)$paxComida[$sv] . ' personas</span>' : '' ?></h3>
                <ul><?php foreach ($pl as $p): ?><li><?= e($p['nombre']) ?><?= !$p['ingredientes'] ? ' <span class="badge b-warn">Sin receta</span>' : '' ?></li><?php endforeach; ?></ul>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

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
        <h2>Este mes</h2>
        <table class="tabla compacta">
            <tr><td>Raciones servidas</td><td class="num"><strong><?= (int)$serv['pax'] ?></strong></td></tr>
            <tr><td>Costo de materia prima</td><td class="num"><strong><?= dinero($serv['costo']) ?></strong></td></tr>
            <tr><td>Costo por ración</td><td class="num"><strong><?= $serv['pax'] ? dinero($serv['costo'] / $serv['pax']) : '—' ?></strong></td></tr>
            <tr><td>Valor del stock actual</td><td class="num"><strong><?= dinero($valor) ?></strong></td></tr>
        </table>
        <p><a href="movimientos.php">Ver movimientos →</a></p>
    </section>
</div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/inc/footer.php'; ?>
