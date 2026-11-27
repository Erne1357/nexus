<?php

use App\Modules\AcademicLoad\Queries\PublishedGroup;
use App\Modules\AcademicLoad\Queries\PublishedSchedule;

/*
| PublishedSchedule (tarea 4.4): junta la última versión publicada de cada carga de una modalidad y periodo (payload v2
| del snapshot, con el campus de cada grupo; M1) y la ordena para la consulta pública: por carrera, retícula y semestre (con la colocación congelada), por
| docente, por salón y por departamento. Lo publicado se muestra sin decir qué falta (decisión del usuario del 10 de
| noviembre de 2026).
*/

function publishedRoomPayload(string $code, string $building = 'Edificio B', ?string $owner = 'dep-sc'): array
{
    return [
        'id' => 'room-'.$code,
        'code' => $code,
        'name' => 'Aula '.$code,
        'building' => ['id' => 'bld-'.$building, 'code' => substr($building, -1), 'name' => $building],
        'owner_department_id' => $owner,
    ];
}

function publishedSessionPayload(int $day, string $startsAt, string $endsAt, ?array $room, string $kind = 'teoria'): array
{
    return [
        'day' => $day,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'kind' => $kind,
        'kind_label' => ['teoria' => 'Teoría', 'practica' => 'Práctica', 'virtual' => 'Virtual'][$kind],
        'room' => $room,
    ];
}

function publishedPlacementPayload(string $career, ?string $plan, ?int $semester, int $seats = 35, bool $owner = true): array
{
    return [
        'career' => ['id' => 'car-'.$career, 'code' => $career, 'name' => 'Carrera '.$career],
        'study_plan' => $plan === null ? null : ['id' => 'plan-'.$plan, 'code' => $plan],
        'semester' => $semester,
        'seats' => $seats,
        'is_owner' => $owner,
    ];
}

/**
 * @param  list<array<string, mixed>>  $placements
 * @param  list<array<string, mixed>>  $sessions
 */
function publishedGroupPayload(string $subject, string $code, ?string $teacher, array $placements, array $sessions, string $department = 'dep-sc', string $campus = 'C2'): array
{
    return [
        'id' => 'grp-'.$subject.'-'.$code,
        'code' => $code,
        'status' => 'confirmed',
        'capacity' => 35,
        'justification' => null,
        'campus' => ['id' => 'campus-'.strtolower($campus), 'code' => $campus, 'name' => 'Campus '.$campus],
        'subject' => ['id' => 'sub-'.$subject, 'code' => $subject, 'name' => 'Materia '.$subject, 'credits' => 5, 'theory_hours' => 3, 'practice_hours' => 2, 'department_id' => $department],
        'teacher' => $teacher === null ? null : ['id' => 'tea-'.$teacher, 'rfc' => 'RFC'.strtoupper($teacher).'000', 'name' => $teacher],
        'placements' => $placements,
        'sessions' => $sessions,
    ];
}

/**
 * @param  list<array<string, mixed>>  $groups
 */
function publishedLoadPayload(string $department, array $groups, int $version = 1, string $publishedAt = '2026-12-10T09:00:00-07:00', array $overrides = []): array
{
    return array_replace([
        'schema_version' => 2,
        'load_id' => 'load-'.$department,
        'version' => $version,
        'published_at' => $publishedAt,
        'published_by' => ['id' => 'usr-1', 'name' => 'Jefa de prueba'],
        'term' => ['id' => 'term-20271', 'code' => '20271', 'name' => 'Enero–Junio 2027'],
        'modality' => ['id' => 'mod-pres', 'code' => 'PRES', 'name' => 'Presencial'],
        'profile' => ['uses_rooms' => true, 'uses_time_blocks' => true, 'days' => [1, 2, 3, 4, 5], 'day_start' => '07:00', 'day_end' => '21:00', 'block_minutes' => 60],
        'department' => ['id' => $department, 'code' => strtoupper(substr($department, 4)), 'name' => 'Departamento '.$department],
        'groups' => $groups,
    ], $overrides);
}

/**
 * Dos departamentos: Ciencias Básicas da Cálculo a Sistemas y a Industrial; Sistemas da Estructura de Datos con una
 * práctica en laboratorio y un grupo sin docente, y comparte uno con Gestión, que lo lleva en dos retículas.
 */
