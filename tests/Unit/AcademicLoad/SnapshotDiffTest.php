<?php

use App\Modules\AcademicLoad\Rules\SnapshotDiff;

/*
| SnapshotDiff (tarea 8.4): lo que cambió una versión publicada contra la anterior, por grupo (`groups[].id` del payload):
| grupos nuevos, cancelados y cambiados (horario, docente, salón, cupo y cupos por carrera), con las etiquetas para
| mostrarlo sin leer otra tabla. De ahí salen los avisos de RA-05: cada docente afectado con lo que cambió para él, las
| carreras afectadas (sus coordinadores) y los otros departamentos cuando se tocan sus préstamos de salón o sus grupos
| compartidos.
*/

const DIFF_DEPARTMENT = 'dep-sis';

/** El departamento de cada carrera: ISC es del departamento de la carga; IND e IGE, de otros. */
const DIFF_CAREER_DEPARTMENTS = ['car-ISC' => DIFF_DEPARTMENT, 'car-IND' => 'dep-ind', 'car-IGE' => 'dep-eco'];

/**
 * Un payload v2 de la carga de Sistemas con estos grupos.
 *
 * @param  list<array<string, mixed>>  $groups
 * @return array<string, mixed>
 */
function diffPayload(array $groups, int $version = 1): array
{
    return [
        'schema_version' => 2,
        'load_id' => 'load-1',
        'version' => $version,
        'published_at' => '2026-12-03T18:42:00-07:00',
        'published_by' => ['id' => 'user-1', 'name' => 'Laura Méndez'],
        'term' => ['id' => 'term-1', 'code' => '20271', 'name' => 'Ene-Jun 2027'],
        'modality' => ['id' => 'mod-1', 'code' => 'PRES', 'name' => 'Presencial'],
        'profile' => ['uses_rooms' => true, 'uses_time_blocks' => true, 'days' => [1, 2, 3, 4, 5], 'day_start' => '07:00', 'day_end' => '21:00', 'block_minutes' => 60],
        'department' => ['id' => DIFF_DEPARTMENT, 'code' => 'SIS', 'name' => 'Sistemas y Computación'],
        'groups' => $groups,
    ];
}

/**
 * Un grupo de Tópicos Avanzados de Programación en C2, con Ortiz, 30 lugares de ISC y una sesión el lunes en A-101.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function snapshotDiffGroup(string $id, array $overrides = []): array
{
    return array_replace([
        'id' => $id,
        'code' => 'A',
        'status' => 'confirmed',
        'capacity' => 30,
        'justification' => null,
        'campus' => ['id' => 'cam-c2', 'code' => 'C2', 'name' => 'Campus II'],
        'subject' => ['id' => 'sub-'.$id, 'code' => 'SCD-1027', 'name' => 'Tópicos Avanzados de Programación', 'credits' => 5, 'theory_hours' => 2, 'practice_hours' => 3, 'department_id' => DIFF_DEPARTMENT],
        'teacher' => diffTeacher('ortiz'),
        'placements' => [diffPlacement('ISC', 30)],
        'sessions' => [diffSession(1, '07:00', '09:00', diffRoom('A-101'))],
    ], $overrides);
}

/**
 * @return array{id: string, rfc: string, name: string}
 */
function diffTeacher(string $key): array
{
    return ['id' => 'tea-'.$key, 'rfc' => 'XAXX010101000', 'name' => ucfirst($key)];
}

/**
 * @return array<string, mixed>
 */
function diffPlacement(string $career, int $seats, bool $owner = true, ?string $plan = 'P1'): array
{
    return [
        'career' => ['id' => 'car-'.$career, 'code' => $career, 'name' => 'Carrera '.$career],
        'study_plan' => $plan === null ? null : ['id' => 'plan-'.$career.$plan, 'code' => $plan],
        'semester' => $plan === null ? null : 4,
        'seats' => $seats,
        'is_owner' => $owner,
    ];
}

/**
 * @param  array<string, mixed>|null  $room
 * @return array<string, mixed>
 */
function diffSession(int $day, string $startsAt, string $endsAt, ?array $room, string $kind = 'teoria'): array
{
    return ['day' => $day, 'starts_at' => $startsAt, 'ends_at' => $endsAt, 'kind' => $kind, 'kind_label' => ucfirst($kind), 'room' => $room];
}

/**
 * Un salón de Sistemas, o prestado si su dueño es otro departamento.
 *
 * @return array<string, mixed>
 */
