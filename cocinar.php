<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();
$pdo = db();

/* ================== ACCIONES ================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $acc = $_POST['accion'] ?? '';

    // Confirmar el servicio y descontar stock
    if ($acc === 'confirmar') {
        $lineas = leer_lineas($_POST);
        $fecha = valid_fecha($_POST['fecha'] ?? '') ?: date('Y-m-d');
        $obs = trim($_POST['observaciones'] ?? '');
        $forzar = es_admin() && !empty($_POST['forzar']);
        $volver = 'cocinar.php?' . http_build_query([
            'fecha' => $fecha,
            'observaciones' => $obs,
            'menu_id' => array_column($lineas, 'menu_id'),
            'comensales' => array_column($lineas, 'comensales'),
        ]);

        try {
            $pdo->beginTransaction();

            // Bloquea los productos involucrados para que dos personas no descuenten a la vez
            $ids = array_values(array_filter(array_map(fn($l) => (int)$l['menu_id'], $lineas)));
            if ($ids) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $lock = $pdo->prepare("SELECT id FROM productos WHERE id IN (SELECT producto_id FROM menu_items WHERE menu_id IN ($in)) FOR UPDATE");
                $lock->execute($ids);
                $lock->fetchAll();
            }

            // Se recalcula en el servidor con el stock real del momento
            $calc = calcular_consumo($pdo, $lineas);
            if (!$calc['lineas']) {
                throw new RuntimeException('Elegí al menos un menú y la cantidad de comensales.');
            }
            if ($calc['faltantes'] > 0 && !$forzar) {
                throw new RuntimeException('No hay stock suficiente de ' . $calc['faltantes'] . ' producto(s). Revisá el detalle en rojo.');
            }

            $insS = $pdo->prepare('INSERT INTO servicios (fecha, menu_id, menu_nombre, comensales, costo_total, observaciones, usuario_id) VALUES (?,?,?,?,?,?,?)');
            $insI = $pdo->prepare('INSERT INTO servicio_items (servicio_id, producto_id, cantidad, costo_unitario) VALUES (?,?,?,?)');

            foreach ($calc['lineas'] as $l) {
                $insS->execute([$fecha, $l['menu_id'], $l['menu_nombre'], $l['comensales'], round($l['costo'], 2), mb_substr($obs, 0, 255), usuario()['id']]);
                $sid = (int)$pdo->lastInsertId();
                foreach ($l['items'] as $it) {
                    if ($it['cantidad'] <= 0) continue;
                    $insI->execute([$sid, $it['producto_id'], $it['cantidad'], $it['costo_unitario']]);
                    mover_stock($pdo, $it['producto_id'], -$it['cantidad'], 'consumo', 'servicio', $sid, $l['menu_nombre'] . ' × ' . $l['comensales'] . ' pax');
                }
            }
            $pdo->commit();
            flash('Listo: se descontó el stock para ' . $calc['comensales'] . ' comensales (' . count($calc['lineas']) . ' menú/s).');
            redirect('cocinar.php');
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
            $s = $st->fetch();
            if (!$s) throw new RuntimeException('El servicio no existe o ya fue anulado.');
            $it = $pdo->prepare('SELECT producto_id, cantidad FROM servicio_items WHERE servicio_id = ?');
            $it->execute([$sid]);
            foreach ($it->fetchAll() as $r) {
                mover_stock($pdo, (int)$r['producto_id'], (float)$r['cantidad'], 'anulacion', 'servicio', $sid, 'Anulación: ' . $s['menu_nombre']);
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
$menus = $pdo->query('SELECT id, nombre, tipo FROM menus WHERE activo = 1 ORDER BY FIELD(tipo, \'Desayuno\', \'Almuerzo\', \'Merienda\', \'Cena\'), tipo, nombre')->fetchAll();
$lineas = leer_lineas($_GET);
$fecha = valid_fecha($_GET['fecha'] ?? '') ?: date('Y-m-d');
$obs = trim($_GET['observaciones'] ?? '');

$calc = null;
if ($lineas) {
    $calc = calcular_consumo($pdo, $lineas);
    if (!$calc['lineas']) $calc = null;
}
if (!$lineas) $lineas = [['menu_id' => 0, 'comensales' => '']];

function opciones_menus(array $menus, int $sel): string
{
    $h = '<option value="">— Elegir menú —</option>';
    $tipo = null;
    foreach ($menus as $m) {
        if ($m['tipo'] !== $tipo) {
            if ($tipo !== null) $h .= '</optgroup>';
            $tipo = $m['tipo'];
            $h .= '<optgroup label="' . e($tipo ?: 'Otros') . '">';
        }
        $h .= '<option value="' . (int)$m['id'] . '"' . ((int)$m['id'] === $sel ? ' selected' : '') . '>' . e($m['nombre']) . '</option>';
    }
    if ($tipo !== null) $h .= '</optgroup>';
    return $h;
}

// Últimos servicios con su detalle
$servicios = $pdo->query('SELECT s.*, u.nombre AS usuario FROM servicios s LEFT JOIN usuarios u ON u.id = s.usuario_id ORDER BY s.id DESC LIMIT 25')->fetchAll();
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
<h1>Cocinar / servir menú</h1>
<p class="muted">Elegí el menú y la cantidad de comensales. El sistema calcula la materia prima y, al confirmar, la descuenta del stock.</p>

<?php if (!$menus): ?>
    <div class="flash error">Todavía no hay menús cargados. <?= es_admin() ? '<a href="menus.php?nuevo=1">Crear el primero</a>' : 'Pedile al administrador que los cargue.' ?></div>
<?php else: ?>

<form method="get" class="card">
    <div class="grid-2">
        <label>Fecha<input type="date" name="fecha" value="<?= e($fecha) ?>"></label>
        <label>Observaciones<input name="observaciones" value="<?= e($obs) ?>" placeholder="Ej: grupo contingente, evento..."></label>
    </div>
    <table class="tabla tabla-form">
        <thead><tr><th>Menú</th><th style="width:130px">Comensales</th><th style="width:44px"></th></tr></thead>
        <tbody id="filas-menu">
        <?php foreach ($lineas as $l): ?>
            <tr>
                <td><select name="menu_id[]" required><?= opciones_menus($menus, (int)$l['menu_id']) ?></select></td>
                <td><input type="number" name="comensales[]" min="1" step="1" required value="<?= e($l['comensales'] ?: '') ?>" inputmode="numeric"></td>
                <td><button type="button" class="btn-x" data-del-row title="Quitar">✕</button></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <template id="tpl-menu">
        <tr>
            <td><select name="menu_id[]" required><?= opciones_menus($menus, 0) ?></select></td>
            <td><input type="number" name="comensales[]" min="1" step="1" required inputmode="numeric"></td>
            <td><button type="button" class="btn-x" data-del-row title="Quitar">✕</button></td>
        </tr>
    </template>
    <div class="acciones">
        <button type="button" class="btn sec" data-add-row="#filas-menu" data-template="tpl-menu">+ Otro menú en el mismo servicio</button>
        <button class="btn">Calcular materia prima</button>
    </div>
</form>

<?php if ($calc): ?>
<section class="card">
    <h2>Materia prima necesaria</h2>
    <div class="chips">
        <?php foreach ($calc['lineas'] as $l): ?>
            <span class="chip"><strong><?= e($l['menu_nombre']) ?></strong> × <?= (int)$l['comensales'] ?> pax · <?= dinero($l['costo']) ?></span>
        <?php endforeach; ?>
    </div>
    <?php foreach ($calc['sin_receta'] as $sr): ?>
        <div class="flash warn">El menú «<?= e($sr) ?>» no tiene ingredientes cargados: no va a descontar nada.</div>
    <?php endforeach; ?>

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
    <p class="total-linea">
        <?= (int)$calc['comensales'] ?> comensales · Costo estimado <strong><?= dinero($calc['costo_total']) ?></strong>
        <?php if ($calc['comensales']): ?>(<?= dinero($calc['costo_total'] / $calc['comensales']) ?> por persona)<?php endif; ?>
    </p>

    <form method="post" data-once data-confirm="¿Confirmás? Se va a descontar esta mercadería del stock.">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="confirmar">
        <input type="hidden" name="fecha" value="<?= e($fecha) ?>">
        <input type="hidden" name="observaciones" value="<?= e($obs) ?>">
        <?php foreach ($calc['lineas'] as $l): ?>
            <input type="hidden" name="menu_id[]" value="<?= (int)$l['menu_id'] ?>">
            <input type="hidden" name="comensales[]" value="<?= (int)$l['comensales'] ?>">
        <?php endforeach; ?>

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
<?php endif; ?>

<section class="card">
    <h2>Últimos servicios registrados</h2>
    <?php if (!$servicios): ?>
        <p class="muted">Todavía no se registró ningún servicio.</p>
    <?php else: ?>
    <div class="scroll">
    <table class="tabla">
        <thead><tr><th>Fecha</th><th>Menú</th><th class="num">Pax</th><th class="num">Costo</th><th>Cargó</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($servicios as $s): ?>
            <tr id="s<?= (int)$s['id'] ?>" class="<?= $s['anulado'] ? 'anulado' : '' ?>">
                <td><?= fecha_ar($s['fecha']) ?></td>
                <td>
                    <details>
                        <summary><?= e($s['menu_nombre']) ?><?= $s['anulado'] ? ' <span class="badge b-muted">Anulado</span>' : '' ?></summary>
                        <ul class="detalle">
                            <?php foreach ($detalle[$s['id']] ?? [] as $d): ?>
                                <li><?= e($d['nombre']) ?>: <?= num($d['cantidad']) ?> <?= e($d['unidad']) ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if ($s['observaciones'] !== ''): ?><p class="muted"><?= e($s['observaciones']) ?></p><?php endif; ?>
                    </details>
                </td>
                <td class="num"><?= (int)$s['comensales'] ?></td>
                <td class="num"><?= dinero($s['costo_total']) ?></td>
                <td><?= e($s['usuario'] ?? '') ?></td>
                <td>
                    <?php if (es_admin() && !$s['anulado']): ?>
                        <form method="post" data-confirm="¿Anular este servicio? La mercadería vuelve al stock.">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="anular">
                            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
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
