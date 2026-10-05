<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    csrf_check();
    $acc = $_POST['accion'] ?? '';

    if ($acc === 'guardar') {
        $id = (int)($_POST['id'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        $cat = trim($_POST['categoria'] ?? '');
        $unidad = in_array($_POST['unidad'] ?? '', UNIDADES, true) ? $_POST['unidad'] : 'unidad';
        $min = dec_in($_POST['stock_minimo'] ?? 0);
        $activo = isset($_POST['activo']) ? 1 : 0;

        if ($nombre === '') {
            flash('El nombre es obligatorio.', 'error');
            redirect($id ? "productos.php?editar=$id" : 'productos.php?nuevo=1');
        }
        try {
            $pdo->beginTransaction();
            if ($id) {
                $pdo->prepare('UPDATE productos SET nombre=?, categoria=?, unidad=?, stock_minimo=?, activo=? WHERE id=?')
                    ->execute([$nombre, $cat, $unidad, $min, $activo, $id]);
            } else {
                $pdo->prepare('INSERT INTO productos (nombre, categoria, unidad, stock_minimo, costo_promedio, activo) VALUES (?,?,?,?,?,?)')
                    ->execute([$nombre, $cat, $unidad, $min, dec_in($_POST['costo'] ?? 0), $activo]);
                $id = (int)$pdo->lastInsertId();
                $ini = dec_in($_POST['stock_inicial'] ?? 0);
                if ($ini != 0) mover_stock($pdo, $id, $ini, 'ajuste', null, null, 'Stock inicial');
            }
            $pdo->commit();
            flash('Producto guardado.');
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('Error: ' . $ex->getMessage(), 'error');
        }
        redirect('productos.php');
    }

    if ($acc === 'ajustar') {
        $id = (int)($_POST['id'] ?? 0);
        $modo = $_POST['modo'] ?? 'conteo';
        $motivo = trim($_POST['motivo'] ?? '');
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare('SELECT stock FROM productos WHERE id=? FOR UPDATE');
            $st->execute([$id]);
            $actual = $st->fetchColumn();
            if ($actual === false) throw new RuntimeException('Producto inexistente.');

            if ($modo === 'baja') {
                $diff = -abs(dec_in($_POST['cantidad'] ?? 0));
                $nota = 'Baja: ' . ($motivo ?: 'merma');
            } else {
                $diff = round(dec_in($_POST['stock_real'] ?? 0) - (float)$actual, 3);
                $nota = 'Conteo físico' . ($motivo ? ': ' . $motivo : '');
            }
            if ($diff != 0) mover_stock($pdo, $id, $diff, 'ajuste', null, null, $nota);
            $pdo->commit();
            flash($diff != 0 ? 'Stock ajustado (' . ($diff > 0 ? '+' : '') . num($diff) . ').' : 'El stock ya coincidía, no se hicieron cambios.');
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash($ex->getMessage(), 'error');
        }
        redirect('productos.php');
    }
}

$titulo = 'Stock';
$editar = (int)($_GET['editar'] ?? 0);
$ajustar = (int)($_GET['ajustar'] ?? 0);
$nuevo = isset($_GET['nuevo']);
$categorias = $pdo->query("SELECT DISTINCT categoria FROM productos WHERE categoria <> '' ORDER BY categoria")->fetchAll(PDO::FETCH_COLUMN);

require __DIR__ . '/inc/header.php';

/* ----- Formulario alta / edición ----- */
if (($editar || $nuevo) && es_admin()):
    $p = ['id' => 0, 'nombre' => '', 'categoria' => '', 'unidad' => 'kg', 'stock_minimo' => 0, 'activo' => 1];
    if ($editar) {
        $st = $pdo->prepare('SELECT * FROM productos WHERE id=?');
        $st->execute([$editar]);
        $p = $st->fetch() ?: $p;
    }
