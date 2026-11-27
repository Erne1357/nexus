<?php

use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Imports\Actions\FillPositionCategories;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/*
| `imports:fill-position-categories` (PL-01): llena la categoría de las plazas ya importadas desde
| `sii_archivo.docentes2.claves_plazas`. Una sola vez, idempotente, con --dry-run. Arman un `sii_archivo` mínimo dentro
| de la transacción de la prueba (DDL transaccional de PostgreSQL). RFC y claves inventados.
*/

beforeEach(function () {
    DB::unprepared('create schema if not exists sii_archivo');
    DB::unprepared('drop table if exists sii_archivo.docentes2');
    DB::unprepared('create table sii_archivo.docentes2 (_linea integer primary key, rfc text not null, claves_plazas text not null default \'\')');

    $this->teacher = Teacher::factory()->create(['rfc' => 'PRUE800101AB1']);
    $this->career = TeacherPosition::factory()->for($this->teacher)->tiempoCompleto()->create();
    $this->assignment = TeacherPosition::factory()->for($this->teacher)->asignatura(7)->create();

    DB::table('sii_archivo.docentes2')->insert([
        ['_linea' => 2, 'rfc' => 'PRUE800101AB1', 'claves_plazas' => '1403 E381700.0100001 1403 E351904.0100002 1403 E352103.0100003'],
        ['_linea' => 3, 'rfc' => 'NOEX800101AA1', 'claves_plazas' => '1403 E381300.0100001'],
    ]);
});

it('PL-01: llena la categoría de cada plaza desde docentes2 y deja bitácora', function () {
    $result = app(FillPositionCategories::class)->handle();

    expect($result)->toBe(['filled' => 2, 'already' => 0, 'unmatched_rows' => 1, 'no_category' => 0])
        ->and($this->career->fresh()->category)->toBe('E3817')
        ->and($this->assignment->fresh()->category)->toBe('E3519')
        ->and(Activity::query()->where('subject_type', (new TeacherPosition)->getMorphClass())->count())->toBe(2);
});

it('PL-01: con --dry-run solo cuenta y no escribe', function () {
    $this->artisan('imports:fill-position-categories', ['--dry-run' => true])
        ->expectsOutputToContain('Llenaría 2 plazas')
        ->assertSuccessful();

    expect(TeacherPosition::query()->whereNotNull('category')->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);
});

it('PL-01: es idempotente y no pisa una categoría que ya tiene la plaza', function () {
    $this->career->update(['category' => 'E3813']);

    $first = app(FillPositionCategories::class)->handle();
    $second = app(FillPositionCategories::class)->handle();

    expect($first['filled'])->toBe(1)
        ->and($second)->toBe(['filled' => 0, 'already' => 2, 'unmatched_rows' => 1, 'no_category' => 0])
        ->and($this->career->fresh()->category)->toBe('E3813')
        ->and($this->assignment->fresh()->category)->toBe('E3519');
});

it('PL-01: sin la exportación archivada avisa y falla', function () {
    DB::unprepared('drop table sii_archivo.docentes2');

    $this->artisan('imports:fill-position-categories')->expectsOutputToContain('No existe sii_archivo.docentes2')->assertFailed();
});
