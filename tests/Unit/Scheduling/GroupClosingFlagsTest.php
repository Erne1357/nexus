<?php

use App\Modules\Core\Models\ModalityProfile;
use App\Modules\Scheduling\Queries\ClosingBlockers;
use App\Modules\Scheduling\Queries\GroupClosingFlags;

/*
| HC-15 por grupo (GroupClosingFlags): la única regla de lo que le falta a un grupo para cerrar, según el perfil de su
| modalidad (M1), que leen el veto, «Cierre de la carga» y «Avance del cierre». ClosingBlockers::tally() la cuenta por grupo.
*/

function closingFlags(array $overrides = []): GroupClosingFlags
{
    return new GroupClosingFlags(...[
        'id' => (string) fake()->uuid(),
        'campusId' => 'c2',
        'modalityId' => 'pres',
        'departmentId' => 'sc',
        'teacherId' => 't1',
        'hasSessions' => true,
        'roomlessSessions' => 0,
        'inactiveTeacher' => false,
        'justified' => false,
        'storedHardConflict' => false,
        ...$overrides,
    ]);
}

function closingProfile(bool $rooms = true, bool $blocks = true): ModalityProfile
{
    return ModalityProfile::fromArray(['uses_rooms' => $rooms, 'uses_time_blocks' => $blocks]);
}

it('HC-15: un grupo completo no bloquea ni se justifica', function () {
    $group = closingFlags();

    expect($group->blocks(closingProfile(), conflicted: false))->toBeFalse()
        ->and($group->needsJustification(closingProfile()))->toBeFalse()
        ->and($group->blocks(closingProfile(), conflicted: true))->toBeTrue();
});

it('HC-15: sin horario y sin salón solo cuentan si el perfil de la modalidad usa bloques de tiempo y salones', function () {
    $unscheduled = closingFlags(['hasSessions' => false]);
    $roomless = closingFlags(['roomlessSessions' => 2]);

    expect($unscheduled->unscheduled(closingProfile()))->toBeTrue()
        ->and($unscheduled->blocks(closingProfile(blocks: false), conflicted: false))->toBeFalse()
        ->and($roomless->withoutRoom(closingProfile()))->toBeTrue()
        ->and($roomless->needsJustification(closingProfile()))->toBeTrue()
        ->and($roomless->blocks(closingProfile(rooms: false), conflicted: false))->toBeFalse()
        ->and($roomless->needsJustification(closingProfile(rooms: false)))->toBeFalse();
});

it('HC-15: la justificación deja pasar al grupo sin docente o sin salón, pero no al que tiene docente dado de baja (P10)', function () {
    $justified = closingFlags(['teacherId' => null, 'roomlessSessions' => 1, 'justified' => true]);
    $inactive = closingFlags(['inactiveTeacher' => true, 'justified' => true]);

    expect($justified->withoutTeacher())->toBeFalse()
        ->and($justified->withoutRoom(closingProfile()))->toBeFalse()
        ->and($justified->blocks(closingProfile(), conflicted: false))->toBeFalse()
        ->and($justified->passesJustified(closingProfile()))->toBeTrue()
        ->and($inactive->blocks(closingProfile(), conflicted: false))->toBeTrue()
        ->and($inactive->passesJustified(closingProfile()))->toBeFalse();
});

it('HC-15: ClosingBlockers::tally cuenta cada cifra por grupo y los grupos distintos una vez', function () {
    $groups = [
        closingFlags(),
        closingFlags(['teacherId' => null, 'roomlessSessions' => 1]),
        closingFlags(['teacherId' => null, 'justified' => true]),
        closingFlags(['hasSessions' => false, 'storedHardConflict' => true]),
        closingFlags(['inactiveTeacher' => true]),
    ];

    $counts = ['conflicted' => 1, 'unscheduled' => 1, 'withoutTeacher' => 1, 'withoutRoom' => 1, 'inactiveTeacher' => 1, 'justified' => 1, 'groups' => 3];

    expect(ClosingBlockers::tally($groups, closingProfile()))->toEqual(new ClosingBlockers(...$counts, byCampus: ['c2' => $counts]))
        ->and(ClosingBlockers::tally($groups, closingProfile(), conflicted: [$groups[0]->id])->conflicted)->toBe(1)
        ->and(ClosingBlockers::tally($groups, closingProfile(), conflicted: [$groups[0]->id])->groups)->toBe(4);
});

it('HC-15, U2: ClosingBlockers reparte cada cifra por el campus de cada grupo; un campus sin grupos es cero', function () {
    $groups = [
        closingFlags(['campusId' => 'c1', 'teacherId' => null]),
        closingFlags(['campusId' => 'c2', 'teacherId' => null]),
        closingFlags(['campusId' => 'c2', 'teacherId' => null, 'storedHardConflict' => true]),
    ];
    $blockers = ClosingBlockers::tally($groups, closingProfile());

    expect($blockers->withoutTeacher)->toBe(3)
        ->and(array_keys($blockers->byCampus))->toBe(['c1', 'c2'])
        ->and($blockers->forCampus('c1'))->withoutTeacher->toBe(1)->conflicted->toBe(0)->groups->toBe(1)
        ->and($blockers->forCampus('c2'))->withoutTeacher->toBe(2)->conflicted->toBe(1)->groups->toBe(2)
        ->and($blockers->forCampus('c9'))->toEqual(new ClosingBlockers)
        ->and(new ClosingBlockers(...get_object_vars($blockers)))->toEqual($blockers);
});
