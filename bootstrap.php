<?php
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

const UNIDADES = ['kg', 'g', 'l', 'ml', 'unidad', 'docena', 'paquete', 'caja', 'lata', 'atado'];
const SERVICIOS = ['Desayuno', 'Almuerzo', 'Merienda', 'Cena'];
const CATEGORIAS_PLATO = ['Entrada', 'Sopa', 'Principal', 'Guarnición', 'Postre', 'Bebida', 'Otro'];
const CICLO_DIAS = 10;        // máximo de días del ciclo de menú (el ciclo real dura lo que esté cargado)
const SCHEMA_VERSION = 4;     // versión de la base que espera este código

/* ---------- Base de datos ---------- */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }
    return $pdo;
}

/* ---------- Formato ---------- */
function e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** Número con formato argentino, sin ceros de más: 1.250,5 */
function num($n, int $dec = 3): string
{
    $s = number_format((float)$n, $dec, ',', '.');
    if ($dec > 0) {
        $s = rtrim(rtrim($s, '0'), ',');
    }
    return $s === '-0' ? '0' : $s;
}

function dinero($n): string
{
    return '$ ' . number_format((float)$n, 2, ',', '.');
}

/** Valor para un input editable: 0,15 */
function num_input($n): string
{
    $s = rtrim(rtrim(number_format((float)$n, 4, '.', ''), '0'), '.');
    return str_replace('.', ',', $s);
}

/** Lee un número escrito como "0,150", "1.250,5" o "2.5" */
function dec_in($v): float
{
    $v = str_replace(' ', '', trim((string)$v));
    if ($v === '') return 0.0;
    if (strpos($v, ',') !== false) {
        $v = str_replace('.', '', $v);
        $v = str_replace(',', '.', $v);
    }
    return is_numeric($v) ? (float)$v : 0.0;
}

function valid_fecha($s): ?string
{
    $s = (string)$s;
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
}

function fecha_ar($s): string
{
    if (!$s) return '';
    $t = strtotime($s);
    return strlen($s) > 10 ? date('d/m/Y H:i', $t) : date('d/m/Y', $t);
}

/* ---------- Sesión / seguridad ---------- */
function usuario(): ?array
{
    return $_SESSION['user'] ?? null;
}

function es_admin(): bool
{
    return (usuario()['rol'] ?? '') === 'admin';
}

function require_login(): void
{
    if (!usuario()) {
        header('Location: login.php');
        exit;
    }
    verificar_version_base();
}

/** Si el código es nuevo pero la base es vieja, manda al actualizador */
function verificar_version_base(): void
{
    if (($_SESSION['schema'] ?? 0) >= SCHEMA_VERSION || basename($_SERVER['PHP_SELF']) === 'actualizar.php') return;
    try {
        $v = (int)db()->query("SELECT valor FROM sistema WHERE clave = 'version'")->fetchColumn();
        // Además de la versión, que estén las tablas nuevas (por si una actualización quedó a medias)
        $n = (int)db()->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('contingente_menu', 'menu_ciclo', 'platos', 'contingentes')")->fetchColumn();
        if ($n < 4) $v = min($v, SCHEMA_VERSION - 1);
    } catch (PDOException $ex) {
        $v = 1;
    }
    if ($v >= SCHEMA_VERSION) {
        $_SESSION['schema'] = $v;
        return;
    }
    redirect('actualizar.php');
}

function require_admin(): void
{
    require_login();
    if (!es_admin()) {
        http_response_code(403);
        exit('Esta sección es solo para administradores. <a href="index.php">Volver</a>');
    }
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

function csrf_check(): void
{
    if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('La sesión expiró. Volvé atrás y recargá la página.');
    }
}

