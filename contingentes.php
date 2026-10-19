<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();
$pdo = db();

/**
 * Confirma un contingente: calcula lo de toda la estadía, guarda la lista de compras
 * (lo que no alcanza con el stock actual) y descuenta todo del stock.
 * Si ya se había descontado antes (por una edición), primero lo devuelve.
 */
function confirmar_consumo(PDO $pdo, int $cid): array
{
    $st = $pdo->prepare('SELECT * FROM contingentes WHERE id = ?');
    $st->execute([$cid]);
    $c = $st->fetch();
    deshacer_consumo($pdo, $cid);

    // comidas de la estadía según el plan guardado
    $st = $pdo->prepare('SELECT fecha, servicio, dia_menu FROM contingente_menu WHERE contingente_id = ? AND dia_menu > 0 ORDER BY fecha');
    $st->execute([$cid]);
    $comidas = [];
    foreach ($st->fetchAll() as $r) {
        $platos = array_map(fn($p) => (int)$p['id'], platos_del_menu($pdo, (int)$r['dia_menu'], $r['servicio']));
        if ($platos) $comidas[] = ['fecha' => $r['fecha'], 'servicio' => $r['servicio'], 'dia' => (int)$r['dia_menu'], 'platos' => $platos];
    }

    // lista de compras con el stock de antes de descontar
    $calc = calcular_plan($pdo, $comidas, (int)$c['personas']);
    $pdo->prepare('DELETE FROM contingente_compras WHERE contingente_id = ?')->execute([$cid]);
    $ins = $pdo->prepare('INSERT INTO contingente_compras (contingente_id, producto_id, necesario, stock_antes, comprar) VALUES (?,?,?,?,?)');
    foreach ($calc['productos'] as $pid => $p) $ins->execute([$cid, $pid, $p['necesario'], $p['stock'], $p['comprar']]);

    // descuento automático (si no alcanza, el stock queda en negativo = lo que falta comprar)
    foreach ($comidas as $m) {
        registrar_consumo($pdo, $m['fecha'], $m['servicio'], $m['dia'], $m['platos'], [$cid => (int)$c['personas']], 'Contingente N° ' . $c['numero'], true);
    }
    return $calc;
}

