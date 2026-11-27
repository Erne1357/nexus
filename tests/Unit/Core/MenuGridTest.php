<?php

use App\Modules\Core\Navigation\MenuGrid;

/*
| La retícula del menú abierto (DESIGN.md, "El menú que se transforma"): a cada icono, nombre de área,
| encabezado de grupo y entrada le da su columna, su renglón y cuántas columnas abarca, para cinco
| columnas (escritorio) y para tres (tableta). Los renglones 1 y 2 son del buscador; las áreas empiezan
| en el 3. Una columna lleva a lo más doce renglones antes de abrir otra.
*/

/**
 * @return array{column: int, row: int, span: int}
 */
function menuCell(int $column, int $row, int $span = 1): array
{
    return ['column' => $column, 'row' => $row, 'span' => $span];
}

/**
 * Un área con sus grupos: cada grupo es [lleva encabezado, cuántas entradas].
 *
 * @param  list<array{0: bool, 1: int}>  $groups
 * @return array{headings: bool, groups: list<array{heading: bool, entries: int}>}
 */
function menuShape(bool $headings, array $groups): array
{
    return [
        'headings' => $headings,
        'groups' => array_map(fn (array $group): array => ['heading' => $group[0], 'entries' => $group[1]], $groups),
    ];
}

/**
 * Las celdas de las entradas de una columna, de un renglón en adelante.
 *
 * @return list<array{column: int, row: int, span: int}>
 */
function menuCells(int $column, int $fromRow, int $count): array
{
    return array_map(fn (int $index): array => menuCell($column, $fromRow + $index), range(0, $count - 1));
}

it('un área sin encabezados es una columna: el icono y el nombre en el renglón 3 y las entradas debajo', function () {
    expect(MenuGrid::place([menuShape(false, [[false, 2]])], 5))->toBe([
        [
            'icon' => menuCell(1, 3),
            'name' => menuCell(1, 3),
            'groups' => [['heading' => null, 'entries' => [menuCell(1, 4), menuCell(1, 5)]]],
        ],
    ]);
});

it('apila las áreas de una columna mientras quepan en doce renglones; la que no cabe va a la columna de al lado', function () {
    $grid = MenuGrid::place([
        menuShape(false, [[false, 5]]),
        menuShape(false, [[false, 2]]),
        menuShape(false, [[false, 3]]),
    ], 5);

    // La primera ocupa los renglones 3 a 8; la segunda deja uno libre y va del 10 al 12; la tercera (4
    // renglones) ya no cabe debajo y abre la columna 2.
    expect($grid[1]['name'])->toBe(menuCell(1, 10))
        ->and($grid[1]['groups'][0]['entries'])->toBe(menuCells(1, 11, 2))
        ->and($grid[2]['icon'])->toBe(menuCell(2, 3))
        ->and($grid[2]['groups'][0]['entries'])->toBe(menuCells(2, 4, 3));
});

it('un área con encabezados ocupa las columnas que necesita, sin partir un grupo, y su nombre las abarca', function () {
    // Bloques de 3, 3, 4, 4 y 4 renglones con su encabezado: caben 11 por columna (12 con el nombre).
    [$area] = MenuGrid::place([menuShape(true, [[true, 2], [true, 2], [true, 3], [true, 3], [true, 3]])], 5);

    expect($area['icon'])->toBe(menuCell(1, 3))
        ->and($area['name'])->toBe(menuCell(1, 3, 2))
        ->and(array_column($area['groups'], 'heading'))->toBe([menuCell(1, 4), menuCell(1, 7), menuCell(1, 10), menuCell(2, 4), menuCell(2, 8)])
        ->and($area['groups'][2]['entries'])->toBe(menuCells(1, 11, 3))
        ->and($area['groups'][3]['entries'])->toBe(menuCells(2, 5, 3))
        ->and($area['groups'][4]['entries'])->toBe(menuCells(2, 9, 3));
});