function flash(string $msg, string $tipo = 'ok'): void
{
    $_SESSION['flash'][] = [$tipo, $msg];
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/* ---------- Stock ---------- */

/**
 * Suma (o resta, si $delta es negativo) stock a un producto
 * y deja registrado el movimiento. Llamar dentro de una transacción.
 */
function mover_stock(PDO $pdo, int $productoId, float $delta, string $tipo, ?string $refTipo, ?int $refId, string $nota = ''): void
{
    $delta = round($delta, 3);
    $pdo->prepare('UPDATE productos SET stock = stock + ? WHERE id = ?')->execute([$delta, $productoId]);
    $st = $pdo->prepare('SELECT stock FROM productos WHERE id = ?');
    $st->execute([$productoId]);
    $stock = $st->fetchColumn();
    $pdo->prepare(
        'INSERT INTO movimientos (producto_id, tipo, cantidad, stock_resultante, ref_tipo, ref_id, usuario_id, nota)
         VALUES (?,?,?,?,?,?,?,?)'
    )->execute([$productoId, $tipo, $delta, $stock, $refTipo, $refId, usuario()['id'] ?? null, mb_substr($nota, 0, 255)]);
}

/* ---------- Contingentes y menú ---------- */

/** Servicio que corresponde según la hora actual */
function servicio_por_hora(): string
{
    $h = (int)date('G');
    if ($h < 10) return 'Desayuno';
    if ($h < 15) return 'Almuerzo';
    if ($h < 18) return 'Merienda';
    return 'Cena';
}

/** Posición de una comida en el día (0 = desayuno ... 3 = cena) */
function orden_servicio(string $s): int
{
    $i = array_search($s, SERVICIOS, true);
    return $i === false ? 0 : (int)$i;
}

/**
 * Contingentes que están en el hotel en una fecha (con su día de estadía).
 * Si se pasa la comida, respeta la comida de llegada/salida y el plan de menú
 * del contingente (si para esa comida está marcado "no come en el hotel", no cuenta).
 */
function contingentes_activos(PDO $pdo, string $fecha, ?string $servicio = null): array
{
    $st = $pdo->prepare(
        'SELECT c.*, DATEDIFF(?, c.fecha_llegada) + 1 AS dia_estadia, cm.dia_menu AS dia_plan
         FROM contingentes c
         LEFT JOIN contingente_menu cm ON cm.contingente_id = c.id AND cm.fecha = ? AND cm.servicio = ?
         WHERE c.cancelado = 0 AND ? BETWEEN c.fecha_llegada AND c.fecha_salida
         ORDER BY c.fecha_llegada, c.numero'
    );
    $st->execute([$fecha, $fecha, (string)$servicio, $fecha]);
    $out = [];
    foreach ($st->fetchAll() as $c) {
        if ($servicio !== null) {
            $o = orden_servicio($servicio);
            if ($fecha === $c['fecha_llegada'] && $o < orden_servicio($c['comida_llegada'])) continue;
            if ($fecha === $c['fecha_salida'] && $o > orden_servicio($c['comida_salida'])) continue;
            if ($c['dia_plan'] !== null && (int)$c['dia_plan'] === 0) continue;
        }
        $out[] = $c;
    }
    return $out;
}

/**
 * Cuántos días tiene el ciclo de menú cargado (el último día con platos).
 * Ej.: si se cargaron los Menús 1, 2 y 3, el ciclo es de 3 días y el día 4 vuelve al 1.
 */
function ciclo_largo(): int
{
    static $n = null;
    if ($n === null) {
        try {
            $n = (int)db()->query('SELECT COALESCE(MAX(dia), 0) FROM menu_ciclo')->fetchColumn();
        } catch (PDOException $ex) {
            $n = 0;
        }
        $n = max(1, min(CICLO_DIAS, $n ?: CICLO_DIAS));
    }
    return $n;
}

/** Día del ciclo de menú que toca según el día de estadía */
function dia_ciclo(int $diaEstadia): int
{
    return (($diaEstadia - 1) % ciclo_largo()) + 1;
}

/**
 * Día de menú sugerido para una fecha y comida.
 * Se toma el contingente que llegó primero: si tiene un menú elegido en su plan
 * se usa ese; si no, el que corresponde a su día de estadía.
 * Devuelve [dia, explicación]
 */
function dia_menu_sugerido(array $activos): array
{
    if ($activos) {
        $c = $activos[0];
        if (!empty($c['dia_plan'])) {
            $d = (int)$c['dia_plan'];
            return [$d, 'Según el plan del contingente N° ' . $c['numero'] . ' → Menú ' . $d . '.'];
        }
        $d = dia_ciclo((int)$c['dia_estadia']);
        return [$d, 'El contingente N° ' . $c['numero'] . ' está en su día ' . (int)$c['dia_estadia'] . ' de estadía → Menú ' . $d . '.'];
    }
    return [1, 'No hay contingentes en el hotel en esta fecha.'];
}

/** Comidas que tienen platos cargados en el menú (en orden del día) */
function servicios_con_menu(PDO $pdo): array
{
    $usados = $pdo->query('SELECT DISTINCT servicio FROM menu_ciclo')->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_filter(SERVICIOS, fn($s) => in_array($s, $usados, true)));
}

