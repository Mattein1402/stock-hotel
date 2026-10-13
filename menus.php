<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    csrf_check();
    $acc = $_POST['accion'] ?? '';
    $dia = (int)($_POST['dia'] ?? -1);

    if ($acc === 'agregar') {
        $servicio = $_POST['servicio'] ?? '';
        $plato = (int)($_POST['plato_id'] ?? 0);
        if ($dia >= 0 && $dia <= CICLO_DIAS && in_array($servicio, SERVICIOS, true) && $plato > 0) {
            $st = $pdo->prepare('SELECT COALESCE(MAX(orden), 0) + 1 FROM menu_ciclo WHERE dia = ? AND servicio = ?');
            $st->execute([$dia, $servicio]);
            $pdo->prepare('INSERT IGNORE INTO menu_ciclo (dia, servicio, plato_id, orden) VALUES (?,?,?,?)')
                ->execute([$dia, $servicio, $plato, (int)$st->fetchColumn()]);
        }
    }
    if ($acc === 'quitar') {
        $pdo->prepare('DELETE FROM menu_ciclo WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
    }
    if ($acc === 'mover') {
        // Sube un plato un lugar dentro de su comida
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare('SELECT * FROM menu_ciclo WHERE id = ?');
        $st->execute([$id]);
        if ($a = $st->fetch()) {
            $st = $pdo->prepare('SELECT * FROM menu_ciclo WHERE dia = ? AND servicio = ? AND (orden < ? OR (orden = ? AND id < ?)) ORDER BY orden DESC, id DESC LIMIT 1');
            $st->execute([$a['dia'], $a['servicio'], $a['orden'], $a['orden'], $a['id']]);
            if ($b = $st->fetch()) {
                $ordA = (int)$b['orden'] === (int)$a['orden'] ? (int)$a['orden'] - 1 : (int)$b['orden'];
                $pdo->prepare('UPDATE menu_ciclo SET orden = ? WHERE id = ?')->execute([$ordA, $a['id']]);
                $pdo->prepare('UPDATE menu_ciclo SET orden = ? WHERE id = ?')->execute([(int)$a['orden'], $b['id']]);
            }
        }
    }
    redirect('menus.php#dia' . max(0, $dia));
}

// Ciclo completo
$celdas = [];
$rows = $pdo->query(
    'SELECT mc.id, mc.dia, mc.servicio, p.id AS plato_id, p.nombre, p.categoria,
            (SELECT COUNT(*) FROM plato_items pi WHERE pi.plato_id = p.id) AS ingredientes,
            (SELECT COALESCE(SUM(pi.cantidad_por_persona * pr.costo_promedio), 0) FROM plato_items pi JOIN productos pr ON pr.id = pi.producto_id WHERE pi.plato_id = p.id) AS costo_pp
     FROM menu_ciclo mc JOIN platos p ON p.id = mc.plato_id
     ORDER BY mc.dia, mc.orden, mc.id'
)->fetchAll();
foreach ($rows as $r) $celdas[$r['dia']][$r['servicio']][] = $r;

$platos = $pdo->query("SELECT id, nombre, categoria FROM platos WHERE activo = 1 ORDER BY FIELD(categoria, 'Entrada', 'Sopa', 'Principal', 'Guarnición', 'Postre', 'Bebida', 'Otro'), nombre")->fetchAll();
function opciones_platos(array $platos): string
{
    $h = '<option value="">+ Agregar plato…</option>';
    $cat = null;
    foreach ($platos as $p) {
        if ($p['categoria'] !== $cat) {
            if ($cat !== null) $h .= '</optgroup>';
            $cat = $p['categoria'];
            $h .= '<optgroup label="' . e($cat) . '">';
        }
        $h .= '<option value="' . (int)$p['id'] . '">' . e($p['nombre']) . '</option>';
    }
    return $h . ($cat !== null ? '</optgroup>' : '');
}
$opciones = opciones_platos($platos);

// Qué comidas mostrar: Almuerzo y Cena siempre; Desayuno/Merienda solo si se usan
$usados = array_unique(array_column($rows, 'servicio'));
$mostrar = array_values(array_filter(SERVICIOS, fn($s) => in_array($s, ['Almuerzo', 'Cena'], true) || in_array($s, $usados, true) || !empty($_GET['todas'])));

$titulo = 'Menú';
require __DIR__ . '/inc/header.php';
?>
<div class="titulo-acciones">
    <h1>Menú — ciclo de <?= CICLO_DIAS ?> días</h1>
    <?php if (es_admin()): ?><a class="btn sec" href="platos.php">Platos y recetas</a><?php endif; ?>
</div>
<p class="muted">El día 1 del ciclo corresponde al día de llegada del contingente. Si se quedan más de <?= CICLO_DIAS ?> días, el ciclo vuelve a empezar.
<?php if (es_admin() && count($mostrar) < count(SERVICIOS)): ?> <a href="menus.php?todas=1">Mostrar también desayuno y merienda</a><?php endif; ?></p>

<?php for ($dia = 0; $dia <= CICLO_DIAS; $dia++):
    if ($dia === 0 && empty($celdas[0]) && empty($_GET['todas'])) continue; ?>
<section class="card dia-menu" id="dia<?= $dia ?>">
    <h2><?= $dia === 0 ? 'Todos los días <span class="muted small">(platos fijos, ej. desayuno)</span>' : 'Día ' . $dia ?></h2>
    <div class="servicios-grid" style="--cols: <?= count($mostrar) ?>">
    <?php foreach ($mostrar as $sv):
        $items = $celdas[$dia][$sv] ?? [];
        $costo = array_sum(array_column($items, 'costo_pp')); ?>
        <div class="servicio-col">
            <h3><?= $sv ?> <?php if ($items): ?><span class="muted small"><?= dinero($costo) ?> x pers.</span><?php endif; ?></h3>
            <ul class="lista-platos">
            <?php foreach ($items as $i => $it): ?>
                <li>
                    <span>
                        <?php if (es_admin()): ?><a href="platos.php?editar=<?= (int)$it['plato_id'] ?>"><?= e($it['nombre']) ?></a><?php else: ?><?= e($it['nombre']) ?><?php endif; ?>
                        <?php if (!$it['ingredientes']): ?><span class="badge b-warn">Sin receta</span><?php endif; ?>
                    </span>
                    <?php if (es_admin()): ?>
                    <span class="mini-acciones">
                        <?php if ($i > 0): ?>
                        <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="mover"><input type="hidden" name="dia" value="<?= $dia ?>"><input type="hidden" name="id" value="<?= (int)$it['id'] ?>"><button class="btn-x" title="Subir">↑</button></form>
                        <?php endif; ?>
                        <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="quitar"><input type="hidden" name="dia" value="<?= $dia ?>"><input type="hidden" name="id" value="<?= (int)$it['id'] ?>"><button class="btn-x" title="Quitar del menú">✕</button></form>
                    </span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
            <?php if (!$items): ?><li class="muted small">Sin platos</li><?php endif; ?>
            </ul>
            <?php if (es_admin()): ?>
            <form method="post" class="agregar-plato">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="agregar"><input type="hidden" name="dia" value="<?= $dia ?>"><input type="hidden" name="servicio" value="<?= $sv ?>">
                <select name="plato_id" data-autosubmit="post"><?= $opciones ?></select>
            </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div>
</section>
<?php endfor; ?>
<?php require __DIR__ . '/inc/footer.php'; ?>
