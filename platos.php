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
        $cat = in_array($_POST['categoria'] ?? '', CATEGORIAS_PLATO, true) ? $_POST['categoria'] : 'Otro';
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
            flash('El nombre del plato es obligatorio.', 'error');
            redirect($id ? "platos.php?editar=$id" : 'platos.php?nuevo=1');
        }
        try {
            $pdo->beginTransaction();
            if ($id) {
                $pdo->prepare('UPDATE platos SET nombre=?, categoria=?, descripcion=?, activo=? WHERE id=?')->execute([$nombre, $cat, mb_substr($desc, 0, 255), $activo, $id]);
                $pdo->prepare('DELETE FROM plato_items WHERE plato_id=?')->execute([$id]);
            } else {
                $pdo->prepare('INSERT INTO platos (nombre, categoria, descripcion, activo) VALUES (?,?,?,?)')->execute([$nombre, $cat, mb_substr($desc, 0, 255), $activo]);
                $id = (int)$pdo->lastInsertId();
            }
            $ins = $pdo->prepare('INSERT INTO plato_items (plato_id, producto_id, cantidad_por_persona) VALUES (?,?,?)');
            foreach ($receta as $pid => $cant) $ins->execute([$id, $pid, round($cant, 4)]);
            $pdo->commit();
            flash('Plato «' . $nombre . '» guardado.' . ($receta ? '' : ' Ojo: no tiene ingredientes cargados.'), $receta ? 'ok' : 'warn');
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('Error al guardar: ' . $ex->getMessage(), 'error');
        }
        $volver = $_POST['volver'] ?? '';
        redirect($volver === 'sin_receta' ? 'platos.php?sin_receta=1' : 'platos.php');
    }

    if ($acc === 'duplicar') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO platos (nombre, categoria, descripcion, activo) SELECT CONCAT(nombre, ' (copia)'), categoria, descripcion, activo FROM platos WHERE id=?")->execute([$id]);
        $nuevo = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO plato_items (plato_id, producto_id, cantidad_por_persona) SELECT ?, producto_id, cantidad_por_persona FROM plato_items WHERE plato_id=?')->execute([$nuevo, $id]);
        $pdo->commit();
        flash('Plato duplicado. Editá la copia.');
        redirect("platos.php?editar=$nuevo");
    }

    if ($acc === 'eliminar') {
        $pdo->prepare('DELETE FROM platos WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('Plato eliminado (también se quitó del menú). El historial de servicios se conserva.');
        redirect('platos.php');
    }
}

$editar = isset($_GET['editar']) ? (int)$_GET['editar'] : 0;
$nuevo = isset($_GET['nuevo']);

/** Dónde se usa cada plato en el ciclo: "Día 1 Almuerzo, Día 8 Almuerzo" */
$usos = [];
foreach ($pdo->query('SELECT plato_id, dia, servicio FROM menu_ciclo ORDER BY dia, FIELD(servicio, \'Desayuno\', \'Almuerzo\', \'Merienda\', \'Cena\')') as $r) {
    $usos[$r['plato_id']][] = ($r['dia'] ? 'D' . $r['dia'] : 'Todos') . ' ' . mb_substr($r['servicio'], 0, 3) . '.';
}

$titulo = 'Platos';
require __DIR__ . '/inc/header.php';

if (($editar || $nuevo) && es_admin()):
    $plato = ['id' => 0, 'nombre' => '', 'categoria' => 'Principal', 'descripcion' => '', 'activo' => 1];
    $receta = [];
    if ($editar) {
        $st = $pdo->prepare('SELECT * FROM platos WHERE id=?');
        $st->execute([$editar]);
        $plato = $st->fetch();
        if (!$plato) redirect('platos.php');
        $st = $pdo->prepare('SELECT pi.*, p.unidad FROM plato_items pi JOIN productos p ON p.id = pi.producto_id WHERE plato_id=? ORDER BY p.nombre');
        $st->execute([$editar]);
        $receta = $st->fetchAll();
    }
    if (!$receta) $receta = [['producto_id' => 0, 'cantidad_por_persona' => '', 'unidad' => '']];
?>
<h1><?= $plato['id'] ? 'Receta: ' . e($plato['nombre']) : 'Nuevo plato' ?></h1>
<?php if (!empty($usos[$plato['id']])): ?><p class="muted">En el menú: <?= e(implode(' · ', $usos[$plato['id']])) ?></p><?php endif; ?>
<form method="post" class="card">
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="guardar">
    <input type="hidden" name="id" value="<?= (int)$plato['id'] ?>">
    <input type="hidden" name="volver" value="<?= e($_GET['volver'] ?? '') ?>">
    <div class="grid-2">
        <label>Nombre del plato<input name="nombre" required value="<?= e($plato['nombre']) ?>" placeholder="Ej: Pata muslo con papas fritas"></label>
        <label>Categoría
            <select name="categoria">
                <?php foreach (CATEGORIAS_PLATO as $t): ?><option <?= $plato['categoria'] === $t ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?>
            </select>
        </label>
    </div>
    <label>Descripción / notas (opcional)<input name="descripcion" value="<?= e($plato['descripcion']) ?>"></label>
    <label class="check"><input type="checkbox" name="activo" value="1" <?= $plato['activo'] ? 'checked' : '' ?>> Plato activo</label>

    <h2>Ingredientes para UNA persona</h2>
    <p class="muted">Cantidad en la unidad del producto. Ej: 250 g de pollo → <strong>0,25</strong> kg · 2 huevos → <strong>2</strong> unidad · 20 ml de aceite → <strong>0,02</strong> l.</p>
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
        <a class="small" href="productos.php?nuevo=1" target="_blank">¿Falta un producto? Crealo acá</a>
        <span class="spacer"></span>
        <a class="btn sec" href="platos.php">Cancelar</a>
        <button class="btn">Guardar receta</button>
    </div>
