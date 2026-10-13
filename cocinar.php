<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();
$pdo = db();

/**
 * Lee la selección del formulario (GET o POST).
 * Si el formulario no viene "armado" se arma solo: todos los contingentes
 * del día con su cantidad de personas, el día de menú sugerido y todos sus platos.
 */
function leer_seleccion(PDO $pdo, array $src): array
{
    $fecha = valid_fecha($src['fecha'] ?? '') ?: date('Y-m-d');
    $servicio = in_array($src['servicio'] ?? '', SERVICIOS, true) ? $src['servicio'] : servicio_por_hora();
    $activos = contingentes_activos($pdo, $fecha);
    [$sugerido, $explicacion] = dia_menu_sugerido($activos);
    $armado = !empty($src['armado']);

    $dia = (int)($src['dia'] ?? 0);
    if (!$armado || $dia < 1 || $dia > CICLO_DIAS) $dia = $sugerido;

    // Contingentes elegidos y sus personas
    $sel = [];
    $paxIn = (array)($src['pax'] ?? []);
    $marcados = array_map('intval', (array)($src['sel'] ?? []));
    foreach ($activos as $c) {
        $cid = (int)$c['id'];
        if ($armado && !in_array($cid, $marcados, true)) continue;
        $p = $armado && isset($paxIn[$cid]) ? max(0, (int)$paxIn[$cid]) : (int)$c['personas'];
        $sel[$cid] = $p;
    }
    $extra = $armado ? max(0, (int)($src['extra'] ?? 0)) : 0;

    // Platos
    $platosMenu = platos_del_menu($pdo, $dia, $servicio);
    $idsMenu = array_map(fn($p) => (int)$p['id'], $platosMenu);
    if ($armado && empty($src['cambio_dia'])) {
        $platos = array_values(array_intersect($idsMenu, array_map('intval', (array)($src['platos'] ?? []))));
    } else {
        $platos = $idsMenu;
    }

    return [
        'fecha' => $fecha, 'servicio' => $servicio, 'activos' => $activos, 'dia' => $dia,
        'sugerido' => $sugerido, 'explicacion' => $explicacion,
        'sel' => $sel, 'extra' => $extra, 'total' => array_sum($sel) + $extra,
        'platosMenu' => $platosMenu, 'platos' => $platos,
        'obs' => trim($src['observaciones'] ?? ''),
    ];
}

function campos_ocultos(array $s): string
{
    $h = '';
    $add = function ($n, $v) use (&$h) { $h .= '<input type="hidden" name="' . e($n) . '" value="' . e($v) . '">'; };
    $add('armado', 1);
    $add('fecha', $s['fecha']);
    $add('servicio', $s['servicio']);
    $add('dia', $s['dia']);
    $add('extra', $s['extra']);
    $add('observaciones', $s['obs']);
    foreach ($s['sel'] as $cid => $p) { $add('sel[]', $cid); $add("pax[$cid]", $p); }
    foreach ($s['platos'] as $pid) $add('platos[]', $pid);
    return $h;
}