/**
 * Lista de comidas de una estadía: desde la comida de llegada hasta la de salida.
 * Cada comida: fecha, servicio, dia_estadia, dia_default (día del ciclo que le toca).
 */
function generar_comidas(string $llegada, string $comLlegada, string $salida, string $comSalida, array $servicios): array
{
    $out = [];
    $t = strtotime($llegada);
    $fin = strtotime($salida);
    $dia = 1;
    while ($t <= $fin && $dia <= 60) {
        $f = date('Y-m-d', $t);
        foreach ($servicios as $sv) {
            $o = orden_servicio($sv);
            if ($f === $llegada && $o < orden_servicio($comLlegada)) continue;
            if ($f === $salida && $o > orden_servicio($comSalida)) continue;
            $out[] = ['fecha' => $f, 'servicio' => $sv, 'dia_estadia' => $dia, 'dia_default' => dia_ciclo($dia), 'clave' => $f . '|' . $sv];
        }
        $t = strtotime('+1 day', $t);
        $dia++;
    }
    return $out;
}

/**
 * Materia prima para toda una estadía.
 * $comidas: lista de generar_comidas() con 'dia' elegido (0 = no come en el hotel).
 */
function calcular_plan(PDO $pdo, array $comidas, int $personas): array
{
    $res = ['comidas' => [], 'productos' => [], 'costo_total' => 0.0, 'sin_receta' => [], 'faltantes' => 0, 'servidas' => 0];
    $usos = []; // plato_id => cantidad de veces que se sirve
    $cache = [];
    foreach ($comidas as $c) {
        $platos = [];
        if ($c['dia'] > 0) {
            $k = $c['dia'] . '|' . $c['servicio'];
            $cache[$k] ??= platos_del_menu($pdo, (int)$c['dia'], $c['servicio']);
            $platos = $cache[$k];
            foreach ($platos as $p) {
                $usos[(int)$p['id']] = ($usos[(int)$p['id']] ?? 0) + 1;
                if (!$p['ingredientes']) $res['sin_receta'][$p['nombre']] = true;
            }
            $res['servidas']++;
        }
        $costo = array_sum(array_column($platos, 'costo_pp')) * $personas;
        $res['comidas'][] = $c + ['platos' => $platos, 'costo' => $costo];
        $res['costo_total'] += $costo;
    }
    if ($usos && $personas > 0) {
        $in = implode(',', array_fill(0, count($usos), '?'));
        $st = $pdo->prepare(
            "SELECT pi.plato_id, pi.producto_id, pi.cantidad_por_persona, p.nombre, p.categoria, p.unidad, p.stock, p.stock_minimo, p.costo_promedio
             FROM plato_items pi JOIN productos p ON p.id = pi.producto_id WHERE pi.plato_id IN ($in)"
        );
        $st->execute(array_keys($usos));
        foreach ($st->fetchAll() as $r) {
            $pid = (int)$r['producto_id'];
            $res['productos'][$pid] ??= [
                'nombre' => $r['nombre'], 'categoria' => $r['categoria'], 'unidad' => $r['unidad'],
                'stock' => (float)$r['stock'], 'minimo' => (float)$r['stock_minimo'], 'costo' => (float)$r['costo_promedio'], 'necesario' => 0.0,
            ];
            $res['productos'][$pid]['necesario'] += (float)$r['cantidad_por_persona'] * $personas * $usos[(int)$r['plato_id']];
        }
    }
    foreach ($res['productos'] as &$p) {
        $p['necesario'] = round($p['necesario'], 3);
        $p['comprar'] = max(0, round($p['necesario'] - max($p['stock'], 0), 3));
        if ($p['comprar'] > 0) $res['faltantes']++;
    }
    unset($p);
    uasort($res['productos'], fn($a, $b) => [$a['categoria'], $a['nombre']] <=> [$b['categoria'], $b['nombre']]);
    $res['sin_receta'] = array_keys($res['sin_receta']);
    return $res;
}

