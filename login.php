<?php
require __DIR__ . '/inc/bootstrap.php';
if (usuario()) redirect('index.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $st = db()->prepare('SELECT * FROM usuarios WHERE usuario = ? AND activo = 1');
        $st->execute([trim($_POST['usuario'] ?? '')]);
        $u = $st->fetch();
        if ($u && password_verify((string)($_POST['pass'] ?? ''), $u['pass_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user'] = ['id' => (int)$u['id'], 'nombre' => $u['nombre'], 'rol' => $u['rol']];
            redirect($u['rol'] === 'cocina' ? 'cocinar.php' : 'index.php');
        }
        $error = 'Usuario o contraseña incorrectos.';
    } catch (PDOException $ex) {
        $error = 'No se pudo conectar a la base. ¿Ya ejecutaste install.php?';
    }
}

$titulo = 'Ingresar';
require __DIR__ . '/inc/header.php';
?>
<div class="card login">
    <h1><?= e(APP_NOMBRE) ?></h1>
    <p class="muted">Control de stock de cocina</p>
    <?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
        <?= csrf_field() ?>
        <label>Usuario<input name="usuario" required autofocus autocomplete="username"></label>
        <label>Contraseña<input type="password" name="pass" required autocomplete="current-password"></label>
        <button class="btn">Ingresar</button>
    </form>
</div>
<?php require __DIR__ . '/inc/footer.php'; ?>
