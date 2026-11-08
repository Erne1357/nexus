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

it('pinta dónde cabe el grupo elegido: cabe, choca (se guarda marcada), no se puede (HC-01, HC-02) o ya es suya', function () {
    $grid = weekGrid(['days' => [1], 'day_start' => '07:00', 'day_end' => '11:00']);
    $cells = $grid->cells([
        '1|07:00' => ['fits' => true, 'rules' => [], 'messages' => []],
        '1|08:00' => ['fits' => false, 'rules' => ['HC-03'], 'messages' => ['Se empalma con B.']],
        '1|09:00' => ['fits' => false, 'rules' => ['HC-03', 'HC-01'], 'messages' => ['Ana ya da Química.']],
    ], ['HC-01', 'HC-02']);

    expect($grid->cells(null, ['HC-01']))->toBeNull()
        ->and(array_column($cells[1], 'state'))->toBe(['fits', 'clash', 'blocked', 'own'])
        ->and($cells[1][1]['why'])->toBe('HC-03 · Se empalma con B.')
        ->and($cells[1][0]['key'])->toBe('1|07:00');
});

it('la lista de un día salta las horas que cubre una sesión, salvo las que el grupo elegido puede tomar', function () {
    $grid = weekGrid(['days' => [1], 'day_start' => '07:00', 'day_end' => '10:00']);
    $laid = $grid->lay([weekItem('otra', 1, '07:00', '09:00')])[1];
    $cells = $grid->cells(['1|07:00' => ['fits' => false, 'rules' => ['HC-03'], 'messages' => []], '1|08:00' => ['fits' => false, 'rules' => ['HC-03'], 'messages' => []], '1|09:00' => ['fits' => true, 'rules' => [], 'messages' => []]], ['HC-01'])[1];

    $plain = $grid->dayRows($laid, [], null);
    $picking = $grid->dayRows($laid, [], $cells);

    expect(array_map(fn (array $row): string => $row['block']['label'], $plain))->toBe(['7:00', '9:00'])
        ->and(array_map(fn (array $row): string => $row['block']['label'].' '.($row['cell']['state'] ?? '-'), $picking))->toBe(['7:00 clash', '8:00 clash', '9:00 fits'])
        ->and(array_column($picking[0]['items'], 'id'))->toBe(['otra']);
});

it('la parada de tabulación es la primera celda que coloca o, sin ella, la primera sesión que se abre', function () {
    $grid = weekGrid(['days' => [1, 2], 'day_start' => '07:00', 'day_end' => '09:00']);
    $laid = $grid->lay([[...weekItem('s', 2, '07:00', '08:00'), 'open' => true]]);
    $cells = $grid->cells(['1|07:00' => ['fits' => false, 'rules' => ['HC-01'], 'messages' => []], '1|08:00' => ['fits' => true, 'rules' => [], 'messages' => []]], ['HC-01']);

    expect($grid->tabStop($laid, $cells, true))->toBe('cell:1|08:00')
        ->and($grid->tabStop($laid, $cells, false))->toBe('session:s')
        ->and($grid->tabStop($laid, null, false))->toBe('session:s');
});

it('extiende la ventana a las sesiones que caen fuera del horario o de los días del perfil, alineada a sus bloques', function () {
    $grid = weekGrid(['days' => [1, 2, 3, 4, 5], 'day_start' => '07:00', 'day_end' => '21:00', 'block_minutes' => 60])
        ->covering([weekItem('sabado', 6, '06:30', '08:00'), weekItem('noche', 2, '20:00', '22:00')]);

    expect(array_column($grid->days, 'day'))->toBe([1, 2, 3, 4, 5, 6])
        ->and($grid->blocks[0]['starts_at'])->toBe('06:00')
        ->and(array_last($grid->blocks)['ends_at'])->toBe('22:00')
        ->and($grid->position('06:30', '08:00'))->toBe(['top' => 0.5, 'span' => 1.5])
        ->and(weekGrid()->covering([])->blocks)->toBe(weekGrid()->blocks);
});
