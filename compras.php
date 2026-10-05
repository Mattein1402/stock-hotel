<?php
require __DIR__ . '/inc/bootstrap.php';
require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $acc = $_POST['accion'] ?? '';

    if ($acc === 'guardar') {
        $fecha = valid_fecha($_POST['fecha'] ?? '') ?: date('Y-m-d');
        $prov = trim($_POST['proveedor'] ?? '');
        $comp = trim($_POST['comprobante'] ?? '');
        $obs = trim($_POST['observaciones'] ?? '');

        $items = [];
        foreach ((array)($_POST['producto_id'] ?? []) as $i => $pid) {
            $pid = (int)$pid;
            $cant = dec_in($_POST['cantidad'][$i] ?? 0);
            $costo = dec_in($_POST['costo'][$i] ?? 0);
            if ($pid > 0 && $cant > 0) $items[] = [$pid, $cant, $costo];
        }
        if (!$items) {
            flash('Cargá al menos un producto con cantidad.', 'error');
            redirect('compras.php');
        }

        try {
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO compras (fecha, proveedor, comprobante, observaciones, usuario_id) VALUES (?,?,?,?,?)')
                ->execute([$fecha, $prov, $comp, $obs, usuario()['id']]);
            $cid = (int)$pdo->lastInsertId();

            $lock = $pdo->prepare('SELECT stock, costo_promedio FROM productos WHERE id = ? FOR UPDATE');
            $insIt = $pdo->prepare('INSERT INTO compra_items (compra_id, producto_id, cantidad, costo_unitario) VALUES (?,?,?,?)');
            $updCosto = $pdo->prepare('UPDATE productos SET costo_promedio = ? WHERE id = ?');
            $total = 0.0;

            foreach ($items as [$pid, $cant, $costo]) {
                $lock->execute([$pid]);
                $p = $lock->fetch();
                if (!$p) continue;

                // Costo promedio ponderado
                if ($costo > 0) {
                    $base = max((float)$p['stock'], 0);
                    $prom = (float)$p['costo_promedio'];
                    $nuevo = ($prom <= 0 || $base <= 0) ? $costo : ($base * $prom + $cant * $costo) / ($base + $cant);
                    $updCosto->execute([round($nuevo, 2), $pid]);
                }
                $insIt->execute([$cid, $pid, $cant, $costo]);
                mover_stock($pdo, $pid, $cant, 'compra', 'compra', $cid, $prov !== '' ? 'Compra a ' . $prov : 'Compra');
                $total += $cant * $costo;
            }
            $pdo->prepare('UPDATE compras SET total = ? WHERE id = ?')->execute([round($total, 2), $cid]);
            $pdo->commit();
            flash('Compra #' . $cid . ' registrada. Se sumó al stock.');
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('Error al guardar: ' . $ex->getMessage(), 'error');
        }
        redirect('compras.php');
    }

    if ($acc === 'anular') {
        $cid = (int)($_POST['id'] ?? 0);
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare('SELECT * FROM compras WHERE id = ? AND anulada = 0 FOR UPDATE');
            $st->execute([$cid]);
            if (!$st->fetch()) throw new RuntimeException('La compra no existe o ya está anulada.');
            $it = $pdo->prepare('SELECT producto_id, cantidad FROM compra_items WHERE compra_id = ?');
            $it->execute([$cid]);
            foreach ($it->fetchAll() as $r) {
                mover_stock($pdo, (int)$r['producto_id'], -(float)$r['cantidad'], 'anulacion', 'compra', $cid, 'Anulación compra #' . $cid);
            }
            $pdo->prepare('UPDATE compras SET anulada = 1 WHERE id = ?')->execute([$cid]);
            $pdo->commit();
            flash('Compra #' . $cid . ' anulada. Se restó del stock.');
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash($ex->getMessage(), 'error');
        }
        redirect('compras.php');
    }
}

/* ----- Ver detalle de una compra ----- */
$ver = (int)($_GET['ver'] ?? 0);
$titulo = 'Compras';

