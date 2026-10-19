<?php
/**
 * Planificador de estadía de un contingente:
 * fechas + menús tildados (completos) + materia prima total.
 */

/** Menús disponibles en el ciclo: [N => [servicio => platos]] */
function menus_disponibles(PDO $pdo): array
{
    $out = [];
    $servicios = servicios_con_menu($pdo);
    for ($d = 1; $d <= ciclo_largo(); $d++) {
        foreach ($servicios as $sv) {
            $pl = platos_del_menu($pdo, $d, $sv);
            if ($pl) $out[$d][$sv] = $pl;
        }
    }
    return $out;
}

/**
 * Reparte los menús tildados en los días de la estadía, en orden:
 * el primer menú tildado va al día de llegada, el segundo al día siguiente, etc.
 * Devuelve [fecha => N° de menú (0 = sin menú ese día)]
 */
function asignar_menus(string $llegada, string $salida, array $menus): array
{
    sort($menus);
    $out = [];
    $t = strtotime($llegada);
    $i = 0;
    while ($t <= strtotime($salida) && $i < 60) {
        $out[date('Y-m-d', $t)] = $menus[$i] ?? 0;
        $t = strtotime('+1 day', $t);
        $i++;
    }
    return $out;
}

/**
 * Arma el plan a partir del formulario ($src) o de lo guardado ($guardado).
 * $guardado = fila de contingentes (o null para uno nuevo).
 */
