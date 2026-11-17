<?php

use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Actions\UpdateSettings;
use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Settings\SettingRegistry;
use App\Modules\Demand\Actions\RefreshDemandForecasts;
use App\Modules\Demand\Enums\ForecastMethod;
use App\Modules\Demand\Events\DemandForecastsComputed;
use App\Modules\Demand\Models\DemandForecast;
use App\Modules\Demand\Rules\DemandForecaster;
use App\Modules\Students\Enums\KardexResult;
use App\Modules\Students\Enums\StudentStatus;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;
use Illuminate\Support\Facades\Event;

/*
| La proyección de un periodo en una modalidad (tareas 5.3 y 5.4): por cada carrera ofrecida, cada columna de su
| propuesta (los campus de la oferta, o una sin campus en una modalidad sin salones) y cada materia de la propuesta, la
| fórmula (DemandForecaster) con los elegibles proyectados de hoy y las tasas de la ventana (DM-01 a DM-05).
|
| Fixture, objetivo 20271, carrera ofrecida en K1 y K2, plan con A (1.º), B (2.º, pide A) y S (especialidad, 3.º):
| - Hoy en K1: 8 activos aprobaron A en 20253; 5 activos cursan A en 20263. Así B tiene 13 elegibles, 5 por seriación
|   en curso, y A tiene 5 cursándola.
| - Historia (egresados, que no cuentan hoy), K1, 20261: 4 aprobaron A en 20253; dos aprueban B, uno la reprueba y
|   uno se da de baja. B: no inscripción 1/4, reprobación 1/3. A no tiene elegibles: toma la tasa del departamento.
*/

beforeEach(function () {
    $this->term = Term::factory()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);
    $this->k1 = Campus::factory()->create(['code' => 'K1']);
    $this->k2 = Campus::factory()->create(['code' => 'K2']);
    $this->plan = StudyPlan::factory()->create(['code' => 'PL-1']);
    CareerCampus::factory()->create(['career_id' => $this->plan->career_id, 'campus_id' => $this->k1->id]);
    CareerCampus::factory()->create(['career_id' => $this->plan->career_id, 'campus_id' => $this->k2->id]);
    $department = Department::factory()->create();
    $this->a = Subject::factory()->create(['code' => 'FA-1', 'department_id' => $department->id, 'needs_lab' => false]);
    $this->b = Subject::factory()->create(['code' => 'FB-2', 'department_id' => $department->id, 'needs_lab' => true]);
    $this->s = Subject::factory()->create(['code' => 'FS-3', 'department_id' => $department->id]);
    placeInPlan($this->plan, $this->a);
    placeInPlan($this->plan, $this->b, 2);
    placeInPlan($this->plan, $this->s, 3, Specialty::factory()->create(['study_plan_id' => $this->plan->id]));
    seriate($this->plan, $this->b, $this->a);
});

/**
 * @param  list<array{0: Subject, 1: string, 2: KardexResult}>  $records
 */
function forecastStudent(Campus $campus, array $records, StudentStatus $status = StudentStatus::Activo, ?Modality $modality = null): Student
{
    $student = Student::factory()->create([
        'study_plan_id' => test()->plan->id,
        'career_id' => test()->plan->career_id,
        'campus_id' => $campus->id,
        'modality_id' => ($modality ?? modalityOf('PRES'))->id,
        'status' => $status,
    ]);

    foreach ($records as [$subject, $term, $result]) {
        KardexRecord::factory()->for($student)->for($subject)->create(['term_code' => $term, 'result' => $result, 'grade' => null]);
    }

    return $student;
}

function forecastFixture(?Modality $modality = null): void
{
    $t = test();
    $ok = KardexResult::Aprobada;

    foreach (range(1, 8) as $i) {
        forecastStudent($t->k1, [[$t->a, '20253', $ok]], modality: $modality);
    }

    foreach (range(1, 5) as $i) {
        forecastStudent($t->k1, [[$t->a, '20263', KardexResult::Cursando]], modality: $modality);
    }

    foreach ([$ok, $ok, KardexResult::Reprobada, KardexResult::Baja] as $result) {
        forecastStudent($t->k1, [[$t->a, '20253', $ok], [$t->b, '20261', $result]], StudentStatus::Egresado, $modality);
    }
}

/**
 * @return array<string, DemandForecast> clave de materia y de campus («FB-2@K1») → fila
 */
function forecastRows(): array
{
    return DemandForecast::query()->with(['subject', 'campus'])->get()
        ->keyBy(fn (DemandForecast $forecast): string => $forecast->subject->code.'@'.($forecast->campus->code ?? '-'))
        ->all();
}

it('DM-01 y DM-03: proyecta cada materia de la propuesta en cada campus con los elegibles de hoy y las tasas de la ventana', function () {
    forecastFixture();

    $count = app(RefreshDemandForecasts::class)->handle($this->term, modalityOf('PRES'));
    $rows = forecastRows();

    expect($count)->toBe(6)
        ->and(array_keys($rows))->toEqualCanonicalizing(['FA-1@K1', 'FB-2@K1', 'FS-3@K1', 'FA-1@K2', 'FB-2@K2', 'FS-3@K2'])
        ->and($rows['FB-2@K1'])
        ->eligible_students->toBe(13)
        ->pending_prerequisite_students->toBe(5)
        ->current_students->toBe(0)
        ->non_enrollment_rate->toBe(0.25)
        ->history_terms_used->toBe(1)
        ->expected_students->toBe(10)
        ->suggested_groups->toBe(1)
        ->method->toBe(ForecastMethod::Formula)
        ->formula_version->toBe(DemandForecaster::VERSION)
        ->needs_review->toBeFalse();
});

