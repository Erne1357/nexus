<?php

use App\Modules\AcademicLoad\Rules\VersionChanges;

/*
| El tablero de versiones (tarea 8.10) lee el diff de una versión (SnapshotDiff) por tipo de cambio: nuevos, cancelados,
| horario, docente, salón, cupo y cupos por carrera, cada grupo con lo que tenía y lo que quedó; y por docente o por
| carrera, con las mismas líneas que dijeron los avisos de la republicación (RA-05, RA-06).
*/

/**
 * Un grupo del diff de Cálculo Integral en C2.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function versionChangesGroup(array $overrides = []): array
{
    return array_replace([
        'id' => 'g-a',
        'code' => 'A',
        'subject' => ['id' => 'sub-1', 'code' => 'ACF-0902', 'name' => 'Cálculo Integral'],
        'campus' => 'C2',
        'teacher' => ['id' => 'tea-ana', 'name' => 'Ana Ruiz'],
        'careers' => [['id' => 'car-isc', 'code' => 'ISC', 'department_id' => 'dep-sis', 'is_owner' => true], ['id' => 'car-ind', 'code' => 'IND', 'department_id' => 'dep-ind', 'is_owner' => false]],
        'sessions' => [
            ['day' => 1, 'starts_at' => '07:00', 'ends_at' => '08:00', 'room' => 'D-14'],
            ['day' => 2, 'starts_at' => '07:00', 'ends_at' => '09:00', 'room' => null],
            ['day' => 3, 'starts_at' => '07:00', 'ends_at' => '08:00', 'room' => 'D-14'],
        ],
        'other_departments' => [],
    ], $overrides);
}

/**
 * Un diff con un grupo nuevo, uno cancelado y uno con cambios.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function versionChangesDiff(array $changes): array
{
    return [
        'version' => 1,
        'groups' => [
            'added' => [versionChangesGroup(['id' => 'g-e', 'code' => 'E'])],
            'cancelled' => [versionChangesGroup(['id' => 'g-d', 'code' => 'D', 'subject' => ['id' => 'sub-2', 'code' => 'ACF-0904', 'name' => 'Cálculo Vectorial'], 'teacher' => null, 'campus' => null])],
            'changed' => [['group' => versionChangesGroup(), 'changes' => $changes]],
        ],
        'summary' => ['added' => 1, 'cancelled' => 1, 'changed' => 1, 'teachers_affected' => 1, 'careers_affected' => 2, 'departments_affected' => 0],
    ];
}

it('junta las sesiones con las mismas horas y salón en pocas palabras', function () {
    expect(VersionChanges::sessions(versionChangesGroup()['sessions']))->toBe('L Mi 7:00–8:00 en D-14 · M 7:00–9:00 sin salón')
        ->and(VersionChanges::sessions([['day' => 4, 'starts_at' => '11:00', 'ends_at' => '13:30']]))->toBe('J 11:00–13:30')
        ->and(VersionChanges::sessions([]))->toBe('sin horario');
});

it('agrupa el diff por tipo, en orden, y deja fuera los tipos sin cambios', function () {
    $sections = VersionChanges::byKind(versionChangesDiff([
        'teacher' => ['from' => ['id' => 'tea-ana', 'name' => 'Ana Ruiz'], 'to' => null],
        'quota' => ['from' => 35, 'to' => 45],
    ]));

    expect(array_column($sections, 'kind'))->toBe(['added', 'cancelled', 'teacher', 'quota'])
        ->and(array_column($sections, 'label'))->toBe(['Nuevos', 'Cancelados', 'Docente', 'Cupo'])
        ->and($sections[0]['rows'][0])->toMatchArray([
            'group' => ['code' => 'E', 'subject_code' => 'ACF-0902', 'subject_name' => 'Cálculo Integral', 'campus' => 'C2'],
            'detail' => 'L Mi 7:00–8:00 en D-14 · M 7:00–9:00 sin salón · Ana Ruiz · ISC, IND',
        ])
        ->and($sections[1]['rows'][0]['detail'])->toBe('Tenía L Mi 7:00–8:00 en D-14 · M 7:00–9:00 sin salón · Por asignar · ISC, IND')
        ->and($sections[1]['rows'][0]['group']['campus'])->toBeNull()
        ->and($sections[2]['rows'][0])->toMatchArray(['from' => 'Ana Ruiz', 'to' => 'Por asignar'])
        ->and($sections[3]['rows'][0])->toMatchArray(['from' => '35', 'to' => '45']);
});

it('dice cada clase de cambio como lo que tenía y lo que quedó', function (array $changes, string $kind, string $from, string $to) {
    $section = collect(VersionChanges::byKind(versionChangesDiff($changes)))->firstWhere('kind', $kind);

    expect($section['rows'][0])->toMatchArray(['from' => $from, 'to' => $to]);
})->with([
    'horario' => [
        ['schedule' => ['from' => [['day' => 1, 'starts_at' => '07:00', 'ends_at' => '08:00'], ['day' => 3, 'starts_at' => '07:00', 'ends_at' => '08:00']], 'to' => [['day' => 2, 'starts_at' => '07:00', 'ends_at' => '09:30'], ['day' => 4, 'starts_at' => '07:00', 'ends_at' => '09:30']]]],
        'schedule', 'L Mi 7:00–8:00', 'M J 7:00–9:30',
    ],
    'salón en todas las sesiones' => [
        ['room' => [
            'from' => [['day' => 1, 'starts_at' => '07:00', 'ends_at' => '08:00', 'room' => 'D-14'], ['day' => 3, 'starts_at' => '07:00', 'ends_at' => '08:00', 'room' => 'D-14']],
            'to' => [['day' => 1, 'starts_at' => '07:00', 'ends_at' => '08:00', 'room' => 'E-05'], ['day' => 3, 'starts_at' => '07:00', 'ends_at' => '08:00', 'room' => 'E-05']],
        ]],
        'room', 'D-14 (las 2 sesiones)', 'E-05',
    ],
    'salón de una sesión' => [
        ['room' => [
            'from' => [['day' => 1, 'starts_at' => '07:00', 'ends_at' => '08:00', 'room' => 'D-14'], ['day' => 3, 'starts_at' => '07:00', 'ends_at' => '08:00', 'room' => null]],
            'to' => [['day' => 1, 'starts_at' => '07:00', 'ends_at' => '08:00', 'room' => 'D-14'], ['day' => 3, 'starts_at' => '07:00', 'ends_at' => '08:00', 'room' => 'A-204']],
        ]],
        'room', 'sin salón (Mi 7:00–8:00)', 'A-204',
    ],
    'otro salón con la misma clave' => [
        ['room' => [
            'from' => [['day' => 1, 'starts_at' => '07:00', 'ends_at' => '08:00', 'room' => 'D-14']],
            'to' => [['day' => 1, 'starts_at' => '07:00', 'ends_at' => '08:00', 'room' => 'D-14']],
        ]],
        'room', 'L 7:00–8:00 en D-14', 'L 7:00–8:00 en D-14',
    ],
    'salón y horario a la vez' => [
        [
            'schedule' => ['from' => [['day' => 1, 'starts_at' => '07:00', 'ends_at' => '08:00']], 'to' => [['day' => 5, 'starts_at' => '09:00', 'ends_at' => '10:00']]],
            'room' => ['from' => [['day' => 1, 'starts_at' => '07:00', 'ends_at' => '08:00', 'room' => 'D-14']], 'to' => [['day' => 5, 'starts_at' => '09:00', 'ends_at' => '10:00', 'room' => 'E-05']]],
        ],
        'room', 'L 7:00–8:00 en D-14', 'V 9:00–10:00 en E-05',
    ],
    'docente asignado' => [
        ['teacher' => ['from' => null, 'to' => ['id' => 'tea-jor', 'name' => 'Jorge Esparza']]],
        'teacher', 'Por asignar', 'Jorge Esparza',
    ],
    'cupos por carrera' => [
        ['career_quotas' => ['from' => [['id' => 'car-isc', 'code' => 'ISC', 'seats' => 15], ['id' => 'car-ind', 'code' => 'IND', 'seats' => 20]], 'to' => [['id' => 'car-isc', 'code' => 'ISC', 'seats' => 25]]]],
        'career_quotas', 'ISC 15 · IND 20', 'ISC 25',
    ],
]);

it('un diff sin cambios no tiene secciones', function () {
    expect(VersionChanges::byKind(['version' => 1, 'groups' => ['added' => [], 'cancelled' => [], 'changed' => []], 'summary' => []]))->toBe([]);
});

it('por docente da a cada uno, por nombre, las líneas que le avisaron (RA-05)', function () {
    $teachers = VersionChanges::byTeacher(versionChangesDiff(['teacher' => ['from' => ['id' => 'tea-ana', 'name' => 'Ana Ruiz'], 'to' => ['id' => 'tea-jor', 'name' => 'Jorge Esparza']]]));

    expect(array_column($teachers, 'name'))->toBe(['Ana Ruiz', 'Jorge Esparza'])
        ->and($teachers[0]['lines'])->toBe([
            'Grupo nuevo: ACF-0902 Cálculo Integral, grupo E (C2), lunes 7:00 a 8:00 en D-14, martes 7:00 a 9:00 sin salón y miércoles 7:00 a 8:00 en D-14.',
            'Ya no impartes ACF-0902 Cálculo Integral, grupo A (C2).',
        ])
        ->and($teachers[1]['lines'])->toBe(['Ahora impartes ACF-0902 Cálculo Integral, grupo A (C2), lunes 7:00 a 8:00 en D-14, martes 7:00 a 9:00 sin salón y miércoles 7:00 a 8:00 en D-14.']);
});

it('por carrera da a cada una, por clave y con su nombre, las líneas de sus grupos (RA-05)', function () {
    $careers = VersionChanges::byCareer(versionChangesDiff(['quota' => ['from' => 35, 'to' => 45]]), ['car-isc' => 'Ingeniería en Sistemas Computacionales']);

    expect(array_column($careers, 'code'))->toBe(['IND', 'ISC'])
        ->and($careers[1]['name'])->toBe('Ingeniería en Sistemas Computacionales')
        ->and($careers[0]['name'])->toBeNull()
        ->and($careers[1]['lines'])->toBe([
            'Grupo nuevo: ACF-0902 Cálculo Integral, grupo E (C2), lunes 7:00 a 8:00 en D-14, martes 7:00 a 9:00 sin salón y miércoles 7:00 a 8:00 en D-14.',
            'Se canceló ACF-0904 Cálculo Vectorial, grupo D.',
            'ACF-0902 Cálculo Integral, grupo A (C2): cupo de 35 a 45.',
        ]);
});