function plan_desde_datos(PDO $pdo, array $src, ?array $guardado): array
{
    $armado = !empty($src['armado']);
    $base = $guardado ?: [
        'id' => 0, 'numero' => '', 'personas' => '', 'observaciones' => '',
        'fecha_llegada' => date('Y-m-d'), 'fecha_salida' => date('Y-m-d', strtotime('+2 days')),
    ];
    $c = $base;
    if ($armado) {
        $c['numero'] = trim($src['numero'] ?? '');
        $c['personas'] = (int)($src['personas'] ?? 0) ?: '';
        $c['observaciones'] = trim($src['observaciones'] ?? '');
        $c['fecha_llegada'] = valid_fecha($src['fecha_llegada'] ?? '') ?: $base['fecha_llegada'];
        $c['fecha_salida'] = valid_fecha($src['fecha_salida'] ?? '') ?: $base['fecha_salida'];
    }

    $errores = [];
    $dias = (int)((strtotime($c['fecha_salida']) - strtotime($c['fecha_llegada'])) / 86400) + 1;
    if ($dias < 1) $errores[] = 'La fecha de salida no puede ser anterior a la de llegada.';
    elseif ($dias > 30) $errores[] = 'La estadía no puede superar los 30 días.';
    $c['dias'] = max(1, $dias);

    $disponibles = menus_disponibles($pdo);

    // Menús tildados
    $sel = [];
    if ($armado) {
        $sel = array_map('intval', (array)($src['menus'] ?? []));
    } elseif (!empty($guardado['id'])) {
        $st = $pdo->prepare('SELECT DISTINCT dia_menu FROM contingente_menu WHERE contingente_id = ? AND dia_menu > 0');
        $st->execute([$guardado['id']]);
        $sel = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
    $sel = array_values(array_unique(array_filter($sel, fn($n) => isset($disponibles[$n]))));
    sort($sel);

    // Cálculo: cada menú tildado completo (todas sus comidas) × personas
    $comidas = [];
    foreach ($sel as $n) {
        foreach (array_keys($disponibles[$n]) as $sv) $comidas[] = ['dia' => $n, 'servicio' => $sv];
    }
    $personas = (int)$c['personas'];
    $calc = (!$errores && $comidas) ? calcular_plan($pdo, $comidas, max(0, $personas)) : null;

    // Si se está modificando un contingente ya confirmado, lo que él mismo descontó vuelve a contar como stock
    if ($calc && !empty($guardado['id'])) {
        $st = $pdo->prepare('SELECT si.producto_id, SUM(si.cantidad) FROM servicio_items si JOIN servicios s ON s.id = si.servicio_id
                             JOIN servicio_contingentes sc ON sc.servicio_id = s.id WHERE sc.contingente_id = ? AND s.anulado = 0 GROUP BY si.producto_id');
        $st->execute([$guardado['id']]);
        $propio = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        $calc['faltantes'] = 0;
        foreach ($calc['productos'] as $pid => &$p) {
            $p['stock'] += (float)($propio[$pid] ?? 0);
            $p['comprar'] = max(0, round($p['necesario'] - max($p['stock'], 0), 3));
            if ($p['comprar'] > 0) $calc['faltantes']++;
        }
        unset($p);
    }
    $asignacion = $errores ? [] : asignar_menus($c['fecha_llegada'], $c['fecha_salida'], $sel);

    return ['c' => $c, 'errores' => $errores, 'disponibles' => $disponibles, 'sel' => $sel,
            'asignacion' => $asignacion, 'calc' => $calc, 'personas' => $personas];
}

/** Campos ocultos para guardar el plan tal como se ve */
function plan_campos_ocultos(array $plan): string
{
    $h = '';
    foreach (['numero', 'personas', 'fecha_llegada', 'fecha_salida', 'observaciones'] as $k) {
        $h .= '<input type="hidden" name="' . $k . '" value="' . e($plan['c'][$k]) . '">';
    }
    foreach ($plan['sel'] as $n) $h .= '<input type="hidden" name="menus[]" value="' . (int)$n . '">';
    return $h;
}

const DIAS_SEMANA = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];

/**
 * Dibuja el planificador.
 * $getAction: página a la que vuelve el formulario al recalcular (index.php o contingentes.php)
 * $getOcultos: campos extra para el GET (ej. planificar=1 o editar=ID)
 * $volver: a dónde ir después de guardar
 */
function render_planificador(PDO $pdo, array $plan, string $getAction, array $getOcultos, string $volver): void
{
    $c = $plan['c'];
    $calc = $plan['calc'];
    $id = (int)($c['id'] ?? 0);

    // Otros contingentes que coinciden en las fechas (comparten el stock)
    $otros = [];
    if (!$plan['errores']) {
        $st = $pdo->prepare('SELECT numero, personas, fecha_llegada, fecha_salida FROM contingentes WHERE cancelado = 0 AND id <> ? AND fecha_llegada <= ? AND fecha_salida >= ? ORDER BY fecha_llegada');
        $st->execute([$id, $c['fecha_salida'], $c['fecha_llegada']]);
        $otros = $st->fetchAll();
    }
    ?>
<form method="get" action="<?= e($getAction) ?>" id="plan-form" class="no-print-controls">
    <input type="hidden" name="armado" value="1">
    <?php foreach ($getOcultos as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>

    <section class="card">
        <h2><?= $id ? 'Contingente N° ' . e($c['numero']) : 'Nuevo contingente' ?></h2>
        <?php $autofocus = !$id && $c['numero'] === ''; include __DIR__ . '/form_contingente.php'; ?>
        <?php foreach ($plan['errores'] as $er): ?><div class="flash error"><?= e($er) ?></div><?php endforeach; ?>
        <button class="btn">Calcular materia prima</button>
    </section>

    <?php if (!$plan['errores']): ?>
    <section class="card">
        <h2>Menús que se van a servir</h2>
        <?php if (!$plan['disponibles']): ?>
            <div class="flash warn">No hay menús cargados. <a href="menus.php">Cargá los menús</a> primero.</div>
        <?php else: ?>
        <p class="muted small">Tildá los menús completos que se van a servir. El primero tildado va el día de llegada, el segundo el día siguiente, y así.</p>
        <div class="menus-opciones">
        <?php foreach ($plan['disponibles'] as $n => $servs):
            $marcado = in_array($n, $plan['sel'], true);
            $costo = 0;
            foreach ($servs as $pls) $costo += array_sum(array_column($pls, 'costo_pp')); ?>
            <label class="menu-opcion <?= $marcado ? 'marcado' : '' ?>">
                <div class="menu-opcion-titulo">
                    <input type="checkbox" name="menus[]" value="<?= $n ?>" <?= $marcado ? 'checked' : '' ?> data-autosubmit="plan">
                    <strong>MENÚ <?= $n ?></strong>
                    <span class="muted small"><?= dinero($costo) ?> x pers.</span>
                </div>
                <?php foreach ($servs as $sv => $pls): ?>
                    <div class="menu-opcion-servicio"><?= e(mb_strtoupper($sv)) ?></div>
                    <ul>
                    <?php foreach ($pls as $p): ?>
                        <li><span class="muted small"><?= e($p['categoria']) ?>:</span> <?= e($p['nombre']) ?><?= !$p['ingredientes'] ? ' <span class="badge b-warn">sin receta</span>' : '' ?></li>
                    <?php endforeach; ?>
                    </ul>
                <?php endforeach; ?>
            </label>
        <?php endforeach; ?>
        </div>
        <noscript><button class="btn">Calcular con los menús tildados</button></noscript>

        <?php if ($plan['sel'] && $plan['asignacion']):
            $sinMenu = array_keys(array_filter($plan['asignacion'], fn($m) => $m === 0));
            $sobran = count($plan['sel']) - count($plan['asignacion']); ?>
            <h3>Qué menú se sirve cada día</h3>
            <div class="chips">
            <?php foreach ($plan['asignacion'] as $f => $m): ?>
                <span class="chip <?= $m ? '' : 'chip-vacio' ?>"><?= DIAS_SEMANA[(int)date('w', strtotime($f))] ?> <?= date('d/m', strtotime($f)) ?> → <?= $m ? 'Menú ' . $m : 'sin menú' ?></span>
            <?php endforeach; ?>
            </div>
            <?php if ($sinMenu): ?><p class="small aviso">La estadía es de <?= count($plan['asignacion']) ?> días y tildaste <?= count($plan['sel']) ?> menú(s): <?= count($sinMenu) ?> día(s) quedan sin menú y no se cuentan.</p><?php endif; ?>
            <?php if ($sobran > 0): ?><p class="small aviso">Tildaste <?= count($plan['sel']) ?> menús pero la estadía es de <?= count($plan['asignacion']) ?> día(s): <?= $sobran ?> menú(s) no tienen día asignado (igual se suman al cálculo).</p><?php endif; ?>
        <?php endif; ?>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</form>

<?php if ($calc) render_materia_prima($plan, $otros); ?>

<?php if (!$plan['errores'] && $plan['disponibles'] && es_admin()): ?>
<form method="post" action="contingentes.php" class="card no-print" data-once>
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="guardar">
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="volver" value="<?= e($volver) ?>">
    <?= plan_campos_ocultos($plan) ?>
    <?php if ($c['numero'] === '' || $plan['personas'] <= 0 || !$plan['sel']): ?>
        <p class="muted">Para confirmar: completá el N° de contingente y las personas, y tildá al menos un menú.</p>
        <button class="btn grande" disabled>Confirmar contingente</button>
    <?php else: ?>
        <button class="btn grande">✔ <?= $id ? 'Confirmar cambios del contingente' : 'Confirmar contingente N° ' . e($c['numero']) ?></button>
        <p class="muted small" style="margin:.5rem 0 0">Al confirmar se descuenta del stock todo lo de la estadía y la lista de compras queda guardada en la ficha del contingente. Si falta mercadería, el stock queda en negativo hasta que cargues la compra.</p>
    <?php endif; ?>
</form>
<?php endif; ?>
<?php
}

/** Lista de materia prima / compras de un plan (se usa en el planificador y en la ficha del contingente) */
function render_materia_prima(array $plan, array $otros = [], string $titulo = 'Materia prima de los menús tildados'): void
{
    $c = $plan['c'];
    $calc = $plan['calc'];
    ?>
<section class="card imprimible" id="resultado">
    <div class="titulo-acciones">
        <h2 style="margin:0"><?= e($titulo) ?></h2>
        <button type="button" class="btn sec no-print" onclick="window.print()">Imprimir lista de compras</button>
    </div>
    <p class="solo-print">Contingente N° <?= e($c['numero']) ?> · <?= (int)$plan['personas'] ?> personas · del <?= fecha_ar($c['fecha_llegada']) ?> al <?= fecha_ar($c['fecha_salida']) ?> · Menús <?= e(implode(', ', $plan['sel'])) ?></p>
    <?php if ($plan['personas'] <= 0): ?>
        <div class="flash warn">Poné la cantidad de personas para calcular.</div>
    <?php else: ?>
    <div class="stats">
        <div class="stat"><span>Personas</span><strong><?= (int)$plan['personas'] ?></strong></div>
        <div class="stat"><span>Menús</span><strong><?= count($plan['sel']) ?></strong></div>
        <div class="stat"><span>Comidas</span><strong><?= (int)$calc['servidas'] ?></strong></div>
        <div class="stat"><span>Raciones</span><strong><?= (int)$calc['servidas'] * $plan['personas'] ?></strong></div>
        <div class="stat"><span>Costo estimado</span><strong><?= dinero($calc['costo_total']) ?></strong></div>
        <div class="stat"><span>Por persona (estadía)</span><strong><?= dinero($calc['costo_total'] / $plan['personas']) ?></strong></div>
    </div>
    <?php if ($calc['sin_receta']): ?>
        <div class="flash warn no-print"><strong><?= count($calc['sin_receta']) ?> plato(s) sin receta</strong> no están sumados: <?= e(implode(', ', $calc['sin_receta'])) ?>. <a href="platos.php?sin_receta=1">Cargar recetas</a></div>
    <?php endif; ?>
    <?php if (!$calc['productos']): ?>
        <p class="muted">Todavía no hay recetas cargadas para los platos de esta estadía, así que no hay nada para calcular.</p>
    <?php else: ?>
    <div class="scroll">
    <table class="tabla">
        <thead><tr><th>Producto</th><th class="num">Se necesita</th><th class="num">Stock actual</th><th class="num">Falta comprar</th></tr></thead>
        <tbody>
        <?php $cat = null; foreach ($calc['productos'] as $p):
            if ($p['categoria'] !== $cat): $cat = $p['categoria']; ?>
                <tr class="fila-categoria"><td colspan="4"><?= e($cat ?: 'Sin categoría') ?></td></tr>
            <?php endif; ?>
            <tr class="<?= $p['comprar'] > 0 ? 'fila-falta' : '' ?>">
                <td><?= e($p['nombre']) ?></td>
                <td class="num"><strong><?= num($p['necesario']) ?></strong> <?= e($p['unidad']) ?></td>
                <td class="num"><?= num($p['stock']) ?> <?= e($p['unidad']) ?></td>
                <td class="num"><?= $p['comprar'] > 0 ? '<span class="badge b-err">' . num($p['comprar']) . ' ' . e($p['unidad']) . '</span>' : '<span class="badge b-ok">Alcanza</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p class="total-linea"><?= $calc['faltantes'] ? '<strong>' . (int)$calc['faltantes'] . ' producto(s) para comprar.</strong>' : 'Con el stock actual alcanza para toda la estadía.' ?></p>
    <?php endif; ?>
    <?php if ($otros): ?>
        <p class="muted small">Ojo: en esas fechas también están <?= e(implode(', ', array_map(fn($o) => 'N° ' . $o['numero'] . ' (' . $o['personas'] . ' pers.)', $otros))) ?>. El stock actual lo comparten, así que esta cuenta es solo para este contingente.</p>
    <?php endif; ?>
    <?php endif; ?>
</section>
<?php
}