/* ================== ACCIONES ================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    csrf_check();
    $acc = $_POST['accion'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $volver = $_POST['volver'] ?? 'contingentes.php';
    if (!in_array($volver, ['index.php', 'contingentes.php'], true)) $volver = 'contingentes.php';

    if ($acc === 'guardar') {
        require_once __DIR__ . '/inc/planificador.php';
        $numero = trim($_POST['numero'] ?? '');
        $llegada = valid_fecha($_POST['fecha_llegada'] ?? '');
        $salida = valid_fecha($_POST['fecha_salida'] ?? '');
        $personas = (int)($_POST['personas'] ?? 0);
        $obs = trim($_POST['observaciones'] ?? '');
        $menus = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['menus'] ?? [])), fn($n) => $n >= 1 && $n <= CICLO_DIAS)));
        $dias = ($llegada && $salida) ? (int)((strtotime($salida) - strtotime($llegada)) / 86400) + 1 : 0;

        if ($numero === '' || !$llegada || !$salida || $dias < 1 || $dias > 30 || $personas < 1 || !$menus) {
            flash('Revisá los datos: N° de contingente, personas, fechas (máximo 30 días) y al menos un menú tildado.', 'error');
            redirect($id ? "contingentes.php?editar=$id" : $volver);
        }
        // Come todas las comidas de los menús tildados
        $comLl = SERVICIOS[0];
        $comSa = SERVICIOS[count(SERVICIOS) - 1];

        // Aviso si el número ya existe en otro contingente
        $st = $pdo->prepare('SELECT COUNT(*) FROM contingentes WHERE numero = ? AND id <> ? AND cancelado = 0');
        $st->execute([$numero, $id]);
        $repetido = (int)$st->fetchColumn() > 0;

        $pdo->beginTransaction();
        if ($id) {
            $pdo->prepare('UPDATE contingentes SET numero=?, fecha_llegada=?, comida_llegada=?, dias=?, fecha_salida=?, comida_salida=?, personas=?, observaciones=? WHERE id=?')
                ->execute([$numero, $llegada, $comLl, $dias, $salida, $comSa, $personas, mb_substr($obs, 0, 255), $id]);
        } else {
            $pdo->prepare('INSERT INTO contingentes (numero, fecha_llegada, comida_llegada, dias, fecha_salida, comida_salida, personas, observaciones) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$numero, $llegada, $comLl, $dias, $salida, $comSa, $personas, mb_substr($obs, 0, 255)]);
            $id = (int)$pdo->lastInsertId();
        }

        // Plan: cada día de la estadía con su menú (en orden de los tildados); 0 = ese día no hay menú
        $pdo->prepare('DELETE FROM contingente_menu WHERE contingente_id = ?')->execute([$id]);
        $ins = $pdo->prepare('INSERT INTO contingente_menu (contingente_id, fecha, servicio, dia_menu) VALUES (?,?,?,?)');
        $servicios = servicios_con_menu($pdo);
        foreach (asignar_menus($llegada, $salida, $menus) as $fecha => $m) {
            foreach ($servicios as $sv) $ins->execute([$id, $fecha, $sv, $m]);
        }
        $calc = confirmar_consumo($pdo, $id);
        $pdo->commit();

        sort($menus);
        flash('Contingente N° ' . $numero . ' confirmado: ' . $personas . ' personas, del ' . fecha_ar($llegada) . ' al ' . fecha_ar($salida) . ' · Menús ' . implode(', ', $menus) . '. Se descontó del stock todo lo de la estadía.');
        if ($repetido) flash('Ojo: ya hay otro contingente con el N° ' . $numero . '.', 'warn');
        if ($calc['faltantes']) flash('Hay ' . $calc['faltantes'] . ' producto(s) para comprar: está la lista abajo.', 'warn');
        redirect('contingentes.php?ver=' . $id . '#compras');
    }

    if ($acc === 'cancelar') {
        $st = $pdo->prepare('SELECT cancelado FROM contingentes WHERE id = ?');
        $st->execute([$id]);
        $cancelado = (int)$st->fetchColumn();
        $pdo->beginTransaction();
        if ($cancelado) {
            $pdo->prepare('UPDATE contingentes SET cancelado = 0 WHERE id = ?')->execute([$id]);
            confirmar_consumo($pdo, $id);
            $msg = 'Contingente reactivado: se volvió a descontar del stock.';
        } else {
            deshacer_consumo($pdo, $id);
            $pdo->prepare('UPDATE contingentes SET cancelado = 1 WHERE id = ?')->execute([$id]);
            $msg = 'Contingente cancelado: la mercadería volvió al stock.';
        }
        $pdo->commit();
        flash($msg);
        redirect('contingentes.php');
    }

    if ($acc === 'eliminar') {
        $pdo->beginTransaction();
        deshacer_consumo($pdo, $id, true);
        $pdo->prepare('DELETE FROM contingentes WHERE id = ?')->execute([$id]);
        $pdo->commit();
        flash('Contingente eliminado. Lo que se había descontado volvió al stock.');
        redirect('contingentes.php');
    }
}

$titulo = 'Contingentes';
$editar = (int)($_GET['editar'] ?? 0);
$ver = (int)($_GET['ver'] ?? 0);
$nuevo = isset($_GET['nuevo']);
require __DIR__ . '/inc/header.php';

/* ================== NUEVO / EDITAR (planificador) ================== */
if (($editar || $nuevo) && es_admin()):
    require_once __DIR__ . '/inc/planificador.php';
    $guardado = null;
    if ($editar) {
        $st = $pdo->prepare('SELECT * FROM contingentes WHERE id=?');
        $st->execute([$editar]);
        $guardado = $st->fetch() ?: null;
    }
    $plan = plan_desde_datos($pdo, $_GET, $guardado);
?>
<div class="titulo-acciones no-print">
    <h1><?= $guardado ? 'Editar contingente' : 'Nuevo contingente' ?></h1>
    <a class="btn sec" href="<?= $guardado ? 'contingentes.php?ver=' . (int)$guardado['id'] : 'contingentes.php' ?>">← Volver</a>
</div>
<?php render_planificador($pdo, $plan, 'contingentes.php', $guardado ? ['editar' => (int)$guardado['id']] : ['nuevo' => 1], 'contingentes.php'); ?>

