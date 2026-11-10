<?php

use App\Modules\AcademicLoad\Database\Factories\LoadSnapshotFactory;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Rules\SnapshotPayload;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

/*
| El contrato del snapshot (tarea 4.3, docs/dominio/modelo-de-datos.md, «Contenido del snapshot»): la forma v1 que
| arma la publicación y leen la consulta publicada y los exportes.
*/

function samplePayload(): array
{
    return LoadSnapshotFactory::payload(AcademicLoad::factory()->create(), 1, User::factory()->create());
}

it('el payload de ejemplo de la factory cumple la forma v1', function () {
    expect(SnapshotPayload::errors(samplePayload()))->toBe([]);
});

it('el encabezado sale de la carga, del perfil de su campus y de quien publica', function () {
    $campus = Campus::factory()->create(['settings' => ['uses_rooms' => false, 'days' => [1, 2, 3, 4, 5, 6], 'day_start' => '08:00', 'day_end' => '20:00', 'block_minutes' => 90]]);
    $load = AcademicLoad::factory()->create(['campus_id' => $campus->id]);
    $publisher = User::factory()->create(['name' => 'Laura Méndez']);

    $header = SnapshotPayload::header($load, 3, $publisher, CarbonImmutable::parse('2026-12-10 10:30', 'America/Ciudad_Juarez'));

    expect($header)->toMatchArray([
        'schema_version' => 1,
        'load_id' => $load->id,
        'version' => 3,
        'published_at' => '2026-12-10T10:30:00-07:00',
        'published_by' => ['id' => $publisher->id, 'name' => 'Laura Méndez'],
        'term' => ['id' => $load->term->id, 'code' => $load->term->code, 'name' => $load->term->name],
        'campus' => ['id' => $campus->id, 'code' => $campus->code, 'name' => $campus->name],
        'profile' => ['uses_rooms' => false, 'uses_time_blocks' => true, 'days' => [1, 2, 3, 4, 5, 6], 'day_start' => '08:00', 'day_end' => '20:00', 'block_minutes' => 90],
        'department' => ['id' => $load->department->id, 'code' => $load->department->code, 'name' => $load->department->name],
    ])->and(SnapshotPayload::errors([...$header, 'groups' => []]))->toBe([]);
});

it('admite docente, salón, retícula y semestre vacíos', function () {
    $payload = samplePayload();
    $payload['groups'][0]['teacher'] = null;
    $payload['groups'][0]['sessions'][0]['room'] = null;
    $payload['groups'][0]['placements'][] = LoadSnapshotFactory::placement(['study_plan' => null, 'semester' => null, 'seats' => 0, 'is_owner' => false]);

    expect(SnapshotPayload::errors($payload))->toBe([]);
});

it('rechaza un payload que no cumple la forma, con la ruta del problema', function (Closure $break, string $error) {
    $payload = samplePayload();
    $break($payload);

    expect(SnapshotPayload::errors($payload))->toContain($error);
})->with([
    'falta una llave' => [fn (array &$p) => Arr::forget($p, 'groups.0.sessions.0.starts_at'), 'groups.0.sessions.0.starts_at: falta'],
    'llave de más' => [fn (array &$p) => data_set($p, 'groups.0.notes', 'x'), 'groups.0.notes: no es parte de la forma'],
    'otra versión del esquema' => [fn (array &$p) => data_set($p, 'schema_version', 2), 'schema_version: valor no admitido'],
    'día fuera de la semana' => [fn (array &$p) => data_set($p, 'groups.0.sessions.0.day', 7), 'groups.0.sessions.0.day: se esperaba day'],
    'hora sin cero' => [fn (array &$p) => data_set($p, 'groups.0.sessions.0.starts_at', '7:00'), 'groups.0.sessions.0.starts_at: se esperaba time'],
    'tipo de sesión desconocido' => [fn (array &$p) => data_set($p, 'groups.0.sessions.0.kind', 'otro'), 'groups.0.sessions.0.kind: valor no admitido'],
    'grupo cancelado' => [fn (array &$p) => data_set($p, 'groups.0.status', 'cancelled'), 'groups.0.status: valor no admitido'],
    'docente sin RFC' => [fn (array &$p) => Arr::forget($p, 'groups.0.teacher.rfc'), 'groups.0.teacher.rfc: falta'],
    'capacidad cero' => [fn (array &$p) => data_set($p, 'groups.0.capacity', 0), 'groups.0.capacity: se esperaba int>=1'],
    'id que no es uuid' => [fn (array &$p) => data_set($p, 'groups.0.subject.id', '42'), 'groups.0.subject.id: se esperaba uuid'],
    'nombre en blanco' => [fn (array &$p) => data_set($p, 'department.name', ' '), 'department.name: se esperaba string'],
    'fecha sin zona' => [fn (array &$p) => data_set($p, 'published_at', '2026-12-10 10:30:00'), 'published_at: se esperaba datetime'],
    'grupos como mapa' => [fn (array &$p) => data_set($p, 'groups', ['a' => $p['groups'][0]]), 'groups: se esperaba una lista'],
    'sesión que termina antes' => [fn (array &$p) => data_set($p, 'groups.0.sessions.0.ends_at', '07:00'), 'groups.0.sessions.0: ends_at debe ser posterior a starts_at'],
    'dueña que no va primero' => [fn (array &$p) => data_set($p, 'groups.1.placements', array_reverse($p['groups'][1]['placements'])), 'groups.1.placements: la carrera dueña va primero y es la única con is_owner'],
    'dos dueñas' => [fn (array &$p) => data_set($p, 'groups.1.placements.1.is_owner', true), 'groups.1.placements: la carrera dueña va primero y es la única con is_owner'],
]);

it('assertValid lanza el primer problema y cuántos más hay', function () {
    $payload = samplePayload();
    Arr::forget($payload, ['term', 'campus']);

    expect(fn () => SnapshotPayload::assertValid($payload))
        ->toThrow(InvalidArgumentException::class, 'El snapshot no cumple su forma: term: falta (y 1 más)');
});
