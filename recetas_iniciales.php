<?php
/**
 * Recetas por persona tomadas de las fichas de cocina (columna «1 persona").
 * Cada ingrediente: [producto, cantidad, unidad en la que está escrita la ficha].
 * El sistema la convierte sola a la unidad del producto.
 */

/**
 * Productos que usan las recetas: nombre => [categoría, unidad de stock, peso de 1 unidad (g/ml) o null, otros nombres]
 * Pesos por unidad sacados de las fichas: frutas (0,3 manzana = 60 g...), costeleta 350-400 g,
 * lata de durazno (1 lata para 10 porciones de 50 g), caja de helado 12 L.
 * Cebolla 150 g: dato a confirmar (se usa para «1/2 cebolla» de la pata muslo).
 */
function productos_recetas(): array
{
    return [
        'Paleta' => ['Carnes', 'kg', null, ['Paleta vacuna']],
        'Carne molida' => ['Carnes', 'kg', null, []],
        'Costeleta' => ['Carnes', 'unidad', 375, ['Costeletas']],
        'Bife de nalga' => ['Carnes', 'unidad', null, ['Bifes de nalga']],
        'Pata muslo' => ['Carnes', 'unidad', null, ['Pata y muslo', 'Pata muslo de pollo']],
        'Pechuga de pollo' => ['Carnes', 'unidad', null, ['Pechuga', 'Pechugas']],
        'Jamón cocido' => ['Fiambres', 'kg', null, ['Jamón', 'Jamon cocido', 'Jamon']],
        'Muzzarella' => ['Lácteos y huevos', 'kg', null, ['Mozzarella', 'Muzarella', 'Queso muzzarella']],
        'Queso reggianito' => ['Lácteos y huevos', 'kg', null, ['Reggianito']],
        'Leche' => ['Lácteos y huevos', 'l', null, []],
        'Manteca' => ['Lácteos y huevos', 'kg', null, []],
        'Huevos' => ['Lácteos y huevos', 'unidad', null, ['Huevo']],
        'Cebolla' => ['Verdulería', 'kg', 150, []],
        'Cebolla de verdeo' => ['Verdulería', 'kg', null, ['Verdeo']],
        'Tomate' => ['Verdulería', 'kg', null, ['Tomate perita', 'Tomate redondo']],
        'Pimiento morrón' => ['Verdulería', 'unidad', null, ['Pimiento', 'Morrón', 'Morron', 'Pimiento rojo']],
        'Lechuga' => ['Verdulería', 'kg', null, []],
        'Limón' => ['Verdulería', 'unidad', null, ['Limon', 'Limones']],
        'Albahaca' => ['Verdulería', 'kg', null, []],
        'Zapallitos' => ['Verdulería', 'kg', null, ['Zapallito']],
        'Calabaza' => ['Verdulería', 'kg', null, []],
        'Papa' => ['Verdulería', 'kg', null, ['Papas']],
        'Manzana' => ['Frutas', 'kg', 200, []],
        'Naranja' => ['Frutas', 'kg', 200, []],
        'Banana' => ['Frutas', 'kg', 133, []],
        'Ciruela' => ['Frutas', 'kg', 50, []],
        'Kiwi' => ['Frutas', 'kg', 83, []],
        'Tomate triturado' => ['Almacén', 'l', null, []],
        'Puré de tomate' => ['Almacén', 'unidad', null, ['Pure de tomate', 'Puré de tomate (brick)']],
        'Tallarines' => ['Almacén', 'kg', null, ['Tallarines secos', 'Fideos tallarines']],
        'Arroz' => ['Almacén', 'kg', null, []],
        'Harina 000' => ['Almacén', 'kg', null, ['Harina']],
        'Harina leudante' => ['Almacén', 'kg', null, []],
        'Levadura' => ['Almacén', 'kg', null, ['Levadura fresca']],
        'Aceite de girasol' => ['Almacén', 'l', null, ['Aceite']],
        'Azúcar' => ['Almacén', 'kg', null, ['Azucar']],
        'Pan rallado' => ['Almacén', 'kg', null, []],
        'Condimento provenzal' => ['Almacén', 'kg', null, ['Provenzal']],
        'Flan en polvo vainilla' => ['Almacén', 'kg', null, ['Flan vainilla']],
        'Flan en polvo dulce de leche' => ['Almacén', 'kg', null, ['Flan dulce de leche']],
        'Durazno en almíbar' => ['Almacén', 'lata', 500, ['Duraznos en almíbar', 'Durazno en almibar']],
        'Discos de empanada' => ['Panadería', 'unidad', null, ['Tapas de empanada', 'Discos empanada']],
        'Masa para canastita' => ['Panadería', 'unidad', null, ['Masa canastita', 'Canastitas']],
        'Helado chocolate (caja 12 L)' => ['Postres', 'caja', 12000, ['Helado chocolate']],
        'Helado chantilly (caja 12 L)' => ['Postres', 'caja', 12000, ['Helado chantilly']],
    ];
}

