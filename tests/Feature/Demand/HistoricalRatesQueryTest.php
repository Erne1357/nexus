<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Campus;
use App\Modules\Demand\Enums\GapBucket;
use App\Modules\Demand\Queries\HistoricalRatesQuery;
use App\Modules\Demand\Rules\HistoricalRates;
use App\Modules\Students\Enums\KardexResult;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;

/*
| Tasas históricas por materia y campus (tarea 5.2; reglas-de-negocio.md, «Elegibilidad y demanda», DM-03 a DM-06):
| sobre los últimos `demand.history_terms` periodos del mismo tipo que el objetivo, la de no inscripción es la parte
| de los elegibles al inicio de cada periodo que no la tomaron, y la de reprobación la de quienes la cursaron con
| calificación y la reprobaron. Elegibles reconstruidos del kárdex (StandingCalculator); población = los alumnos con
| kárdex en ese periodo. La baja no es tomarla. Sin historia, la tasa promedio del departamento. La no inscripción
| también se mide por tramo de distancia entre el semestre de la materia y el del alumno (DM-03, confirmado el 17 de
| noviembre de 2026), y una materia con elegibles que nadie tomó en ningún campus se marca para revisar (DM-04).
|
| Kárdex sintético para el objetivo 20271 con 4 periodos (20261, 20251, 20241, 20231):
| - K1, 20261: s1–s4 aprobaron A en 20253; s1 y s2 aprueban B, s3 la reprueba y s4 solo tiene una baja en B. n1 y n2
|   entran (nada antes) y aprueban A. Todos van en su semestre: B (2.º) y A (1.º) son del tramo «la suya».
| - K1, 20251: s5 y s6 aprobaron A en 20243; s5 aprueba B y s6 solo tiene una baja en X.
| - K2, 20261: s10 aprobó A en 20253 y aprueba B.
| - Fuera: n1 reprueba B en 20263 (otro tipo), s9 reprueba B en 20221 (fuera de la ventana) y un alumno de EAD.
*/

beforeEach(function () {
    $this->k1 = Campus::factory()->create(['code' => 'K1']);
    $this->k2 = Campus::factory()->create(['code' => 'K2']);
    $this->plan = StudyPlan::factory()->create();
    $this->d1 = Department::factory()->create();
    $this->d2 = Department::factory()->create();
    $this->a = Subject::factory()->create(['code' => 'RA-1', 'department_id' => $this->d1->id]);
    $this->b = Subject::factory()->create(['code' => 'RB-2', 'department_id' => $this->d1->id]);
    $this->c = Subject::factory()->create(['code' => 'RC-9', 'department_id' => $this->d1->id]);
    $this->x = Subject::factory()->create(['code' => 'RX-9', 'department_id' => $this->d2->id]);
    $this->lonely = Subject::factory()->create(['code' => 'RL-9']);
    placeInPlan($this->plan, $this->a);
    placeInPlan($this->plan, $this->b, 2);
    placeInPlan($this->plan, $this->c, 9);
    placeInPlan($this->plan, $this->x, 9);
    seriate($this->plan, $this->b, $this->a);
});

/**
 * @param  list<array{0: Subject, 1: ?string, 2?: KardexResult}>  $records
 */
function ratesStudent(Campus $campus, array $records, string $modality = 'PRES'): Student
{
    $student = Student::factory()->create([
        'study_plan_id' => test()->plan->id,
        'career_id' => test()->plan->career_id,
        'campus_id' => $campus->id,
        'modality_id' => modalityOf($modality)->id,
    ]);

    foreach ($records as [$subject, $term, $result]) {
        KardexRecord::factory()->for($student)->for($subject)->create([
            'term_code' => $term,
            'result' => $result,
            'grade' => $result === KardexResult::Aprobada ? 85 : ($result === KardexResult::Reprobada ? 50 : null),
        ]);
    }

    return $student;
}