?>
<h1><?= $p['id'] ? 'Editar producto' : 'Nuevo producto' ?></h1>
<form method="post" class="card">
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="guardar">
    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
    <div class="grid-2">
        <label>Nombre<input name="nombre" required value="<?= e($p['nombre']) ?>" placeholder="Ej: Pechuga de pollo"></label>
        <label>Categoría<input name="categoria" list="lista-cat" value="<?= e($p['categoria']) ?>" placeholder="Ej: Carnes"></label>
    </div>
    <datalist id="lista-cat"><?php foreach ($categorias as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
    <div class="grid-3">
        <label>Unidad de medida
            <select name="unidad">
                <?php foreach (UNIDADES as $u): ?><option <?= $p['unidad'] === $u ? 'selected' : '' ?>><?= $u ?></option><?php endforeach; ?>
            </select>
        </label>
        <label>Stock mínimo (alerta)<input name="stock_minimo" inputmode="decimal" value="<?= num_input($p['stock_minimo']) ?>"></label>
        <?php if (!$p['id']): ?>
            <label>Stock inicial<input name="stock_inicial" inputmode="decimal" placeholder="0"></label>
            <label>Costo unitario $<input name="costo" inputmode="decimal" placeholder="0,00"></label>
        <?php endif; ?>
    </div>
    <?php if ($p['id']): ?><p class="muted small">El stock no se edita acá: se mueve con compras, servicios o «Ajustar».</p><?php endif; ?>
    <label class="check"><input type="checkbox" name="activo" value="1" <?= $p['activo'] ? 'checked' : '' ?>> Producto activo</label>
    <div class="acciones"><span class="spacer"></span><a class="btn sec" href="productos.php">Cancelar</a><button class="btn">Guardar</button></div>
</form>

<?php
/* ----- Ajuste de inventario ----- */
elseif ($ajustar && es_admin()):
    $st = $pdo->prepare('SELECT * FROM productos WHERE id=?');
    $st->execute([$ajustar]);
    $p = $st->fetch();
    if (!$p) { echo '<p>Producto inexistente.</p>'; require __DIR__ . '/inc/footer.php'; exit; }
?>
<h1>Ajustar stock: <?= e($p['nombre']) ?></h1>
<p>Stock según sistema: <strong><?= num($p['stock']) ?> <?= e($p['unidad']) ?></strong></p>
<div class="grid-2">
    <form method="post" class="card">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="ajustar"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="modo" value="conteo">
        <h2>Conteo físico</h2>
        <p class="muted small">Contaste lo que hay en depósito: poné el número real y el sistema corrige la diferencia.</p>
        <label>Stock real (<?= e($p['unidad']) ?>)<input name="stock_real" inputmode="decimal" required value="<?= num_input($p['stock']) ?>"></label>
        <label>Nota<input name="motivo" placeholder="Ej: inventario mensual"></label>
        <button class="btn">Guardar conteo</button>
    </form>
    <form method="post" class="card">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="ajustar"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="modo" value="baja">
        <h2>Dar de baja (merma)</h2>
        <p class="muted small">Mercadería vencida, rota o descartada que no se usó en un menú.</p>
        <label>Cantidad a descontar (<?= e($p['unidad']) ?>)<input name="cantidad" inputmode="decimal" required></label>
        <label>Motivo
            <select name="motivo"><option>Vencimiento</option><option>Rotura / en mal estado</option><option>Consumo del personal</option><option>Otro</option></select>
        </label>
        <button class="btn peligro">Dar de baja</button>
    </form>
</div>
<p><a href="productos.php">← Volver al stock</a></p>

<?php
/* ----- Listado ----- */
else:
    $q = trim($_GET['q'] ?? '');
    $cat = trim($_GET['cat'] ?? '');
    $bajo = !empty($_GET['bajo']);
    $inactivos = !empty($_GET['inactivos']);
    $where = ['1=1'];
    $params = [];
    if ($q !== '') { $where[] = 'nombre LIKE ?'; $params[] = "%$q%"; }
    if ($cat !== '') { $where[] = 'categoria = ?'; $params[] = $cat; }
    if ($bajo) $where[] = 'stock <= stock_minimo';
    if (!$inactivos) $where[] = 'activo = 1';
    $st = $pdo->prepare('SELECT * FROM productos WHERE ' . implode(' AND ', $where) . ' ORDER BY categoria, nombre');
    $st->execute($params);
    $productos = $st->fetchAll();
    $valorTotal = 0;
?>
<div class="titulo-acciones">
    <h1>Stock de materia prima</h1>
    <?php if (es_admin()): ?><a class="btn" href="productos.php?nuevo=1">+ Nuevo producto</a><?php endif; ?>
</div>
<form method="get" class="filtros card">
    <input name="q" value="<?= e($q) ?>" placeholder="Buscar producto...">
    <select name="cat"><option value="">Todas las categorías</option><?php foreach ($categorias as $c): ?><option <?= $cat === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select>
    <label class="check"><input type="checkbox" name="bajo" value="1" <?= $bajo ? 'checked' : '' ?>> Solo bajo mínimo</label>
    <label class="check"><input type="checkbox" name="inactivos" value="1" <?= $inactivos ? 'checked' : '' ?>> Ver inactivos</label>
    <button class="btn sec">Filtrar</button>
</form>
<div class="card">
    <div class="scroll">
    <table class="tabla">
        <thead><tr><th>Producto</th><th>Categoría</th><th class="num">Stock</th><th class="num">Mínimo</th><th class="num">Costo prom.</th><th class="num">Valor</th><?php if (es_admin()): ?><th></th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($productos as $p):
            $valor = max((float)$p['stock'], 0) * (float)$p['costo_promedio'];
            $valorTotal += $valor;
            $cls = $p['stock'] < 0 ? 'b-err' : ($p['stock'] <= $p['stock_minimo'] ? 'b-warn' : 'b-ok'); ?>
            <tr class="<?= $p['activo'] ? '' : 'anulado' ?>">
                <td><strong><?= e($p['nombre']) ?></strong></td>
                <td><?= e($p['categoria']) ?></td>
                <td class="num"><span class="badge <?= $cls ?>"><?= num($p['stock']) ?> <?= e($p['unidad']) ?></span></td>
                <td class="num"><?= num($p['stock_minimo']) ?></td>
                <td class="num"><?= dinero($p['costo_promedio']) ?></td>
                <td class="num"><?= dinero($valor) ?></td>
                <?php if (es_admin()): ?>
                <td class="acciones-fila">
                    <a href="compras.php?producto=<?= (int)$p['id'] ?>">Comprar</a>
                    <a href="productos.php?ajustar=<?= (int)$p['id'] ?>">Ajustar</a>
                    <a href="productos.php?editar=<?= (int)$p['id'] ?>">Editar</a>
                    <a href="movimientos.php?producto=<?= (int)$p['id'] ?>&desde=<?= date('Y-m-d', strtotime('-90 days')) ?>">Historial</a>
                </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        <?php if (!$productos): ?><tr><td colspan="7" class="muted">No hay productos con ese filtro.</td></tr><?php endif; ?>
        </tbody>
        <tfoot><tr><th colspan="5" class="num">Valor total del stock</th><th class="num"><?= dinero($valorTotal) ?></th><?php if (es_admin()): ?><th></th><?php endif; ?></tr></tfoot>
    </table>
    </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/inc/footer.php'; ?>