function diffRoom(string $code, string $owner = DIFF_DEPARTMENT): array
{
    return ['id' => 'room-'.$code, 'code' => $code, 'name' => null, 'building' => ['id' => 'bld-A', 'code' => 'A', 'name' => 'Edificio A'], 'owner_department_id' => $owner];
}

/**
 * @param  list<array<string, mixed>>  $before
 * @param  list<array<string, mixed>>  $after
 * @return array<string, mixed>
 */
function diffOf(array $before, array $after): array
{
    return SnapshotDiff::between(diffPayload($before), diffPayload($after, 2), DIFF_CAREER_DEPARTMENTS);
}

it('sin cambios, el diff está vacío (y lo que no se compara, como el tipo de sesión o la justificación, no cuenta)', function () {
    $before = [snapshotDiffGroup('g1'), snapshotDiffGroup('g2', ['code' => 'B', 'teacher' => null, 'justification' => 'Docente en contratación.'])];
    $after = [
        snapshotDiffGroup('g1', ['sessions' => [diffSession(1, '07:00', '09:00', diffRoom('A-101'), 'practica')]]),
        snapshotDiffGroup('g2', ['code' => 'B', 'teacher' => null, 'justification' => 'Otro motivo.']),
    ];

    $diff = diffOf($before, $after);

    expect($diff)->toBe([
        'version' => 1,
        'groups' => ['added' => [], 'cancelled' => [], 'changed' => []],
        'summary' => ['added' => 0, 'cancelled' => 0, 'changed' => 0, 'teachers_affected' => 0, 'careers_affected' => 0, 'departments_affected' => 0],
    ])->and(SnapshotDiff::isEmpty($diff))->toBeTrue();
});

it('un grupo nuevo entra en added con las etiquetas para mostrarlo sin leer otra tabla', function () {
    $diff = diffOf([], [snapshotDiffGroup('g1', ['placements' => [diffPlacement('ISC', 25), diffPlacement('IND', 5, false, null)]])]);

    expect($diff['groups']['added'])->toBe([[
        'id' => 'g1',
        'code' => 'A',
        'subject' => ['id' => 'sub-g1', 'code' => 'SCD-1027', 'name' => 'Tópicos Avanzados de Programación'],
        'campus' => 'C2',
        'teacher' => ['id' => 'tea-ortiz', 'name' => 'Ortiz'],
        'careers' => [
            ['id' => 'car-ISC', 'code' => 'ISC', 'department_id' => DIFF_DEPARTMENT, 'is_owner' => true],
            ['id' => 'car-IND', 'code' => 'IND', 'department_id' => 'dep-ind', 'is_owner' => false],
        ],
        'sessions' => [['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00', 'room' => 'A-101']],
        'other_departments' => ['dep-ind'],
    ]])->and($diff['summary'])->toMatchArray(['added' => 1, 'cancelled' => 0, 'changed' => 0])
        ->and(SnapshotDiff::isEmpty($diff))->toBeFalse();
});

it('un grupo que ya no está entra en cancelled, con lo que tenía en la versión anterior', function () {
    $diff = diffOf([snapshotDiffGroup('g1'), snapshotDiffGroup('g2', ['code' => 'B'])], [snapshotDiffGroup('g1')]);

    expect($diff['groups']['cancelled'])->toHaveCount(1)
        ->and($diff['groups']['cancelled'][0])->toMatchArray(['id' => 'g2', 'code' => 'B', 'teacher' => ['id' => 'tea-ortiz', 'name' => 'Ortiz']])
        ->and($diff['groups']['added'])->toBe([])
        ->and($diff['groups']['changed'])->toBe([])
        ->and($diff['summary']['cancelled'])->toBe(1);
});

