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
        'SCD-1027 Tópicos Avanzados de Programación, grupo A (C2): se quita la sesión del lunes 7:00 a 9:00.',
    ],
]);

/*
| RA-05: un cambio de horario dice solo las sesiones que cambiaron (las que se quitaron, las que se agregaron y las que se
| movieron), nunca todo el horario de antes y de después: en la campana, la línea se corta a 160 caracteres.
*/
it('RA-05: de un cambio de horario nombra solo las sesiones que cambiaron', function (array $changes, string $text) {
    expect(SnapshotDiffText::changes($changes))->toBe($text);
})->with([
    'una sesión movida entre tres, con otro salón' => [
        [
            'schedule' => [
                'from' => [['day' => 1, 'starts_at' => '09:00', 'ends_at' => '11:00'], ['day' => 2, 'starts_at' => '09:00', 'ends_at' => '11:00'], ['day' => 3, 'starts_at' => '09:00', 'ends_at' => '10:00']],
                'to' => [['day' => 1, 'starts_at' => '09:00', 'ends_at' => '11:00'], ['day' => 2, 'starts_at' => '09:00', 'ends_at' => '11:00'], ['day' => 4, 'starts_at' => '09:00', 'ends_at' => '10:00']],
            ],
            'room' => [
                'from' => [['day' => 1, 'starts_at' => '09:00', 'ends_at' => '11:00', 'room' => 'LC05'], ['day' => 2, 'starts_at' => '09:00', 'ends_at' => '11:00', 'room' => 'LC05'], ['day' => 3, 'starts_at' => '09:00', 'ends_at' => '10:00', 'room' => 'LC05']],
                'to' => [['day' => 1, 'starts_at' => '09:00', 'ends_at' => '11:00', 'room' => 'LC05'], ['day' => 2, 'starts_at' => '09:00', 'ends_at' => '11:00', 'room' => 'LC05'], ['day' => 4, 'starts_at' => '09:00', 'ends_at' => '10:00', 'room' => 'LN02']],
            ],
        ],
        'horario y salón de miércoles 9:00 a 10:00 en LC05 a jueves 9:00 a 10:00 en LN02',
    ],
    'una sesión movida entre tres, en el mismo salón' => [
        ['schedule' => [
            'from' => [['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00'], ['day' => 3, 'starts_at' => '07:00', 'ends_at' => '09:00'], ['day' => 5, 'starts_at' => '07:00', 'ends_at' => '08:00']],
            'to' => [['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00'], ['day' => 3, 'starts_at' => '07:00', 'ends_at' => '09:00'], ['day' => 5, 'starts_at' => '10:00', 'ends_at' => '11:00']],
        ]],
        'horario de viernes 7:00 a 8:00 a viernes 10:00 a 11:00',
    ],
    'una sesión agregada' => [
        ['schedule' => [
            'from' => [['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00']],
            'to' => [['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00'], ['day' => 3, 'starts_at' => '07:00', 'ends_at' => '09:00']],
        ]],
        'se agrega la sesión del miércoles 7:00 a 9:00',
    ],
    'dos sesiones quitadas, con su salón' => [
        [
            'schedule' => [
                'from' => [['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00'], ['day' => 2, 'starts_at' => '07:00', 'ends_at' => '09:00'], ['day' => 3, 'starts_at' => '07:00', 'ends_at' => '09:00']],
                'to' => [['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00']],
            ],
            'room' => [
                'from' => [['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00', 'room' => 'A-101'], ['day' => 2, 'starts_at' => '07:00', 'ends_at' => '09:00', 'room' => 'B-12'], ['day' => 3, 'starts_at' => '07:00', 'ends_at' => '09:00', 'room' => null]],
                'to' => [['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00', 'room' => 'A-101']],
            ],
        ],
        'se quitan las sesiones del martes 7:00 a 9:00 en B-12 y del miércoles 7:00 a 9:00 sin salón',
    ],
    'una movida y otra quitada: la movida es la de la misma duración' => [
        ['schedule' => [
            'from' => [['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00'], ['day' => 3, 'starts_at' => '07:00', 'ends_at' => '09:00'], ['day' => 5, 'starts_at' => '07:00', 'ends_at' => '08:00']],
            'to' => [['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00'], ['day' => 4, 'starts_at' => '07:00', 'ends_at' => '09:00']],
        ]],
        'horario de miércoles 7:00 a 9:00 a jueves 7:00 a 9:00, se quita la sesión del viernes 7:00 a 8:00',
    ],
    'una movida de día y otra que solo cambió de salón' => [
        [
            'schedule' => [
                'from' => [['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00'], ['day' => 3, 'starts_at' => '07:00', 'ends_at' => '09:00']],
                'to' => [['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00'], ['day' => 4, 'starts_at' => '07:00', 'ends_at' => '09:00']],
            ],
            'room' => [
                'from' => [['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00', 'room' => 'A-101'], ['day' => 3, 'starts_at' => '07:00', 'ends_at' => '09:00', 'room' => 'A-101']],
                'to' => [['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00', 'room' => 'A-204'], ['day' => 4, 'starts_at' => '07:00', 'ends_at' => '09:00', 'room' => 'A-101']],
            ],
        ],
        'horario de miércoles 7:00 a 9:00 en A-101 a jueves 7:00 a 9:00 en A-101, salón del lunes 7:00 a 9:00 de A-101 a A-204',
    ],
]);