it('DM-04 y DM-05: en primer semestre suma repetidores con la tasa del departamento y el nuevo ingreso', function () {
    forecastFixture();

    app(RefreshDemandForecasts::class)->handle($this->term, modalityOf('PRES'));
    $a = forecastRows()['FA-1@K1'];

    expect($a)
        ->eligible_students->toBe(0)
        ->current_students->toBe(5)
        ->failure_rate->toEqualWithDelta(1 / 3, 0.0001)
        ->expected_repeaters->toBe(2)
        ->new_students->toBe(0)
        ->expected_students->toBe(2)
        ->method->toBe(ForecastMethod::FirstSemester);
});

it('DM-04: sin historia en el campus espera a todos los elegibles; la especialidad se marca para revisar', function () {
    forecastFixture();

    app(RefreshDemandForecasts::class)->handle($this->term, modalityOf('PRES'));
    $rows = forecastRows();

    expect($rows['FB-2@K2'])
        ->eligible_students->toBe(0)
        ->non_enrollment_rate->toBeNull()
        ->expected_students->toBe(0)
        ->suggested_groups->toBe(0)
        ->method->toBe(ForecastMethod::NoHistory)
        ->and($rows['FS-3@K1']->needs_review)->toBeTrue()
        ->and($rows['FA-1@K2']->new_students)->toBe(0);
});

it('usa los tamaños objetivo y la ventana de los parámetros', function () {
    forecastFixture();
    Setting::query()->create(['scope' => SettingScope::Global, 'key' => 'demand.target_group_size', 'value' => ['default' => 35, 'lab' => 5]]);

    app(RefreshDemandForecasts::class)->handle($this->term, modalityOf('PRES'));

    expect(forecastRows()['FB-2@K1']->suggested_groups)->toBe(2);
});

it('recalcular reemplaza las filas del periodo y la modalidad sin duplicarlas ni tocar otro periodo', function () {
    forecastFixture();
    $other = DemandForecast::factory()->create(['subject_id' => $this->a->id, 'campus_id' => $this->k1->id, 'career_id' => $this->plan->career_id]);

    app(RefreshDemandForecasts::class)->handle($this->term, modalityOf('PRES'));
    app(RefreshDemandForecasts::class)->handle($this->term, modalityOf('PRES'));

    expect(DemandForecast::query()->where('term_id', $this->term->id)->count())->toBe(6)
        ->and($other->fresh())->not->toBeNull();
});

it('avisa después del commit qué periodo, modalidad y carreras recalculó', function () {
    Event::fake([DemandForecastsComputed::class]);
    forecastFixture();

    app(RefreshDemandForecasts::class)->handle($this->term, modalityOf('PRES'));

    Event::assertDispatched(DemandForecastsComputed::class, fn (DemandForecastsComputed $event): bool => $event->termId === $this->term->id
        && $event->modalityId === modalityOf('PRES')->id
        && $event->campusId === null
        && $event->careerIds === [$this->plan->career_id]);
});

it('en una modalidad sin salones proyecta una sola columna sin campus con los alumnos de todos los campus', function () {
    $online = Modality::factory()->create(['code' => 'M901', 'settings' => ['uses_rooms' => false]]);
    forecastFixture($online);

    app(RefreshDemandForecasts::class)->handle($this->term, $online);
    $rows = forecastRows();

    expect(array_keys($rows))->toEqualCanonicalizing(['FA-1@-', 'FB-2@-', 'FS-3@-'])
        ->and($rows['FB-2@-']->eligible_students)->toBe(13)
        ->and($rows['FB-2@-']->non_enrollment_rate)->toBe(0.25);
});

it('como si se corriera durante el periodo anterior, ve solo a quienes estaban inscritos y no guarda nada', function () {
    forecastFixture();

    $rows = collect(app(RefreshDemandForecasts::class)->compute($this->term, modalityOf('PRES'), asIf: true))
        ->keyBy(fn (array $row): string => $row['subject_id'].'@'.$row['campus_id']);

    expect(DemandForecast::query()->count())->toBe(0)
        ->and($rows[$this->b->id.'@'.$this->k1->id]['eligible_students'])->toBe(5)
        ->and($rows[$this->b->id.'@'.$this->k1->id]['pending_prerequisite_students'])->toBe(5)
        ->and($rows[$this->a->id.'@'.$this->k1->id]['current_students'])->toBe(5);
});

it('cambiar el tamaño de grupo cambia los grupos sugeridos en la siguiente corrida', function () {
    forecastFixture();
    $sizes = fn (int $default, int $lab) => app(UpdateSettings::class)->handle(
        collect(app(SettingRegistry::class)->byModule()['demand'])->filter(fn ($d): bool => $d->key === 'demand.target_group_size')->values()->all(),
        ['demand.target_group_size' => ['default' => $default, 'lab' => $lab]],
    );

    // FB-2 pide laboratorio y espera 10 alumnos en K1: con 25 por grupo es 1 y con 4 son 3.
    $sizes(35, 25);
    app(RefreshDemandForecasts::class)->handle($this->term, modalityOf('PRES'));
    $before = forecastRows()['FB-2@K1']->suggested_groups;

    $sizes(35, 4);
    app(RefreshDemandForecasts::class)->handle($this->term, modalityOf('PRES'));

    expect($before)->toBe(1)
        ->and(forecastRows()['FB-2@K1']->suggested_groups)->toBe(3);
});
