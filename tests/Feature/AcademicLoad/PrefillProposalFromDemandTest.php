<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Actions\Proposals\StartCareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use App\Modules\Demand\Enums\ForecastMethod;
use App\Modules\Demand\Models\DemandForecast;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Models\StudentProgress;

/*
| Precarga de la propuesta con la proyección de Demand (tarea 5.5, confirmado el 16 de noviembre de 2026): cada fila nace con
| los esperados y los grupos de la proyección de su materia y su campus, guardados también aparte como `suggested_*` con su
| método; sin proyección cae a lo de siempre (esperados = elegibles, grupos sugeridos) sin `suggested_*`. Los elegibles de la
| fila y el total de la propuesta siguen siendo los de EligibleStudentsQuery.
*/

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->term = Term::factory()->create(['code' => '20271']);
    TermOpening::factory()->create(['term_id' => $this->term->id, 'modality_id' => modalityOf('PRES')->id]);
    $this->career = Career::factory()->create(['code' => 'ISC']);
    CareerCampus::factory()->create(['career_id' => $this->career->id, 'campus_id' => $this->c2->id]);
    CareerCampus::factory()->create(['career_id' => $this->career->id, 'campus_id' => $this->c1->id]);
    $this->plan = StudyPlan::factory()->create(['career_id' => $this->career->id, 'code' => 'ISIC-2010-224']);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function demandForecastFor(Subject $subject, ?Campus $campus, array $attributes = [], ?Modality $modality = null): DemandForecast
{
    return DemandForecast::factory()->create([
        'term_id' => test()->term->id,
        'modality_id' => ($modality ?? modalityOf('PRES'))->id,
        'career_id' => test()->career->id,
        'subject_id' => $subject->id,
        'campus_id' => $campus?->id,
        ...$attributes,
    ]);
}

/**
 * `$count` alumnos activos del plan en el campus que ya pueden cursar `$subjects`.
 *
 * @param  list<Subject>  $subjects
 */
function demandPrefillEligible(StudyPlan $plan, Campus $campus, array $subjects, int $count): void
{
    Student::factory()->count($count)->for($plan)->create(['campus_id' => $campus->id])
        ->each(fn (Student $student) => StudentProgress::factory()->for($student)->create([
            'subjects_available' => array_map(fn (Subject $subject): string => $subject->id, $subjects),
        ]));
}

it('la propuesta nueva nace con la proyección de la materia en cada uno de sus dos campus', function () {
    $calculo = Subject::factory()->create(['code' => 'ACF-0901']);
    placeInPlan($this->plan, $calculo, 1);
    demandForecastFor($calculo, $this->c2, ['expected_students' => 53, 'suggested_groups' => 2]);
    demandForecastFor($calculo, $this->c1, ['expected_students' => 12, 'suggested_groups' => 1, 'method' => ForecastMethod::DepartmentFallback]);

    $proposal = app(StartCareerProposal::class)->handle($this->term, modalityOf('PRES'), $this->career, $this->actor);

    $rows = $proposal->items()->get()->keyBy('campus_id');

    expect($rows[$this->c2->id]->only(['expected_students', 'requested_groups', 'suggested_expected_students', 'suggested_groups']))->toBe([
        'expected_students' => 53, 'requested_groups' => 2, 'suggested_expected_students' => 53, 'suggested_groups' => 2,
    ])
        ->and($rows[$this->c2->id]->forecast_method)->toBe(ForecastMethod::Formula)
        ->and($rows[$this->c1->id]->only(['expected_students', 'requested_groups', 'suggested_expected_students', 'suggested_groups']))->toBe([
            'expected_students' => 12, 'requested_groups' => 1, 'suggested_expected_students' => 12, 'suggested_groups' => 1,
        ])
        ->and($rows[$this->c1->id]->forecast_method)->toBe(ForecastMethod::DepartmentFallback)
        ->and($rows[$this->c2->id]->captured_at)->toBeNull();
});

