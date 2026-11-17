<?php

use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Demo\DemoCheck;
use App\Modules\Core\Demo\DemoContext;
use App\Modules\Core\Demo\DemoStep;
use App\Modules\Core\Enums\DemoCheckpoint;
use App\Modules\Core\Enums\DemoVolume;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Demand\Actions\RefreshDemandForecasts;
use App\Modules\Demand\Database\Seeders\DemandRareCasesDemoSeeder;
use App\Modules\Demand\DemandServiceProvider;
use App\Modules\Demand\Enums\ForecastMethod;
use App\Modules\Demand\Models\DemandForecast;
use App\Modules\Students\Enums\KardexResult;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;
use Illuminate\Support\Collection;

/*
| Los casos raros de la proyección en la demo (tarea 5.10b, #541): para que las pantallas de Demand muestren todos los
| métodos, desde «propuesta» la demo trae filas de (1) una materia nueva que nadie tiene en el kárdex (método
| department_fallback, DM-04), (2) una materia con un solo periodo de historia, (3) una materia de especialidad en un plan
| cuyos alumnos se reparten entre dos especialidades (needs_review), (4) una carrera ofrecida por primera vez en un campus
| sin alumnos (cero elegibles y cero de nuevo ingreso) y (5) primer semestre con nuevo ingreso (DM-05).
|
| Fixture, objetivo 20271 abierto en Presencial; campus K1 (el de la demo) y K2 (otro campus activo, sin la carrera);
| plan de una carrera ofrecida en K1 con A (1.º), X (1.º, nadie la tiene), B (2.º, pide A), E (2.º, pide X: nadie pudo
| cursarla, así que toma el promedio del departamento), C y D (3.º, de dos especialidades). Historia en K1: 4 alumnos
| entran en 20261 con A; 4 aprobaron A en 20253 y B en 20261 (B con un solo periodo); 2 aprueban C y 2 aprueban D en
| 20261. Los casos 1, 2, 3 y 5 salen solos, como en la demo; no hay oferta en K2: el seeder la agrega solo si la
| proyección no trae el caso 4.
*/

beforeEach(function () {
    $this->term = Term::factory()->current()->create(['code' => '20271']);
    TermOpening::factory()->create(['term_id' => $this->term->id]);
    $this->k1 = Campus::factory()->create(['code' => 'K1']);
    $this->k2 = Campus::factory()->create(['code' => 'K2']);
    $this->plan = StudyPlan::factory()->create(['code' => 'PL-RC']);
    CareerCampus::factory()->create(['career_id' => $this->plan->career_id, 'campus_id' => $this->k1->id]);
    $this->department = Department::factory()->create();
    $this->a = Subject::factory()->create(['code' => 'RA-1', 'department_id' => $this->department->id]);
    $this->b = Subject::factory()->create(['code' => 'RB-2', 'department_id' => $this->department->id]);
    $this->c = Subject::factory()->create(['code' => 'RC-3', 'department_id' => $this->department->id]);
    $this->d = Subject::factory()->create(['code' => 'RD-3', 'department_id' => $this->department->id]);
    placeInPlan($this->plan, $this->a);
    placeInPlan($this->plan, $this->b, 2);
    placeInPlan($this->plan, $this->c, 3, Specialty::factory()->create(['study_plan_id' => $this->plan->id]));
    placeInPlan($this->plan, $this->d, 3, Specialty::factory()->create(['study_plan_id' => $this->plan->id]));
    $this->x = Subject::factory()->create(['code' => 'RX-1', 'department_id' => $this->department->id]);
    $this->e = Subject::factory()->create(['code' => 'RE-2', 'department_id' => $this->department->id]);
    placeInPlan($this->plan, $this->x);
    placeInPlan($this->plan, $this->e, 2);
    seriate($this->plan, $this->b, $this->a);
    seriate($this->plan, $this->e, $this->x);

    foreach (range(1, 4) as $i) {
        rareCasesStudent($this->k1, [[$this->a, '20261', KardexResult::Aprobada]]);
        rareCasesStudent($this->k1, [[$this->a, '20253', KardexResult::Aprobada], [$this->b, '20261', KardexResult::Aprobada]]);
    }

    foreach (range(1, 2) as $i) {
        rareCasesStudent($this->k1, [[$this->a, '20253', KardexResult::Aprobada], [$this->c, '20261', KardexResult::Aprobada]]);
        rareCasesStudent($this->k1, [[$this->a, '20253', KardexResult::Aprobada], [$this->d, '20261', KardexResult::Aprobada]]);
    }
});

/**
 * @param  list<array{0: Subject, 1: string, 2: KardexResult}>  $records
 */
function rareCasesStudent(Campus $campus, array $records): Student
{
    $student = Student::factory()->create([
        'study_plan_id' => test()->plan->id,
        'career_id' => test()->plan->career_id,
        'campus_id' => $campus->id,
        'modality_id' => modalityOf('PRES')->id,
    ]);

    foreach ($records as [$subject, $term, $result]) {
        KardexRecord::factory()->for($student)->for($subject)->create(['term_code' => $term, 'result' => $result, 'grade' => null]);
    }

    return $student;
}

function rareCasesSeed(): void
{
    app(DemandRareCasesDemoSeeder::class)->run('20271', ['K1']);
}

/**
 * @return Collection<int, DemandForecast>
 */
