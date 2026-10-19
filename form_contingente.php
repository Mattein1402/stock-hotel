<?php
/* Campos del formulario de contingente. Espera $c con:
   numero, personas, fecha_llegada, fecha_salida, observaciones */
$autofocus = $autofocus ?? true;
?>
<div class="grid-2">
    <label>N° de contingente<input name="numero" required value="<?= e($c['numero']) ?>" placeholder="Ej: 1254" inputmode="numeric" <?= $autofocus ? 'autofocus' : '' ?>></label>
    <label>Personas que vienen<input type="number" name="personas" min="1" required value="<?= e($c['personas']) ?>" inputmode="numeric" placeholder="Ej: 45"></label>
</div>
<div class="grid-2">
    <label>Fecha de llegada<input type="date" name="fecha_llegada" required value="<?= e($c['fecha_llegada']) ?>" data-llegada></label>
    <label>Fecha de salida<input type="date" name="fecha_salida" required value="<?= e($c['fecha_salida']) ?>" data-salida></label>
</div>
<p class="muted small" data-dias-preview></p>
<label>Observaciones (opcional)<input name="observaciones" value="<?= e($c['observaciones']) ?>" placeholder="Ej: seccional, coordinador, dietas especiales..."></label>
