<?php

use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Campus;
use App\Modules\Students\Actions\RefreshStudentProgress;
use App\Modules\Students\Enums\StudentStatus;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Queries\EligibleStudentsQuery;

/*
| EligibleStudentsQuery cuenta, por materia, los alumnos elegibles de una carrera en un campus (tarea 1.5): los
| que aprobaron toda su seriación y no la han aprobado, según `student_progress`. Solo cuentan los alumnos con
| estatus activo, y solo con el avance calculado con su plan actual (reglas-de-negocio.md, "Elegibilidad y
| demanda"). El avance se arma desde el kardex con RefreshStudentProgress, como en la importación.
*/

beforeEach(function () {
    // Plan con Cálculo (A) y Física (F) de primero, y Cálculo II (B) de segundo, que pide A.
    $this->plan = StudyPlan::factory()->create();
    $this->campus = Campus::factory()->create();
    $this->a = Subject::factory()->create(['code' => 'A-1']);
    $this->f = Subject::factory()->create(['code' => 'F-1']);
    $this->b = Subject::factory()->create(['code' => 'B-2']);
    placeInPlan($this->plan, $this->a);
    placeInPlan($this->plan, $this->f);
    placeInPlan($this->plan, $this->b, 2);
    seriate($this->plan, $this->b, $this->a);
});

/**
 * Un alumno del plan en el campus de la prueba.
 *
 * @param  array<string, mixed>  $attributes
 */
function eligibilityStudent(StudyPlan $plan, Campus $campus, array $attributes = []): Student
{
    return Student::factory()->for($plan)->create(['campus_id' => $campus->id, ...$attributes]);
}

/**
 * Los elegibles de la carrera del plan en el campus, en orden de llave para compararlos.
 *
 * @return array<string, int>
 */
function eligibleCounts(StudyPlan $plan, Campus $campus): array
{
    return sortedCounts(app(EligibleStudentsQuery::class)->countsFor($plan->career, $campus));
}

/**
 * @param  array<string, int>  $counts
 * @return array<string, int>
 */
function sortedCounts(array $counts): array
{
    ksort($counts);

    return $counts;
}

it('sin seriación cuenta a quien no ha aprobado la materia; con seriación, solo a quien aprobó el requisito', function () {
    eligibilityStudent($this->plan, $this->campus);
    $passed = eligibilityStudent($this->plan, $this->campus);
    KardexRecord::factory()->for($passed)->for($this->a)->create();
    $taking = eligibilityStudent($this->plan, $this->campus);
    KardexRecord::factory()->for($taking)->for($this->a)->inProgress()->create(['term_code' => '20271']);

    app(RefreshStudentProgress::class)->handle();

    expect(eligibleCounts($this->plan, $this->campus))->toBe(sortedCounts([
        $this->a->id => 2,
        $this->f->id => 3,
        $this->b->id => 1,
    ]));
});

it('un repetidor sigue elegible para la materia que reprobó, y deja de serlo al aprobarla', function () {
    $failedB = eligibilityStudent($this->plan, $this->campus);
    KardexRecord::factory()->for($failedB)->for($this->a)->create(['term_code' => '20251']);
    KardexRecord::factory()->for($failedB)->for($this->b)->failed()->create();
    $failedA = eligibilityStudent($this->plan, $this->campus);
    KardexRecord::factory()->for($failedA)->for($this->a)->failed()->create();
    $passedOnRetry = eligibilityStudent($this->plan, $this->campus);
    KardexRecord::factory()->for($passedOnRetry)->for($this->a)->failed()->create(['term_code' => '20251']);
    KardexRecord::factory()->for($passedOnRetry)->for($this->a)->create(['attempt' => 2]);

    app(RefreshStudentProgress::class)->handle();

    expect(eligibleCounts($this->plan, $this->campus))->toBe(sortedCounts([
        $this->a->id => 1,
        $this->f->id => 3,
        $this->b->id => 2,
    ]));
});

it('no cuenta a un alumno para una materia más allá de su semestre y dos más', function () {
    $g = Subject::factory()->create(['code' => 'G-4']);
    placeInPlan($this->plan, $g, 4);
    eligibilityStudent($this->plan, $this->campus, ['current_semester' => 1]);
    eligibilityStudent($this->plan, $this->campus, ['current_semester' => 2]);

    app(RefreshStudentProgress::class)->handle();

    expect(eligibleCounts($this->plan, $this->campus))->toBe(sortedCounts([$this->a->id => 2, $this->f->id => 2, $g->id => 1]));
});

it('no cuenta a un alumno que no está activo: :dataset', function (array $attributes) {
    eligibilityStudent($this->plan, $this->campus);
    eligibilityStudent($this->plan, $this->campus, $attributes);

    app(RefreshStudentProgress::class)->handle();

    expect(eligibleCounts($this->plan, $this->campus))->toBe(sortedCounts([$this->a->id => 1, $this->f->id => 1]));
})->with([
    'baja temporal' => [['status' => StudentStatus::BajaTemporal]],
    'baja definitiva' => [['status' => StudentStatus::BajaDefinitiva]],
    'egresado' => [['status' => StudentStatus::Egresado, 'current_semester' => null]],
    'sin estatus' => [['status' => null]],
    'desactivado' => [['is_active' => false]],
]);

it('cuenta solo a los alumnos de esa carrera y ese campus', function () {
    $other = StudyPlan::factory()->create();
    placeInPlan($other, $this->a);
    eligibilityStudent($this->plan, $this->campus);
    eligibilityStudent($this->plan, Campus::factory()->create());
    eligibilityStudent($other, $this->campus);

    app(RefreshStudentProgress::class)->handle();

    expect(eligibleCounts($this->plan, $this->campus))->toBe(sortedCounts([$this->a->id => 1, $this->f->id => 1]))
        ->and(eligibleCounts($other, $this->campus))->toBe([$this->a->id => 1]);
});

it('suma los alumnos de los distintos planes de la carrera', function () {
    $older = StudyPlan::factory()->for($this->plan->career)->create();
    placeInPlan($older, $this->a);
    eligibilityStudent($this->plan, $this->campus);
    eligibilityStudent($older, $this->campus);

    app(RefreshStudentProgress::class)->handle();

    expect(eligibleCounts($this->plan, $this->campus))->toBe(sortedCounts([$this->a->id => 2, $this->f->id => 1]));
});

it('no cuenta a un alumno borrado ni a uno cuyo avance se calculó con otro plan', function () {
    $other = StudyPlan::factory()->for($this->plan->career)->create();
    placeInPlan($other, $this->f);
    eligibilityStudent($this->plan, $this->campus);
    $deleted = eligibilityStudent($this->plan, $this->campus);
    $moved = eligibilityStudent($this->plan, $this->campus);

    app(RefreshStudentProgress::class)->handle();
    $deleted->delete();
    $moved->update(['study_plan_id' => $other->id]);

    expect(eligibleCounts($this->plan, $this->campus))->toBe(sortedCounts([$this->a->id => 1, $this->f->id => 1]));
});

it('devuelve un mapa vacío si nadie de la carrera puede cursar nada', function () {
    $graduate = eligibilityStudent($this->plan, $this->campus);
    foreach ([$this->a, $this->f, $this->b] as $subject) {
        KardexRecord::factory()->for($graduate)->for($subject)->create();
    }
    eligibilityStudent($this->plan, $this->campus, ['status' => StudentStatus::Egresado, 'current_semester' => null]);

    app(RefreshStudentProgress::class)->handle();

    expect(eligibleCounts($this->plan, $this->campus))->toBe([]);
});