/* ---------- Unidades de medida de las recetas ---------- */

/** Familia y factor de cada unidad (la base es g, ml o unidad) */
const UNIDADES_RECETA = [
    'g' => ['masa', 1], 'kg' => ['masa', 1000],
    'ml' => ['volumen', 1], 'cc' => ['volumen', 1], 'l' => ['volumen', 1000],
    'cucharadita' => ['volumen', 5], 'cucharada' => ['volumen', 15], 'taza' => ['volumen', 250],
    'unidad' => ['cantidad', 1], 'docena' => ['cantidad', 12],
];

function unidad_info(string $u): array
{
    return UNIDADES_RECETA[$u] ?? [$u, 1];
}

/** Convierte una cantidad entre unidades. null si no son compatibles (ej. g → l) */
function convertir(float $cant, string $de, string $a): ?float
{
    if ($de === $a) return $cant;
    [$fd, $xd] = unidad_info($de);
    [$fa, $xa] = unidad_info($a);
    if ($fd !== $fa) return null;
    return $cant * $xd / $xa;
}

/**
 * Convierte una cantidad de receta a la unidad del producto.
 * Usa el "peso de 1 unidad" del producto para pasar de piezas a g/ml y al revés
 * (ej. 1/2 cebolla → kg, 50 g de durazno → latas). null si no se puede.
 */
function convertir_producto(float $cant, string $de, string $unidadProducto, ?float $pesoUnidad): ?float
{
    $r = convertir($cant, $de, $unidadProducto);
    if ($r !== null) return $r;
    if (!$pesoUnidad || $pesoUnidad <= 0) return null;
    [$fd, $xd] = unidad_info($de);
    [$fp, $xp] = unidad_info($unidadProducto);
    $fisica = ['masa', 'volumen'];
    if (in_array($fd, $fisica, true) && !in_array($fp, $fisica, true)) {
        // receta en g/ml, producto por pieza (unidad, docena, lata, caja...)
        $piezas = $cant * $xd / $pesoUnidad;
        return $fp === 'cantidad' ? $piezas / $xp : $piezas;
    }
    if ($fd === 'cantidad' && in_array($fp, $fisica, true)) {
        // receta en unidades, producto en kg/l
        return $cant * $xd * $pesoUnidad / $xp;
    }
    return null;
}

/** Unidades que se ofrecen al cargar una receta (la del producto primero) */
function unidades_receta(string $unidadProducto): array
{
    return array_values(array_unique(array_merge([$unidadProducto], array_keys(UNIDADES_RECETA))));
}

/** Platos del ciclo para un día y servicio (incluye los fijos de "todos los días") */
function platos_del_menu(PDO $pdo, int $dia, string $servicio): array
{
    $st = $pdo->prepare(
        'SELECT p.id, p.nombre, p.categoria, mc.dia,
                (SELECT COUNT(*) FROM plato_items pi WHERE pi.plato_id = p.id) AS ingredientes,
                (SELECT COALESCE(SUM(pi.cantidad_por_persona * pr.costo_promedio), 0)
                   FROM plato_items pi JOIN productos pr ON pr.id = pi.producto_id WHERE pi.plato_id = p.id) AS costo_pp
         FROM menu_ciclo mc JOIN platos p ON p.id = mc.plato_id
         WHERE mc.servicio = ? AND (mc.dia = ? OR mc.dia = 0)
         ORDER BY mc.dia = 0, mc.orden, mc.id'
    );
    $st->execute([$servicio, $dia]);
    return $st->fetchAll();
}

/**
 * Calcula cuánta materia prima se necesita para cocinar ciertos platos
 * para $pax personas, usando la receta por persona de cada plato.
 */
