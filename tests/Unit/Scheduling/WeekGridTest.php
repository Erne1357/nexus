<?php

use App\Modules\Core\Models\CampusProfile;
use App\Modules\Scheduling\Support\WeekGrid;

/*
| WeekGrid (tarea 3.5): la semana del editor de horarios con el perfil del campus. Da los días y los bloques de la
| cuadrícula y acomoda cada sesión en su día: desde qué renglón y cuántos abarca, y en qué carril va cuando otras del
| mismo día coinciden con ella.
*/

function weekGrid(array $settings = []): WeekGrid
{
    return WeekGrid::of(CampusProfile::fromArray($settings));
}

/**
 * @return array{day: int, starts_at: string, ends_at: string, id: string}
 */
function weekItem(string $id, int $day, string $startsAt, string $endsAt): array
{
    return ['id' => $id, 'day' => $day, 'starts_at' => $startsAt, 'ends_at' => $endsAt];
}

it('da los días y los bloques del perfil del campus, con su hora sin cero a la izquierda', function () {
    $grid = weekGrid(['days' => [1, 2, 3, 4, 5, 6], 'day_start' => '07:00', 'day_end' => '10:00', 'block_minutes' => 60]);

    expect($grid->days)->toBe([
        ['day' => 1, 'name' => 'Lunes', 'short' => 'L'],
        ['day' => 2, 'name' => 'Martes', 'short' => 'M'],
        ['day' => 3, 'name' => 'Miércoles', 'short' => 'Mi'],
        ['day' => 4, 'name' => 'Jueves', 'short' => 'J'],
        ['day' => 5, 'name' => 'Viernes', 'short' => 'V'],
        ['day' => 6, 'name' => 'Sábado', 'short' => 'S'],
    ])->and($grid->blocks)->toBe([
        ['starts_at' => '07:00', 'ends_at' => '08:00', 'label' => '7:00'],
        ['starts_at' => '08:00', 'ends_at' => '09:00', 'label' => '8:00'],
        ['starts_at' => '09:00', 'ends_at' => '10:00', 'label' => '9:00'],
    ])->and(WeekGrid::range('07:00', '09:30'))->toBe('7:00–9:30');
});

it('acomoda cada sesión en su día con el renglón donde empieza y los que abarca, en bloques del perfil', function () {
    $laid = weekGrid(['block_minutes' => 30, 'day_start' => '07:00'])->lay([
        weekItem('a', 2, '08:00', '09:30'),
        weekItem('b', 1, '07:00', '08:00'),
        weekItem('fuera', 7, '07:00', '08:00'),
    ]);

    expect(array_keys($laid))->toBe([1, 2, 3, 4, 5])
        ->and($laid[1])->toBe([[...weekItem('b', 1, '07:00', '08:00'), 'top' => 0.0, 'span' => 2.0, 'lane' => 0, 'lanes' => 1]])
        ->and($laid[2][0])->toMatchArray(['id' => 'a', 'top' => 2.0, 'span' => 3.0])
        ->and($laid[3])->toBe([]);
});

it('pone en carriles lado a lado las sesiones de un día que coinciden, y en uno solo las que no', function () {
    $grid = weekGrid();
    $laid = $grid->lay([
        weekItem('larga', 1, '07:00', '10:00'),
        weekItem('choca', 1, '08:00', '09:00'),
        weekItem('despues', 1, '09:00', '11:00'),
        weekItem('sola', 1, '12:00', '13:00'),
    ]);

    expect(array_map(fn (array $item): array => [$item['id'], $item['lane'], $item['lanes']], $laid[1]))->toBe([
        ['larga', 0, 2],
        ['choca', 1, 2],
        ['despues', 1, 2],
        ['sola', 0, 1],
    ])->and($grid->lanes($laid))->toBe([1 => 2, 2 => 1, 3 => 1, 4 => 1, 5 => 1]);
});

it('cuenta las horas ocupadas de un día sin contar dos veces las que coinciden', function () {
    $grid = weekGrid();

    expect($grid->busyHours([weekItem('a', 1, '07:00', '09:00'), weekItem('b', 1, '08:00', '10:30'), weekItem('c', 1, '12:00', '13:00'), weekItem('d', 2, '07:00', '09:00')], 1))->toBe(4.5)
        ->and($grid->busyHours([], 1))->toBe(0);
});