<?php
/* ================== DETALLE ================== */
elseif ($ver):
    $st = $pdo->prepare('SELECT * FROM contingentes WHERE id=?');
    $st->execute([$ver]);
    $c = $st->fetch();
    if (!$c) { echo '<p>No existe.</p>'; require __DIR__ . '/inc/footer.php'; exit; }
    [$est, $cls] = estado_contingente($c);
    $st = $pdo->prepare(
        "SELECT s.id, s.fecha, s.servicio, s.dia_menu, s.menu_nombre, s.comensales, s.costo_total, sc.personas,
                s.costo_total * sc.personas / NULLIF(s.comensales, 0) AS costo_parte
         FROM servicio_contingentes sc JOIN servicios s ON s.id = sc.servicio_id
         WHERE sc.contingente_id = ? AND s.anulado = 0
         ORDER BY s.fecha, FIELD(s.servicio, 'Desayuno', 'Almuerzo', 'Merienda', 'Cena')"
    );
    $st->execute([$ver]);
    $comidas = $st->fetchAll();
    $total = array_sum(array_column($comidas, 'costo_parte'));
    $raciones = array_sum(array_column($comidas, 'personas'));
?>
<div class="titulo-acciones">
    <h1>Contingente N° <?= e($c['numero']) ?> <span class="badge <?= $cls ?>"><?= e($est) ?></span></h1>
    <div>
        <?php if (es_admin()): ?><a class="btn sec" href="contingentes.php?editar=<?= (int)$c['id'] ?>">Modificar contingente</a><?php endif; ?>
        <a class="btn sec" href="contingentes.php">← Volver</a>
    </div>
</div>
<div class="stats">
    <div class="stat"><span>Personas</span><strong><?= (int)$c['personas'] ?></strong></div>
    <div class="stat"><span>Estadía</span><strong><?= fecha_ar($c['fecha_llegada']) ?> → <?= fecha_ar($c['fecha_salida']) ?></strong></div>
    <div class="stat"><span>Comidas registradas</span><strong><?= count($comidas) ?></strong></div>
    <div class="stat"><span>Costo materia prima</span><strong><?= dinero($total) ?></strong></div>
    <div class="stat"><span>Costo por ración</span><strong><?= $raciones ? dinero($total / $raciones) : '—' ?></strong></div>
</div>
<?php if ($c['observaciones']): ?><p class="muted"><?= e($c['observaciones']) ?></p><?php endif; ?>
<?php
    require_once __DIR__ . '/inc/planificador.php';
    $st = $pdo->prepare('SELECT fecha, MAX(dia_menu) AS menu FROM contingente_menu WHERE contingente_id = ? GROUP BY fecha ORDER BY fecha');
    $st->execute([$ver]);
    $planDias = $st->fetchAll();
    $st = $pdo->prepare(
        "SELECT cc.*, p.nombre, p.categoria, p.unidad, p.stock AS stock_actual
         FROM contingente_compras cc JOIN productos p ON p.id = cc.producto_id
         WHERE cc.contingente_id = ? ORDER BY p.categoria, p.nombre"
    );
    $st->execute([$ver]);
    $lista = $st->fetchAll();
    $aComprar = array_filter($lista, fn($r) => $r['comprar'] > 0);