function publishedTwoDepartments(): PublishedSchedule
{
    $b12 = publishedRoomPayload('B-12');
    $lc2 = publishedRoomPayload('LC-2', 'Edificio de Laboratorios');
    $a3 = publishedRoomPayload('A-3', 'Edificio A', 'dep-cb');

    $basic = publishedLoadPayload('dep-cb', [
        publishedGroupPayload('ACF-0904', 'B', 'Laura Ríos', [publishedPlacementPayload('ISC', 'ISIC-2010', 3), publishedPlacementPayload('IND', 'IIND-2010', 3, 10, false)], [
            publishedSessionPayload(1, '08:00', '09:00', $b12),
            publishedSessionPayload(2, '08:00', '09:00', $b12),
        ], 'dep-cb'),
        publishedGroupPayload('ACF-0904', 'D', 'Héctor Saucedo', [publishedPlacementPayload('ISC', 'ISIC-2010', 3)], [
            publishedSessionPayload(1, '10:00', '11:00', $a3),
        ], 'dep-cb'),
    ], publishedAt: '2026-12-08T10:00:00-07:00');

    $systems = publishedLoadPayload('dep-sc', [
        publishedGroupPayload('AED-1026', 'C', 'Laura Ríos', [publishedPlacementPayload('ISC', 'ISIC-2010', 3)], [
            publishedSessionPayload(1, '09:00', '10:00', $b12),
            publishedSessionPayload(2, '09:00', '11:00', $lc2, 'practica'),
            publishedSessionPayload(5, '09:00', '10:00', null, 'virtual'),
        ]),
        publishedGroupPayload('AED-1026', 'K', null, [publishedPlacementPayload('ISC', 'ISIC-2004', 4)], [
            publishedSessionPayload(3, '17:00', '18:00', $b12),
        ]),
        publishedGroupPayload('SCC-1005', 'E', 'Mónica Treviño', [
            publishedPlacementPayload('IGE', 'IGEM-2009', 2),
            publishedPlacementPayload('ISC', 'ISIC-2010', 3, 12, false),
            publishedPlacementPayload('ISC', 'ISIC-2004', 4, 12, false),
            publishedPlacementPayload('LOG', null, null, 5, false),
        ], [publishedSessionPayload(4, '11:00', '12:00', $a3)]),
    ], version: 2, publishedAt: '2026-12-12T10:30:00-07:00');

    return PublishedSchedule::fromPayloads([$basic, $systems]);
}

it('lista las carreras con sus retículas y semestres desde las colocaciones congeladas, sin las que no tienen retícula', function () {
    expect(publishedTwoDepartments()->careers())->toBe([
        ['career' => ['id' => 'car-IGE', 'code' => 'IGE', 'name' => 'Carrera IGE'], 'plans' => [
            ['plan' => ['id' => 'plan-IGEM-2009', 'code' => 'IGEM-2009'], 'semesters' => [2]],
        ]],
        ['career' => ['id' => 'car-IND', 'code' => 'IND', 'name' => 'Carrera IND'], 'plans' => [
            ['plan' => ['id' => 'plan-IIND-2010', 'code' => 'IIND-2010'], 'semesters' => [3]],
        ]],
        ['career' => ['id' => 'car-ISC', 'code' => 'ISC', 'name' => 'Carrera ISC'], 'plans' => [
            ['plan' => ['id' => 'plan-ISIC-2004', 'code' => 'ISIC-2004'], 'semesters' => [4]],
            ['plan' => ['id' => 'plan-ISIC-2010', 'code' => 'ISIC-2010'], 'semesters' => [3]],
        ]],
    ]);
});

it('junta en un semestre los grupos de varios departamentos, propios y con cupo, ordenados por materia y grupo', function () {
    $schedule = publishedTwoDepartments();

    expect(array_map(fn (PublishedGroup $group) => $group->label(), $schedule->semester('car-ISC', 'plan-ISIC-2010', 3)))
        ->toBe(['ACF-0904 · B', 'ACF-0904 · D', 'AED-1026 · C', 'SCC-1005 · E'])
        ->and(array_map(fn (PublishedGroup $group) => $group->label(), $schedule->semester('car-ISC', 'plan-ISIC-2004', 4)))
        ->toBe(['AED-1026 · K', 'SCC-1005 · E'])
        ->and($schedule->semester('car-ISC', 'plan-ISIC-2010', 5))->toBe([]);
});

it('da los grupos de un docente en todos los departamentos del campus y lista a los docentes por nombre', function () {
    $schedule = publishedTwoDepartments();

    expect(array_map(fn (PublishedGroup $group) => $group->label(), $schedule->teacher('tea-Laura Ríos')))
        ->toBe(['ACF-0904 · B', 'AED-1026 · C'])
        ->and($schedule->teachers())->toBe([
            ['id' => 'tea-Héctor Saucedo', 'name' => 'Héctor Saucedo'],
            ['id' => 'tea-Laura Ríos', 'name' => 'Laura Ríos'],
            ['id' => 'tea-Mónica Treviño', 'name' => 'Mónica Treviño'],
        ]);
});

it('no expone el RFC del docente y dice qué grupo no tiene docente', function () {
    $groups = publishedTwoDepartments()->semester('car-ISC', 'plan-ISIC-2004', 4);

    expect($groups[0]->teacher)->toBeNull()
        ->and($groups[1]->teacher)->toBe(['id' => 'tea-Mónica Treviño', 'name' => 'Mónica Treviño']);
});