function calcular_consumo(PDO $pdo, array $platoIds, int $pax): array
{
    $res = ['platos' => [], 'productos' => [], 'costo_total' => 0.0, 'comensales' => $pax, 'faltantes' => 0, 'sin_receta' => []];
    $platoIds = array_values(array_unique(array_filter(array_map('intval', $platoIds))));
    if (!$platoIds || $pax <= 0) return $res;

    $stPlato = $pdo->prepare('SELECT id, nombre, categoria FROM platos WHERE id = ?');
    $stItems = $pdo->prepare(
        'SELECT pi.producto_id, pi.cantidad_por_persona, p.nombre, p.unidad, p.stock, p.stock_minimo, p.costo_promedio
         FROM plato_items pi JOIN productos p ON p.id = pi.producto_id
         WHERE pi.plato_id = ?'
    );

    foreach ($platoIds as $pid) {
        $stPlato->execute([$pid]);
        $plato = $stPlato->fetch();
        if (!$plato) continue;
        $stItems->execute([$pid]);
        $filas = $stItems->fetchAll();
        if (!$filas) $res['sin_receta'][] = $plato['nombre'];

        $costo = 0.0;
        foreach ($filas as $it) {
            $prod = (int)$it['producto_id'];
            $cant = (float)$it['cantidad_por_persona'] * $pax;
            $costo += $cant * (float)$it['costo_promedio'];
            if (!isset($res['productos'][$prod])) {
                $res['productos'][$prod] = [
                    'nombre' => $it['nombre'],
                    'unidad' => $it['unidad'],
                    'stock' => (float)$it['stock'],
                    'minimo' => (float)$it['stock_minimo'],
                    'costo' => (float)$it['costo_promedio'],
                    'necesario' => 0.0,
                ];
            }
            $res['productos'][$prod]['necesario'] += $cant;
        }
        $res['platos'][] = ['id' => (int)$plato['id'], 'nombre' => $plato['nombre'], 'categoria' => $plato['categoria'], 'costo' => $costo, 'sin_receta' => !$filas];
        $res['costo_total'] += $costo;
    }

    foreach ($res['productos'] as &$p) {
        $p['necesario'] = round($p['necesario'], 3);
        $p['queda'] = round($p['stock'] - $p['necesario'], 3);
        if ($p['queda'] < 0) {
            $p['estado'] = 'falta';
            $res['faltantes']++;
        } elseif ($p['queda'] <= $p['minimo']) {
            $p['estado'] = 'bajo';
        } else {
            $p['estado'] = 'ok';
        }
    }
    unset($p);
    uasort($res['productos'], fn($a, $b) => strcasecmp($a['nombre'], $b['nombre']));
    return $res;
}

/**
 * Registra que se cocinó una comida y descuenta el stock.
 * $pax = [contingente_id => personas]. Debe llamarse dentro de una transacción.
 * Devuelve el id del servicio. Si falta stock y no se fuerza, lanza una excepción.
 */