it('las entradas sin grupo de un área con encabezados van primero y sin encabezado', function () {
    [$area] = MenuGrid::place([menuShape(true, [[false, 2], [true, 3], [true, 3]])], 5);

    expect($area['groups'][0])->toBe(['heading' => null, 'entries' => menuCells(1, 4, 2)])
        ->and($area['groups'][1]['heading'])->toBe(menuCell(1, 6))
        ->and($area['groups'][2]['heading'])->toBe(menuCell(1, 10));
});

it('si un área necesita más columnas de las que hay, lo que sobra se junta en la última', function () {
    [$area] = MenuGrid::place([menuShape(true, [[true, 10], [true, 10], [true, 10], [true, 10]])], 3);

    expect($area['name'])->toBe(menuCell(1, 3, 3))
        ->and($area['groups'][2]['heading'])->toBe(menuCell(3, 4))
        ->and($area['groups'][3]['heading'])->toBe(menuCell(3, 15))
        ->and($area['groups'][3]['entries'][9])->toBe(menuCell(3, 25));
});

it('un área con encabezados no se apila, aunque quepa en una columna, ni deja que otra se le apile', function () {
    $grid = MenuGrid::place([
        menuShape(false, [[false, 2]]),
        menuShape(true, [[true, 2], [true, 2]]),
        menuShape(false, [[false, 1]]),
    ], 5);

    expect($grid[1]['name'])->toBe(menuCell(2, 3))
        ->and($grid[2]['name'])->toBe(menuCell(3, 3));
});

/*
| El menú del admin de la maqueta B: Académico por etapas, Servicios escolares, Recursos humanos y
| Administración por propósito.
*/
dataset('menú del admin', [[[
    menuShape(true, [[true, 2], [true, 2], [true, 3], [true, 3], [true, 3]]),
    menuShape(false, [[false, 1]]),
    menuShape(false, [[false, 1]]),
    menuShape(true, [[true, 4], [true, 6], [true, 1]]),
]]]);

it('en cinco columnas, el admin ve Académico en dos, Servicios escolares y Recursos humanos apiladas y Administración en dos', function (array $shape) {
    [$academico, $escolares, $humanos, $administracion] = MenuGrid::place($shape, 5);

    expect($academico['name'])->toBe(menuCell(1, 3, 2))
        ->and($academico['groups'][2]['entries'][2])->toBe(menuCell(1, 13))
        ->and($escolares['name'])->toBe(menuCell(3, 3))
        ->and($humanos['icon'])->toBe(menuCell(3, 6))
        ->and($humanos['groups'][0]['entries'])->toBe([menuCell(3, 7)])
        ->and($administracion['icon'])->toBe(menuCell(4, 3))
        ->and($administracion['name'])->toBe(menuCell(4, 3, 2))
        ->and(array_column($administracion['groups'], 'heading'))->toBe([menuCell(4, 4), menuCell(5, 4), menuCell(5, 11)])
        ->and($administracion['groups'][2]['entries'])->toBe([menuCell(5, 12)]);
})->with('menú del admin');

it('en tres columnas, el área que ya no cabe baja debajo de la más larga de arriba', function (array $shape) {
    [$academico, $escolares, $humanos, $administracion] = MenuGrid::place($shape, 3);

    // Académico llega al renglón 13; Administración empieza en el 15, con un renglón libre de por medio.
    expect($academico['name'])->toBe(menuCell(1, 3, 2))
        ->and($escolares['name'])->toBe(menuCell(3, 3))
        ->and($humanos['name'])->toBe(menuCell(3, 6))
        ->and($administracion['icon'])->toBe(menuCell(1, 15))
        ->and($administracion['name'])->toBe(menuCell(1, 15, 2))
        ->and(array_column($administracion['groups'], 'heading'))->toBe([menuCell(1, 16), menuCell(2, 16), menuCell(2, 23)])
        ->and($administracion['groups'][2]['entries'])->toBe([menuCell(2, 24)]);
})->with('menú del admin');

it('sin áreas no da nada', function () {
    expect(MenuGrid::place([], 5))->toBe([]);
});
