<?php

use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Actions\EvaluateForecast;
use App\Modules\AcademicLoad\Actions\RequestForecastEvaluation;
use App\Modules\AcademicLoad\Enums\ForecastEvaluationStatus;
use App\Modules\AcademicLoad\Jobs\RunForecastEvaluation;
use App\Modules\AcademicLoad\Models\ForecastEvaluation;
use App\Modules\AcademicLoad\Notifications\ForecastEvaluationFinished;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Demand\Rules\DemandForecaster;
use App\Modules\Students\Enums\KardexResult;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

/*
| Pedir una evaluación de la proyección y correrla en la cola (tarea 5.9b; DM-25 y DM-29): la evaluación nace en cola,
| el job la pasa a corriendo y la deja lista o fallida, y avisa en la campana a quien la pidió. Un periodo, modalidad y
| campus tiene a lo más una evaluación en curso; evaluarlo otra vez después crea otra y conserva la anterior.
*/

beforeEach(function () {
    $this->term = Term::factory()->create(['code' => '20261', 'name' => 'Ene-Jun 2026']);
    $this->campus = Campus::factory()->create(['code' => 'K1']);
    $plan = StudyPlan::factory()->create();
    $subject = Subject::factory()->create();
    placeInPlan($plan, $subject);
    CareerCampus::factory()->create(['career_id' => $plan->career_id, 'campus_id' => $this->campus->id]);
    $student = Student::factory()->create(['study_plan_id' => $plan->id, 'campus_id' => $this->campus->id]);
    KardexRecord::factory()->for($student)->for($subject)->create(['term_code' => '20261', 'result' => KardexResult::Cursando, 'grade' => null]);
    $this->requester = User::factory()->create();
});

function requestRunEvaluation(?Campus $campus = null, ?User $by = null): ForecastEvaluation
{
    return app(RequestForecastEvaluation::class)->handle(test()->term, modalityOf('PRES'), $campus, $by ?? test()->requester);
}

it('nace en cola con la ventana y la versión de la fórmula, deja bitácora y encola el job', function () {
    Queue::fake();

    $evaluation = requestRunEvaluation();

    expect($evaluation)
        ->status->toBe(ForecastEvaluationStatus::Queued)
        ->method->toBe(DemandForecaster::VERSION)
        ->history_terms->toBe(4)
        ->requested_by->toBe($this->requester->id)
        ->progress->toBe(0)
        ->and(Activity::query()->where('subject_id', $evaluation->id)->sole())
        ->event->toBe('requested')
        ->causer_id->toBe($this->requester->id);

    Queue::assertPushed(RunForecastEvaluation::class, fn (RunForecastEvaluation $job): bool => $job->evaluationId === $evaluation->id);
});

it('DM-29: el job no se duplica para el mismo periodo, modalidad y campus', function () {
    Queue::fake();

    $first = requestRunEvaluation();
    $again = requestRunEvaluation(by: User::factory()->create());
    $otherCampus = requestRunEvaluation($this->campus);
    $same = ForecastEvaluation::factory()->queued()->create(['term_id' => $this->term->id, 'campus_id' => null]);

    expect($again->is($first))->toBeTrue()
        ->and($otherCampus->is($first))->toBeFalse()
        ->and(ForecastEvaluation::query()->count())->toBe(3)
        ->and((new RunForecastEvaluation($same->id))->uniqueId())->toBe((new RunForecastEvaluation($first->id))->uniqueId())
        ->and((new RunForecastEvaluation($otherCampus->id))->uniqueId())->not->toBe((new RunForecastEvaluation($first->id))->uniqueId());

    RunForecastEvaluation::dispatch($same->id);

    Queue::assertPushedTimes(RunForecastEvaluation::class, 2);
});

it('DM-29: evaluar otra vez el mismo periodo crea otra evaluación y conserva la anterior', function () {
    $first = requestRunEvaluation();
    $second = requestRunEvaluation();

    expect($first->fresh()->status)->toBe(ForecastEvaluationStatus::Done)
        ->and($second->is($first))->toBeFalse()
        ->and($second->fresh()->status)->toBe(ForecastEvaluationStatus::Done)
        ->and($first->rows()->count())->toBe(1)
        ->and($second->rows()->count())->toBe(1);
});

it('una evaluación que se quedó en cola más de una hora no impide pedir otra', function () {
    Queue::fake();
    $stuck = ForecastEvaluation::factory()->queued()->create(['term_id' => $this->term->id, 'updated_at' => now()->subMinutes(61)]);

    $evaluation = requestRunEvaluation();

    expect($evaluation->is($stuck))->toBeFalse()
        ->and($stuck->fresh())
        ->status->toBe(ForecastEvaluationStatus::Failed)
        ->error->toBe('No terminó en una hora: se pidió otra evaluación.');
});

it('termina lista y avisa a quien la pidió', function () {
    Notification::fake();

    $evaluation = requestRunEvaluation()->fresh();

    expect($evaluation)
        ->status->toBe(ForecastEvaluationStatus::Done)
        ->progress->toBe(100)
        ->stage->toBeNull()
        ->started_at->not->toBeNull()
        ->finished_at->not->toBeNull();

    Notification::assertSentTo($this->requester, ForecastEvaluationFinished::class, function (ForecastEvaluationFinished $notification) use ($evaluation): bool {
        $message = $notification->message($this->requester);

        return $message->title === 'La evaluación de Ene-Jun 2026 está lista'
            && $message->route === 'academic-load.forecast-accuracy'
            && $message->parameters === ['evaluacion' => $evaluation->id]
            && $message->tone === 'success';
    });
});

it('una evaluación fallida queda en failed con su error y avisa a quien la pidió', function () {
    Notification::fake();
    $broken = 'Perfil de modalidad inválido: block_minutes debe ser un entero positivo.';
    modalityOf('PRES')->update(['settings' => ['block_minutes' => 0]]);

    expect(fn () => requestRunEvaluation())->toThrow(InvalidArgumentException::class, $broken);

    $evaluation = ForecastEvaluation::query()->sole();

    expect($evaluation)
        ->status->toBe(ForecastEvaluationStatus::Failed)
        ->error->toBe($broken)
        ->stage->toBe(EvaluateForecast::STAGE_COMPUTING)
        ->finished_at->not->toBeNull();

    Notification::assertSentTo($this->requester, ForecastEvaluationFinished::class, function (ForecastEvaluationFinished $notification) use ($broken): bool {
        $message = $notification->message($this->requester);

        return $message->title === 'La evaluación de Ene-Jun 2026 no terminó'
            && $message->body === $broken
            && $message->tone === 'warning';
    });
});

it('el job no corre una evaluación que ya no está en cola', function () {
    $done = ForecastEvaluation::factory()->create(['term_id' => $this->term->id]);

    app()->call([new RunForecastEvaluation($done->id), 'handle']);
    app()->call([new RunForecastEvaluation(fakeKey(1)), 'handle']);

    expect($done->fresh()->status)->toBe(ForecastEvaluationStatus::Done)
        ->and($done->rows()->count())->toBe(0);
});

it('el avance de una evaluación va de 0 a 100', function (int $progress) {
    ForecastEvaluation::factory()->create(['progress' => $progress]);
})->with([101, -1])->throws(QueryException::class, 'forecast_evaluations_progress_check');