function registrar_consumo(PDO $pdo, string $fecha, string $servicio, int $dia, array $platoIds, array $pax, string $obs = '', bool $forzar = false): int
{
    $total = array_sum($pax);
    if ($total <= 0) throw new RuntimeException('No hay comensales.');
    if (!$platoIds) throw new RuntimeException('El menú no tiene platos.');

    // Bloquea los productos que se van a usar
    $in = implode(',', array_fill(0, count($platoIds), '?'));
    $lock = $pdo->prepare("SELECT id FROM productos WHERE id IN (SELECT producto_id FROM plato_items WHERE plato_id IN ($in)) FOR UPDATE");
    $lock->execute(array_values($platoIds));
    $lock->fetchAll();

    $calc = calcular_consumo($pdo, $platoIds, $total);
    if ($calc['faltantes'] > 0 && !$forzar) {
        $faltan = [];
        foreach ($calc['productos'] as $p) if ($p['estado'] === 'falta') $faltan[] = $p['nombre'] . ' (faltan ' . num(-$p['queda']) . ' ' . $p['unidad'] . ')';
        throw new RuntimeException('No alcanza el stock para el ' . mb_strtolower($servicio) . ': ' . implode(', ', $faltan) . '. Cargá la compra, o tildá «Descontar igual».');
    }

    $nombre = mb_substr(implode(', ', array_column($calc['platos'], 'nombre')), 0, 255);
    $pdo->prepare('INSERT INTO servicios (fecha, servicio, dia_menu, menu_nombre, comensales, costo_total, observaciones, usuario_id) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$fecha, $servicio, $dia, $nombre, $total, round($calc['costo_total'], 2), mb_substr($obs, 0, 255), usuario()['id'] ?? null]);
    $sid = (int)$pdo->lastInsertId();

    $insC = $pdo->prepare('INSERT INTO servicio_contingentes (servicio_id, contingente_id, personas) VALUES (?,?,?)');
    foreach ($pax as $cid => $p) if ($p > 0) $insC->execute([$sid, $cid, $p]);

    $insI = $pdo->prepare('INSERT INTO servicio_items (servicio_id, producto_id, cantidad, costo_unitario) VALUES (?,?,?,?)');
    $nota = $servicio . ' Menú ' . $dia . ' × ' . $total . ' pax';
    foreach ($calc['productos'] as $prod => $p) {
        if ($p['necesario'] <= 0) continue;
        $insI->execute([$sid, $prod, $p['necesario'], $p['costo']]);
        mover_stock($pdo, $prod, -$p['necesario'], 'consumo', 'servicio', $sid, $nota);
    }
    return $sid;
}

/** Anula un servicio y devuelve la mercadería al stock. Debe llamarse dentro de una transacción. */
function anular_servicio(PDO $pdo, int $sid): void
{
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
}

/** Devuelve al stock todo lo que se descontó para un contingente. Si $borrar, elimina esos registros. */
function deshacer_consumo(PDO $pdo, int $cid, bool $borrar = false): void
{
    $st = $pdo->prepare('SELECT DISTINCT s.id, s.anulado FROM servicios s JOIN servicio_contingentes sc ON sc.servicio_id = s.id WHERE sc.contingente_id = ?');
    $st->execute([$cid]);
    foreach ($st->fetchAll() as $r) {
        if (!$r['anulado']) anular_servicio($pdo, (int)$r['id']);
        if ($borrar) $pdo->prepare('DELETE FROM servicios WHERE id = ?')->execute([$r['id']]);
    }
}

/** Etiqueta del estado de un contingente respecto de una fecha */
function estado_contingente(array $c, ?string $hoy = null): array
{
    $hoy = $hoy ?: date('Y-m-d');
    if ($c['cancelado']) return ['Cancelado', 'b-muted'];
    if ($hoy < $c['fecha_llegada']) return ['Próximo', 'b-info'];
    if ($hoy > $c['fecha_salida']) return ['Finalizado', 'b-muted'];
    $dia = (int)((strtotime($hoy) - strtotime($c['fecha_llegada'])) / 86400) + 1;
    return ['En el hotel · día ' . $dia . ' de ' . (int)$c['dias'], 'b-ok'];
}

/** <option> de productos activos con la unidad en data-unidad */
function opciones_productos(PDO $pdo, $seleccionado = null): string
{
    static $lista = null;
    if ($lista === null) {
        $lista = $pdo->query('SELECT id, nombre, unidad, categoria, peso_unidad FROM productos WHERE activo = 1 ORDER BY categoria, nombre')->fetchAll();
    }
    $html = '<option value="">— Elegir producto —</option>';
    $cat = null;
    foreach ($lista as $p) {
        if ($p['categoria'] !== $cat) {
            if ($cat !== null) $html .= '</optgroup>';
            $cat = $p['categoria'];
            $html .= '<optgroup label="' . e($cat !== '' ? $cat : 'Sin categoría') . '">';
        }
        $sel = ((int)$seleccionado === (int)$p['id']) ? ' selected' : '';
        $html .= '<option value="' . (int)$p['id'] . '" data-unidad="' . e($p['unidad']) . '" data-peso="' . e((float)$p['peso_unidad']) . '"' . $sel . '>' . e($p['nombre']) . ' (' . e($p['unidad']) . ')</option>';
    }
    if ($cat !== null) $html .= '</optgroup>';
    return $html;
}

/** <option> de unidades para una fila de receta */
function opciones_unidades(string $unidadProducto, string $sel): string
{
    $h = '';
    foreach (unidades_receta($unidadProducto) as $u) {
        $h .= '<option' . ($u === $sel ? ' selected' : '') . '>' . e($u) . '</option>';
    }
    return $h;
}

function badge_tipo(string $t): string
{
    $map = [
        'compra' => ['Compra', 'b-ok'],
        'consumo' => ['Consumo', 'b-info'],
        'ajuste' => ['Ajuste', 'b-warn'],
        'anulacion' => ['Anulación', 'b-muted'],
    ];
    [$txt, $cls] = $map[$t] ?? [$t, 'b-muted'];
    return '<span class="badge ' . $cls . '">' . $txt . '</span>';
}
