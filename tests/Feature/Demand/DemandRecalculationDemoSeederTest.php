<?php

use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Demand\Actions\RefreshDemandForecasts;
use App\Modules\Demand\Database\Seeders\DemandRecalculationDemoSeeder;
use App\Modules\Demand\Models\DemandForecast;
use App\Modules\Students\Enums\KardexResult;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;

/*
| Recálculo de la demo con calificaciones tardías (tarea 5.10, pedido para la 5.5 y la 5.6): en las materias con más
| alumnos cursándolas en el periodo ordinario anterior al de la demo, lo cursando pasa a aprobada o reprobada, se
| recalcula el avance y, con él, la proyección. Así la propuesta precargada tiene filas donde «la proyección cambió».
|
| Fixture: objetivo 20271 abierto en Presencial; plan con A (1.º) y B (2.º, pide A); 6 alumnos cursan A en 20263 y 2
| cursan C en 20263 (y son elegibles para A); 3 aprobaron A antes.
*/

beforeEach(function () {
    $this->term = Term::factory()->current()->create(['code' => '20271']);
    TermOpening::factory()->create(['term_id' => $this->term->id]);
    $this->campus = Campus::factory()->create();
    $this->plan = StudyPlan::factory()->create();
    CareerCampus::factory()->create(['career_id' => $this->plan->career_id, 'campus_id' => $this->campus->id]);
    $this->a = Subject::factory()->create(['code' => 'LA-1']);
    $this->b = Subject::factory()->create(['code' => 'LB-2']);
    $this->c = Subject::factory()->create(['code' => 'LC-1']);
    placeInPlan($this->plan, $this->a);
    placeInPlan($this->plan, $this->b, 2);
    placeInPlan($this->plan, $this->c);
    seriate($this->plan, $this->b, $this->a);

    $student = fn (Subject $subject, string $term, KardexResult $result) => KardexRecord::factory()
        ->for(Student::factory()->create(['study_plan_id' => $this->plan->id, 'career_id' => $this->plan->career_id, 'campus_id' => $this->campus->id]))
        ->for($subject)
        ->create(['term_code' => $term, 'result' => $result, 'grade' => null]);

    foreach (range(1, 6) as $i) {
        $student($this->a, '20263', KardexResult::Cursando);
    }

    foreach (range(1, 2) as $i) {
        $student($this->c, '20263', KardexResult::Cursando);
    }

    foreach (range(1, 3) as $i) {
        $student($this->a, '20253', KardexResult::Aprobada);
    }

    app(RefreshDemandForecasts::class)->handle($this->term, modalityOf('PRES'));
});

function lateGradesRow(Subject $subject): DemandForecast
{
    return DemandForecast::query()->where('subject_id', $subject->id)->sole();
}

it('califica solo las materias con más alumnos cursándolas y recalcula la proyección', function () {
    expect(lateGradesRow($this->b)->eligible_students)->toBe(9)
        ->and(lateGradesRow($this->b)->pending_prerequisite_students)->toBe(6)
        ->and(lateGradesRow($this->a)->eligible_students)->toBe(2);

    (new DemandRecalculationDemoSeeder)->run(term: '20271', subjects: 1, failureShare: 1.0);

    expect(KardexRecord::query()->where('subject_id', $this->a->id)->where('term_code', '20263')->pluck('result')->unique()->all())->toEqual([KardexResult::Reprobada])
        ->and(KardexRecord::query()->where('subject_id', $this->c->id)->where('result', KardexResult::Cursando)->count())->toBe(2)
        ->and(lateGradesRow($this->a)->current_students)->toBe(0)
        ->and(lateGradesRow($this->a)->eligible_students)->toBe(8)
        ->and(lateGradesRow($this->b)->eligible_students)->toBe(3)
        ->and(lateGradesRow($this->b)->pending_prerequisite_students)->toBe(0);
});

it('reparte aprobadas y reprobadas de forma repetible con la semilla', function () {
    (new DemandRecalculationDemoSeeder)->run(term: '20271', subjects: 2, failureShare: 0.5, seed: 7);
    $first = KardexRecord::query()->where('term_code', '20263')->orderBy('id')->pluck('result')->all();

    $counts = array_count_values(array_map(fn (KardexResult $result): string => $result->value, $first));
    ksort($counts);

    expect($counts)->toBe(['aprobada' => 4, 'reprobada' => 4])
        ->and(KardexRecord::query()->where('result', KardexResult::Cursando)->count())->toBe(0);
});