/** Platos: nombre => [categoría, [ingredientes], otros nombres con los que puede estar en el menú] */
function recetas_iniciales(): array
{
    return [
        'Tallarines con carne estofada' => ['Principal', [
            ['Paleta', 350, 'g'], ['Cebolla', 150, 'g'], ['Pimiento morrón', 0.2, 'unidad'],
            ['Tomate triturado', 200, 'ml'], ['Puré de tomate', 0.2, 'unidad'], ['Tallarines', 100, 'g'], ['Queso reggianito', 25, 'g'],
        ], []],
        'Empanada árabe' => ['Entrada', [
            ['Carne molida', 21, 'g'], ['Tomate', 42, 'g'], ['Cebolla', 42, 'g'], ['Discos de empanada', 1, 'unidad'], ['Limón', 0.2, 'unidad'],
        ], []],
        'Flan de vainilla' => ['Postre', [
            ['Leche', 125, 'ml'], ['Flan en polvo vainilla', 16.25, 'g'], ['Azúcar', 31.25, 'g'],
        ], ['Flan vainilla']],
        'Flan de dulce de leche' => ['Postre', [
            ['Leche', 125, 'ml'], ['Flan en polvo dulce de leche', 16.25, 'g'], ['Azúcar', 31.25, 'g'],
        ], ['Flan dulce de leche']],
        'Paleta al horno con ensalada de lechuga y tomate' => ['Principal', [
            ['Paleta', 350, 'g'], ['Lechuga', 70, 'g'], ['Tomate', 100, 'g'],
        ], []],
        'Tarta de jamón y queso' => ['Entrada', [
            // masa: 80 g harina + 0,16 huevo (+ 40 ml de agua, no se descuenta) · relleno: 0,6 huevo duro
            ['Harina 000', 80, 'g'], ['Huevos', 0.16, 'unidad'], ['Jamón cocido', 30, 'g'], ['Muzzarella', 40, 'g'],
            ['Huevos', 0.6, 'unidad'], ['Tomate', 80, 'g'],
        ], []],
        'Ensalada de frutas' => ['Postre', [
            ['Manzana', 60, 'g'], ['Naranja', 60, 'g'], ['Banana', 40, 'g'], ['Ciruela', 30, 'g'], ['Kiwi', 25, 'g'],
            ['Durazno en almíbar', 50, 'g'], ['Azúcar', 15, 'g'],
        ], []],
        'Canastita de jamón y queso' => ['Entrada', [
            ['Masa para canastita', 1, 'unidad'], ['Jamón cocido', 30, 'g'], ['Muzzarella', 40, 'g'],
        ], ['Canastita']],
        'Pizza muzzarella' => ['Entrada', [
            // + 18 cc de agua (no se descuenta). Muzzarella 36-40 g: se toma 40 g
            ['Harina 000', 30, 'g'], ['Levadura', 1.5, 'g'], ['Aceite de girasol', 1, 'cc'], ['Tomate triturado', 40, 'cc'], ['Muzzarella', 40, 'g'],
        ], []],
        'Pata muslo con arroz amarillo' => ['Principal', [
            ['Pata muslo', 1, 'unidad'], ['Cebolla', 0.5, 'unidad'], ['Pimiento morrón', 0.2, 'unidad'], ['Arroz', 40, 'g'],
            ['Cebolla de verdeo', 25, 'g'], ['Queso reggianito', 25, 'g'],
        ], ['Pata muslo y arroz amarillo']],
        'Costeleta de vaca con papas fritas' => ['Principal', [
            // 200 g de papa frita cocida ≈ 375 g de papa con cáscara (aclaración de la ficha)
            ['Costeleta', 1, 'unidad'], ['Papa', 375, 'g'],
        ], ['Costeleta con papas fritas', 'Costeleta con papa fritas']],
        'Soufflé de zapallitos' => ['Entrada', [
            ['Zapallitos', 150, 'g'], ['Huevos', 0.6, 'unidad'], ['Cebolla', 30, 'g'], ['Pimiento morrón', 0.03, 'unidad'], ['Harina leudante', 5, 'g'],
        ], ['Souffle de zapallito', 'Soufflé de zapallito']],
        'Milanesa con puré mixto' => ['Principal', [
            ['Bife de nalga', 1, 'unidad'], ['Papa', 150, 'g'], ['Calabaza', 150, 'g'], ['Pan rallado', 100, 'g'], ['Huevos', 1, 'unidad'],
            ['Leche', 100, 'cc'], ['Condimento provenzal', 15, 'g'], ['Manteca', 50, 'g'],
        ], []],
        'Canastita caprese' => ['Entrada', [
            ['Masa para canastita', 1, 'unidad'], ['Muzzarella', 40, 'g'], ['Tomate', 50, 'g'], ['Albahaca', 5, 'g'],
        ], []],
        'Helado (chocolate y chantilly)' => ['Postre', [
            // 2 bochas: 0,0125 caja de cada sabor
            ['Helado chocolate (caja 12 L)', 0.0125, 'caja'], ['Helado chantilly (caja 12 L)', 0.0125, 'caja'],
        ], ['Helado bocha']],
        'Suprema con papas fritas' => ['Principal', [
            // 50 g de papa frita cocida ≈ 93,75 g de papa con cáscara (misma proporción que la costeleta)
            ['Pechuga de pollo', 0.25, 'unidad'], ['Huevos', 0.5, 'unidad'], ['Pan rallado', 250, 'g'], ['Condimento provenzal', 12.5, 'g'], ['Papa', 93.75, 'g'],
        ], ['Suprema con papa frita']],
    ];
}

