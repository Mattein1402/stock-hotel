<?php
/**
 * Menú 2025/2026 (ciclo de 10 días) — Lic. Héctor Cabezas
 * Cada plato: [nombre, categoría]. Los platos que se repiten
 * (Sopa, Postre, Fiambre surtido...) se crean una sola vez y
 * comparten la receta.
 */
function menu_inicial(): array
{
    $S = ['Sopa', 'Sopa'];
    $P = ['Postre', 'Postre'];
    return [
        1 => [
            'Almuerzo' => [['Fiambre surtido', 'Entrada'], ['Costeleta con ensalada (lechuga y tomate)', 'Principal'], $P],
            'Cena' => [$S, ['Escabeche de verduras', 'Entrada'], ['Tallarines con salsa bolognesa', 'Principal'], $P],
        ],
        2 => [
            'Almuerzo' => [['Timbal de arroz', 'Entrada'], ['Pata muslo con papas fritas', 'Principal'], $P],
            'Cena' => [$S, ['Canastita de cebolla y queso', 'Entrada'], ['Carne al horno con ensalada de remolacha, tomate y zanahoria', 'Principal'], $P],
        ],
        3 => [
            'Almuerzo' => [['Berenjena a la napolitana', 'Entrada'], ['Cerdo al horno con calabaza braseada', 'Principal'], $P],
            'Cena' => [$S, ['Ternerita a la ciboulette', 'Entrada'], ['Canelones a la rossini', 'Principal'], $P],
        ],
        4 => [
            'Almuerzo' => [['Canastita caprese', 'Entrada'], ['Pechuga grillada con arroz', 'Principal'], $P],
            'Cena' => [['Soufflé de zapallitos', 'Entrada'], ['Milanesa napolitana con puré', 'Principal'], $P],
        ],
        5 => [
            'Almuerzo' => [['Jamón con ensalada rusa', 'Entrada'], ['Ravioles con salsa filetto', 'Principal'], $P],
            'Cena' => [$S, ['Canastita de calabaza', 'Entrada'], ['Pastel de papas', 'Principal'], $P],
        ],
        6 => [
            'Almuerzo' => [['Terrina de acelga', 'Entrada'], ['Suprema con ensalada (tomate, zanahoria y repollo morado)', 'Principal'], $P],
            'Cena' => [$S, ['Escabeche de pollo', 'Entrada'], ['Variedad de pizzas', 'Principal'], $P],
        ],
        7 => [
            'Almuerzo' => [['Humita', 'Entrada'], ['Carne al horno con tortillas de papas', 'Principal'], $P],
            'Cena' => [$S, ['Lengua a la vinagreta', 'Entrada'], ['Ñoquis con salsa bolognesa', 'Principal'], $P],
        ],
        8 => [
            'Almuerzo' => [['Fiambre surtido', 'Entrada'], ['Lasagna con salsa filetto', 'Principal'], $P],
            'Cena' => [$S, ['Empanada criolla', 'Entrada'], ['Pata muslo con papas a la crema', 'Principal'], $P],
        ],
        9 => [
            'Almuerzo' => [['Canastita de verduras', 'Entrada'], ['Albóndigas con puré', 'Principal'], $P],
            'Cena' => [$S, ['Pizza muzzarella', 'Entrada'], ['Milanesa con ensalada (lechuga y tomate)', 'Principal'], $P],
        ],
        10 => [
            'Almuerzo' => [['Arrollado primavera', 'Entrada'], ['Carne estofada con arroz', 'Principal'], $P],
            'Cena' => [$S, ['Soufflé de calabaza', 'Entrada'], ['Costeleta de cerdo con papas fritas', 'Principal'], $P],
        ],
    ];
}

/**
 * Menús 1, 2 y 3 — contingente de 3 días (los platos de las fichas de cocina).
 */
function menu_3_dias(): array
{
    return [
        1 => [
            'Almuerzo' => [['Canastita de jamón y queso', 'Entrada'], ['Costeleta de vaca con papas fritas', 'Principal'], ['Helado almendrado', 'Postre']],
            'Cena' => [['Pizza muzzarella', 'Entrada'], ['Pata muslo con arroz amarillo', 'Principal'], ['Flan de dulce de leche', 'Postre']],
        ],
        2 => [
            'Almuerzo' => [['Empanada árabe', 'Entrada'], ['Tallarines con carne estofada', 'Principal'], ['Flan de vainilla', 'Postre']],
            'Cena' => [['Soufflé de zapallitos', 'Entrada'], ['Milanesa con puré mixto', 'Principal'], ['Helado (chocolate y chantilly)', 'Postre']],
        ],
        3 => [
            'Almuerzo' => [['Tarta de jamón y queso', 'Entrada'], ['Paleta al horno con ensalada de lechuga y tomate', 'Principal'], ['Ensalada de frutas', 'Postre']],
            'Cena' => [['Canastita caprese', 'Entrada'], ['Suprema con papas fritas', 'Principal'], ['Helado suizo', 'Postre']],
        ],
    ];
}

/** Carga el Menú 2025/2026 de 10 días */
function cargar_menu_inicial(PDO $pdo): int
{
    return cargar_menu($pdo, menu_inicial());
}

/**
 * Carga un menú en el ciclo. Reutiliza platos que ya existan con el mismo nombre
 * (y los vuelve a activar). Devuelve la cantidad de platos nuevos creados.
 */
function cargar_menu(PDO $pdo, array $menu): int
{
    // nombre nuevo de la costeleta (así figura en la carta)
    $pdo->exec("UPDATE platos SET nombre = 'Costeleta de vaca con papas fritas' WHERE nombre = 'Costeleta con papas fritas'");
    $activar = $pdo->prepare('UPDATE platos SET activo = 1, categoria = ? WHERE id = ?');
    $buscar = $pdo->prepare('SELECT id FROM platos WHERE LOWER(nombre) = LOWER(?) LIMIT 1');
    $crear = $pdo->prepare('INSERT INTO platos (nombre, categoria) VALUES (?, ?)');
    $asignar = $pdo->prepare('INSERT IGNORE INTO menu_ciclo (dia, servicio, plato_id, orden) VALUES (?,?,?,?)');
    $nuevos = 0;
    foreach ($menu as $dia => $servicios) {
        foreach ($servicios as $servicio => $platos) {
            foreach ($platos as $orden => [$nombre, $cat]) {
                $buscar->execute([$nombre]);
                $id = $buscar->fetchColumn();
                if (!$id) {
                    $crear->execute([$nombre, $cat]);
                    $id = $pdo->lastInsertId();
                    $nuevos++;
                } else {
                    $activar->execute([$cat, $id]);
                }
                $asignar->execute([$dia, $servicio, (int)$id, $orden + 1]);
            }
        }
    }
    return $nuevos;
}
