<?php

use App\Modules\AcademicLoad\Queries\PublishedChange;
use App\Modules\Core\Enums\StatusTone;

/*
| La marca «Cambió» de la consulta pública (tarea 8.5; decisión del usuario del 17 de noviembre de 2026): de lo que cambió
| en la última publicación de una carga (el diff guardado, SnapshotDiff), qué ve el público de cada grupo. Dice qué cambió
| (horario, salón, docente) o si el grupo es nuevo o se canceló, con la línea de SnapshotDiffText::publicEntries(), y nunca
| cupos ni lugares por carrera.
*/

/**
 * Un grupo del diff de Cálculo Vectorial en C2, lunes de 7:00 a 9:00 en B-12.
 *
 * @return array<string, mixed>
 */
function publishedChangeGroup(string $id, string $code = 'B'): array
{
    return [
        'id' => $id,
        'code' => $code,
        'subject' => ['id' => 'sub-1', 'code' => 'ACF-0904', 'name' => 'Cálculo Vectorial'],
        'campus' => 'C2',
        'teacher' => ['id' => 'tea-1', 'name' => 'Laura Ríos Medina'],
        'careers' => [['id' => 'car-ISC', 'code' => 'ISC', 'department_id' => 'dep-cb', 'is_owner' => true]],
        'sessions' => [['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00', 'room' => 'B-12']],
        'other_departments' => ['dep-otro'],
    ];
}

/**
 * @param  list<array<string, mixed>>  $added
 * @param  list<array<string, mixed>>  $cancelled
 * @param  list<array<string, mixed>>  $changed
 * @return array<string, mixed>
 */
function publishedChangeDiff(array $added = [], array $cancelled = [], array $changed = []): array
{
    return ['version' => 1, 'groups' => ['added' => $added, 'cancelled' => $cancelled, 'changed' => $changed], 'summary' => []];
}

it('marca el grupo nuevo y el cancelado, con la línea que dice el aviso', function () {
    $changes = PublishedChange::ofDiff(publishedChangeDiff(added: [publishedChangeGroup('g-new', 'E')], cancelled: [publishedChangeGroup('g-gone', 'C')]));

    expect($changes)->toHaveKeys(['g-new', 'g-gone'])
        ->and($changes['g-new']->label())->toBe('Grupo nuevo')
        ->and($changes['g-new']->isAdded())->toBeTrue()
        ->and($changes['g-new']->tone())->toBe(StatusTone::Success)
        ->and($changes['g-new']->line)->toBe('Grupo nuevo: ACF-0904 Cálculo Vectorial, grupo E (C2), lunes 7:00 a 9:00 en B-12.')
        ->and($changes['g-new']->detail)->toBeNull()
        ->and($changes['g-gone']->label())->toBe('Cancelado')
        ->and($changes['g-gone']->isCancelled())->toBeTrue()
        ->and($changes['g-gone']->tone())->toBe(StatusTone::Error)
        ->and($changes['g-gone']->line)->toBe('Se canceló ACF-0904 Cálculo Vectorial, grupo C (C2).');
});