/* ================== ACCIONES ================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $acc = $_POST['accion'] ?? '';

    if ($acc === 'confirmar') {
        $s = leer_seleccion($pdo, $_POST);
        $forzar = es_admin() && !empty($_POST['forzar']);
        $volver = 'cocinar.php?' . http_build_query([
            'armado' => 1, 'calc' => 1, 'fecha' => $s['fecha'], 'servicio' => $s['servicio'], 'dia' => $s['dia'],
            'extra' => $s['extra'], 'observaciones' => $s['obs'], 'sel' => array_keys($s['sel']), 'pax' => $s['sel'], 'platos' => $s['platos'],
        ]);
        try {
            if ($s['total'] <= 0) throw new RuntimeException('No hay comensales: elegí al menos un contingente o cargá otros comensales.');
            if (!$s['platos']) throw new RuntimeException('Elegí al menos un plato.');

            $pdo->beginTransaction();
            // Bloquea los productos que se van a usar
            $in = implode(',', array_fill(0, count($s['platos']), '?'));
            $lock = $pdo->prepare("SELECT id FROM productos WHERE id IN (SELECT producto_id FROM plato_items WHERE plato_id IN ($in)) FOR UPDATE");
            $lock->execute($s['platos']);
            $lock->fetchAll();

            $calc = calcular_consumo($pdo, $s['platos'], $s['total']);
            if ($calc['faltantes'] > 0 && !$forzar) {
                throw new RuntimeException('No hay stock suficiente de ' . $calc['faltantes'] . ' producto(s). Revisá el detalle en rojo.');
            }

            $nombre = mb_substr(implode(', ', array_column($calc['platos'], 'nombre')), 0, 255);
            $obs = $s['obs'];
            if ($s['extra'] > 0) $obs = trim($obs . ' (+' . $s['extra'] . ' otros comensales)');
            $pdo->prepare('INSERT INTO servicios (fecha, servicio, dia_menu, menu_nombre, comensales, costo_total, observaciones, usuario_id) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$s['fecha'], $s['servicio'], $s['dia'], $nombre, $s['total'], round($calc['costo_total'], 2), mb_substr($obs, 0, 255), usuario()['id']]);
            $sid = (int)$pdo->lastInsertId();

            $insC = $pdo->prepare('INSERT INTO servicio_contingentes (servicio_id, contingente_id, personas) VALUES (?,?,?)');
            foreach ($s['sel'] as $cid => $p) if ($p > 0) $insC->execute([$sid, $cid, $p]);

            $insI = $pdo->prepare('INSERT INTO servicio_items (servicio_id, producto_id, cantidad, costo_unitario) VALUES (?,?,?,?)');
            $nota = $s['servicio'] . ' día ' . $s['dia'] . ' × ' . $s['total'] . ' pax';
            foreach ($calc['productos'] as $prod => $p) {
                if ($p['necesario'] <= 0) continue;
                $insI->execute([$sid, $prod, $p['necesario'], $p['costo']]);
                mover_stock($pdo, $prod, -$p['necesario'], 'consumo', 'servicio', $sid, $nota);
            }
            $pdo->commit();
            flash($s['servicio'] . ' del ' . fecha_ar($s['fecha']) . ' registrado: se descontó el stock para ' . $s['total'] . ' comensales.');
            redirect('cocinar.php?fecha=' . $s['fecha']);
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash($ex->getMessage(), 'error');
            redirect($volver);
        }
    }

    // Anular un servicio: devuelve la mercadería al stock
    if ($acc === 'anular') {
        require_admin();
        $sid = (int)($_POST['id'] ?? 0);
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare('SELECT * FROM servicios WHERE id = ? AND anulado = 0 FOR UPDATE');
            $st->execute([$sid]);
            $sv = $st->fetch();
            if (!$sv) throw new RuntimeException('El servicio no existe o ya fue anulado.');
            $it = $pdo->prepare('SELECT producto_id, cantidad FROM servicio_items WHERE servicio_id = ?');
            $it->execute([$sid]);
            foreach ($it->fetchAll() as $r) {
                mover_stock($pdo, (int)$r['producto_id'], (float)$r['cantidad'], 'anulacion', 'servicio', $sid, 'Anulación: ' . ($sv['servicio'] ?? '') . ' ' . fecha_ar($sv['fecha']));
            }
            $pdo->prepare('UPDATE servicios SET anulado = 1 WHERE id = ?')->execute([$sid]);
            $pdo->commit();
            flash('Servicio anulado. La mercadería volvió al stock.');
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash($ex->getMessage(), 'error');
        }
        redirect('cocinar.php');
    }
}

/* ================== DATOS PARA LA VISTA ================== */
$s = leer_seleccion($pdo, $_GET);
$calc = null;
if (!empty($_GET['calc']) && $s['total'] > 0 && $s['platos']) {
    $calc = calcular_consumo($pdo, $s['platos'], $s['total']);
}