it('detecta cada clase de cambio de un grupo que sigue, con su antes y su después', function (array $after, array $changes) {
    $diff = diffOf([snapshotDiffGroup('g1')], [snapshotDiffGroup('g1', $after)]);

    expect($diff['groups']['changed'])->toHaveCount(1)
        ->and($diff['groups']['changed'][0]['group']['id'])->toBe('g1')
        ->and($diff['groups']['changed'][0]['changes'])->toBe($changes);
})->with([
    'horario' => [
        ['sessions' => [diffSession(2, '09:00', '11:00', diffRoom('A-101'))]],
        ['schedule' => ['from' => [['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00']], 'to' => [['day' => 2, 'starts_at' => '09:00', 'ends_at' => '11:00']]]],
    ],
    'docente' => [
        ['teacher' => diffTeacher('delgado')],
        ['teacher' => ['from' => ['id' => 'tea-ortiz', 'name' => 'Ortiz'], 'to' => ['id' => 'tea-delgado', 'name' => 'Delgado']]],
    ],
    'docente que se quita' => [
        ['teacher' => null],
        ['teacher' => ['from' => ['id' => 'tea-ortiz', 'name' => 'Ortiz'], 'to' => null]],
    ],
    'salón' => [
        ['sessions' => [diffSession(1, '07:00', '09:00', diffRoom('A-102'))]],
        ['room' => ['from' => [['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00', 'room' => 'A-101']], 'to' => [['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00', 'room' => 'A-102']]]],
    ],
    'salón que se quita' => [
        ['sessions' => [diffSession(1, '07:00', '09:00', null)]],
        ['room' => ['from' => [['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00', 'room' => 'A-101']], 'to' => [['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00', 'room' => null]]]],
    ],
    'cupo del grupo' => [
        ['capacity' => 35],
        ['quota' => ['from' => 30, 'to' => 35]],
    ],
    'cupos por carrera' => [
        ['placements' => [diffPlacement('ISC', 25), diffPlacement('IND', 5, false)]],
        ['career_quotas' => [
            'from' => [['id' => 'car-ISC', 'code' => 'ISC', 'seats' => 30]],
            'to' => [['id' => 'car-ISC', 'code' => 'ISC', 'seats' => 25], ['id' => 'car-IND', 'code' => 'IND', 'seats' => 5]],
        ]],
    ],
]);

it('los lugares de una carrera cuentan una vez aunque se repitan en cada retícula', function () {
    $twoPlans = [diffPlacement('ISC', 30), diffPlacement('ISC', 30, false, 'P2')];

    expect(SnapshotDiff::isEmpty(diffOf([snapshotDiffGroup('g1')], [snapshotDiffGroup('g1', ['placements' => $twoPlans])])))->toBeTrue();
});

it('mover una sesión sin cambiar de salón es solo horario; cambiar las dos cosas da los dos cambios', function () {
    $moved = diffOf([snapshotDiffGroup('g1')], [snapshotDiffGroup('g1', ['sessions' => [diffSession(3, '07:00', '09:00', diffRoom('A-101'))]])]);
    $both = diffOf([snapshotDiffGroup('g1')], [snapshotDiffGroup('g1', ['sessions' => [diffSession(3, '07:00', '09:00', diffRoom('A-204'))]])]);

    expect(array_keys($moved['groups']['changed'][0]['changes']))->toBe(['schedule'])
        ->and(array_keys($both['groups']['changed'][0]['changes']))->toBe(['schedule', 'room'])
        ->and($both['groups']['changed'][0]['changes']['room']['to'])->toBe([['day' => 3, 'starts_at' => '07:00', 'ends_at' => '09:00', 'room' => 'A-204']]);
});

it('dos sesiones que intercambian salón son un cambio de salón', function () {
    $sessions = fn (string $monday, string $wednesday): array => ['sessions' => [diffSession(1, '07:00', '09:00', diffRoom($monday)), diffSession(3, '07:00', '09:00', diffRoom($wednesday))]];

    $diff = diffOf([snapshotDiffGroup('g1', $sessions('A-101', 'A-102'))], [snapshotDiffGroup('g1', $sessions('A-102', 'A-101'))]);

    expect(array_keys($diff['groups']['changed'][0]['changes']))->toBe(['room']);
});

