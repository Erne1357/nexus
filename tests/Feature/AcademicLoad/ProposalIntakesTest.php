<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Actions\Proposals\CreateProposalPackage;
use App\Modules\AcademicLoad\Actions\Proposals\SetPackageCount;
use App\Modules\AcademicLoad\Actions\Proposals\StartCareerProposal;
use App\Modules\AcademicLoad\Actions\Proposals\SyncProposalIntakes;
use App\Modules\AcademicLoad\Actions\Proposals\UpdateProposalIntake;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalIntake;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalPackageCampus;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use App\Modules\Demand\Enums\ForecastMethod;
use App\Modules\Demand\Models\DemandForecast;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| Nuevo ingreso esperado por campus (tarea 5.6b; DM-09; confirmado el 16 y 17 de noviembre de 2026): Nexus lo precarga con el
| `new_students` de la proyección en las filas de primer semestre del campus (el máximo si varían), el coordinador lo puede
| corregir, y lo sugerido y lo capturado se guardan aparte. Lo que el coordinador no ha tocado sigue a la proyección al
| recalcular; lo corregido se conserva. Los paquetes sugeridos salen del nuevo ingreso vigente.
*/

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->term = Term::factory()->create(['code' => '20271']);
    TermOpening::factory()->create(['term_id' => $this->term->id, 'modality_id' => modalityOf('PRES')->id]);
    $this->career = Career::factory()->create(['code' => 'ISC']);

    foreach ([$this->c1, $this->c2] as $campus) {
        CareerCampus::factory()->create(['career_id' => $this->career->id, 'campus_id' => $campus->id]);
    }

    $plan = StudyPlan::factory()->create(['career_id' => $this->career->id]);
    $this->dept = Department::factory()->create(['code' => 'CB']);
    $this->first = Subject::factory()->create(['code' => 'ACF-0901', 'department_id' => $this->dept->id]);
    $this->second = Subject::factory()->create(['code' => 'AED-1285', 'department_id' => $this->dept->id]);
    $this->third = Subject::factory()->create(['code' => 'ACF-0902', 'department_id' => $this->dept->id]);

    foreach ([[$this->first, 1], [$this->second, 1], [$this->third, 2]] as [$subject, $semester]) {
        StudyPlanSubject::factory()->create(['study_plan_id' => $plan->id, 'subject_id' => $subject->id, 'semester' => $semester]);
    }
});

function intakeForecast(Subject $subject, Campus $campus, ?int $newStudents, ForecastMethod $method = ForecastMethod::FirstSemester): DemandForecast
{
    return DemandForecast::factory()->create([
        'term_id' => test()->term->id,
        'modality_id' => modalityOf('PRES')->id,
        'career_id' => test()->career->id,
        'subject_id' => $subject->id,
        'campus_id' => $campus->id,
        'new_students' => $newStudents,
        'method' => $method,
    ]);
}

function startedIntakeProposal(): CareerProposal
{
    return app(StartCareerProposal::class)->handle(test()->term, modalityOf('PRES'), test()->career, test()->actor);
}

function intakeOf(CareerProposal $proposal, Campus $campus): ProposalIntake
{
    return ProposalIntake::query()->where('career_proposal_id', $proposal->id)->where('campus_id', $campus->id)->sole();
}

function syncIntakes(CareerProposal $proposal, ?string $campusId = null): int
{
    return app(SyncProposalIntakes::class)->handle($proposal->term_id, $proposal->modality_id, $campusId, [$proposal->career_id]);
}

