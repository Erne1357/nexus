<?php

use App\Modules\AcademicLoad\Database\Factories\LoadSnapshotFactory;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Rules\SnapshotPayload;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/*
| El contrato del snapshot (tarea 4.3, docs/dominio/modelo-de-datos.md, «Contenido del snapshot»): la forma v2 que
| arma la publicación y leen la consulta publicada y los exportes, con la modalidad de la carga en la raíz y el campus de
| cada grupo (M1). Las fotos v1, con el campus en la raíz, no se reescriben (HC-18): normalize() las lee como
| modalidad presencial con el campus de la raíz en cada grupo (MS13).
*/

function samplePayload(): array
{
    return LoadSnapshotFactory::payload(AcademicLoad::factory()->create(), 1, User::factory()->create());
}

it('el payload de ejemplo de la factory cumple la forma v2', function () {
    expect(SnapshotPayload::errors(samplePayload()))->toBe([]);
});

it('el encabezado sale de la carga, de su modalidad y su perfil, y de quien publica (M1)', function () {
    $modality = Modality::factory()->create(['code' => 'XPR', 'name' => 'Experimental', 'settings' => ['uses_rooms' => false, 'days' => [1, 2, 3, 4, 5, 6], 'day_start' => '08:00', 'day_end' => '20:00', 'block_minutes' => 90]]);
    $load = AcademicLoad::factory()->create(['modality_id' => $modality->id]);
    $publisher = User::factory()->create(['name' => 'Laura Méndez']);

    $header = SnapshotPayload::header($load, 3, $publisher, CarbonImmutable::parse('2026-12-10 10:30', 'America/Ciudad_Juarez'));

    expect($header)->toMatchArray([
        'schema_version' => 2,
        'load_id' => $load->id,
        'version' => 3,
        'published_at' => '2026-12-10T10:30:00-07:00',
        'published_by' => ['id' => $publisher->id, 'name' => 'Laura Méndez'],
        'term' => ['id' => $load->term->id, 'code' => $load->term->code, 'name' => $load->term->name],
        'modality' => ['id' => $modality->id, 'code' => 'XPR', 'name' => 'Experimental'],
        'profile' => ['uses_rooms' => false, 'uses_time_blocks' => true, 'days' => [1, 2, 3, 4, 5, 6], 'day_start' => '08:00', 'day_end' => '20:00', 'block_minutes' => 90],
        'department' => ['id' => $load->department->id, 'code' => $load->department->code, 'name' => $load->department->name],
    ])->and($header)->not->toHaveKey('campus')
        ->and(SnapshotPayload::errors([...$header, 'groups' => []]))->toBe([]);
});

it('cada grupo lleva su campus, o ninguno en una modalidad sin salones', function () {
    $c2 = Campus::factory()->create(['code' => 'C2']);
    $payload = samplePayload();

    expect($payload['groups'][0]['campus'])->toBe(['id' => $c2->id, 'code' => 'C2', 'name' => $c2->name]);

    $payload['groups'][0]['campus'] = null;

    expect(SnapshotPayload::errors($payload))->toBe([]);

    Arr::forget($payload, 'groups.1.campus');

    expect(SnapshotPayload::errors($payload))->toBe(['groups.1.campus: falta']);
});

it('MS13: normalize lee una foto v1 como modalidad presencial y pasa el campus de la raíz a cada grupo, sin cambiar lo demás', function () {
    $c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $presencial = modalityOf('PRES');
    $old = samplePayload();
    unset($old['modality']);
    $old['schema_version'] = 1;
    $old['campus'] = ['id' => $c2->id, 'code' => 'C2', 'name' => 'Campus II'];
    $old['groups'] = array_map(function (array $group): array {
        unset($group['campus']);

        return $group;
    }, $old['groups']);

    $normalized = SnapshotPayload::normalize($old);

    expect($normalized['schema_version'])->toBe(2)
        ->and($normalized['modality'])->toBe(['id' => $presencial->id, 'code' => 'PRES', 'name' => $presencial->name])
        ->and($normalized)->not->toHaveKey('campus')
        ->and(array_column($normalized['groups'], 'campus'))->toBe(array_fill(0, count($old['groups']), ['id' => $c2->id, 'code' => 'C2', 'name' => 'Campus II']))
        ->and($normalized['groups'][0]['sessions'])->toBe($old['groups'][0]['sessions'])
        ->and(SnapshotPayload::errors($normalized))->toBe([]);
});

it('MS13: normalize toma la modalidad del campus de la raíz por el puente y, sin él, la presencial; nunca usa el id del campus', function () {
    $ead = Campus::factory()->create(['code' => 'EAD', 'modality_id' => modalityOf('EAD')->id]);
    $old = fn (array $campus): array => [...array_diff_key(samplePayload(), ['modality' => true]), 'schema_version' => 1, 'campus' => $campus];

    expect(SnapshotPayload::normalize($old(['id' => $ead->id, 'code' => 'EAD', 'name' => 'Campus EAD']))['modality']['code'])->toBe('EAD')
        ->and(SnapshotPayload::normalize($old(['id' => (string) Str::uuid7(), 'code' => 'CX', 'name' => 'Campus que ya no existe']))['modality'])
        ->toBe(['id' => modalityOf('PRES')->id, 'code' => 'PRES', 'name' => modalityOf('PRES')->name]);
});

it('normalize deja igual una foto v2', function () {
    $payload = samplePayload();

    expect(SnapshotPayload::normalize($payload))->toBe($payload);
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
    'otra versión del esquema' => [fn (array &$p) => data_set($p, 'schema_version', 1), 'schema_version: valor no admitido'],
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
    Arr::forget($payload, ['term', 'modality']);

    expect(fn () => SnapshotPayload::assertValid($payload))
        ->toThrow(InvalidArgumentException::class, 'El snapshot no cumple su forma: term: falta (y 1 más)');
});
