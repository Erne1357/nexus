<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Actions\CreateTermLoads;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;

/*
| Al abrir un periodo en una modalidad (M4), AcademicLoad crea en borrador la carga de cada departamento activo que
| todavía no la tenga en esa modalidad (MS15, supuesto por confirmar). La carga es por departamento y modalidad (M1):
| abrir Presencial crea una por departamento, con los grupos de C1 y C2 juntos, sin importar cuántos campus tenga.
*/

function createTermLoads(Term $term, Modality $modality): int
{
    return app(CreateTermLoads::class)->handle($term, $modality);
}

/**
 * Las cargas del periodo como «departamento modalidad», ordenadas.
 *
 * @return list<string>
 */
function loadKeysOf(Term $term): array
{
    return AcademicLoad::query()->where('term_id', $term->id)->with(['department', 'modality'])->get()
        ->map(fn (AcademicLoad $load): string => "{$load->department->code} {$load->modality->code}")
        ->sort()->values()->all();
}

beforeEach(function () {
    $this->presencial = modalityOf('PRES');
});

it('M1 y M4: crea en borrador una carga por departamento activo en la modalidad, aunque tenga dos campus', function () {
    $term = Term::factory()->create();
    Department::factory()->create(['code' => 'D1']);
    Department::factory()->create(['code' => 'D2']);
    Campus::factory()->create(['code' => 'C1']);
    Campus::factory()->create(['code' => 'C2']);

    expect(createTermLoads($term, $this->presencial))->toBe(2)
        ->and(loadKeysOf($term))->toBe(['D1 PRES', 'D2 PRES'])
        ->and(AcademicLoad::query()->pluck('status')->unique()->all())->toBe([LoadStatus::Draft]);
});

it('cada carga nace con la modalidad que se abre', function () {
    $term = Term::factory()->create();
    Department::factory()->count(2)->create();
    $ead = modalityOf('EAD');
    $ead->update(['is_active' => true]);

    createTermLoads($term, $ead);

    expect(AcademicLoad::query()->with('modality')->get()->map(fn (AcademicLoad $load): string => $load->modality->code)->all())->toBe(['EAD', 'EAD']);
});

it('no crea cargas de departamentos inactivos ni borrados', function () {
    $term = Term::factory()->create();
    Department::factory()->create(['code' => 'D1']);
    Department::factory()->inactive()->create();
    Department::factory()->create()->delete();

    expect(createTermLoads($term, $this->presencial))->toBe(1)
        ->and(loadKeysOf($term))->toBe(['D1 PRES']);
});

it('abrir dos veces el periodo en la modalidad deja una carga por departamento; un departamento nuevo recibe solo la suya', function () {
    $term = Term::factory()->create();
    Campus::factory()->create(['code' => 'C1']);
    Campus::factory()->create(['code' => 'C2']);
    Department::factory()->create(['code' => 'D1']);
    createTermLoads($term, $this->presencial);

    expect(createTermLoads($term, $this->presencial))->toBe(0)
        ->and(loadKeysOf($term))->toBe(['D1 PRES']);

    Department::factory()->create(['code' => 'D2']);

    expect(createTermLoads($term, $this->presencial))->toBe(1)
        ->and(loadKeysOf($term))->toBe(['D1 PRES', 'D2 PRES']);
});

it('la misma modalidad en otro periodo, u otra modalidad en el mismo, tiene sus propias cargas', function () {
    $term = Term::factory()->create();
    Department::factory()->create(['code' => 'D1']);
    $ead = modalityOf('EAD');
    $ead->update(['is_active' => true]);
    createTermLoads($term, $this->presencial);

    expect(createTermLoads($term, $ead))->toBe(1)
        ->and(createTermLoads(Term::factory()->create(), $this->presencial))->toBe(1)
        ->and(loadKeysOf($term))->toBe(['D1 EAD', 'D1 PRES']);
});

it('no toca la carga que ya avanzó', function () {
    $term = Term::factory()->create();
    $load = AcademicLoad::factory()->inStatus(LoadStatus::Staffing)->create(['term_id' => $term->id, 'modality_id' => $this->presencial->id]);

    createTermLoads($term, $this->presencial);

    expect($load->fresh()->status)->toBe(LoadStatus::Staffing);
});
