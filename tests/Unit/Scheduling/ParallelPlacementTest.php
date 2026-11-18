<?php

use App\Modules\Scheduling\Rules\ParallelPlacement;

/*
| Armar paralelos (tarea 8.8): qué grupo suelto que ya existe se queda en cada grupo del arreglo. Se queda el de más avance
| (con docente o una solicitud de cambio abierta, que no se pueden cancelar; luego con más horario) y conserva su carrera
| dueña, así que solo entra en un grupo del arreglo que lleva lugares de su carrera; los que sobran se cancelan, y uno con
| docente nunca (confirmado el 17 de noviembre de 2026).
*/

/**
 * @return array{id: string, owner: string, locked: bool, minutes: int}
 */
function placementGroup(string $id, string $owner, bool $locked = false, int $minutes = 0): array
{
    return ['id' => $id, 'owner' => $owner, 'locked' => $locked, 'minutes' => $minutes];
}

it('cada completo toma un grupo de su carrera y el mixto el de más avance de las carreras que lleva', function () {
    $placement = ParallelPlacement::place(
        [['ISC' => 35], ['IIND' => 35], ['ISC' => 25, 'IIND' => 5]],
        [placementGroup('isc-1', 'ISC'), placementGroup('isc-2', 'ISC'), placementGroup('iind-1', 'IIND', minutes: 300), placementGroup('iind-2', 'IIND')],
    );

    expect($placement->kept)->toBe([0 => 'isc-1', 1 => 'iind-1', 2 => 'isc-2'])
        ->and($placement->cancelled)->toBe(['iind-2'])
        ->and($placement->blocked)->toBe([]);
});

it('el de docente gana al de horario y el de horario al que no tiene nada', function () {
    $placement = ParallelPlacement::place(
        [['ISC' => 20, 'IIND' => 15]],
        [placementGroup('isc-1', 'ISC', minutes: 300), placementGroup('iind-1', 'IIND', locked: true), placementGroup('isc-2', 'ISC')],
    );

    expect($placement->kept)->toBe([0 => 'iind-1'])
        ->and($placement->cancelled)->toBe(['isc-1', 'isc-2']);
});

it('reacomoda los que ya se quedaron para no cancelar uno con horario si cabe en otro grupo', function () {
    $placement = ParallelPlacement::place(
        [['ISC' => 10, 'IIND' => 10], ['ISC' => 10, 'ITIC' => 10]],
        [placementGroup('isc-1', 'ISC', locked: true), placementGroup('iind-1', 'IIND', minutes: 120)],
    );

    expect($placement->kept)->toBe([0 => 'iind-1', 1 => 'isc-1'])
        ->and($placement->cancelled)->toBe([]);
});

it('un grupo que no se puede cancelar y no cabe en el arreglo queda bloqueado', function () {
    $placement = ParallelPlacement::place(
        [['IIND' => 35]],
        [placementGroup('isc-1', 'ISC', locked: true), placementGroup('iind-1', 'IIND')],
    );

    expect($placement->kept)->toBe([0 => 'iind-1'])
        ->and($placement->cancelled)->toBe(['isc-1'])
        ->and($placement->blocked)->toBe(['isc-1']);
});

it('un grupo del arreglo sin grupo que lo tome es nuevo, y sin arreglo todo sobra', function () {
    expect(ParallelPlacement::place([['ISC' => 35], ['ISC' => 35]], [placementGroup('isc-1', 'ISC')])->kept)->toBe([0 => 'isc-1'])
        ->and(ParallelPlacement::place([], [placementGroup('isc-1', 'ISC')])->cancelled)->toBe(['isc-1']);
});

it('en un empate se queda el primero recibido, el de creación más antigua', function () {
    $placement = ParallelPlacement::place([['ISC' => 35]], [placementGroup('isc-1', 'ISC'), placementGroup('isc-2', 'ISC')]);

    expect($placement->kept)->toBe([0 => 'isc-1'])->and($placement->cancelled)->toBe(['isc-2']);
});
