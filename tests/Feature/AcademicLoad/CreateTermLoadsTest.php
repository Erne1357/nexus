<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Actions\CreateTermLoads;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Enums\Modality as CampusModality;
use App\Modules\Core\Events\TermOpened;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;

/*
| Al abrir un periodo en un campus, AcademicLoad crea en borrador la carga de cada departamento activo
| que todavía no la tenga en ese periodo y campus (S-A6, supuesto por confirmar).
*/

function createTermLoads(Term $term, Campus $campus): int
{
    return app(CreateTermLoads::class)->handle($term, $campus);
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

it('crea en borrador la carga de cada departamento activo en el campus', function () {
    $term = Term::factory()->create();
    Department::factory()->create(['code' => 'D1']);
    Department::factory()->create(['code' => 'D2']);

    expect(createTermLoads($term, Campus::factory()->create(['code' => 'C2'])))->toBe(2)
        ->and(loadKeysOf($term))->toBe(['D1 C2', 'D2 C2'])
        ->and(AcademicLoad::query()->pluck('status')->unique()->all())->toBe([LoadStatus::Draft]);
});

it('cada carga nace con la modalidad del catálogo de su campus (ampliación de la ronda 2)', function () {
    $term = Term::factory()->create();
    Department::factory()->count(2)->create();
    $campus = Campus::factory()->create(['code' => 'EAD', 'modality' => CampusModality::Mixta]);

    createTermLoads($term, $campus);

    expect(AcademicLoad::query()->with('modality')->get()->map(fn (AcademicLoad $load): string => $load->modality->code)->all())->toBe(['EAD', 'EAD']);
});

it('no crea cargas de departamentos inactivos ni borrados', function () {
    $term = Term::factory()->create();
    Department::factory()->create(['code' => 'D1']);
    Department::factory()->inactive()->create();
    Department::factory()->create()->delete();

    expect(createTermLoads($term, Campus::factory()->create(['code' => 'C2'])))->toBe(1)
        ->and(loadKeysOf($term))->toBe(['D1 C2']);
});

it('es idempotente: la segunda vez no crea nada; un departamento nuevo recibe solo la suya', function () {
    $term = Term::factory()->create();
    $c2 = Campus::factory()->create(['code' => 'C2']);
    Department::factory()->create(['code' => 'D1']);
    createTermLoads($term, $c2);

    expect(createTermLoads($term, $c2))->toBe(0);

    Department::factory()->create(['code' => 'D2']);

    expect(createTermLoads($term, $c2))->toBe(1)
        ->and(loadKeysOf($term))->toBe(['D1 C2', 'D2 C2']);
});

it('no toca la carga que ya avanzó', function () {
    $term = Term::factory()->create();
    $campus = Campus::factory()->create();
    $load = AcademicLoad::factory()->inStatus(LoadStatus::Staffing)->create(['term_id' => $term->id, 'campus_id' => $campus->id]);

    createTermLoads($term, $campus);

    expect($load->fresh()->status)->toBe(LoadStatus::Staffing);
});

it('cada campus tiene sus propias cargas', function () {
    $term = Term::factory()->create();
    Department::factory()->create(['code' => 'D1']);
    createTermLoads($term, Campus::factory()->create(['code' => 'C2']));

    expect(createTermLoads($term, Campus::factory()->create(['code' => 'C1'])))->toBe(1)
        ->and(loadKeysOf($term))->toBe(['D1 C1', 'D1 C2']);
});

describe('al oír TermOpened', function () {
    it('reporta cuántas cargas creó, en plural', function () {
        Department::factory()->count(3)->create();
        $event = new TermOpened(Term::factory()->create(), Campus::factory()->create());

        event($event);

        expect($event->lines())->toContain('3 cargas académicas nuevas');
    });

    it('reporta «1 carga académica nueva» en singular', function () {
        Department::factory()->create();
        $event = new TermOpened(Term::factory()->create(), Campus::factory()->create());

        event($event);

        expect($event->lines())->toContain('1 carga académica nueva');
    });

    it('no reporta nada si no faltaba ninguna', function () {
        $term = Term::factory()->create();
        $campus = Campus::factory()->create();
        Department::factory()->create();
        createTermLoads($term, $campus);
        $event = new TermOpened($term, $campus);

        event($event);

        expect(array_values(array_filter($event->lines(), fn (string $line): bool => str_contains($line, 'carga'))))->toBe([]);
    });
});