it('RA-05: la línea de una sesión movida entre tres cabe en la campana y dice el cambio real', function () {
    $sessions = fn (int $day, string $room): array => [
        ['day' => 1, 'starts_at' => '09:00', 'ends_at' => '11:00', 'room' => 'LC05'],
        ['day' => 2, 'starts_at' => '09:00', 'ends_at' => '11:00', 'room' => 'LC05'],
        ['day' => $day, 'starts_at' => '09:00', 'ends_at' => '10:00', 'room' => $room],
    ];
    $times = fn (array $list): array => array_map(fn (array $session): array => array_diff_key($session, ['room' => true]), $list);

    $line = SnapshotDiffText::entry(['kind' => 'changed', 'group' => diffTextGroup($sessions(4, 'LN02')), 'changes' => [
        'schedule' => ['from' => $times($sessions(3, 'LC05')), 'to' => $times($sessions(4, 'LN02'))],
        'room' => ['from' => $sessions(3, 'LC05'), 'to' => $sessions(4, 'LN02')],
    ]]);

    expect($line)->toBe('SCD-1027 Tópicos Avanzados de Programación, grupo A (C2): horario y salón de miércoles 9:00 a 10:00 en LC05 a jueves 9:00 a 10:00 en LN02.')
        ->and(mb_strlen($line))->toBeLessThanOrEqual(160)
        ->and($line)->not->toContain('lunes')->not->toContain('martes');
});

it('publicEntries da las líneas del diff sin lo interno: sin cupos, lugares por carrera, departamentos ni llaves', function () {
    $group = diffTextGroup([['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00', 'room' => 'A-101']]);
    $group['other_departments'] = ['dep-secreto'];
    $diff = ['version' => 1, 'groups' => [
        'added' => [$group],
        'cancelled' => [array_replace($group, ['code' => 'B'])],
        'changed' => [
            ['group' => array_replace($group, ['code' => 'C']), 'changes' => [
                'teacher' => ['from' => ['id' => 'tea-1', 'name' => 'Ana Ruiz'], 'to' => ['id' => 'tea-2', 'name' => 'Luis Pérez']],
                'quota' => ['from' => 30, 'to' => 35],
                'career_quotas' => ['from' => [['id' => 'car-ISC', 'code' => 'ISC', 'seats' => 30]], 'to' => [['id' => 'car-ISC', 'code' => 'ISC', 'seats' => 35]]],
            ]],
            ['group' => array_replace($group, ['code' => 'D']), 'changes' => [
                'career_quotas' => ['from' => [['id' => 'car-ISC', 'code' => 'ISC', 'seats' => 30]], 'to' => [['id' => 'car-ISC', 'code' => 'ISC', 'seats' => 25]]],
            ]],
        ],
    ], 'summary' => []];

    $lines = SnapshotDiffText::publicEntries($diff);

    expect($lines)->toBe([
        'Grupo nuevo: SCD-1027 Tópicos Avanzados de Programación, grupo A (C2), lunes 7:00 a 9:00 en A-101.',
        'Se canceló SCD-1027 Tópicos Avanzados de Programación, grupo B (C2).',
        'SCD-1027 Tópicos Avanzados de Programación, grupo C (C2): docente de Ana Ruiz a Luis Pérez.',
    ]);

    $text = implode(' ', $lines);
    expect($text)->not->toContain('cupo')->not->toContain('lugares por carrera')->not->toContain('ISC')->not->toContain('dep-')->not->toContain('tea-')->not->toContain('car-');
});