function ratesFixture(): HistoricalRates
{
    $t = test();
    $ok = KardexResult::Aprobada;
    $failed = KardexResult::Reprobada;
    $dropped = KardexResult::Baja;

    ratesStudent($t->k1, [[$t->a, '20253', $ok], [$t->b, '20261', $ok]]);
    ratesStudent($t->k1, [[$t->a, '20253', $ok], [$t->b, '20261', $ok]]);
    ratesStudent($t->k1, [[$t->a, '20253', $ok], [$t->b, '20261', $failed]]);
    ratesStudent($t->k1, [[$t->a, '20253', $ok], [$t->b, '20261', $dropped]]);
    ratesStudent($t->k1, [[$t->a, '20261', $ok], [$t->b, '20263', $failed]]);
    ratesStudent($t->k1, [[$t->a, '20261', $ok]]);
    ratesStudent($t->k1, [[$t->a, '20243', $ok], [$t->b, '20251', $ok]]);
    ratesStudent($t->k1, [[$t->a, '20243', $ok], [$t->x, '20251', $dropped]]);
    ratesStudent($t->k1, [[$t->a, '20213', $ok], [$t->b, '20221', $failed]]);
    ratesStudent($t->k2, [[$t->a, '20253', $ok], [$t->b, '20261', $ok]]);
    ratesStudent($t->k1, [[$t->a, '20253', $ok], [$t->b, '20261', $failed]], modality: 'EAD');

    return app(HistoricalRatesQuery::class)->forTerm('20271', modalityOf('PRES'), historyTerms: 4);
}

it('DM-03: la no inscripción y la reprobación de una materia salen de los periodos del mismo tipo de la ventana', function () {
    $rates = ratesFixture()->rateFor($this->b->id, $this->k1->id, $this->d1->id);

    expect($rates->nonEnrollment)->toEqualWithDelta(2 / 6, 0.0001)
        ->and($rates->failure)->toEqualWithDelta(1 / 4, 0.0001)
        ->and($rates->termsUsed)->toBe(2)
        ->and($rates->fromDepartment)->toBeFalse();
});

it('DM-03: una materia con un solo periodo de historia usa ese periodo', function () {
    $rates = ratesFixture()->rateFor($this->b->id, $this->k2->id, $this->d1->id);

    expect($rates->nonEnrollment)->toBe(0.0)
        ->and($rates->failure)->toBe(0.0)
        ->and($rates->termsUsed)->toBe(1);
});

it('DM-03: sin campus junta todos los campus de la modalidad', function () {
    $rates = ratesFixture()->rateFor($this->b->id, null, $this->d1->id);

    expect($rates->nonEnrollment)->toEqualWithDelta(2 / 7, 0.0001)
        ->and($rates->failure)->toEqualWithDelta(1 / 5, 0.0001);
});

it('DM-04: una materia sin historia usa la tasa promedio de su departamento y lo marca', function () {
    $rates = ratesFixture()->rateFor($this->c->id, $this->k1->id, $this->d1->id);

    expect($rates->fromDepartment)->toBeTrue()
        ->and($rates->nonEnrollment)->toEqualWithDelta(2 / 8, 0.0001)
        ->and($rates->nonEnrollmentByGap)->toBe([GapBucket::Same->value => 0.25])
        ->and($rates->failure)->toEqualWithDelta(1 / 6, 0.0001)
        ->and($rates->termsUsed)->toBe(2);
});

it('DM-03: mide la no inscripción de cada tramo de distancia entre el semestre de la materia y el del alumno', function () {
    $ok = KardexResult::Aprobada;
    ratesStudent($this->k1, [[$this->a, '20233', $ok], [$this->x, '20243', KardexResult::Baja], [$this->b, '20261', $ok]]);
    ratesStudent($this->k1, [[$this->a, '20233', $ok], [$this->x, '20243', KardexResult::Baja], [$this->x, '20261', KardexResult::Baja]]);

    $rates = ratesFixture()->rateFor($this->b->id, $this->k1->id, $this->d1->id);

    expect($rates->nonEnrollment)->toEqualWithDelta(3 / 8, 0.0001)
        ->and($rates->nonEnrollmentByGap)->toHaveCount(2)
        ->and($rates->nonEnrollmentByGap[GapBucket::Same->value])->toEqualWithDelta(2 / 6, 0.0001)
        ->and($rates->nonEnrollmentByGap[GapBucket::Behind->value])->toEqualWithDelta(1 / 2, 0.0001)
        ->and($rates->nonEnrollmentFor(GapBucket::OneAhead))->toEqualWithDelta(3 / 8, 0.0001);
});

