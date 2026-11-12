<?php

namespace App\Modules\Core\Navigation;

/**
 * La retícula del menú abierto (DESIGN.md, "El menú que se transforma"): da a cada icono, nombre de área,
 * encabezado de grupo y entrada su columna, su renglón y cuántas columnas abarca. El menú la pide para
 * cinco columnas (escritorio) y para tres (tableta), y el buscador la vuelve a pedir en el navegador con
 * las entradas que quedan (`packMenu` de resources/js/app.js, que repite este algoritmo y debe dar lo
 * mismo).
 *
 * Los renglones 1 y 2 son del buscador; las áreas empiezan en el 3, con el icono y el nombre en su primer
 * renglón y lo demás debajo. Las áreas van de izquierda a derecha:
 * - una con encabezados de grupo (MN-01) ocupa las columnas que necesita: cada grupo, con su encabezado,
 *   va completo en una columna de hasta COLUMN_ROWS renglones (contando el del nombre), su nombre abarca
 *   todas sus columnas y, si necesita más de las que hay, lo que sobra se junta en la última;
 * - una sin encabezados es una sola columna y se apila debajo de la anterior, con un renglón libre de por
 *   medio, si la anterior también es así y las dos caben en la columna más alta;
 * - cuando las columnas se acaban, la siguiente baja a la izquierda, un renglón debajo de la más larga.
 */
final readonly class MenuGrid
{
    /**
     * Renglón del nombre de la primera área: los dos de arriba son del buscador.
     */
    public const int FIRST_ROW = 3;

    /**
     * Renglones de una columna, con el del nombre del área, antes de abrir otra.
     */
    public const int COLUMN_ROWS = 12;

    /**
     * @param  list<array{headings: bool, groups: list<array{heading: bool, entries: int}>}>  $areas  las áreas en el orden del menú: si llevan encabezados de grupo y, por grupo, si se ve su encabezado y cuántas entradas tiene
     * @param  int  $columns  columnas de la retícula
     * @return list<array{icon: array{column: int, row: int, span: int}, name: array{column: int, row: int, span: int}, groups: list<array{heading: ?array{column: int, row: int, span: int}, entries: list<array{column: int, row: int, span: int}>}>}>
     */
    public static function place(array $areas, int $columns): array
    {
        $units = array_map(fn (array $area): array => self::unitOf($area, $columns), $areas);
        $tallest = max([self::COLUMN_ROWS, ...array_map(
            fn (array $unit): int => count($unit['columns']) === 1 ? count($unit['columns'][0]) + 1 : 0,
            $units,
        )]);

        $placed = [];
        $top = self::FIRST_ROW;
        $column = 0;
        $bandBottom = self::FIRST_ROW;
        $last = null;

        foreach ($areas as $index => $area) {
            $unit = $units[$index];
            $width = count($unit['columns']);
            $height = 1 + max(array_map(count(...), $unit['columns']));

            if ($unit['stacks'] && $last !== null && $last['stacks'] && $last['width'] === 1 && $top + $tallest >= $last['bottom'] + 2 + $height) {
                [$firstColumn, $firstRow] = [$last['column'], $last['bottom'] + 2];
            } else {
                if ($column + $width > $columns) {
                    $top = $bandBottom + 1;
                    $column = 0;
                }

                [$firstColumn, $firstRow] = [$column + 1, $top];
                $column += $width;
            }

            $groups = array_map(fn (array $group): array => ['heading' => null, 'entries' => []], $area['groups']);

            foreach ($unit['columns'] as $offset => $rows) {
                foreach ($rows as $line => [$group, $isHeading]) {
                    $cell = self::cell($firstColumn + $offset, $firstRow + 1 + $line);

                    if ($isHeading) {
                        $groups[$group]['heading'] = $cell;
                    } else {
                        $groups[$group]['entries'][] = $cell;
                    }
                }
            }

            $placed[] = [
                'icon' => self::cell($firstColumn, $firstRow),
                'name' => self::cell($firstColumn, $firstRow, $width),
                'groups' => $groups,
            ];

            $bottom = $firstRow + $height - 1;
            $bandBottom = max($bandBottom, $bottom + 1);
            $last = ['column' => $firstColumn, 'bottom' => $bottom, 'width' => $width, 'stacks' => $unit['stacks']];
        }

        return $placed;
    }

    /**
     * Los renglones de un área, repartidos en columnas: cada renglón es [número de grupo, si es su
     * encabezado]. Un área con encabezados reparte sus grupos completos; una sin ellos es una columna.
     *
     * @param  array{headings: bool, groups: list<array{heading: bool, entries: int}>}  $area
     * @return array{columns: non-empty-list<list<array{0: int, 1: bool}>>, stacks: bool}
     */
    private static function unitOf(array $area, int $columns): array
    {
        $blocks = [];

        foreach ($area['groups'] as $group => ['heading' => $heading, 'entries' => $entries]) {
            $blocks[] = [
                ...($heading ? [[$group, true]] : []),
                ...array_fill(0, $entries, [$group, false]),
            ];
        }

        if (! $area['headings']) {
            return ['columns' => [array_merge(...$blocks)], 'stacks' => true];
        }

        $split = [[]];

        foreach ($blocks as $block) {
            $current = array_key_last($split);

            if ($split[$current] !== [] && count($split[$current]) + count($block) > self::COLUMN_ROWS - 1) {
                $split[] = $block;
            } else {
                array_push($split[$current], ...$block);
            }
        }

        while (count($split) > $columns) {
            $extra = array_pop($split);
            array_push($split[array_key_last($split)], ...$extra);
        }

        return ['columns' => $split, 'stacks' => false];
    }

    /**
     * @return array{column: int, row: int, span: int}
     */
    private static function cell(int $column, int $row, int $span = 1): array
    {
        return ['column' => $column, 'row' => $row, 'span' => $span];
    }
}