it('RA-05: cada docente afectado, con exactamente lo que cambió para él', function () {
    $before = [
        snapshotDiffGroup('g1'),
        snapshotDiffGroup('g2', ['code' => 'B', 'teacher' => diffTeacher('delgado')]),
        snapshotDiffGroup('g3', ['code' => 'C', 'teacher' => diffTeacher('ruiz')]),
        snapshotDiffGroup('g4', ['code' => 'D', 'teacher' => diffTeacher('soto')]),
        snapshotDiffGroup('g6', ['code' => 'F', 'teacher' => diffTeacher('lara')]),
    ];
    $after = [
        snapshotDiffGroup('g1', ['teacher' => diffTeacher('ruiz'), 'capacity' => 35]),
        snapshotDiffGroup('g3', ['code' => 'C', 'teacher' => diffTeacher('ruiz'), 'sessions' => [diffSession(2, '09:00', '11:00', diffRoom('A-101'))]]),
        snapshotDiffGroup('g4', ['code' => 'D', 'teacher' => diffTeacher('soto')]),
        snapshotDiffGroup('g5', ['code' => 'E', 'teacher' => diffTeacher('lara')]),
        snapshotDiffGroup('g6', ['code' => 'F', 'teacher' => diffTeacher('lara'), 'placements' => [diffPlacement('ISC', 20)]]),
    ];

    $teachers = SnapshotDiff::teachers(diffOf($before, $after));
    $kinds = array_map(fn (array $teacher): array => array_map(fn (array $entry): string => "{$entry['kind']}:{$entry['group']['code']}", $teacher['entries']), $teachers);

    expect($kinds)->toEqualCanonicalizing([
        'tea-ortiz' => ['unassigned:A'],
        'tea-ruiz' => ['assigned:A', 'changed:C'],
        'tea-delgado' => ['cancelled:B'],
        'tea-lara' => ['added:E', 'changed:F'],
    ])->and($teachers)->not->toHaveKey('tea-soto')
        ->and($teachers['tea-ruiz']['teacher'])->toBe(['id' => 'tea-ruiz', 'name' => 'Ruiz'])
        ->and(collect($teachers['tea-ruiz']['entries'])->firstWhere('kind', 'changed')['changes'])->toHaveKey('schedule')
        ->and(collect($teachers['tea-lara']['entries'])->firstWhere('kind', 'changed')['changes'])->toHaveKey('career_quotas')
        ->and(collect($teachers['tea-ruiz']['entries'])->firstWhere('kind', 'assigned'))->not->toHaveKey('changes');
});

it('RA-05: un grupo sin docente que cambia no afecta a ningún docente', function () {
    $diff = diffOf([snapshotDiffGroup('g1', ['teacher' => null])], [snapshotDiffGroup('g1', ['teacher' => null, 'capacity' => 40])]);

    expect(SnapshotDiff::teachers($diff))->toBe([])
        ->and($diff['summary']['teachers_affected'])->toBe(0)
        ->and($diff['summary']['changed'])->toBe(1);
});

it('RA-05: las carreras afectadas son las que tienen lugares en el grupo antes o después, también la que sale', function () {
    $before = [snapshotDiffGroup('g1', ['placements' => [diffPlacement('ISC', 25), diffPlacement('IGE', 5, false)]]), snapshotDiffGroup('g2', ['code' => 'B'])];
    $after = [snapshotDiffGroup('g1', ['placements' => [diffPlacement('ISC', 25), diffPlacement('IND', 5, false)]]), snapshotDiffGroup('g2', ['code' => 'B'])];

    $diff = diffOf($before, $after);
    $careers = SnapshotDiff::careers($diff);

    expect(array_keys($careers))->toEqualCanonicalizing(['car-ISC', 'car-IND', 'car-IGE'])
        ->and($careers['car-IGE']['career'])->toBe(['id' => 'car-IGE', 'code' => 'IGE'])
        ->and(array_map(fn (array $entry): string => "{$entry['kind']}:{$entry['group']['code']}", $careers['car-IGE']['entries']))->toBe(['changed:A'])
        ->and($diff['groups']['changed'][0]['group']['careers'])->toBe([
            ['id' => 'car-ISC', 'code' => 'ISC', 'department_id' => DIFF_DEPARTMENT, 'is_owner' => true],
            ['id' => 'car-IND', 'code' => 'IND', 'department_id' => 'dep-ind', 'is_owner' => false],
            ['id' => 'car-IGE', 'code' => 'IGE', 'department_id' => 'dep-eco', 'is_owner' => false],
        ])
        ->and($diff['summary']['careers_affected'])->toBe(3);
});

it('RA-05: otro departamento se afecta cuando cambia una sesión en su salón prestado, no cuando cambia otra sesión del grupo', function () {
    $loaned = diffRoom('LC-2', 'dep-ind');
    $before = [snapshotDiffGroup('g1', ['sessions' => [diffSession(1, '07:00', '09:00', diffRoom('A-101')), diffSession(3, '07:00', '09:00', $loaned)]])];
    $ownMoved = [snapshotDiffGroup('g1', ['sessions' => [diffSession(2, '07:00', '09:00', diffRoom('A-101')), diffSession(3, '07:00', '09:00', $loaned)]])];
    $loanMoved = [snapshotDiffGroup('g1', ['sessions' => [diffSession(1, '07:00', '09:00', diffRoom('A-101')), diffSession(4, '07:00', '09:00', $loaned)]])];

    expect(SnapshotDiff::departments(diffOf($before, $ownMoved)))->toBe([])
        ->and(array_keys(SnapshotDiff::departments(diffOf($before, $loanMoved))))->toBe(['dep-ind'])
        ->and(diffOf($before, $loanMoved)['summary']['departments_affected'])->toBe(1);
});

