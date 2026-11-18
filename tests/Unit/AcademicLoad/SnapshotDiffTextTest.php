<?php

use App\Modules\AcademicLoad\Rules\SnapshotDiffText;

/*
| Cómo se lee en español un cambio del diff de una versión publicada (tarea 8.4): una línea por grupo, que es lo que dicen
| los avisos de la republicación a cada docente, coordinador o jefe (RA-05, RA-06).
*/

/**
 * Un grupo del diff de Tópicos Avanzados de Programación en C2.
 *
 * @param  list<array<string, mixed>>  $sessions
 * @return array<string, mixed>
 */
function diffTextGroup(array $sessions = [], ?string $campus = 'C2'): array
{
    return [
        'id' => 'g1',
        'code' => 'A',
        'subject' => ['id' => 'sub-1', 'code' => 'SCD-1027', 'name' => 'Tópicos Avanzados de Programación'],
        'campus' => $campus,
        'teacher' => ['id' => 'tea-1', 'name' => 'Ana Ruiz'],
        'careers' => [['id' => 'car-ISC', 'code' => 'ISC', 'department_id' => 'dep-sis']],
        'sessions' => $sessions,
        'other_departments' => [],
    ];
}

it('nombra el grupo con su materia, su clave y su campus', function () {
    expect(SnapshotDiffText::group(diffTextGroup()))->toBe('SCD-1027 Tópicos Avanzados de Programación, grupo A (C2)')
        ->and(SnapshotDiffText::group(diffTextGroup(campus: null)))->toBe('SCD-1027 Tópicos Avanzados de Programación, grupo A');
});

it('dice cada clase de cambio de un grupo en una línea', function (array $entry, string $line) {
    expect(SnapshotDiffText::entry($entry))->toBe($line);
})->with([
    'nuevo, con su horario' => [
        ['kind' => 'added', 'group' => diffTextGroup([['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00', 'room' => 'A-101'], ['day' => 3, 'starts_at' => '07:00', 'ends_at' => '10:00', 'room' => null]])],
        'Grupo nuevo: SCD-1027 Tópicos Avanzados de Programación, grupo A (C2), lunes 7:00 a 9:00 en A-101 y miércoles 7:00 a 10:00 sin salón.',
    ],
    'nuevo, sin sesiones' => [
        ['kind' => 'added', 'group' => diffTextGroup()],
        'Grupo nuevo: SCD-1027 Tópicos Avanzados de Programación, grupo A (C2).',
    ],
    'cancelado' => [
        ['kind' => 'cancelled', 'group' => diffTextGroup()],
        'Se canceló SCD-1027 Tópicos Avanzados de Programación, grupo A (C2).',
    ],
    'asignado' => [
        ['kind' => 'assigned', 'group' => diffTextGroup([['day' => 2, 'starts_at' => '09:00', 'ends_at' => '11:00', 'room' => 'A-204']])],
        'Ahora impartes SCD-1027 Tópicos Avanzados de Programación, grupo A (C2), martes 9:00 a 11:00 en A-204.',
    ],
    'ya no asignado' => [
        ['kind' => 'unassigned', 'group' => diffTextGroup()],
        'Ya no impartes SCD-1027 Tópicos Avanzados de Programación, grupo A (C2).',
    ],
    'horario' => [
        ['kind' => 'changed', 'group' => diffTextGroup(), 'changes' => ['schedule' => [
            'from' => [['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00']],
            'to' => [['day' => 2, 'starts_at' => '09:00', 'ends_at' => '11:00']],
        ]]],
        'SCD-1027 Tópicos Avanzados de Programación, grupo A (C2): horario de lunes 7:00 a 9:00 a martes 9:00 a 11:00.',
    ],
    'salón' => [
        ['kind' => 'changed', 'group' => diffTextGroup(), 'changes' => ['room' => [
            'from' => [['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00', 'room' => 'A-101'], ['day' => 3, 'starts_at' => '07:00', 'ends_at' => '09:00', 'room' => 'A-102']],
            'to' => [['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00', 'room' => 'A-101'], ['day' => 3, 'starts_at' => '07:00', 'ends_at' => '09:00', 'room' => null]],
        ]]],
        'SCD-1027 Tópicos Avanzados de Programación, grupo A (C2): salón del miércoles 7:00 a 9:00 de A-102 a ninguno.',
    ],
    'horario y salón' => [
        ['kind' => 'changed', 'group' => diffTextGroup(), 'changes' => [
            'schedule' => ['from' => [['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00']], 'to' => [['day' => 3, 'starts_at' => '07:00', 'ends_at' => '09:00']]],
            'room' => ['from' => [['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00', 'room' => 'A-101']], 'to' => [['day' => 3, 'starts_at' => '07:00', 'ends_at' => '09:00', 'room' => 'A-204']]],
        ]],
        'SCD-1027 Tópicos Avanzados de Programación, grupo A (C2): horario y salón de lunes 7:00 a 9:00 en A-101 a miércoles 7:00 a 9:00 en A-204.',
    ],
    'docente, cupo y lugares por carrera' => [
        ['kind' => 'changed', 'group' => diffTextGroup(), 'changes' => [
            'teacher' => ['from' => ['id' => 'tea-1', 'name' => 'Ana Ruiz'], 'to' => null],
            'quota' => ['from' => 30, 'to' => 35],
            'career_quotas' => ['from' => [['id' => 'car-ISC', 'code' => 'ISC', 'seats' => 30]], 'to' => [['id' => 'car-ISC', 'code' => 'ISC', 'seats' => 25], ['id' => 'car-IND', 'code' => 'IND', 'seats' => 10]]],
        ]],
        'SCD-1027 Tópicos Avanzados de Programación, grupo A (C2): docente de Ana Ruiz a Por asignar; cupo de 30 a 35; lugares por carrera de ISC 30 a ISC 25, IND 10.',
    ],
    'horario que se queda sin sesiones' => [
        ['kind' => 'changed', 'group' => diffTextGroup(), 'changes' => ['schedule' => ['from' => [['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00']], 'to' => []]]],
        'SCD-1027 Tópicos Avanzados de Programación, grupo A (C2): horario de lunes 7:00 a 9:00 a ninguno.',
    ],
]);
