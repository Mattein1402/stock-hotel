<?php
require __DIR__ . '/inc/bootstrap.php';
require_admin();
$pdo = db();
$yo = usuario()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $acc = $_POST['accion'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($acc === 'crear') {
        $nombre = trim($_POST['nombre'] ?? '');
        $user = trim($_POST['usuario'] ?? '');
        $pass = (string)($_POST['pass'] ?? '');
        $rol = ($_POST['rol'] ?? '') === 'admin' ? 'admin' : 'cocina';
        if ($nombre === '' || $user === '' || strlen($pass) < 6) {
            flash('Completá todo. La contraseña debe tener al menos 6 caracteres.', 'error');
        } else {
            try {
                $pdo->prepare('INSERT INTO usuarios (nombre, usuario, pass_hash, rol) VALUES (?,?,?,?)')
                    ->execute([$nombre, $user, password_hash($pass, PASSWORD_DEFAULT), $rol]);
                flash('Usuario creado.');
            } catch (PDOException $ex) {
                flash('Ese nombre de usuario ya existe.', 'error');
            }
        }
    }
    if ($acc === 'clave') {
        $pass = (string)($_POST['pass'] ?? '');
        if (strlen($pass) < 6) {
            flash('La contraseña debe tener al menos 6 caracteres.', 'error');
        } else {
            $pdo->prepare('UPDATE usuarios SET pass_hash=? WHERE id=?')->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
            flash('Contraseña actualizada.');
        }
    }
    if ($acc === 'estado' && $id !== $yo) {
        $pdo->prepare('UPDATE usuarios SET activo = 1 - activo WHERE id=?')->execute([$id]);
        flash('Estado actualizado.');
    }
    redirect('usuarios.php');
}

$usuarios = $pdo->query('SELECT * FROM usuarios ORDER BY activo DESC, nombre')->fetchAll();
$titulo = 'Usuarios';
require __DIR__ . '/inc/header.php';
?>
<h1>Usuarios</h1>
<p class="muted"><strong>Administrador:</strong> todo el sistema. <strong>Cocina:</strong> registra servicios (cocinar) y consulta stock y movimientos.</p>
<div class="card">
    <div class="scroll">
    <table class="tabla">
        <thead><tr><th>Nombre</th><th>Usuario</th><th>Rol</th><th>Nueva contraseña</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($usuarios as $u): ?>
            <tr class="<?= $u['activo'] ? '' : 'anulado' ?>">
                <td><?= e($u['nombre']) ?><?= $u['activo'] ? '' : ' <span class="badge b-muted">Inactivo</span>' ?></td>
                <td><?= e($u['usuario']) ?></td>
                <td><?= $u['rol'] === 'admin' ? 'Administrador' : 'Cocina' ?></td>
                <td>
                    <form method="post" class="inline">
                        <?= csrf_field() ?><input type="hidden" name="accion" value="clave"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                        <input type="password" name="pass" minlength="6" placeholder="Nueva" required autocomplete="new-password"><button class="btn-link">Cambiar</button>
                    </form>
                </td>
                <td>
                    <?php if ((int)$u['id'] !== $yo): ?>
                    <form method="post">
                        <?= csrf_field() ?><input type="hidden" name="accion" value="estado"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                        <button class="btn-link"><?= $u['activo'] ? 'Desactivar' : 'Activar' ?></button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<form method="post" class="card">
    <?= csrf_field() ?><input type="hidden" name="accion" value="crear">
    <h2>Nuevo usuario</h2>
    <div class="grid-2">
        <label>Nombre<input name="nombre" required></label>
        <label>Usuario<input name="usuario" required autocomplete="off"></label>
        <label>Contraseña<input type="password" name="pass" minlength="6" required autocomplete="new-password"></label>
        <label>Rol<select name="rol"><option value="cocina">Cocina</option><option value="admin">Administrador</option></select></label>
    </div>
    <button class="btn">Crear usuario</button>
</form>
<?php require __DIR__ . '/inc/footer.php'; ?>
