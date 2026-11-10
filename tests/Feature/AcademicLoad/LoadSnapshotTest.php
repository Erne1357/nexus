<?php

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\AcademicLoad\Rules\SnapshotPayload;
use App\Modules\Core\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| `load_snapshots` (tarea 4.3): una versión por publicación de una carga. HC-18: ninguna se edita ni se borra.
*/

it('HC-18: rechaza UPDATE de un snapshot', function () {
    $snapshot = LoadSnapshot::factory()->create();

    expect(fn () => DB::table('load_snapshots')->where('id', $snapshot->id)->update(['version' => 9]))
        ->toThrow(QueryException::class, 'load_snapshots es inmutable');
});

it('HC-18: rechaza DELETE de un snapshot', function () {
    $snapshot = LoadSnapshot::factory()->create();

    expect(fn () => DB::table('load_snapshots')->where('id', $snapshot->id)->delete())
        ->toThrow(QueryException::class, 'load_snapshots es inmutable');
});

it('HC-18: rechaza TRUNCATE de la tabla', function () {
    LoadSnapshot::factory()->create();

    expect(fn () => DB::statement('truncate table load_snapshots'))
        ->toThrow(QueryException::class, 'load_snapshots es inmutable');
});

it('una carga no repite versión', function () {
    $snapshot = LoadSnapshot::factory()->create();

    expect(fn () => DB::table('load_snapshots')->insert(['id' => fakeKey(1), 'academic_load_id' => $snapshot->academic_load_id, 'version' => 1, 'payload' => '{}', 'created_by' => $snapshot->created_by]))
        ->toThrow(QueryException::class, 'load_snapshots_academic_load_id_version_unique');
});

it('la versión empieza en 1', function () {
    $snapshot = LoadSnapshot::factory()->create();

    expect(fn () => DB::table('load_snapshots')->insert(['id' => fakeKey(1), 'academic_load_id' => $snapshot->academic_load_id, 'version' => 0, 'payload' => '{}', 'created_by' => $snapshot->created_by]))
        ->toThrow(QueryException::class, 'load_snapshots_version_check');
});

it('la carga lista sus versiones en orden y latestVersion deja solo la última de cada carga', function () {
    $load = AcademicLoad::factory()->inStatus(LoadStatus::Published)->create();
    $other = LoadSnapshot::factory()->create();
    $publisher = User::factory()->create();

    foreach ([2, 1, 3] as $version) {
        LoadSnapshot::factory()->for($load)->create(['version' => $version, 'created_by' => $publisher->id]);
    }

    expect($load->snapshots->pluck('version')->all())->toBe([1, 2, 3])
        ->and(LoadSnapshot::query()->latestVersion()->get()->map(fn (LoadSnapshot $s): string => "{$s->academic_load_id}|{$s->version}")->sort()->values()->all())
        ->toBe(collect(["{$load->id}|3", "{$other->academic_load_id}|1"])->sort()->values()->all())
        ->and($load->fresh()->current_version)->toBe(3)
        ->and($load->fresh()->published_by)->toBe($publisher->id);
});

it('la factory guarda un payload válido de la carga y versión que recibe', function () {
    $snapshot = LoadSnapshot::factory()->create(['version' => 2])->fresh();

    expect(SnapshotPayload::errors($snapshot->payload))->toBe([])
        ->and($snapshot->payload['version'])->toBe(2)
        ->and($snapshot->payload['load_id'])->toBe($snapshot->academic_load_id)
        ->and($snapshot->payload['published_by']['id'])->toBe($snapshot->created_by)
        ->and($snapshot->creator->id)->toBe($snapshot->created_by);
});

it('withGroups reemplaza los grupos de ejemplo', function () {
    $snapshot = LoadSnapshot::factory()->withGroups([])->create();

    expect($snapshot->payload['groups'])->toBe([])
        ->and(SnapshotPayload::errors($snapshot->payload))->toBe([]);
});
