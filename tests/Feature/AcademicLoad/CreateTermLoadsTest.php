<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Actions\CreateTermLoads;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Enums\Modality as CampusModality;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;

/*
| Al abrir un periodo en una modalidad (M4), AcademicLoad crea en borrador la carga de cada departamento activo que
| todavía no la tenga (MS15, supuesto por confirmar). Mientras la carga sea por campus (hasta que pase a departamento ×
| modalidad, M1), crea una por departamento en cada campus activo de esa modalidad, por el puente `campuses.modality_id`:
| abrir Presencial crea las de C1 y C2, como abrir antes cada campus.
*/

function createTermLoads(Term $term, Modality $modality): int
{
    return app(CreateTermLoads::class)->handle($term, $modality);
}

/**
 * Las cargas del periodo como «departamento campus», ordenadas.
 *
 * @return list<string>
 */
function loadKeysOf(Term $term): array
{
    return AcademicLoad::query()->where('term_id', $term->id)->with(['department', 'campus'])->get()
        ->map(fn (AcademicLoad $load): string => "{$load->department->code} {$load->campus->code}")
        ->sort()->values()->all();
}

beforeEach(function () {
    $this->presencial = modalityOf('PRES');
});

it('M4: crea en borrador la carga de cada departamento activo en cada campus activo de la modalidad', function () {
    $term = Term::factory()->create();
    Department::factory()->create(['code' => 'D1']);
    Department::factory()->create(['code' => 'D2']);
    Campus::factory()->create(['code' => 'C1']);
    Campus::factory()->create(['code' => 'C2']);
    Campus::factory()->inactive()->create(['code' => 'C3']);
    Campus::factory()->create(['code' => 'C8', 'modality_id' => Modality::factory()->create()->id]);

    expect(createTermLoads($term, $this->presencial))->toBe(4)
        ->and(loadKeysOf($term))->toBe(['D1 C1', 'D1 C2', 'D2 C1', 'D2 C2'])
        ->and(AcademicLoad::query()->pluck('status')->unique()->all())->toBe([LoadStatus::Draft]);
});

it('cada carga nace con la modalidad que se abre', function () {
    $term = Term::factory()->create();
    Department::factory()->count(2)->create();
    $ead = modalityOf('EAD');
    $ead->update(['is_active' => true]);
    Campus::factory()->create(['code' => 'EAD', 'modality' => CampusModality::Mixta]);

    createTermLoads($term, $ead);

    expect(AcademicLoad::query()->with('modality')->get()->map(fn (AcademicLoad $load): string => $load->modality->code)->all())->toBe(['EAD', 'EAD']);
});

it('una modalidad sin campus activos no crea cargas', function () {
    Department::factory()->create();

    expect(createTermLoads(Term::factory()->create(), Modality::factory()->create()))->toBe(0)
        ->and(AcademicLoad::count())->toBe(0);
});

it('no crea cargas de departamentos inactivos ni borrados', function () {
    $term = Term::factory()->create();
    Campus::factory()->create(['code' => 'C2']);
    Department::factory()->create(['code' => 'D1']);
    Department::factory()->inactive()->create();
    Department::factory()->create()->delete();

    expect(createTermLoads($term, $this->presencial))->toBe(1)
        ->and(loadKeysOf($term))->toBe(['D1 C2']);
});

it('es idempotente: la segunda vez no crea nada; un departamento nuevo recibe solo las suyas', function () {
    $term = Term::factory()->create();
    Campus::factory()->create(['code' => 'C2']);
    Department::factory()->create(['code' => 'D1']);
    createTermLoads($term, $this->presencial);

    expect(createTermLoads($term, $this->presencial))->toBe(0);

    Department::factory()->create(['code' => 'D2']);

    expect(createTermLoads($term, $this->presencial))->toBe(1)
        ->and(loadKeysOf($term))->toBe(['D1 C2', 'D2 C2']);
});

it('un campus nuevo de la modalidad recibe sus cargas al completar la apertura', function () {
    $term = Term::factory()->create();
    Department::factory()->create(['code' => 'D1']);
    Campus::factory()->create(['code' => 'C2']);
    createTermLoads($term, $this->presencial);
    Campus::factory()->create(['code' => 'C1']);

    expect(createTermLoads($term, $this->presencial))->toBe(1)
        ->and(loadKeysOf($term))->toBe(['D1 C1', 'D1 C2']);
});

it('no toca la carga que ya avanzó', function () {
    $term = Term::factory()->create();
    $campus = Campus::factory()->create();
    $load = AcademicLoad::factory()->inStatus(LoadStatus::Staffing)->create(['term_id' => $term->id, 'campus_id' => $campus->id]);

    createTermLoads($term, $this->presencial);

    expect($load->fresh()->status)->toBe(LoadStatus::Staffing);
});
