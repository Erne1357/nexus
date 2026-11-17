<?php

use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Models\Term;
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
| fórmula (DemandForecaster) con los elegibles proyectados de hoy y las tasas de la ventana (DM-01 a DM-05; versión
| v2 de la fórmula, confirmada el 17 de noviembre de 2026).
|
| Fixture, objetivo 20271, carrera ofrecida en K1 y K2, plan vigente con A (1.º), B (2.º, pide A) y S (especialidad,
| 3.º):
| - Hoy en K1: 8 activos aprobaron A en 20253; 5 activos cursan A en 20263. Así B tiene 13 elegibles, 5 por seriación
|   en curso, todos en su semestre, y A tiene 5 cursándola.
| - Historia (egresados, que no cuentan hoy), K1, 20261: 4 aprobaron A en 20253; dos aprueban B, uno la reprueba y
|   uno se da de baja. B: no inscripción 1/4, reprobación 1/3. Nadie toma S: se proyecta con su historia, se marca y
|   no entra en el promedio del departamento. A no tiene elegibles: toma la tasa del departamento (la de B).
*/

beforeEach(function () {
    $this->term = Term::factory()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);
    $this->k1 = Campus::factory()->create(['code' => 'K1']);
    $this->k2 = Campus::factory()->create(['code' => 'K2']);
    $this->plan = StudyPlan::factory()->create(['code' => 'PL-1', 'is_current' => true]);
    CareerCampus::factory()->create(['career_id' => $this->plan->career_id, 'campus_id' => $this->k1->id]);
    CareerCampus::factory()->create(['career_id' => $this->plan->career_id, 'campus_id' => $this->k2->id]);
    $this->department = Department::factory()->create();
    $this->a = Subject::factory()->create(['code' => 'FA-1', 'department_id' => $this->department->id, 'needs_lab' => false]);
    $this->b = Subject::factory()->create(['code' => 'FB-2', 'department_id' => $this->department->id, 'needs_lab' => true]);
    $this->s = Subject::factory()->create(['code' => 'FS-3', 'department_id' => $this->department->id]);
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

it('DM-01, DM-04 y DM-05: en primer semestre suma el nuevo ingreso y los repetidores que vuelven, con la tasa del departamento', function () {
    forecastFixture();

    app(RefreshDemandForecasts::class)->handle($this->term, modalityOf('PRES'));
    $a = forecastRows()['FA-1@K1'];

    expect($a)
        ->eligible_students->toBe(0)
        ->current_students->toBe(5)
        ->failure_rate->toEqualWithDelta(1 / 3, 0.0001)
        ->non_enrollment_rate->toBe(0.25)
        ->expected_repeaters->toBe(1)
        ->new_students->toBe(0)
        ->expected_students->toBe(1)
        ->method->toBe(ForecastMethod::FirstSemester);
});

it('DM-05: el nuevo ingreso entra solo en el primer semestre del plan vigente; un plan anterior lleva solo a sus rezagados', function () {
    forecastFixture();
    $old = StudyPlan::factory()->create(['career_id' => $this->plan->career_id, 'code' => 'PL-0']);
    $oldFirst = Subject::factory()->create(['code' => 'FO-1']);
    placeInPlan($old, $oldFirst);

    foreach (range(1, 2) as $i) {
        forecastStudent($this->k1, [[$this->a, '20261', KardexResult::Aprobada]], StudentStatus::Egresado);
    }

    app(RefreshDemandForecasts::class)->handle($this->term, modalityOf('PRES'));
    $rows = forecastRows();

    expect($rows['FA-1@K1'])
        ->new_students->toBe(2)
        ->method->toBe(ForecastMethod::FirstSemester)
        ->and($rows['FO-1@K1'])
        ->new_students->toBeNull()
        ->expected_students->toBe(0)
        ->method->toBe(ForecastMethod::NoHistory);
});

it('DM-03: los elegibles de cada tramo de distancia usan la no inscripción de su tramo y se guarda la efectiva', function () {
    forecastFixture();
    $ok = KardexResult::Aprobada;
    forecastStudent($this->k1, [[$this->a, '20233', KardexResult::Reprobada], [$this->a, '20243', $ok], [$this->b, '20261', $ok]], StudentStatus::Egresado);
    forecastStudent($this->k1, [[$this->a, '20233', KardexResult::Reprobada], [$this->a, '20243', $ok], [$this->b, '20261', KardexResult::Baja]], StudentStatus::Egresado);

    foreach (range(1, 3) as $i) {
        forecastStudent($this->k1, [[$this->a, '20243', KardexResult::Reprobada], [$this->a, '20253', $ok]]);
    }

    app(RefreshDemandForecasts::class)->handle($this->term, modalityOf('PRES'));

    expect(forecastRows()['FB-2@K1'])
        ->eligible_students->toBe(16)
        ->expected_students->toBe(11)
        ->non_enrollment_rate->toBe(round(1 - 11.25 / 16, 4))
        ->method->toBe(ForecastMethod::Formula);
});

it('DM-04: una materia con elegibles en la ventana que nadie tomó en ningún campus se proyecta con su historia y se marca para revisar', function () {
    $untaken = Subject::factory()->create(['code' => 'FN-2', 'department_id' => $this->department->id]);
    placeInPlan($this->plan, $untaken, 2);
    forecastFixture();

    app(RefreshDemandForecasts::class)->handle($this->term, modalityOf('PRES'));

    expect(forecastRows()['FN-2@K1'])
        ->eligible_students->toBe(13)
        ->non_enrollment_rate->toBe(1.0)
        ->expected_students->toBe(0)
        ->method->toBe(ForecastMethod::Formula)
        ->needs_review->toBeTrue()
        ->and(forecastRows()['FB-2@K1']->needs_review)->toBeFalse();
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
