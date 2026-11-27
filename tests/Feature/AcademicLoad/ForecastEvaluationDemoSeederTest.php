<?php

use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Database\Seeders\ForecastEvaluationDemoSeeder;
use App\Modules\AcademicLoad\Enums\ForecastEvaluationStatus;
use App\Modules\AcademicLoad\Models\ForecastEvaluation;
use App\Modules\AcademicLoad\Queries\ForecastEvaluationsQuery;
use App\Modules\AcademicLoad\Queries\TermKardexQuery;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Students\Enums\KardexResult;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;

/*
| ForecastEvaluationDemoSeeder (tarea 5.10a): evalúa los periodos con kárdex que tienen un periodo anterior del mismo
| tipo con kárdex, y deja una fallida y una en cola antigua para ver todos los estados.
|
| Fixture: kárdex en 20251 y 20261 (Ene-Jun) y en 20253 (Ago-Dic, sin periodo anterior del mismo tipo). Solo 20261 se
| evalúa: sin campus y en el campus K1.
*/

beforeEach(function () {
    foreach (['20251', '20253', '20261'] as $code) {
        Term::factory()->create(['code' => $code]);
    }

    $this->k1 = Campus::factory()->create(['code' => 'K1']);
    $plan = StudyPlan::factory()->create();
    $a = Subject::factory()->create();
    $b = Subject::factory()->create();
    placeInPlan($plan, $a);
    placeInPlan($plan, $b, 2);
    seriate($plan, $b, $a);
    CareerCampus::factory()->create(['career_id' => $plan->career_id, 'campus_id' => $this->k1->id]);

    foreach (range(1, 4) as $i) {
        $student = Student::factory()->create(['study_plan_id' => $plan->id, 'campus_id' => $this->k1->id, 'modality_id' => modalityOf('PRES')->id]);
        KardexRecord::factory()->for($student)->for($a)->create(['term_code' => '20251']);
        KardexRecord::factory()->for($student)->for($a)->create(['term_code' => '20253']);
        KardexRecord::factory()->for($student)->for($b)->create(['term_code' => '20261', 'result' => KardexResult::Cursando, 'grade' => null]);
    }
});

it('descubre los periodos con historia del kárdex en vez de fijarlos', function () {
    expect(app(TermKardexQuery::class)->termCodes(modalityOf('PRES')))->toBe(['20261', '20253', '20251']);
});

it('un registro de kárdex sin periodo no cuenta como periodo (termCodes sin nulos) y evaluableTerms no truena', function () {
    $student = Student::query()->firstOrFail();
    KardexRecord::factory()->for($student)->for(Subject::query()->firstOrFail())->create(['term_code' => null]);

    expect(app(TermKardexQuery::class)->termCodes(modalityOf('PRES')))->toBe(['20261', '20253', '20251'])
        ->and(app(ForecastEvaluationsQuery::class)->evaluableTerms(modalityOf('PRES'))->pluck('code')->all())->toBe(['20261', '20253', '20251']);
});

it('evalúa el periodo con historia, por campus el más reciente, y deja una fallida y una en cola antigua', function () {
    $subdir = User::factory()->create(['username' => 'subdir']);

    (new ForecastEvaluationDemoSeeder)->run('PRES', ['K1'], '20271');

    $evaluations = ForecastEvaluation::query()->with('term')->get();
    $done = $evaluations->where('status', ForecastEvaluationStatus::Done);

    expect($done->map(fn (ForecastEvaluation $evaluation): string => $evaluation->term->code.':'.($evaluation->campus_id === $this->k1->id ? 'K1' : 'todos'))->sort()->values()->all())
        ->toBe(['20261:K1', '20261:todos'])
        ->and($done->every(fn (ForecastEvaluation $evaluation): bool => $evaluation->requested_by === $subdir->id))->toBeTrue()
        ->and($evaluations->where('status', ForecastEvaluationStatus::Failed))->toHaveCount(1)
        ->and($evaluations->firstWhere('status', ForecastEvaluationStatus::Failed)->error)->not->toBeEmpty()
        ->and($evaluations->where('status', ForecastEvaluationStatus::Queued))->toHaveCount(1)
        ->and($evaluations->firstWhere('status', ForecastEvaluationStatus::Queued)->updated_at->lt(now()->subHour()))->toBeTrue();
});

it('sin cuenta de Subdirección las evaluaciones quedan sin quien las pidió', function () {
    (new ForecastEvaluationDemoSeeder)->run('PRES', ['K1'], '20271');

    expect(ForecastEvaluation::query()->whereNotNull('requested_by')->count())->toBe(0);
});

it('resembrar no duplica evaluaciones ni avisos', function () {
    $subdir = User::factory()->create(['username' => 'subdir']);

    (new ForecastEvaluationDemoSeeder)->run('PRES', ['K1'], '20271');
    $evaluations = ForecastEvaluation::query()->count();
    $notifications = $subdir->notifications()->count();

    (new ForecastEvaluationDemoSeeder)->run('PRES', ['K1'], '20271');

    expect($evaluations)->toBe(4)
        ->and(ForecastEvaluation::query()->count())->toBe($evaluations)
        ->and($subdir->notifications()->count())->toBe($notifications);
});
