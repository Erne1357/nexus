<?php

use App\Modules\Core\Enums\AssignmentStatus;
use App\Modules\Core\Enums\StatusTone;
use App\Modules\Core\Models\RoleAssignment;
use Carbon\CarbonImmutable;

/*
| El estado de un nombramiento según el día: vigente (ya empezó y no ha terminado), programado (empieza después) o
| terminado (su último día quedó atrás). Los dos extremos cuentan completos.
*/

function assignmentOn(?string $from, ?string $to): RoleAssignment
{
    return (new RoleAssignment)->setRawAttributes(['valid_from' => $from, 'valid_to' => $to]);
}

it('vigente, programado o terminado por día, con su tono', function () {
    $today = CarbonImmutable::parse('2026-11-03');

    expect(AssignmentStatus::of(assignmentOn('2026-11-03', null), $today))->toBe(AssignmentStatus::Current)
        ->and(AssignmentStatus::of(assignmentOn('2026-01-01', '2026-11-03'), $today))->toBe(AssignmentStatus::Current)
        ->and(AssignmentStatus::of(assignmentOn('2026-11-04', null), $today))->toBe(AssignmentStatus::Scheduled)
        ->and(AssignmentStatus::of(assignmentOn('2026-01-01', '2026-11-02'), $today))->toBe(AssignmentStatus::Ended)
        ->and(AssignmentStatus::Current->label())->toBe('Vigente')
        ->and(AssignmentStatus::Scheduled->label())->toBe('Programado')
        ->and(AssignmentStatus::Ended->label())->toBe('Terminado')
        ->and(AssignmentStatus::Current->tone())->toBe(StatusTone::Success)
        ->and(AssignmentStatus::Scheduled->tone())->toBe(StatusTone::Info)
        ->and(AssignmentStatus::Ended->tone())->toBe(StatusTone::Neutral);
});
