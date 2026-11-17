<?php

use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Actions\OpenTerm;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use App\Modules\Demand\Actions\RefreshDemandForecasts;
use App\Modules\Demand\Jobs\ComputeDemandForecasts;
use App\Modules\Demand\Models\DemandForecast;
use App\Modules\Students\Actions\RefreshStudentProgress;
use App\Modules\Students\Events\StudentProgressRefreshed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/*
| El job de la proyección (tarea 5.4): lo encolan la apertura de un periodo en una modalidad (TermOpened) y el recálculo
| del avance de los alumnos tras una importación (StudentProgressRefreshed), y es único por periodo, modalidad y campus.
*/

function jobOffer(): Subject
{
    $plan = StudyPlan::factory()->create();
    $subject = Subject::factory()->create();
    placeInPlan($plan, $subject);
    CareerCampus::factory()->create(['career_id' => $plan->career_id, 'campus_id' => Campus::factory()->create()->id]);

    return $subject;
}

it('al abrir un periodo en una modalidad encola su proyección', function () {
    Queue::fake([ComputeDemandForecasts::class]);
    $term = Term::factory()->create();
    $this->actingAs(User::factory()->admin()->create());

    app(OpenTerm::class)->handle($term, modalityOf('PRES'));

    Queue::assertPushed(ComputeDemandForecasts::class, fn (ComputeDemandForecasts $job): bool => $job->termId === $term->id
        && $job->modalityId === modalityOf('PRES')->id
        && $job->campusId === null);
});

it('al recalcular el avance encola la proyección de cada periodo abierto que se planea, en cada modalidad activa', function () {
    Queue::fake([ComputeDemandForecasts::class]);
    $current = Term::factory()->current()->create(['code' => '20271']);
    $next = Term::factory()->create(['code' => '20273']);
    $old = Term::factory()->create(['code' => '20263']);
    $other = Modality::factory()->create();
    $inactive = Modality::factory()->inactive()->create();
    TermOpening::factory()->create(['term_id' => $current->id]);
    TermOpening::factory()->create(['term_id' => $current->id, 'modality_id' => $other->id]);
    TermOpening::factory()->create(['term_id' => $next->id]);
    TermOpening::factory()->create(['term_id' => $old->id]);
    TermOpening::factory()->create(['term_id' => $current->id, 'modality_id' => $inactive->id]);

    app(RefreshStudentProgress::class)->handle();

    $pushed = Queue::pushed(ComputeDemandForecasts::class)->map(fn (ComputeDemandForecasts $job): string => $job->termId.'|'.$job->modalityId)->all();

    expect($pushed)->toEqualCanonicalizing([
        $current->id.'|'.modalityOf('PRES')->id,
        $current->id.'|'.$other->id,
        $next->id.'|'.modalityOf('PRES')->id,
    ]);
});

it('el recálculo del avance avisa con el plan que recalculó', function () {
    Event::fake([StudentProgressRefreshed::class]);
    $plan = StudyPlan::factory()->create();

    app(RefreshStudentProgress::class)->handle($plan->id);
    app(RefreshStudentProgress::class)->handle();

    Event::assertDispatched(StudentProgressRefreshed::class, fn (StudentProgressRefreshed $event): bool => $event->studyPlanId === $plan->id);
    Event::assertDispatched(StudentProgressRefreshed::class, fn (StudentProgressRefreshed $event): bool => $event->studyPlanId === null);
});

it('el job calcula y guarda la proyección del periodo y la modalidad', function () {
    jobOffer();
    $term = Term::factory()->create(['code' => '20271']);

    (new ComputeDemandForecasts($term->id, modalityOf('PRES')->id))->handle(app(RefreshDemandForecasts::class));

    expect(DemandForecast::query()->where('term_id', $term->id)->count())->toBe(1);
});

it('el job es único por periodo, modalidad y campus', function () {
    expect((new ComputeDemandForecasts('t', 'm'))->uniqueId())->toBe('t|m|all')
        ->and((new ComputeDemandForecasts('t', 'm', 'c'))->uniqueId())->toBe('t|m|c');
});

it('si el periodo, la modalidad o el campus ya no existen, el job no hace nada', function () {
    jobOffer();
    $term = Term::factory()->create();
    $refresh = app(RefreshDemandForecasts::class);

    (new ComputeDemandForecasts(fakeKey(1), modalityOf('PRES')->id))->handle($refresh);
    (new ComputeDemandForecasts($term->id, fakeKey(2)))->handle($refresh);
    (new ComputeDemandForecasts($term->id, modalityOf('PRES')->id, fakeKey(3)))->handle($refresh);

    expect(DemandForecast::query()->count())->toBe(0);
});