it('al armar la propuesta precarga el nuevo ingreso de cada campus con el máximo de sus filas de primer semestre', function () {
    intakeForecast($this->first, $this->c1, 120);
    intakeForecast($this->second, $this->c1, 100);
    intakeForecast($this->first, $this->c2, 40);
    intakeForecast($this->third, $this->c2, 999, ForecastMethod::Formula);

    $proposal = startedIntakeProposal();

    expect(intakeOf($proposal, $this->c1)->only(['suggested_new_students', 'new_students', 'captured_at']))->toBe(['suggested_new_students' => 120, 'new_students' => 120, 'captured_at' => null])
        ->and(intakeOf($proposal, $this->c2)->only(['suggested_new_students', 'new_students']))->toBe(['suggested_new_students' => 40, 'new_students' => 40])
        ->and($proposal->intakes()->count())->toBe(2)
        ->and($proposal->packages()->count())->toBe(0);
});

it('sin proyección de primer semestre el sugerido es nulo y el nuevo ingreso, 0', function () {
    $proposal = startedIntakeProposal();

    expect(intakeOf($proposal, $this->c1)->only(['suggested_new_students', 'new_students']))->toBe(['suggested_new_students' => null, 'new_students' => 0]);
});

it('el coordinador corrige el nuevo ingreso: lo marca, lo sugerido queda aparte y deja bitácora', function () {
    intakeForecast($this->first, $this->c1, 120);
    $proposal = startedIntakeProposal();

    $intake = app(UpdateProposalIntake::class)->handle($proposal, $this->c1, 90, $this->actor);

    expect($intake->only(['suggested_new_students', 'new_students']))->toBe(['suggested_new_students' => 120, 'new_students' => 90])
        ->and($intake->captured_at)->not->toBeNull()
        ->and($intake->captured_by)->toBe($this->actor->id);

    $activity = Activity::query()->where('subject_id', $intake->id)->sole();
    expect($activity->attribute_changes['attributes'])->toBe(['new_students' => 90])
        ->and($activity->attribute_changes['old'])->toBe(['new_students' => 120]);
});

it('valida el rango, la columna y el estado de la propuesta', function () {
    $proposal = startedIntakeProposal();
    $update = fn (int $n, ?Campus $campus = null) => app(UpdateProposalIntake::class)->handle($proposal, $campus ?? $this->c1, $n, $this->actor);

    expect(fn () => $update(-1))->toThrow(ValidationException::class)
        ->and(fn () => $update(1001))->toThrow(ValidationException::class)
        ->and(fn () => $update(10, Campus::factory()->create()))->toThrow(ValidationException::class);

    $proposal->forceFill(['status' => ProposalStatus::Submitted])->save();

    expect(fn () => $update(10))->toThrow(ValidationException::class);
});

it('el recalculo sigue a la proyección en lo sin tocar y conserva lo corregido', function () {
    $forecast1 = intakeForecast($this->first, $this->c1, 120);
    $forecast2 = intakeForecast($this->first, $this->c2, 40);
    $proposal = startedIntakeProposal();
    app(UpdateProposalIntake::class)->handle($proposal, $this->c2, 50, $this->actor);

    $forecast1->update(['new_students' => 140]);
    $forecast2->update(['new_students' => 45]);

    expect(syncIntakes($proposal))->toBe(2)
        ->and(intakeOf($proposal, $this->c1)->only(['suggested_new_students', 'new_students']))->toBe(['suggested_new_students' => 140, 'new_students' => 140])
        ->and(intakeOf($proposal, $this->c2)->only(['suggested_new_students', 'new_students']))->toBe(['suggested_new_students' => 45, 'new_students' => 50]);

    expect(syncIntakes($proposal))->toBe(0);
});

it('el recalculo con un campus solo toca ese campus y una propuesta enviada no se toca', function () {
    $forecast1 = intakeForecast($this->first, $this->c1, 120);
    $forecast2 = intakeForecast($this->first, $this->c2, 40);
    $proposal = startedIntakeProposal();
    $forecast1->update(['new_students' => 130]);
    $forecast2->update(['new_students' => 50]);

    syncIntakes($proposal, $this->c1->id);

    expect(intakeOf($proposal, $this->c1)->new_students)->toBe(130)
        ->and(intakeOf($proposal, $this->c2)->new_students)->toBe(40);

    $proposal->forceFill(['status' => ProposalStatus::Submitted])->save();
    syncIntakes($proposal);

    expect(intakeOf($proposal, $this->c2)->new_students)->toBe(40);
});

