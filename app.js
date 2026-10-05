/* Filas dinámicas: agregar / quitar */
document.addEventListener('click', function (e) {
    var add = e.target.closest('[data-add-row]');
    if (add) {
        var tpl = document.getElementById(add.dataset.template);
        var body = document.querySelector(add.dataset.addRow);
        body.appendChild(tpl.content.cloneNode(true));
        var fila = body.lastElementChild;
        actualizarUnidad(fila);
        var primero = fila.querySelector('select, input');
        if (primero) primero.focus();
    }
    var del = e.target.closest('[data-del-row]');
    if (del) {
        var tr = del.closest('tr');
        if (tr.parentElement.children.length > 1) {
            tr.remove();
        } else {
            tr.querySelectorAll('input, select').forEach(function (i) { i.value = ''; });
            actualizarUnidad(tr);
        }
        recalcular();
    }
});

/* Muestra la unidad del producto elegido */
document.addEventListener('change', function (e) {
    if (e.target.matches('select.sel-producto')) actualizarUnidad(e.target.closest('tr'));
});
function actualizarUnidad(tr) {
    if (!tr) return;
    var sel = tr.querySelector('select.sel-producto');
    var celda = tr.querySelector('.unidad');
    if (!sel || !celda) return;
    var op = sel.options[sel.selectedIndex];
    celda.textContent = op && op.dataset.unidad ? op.dataset.unidad : '';
}

/* Subtotales en compras */
function leerNum(v) {
    v = String(v || '').trim().replace(/\s/g, '');
    if (v.indexOf(',') !== -1) v = v.replace(/\./g, '').replace(',', '.');
    var n = parseFloat(v);
    return isNaN(n) ? 0 : n;
}
function plata(n) {
    return '$ ' + n.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function recalcular() {
    document.querySelectorAll('.tabla-compra').forEach(function (t) {
        var total = 0;
        t.querySelectorAll('tbody tr').forEach(function (tr) {
            var c = tr.querySelector('[name="cantidad[]"]');
            var p = tr.querySelector('[name="costo[]"]');
            var s = leerNum(c && c.value) * leerNum(p && p.value);
            total += s;
            var sub = tr.querySelector('.subtotal');
            if (sub) sub.textContent = s ? plata(s) : '';
        });
        var tt = t.querySelector('.total');
        if (tt) tt.textContent = plata(total);
    });
}
document.addEventListener('input', function (e) {
    if (e.target.closest('.tabla-compra')) recalcular();
});

/* Confirmaciones y evitar doble envío */
document.addEventListener('submit', function (e) {
    var f = e.target;
    if (f.dataset.confirm && !confirm(f.dataset.confirm)) {
        e.preventDefault();
        return;
    }
    if (f.hasAttribute('data-once')) {
        var b = f.querySelector('button:not([type=button])');
        if (b) setTimeout(function () { b.disabled = true; b.textContent = 'Guardando...'; }, 0);
    }
});

document.querySelectorAll('tr').forEach(actualizarUnidad);
recalcular();