function rareCasesRows(): Collection
{
    return DemandForecast::query()->with(['subject', 'campus'])->get();
}

/**
 * Las revisiones de la demo de Demand, por su etiqueta; con el resultado de correrlas en el volumen demo.
 *
 * @return array<string, true|string>
 */
function rareCasesChecks(): array
{
    $context = new DemoContext(DemoVolume::Demo, ['K1']);

    return collect(app()->getProvider(DemandServiceProvider::class)->demoChecks())
        ->filter(fn (DemoCheck $check): bool => in_array(DemoCheckpoint::Proposal, $check->at, true))
        ->mapWithKeys(fn (DemoCheck $check): array => [$check->label => ($check->passes)($context)])
        ->all();
}

it('trae sola la materia nueva que nadie puede cursar, con el promedio del departamento (DM-04)', function () {
    rareCasesSeed();

    $row = rareCasesRows()->first(fn (DemandForecast $forecast): bool => $forecast->subject_id === $this->e->id && $forecast->campus_id === $this->k1->id);

    expect(KardexRecord::query()->where('subject_id', $this->e->id)->exists())->toBeFalse()
        ->and($row)
        ->method->toBe(ForecastMethod::DepartmentFallback)
        ->eligible_students->toBe(0);
});

it('deja la carrera ofrecida por primera vez en un campus sin alumnos, con cero elegibles y cero de nuevo ingreso', function () {
    rareCasesSeed();

    $offering = CareerCampus::query()->where('career_id', $this->plan->career_id)->where('campus_id', $this->k2->id)->sole();
    $rows = rareCasesRows()->where('campus_id', $this->k2->id)->where('career_id', $this->plan->career_id);

    expect($offering->is_active)->toBeTrue()
        ->and(Student::query()->where('campus_id', $this->k2->id)->exists())->toBeFalse()
        ->and($rows)->not->toBeEmpty()
        ->and($rows->sum('eligible_students'))->toBe(0)
        ->and($rows->sum('new_students'))->toBe(0)
        ->and($rows->firstWhere('subject_id', $this->a->id)->new_students)->toBe(0);
});

it('conserva los casos de historia: un solo periodo, especialidad por revisar y primer semestre con nuevo ingreso (DM-05)', function () {
    rareCasesSeed();

    $rows = rareCasesRows()->where('campus_id', $this->k1->id);
    $b = $rows->firstWhere('subject_id', $this->b->id);
    $a = $rows->firstWhere('subject_id', $this->a->id);

    expect($b)->history_terms_used->toBe(1)->method->toBe(ForecastMethod::Formula)
        ->and($rows->where('needs_review', true)->pluck('subject_id')->all())->toEqualCanonicalizing([$this->c->id, $this->d->id])
        ->and($a)->method->toBe(ForecastMethod::FirstSemester)->new_students->toBe(4);
});

it('es idempotente: correrlo dos veces no duplica la oferta ni las filas', function () {
    rareCasesSeed();
    $rows = rareCasesRows()->count();

    rareCasesSeed();

    expect(CareerCampus::query()->where('campus_id', $this->k2->id)->count())->toBe(1)
        ->and(rareCasesRows()->count())->toBe($rows);
});

it('no agrega datos si la proyección ya trae el caso por sí sola', function () {
    CareerCampus::factory()->create(['career_id' => $this->plan->career_id, 'campus_id' => $this->k2->id]);
    app(RefreshDemandForecasts::class)->handle($this->term, modalityOf('PRES'));
    $rows = rareCasesRows()->count();

    rareCasesSeed();

    expect(CareerCampus::query()->count())->toBe(2)
        ->and(rareCasesRows()->count())->toBe($rows);
});

it('no ofrece la carrera donde ya hay una oferta inactiva (respeta lo que ya existe)', function () {
    CareerCampus::factory()->inactive()->create(['career_id' => $this->plan->career_id, 'campus_id' => $this->k2->id]);

    rareCasesSeed();

    $offering = CareerCampus::query()->where('campus_id', $this->k2->id)->sole();

    expect($offering->is_active)->toBeFalse()
        ->and(rareCasesRows()->where('campus_id', $this->k2->id))->toBeEmpty();
});

it('las revisiones del punto «propuesta» fallan sin los casos y pasan después de sembrarlos', function () {
    app(RefreshDemandForecasts::class)->handle($this->term, modalityOf('PRES'));
    $before = rareCasesChecks();

    rareCasesSeed();
    $after = rareCasesChecks();

    expect($before)->toHaveCount(6)
        ->and(collect($before)->filter(fn (true|string $result): bool => $result !== true)->keys()->all())->toBe(['La proyección trae una carrera ofrecida por primera vez en un campus sin alumnos'])
        ->and(collect($after)->every(fn (true|string $result): bool => $result === true))->toBeTrue();
});

it('el paso de la demo lleva a «validación» y corre el seeder con los campus y el periodo del contexto', function () {
    $steps = collect(app()->getProvider(DemandServiceProvider::class)->demoSteps());
    /** @var DemoStep $step */
    $step = $steps->sole();

    ($step->run)(new DemoContext(DemoVolume::Demo, ['K1']));

    expect($step->reaches)->toBe(DemoCheckpoint::CatalogValidation)
        ->and(rareCasesRows()->where('campus_id', $this->k2->id))->not->toBeEmpty();
});