</form>

<?php else:
    $q = trim($_GET['q'] ?? '');
    $cat = $_GET['cat'] ?? '';
    $sinReceta = !empty($_GET['sin_receta']);
    $where = ['1=1'];
    $params = [];
    if ($q !== '') { $where[] = 'p.nombre LIKE ?'; $params[] = "%$q%"; }
    if (in_array($cat, CATEGORIAS_PLATO, true)) { $where[] = 'p.categoria = ?'; $params[] = $cat; }
    $having = $sinReceta ? 'HAVING ingredientes = 0' : '';
    $st = $pdo->prepare(
        "SELECT p.*, COUNT(pi.id) AS ingredientes, COALESCE(SUM(pi.cantidad_por_persona * pr.costo_promedio), 0) AS costo_pp
         FROM platos p
         LEFT JOIN plato_items pi ON pi.plato_id = p.id
         LEFT JOIN productos pr ON pr.id = pi.producto_id
         WHERE " . implode(' AND ', $where) . "
         GROUP BY p.id $having
         ORDER BY p.activo DESC, FIELD(p.categoria, 'Entrada', 'Sopa', 'Principal', 'Guarnición', 'Postre', 'Bebida', 'Otro'), p.nombre"
    );
    $st->execute($params);
    $lista = $st->fetchAll();
    $faltan = (int)$pdo->query('SELECT COUNT(*) FROM platos p WHERE p.activo = 1 AND NOT EXISTS (SELECT 1 FROM plato_items pi WHERE pi.plato_id = p.id)')->fetchColumn();
?>
<div class="titulo-acciones">
    <h1>Platos y recetas</h1>
    <?php if (es_admin()): ?><a class="btn" href="platos.php?nuevo=1">+ Nuevo plato</a><?php endif; ?>
</div>
<?php if ($faltan): ?>
    <div class="flash warn"><?= $faltan ?> plato(s) todavía no tienen receta, así que no descuentan stock. <a href="platos.php?sin_receta=1">Ver cuáles</a></div>
<?php endif; ?>
<form method="get" class="filtros card">
    <input name="q" value="<?= e($q) ?>" placeholder="Buscar plato...">
    <select name="cat"><option value="">Todas las categorías</option><?php foreach (CATEGORIAS_PLATO as $c): ?><option <?= $cat === $c ? 'selected' : '' ?>><?= $c ?></option><?php endforeach; ?></select>
    <label class="check"><input type="checkbox" name="sin_receta" value="1" <?= $sinReceta ? 'checked' : '' ?>> Solo sin receta</label>
    <button class="btn sec">Filtrar</button>
</form>
<div class="card">
    <div class="scroll">
    <table class="tabla">
        <thead><tr><th>Plato</th><th>Categoría</th><th>En el menú</th><th class="num">Ingredientes</th><th class="num">Costo x persona</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($lista as $p): ?>
            <tr class="<?= $p['activo'] ? '' : 'anulado' ?>">
                <td><strong><?= e($p['nombre']) ?></strong><?= $p['activo'] ? '' : ' <span class="badge b-muted">Inactivo</span>' ?>
                    <?php if ($p['descripcion']): ?><div class="muted small"><?= e($p['descripcion']) ?></div><?php endif; ?></td>
                <td><?= e($p['categoria']) ?></td>
                <td class="small muted"><?= e(implode(' · ', $usos[$p['id']] ?? ['—'])) ?></td>
                <td class="num"><?= (int)$p['ingredientes'] ?: '<span class="badge b-warn">Sin receta</span>' ?></td>
                <td class="num"><?= dinero($p['costo_pp']) ?></td>
                <td class="acciones-fila">
                    <?php if (es_admin()): ?>
                    <a href="platos.php?editar=<?= (int)$p['id'] ?><?= $sinReceta ? '&volver=sin_receta' : '' ?>"><?= $p['ingredientes'] ? 'Editar receta' : 'Cargar receta' ?></a>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="duplicar"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn-link">Duplicar</button></form>
                    <form method="post" data-confirm="¿Eliminar este plato? También se quita del menú."><?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn-link peligro">Eliminar</button></form>
                    <?php else: ?>
                    <a href="platos.php?receta=<?= (int)$p['id'] ?>">Ver receta</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$lista): ?><tr><td colspan="6" class="muted">No hay platos con ese filtro.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
<?php
    // Vista de receta de solo lectura (rol cocina)
    if (!empty($_GET['receta'])):
        $st = $pdo->prepare('SELECT p.nombre AS plato, pr.nombre, pr.unidad, pi.cantidad_por_persona FROM plato_items pi JOIN productos pr ON pr.id = pi.producto_id JOIN platos p ON p.id = pi.plato_id WHERE pi.plato_id = ? ORDER BY pr.nombre');
        $st->execute([(int)$_GET['receta']]);
        $rec = $st->fetchAll();
?>
<div class="card" id="receta">
    <h2>Receta x persona<?= $rec ? ': ' . e($rec[0]['plato']) : '' ?></h2>
    <?php if (!$rec): ?><p class="muted">Sin receta cargada.</p><?php else: ?>
    <ul><?php foreach ($rec as $r): ?><li><?= e($r['nombre']) ?>: <?= num($r['cantidad_por_persona'], 4) ?> <?= e($r['unidad']) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/inc/footer.php'; ?>