/**
 * Carga productos y recetas. No pisa recetas que ya estén cargadas.
 * Devuelve un registro de lo que hizo (para mostrar al usuario).
 */
function cargar_recetas_iniciales(PDO $pdo): array
{
    $log = ['productos_nuevos' => [], 'platos_nuevos' => [], 'recetas' => [], 'salteadas' => [], 'pendientes' => []];

    // --- Productos: busca por nombre o alias, si no existe lo crea (stock 0) ---
    $buscar = $pdo->prepare('SELECT * FROM productos WHERE LOWER(nombre) = LOWER(?) LIMIT 1');
    $crear = $pdo->prepare('INSERT INTO productos (nombre, categoria, unidad, peso_unidad) VALUES (?,?,?,?)');
    $ponerPeso = $pdo->prepare('UPDATE productos SET peso_unidad = ? WHERE id = ? AND (peso_unidad IS NULL OR peso_unidad = 0)');
    $prod = [];
    foreach (productos_recetas() as $nombre => [$cat, $unidad, $peso, $alias]) {
        $fila = null;
        foreach (array_merge([$nombre], $alias) as $n) {
            $buscar->execute([$n]);
            if ($fila = $buscar->fetch()) break;
        }
        if (!$fila) {
            $crear->execute([$nombre, $cat, $unidad, $peso]);
            $buscar->execute([$nombre]);
            $fila = $buscar->fetch();
            $log['productos_nuevos'][] = $nombre;
        } elseif ($peso && empty($fila['peso_unidad'])) {
            $ponerPeso->execute([$peso, $fila['id']]);
            $fila['peso_unidad'] = $peso;
        }
        $prod[$nombre] = $fila;
    }

    // --- Platos y recetas ---
    $buscarPlato = $pdo->prepare('SELECT id FROM platos WHERE LOWER(nombre) = LOWER(?) LIMIT 1');
    $crearPlato = $pdo->prepare('INSERT INTO platos (nombre, categoria) VALUES (?,?)');
    $tieneReceta = $pdo->prepare('SELECT COUNT(*) FROM plato_items WHERE plato_id = ?');
    $insItem = $pdo->prepare('INSERT INTO plato_items (plato_id, producto_id, cantidad_por_persona, cantidad_receta, unidad_receta) VALUES (?,?,?,?,?)');

    foreach (recetas_iniciales() as $nombre => [$cat, $items, $alias]) {
        $pid = null;
        foreach (array_merge([$nombre], $alias) as $n) {
            $buscarPlato->execute([$n]);
            if ($pid = $buscarPlato->fetchColumn()) break;
        }
        if (!$pid) {
            $crearPlato->execute([$nombre, $cat]);
            $pid = $pdo->lastInsertId();
            $log['platos_nuevos'][] = $nombre;
        }
        $tieneReceta->execute([$pid]);
        if ((int)$tieneReceta->fetchColumn() > 0) {
            $log['salteadas'][] = $nombre;
            continue;
        }
        // Si un producto se repite en la ficha (ej. huevo de la masa + huevo duro) se suma
        $sum = [];
        foreach ($items as [$pn, $cant, $uni]) {
            $k = $pn . '|' . $uni;
            $sum[$k] = ['p' => $pn, 'u' => $uni, 'c' => ($sum[$k]['c'] ?? 0) + $cant];
        }
        $porProducto = [];
        foreach ($sum as $it) {
            $p = $prod[$it['p']];
            $conv = convertir_producto((float)$it['c'], $it['u'], $p['unidad'], $p['peso_unidad'] !== null ? (float)$p['peso_unidad'] : null);
            if ($conv === null) {
                $log['pendientes'][] = $nombre . ': ' . $it['p'] . ' (' . num($it['c']) . ' ' . $it['u'] . ') — cargá en el producto cuánto pesa 1 unidad';
                $conv = 0;
            }
            if (isset($porProducto[$p['id']])) {
                $porProducto[$p['id']]['conv'] += $conv;
                $porProducto[$p['id']]['cant'] = $porProducto[$p['id']]['conv'];
                $porProducto[$p['id']]['uni'] = $p['unidad'];
            } else {
                $porProducto[$p['id']] = ['conv' => $conv, 'cant' => $it['c'], 'uni' => $it['u']];
            }
        }
        foreach ($porProducto as $prodId => $v) {
            $insItem->execute([$pid, $prodId, round($v['conv'], 6), $v['cant'], $v['uni']]);
        }
        $log['recetas'][] = $nombre;
    }
    return $log;
}
