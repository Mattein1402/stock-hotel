/* Filas dinámicas: agregar / quitar */
document.addEventListener('click', function (e) {
    var add = e.target.closest('[data-add-row]');
    if (add) {
        var tpl = document.getElementById(add.dataset.template);
        var body = document.querySelector(add.dataset.addRow);
        body.appendChild(tpl.content.cloneNode(true));
        var fila = body.lastElementChild;
        actualizarUnidad(fila);
        if (typeof filaReceta === 'function') filaReceta(fila, true);
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

/* Cambios que recargan el formulario (fecha, comida, día del menú) */
document.addEventListener('change', function (e) {
    var el = e.target.closest('[data-autosubmit]');
    if (!el) return;
    var f = el.form;
    var modo = el.dataset.autosubmit;
    if (modo === 'reset') {
        // nueva fecha/comida: el sistema vuelve a armar comensales y menú solo
        f.querySelectorAll('[name=armado]').forEach(function (i) { i.remove(); });
    } else if (modo === 'dia') {
        var h = document.createElement('input');
        h.type = 'hidden'; h.name = 'cambio_dia'; h.value = '1';
        f.appendChild(h);
    } else if (modo === 'post' && !el.value) {
        return;
    }
    f.submit();
});

/* Total de comensales en vivo */
function totalPax() {
    var out = document.querySelector('[data-pax-total]');
    if (!out) return;
    var t = 0;
    document.querySelectorAll('[data-pax]').forEach(function (i) {
        var chk = i.closest('tr').querySelector('[data-pax-check]');
        if (!chk || chk.checked) t += parseInt(i.value, 10) || 0;
    });
    var ex = document.querySelector('[data-pax-extra]');
    if (ex) t += parseInt(ex.value, 10) || 0;
    out.textContent = t;
}
document.addEventListener('input', function (e) { if (e.target.matches('[data-pax], [data-pax-extra]')) totalPax(); });
document.addEventListener('change', function (e) { if (e.target.matches('[data-pax-check]')) totalPax(); });

/* Duración de la estadía (llegada → salida) */
function previewDias() {
    document.querySelectorAll('[data-dias-preview]').forEach(function (p) {
        var f = p.closest('form');
        var ll = f.querySelector('[data-llegada]'), sa = f.querySelector('[data-salida]');
        if (!ll || !sa || !ll.value || !sa.value) { p.textContent = ''; return; }
        var d = Math.round((new Date(sa.value + 'T12:00:00') - new Date(ll.value + 'T12:00:00')) / 86400000) + 1;
        p.textContent = d < 1 ? '⚠ La salida es anterior a la llegada.' : 'Estadía de ' + d + ' día' + (d > 1 ? 's' : '') + '.';
    });
}
document.addEventListener('change', function (e) {
    if (!e.target.matches('[data-llegada], [data-salida]')) return;
    var f = e.target.closest('form');
    var ll = f.querySelector('[data-llegada]'), sa = f.querySelector('[data-salida]');
    if (e.target === ll && sa && ll.value && sa.value < ll.value) sa.value = ll.value;
    previewDias();
});
previewDias();

/* Recetas: unidades y conversión a la unidad del producto */
var UNIDADES = {
    g: ['masa', 1], kg: ['masa', 1000],
    ml: ['volumen', 1], cc: ['volumen', 1], l: ['volumen', 1000],
    cucharadita: ['volumen', 5], cucharada: ['volumen', 15], taza: ['volumen', 250],
    unidad: ['cantidad', 1], docena: ['cantidad', 12]
};
function uInfo(u) { return UNIDADES[u] || [u, 1]; }
function convertirProducto(c, de, prod, peso) {
    var d = uInfo(de), p = uInfo(prod), fis = ['masa', 'volumen'];
    if (de === prod) return c;
    if (d[0] === p[0]) return c * d[1] / p[1];
    if (!peso) return null;
    if (fis.indexOf(d[0]) >= 0 && fis.indexOf(p[0]) < 0) {
        var piezas = c * d[1] / peso;
        return p[0] === 'cantidad' ? piezas / p[1] : piezas;
    }
    if (d[0] === 'cantidad' && fis.indexOf(p[0]) >= 0) return c * d[1] * peso / p[1];
    return null;
}
function fmt(n) {
    return n.toLocaleString('es-AR', { maximumFractionDigits: n < 0.01 ? 5 : (n < 1 ? 4 : 3) });
}
function filaReceta(tr, cambioProducto) {
    var sp = tr.querySelector('select.sel-producto'), su = tr.querySelector('select.sel-unidad');
    if (!sp || !su) return;
    var op = sp.options[sp.selectedIndex];
    var up = op && op.dataset.unidad ? op.dataset.unidad : '';
    var peso = op ? parseFloat(op.dataset.peso) || 0 : 0;
    if (cambioProducto && up) {
        // la unidad del producto primero, después el resto
        var lista = [up].concat(Object.keys(UNIDADES).filter(function (u) { return u !== up; }));
        su.innerHTML = lista.map(function (u) { return '<option>' + u + '</option>'; }).join('');
        su.value = up;
    }
    var out = tr.querySelector('.conv');
    if (!out) return;
    var c = leerNum(tr.querySelector('[data-cant]').value);
    if (!up || !c) { out.textContent = ''; out.className = 'conv small muted'; return; }
    var r = convertirProducto(c, su.value, up, peso);
    if (r === null) {
        out.textContent = '⚠ cargá el peso de 1 unidad del producto';
        out.className = 'conv small aviso';
    } else {
        out.textContent = su.value === up ? '' : '= ' + fmt(r) + ' ' + up;
        out.className = 'conv small muted';
    }
}
document.addEventListener('change', function (e) {
    if (e.target.matches('.tabla-receta select.sel-producto')) filaReceta(e.target.closest('tr'), true);
    if (e.target.matches('.tabla-receta select.sel-unidad')) filaReceta(e.target.closest('tr'), false);
});
document.addEventListener('input', function (e) {
    if (e.target.matches('.tabla-receta [data-cant]')) filaReceta(e.target.closest('tr'), false);
});
document.querySelectorAll('.tabla-receta tbody tr').forEach(function (tr) { filaReceta(tr, false); });

var res = document.getElementById('resultado');
if (res) res.scrollIntoView({ behavior: 'smooth', block: 'start' });

document.querySelectorAll('tr').forEach(actualizarUnidad);
recalcular();