if ($ver) {
    $st = $pdo->prepare('SELECT c.*, u.nombre AS usuario FROM compras c LEFT JOIN usuarios u ON u.id = c.usuario_id WHERE c.id = ?');
    $st->execute([$ver]);
    $c = $st->fetch();
    if (!$c) redirect('compras.php');
    $st = $pdo->prepare('SELECT ci.*, p.nombre, p.unidad FROM compra_items ci JOIN productos p ON p.id = ci.producto_id WHERE ci.compra_id = ? ORDER BY p.nombre');
    $st->execute([$ver]);
    $items = $st->fetchAll();
    require __DIR__ . '/inc/header.php';
    ?>
    <div class="titulo-acciones">
        <h1>Compra #<?= (int)$c['id'] ?> <?= $c['anulada'] ? '<span class="badge b-muted">Anulada</span>' : '' ?></h1>
        <a class="btn sec" href="compras.php">← Volver</a>
    </div>
    <div class="card">
        <p><strong>Fecha:</strong> <?= fecha_ar($c['fecha']) ?> · <strong>Proveedor:</strong> <?= e($c['proveedor'] ?: '—') ?> · <strong>Comprobante:</strong> <?= e($c['comprobante'] ?: '—') ?> · <strong>Cargó:</strong> <?= e($c['usuario'] ?? '') ?></p>
        <?php if ($c['observaciones']): ?><p class="muted"><?= e($c['observaciones']) ?></p><?php endif; ?>
        <div class="scroll">
        <table class="tabla">
            <thead><tr><th>Producto</th><th class="num">Cantidad</th><th class="num">Costo unit.</th><th class="num">Subtotal</th></tr></thead>
            <tbody>
            <?php foreach ($items as $i): ?>
                <tr><td><?= e($i['nombre']) ?></td><td class="num"><?= num($i['cantidad']) ?> <?= e($i['unidad']) ?></td><td class="num"><?= dinero($i['costo_unitario']) ?></td><td class="num"><?= dinero($i['cantidad'] * $i['costo_unitario']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><th colspan="3" class="num">Total</th><th class="num"><?= dinero($c['total']) ?></th></tr></tfoot>
        </table>
        </div>
        <?php if (!$c['anulada']): ?>
            <form method="post" data-confirm="¿Anular esta compra? Se va a restar del stock lo que se había sumado.">
                <?= csrf_field() ?><input type="hidden" name="accion" value="anular"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <button class="btn peligro">Anular compra</button>
            </form>
        <?php endif; ?>
    </div>
    <?php
    require __DIR__ . '/inc/footer.php';
    exit;
}

/* ----- Formulario + listado ----- */
$compras = $pdo->query('SELECT c.*, (SELECT COUNT(*) FROM compra_items ci WHERE ci.compra_id = c.id) AS items FROM compras c ORDER BY c.fecha DESC, c.id DESC LIMIT 50')->fetchAll();
$proveedores = $pdo->query("SELECT DISTINCT proveedor FROM compras WHERE proveedor <> '' ORDER BY proveedor")->fetchAll(PDO::FETCH_COLUMN);
$preProducto = (int)($_GET['producto'] ?? 0);

require __DIR__ . '/inc/header.php';
?>
<h1>Ingreso de compras</h1>
<form method="post" class="card" data-once>
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="guardar">
    <div class="grid-3">
        <label>Fecha<input type="date" name="fecha" value="<?= date('Y-m-d') ?>" required></label>
        <label>Proveedor<input name="proveedor" list="lista-prov" placeholder="Ej: Distribuidora X"></label>
        <label>N° factura / remito<input name="comprobante"></label>
    </div>
    <datalist id="lista-prov"><?php foreach ($proveedores as $p): ?><option value="<?= e($p) ?>"><?php endforeach; ?></datalist>

    <div class="scroll">
    <table class="tabla tabla-form tabla-compra">
        <thead><tr><th>Producto</th><th style="width:120px">Cantidad</th><th style="width:60px"></th><th style="width:140px">Costo unitario $</th><th class="num" style="width:120px">Subtotal</th><th style="width:44px"></th></tr></thead>
        <tbody id="filas-compra">
            <tr>
                <td><select name="producto_id[]" class="sel-producto" required><?= opciones_productos($pdo, $preProducto) ?></select></td>
                <td><input name="cantidad[]" inputmode="decimal" required placeholder="0"></td>
                <td class="unidad muted"></td>
                <td><input name="costo[]" inputmode="decimal" placeholder="0,00"></td>
                <td class="num subtotal"></td>
                <td><button type="button" class="btn-x" data-del-row title="Quitar">✕</button></td>
            </tr>
        </tbody>
        <tfoot><tr><th colspan="4" class="num">Total</th><th class="num total">$ 0,00</th><th></th></tr></tfoot>
    </table>
    </div>
    <template id="tpl-compra">
        <tr>
            <td><select name="producto_id[]" class="sel-producto" required><?= opciones_productos($pdo) ?></select></td>
            <td><input name="cantidad[]" inputmode="decimal" required placeholder="0"></td>
            <td class="unidad muted"></td>
            <td><input name="costo[]" inputmode="decimal" placeholder="0,00"></td>
            <td class="num subtotal"></td>
            <td><button type="button" class="btn-x" data-del-row title="Quitar">✕</button></td>
        </tr>
    </template>
    <label>Observaciones<input name="observaciones"></label>
    <div class="acciones">
        <button type="button" class="btn sec" data-add-row="#filas-compra" data-template="tpl-compra">+ Agregar producto</button>
        <span class="spacer"></span>
        <a class="btn sec" href="productos.php?nuevo=1">¿Producto nuevo? Crealo acá</a>
        <button class="btn">Guardar compra y sumar al stock</button>
    </div>
</form>

<section class="card">
    <h2>Últimas compras</h2>
    <?php if (!$compras): ?><p class="muted">Todavía no hay compras cargadas.</p><?php else: ?>
    <div class="scroll">
    <table class="tabla">
        <thead><tr><th>#</th><th>Fecha</th><th>Proveedor</th><th>Comprobante</th><th class="num">Ítems</th><th class="num">Total</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($compras as $c): ?>
            <tr class="<?= $c['anulada'] ? 'anulado' : '' ?>">
                <td><?= (int)$c['id'] ?></td>
                <td><?= fecha_ar($c['fecha']) ?></td>
                <td><?= e($c['proveedor']) ?><?= $c['anulada'] ? ' <span class="badge b-muted">Anulada</span>' : '' ?></td>
                <td><?= e($c['comprobante']) ?></td>
                <td class="num"><?= (int)$c['items'] ?></td>
                <td class="num"><?= dinero($c['total']) ?></td>
                <td><a href="compras.php?ver=<?= (int)$c['id'] ?>">Ver</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/inc/footer.php'; ?>