?>
<?php if ($planDias): ?>
<div class="card no-print">
    <h2>Menú de cada día</h2>
    <div class="chips">
        <?php foreach ($planDias as $pd): ?>
            <span class="chip <?= $pd['menu'] ? '' : 'chip-vacio' ?>"><?= DIAS_SEMANA[(int)date('w', strtotime($pd['fecha']))] ?> <?= fecha_ar($pd['fecha']) ?> → <?= $pd['menu'] ? 'Menú ' . (int)$pd['menu'] : 'sin menú' ?></span>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<section class="card imprimible" id="compras">
    <div class="titulo-acciones">
        <h2 style="margin:0">Lista de compras</h2>
        <?php if ($lista): ?><button type="button" class="btn sec no-print" onclick="window.print()">Imprimir lista de compras</button><?php endif; ?>
    </div>
    <p class="solo-print">Contingente N° <?= e($c['numero']) ?> · <?= (int)$c['personas'] ?> personas · del <?= fecha_ar($c['fecha_llegada']) ?> al <?= fecha_ar($c['fecha_salida']) ?></p>
    <?php if (!$lista): ?>
        <p class="muted">Este contingente todavía no se confirmó. <?= es_admin() ? '<a href="contingentes.php?editar=' . (int)$c['id'] . '">Elegir menús y confirmar</a>' : '' ?></p>
    <?php else: ?>
        <p class="muted small no-print">Calculada al confirmar el contingente (<?= fecha_ar($lista[0]['created_at']) ?>), con el stock que había en ese momento. «Stock ahora» te muestra cómo va después de cargar las compras: si está en negativo, todavía falta comprar esa cantidad.</p>
        <div class="scroll">
        <table class="tabla">
            <thead><tr><th>Producto</th><th class="num">Se necesita</th><th class="num">Había</th><th class="num">A comprar</th><th class="num no-print">Stock ahora</th></tr></thead>
            <tbody>
            <?php $cat = null; foreach ($lista as $r):
                if ($r['categoria'] !== $cat): $cat = $r['categoria']; ?>
                    <tr class="fila-categoria"><td colspan="5"><?= e($cat ?: 'Sin categoría') ?></td></tr>
                <?php endif; ?>
                <tr class="<?= $r['comprar'] > 0 ? 'fila-falta' : '' ?>">
                    <td><?= e($r['nombre']) ?></td>
                    <td class="num"><?= num($r['necesario']) ?> <?= e($r['unidad']) ?></td>
                    <td class="num"><?= num(max(0, $r['stock_antes'])) ?></td>
                    <td class="num"><?= $r['comprar'] > 0 ? '<strong>' . num($r['comprar']) . ' ' . e($r['unidad']) . '</strong>' : '<span class="badge b-ok">Alcanza</span>' ?></td>
                    <td class="num no-print"><span class="badge <?= $r['stock_actual'] < 0 ? 'b-err' : 'b-ok' ?>"><?= num($r['stock_actual']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <p class="total-linea"><?= $aComprar ? '<strong>' . count($aComprar) . ' producto(s) para comprar.</strong>' : 'Con el stock que había alcanzaba para toda la estadía.' ?></p>
        <?php if (es_admin() && $aComprar): ?><p class="no-print"><a class="btn" href="compras.php">Cargar la compra →</a></p><?php endif; ?>
    <?php endif; ?>
</section>

<div class="card">
    <h2>Descontado del stock</h2>
    <?php if (!$comidas): ?><p class="muted">Todavía no se descontó nada (el contingente no está confirmado).</p><?php else: ?>
    <div class="scroll">
    <table class="tabla">
        <thead><tr><th>Fecha</th><th>Servicio</th><th>Platos</th><th class="num">Personas</th><th class="num">Costo (parte del contingente)</th></tr></thead>
        <tbody>
        <?php foreach ($comidas as $m): ?>
            <tr>
                <td class="nowrap"><?= fecha_ar($m['fecha']) ?></td>
                <td class="nowrap"><?= e($m['servicio'] ?? '') ?><?= $m['dia_menu'] ? ' · Menú ' . (int)$m['dia_menu'] : '' ?></td>
                <td class="small"><?= e($m['menu_nombre']) ?></td>
                <td class="num"><?= (int)$m['personas'] ?><?= $m['comensales'] != $m['personas'] ? ' <span class="muted small">de ' . (int)$m['comensales'] . '</span>' : '' ?></td>
                <td class="num"><?= dinero($m['costo_parte']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><th colspan="4" class="num">Total</th><th class="num"><?= dinero($total) ?></th></tr></tfoot>
    </table>
    </div>
    <p class="muted small">Si en una comida comieron varios contingentes juntos, el costo se reparte según la cantidad de personas de cada uno.</p>
    <?php endif; ?>
</div>

<?php
/* ================== LISTADO ================== */
else:
    $filtro = $_GET['f'] ?? 'vigentes';
    $hoy = date('Y-m-d');
    $where = match ($filtro) {
        'finalizados' => 'c.cancelado = 0 AND c.fecha_salida < ?',
        'cancelados' => 'c.cancelado = 1 AND ? = ?',
        'todos' => '? = ?',
        default => 'c.cancelado = 0 AND c.fecha_salida >= ?',
    };
    $params = match ($filtro) {
        'cancelados', 'todos' => [1, 1],
        default => [$hoy],
    };
    $orden = in_array($filtro, ['finalizados', 'todos', 'cancelados'], true) ? 'c.fecha_llegada DESC' : 'c.fecha_llegada ASC';
    $st = $pdo->prepare(
        "SELECT c.*,
            (SELECT COUNT(*) FROM servicio_contingentes sc JOIN servicios s ON s.id = sc.servicio_id WHERE sc.contingente_id = c.id AND s.anulado = 0) AS comidas,
            (SELECT COALESCE(SUM(s.costo_total * sc.personas / NULLIF(s.comensales, 0)), 0) FROM servicio_contingentes sc JOIN servicios s ON s.id = sc.servicio_id WHERE sc.contingente_id = c.id AND s.anulado = 0) AS costo
         FROM contingentes c WHERE $where ORDER BY $orden LIMIT 300"
    );
    $st->execute($params);
    $lista = $st->fetchAll();
?>
<div class="titulo-acciones">
    <h1>Contingentes</h1>
    <?php if (es_admin()): ?><a class="btn" href="contingentes.php?nuevo=1">+ Nuevo contingente</a><?php endif; ?>
</div>
<div class="tabs">
    <?php foreach (['vigentes' => 'En el hotel y próximos', 'finalizados' => 'Finalizados', 'cancelados' => 'Cancelados', 'todos' => 'Todos'] as $k => $v): ?>
        <a href="?f=<?= $k ?>" class="<?= $filtro === $k || ($k === 'vigentes' && !in_array($filtro, ['finalizados', 'cancelados', 'todos'], true)) ? 'activo' : '' ?>"><?= $v ?></a>
    <?php endforeach; ?>
</div>
<div class="card">
    <div class="scroll">
    <table class="tabla">
        <thead><tr><th>N°</th><th>Llegada</th><th>Salida</th><th class="num">Días</th><th class="num">Personas</th><th>Estado</th><th class="num">Comidas</th><th class="num">Costo</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($lista as $c): [$est, $cls] = estado_contingente($c); ?>
            <tr class="<?= $c['cancelado'] ? 'anulado' : '' ?>">
                <td><a href="contingentes.php?ver=<?= (int)$c['id'] ?>"><strong><?= e($c['numero']) ?></strong></a>
                    <?php if ($c['observaciones']): ?><div class="muted small"><?= e($c['observaciones']) ?></div><?php endif; ?></td>
                <td class="nowrap"><?= fecha_ar($c['fecha_llegada']) ?></td>
                <td class="nowrap"><?= fecha_ar($c['fecha_salida']) ?></td>
                <td class="num"><?= (int)$c['dias'] ?></td>
                <td class="num"><strong><?= (int)$c['personas'] ?></strong></td>
                <td><span class="badge <?= $cls ?>"><?= e($est) ?></span></td>
                <td class="num"><?= (int)$c['comidas'] ?></td>
                <td class="num"><?= dinero($c['costo']) ?></td>
                <td class="acciones-fila">
                    <a href="contingentes.php?ver=<?= (int)$c['id'] ?>">Ver</a>
                    <?php if (es_admin()): ?>
                        <a href="contingentes.php?editar=<?= (int)$c['id'] ?>">Editar</a>
                        <form method="post" data-confirm="<?= $c['cancelado'] ? '¿Reactivar este contingente? Se vuelve a descontar del stock.' : '¿Cancelar este contingente? Lo que se descontó vuelve al stock.' ?>"><?= csrf_field() ?><input type="hidden" name="accion" value="cancelar"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn-link"><?= $c['cancelado'] ? 'Reactivar' : 'Cancelar' ?></button></form>
                        <?php if (true): ?>
                        <form method="post" data-confirm="¿Eliminar el contingente N° <?= e($c['numero']) ?>? Lo que se descontó vuelve al stock."><?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn-link peligro">Eliminar</button></form>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$lista): ?><tr><td colspan="9" class="muted">No hay contingentes en esta lista.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/inc/footer.php'; ?>
