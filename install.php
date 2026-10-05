<?php
require __DIR__ . '/inc/bootstrap.php';

$error = '';
$pdo = null;
try {
    $pdo = db();
} catch (PDOException $ex) {
    $error = 'No se pudo conectar a la base de datos. Revisá los datos en config.php. Detalle: ' . $ex->getMessage();
}

$instalado = false;
if ($pdo) {
    try {
        $instalado = (int)$pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() > 0;
    } catch (PDOException $ex) {
        $instalado = false; // la tabla todavía no existe
    }
}

function cargar_ejemplo(PDO $pdo): void
{
    // nombre, categoría, unidad, stock inicial, mínimo, costo unitario
    $productos = [
        ['Arroz', 'Almacén', 'kg', 20, 5, 1800],
        ['Fideos secos', 'Almacén', 'kg', 15, 5, 2000],
        ['Harina 000', 'Almacén', 'kg', 10, 3, 900],
        ['Aceite de girasol', 'Almacén', 'l', 12, 4, 2500],
        ['Azúcar', 'Almacén', 'kg', 8, 2, 1300],
        ['Café molido', 'Almacén', 'kg', 3, 1, 18000],
        ['Pan rallado', 'Almacén', 'kg', 5, 2, 2200],
        ['Tomate triturado', 'Almacén', 'l', 12, 4, 1500],
        ['Mermelada', 'Almacén', 'kg', 4, 1, 5000],
        ['Pechuga de pollo', 'Carnes', 'kg', 25, 8, 6500],
        ['Carne vacuna (nalga)', 'Carnes', 'kg', 18, 6, 11000],
        ['Huevos', 'Lácteos y huevos', 'unidad', 180, 60, 250],
        ['Leche', 'Lácteos y huevos', 'l', 30, 10, 1300],
        ['Manteca', 'Lácteos y huevos', 'kg', 3, 1, 9000],
        ['Queso rallado', 'Lácteos y huevos', 'kg', 3, 1, 12000],
        ['Papa', 'Verdulería', 'kg', 40, 10, 900],
        ['Cebolla', 'Verdulería', 'kg', 15, 5, 800],
        ['Lechuga', 'Verdulería', 'unidad', 15, 5, 1200],
        ['Pan francés', 'Panadería', 'kg', 8, 3, 2800],
        ['Medialunas', 'Panadería', 'unidad', 120, 40, 450],
    ];
    $ins = $pdo->prepare('INSERT INTO productos (nombre, categoria, unidad, stock_minimo, costo_promedio) VALUES (?,?,?,?,?)');
    $ids = [];
    foreach ($productos as [$n, $c, $u, $stock, $min, $costo]) {
        $ins->execute([$n, $c, $u, $min, $costo]);
        $ids[$n] = (int)$pdo->lastInsertId();
        mover_stock($pdo, $ids[$n], $stock, 'ajuste', null, null, 'Stock inicial');
    }

    // Recetas: cantidad de materia prima POR PERSONA
    $menus = [
        ['Desayuno continental', 'Desayuno', 'Café con leche, medialunas, pan con manteca y mermelada', [
            'Café molido' => 0.012, 'Leche' => 0.15, 'Azúcar' => 0.015, 'Medialunas' => 2,
            'Pan francés' => 0.05, 'Manteca' => 0.01, 'Mermelada' => 0.02,
        ]],
        ['Pollo al horno con papas', 'Almuerzo', '', [
            'Pechuga de pollo' => 0.25, 'Papa' => 0.3, 'Cebolla' => 0.05, 'Aceite de girasol' => 0.02,
        ]],
        ['Milanesas con puré', 'Cena', '', [
            'Carne vacuna (nalga)' => 0.2, 'Huevos' => 0.5, 'Pan rallado' => 0.06, 'Aceite de girasol' => 0.05,
            'Papa' => 0.3, 'Leche' => 0.05, 'Manteca' => 0.01,
        ]],
        ['Fideos con tuco', 'Cena', '', [
            'Fideos secos' => 0.12, 'Tomate triturado' => 0.1, 'Cebolla' => 0.04,
            'Aceite de girasol' => 0.01, 'Queso rallado' => 0.015,
        ]],
    ];
    $insM = $pdo->prepare('INSERT INTO menus (nombre, tipo, descripcion) VALUES (?,?,?)');
    $insI = $pdo->prepare('INSERT INTO menu_items (menu_id, producto_id, cantidad_por_persona) VALUES (?,?,?)');
    foreach ($menus as [$nombre, $tipo, $desc, $receta]) {
        $insM->execute([$nombre, $tipo, $desc]);
        $mid = (int)$pdo->lastInsertId();
        foreach ($receta as $prod => $cant) {
            $insI->execute([$mid, $ids[$prod], $cant]);
        }
    }
}

if ($pdo && !$instalado && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $nombre = trim($_POST['nombre'] ?? '');
    $user = trim($_POST['usuario'] ?? '');
    $pass = (string)($_POST['pass'] ?? '');

    if ($nombre === '' || $user === '' || strlen($pass) < 6) {
        $error = 'Completá nombre y usuario, y usá una contraseña de al menos 6 caracteres.';
    } else {
        try {
            $sql = file_get_contents(__DIR__ . '/database.sql');
            $sql = preg_replace('/^--.*$/m', '', $sql);
            foreach (explode(';', $sql) as $q) {
                if (trim($q) !== '') $pdo->exec($q);
            }

            $pdo->prepare('INSERT INTO usuarios (nombre, usuario, pass_hash, rol) VALUES (?,?,?,?)')
                ->execute([$nombre, $user, password_hash($pass, PASSWORD_DEFAULT), 'admin']);
            $uid = (int)$pdo->lastInsertId();
            session_regenerate_id(true);
            $_SESSION['user'] = ['id' => $uid, 'nombre' => $nombre, 'rol' => 'admin'];

            if (!empty($_POST['ejemplo'])) {
                $pdo->beginTransaction();
                cargar_ejemplo($pdo);
                $pdo->commit();
            }
            flash('¡Sistema instalado! Por seguridad, borrá install.php del servidor.');
            redirect('index.php');
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Error durante la instalación: ' . $ex->getMessage();
        }
    }
}

$titulo = 'Instalación';
require __DIR__ . '/inc/header.php';
?>
<div class="card login">
    <h1>Instalación</h1>
    <?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>

    <?php if ($instalado): ?>
        <p>El sistema ya está instalado. Por seguridad, <strong>borrá el archivo install.php</strong> del servidor.</p>
        <p><a class="btn" href="login.php">Ir al ingreso</a></p>
    <?php elseif ($pdo): ?>
        <p class="muted">Se van a crear las tablas en la base <strong><?= e(DB_NAME) ?></strong> y el usuario administrador.</p>
        <form method="post">
            <?= csrf_field() ?>
            <label>Tu nombre<input name="nombre" required value="<?= e($_POST['nombre'] ?? '') ?>"></label>
            <label>Usuario<input name="usuario" required autocomplete="username" value="<?= e($_POST['usuario'] ?? 'admin') ?>"></label>
            <label>Contraseña (mín. 6)<input type="password" name="pass" required minlength="6" autocomplete="new-password"></label>
            <label class="check"><input type="checkbox" name="ejemplo" value="1" checked> Cargar productos y menús de ejemplo (después los podés editar o desactivar)</label>
            <button class="btn">Instalar</button>
        </form>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/inc/footer.php'; ?>
