<?php

use App\Modules\Academic\Actions\MarkCurrentSpecialties;
use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/*
| 1.69: las especialidades con el periodo de inicio más reciente de cada plan (catálogo del SII) quedan activas y las
| demás del plan, inactivas; las que el SII no lista no se tocan (confirmado el 26 de noviembre de 2026).
*/

function siiSpecialty(string $plan, string $code, string $start, string $end = '99999'): array
{
    return ['plan' => $plan, 'code' => $code, 'start' => $start, 'end' => $end];
}

beforeEach(function () {
    $this->plan = StudyPlan::factory()->create(['code' => 'ISC-2010']);
    $this->a = Specialty::factory()->for($this->plan)->create(['code' => '01']);
    $this->b = Specialty::factory()->for($this->plan)->create(['code' => '02']);
    $this->c = Specialty::factory()->for($this->plan)->create(['code' => '03']);
});

it('activa la de periodo de inicio más reciente y desactiva las demás del plan', function () {
    $result = app(MarkCurrentSpecialties::class)->handle([
        siiSpecialty('ISC-2010', '01', '20131'),
        siiSpecialty('ISC-2010', '02', '20201'),
        siiSpecialty('ISC-2010', '03', '20201'),
    ]);

    expect($this->a->fresh()->is_active)->toBeFalse()
        ->and($this->b->fresh()->is_active)->toBeTrue()
        ->and($this->c->fresh()->is_active)->toBeTrue()
        ->and($result['plans']['ISC-2010'])->toBe(['current' => '20201', 'activated' => 0, 'deactivated' => 1, 'unchanged' => 2]);
});

it('una especialidad cerrada con el término 20001 queda inactiva aunque sea la más reciente', function () {
    app(MarkCurrentSpecialties::class)->handle([
        siiSpecialty('ISC-2010', '01', '20201', '20001'),
        siiSpecialty('ISC-2010', '02', '20201'),
        siiSpecialty('ISC-2010', '03', '20131'),
    ]);

    expect($this->a->fresh()->is_active)->toBeFalse()->and($this->b->fresh()->is_active)->toBeTrue();
});

it('reactiva una inactiva si es de las más recientes', function () {
    $this->b->update(['is_active' => false]);

    app(MarkCurrentSpecialties::class)->handle([siiSpecialty('ISC-2010', '01', '20131'), siiSpecialty('ISC-2010', '02', '20201')]);

    expect($this->b->fresh()->is_active)->toBeTrue()->and($this->a->fresh()->is_active)->toBeFalse();
});

it('deja bitácora de cada cambio y es idempotente', function () {
    $rows = [siiSpecialty('ISC-2010', '01', '20131'), siiSpecialty('ISC-2010', '02', '20201'), siiSpecialty('ISC-2010', '03', '20201')];

    app(MarkCurrentSpecialties::class)->handle($rows);
    $logged = Activity::query()->count();
    $second = app(MarkCurrentSpecialties::class)->handle($rows);

    expect($logged)->toBe(1)
        ->and(Activity::query()->count())->toBe(1)
        ->and($second['plans']['ISC-2010'])->toBe(['current' => '20201', 'activated' => 0, 'deactivated' => 0, 'unchanged' => 3]);
});

it('con --dry-run solo cuenta y no escribe', function () {
    $result = app(MarkCurrentSpecialties::class)->handle([siiSpecialty('ISC-2010', '01', '20131'), siiSpecialty('ISC-2010', '02', '20201'), siiSpecialty('ISC-2010', '03', '20201')], dryRun: true);

    expect($result['plans']['ISC-2010']['deactivated'])->toBe(1)
        ->and($this->a->fresh()->is_active)->toBeTrue()
        ->and(Activity::query()->count())->toBe(0);
});

it('no toca las especialidades que el SII no lista y las informa; tampoco toca planes ajenos', function () {
    $other = StudyPlan::factory()->create(['code' => 'OTRO']);
    $untouched = Specialty::factory()->for($other)->create(['code' => '09']);

    $result = app(MarkCurrentSpecialties::class)->handle([siiSpecialty('ISC-2010', '01', '20201'), siiSpecialty('NO-EXISTE', '01', '20201')]);

    expect($this->b->fresh()->is_active)->toBeTrue()
        ->and($result['unmatched'])->toBe(['ISC-2010/02', 'ISC-2010/03'])
        ->and($result['unknownPlans'])->toBe(['NO-EXISTE'])
        ->and($untouched->fresh()->is_active)->toBeTrue();
});

describe('comando academic:mark-current-specialties', function () {
    beforeEach(function () {
        DB::unprepared('create schema if not exists sii_archivo');
        DB::unprepared('drop table if exists sii_archivo.especialidades');
        DB::unprepared('create table sii_archivo.especialidades (_linea integer primary key, especialidad text not null, carrera text not null, reticula text not null, nombre_especialidad text not null default \'\', periodo_inicio text not null, periodo_termino text not null)');
    });

    it('lee el SII, resuelve el plan con el curado y desactiva las viejas; con --dry-run no escribe', function () {
        $curated = app_path('Modules/Imports/Sii/Curated/planes.csv');
        $row = collect(file($curated, FILE_IGNORE_NEW_LINES))->skip(1)->map(fn (string $line): array => str_getcsv($line, escape: ''))->first();
        [$carrera, $reticula, $planCode] = [$row[0], $row[1], $row[2]];
        $plan = StudyPlan::factory()->create(['code' => $planCode]);
        $old = Specialty::factory()->for($plan)->create(['code' => 'X1']);
        $new = Specialty::factory()->for($plan)->create(['code' => 'X2']);
        DB::table('sii_archivo.especialidades')->insert([
            ['_linea' => 2, 'especialidad' => 'X1', 'carrera' => $carrera, 'reticula' => $reticula, 'periodo_inicio' => '20131', 'periodo_termino' => '99999'],
            ['_linea' => 3, 'especialidad' => 'X2', 'carrera' => $carrera, 'reticula' => $reticula, 'periodo_inicio' => '20201', 'periodo_termino' => '99999'],
        ]);

        $this->artisan('academic:mark-current-specialties', ['--dry-run' => true])->expectsOutputToContain('Simulación')->assertSuccessful();
        expect($old->fresh()->is_active)->toBeTrue();

        $this->artisan('academic:mark-current-specialties')->assertSuccessful();
        expect($old->fresh()->is_active)->toBeFalse()->and($new->fresh()->is_active)->toBeTrue();
    });
});
