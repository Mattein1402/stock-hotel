<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();
$pdo = db();

/* ================== ACCIONES ================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    csrf_check();
    $acc = $_POST['accion'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $volver = $_POST['volver'] ?? 'contingentes.php';
    if (!in_array($volver, ['index.php', 'contingentes.php'], true)) $volver = 'contingentes.php';

    if ($acc === 'guardar') {
        $numero = trim($_POST['numero'] ?? '');
        $llegada = valid_fecha($_POST['fecha_llegada'] ?? '');
        $dias = (int)($_POST['dias'] ?? 0);
        $personas = (int)($_POST['personas'] ?? 0);
        $obs = trim($_POST['observaciones'] ?? '');

        if ($numero === '' || !$llegada || $dias < 1 || $dias > 30 || $personas < 1) {
            flash('Completá N° de contingente, fecha de llegada, días (1 a 30) y cantidad de personas.', 'error');
            redirect($id ? "contingentes.php?editar=$id" : $volver);
        }
        $salida = date('Y-m-d', strtotime($llegada . ' +' . ($dias - 1) . ' days'));

        // Aviso si el número ya existe en otro contingente
        $st = $pdo->prepare('SELECT COUNT(*) FROM contingentes WHERE numero = ? AND id <> ? AND cancelado = 0');
        $st->execute([$numero, $id]);
        $repetido = (int)$st->fetchColumn() > 0;

        if ($id) {
            $pdo->prepare('UPDATE contingentes SET numero=?, fecha_llegada=?, dias=?, fecha_salida=?, personas=?, observaciones=? WHERE id=?')
                ->execute([$numero, $llegada, $dias, $salida, $personas, mb_substr($obs, 0, 255), $id]);
            flash('Contingente N° ' . $numero . ' actualizado.');
        } else {
            $pdo->prepare('INSERT INTO contingentes (numero, fecha_llegada, dias, fecha_salida, personas, observaciones) VALUES (?,?,?,?,?,?)')
                ->execute([$numero, $llegada, $dias, $salida, $personas, mb_substr($obs, 0, 255)]);
            flash('Contingente N° ' . $numero . ' cargado: ' . $personas . ' personas del ' . fecha_ar($llegada) . ' al ' . fecha_ar($salida) . '.');
        }
        if ($repetido) flash('Ojo: ya hay otro contingente con el N° ' . $numero . '.', 'warn');
        redirect($volver);
    }

    if ($acc === 'cancelar') {
        $pdo->prepare('UPDATE contingentes SET cancelado = 1 - cancelado WHERE id=?')->execute([$id]);
        flash('Estado del contingente actualizado.');
        redirect('contingentes.php');
    }

    if ($acc === 'eliminar') {
        $st = $pdo->prepare('SELECT COUNT(*) FROM servicio_contingentes WHERE contingente_id=?');
        $st->execute([$id]);
        if ((int)$st->fetchColumn() > 0) {
            flash('Este contingente ya tiene comidas registradas. No se puede eliminar, pero podés cancelarlo.', 'error');
        } else {
            $pdo->prepare('DELETE FROM contingentes WHERE id=?')->execute([$id]);
            flash('Contingente eliminado.');
        }
        redirect('contingentes.php');
    }
}

$titulo = 'Contingentes';
$editar = (int)($_GET['editar'] ?? 0);
$ver = (int)($_GET['ver'] ?? 0);
$nuevo = isset($_GET['nuevo']);
require __DIR__ . '/inc/header.php';

/* ================== FORMULARIO ================== */
if (($editar || $nuevo) && es_admin()):
    $c = ['id' => 0, 'numero' => '', 'fecha_llegada' => date('Y-m-d'), 'dias' => 7, 'personas' => '', 'observaciones' => ''];
    if ($editar) {
        $st = $pdo->prepare('SELECT * FROM contingentes WHERE id=?');
        $st->execute([$editar]);
        $c = $st->fetch() ?: $c;
    }
?>
<h1><?= $c['id'] ? 'Editar contingente' : 'Nuevo contingente' ?></h1>
<form method="post" class="card" style="max-width:640px">
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="guardar">
    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
    <?php include __DIR__ . '/inc/form_contingente.php'; ?>
    <div class="acciones"><span class="spacer"></span><a class="btn sec" href="contingentes.php">Cancelar</a><button class="btn">Guardar</button></div>
</form>

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
        <?php if (es_admin()): ?><a class="btn sec" href="contingentes.php?editar=<?= (int)$c['id'] ?>">Editar</a><?php endif; ?>
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
<div class="card">
    <h2>Comidas servidas a este contingente</h2>
    <?php if (!$comidas): ?><p class="muted">Todavía no se registraron comidas.</p><?php else: ?>
    <div class="scroll">
    <table class="tabla">
        <thead><tr><th>Fecha</th><th>Servicio</th><th>Platos</th><th class="num">Personas</th><th class="num">Costo (parte del contingente)</th></tr></thead>
        <tbody>
        <?php foreach ($comidas as $m): ?>
            <tr>
                <td class="nowrap"><?= fecha_ar($m['fecha']) ?></td>
                <td class="nowrap"><?= e($m['servicio'] ?? '') ?><?= $m['dia_menu'] ? ' · Día ' . (int)$m['dia_menu'] : '' ?></td>
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
        <thead><tr><th>N°</th><th>Llegada</th><th>Último día</th><th class="num">Días</th><th class="num">Personas</th><th>Estado</th><th class="num">Comidas</th><th class="num">Costo</th><th></th></tr></thead>
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
                        <form method="post" data-confirm="<?= $c['cancelado'] ? '¿Reactivar este contingente?' : '¿Cancelar este contingente? Deja de contar para las comidas.' ?>"><?= csrf_field() ?><input type="hidden" name="accion" value="cancelar"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn-link"><?= $c['cancelado'] ? 'Reactivar' : 'Cancelar' ?></button></form>
                        <?php if (!$c['comidas']): ?>
                        <form method="post" data-confirm="¿Eliminar el contingente N° <?= e($c['numero']) ?>?"><?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn-link peligro">Eliminar</button></form>
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