it('los paquetes sugeridos son ceil(nuevo ingreso / tamaño normal) en el primer paquete y siguen al recalcular lo sin tocar', function () {
    $forecast = intakeForecast($this->first, $this->c1, 120);
    $proposal = startedIntakeProposal();
    $package = app(CreateProposalPackage::class)->handle($proposal, [$this->first->id, $this->second->id], null, $this->actor);
    $second = app(CreateProposalPackage::class)->handle($proposal, [$this->second->id], null, $this->actor);
    $column = fn ($package): ProposalPackageCampus => $package->campuses()->where('campus_id', $this->c1->id)->sole();

    expect($column($package)->only(['suggested_count', 'count']))->toBe(['suggested_count' => 4, 'count' => 4])
        ->and($column($second)->only(['suggested_count', 'count']))->toBe(['suggested_count' => 0, 'count' => 0])
        ->and(ProposalItem::query()->where('career_proposal_id', $proposal->id)->where('subject_id', $this->first->id)->where('campus_id', $this->c1->id)->value('package_groups'))->toBe(4);

    $forecast->update(['new_students' => 36]);
    syncIntakes($proposal);

    expect($column($package)->only(['suggested_count', 'count']))->toBe(['suggested_count' => 2, 'count' => 2])
        ->and(ProposalItem::query()->where('career_proposal_id', $proposal->id)->where('subject_id', $this->first->id)->where('campus_id', $this->c1->id)->value('package_groups'))->toBe(2);
});

it('una cuenta corregida conserva su valor aunque cambie el sugerido', function () {
    $forecast = intakeForecast($this->first, $this->c1, 120);
    $proposal = startedIntakeProposal();
    $package = app(CreateProposalPackage::class)->handle($proposal, [$this->first->id], null, $this->actor);
    app(SetPackageCount::class)->handle($package, $this->c1, 7, $this->actor);

    $forecast->update(['new_students' => 36]);
    syncIntakes($proposal);

    expect($package->campuses()->where('campus_id', $this->c1->id)->sole()->only(['suggested_count', 'count']))->toBe(['suggested_count' => 2, 'count' => 7]);
});

it('corregir el nuevo ingreso mueve los paquetes sin tocar de ese campus', function () {
    intakeForecast($this->first, $this->c1, 70);
    $proposal = startedIntakeProposal();
    $package = app(CreateProposalPackage::class)->handle($proposal, [$this->first->id], null, $this->actor);

    app(UpdateProposalIntake::class)->handle($proposal, $this->c1, 105, $this->actor);

    expect($package->campuses()->where('campus_id', $this->c1->id)->sole()->only(['suggested_count', 'count']))->toBe(['suggested_count' => 3, 'count' => 3]);
});

it('los paquetes de una materia de un departamento que ya aceptó su parte no se mueven solos', function () {
    $forecast = intakeForecast($this->first, $this->c1, 70);
    $proposal = startedIntakeProposal();
    $package = app(CreateProposalPackage::class)->handle($proposal, [$this->first->id], null, $this->actor);
    $proposal->forceFill(['status' => ProposalStatus::Returned])->save();
    ProposalReview::factory()->accepted()->create(['career_proposal_id' => $proposal->id, 'department_id' => $this->dept->id]);

    $forecast->update(['new_students' => 140]);
    syncIntakes($proposal);

    expect(intakeOf($proposal, $this->c1)->new_students)->toBe(140)
        ->and($package->campuses()->where('campus_id', $this->c1->id)->sole()->only(['suggested_count', 'count']))->toBe(['suggested_count' => 4, 'count' => 2]);
});
