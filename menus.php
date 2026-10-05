<?php
require __DIR__ . '/inc/bootstrap.php';
require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $acc = $_POST['accion'] ?? '';

    if ($acc === 'guardar') {
        $id = (int)($_POST['id'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        $tipo = in_array($_POST['tipo'] ?? '', TIPOS_MENU, true) ? $_POST['tipo'] : 'Otro';
        $desc = trim($_POST['descripcion'] ?? '');
        $activo = isset($_POST['activo']) ? 1 : 0;

        // Ingredientes (si un producto se repite, se suman las cantidades)
        $receta = [];
        foreach ((array)($_POST['producto_id'] ?? []) as $i => $pid) {
            $pid = (int)$pid;
            $cant = dec_in($_POST['cantidad'][$i] ?? 0);
            if ($pid > 0 && $cant > 0) $receta[$pid] = ($receta[$pid] ?? 0) + $cant;
        }

        if ($nombre === '') {
            flash('El nombre del menú es obligatorio.', 'error');
            redirect($id ? "menus.php?editar=$id" : 'menus.php?nuevo=1');
        }

        try {
            $pdo->beginTransaction();
            if ($id) {
                $pdo->prepare('UPDATE menus SET nombre=?, tipo=?, descripcion=?, activo=? WHERE id=?')->execute([$nombre, $tipo, $desc, $activo, $id]);
                $pdo->prepare('DELETE FROM menu_items WHERE menu_id=?')->execute([$id]);
            } else {
                $pdo->prepare('INSERT INTO menus (nombre, tipo, descripcion, activo) VALUES (?,?,?,?)')->execute([$nombre, $tipo, $desc, $activo]);
                $id = (int)$pdo->lastInsertId();
            }
            $ins = $pdo->prepare('INSERT INTO menu_items (menu_id, producto_id, cantidad_por_persona) VALUES (?,?,?)');
            foreach ($receta as $pid => $cant) $ins->execute([$id, $pid, round($cant, 4)]);
            $pdo->commit();
            flash('Menú guardado.' . ($receta ? '' : ' Ojo: no tiene ingredientes cargados.'), $receta ? 'ok' : 'warn');
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('Error al guardar: ' . $ex->getMessage(), 'error');
        }
        redirect('menus.php');
    }

    if ($acc === 'duplicar') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO menus (nombre, tipo, descripcion, activo) SELECT CONCAT(nombre, \' (copia)\'), tipo, descripcion, activo FROM menus WHERE id=?')->execute([$id]);
        $nuevo = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO menu_items (menu_id, producto_id, cantidad_por_persona) SELECT ?, producto_id, cantidad_por_persona FROM menu_items WHERE menu_id=?')->execute([$nuevo, $id]);
        $pdo->commit();
        flash('Menú duplicado. Editá la copia.');
        redirect("menus.php?editar=$nuevo");
    }

    if ($acc === 'eliminar') {
        $pdo->prepare('DELETE FROM menus WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('Menú eliminado. El historial de servicios se conserva.');
        redirect('menus.php');
    }
}

$editar = isset($_GET['editar']) ? (int)$_GET['editar'] : 0;
$nuevo = isset($_GET['nuevo']);
$menu = null;
$receta = [];
if ($editar) {
    $st = $pdo->prepare('SELECT * FROM menus WHERE id=?');
    $st->execute([$editar]);
    $menu = $st->fetch();
    if (!$menu) redirect('menus.php');
    $st = $pdo->prepare('SELECT mi.*, p.unidad FROM menu_items mi JOIN productos p ON p.id = mi.producto_id WHERE menu_id=? ORDER BY p.nombre');
    $st->execute([$editar]);
    $receta = $st->fetchAll();
}

$titulo = 'Menús';
require __DIR__ . '/inc/header.php';

if ($editar || $nuevo):
    if (!$receta) $receta = [['producto_id' => 0, 'cantidad_por_persona' => '', 'unidad' => '']];
?>
<h1><?= $menu ? 'Editar menú' : 'Nuevo menú' ?></h1>
<form method="post" class="card">
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="guardar">
    <input type="hidden" name="id" value="<?= (int)($menu['id'] ?? 0) ?>">
    <div class="grid-2">
        <label>Nombre del menú<input name="nombre" required value="<?= e($menu['nombre'] ?? '') ?>" placeholder="Ej: Pollo al horno con papas"></label>
        <label>Tipo
            <select name="tipo">
                <?php foreach (TIPOS_MENU as $t): ?>
                    <option <?= ($menu['tipo'] ?? '') === $t ? 'selected' : '' ?>><?= $t ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>
    <label>Descripción (opcional)<input name="descripcion" value="<?= e($menu['descripcion'] ?? '') ?>"></label>
    <label class="check"><input type="checkbox" name="activo" value="1" <?= ($menu['activo'] ?? 1) ? 'checked' : '' ?>> Menú activo (aparece en «Cocinar»)</label>

    <h2>Ingredientes para UNA persona</h2>
    <p class="muted">Poné la cantidad en la unidad del producto. Ej: 250 g de pollo → <strong>0,25</strong> kg. 2 huevos → <strong>2</strong> unidad.</p>
    <table class="tabla tabla-form">
        <thead><tr><th>Producto</th><th style="width:150px">Cantidad x persona</th><th style="width:70px">Unidad</th><th style="width:44px"></th></tr></thead>
        <tbody id="filas-receta">
        <?php foreach ($receta as $r): ?>
            <tr>
                <td><select name="producto_id[]" class="sel-producto"><?= opciones_productos($pdo, $r['producto_id']) ?></select></td>
                <td><input name="cantidad[]" inputmode="decimal" value="<?= $r['cantidad_por_persona'] !== '' ? num_input($r['cantidad_por_persona']) : '' ?>" placeholder="0,25"></td>
                <td class="unidad muted"><?= e($r['unidad']) ?></td>
                <td><button type="button" class="btn-x" data-del-row title="Quitar">✕</button></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <template id="tpl-receta">
        <tr>
            <td><select name="producto_id[]" class="sel-producto"><?= opciones_productos($pdo) ?></select></td>
            <td><input name="cantidad[]" inputmode="decimal" placeholder="0,25"></td>
            <td class="unidad muted"></td>
            <td><button type="button" class="btn-x" data-del-row title="Quitar">✕</button></td>
        </tr>
    </template>
    <div class="acciones">
        <button type="button" class="btn sec" data-add-row="#filas-receta" data-template="tpl-receta">+ Agregar ingrediente</button>
        <span class="spacer"></span>
        <a class="btn sec" href="menus.php">Cancelar</a>
        <button class="btn">Guardar menú</button>
    </div>
</form>

<?php else:
    $lista = $pdo->query(
        'SELECT m.*, COUNT(mi.id) AS ingredientes, COALESCE(SUM(mi.cantidad_por_persona * p.costo_promedio), 0) AS costo_pp
         FROM menus m
         LEFT JOIN menu_items mi ON mi.menu_id = m.id
         LEFT JOIN productos p ON p.id = mi.producto_id
         GROUP BY m.id ORDER BY m.activo DESC, m.tipo, m.nombre'
    )->fetchAll();
?>
<div class="titulo-acciones">
    <h1>Menús fijos</h1>
    <a class="btn" href="menus.php?nuevo=1">+ Nuevo menú</a>
</div>
<div class="card">
    <?php if (!$lista): ?>
        <p class="muted">No hay menús. Creá el primero con su receta por persona.</p>
    <?php else: ?>
    <div class="scroll">
    <table class="tabla">
        <thead><tr><th>Menú</th><th>Tipo</th><th class="num">Ingredientes</th><th class="num">Costo x persona</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($lista as $m): ?>
            <tr class="<?= $m['activo'] ? '' : 'anulado' ?>">
                <td><strong><?= e($m['nombre']) ?></strong><?= $m['activo'] ? '' : ' <span class="badge b-muted">Inactivo</span>' ?>
                    <?php if ($m['descripcion']): ?><div class="muted small"><?= e($m['descripcion']) ?></div><?php endif; ?></td>
                <td><?= e($m['tipo']) ?></td>
                <td class="num"><?= (int)$m['ingredientes'] ?: '<span class="badge b-warn">Sin receta</span>' ?></td>
                <td class="num"><?= dinero($m['costo_pp']) ?></td>
                <td class="acciones-fila">
                    <a href="menus.php?editar=<?= (int)$m['id'] ?>">Editar</a>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="duplicar"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="btn-link">Duplicar</button></form>
                    <form method="post" data-confirm="¿Eliminar este menú? (Si solo querés ocultarlo, editalo y desactivalo)"><?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="btn-link peligro">Eliminar</button></form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/inc/footer.php'; ?>