it('DM-04: una materia con elegibles que nadie tomó en ningún campus conserva su historia, se marca y no cambia la del departamento', function () {
    $untaken = Subject::factory()->create(['code' => 'RN-2', 'department_id' => $this->d1->id]);
    placeInPlan($this->plan, $untaken, 2);

    $history = ratesFixture();
    $rates = $history->rateFor($untaken->id, $this->k1->id, $this->d1->id);

    expect($history->neverTaken($untaken->id))->toBeTrue()
        ->and($rates->fromDepartment)->toBeFalse()
        ->and($rates->nonEnrollment)->toBe(1.0)
        ->and($history->rateFor($this->c->id, $this->k1->id, $this->d1->id)->nonEnrollment)->toEqualWithDelta(2 / 8, 0.0001)
        ->and($history->neverTaken($this->b->id))->toBeFalse()
        ->and($history->neverTaken($this->c->id))->toBeFalse();
});

it('DM-04: la materia que alguien tomó en otro campus conserva su tasa propia en el campus donde nadie la tomó', function () {
    $elsewhere = Subject::factory()->create(['code' => 'RQ-2', 'department_id' => $this->d1->id]);
    placeInPlan($this->plan, $elsewhere, 2);
    ratesStudent($this->k2, [[$this->a, '20253', KardexResult::Aprobada], [$elsewhere, '20261', KardexResult::Aprobada]]);

    $history = ratesFixture();
    $rates = $history->rateFor($elsewhere->id, $this->k1->id, $this->d1->id);

    expect($history->neverTaken($elsewhere->id))->toBeFalse()
        ->and($rates->fromDepartment)->toBeFalse()
        ->and($rates->nonEnrollment)->toBe(1.0);
});

it('DM-04: sin historia propia ni de su departamento no hay tasa', function () {
    expect(ratesFixture()->rateFor($this->lonely->id, $this->k1->id, $this->lonely->department_id))->toBeNull();
});

it('DM-05: el nuevo ingreso es el promedio de los alumnos que entraron en los periodos de la ventana con datos', function () {
    $rates = ratesFixture();

    expect($rates->newStudentsFor($this->plan->career_id, $this->k1->id))->toBe(1)
        ->and($rates->newStudentsFor($this->plan->career_id, $this->k2->id))->toBe(0)
        ->and($rates->newStudentsFor($this->plan->career_id, null))->toBe(1)
        ->and($rates->newStudentsFor($this->plan->career_id, Campus::factory()->create()->id))->toBeNull();
});

it('no cuenta filas ni alumnos borrados', function () {
    ratesStudent($this->k2, [[$this->a, '20253', KardexResult::Aprobada], [$this->b, '20261', KardexResult::Reprobada]])->delete();
    $student = ratesStudent($this->k2, [[$this->a, '20253', KardexResult::Aprobada]]);
    KardexRecord::factory()->for($student)->for($this->b)->failed()->create(['term_code' => '20261'])->delete();

    $rates = ratesFixture()->rateFor($this->b->id, $this->k2->id, $this->d1->id);

    expect($rates->failure)->toBe(0.0)
        ->and($rates->nonEnrollment)->toBe(0.0);
});

it('una ventana sin datos no da tasas ni nuevo ingreso', function () {
    $rates = app(HistoricalRatesQuery::class)->forTerm('20271', modalityOf('PRES'), historyTerms: 4);

    expect($rates->rateFor($this->b->id, $this->k1->id, $this->d1->id))->toBeNull()
        ->and($rates->newStudentsFor($this->plan->career_id, $this->k1->id))->toBeNull();
});