it('RA-05: un grupo con salón prestado que se crea o se cancela afecta al dueño del salón', function () {
    $group = snapshotDiffGroup('g1', ['sessions' => [diffSession(3, '07:00', '09:00', diffRoom('LC-2', 'dep-ind'))]]);

    expect(array_keys(SnapshotDiff::departments(diffOf([], [$group]))))->toBe(['dep-ind'])
        ->and(array_keys(SnapshotDiff::departments(diffOf([$group], []))))->toBe(['dep-ind'])
        ->and(SnapshotDiff::departments(diffOf([$group], []))['dep-ind']['entries'][0]['kind'])->toBe('cancelled');
});

it('RA-05: tocar un grupo compartido afecta al departamento de la otra carrera; uno solo del departamento de la carga, a nadie más', function () {
    $shared = snapshotDiffGroup('g1', ['placements' => [diffPlacement('ISC', 25), diffPlacement('IGE', 5, false)]]);
    $own = snapshotDiffGroup('g2', ['code' => 'B']);

    $diff = diffOf([$shared, $own], [array_replace($shared, ['teacher' => diffTeacher('ruiz')]), array_replace($own, ['capacity' => 40])]);
    $departments = SnapshotDiff::departments($diff);

    expect(array_keys($departments))->toBe(['dep-eco'])
        ->and(array_map(fn (array $entry): string => $entry['group']['code'], $departments['dep-eco']['entries']))->toBe(['A'])
        ->and($diff['groups']['changed'][1]['group']['other_departments'])->toBe([]);
});

it('RA-05: la carrera dueña de otro departamento no hace compartido el grupo (Básicas imparte para ISC)', function () {
    $group = snapshotDiffGroup('g1', ['placements' => [diffPlacement('IND', 30)]]);

    $diff = diffOf([$group], [array_replace($group, ['capacity' => 35])]);

    expect(SnapshotDiff::departments($diff))->toBe([])
        ->and(array_keys(SnapshotDiff::careers($diff)))->toBe(['car-IND']);
});

it('una carrera sin departamento conocido no afecta a otro departamento', function () {
    $group = snapshotDiffGroup('g1', ['placements' => [diffPlacement('ISC', 25), diffPlacement('IGE', 5, false)]]);

    $diff = SnapshotDiff::between(diffPayload([]), diffPayload([$group], 2));

    expect($diff['groups']['added'][0]['careers'][1])->toBe(['id' => 'car-IGE', 'code' => 'IGE', 'department_id' => null, 'is_owner' => false])
        ->and(SnapshotDiff::departments($diff))->toBe([]);
});

it('da las carreras de varios payloads, cada una una vez, para buscar su departamento', function () {
    $before = diffPayload([snapshotDiffGroup('g1', ['placements' => [diffPlacement('ISC', 25), diffPlacement('ISC', 25, false, 'P2')]])]);
    $after = diffPayload([snapshotDiffGroup('g1', ['placements' => [diffPlacement('ISC', 25), diffPlacement('IND', 5, false)]])]);

    expect(SnapshotDiff::careerIds($before, $after))->toBe(['car-ISC', 'car-IND']);
});

it('el resumen cuenta grupos, docentes, carreras y otros departamentos afectados', function () {
    $before = [snapshotDiffGroup('g1'), snapshotDiffGroup('g2', ['code' => 'B', 'teacher' => diffTeacher('delgado'), 'placements' => [diffPlacement('ISC', 20), diffPlacement('IND', 10, false)]])];
    $after = [snapshotDiffGroup('g1', ['capacity' => 35]), snapshotDiffGroup('g3', ['code' => 'C', 'teacher' => diffTeacher('ruiz')])];

    expect(diffOf($before, $after)['summary'])->toBe([
        'added' => 1,
        'cancelled' => 1,
        'changed' => 1,
        'teachers_affected' => 3,
        'careers_affected' => 2,
        'departments_affected' => 1,
    ]);
});