it('da de un salón solo sus sesiones, y lista los salones por edificio y clave sin las sesiones virtuales', function () {
    $schedule = publishedTwoDepartments();
    $room = $schedule->room('room-B-12');

    expect(array_map(fn (PublishedGroup $group) => $group->label(), $room))->toBe(['ACF-0904 · B', 'AED-1026 · C', 'AED-1026 · K'])
        ->and(array_map(fn (PublishedGroup $group) => count($group->sessions), $room))->toBe([2, 1, 1])
        ->and(array_column($schedule->rooms(), 'code'))->toBe(['A-3', 'B-12', 'LC-2'])
        ->and($schedule->rooms()[1])->toBe(['id' => 'room-B-12', 'code' => 'B-12', 'name' => 'Aula B-12', 'building' => 'Edificio B']);
});

it('da los grupos de un departamento y sabe qué departamentos ya publicaron', function () {
    $schedule = publishedTwoDepartments();

    expect(array_map(fn (PublishedGroup $group) => $group->label(), $schedule->department('dep-cb')))->toBe(['ACF-0904 · B', 'ACF-0904 · D'])
        ->and($schedule->isPublished('dep-cb'))->toBeTrue()
        ->and($schedule->isPublished('dep-ii'))->toBeFalse();
});

it('fecha cada vista con la publicación más reciente de las cargas que la forman', function () {
    $schedule = publishedTwoDepartments();

    expect($schedule->updatedAt($schedule->department('dep-cb'))?->toIso8601String())->toBe('2026-12-08T10:00:00-07:00')
        ->and($schedule->updatedAt($schedule->semester('car-ISC', 'plan-ISIC-2010', 3))?->toIso8601String())->toBe('2026-12-12T10:30:00-07:00')
        ->and($schedule->updatedAt([]))->toBeNull();
});

it('conserva las sesiones del grupo con su tipo, sus horas y su salón', function () {
    $group = publishedTwoDepartments()->semester('car-ISC', 'plan-ISIC-2010', 3)[2];

    expect($group->subject['name'])->toBe('Materia AED-1026')
        ->and($group->capacity)->toBe(35)
        ->and($group->departmentId)->toBe('dep-sc')
        ->and($group->version)->toBe(2)
        ->and(array_map(fn ($session) => [$session->day, $session->startsAt, $session->endsAt, $session->kindLabel, $session->room['code'] ?? null], $group->sessions))
        ->toBe([[1, '09:00', '10:00', 'Teoría', 'B-12'], [2, '09:00', '11:00', 'Práctica', 'LC-2'], [5, '09:00', '10:00', 'Virtual', null]]);
});

it('toma el perfil de la modalidad del payload para pintar la semana', function () {
    $profile = publishedTwoDepartments()->profile();

    expect($profile->days)->toBe([1, 2, 3, 4, 5])
        ->and($profile->usesRooms)->toBeTrue()
        ->and($profile->blockMinutes)->toBe(60);
});

it('sin publicaciones no tiene grupos ni carreras', function () {
    $schedule = PublishedSchedule::fromPayloads([]);

    expect($schedule->groups())->toBe([])
        ->and($schedule->careers())->toBe([])
        ->and($schedule->isEmpty())->toBeTrue();
});

it('rechaza juntar payloads de otra modalidad o periodo, o de una versión de esquema que no conoce (llegan normalizados)', function (array $overrides) {
    PublishedSchedule::fromPayloads([
        publishedLoadPayload('dep-cb', []),
        publishedLoadPayload('dep-sc', [], overrides: $overrides),
    ]);
})->throws(InvalidArgumentException::class)->with([
    'otra modalidad' => [['modality' => ['id' => 'mod-ead', 'code' => 'EAD', 'name' => 'EAD']]],
    'otro periodo' => [['term' => ['id' => 'term-20263', 'code' => '20263', 'name' => 'Agosto–Diciembre 2026']]],
    'esquema 1 sin normalizar' => [['schema_version' => 1]],
]);

it('M1: onlyCampus deja solo los grupos que se dan en ese campus, y campuses() dice los campus con grupos', function () {
    $schedule = PublishedSchedule::fromPayloads([
        publishedLoadPayload('dep-sc', [
            publishedGroupPayload('AED-1026', 'C', 'Laura Ríos', [publishedPlacementPayload('ISC', 'ISIC-2010', 3)], [], campus: 'C2'),
            publishedGroupPayload('AED-1026', 'K', null, [publishedPlacementPayload('ISC', 'ISIC-2010', 3)], [], campus: 'C1'),
        ]),
    ]);

    expect(array_column($schedule->campuses(), 'code'))->toBe(['C1', 'C2'])
        ->and(array_map(fn (PublishedGroup $group): string => $group->code, $schedule->onlyCampus('C1')->groups()))->toBe(['K'])
        ->and($schedule->onlyCampus('C1')->isPublished('dep-sc'))->toBeTrue()
        ->and($schedule->onlyCampus(null))->toBe($schedule);
});