it('dice qué cambió de un grupo que sigue: horario, salón o docente, y el detalle sin repetir el grupo', function (array $changes, string $label, string $detail) {
    $change = PublishedChange::ofDiff(publishedChangeDiff(changed: [['group' => publishedChangeGroup('g-1'), 'changes' => $changes]]))['g-1'];

    expect($change->label())->toBe($label)
        ->and($change->tone())->toBe(StatusTone::Info)
        ->and($change->icon())->toBe('o-arrow-path')
        ->and($change->isAdded())->toBeFalse()
        ->and($change->isCancelled())->toBeFalse()
        ->and($change->detail)->toBe($detail)
        ->and($change->line)->toBe('ACF-0904 Cálculo Vectorial, grupo B (C2): '.lcfirst($detail));
})->with([
    'horario' => [
        ['schedule' => ['from' => [['day' => 2, 'starts_at' => '07:00', 'ends_at' => '09:00']], 'to' => [['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00']]]],
        'Cambió: horario',
        'Horario de martes 7:00 a 9:00 a lunes 7:00 a 9:00.',
    ],
    'salón' => [
        ['room' => ['from' => [['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00', 'room' => 'A-101']], 'to' => [['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00', 'room' => 'B-12']]]],
        'Cambió: salón',
        'Salón del lunes 7:00 a 9:00 de A-101 a B-12.',
    ],
    'docente' => [
        ['teacher' => ['from' => null, 'to' => ['id' => 'tea-1', 'name' => 'Laura Ríos Medina']]],
        'Cambió: docente',
        'Docente de Por asignar a Laura Ríos Medina.',
    ],
    'horario, salón y docente, y el cupo no se dice' => [
        [
            'schedule' => ['from' => [['day' => 2, 'starts_at' => '07:00', 'ends_at' => '09:00']], 'to' => [['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00']]],
            'room' => ['from' => [['day' => 2, 'starts_at' => '07:00', 'ends_at' => '09:00', 'room' => 'A-101']], 'to' => [['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00', 'room' => 'B-12']]],
            'teacher' => ['from' => ['id' => 'tea-2', 'name' => 'Ana Ruiz'], 'to' => ['id' => 'tea-1', 'name' => 'Laura Ríos Medina']],
            'quota' => ['from' => 30, 'to' => 35],
        ],
        'Cambió: horario, salón y docente',
        'Horario y salón de martes 7:00 a 9:00 en A-101 a lunes 7:00 a 9:00 en B-12; docente de Ana Ruiz a Laura Ríos Medina.',
    ],
]);

it('un grupo al que solo le cambió el cupo o los lugares por carrera no lleva marca', function () {
    $changes = PublishedChange::ofDiff(publishedChangeDiff(changed: [
        ['group' => publishedChangeGroup('g-quota'), 'changes' => ['quota' => ['from' => 30, 'to' => 35]]],
        ['group' => publishedChangeGroup('g-seats'), 'changes' => ['career_quotas' => ['from' => [['id' => 'car-ISC', 'code' => 'ISC', 'seats' => 30]], 'to' => [['id' => 'car-ISC', 'code' => 'ISC', 'seats' => 25]]]]],
    ]));

    expect($changes)->toBe([]);
});

it('ninguna marca dice cupos, lugares, departamentos, llaves ni versiones', function () {
    $changes = PublishedChange::ofDiff(publishedChangeDiff(
        added: [publishedChangeGroup('g-new', 'E')],
        cancelled: [publishedChangeGroup('g-gone', 'C')],
        changed: [['group' => publishedChangeGroup('g-1'), 'changes' => [
            'teacher' => ['from' => ['id' => 'tea-2', 'name' => 'Ana Ruiz'], 'to' => ['id' => 'tea-1', 'name' => 'Laura Ríos Medina']],
            'quota' => ['from' => 30, 'to' => 35],
            'career_quotas' => ['from' => [['id' => 'car-ISC', 'code' => 'ISC', 'seats' => 30]], 'to' => [['id' => 'car-ISC', 'code' => 'ISC', 'seats' => 35]]],
        ]]],
    ));

    $text = implode(' ', array_map(fn (PublishedChange $change): string => $change->label().' '.$change->line.' '.$change->detail, $changes));

    expect($text)->not->toContain('cupo')->not->toContain('lugares')->not->toContain('dep-')->not->toContain('tea-')->not->toContain('car-')->not->toContain('versión');
});

it('va y viene de la caché como arreglo, sin clases', function () {
    $change = PublishedChange::ofDiff(publishedChangeDiff(changed: [['group' => publishedChangeGroup('g-1'), 'changes' => [
        'teacher' => ['from' => ['id' => 'tea-2', 'name' => 'Ana Ruiz'], 'to' => ['id' => 'tea-1', 'name' => 'Laura Ríos Medina']],
    ]]]))['g-1'];

    expect(PublishedChange::fromArray($change->toArray()))->toEqual($change)
        ->and(json_encode($change->toArray()))->toBeString();
});