$st = $pdo->prepare('SELECT COUNT(*) FROM servicios WHERE fecha = ? AND servicio = ? AND anulado = 0');
$st->execute([$s['fecha'], $s['servicio']]);
$yaRegistrado = (int)$st->fetchColumn();

// Últimos servicios
$servicios = $pdo->query(
    "SELECT s.*, u.nombre AS usuario,
        (SELECT GROUP_CONCAT(CONCAT('N° ', c.numero, ' (', sc.personas, ')') SEPARATOR ', ')
           FROM servicio_contingentes sc JOIN contingentes c ON c.id = sc.contingente_id WHERE sc.servicio_id = s.id) AS contingentes
     FROM servicios s LEFT JOIN usuarios u ON u.id = s.usuario_id ORDER BY s.id DESC LIMIT 25"
)->fetchAll();
$detalle = [];
if ($servicios) {
    $ids = array_column($servicios, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT si.*, p.nombre, p.unidad FROM servicio_items si JOIN productos p ON p.id = si.producto_id WHERE si.servicio_id IN ($in) ORDER BY p.nombre");
    $st->execute($ids);
    foreach ($st->fetchAll() as $r) $detalle[$r['servicio_id']][] = $r;
}

$titulo = 'Cocinar';
require __DIR__ . '/inc/header.php';
?>
<h1>Cocinar / servir</h1>
<p class="muted">Los comensales salen solos de los contingentes que están en el hotel. Revisá, calculá y confirmá: el stock se descuenta automáticamente.</p>

<form method="get" class="card" id="form-cocinar">
    <input type="hidden" name="armado" value="1">

    <h2>1. ¿Qué comida?</h2>
    <div class="grid-2">
        <label>Fecha<input type="date" name="fecha" value="<?= e($s['fecha']) ?>" data-autosubmit="reset"></label>
        <label>Comida
            <select name="servicio" data-autosubmit="reset">
                <?php foreach (SERVICIOS as $sv): ?><option <?= $s['servicio'] === $sv ? 'selected' : '' ?>><?= $sv ?></option><?php endforeach; ?>
            </select>
        </label>
    </div>
    <?php if ($yaRegistrado): ?>
        <div class="flash warn">Ojo: ya hay <?= $yaRegistrado ?> registro(s) de <?= e(mb_strtolower($s['servicio'])) ?> para el <?= fecha_ar($s['fecha']) ?>. Revisá abajo en «Últimos servicios» para no descontar dos veces.</div>
    <?php endif; ?>

    <h2>2. Comensales</h2>
    <?php if (!$s['activos']): ?>
        <div class="flash warn">No hay contingentes en el hotel el <?= fecha_ar($s['fecha']) ?>.
            <?= es_admin() ? '<a href="contingentes.php?nuevo=1">Cargar contingente</a>.' : '' ?> Si igual hay que cocinar, poné la cantidad en «Otros comensales».</div>
    <?php else: ?>
    <div class="scroll">
    <table class="tabla tabla-pax">
        <thead><tr><th style="width:36px"></th><th>Contingente</th><th>Estadía</th><th style="width:120px">Personas</th></tr></thead>
        <tbody>
        <?php foreach ($s['activos'] as $c):
            $cid = (int)$c['id'];
            $marcado = isset($s['sel'][$cid]);
            $pax = $s['sel'][$cid] ?? (int)$c['personas'];
            $de = (int)$c['dia_estadia']; ?>
            <tr>
                <td><input type="checkbox" name="sel[]" value="<?= $cid ?>" <?= $marcado ? 'checked' : '' ?> data-pax-check></td>
                <td><strong>N° <?= e($c['numero']) ?></strong>
                    <?php if ($c['observaciones']): ?><div class="muted small"><?= e($c['observaciones']) ?></div><?php endif; ?></td>
                <td class="small">Día <?= $de ?> de <?= (int)$c['dias'] ?>
                    <?php if ($de === 1): ?> <span class="badge b-info">Llega hoy</span><?php endif; ?>
                    <?php if ($de === (int)$c['dias']): ?> <span class="badge b-warn">Último día</span><?php endif; ?></td>
                <td><input type="number" name="pax[<?= $cid ?>]" min="0" value="<?= $pax ?>" data-pax inputmode="numeric"></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
    <div class="grid-2">
        <label>Otros comensales (personal, invitados…)<input type="number" name="extra" min="0" value="<?= (int)$s['extra'] ?>" data-pax-extra inputmode="numeric"></label>
        <div class="total-pax">Total: <strong data-pax-total><?= (int)$s['total'] ?></strong> comensales</div>
    </div>

    <h2>3. Menú</h2>
    <div class="grid-2">
        <label>Día del ciclo de menú
            <select name="dia" data-autosubmit="dia">
                <?php for ($d = 1; $d <= CICLO_DIAS; $d++): ?>
                    <option value="<?= $d ?>" <?= $s['dia'] === $d ? 'selected' : '' ?>>Día <?= $d ?><?= $d === $s['sugerido'] ? ' (sugerido)' : '' ?></option>
                <?php endfor; ?>
            </select>
        </label>
        <p class="muted small" style="align-self:center"><?= e($s['explicacion']) ?></p>
    </div>
    <?php if (!$s['platosMenu']): ?>
        <div class="flash warn">El día <?= $s['dia'] ?> no tiene platos cargados para <?= e(mb_strtolower($s['servicio'])) ?>. <?= es_admin() ? '<a href="menus.php">Armar el menú</a>.' : '' ?></div>
    <?php else: ?>
        <ul class="platos-check">
        <?php foreach ($s['platosMenu'] as $p): ?>
            <li><label class="check">
                <input type="checkbox" name="platos[]" value="<?= (int)$p['id'] ?>" <?= in_array((int)$p['id'], $s['platos'], true) ? 'checked' : '' ?>>
                <span><?= e($p['nombre']) ?> <span class="muted small">· <?= e($p['categoria']) ?><?= (int)$p['dia'] === 0 ? ' · todos los días' : '' ?></span>
                <?php if (!$p['ingredientes']): ?><span class="badge b-warn">Sin receta</span>
                <?php else: ?><span class="muted small">· <?= dinero($p['costo_pp']) ?> x persona</span><?php endif; ?></span>
            </label></li>
        <?php endforeach; ?>
        </ul>
        <p class="muted small">Destildá un plato si ese día no se hace (o se reemplaza por otro).</p>
    <?php endif; ?>

    <label>Observaciones (opcional)<input name="observaciones" value="<?= e($s['obs']) ?>"></label>
    <button class="btn grande" name="calc" value="1">Calcular materia prima</button>
</form>

<?php if ($calc): ?>
<section class="card" id="resultado">
    <h2>Materia prima para <?= (int)$calc['comensales'] ?> comensales · <?= e($s['servicio']) ?> día <?= $s['dia'] ?></h2>
    <div class="chips">
        <?php foreach ($calc['platos'] as $p): ?>
            <span class="chip"><?= e($p['nombre']) ?> · <?= $p['sin_receta'] ? 'sin receta' : dinero($p['costo']) ?></span>
        <?php endforeach; ?>
    </div>
    <?php if ($calc['sin_receta']): ?>
        <div class="flash warn">Sin receta cargada (no descuentan nada): <?= e(implode(', ', $calc['sin_receta'])) ?>. <?= es_admin() ? '<a href="platos.php?sin_receta=1">Completar recetas</a>' : '' ?></div>
    <?php endif; ?>

    <?php if ($calc['productos']): ?>
    <div class="scroll">
    <table class="tabla">
        <thead><tr><th>Producto</th><th class="num">Se usa</th><th class="num">Stock actual</th><th class="num">Queda</th><th>Estado</th></tr></thead>
        <tbody>
        <?php foreach ($calc['productos'] as $p): ?>
            <tr class="<?= $p['estado'] === 'falta' ? 'fila-falta' : '' ?>">
                <td><?= e($p['nombre']) ?></td>
                <td class="num"><strong><?= num($p['necesario']) ?></strong> <?= e($p['unidad']) ?></td>
                <td class="num"><?= num($p['stock']) ?> <?= e($p['unidad']) ?></td>
                <td class="num"><?= num($p['queda']) ?> <?= e($p['unidad']) ?></td>
                <td>
                    <?php if ($p['estado'] === 'falta'): ?><span class="badge b-err">Falta <?= num(-$p['queda']) ?></span>
                    <?php elseif ($p['estado'] === 'bajo'): ?><span class="badge b-warn">Queda bajo mínimo</span>
                    <?php else: ?><span class="badge b-ok">OK</span><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p class="total-linea">Costo estimado <strong><?= dinero($calc['costo_total']) ?></strong> (<?= dinero($calc['costo_total'] / max(1, $calc['comensales'])) ?> por persona)</p>
    <?php endif; ?>

    <form method="post" data-once data-confirm="¿Confirmás? Se va a descontar esta mercadería del stock.">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="confirmar">
        <?= campos_ocultos($s) ?>
        <?php if ($calc['faltantes'] > 0): ?>
            <div class="flash error">No alcanza el stock de <?= (int)$calc['faltantes'] ?> producto(s). Cargá la compra o ajustá el stock antes de confirmar.</div>
            <?php if (es_admin()): ?>
                <label class="check"><input type="checkbox" name="forzar" value="1"> Descontar igual (el stock de esos productos quedará negativo)</label>
            <?php endif; ?>
        <?php endif; ?>
        <button class="btn grande" <?= ($calc['faltantes'] > 0 && !es_admin()) ? 'disabled' : '' ?>>✔ Confirmar y descontar stock</button>
    </form>
</section>
<?php endif; ?>

<section class="card">
    <h2>Últimos servicios registrados</h2>
    <?php if (!$servicios): ?>
        <p class="muted">Todavía no se registró ningún servicio.</p>
    <?php else: ?>
    <div class="scroll">
    <table class="tabla">
        <thead><tr><th>Fecha</th><th>Comida</th><th>Platos / contingentes</th><th class="num">Pax</th><th class="num">Costo</th><th>Cargó</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($servicios as $sv): ?>
            <tr id="s<?= (int)$sv['id'] ?>" class="<?= $sv['anulado'] ? 'anulado' : '' ?>">
                <td class="nowrap"><?= fecha_ar($sv['fecha']) ?></td>
                <td class="nowrap"><?= e($sv['servicio'] ?? '—') ?><?= $sv['dia_menu'] ? ' <span class="muted small">día ' . (int)$sv['dia_menu'] . '</span>' : '' ?>
                    <?= $sv['anulado'] ? ' <span class="badge b-muted">Anulado</span>' : '' ?></td>
                <td>
                    <details>
                        <summary><?= e($sv['menu_nombre']) ?></summary>
                        <?php if ($sv['contingentes']): ?><p class="small"><strong>Contingentes:</strong> <?= e($sv['contingentes']) ?></p><?php endif; ?>
                        <ul class="detalle">
                            <?php foreach ($detalle[$sv['id']] ?? [] as $d): ?>
                                <li><?= e($d['nombre']) ?>: <?= num($d['cantidad']) ?> <?= e($d['unidad']) ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if ($sv['observaciones'] !== ''): ?><p class="muted small"><?= e($sv['observaciones']) ?></p><?php endif; ?>
                    </details>
                </td>
                <td class="num"><?= (int)$sv['comensales'] ?></td>
                <td class="num"><?= dinero($sv['costo_total']) ?></td>
                <td><?= e($sv['usuario'] ?? '') ?></td>
                <td>
                    <?php if (es_admin() && !$sv['anulado']): ?>
                        <form method="post" data-confirm="¿Anular este servicio? La mercadería vuelve al stock.">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="anular">
                            <input type="hidden" name="id" value="<?= (int)$sv['id'] ?>">
                            <button class="btn-link">Anular</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/inc/footer.php'; ?>
