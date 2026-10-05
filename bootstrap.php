<?php
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

const UNIDADES = ['kg', 'g', 'l', 'ml', 'unidad', 'docena', 'paquete', 'caja', 'lata', 'atado'];
const TIPOS_MENU = ['Desayuno', 'Almuerzo', 'Merienda', 'Cena', 'Evento', 'Otro'];

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

/** Lee las filas menú + comensales de un formulario */
function leer_lineas(array $src): array
{
    $out = [];
    $menus = (array)($src['menu_id'] ?? []);
    $pax = (array)($src['comensales'] ?? []);
    foreach ($menus as $i => $m) {
        $out[] = ['menu_id' => (int)$m, 'comensales' => (int)($pax[$i] ?? 0)];
    }
    return $out;
}

/**
 * Calcula cuánta materia prima se necesita para los menús elegidos.
 * $lineas = [['menu_id' => 1, 'comensales' => 30], ...]
 */
function calcular_consumo(PDO $pdo, array $lineas): array
{
    $res = ['lineas' => [], 'productos' => [], 'costo_total' => 0.0, 'comensales' => 0, 'faltantes' => 0, 'sin_receta' => []];

    $stMenu = $pdo->prepare('SELECT id, nombre, tipo FROM menus WHERE id = ? AND activo = 1');
    $stItems = $pdo->prepare(
        'SELECT mi.producto_id, mi.cantidad_por_persona, p.nombre, p.unidad, p.stock, p.stock_minimo, p.costo_promedio
         FROM menu_items mi JOIN productos p ON p.id = mi.producto_id
         WHERE mi.menu_id = ?'
    );

    foreach ($lineas as $l) {
        $mid = (int)$l['menu_id'];
        $pax = (int)$l['comensales'];
        if ($mid <= 0 || $pax <= 0) continue;

        $stMenu->execute([$mid]);
        $menu = $stMenu->fetch();
        if (!$menu) continue;

        $stItems->execute([$mid]);
        $filas = $stItems->fetchAll();
        if (!$filas) $res['sin_receta'][] = $menu['nombre'];

        $items = [];
        $costo = 0.0;
        foreach ($filas as $it) {
            $pid = (int)$it['producto_id'];
            $cant = round((float)$it['cantidad_por_persona'] * $pax, 3);
            $items[] = ['producto_id' => $pid, 'cantidad' => $cant, 'costo_unitario' => (float)$it['costo_promedio']];
            $costo += $cant * (float)$it['costo_promedio'];

            if (!isset($res['productos'][$pid])) {
                $res['productos'][$pid] = [
                    'nombre' => $it['nombre'],
                    'unidad' => $it['unidad'],
                    'stock' => (float)$it['stock'],
                    'minimo' => (float)$it['stock_minimo'],
                    'costo' => (float)$it['costo_promedio'],
                    'necesario' => 0.0,
                ];
            }
            $res['productos'][$pid]['necesario'] += $cant;
        }

        $res['lineas'][] = [
            'menu_id' => $mid,
            'menu_nombre' => $menu['nombre'],
            'tipo' => $menu['tipo'],
            'comensales' => $pax,
            'items' => $items,
            'costo' => $costo,
        ];
        $res['costo_total'] += $costo;
        $res['comensales'] += $pax;
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

/** <option> de productos activos con la unidad en data-unidad */
function opciones_productos(PDO $pdo, $seleccionado = null): string
{
    static $lista = null;
    if ($lista === null) {
        $lista = $pdo->query('SELECT id, nombre, unidad, categoria FROM productos WHERE activo = 1 ORDER BY categoria, nombre')->fetchAll();
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
        $html .= '<option value="' . (int)$p['id'] . '" data-unidad="' . e($p['unidad']) . '"' . $sel . '>' . e($p['nombre']) . ' (' . e($p['unidad']) . ')</option>';
    }
    if ($cat !== null) $html .= '</optgroup>';
    return $html;
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