it('una materia sin proyección cae a esperados = elegibles y los grupos de siempre, sin sugerido ni método', function () {
    $conProyeccion = Subject::factory()->create(['code' => 'ACF-0901']);
    $sinProyeccion = Subject::factory()->create(['code' => 'SCD-1015', 'needs_lab' => true]);
    placeInPlan($this->plan, $conProyeccion, 1);
    placeInPlan($this->plan, $sinProyeccion, 5);
    demandPrefillEligible($this->plan, $this->c2, [$sinProyeccion], 30);
    demandForecastFor($conProyeccion, $this->c2, ['expected_students' => 53, 'suggested_groups' => 2]);

    $proposal = app(StartCareerProposal::class)->handle($this->term, modalityOf('PRES'), $this->career, $this->actor);
    $row = $proposal->items()->where('subject_id', $sinProyeccion->id)->where('campus_id', $this->c2->id)->sole();

    expect($row->only(['eligible_students', 'expected_students', 'requested_groups']))->toBe(['eligible_students' => 30, 'expected_students' => 30, 'requested_groups' => 2])
        ->and($row->suggested_expected_students)->toBeNull()
        ->and($row->suggested_groups)->toBeNull()
        ->and($row->forecast_method)->toBeNull();

    // La fila del otro campus de la materia con proyección en C2 no la tiene en C1.
    $other = $proposal->items()->where('subject_id', $conProyeccion->id)->where('campus_id', $this->c1->id)->sole();
    expect($other->suggested_groups)->toBeNull();
});

it('los elegibles de la fila y el total de la propuesta siguen siendo los de EligibleStudentsQuery, no los de la proyección', function () {
    $calculo = Subject::factory()->create(['code' => 'ACF-0901']);
    placeInPlan($this->plan, $calculo, 1);
    demandPrefillEligible($this->plan, $this->c2, [$calculo], 7);
    demandForecastFor($calculo, $this->c2, ['eligible_students' => 60, 'expected_students' => 53, 'suggested_groups' => 2]);

    $proposal = app(StartCareerProposal::class)->handle($this->term, modalityOf('PRES'), $this->career, $this->actor);

    expect($proposal->items()->where('campus_id', $this->c2->id)->sole()->eligible_students)->toBe(7)
        ->and($proposal->eligible_students)->toBe(7);
});

it('MS5: en una modalidad sin salones la proyección sin campus precarga la fila sin campus', function () {
    $online = Modality::factory()->create(['code' => 'M901', 'settings' => ['uses_rooms' => false]]);
    TermOpening::factory()->create(['term_id' => $this->term->id, 'modality_id' => $online->id]);
    $calculo = Subject::factory()->create(['code' => 'ACF-0901']);
    placeInPlan($this->plan, $calculo, 1);
    demandForecastFor($calculo, null, ['expected_students' => 21, 'suggested_groups' => 1], $online);

    $proposal = app(StartCareerProposal::class)->handle($this->term, $online, $this->career, $this->actor);
    $row = $proposal->items()->sole();

    expect($row->campus_id)->toBeNull()
        ->and($row->only(['expected_students', 'requested_groups', 'suggested_expected_students', 'suggested_groups']))->toBe([
            'expected_students' => 21, 'requested_groups' => 1, 'suggested_expected_students' => 21, 'suggested_groups' => 1,
        ]);
});

it('una proyección de otra carrera, periodo o modalidad no precarga la fila', function () {
    $calculo = Subject::factory()->create(['code' => 'ACF-0901']);
    placeInPlan($this->plan, $calculo, 1);
    demandForecastFor($calculo, $this->c2, ['term_id' => Term::factory()->create(['code' => '20272'])->id]);

    $proposal = app(StartCareerProposal::class)->handle($this->term, modalityOf('PRES'), $this->career, $this->actor);

    expect(ProposalItem::query()->where('career_proposal_id', $proposal->id)->whereNotNull('suggested_groups')->count())->toBe(0);
});
