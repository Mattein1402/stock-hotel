<?php
$u = usuario();
$pagina = basename($_SERVER['PHP_SELF']);
$links = [
    'index.php' => ['Inicio', false],
    'cocinar.php' => ['Cocinar', false],
    'productos.php' => ['Stock', false],
    'compras.php' => ['Compras', true],
    'menus.php' => ['Menús', true],
    'movimientos.php' => ['Movimientos', false],
    'usuarios.php' => ['Usuarios', true],
];
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(($titulo ?? '') . ' · ' . APP_NOMBRE) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<?php if ($u): ?>
<header class="top">
    <a class="brand" href="index.php"><?= e(APP_NOMBRE) ?></a>
    <button class="menu-toggle" type="button" onclick="document.body.classList.toggle('nav-open')" aria-label="Menú">☰</button>
    <nav>
        <?php foreach ($links as $href => [$txt, $soloAdmin]):
            if ($soloAdmin && !es_admin()) continue; ?>
            <a href="<?= $href ?>" class="<?= $pagina === $href ? 'activo' : '' ?>"><?= $txt ?></a>
        <?php endforeach; ?>
        <a href="logout.php" class="salir">Salir · <?= e($u['nombre']) ?></a>
    </nav>
</header>
<?php endif; ?>
<main class="wrap">
<?php foreach ($_SESSION['flash'] ?? [] as [$t, $m]): ?>
    <div class="flash <?= e($t) ?>"><?= e($m) ?></div>
<?php endforeach; unset($_SESSION['flash']); ?>
