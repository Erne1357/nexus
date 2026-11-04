<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\AcademicLoad\Queries\CareerProposalQuery;
use App\Modules\AcademicLoad\Queries\ProposalOverview;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\CampusProfile;
use App\Modules\Core\Models\Term;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/*
| CareerProposalQuery da a la página de la propuesta lo que muestra (reglas-de-negocio.md, "Propuesta de
| carrera"): las filas por semestre con su materia y departamento, la parte de cada departamento con su
| comentario, los totales de lo que se pide y, para el aviso, los departamentos involucrados con catálogos sin
| validar. Sin consultas por fila.
*/

beforeEach(function () {
    $this->campus = Campus::factory()->create(['settings' => [...CampusProfile::DEFAULTS, 'uses_rooms' => false]]);
    $this->proposal = CareerProposal::factory()->create(['campus_id' => $this->campus->id]);
    $this->cb = Department::factory()->create(['code' => 'CB']);
    $this->sc = Department::factory()->create(['code' => 'SC']);
});

function proposalOverview(?CareerProposal $proposal = null): ProposalOverview
{
    return app(CareerProposalQuery::class)->overview($proposal ?? test()->proposal);
}

/**
 * Una fila de la propuesta de la prueba con una materia nueva del departamento.
 *
 * @param  array<string, mixed>  $attributes
 */
function overviewRow(Department $department, string $code, int $semester, int $groups, int $expected = 30, array $subject = []): ProposalItem
{
    return ProposalItem::factory()->create([
        'career_proposal_id' => test()->proposal->id,
        'subject_id' => Subject::factory()->create(['code' => $code, 'department_id' => $department->id, ...$subject])->id,
        'semester' => $semester,
        'requested_groups' => $groups,
        'expected_students' => $expected,
    ]);
}

/**
 * Valida los cuatro catálogos generales del departamento en el periodo de la propuesta.
 */
function validateOverviewCatalogs(Department $department): void
{
    foreach (Catalog::general() as $catalog) {
        CatalogReview::factory()->general($catalog)->validated()->create(['term_id' => test()->proposal->term_id, 'department_id' => $department->id]);
    }
}

it('agrupa las filas por semestre, en orden, y por clave de materia, con su materia y departamento', function () {
    overviewRow($this->sc, 'SCD-1015', 5, 1, subject: ['name' => 'Redes', 'needs_lab' => true]);
    overviewRow($this->cb, 'ACF-0902', 2, 1);
    overviewRow($this->cb, 'ACF-0901', 1, 2);
    overviewRow($this->sc, 'AED-1026', 2, 0);

    $overview = proposalOverview();

    expect(array_keys($overview->semesters))->toBe([1, 2, 5])
        ->and(array_map(fn (array $rows): array => array_map(fn (ProposalItem $item): string => $item->subject->code, $rows), $overview->semesters))
        ->toBe([1 => ['ACF-0901'], 2 => ['ACF-0902', 'AED-1026'], 5 => ['SCD-1015']]);

    $redes = $overview->semesters[5][0];
    expect($redes->subject->name)->toBe('Redes')
        ->and($redes->subject->needs_lab)->toBeTrue()
        ->and($redes->subject->department->code)->toBe('SC');
});

it('da las partes de los departamentos por clave, con su estado y comentario', function () {
    ProposalReview::factory()->returned('Falta un grupo.')->create(['career_proposal_id' => $this->proposal->id, 'department_id' => $this->sc->id]);
    ProposalReview::factory()->accepted()->create(['career_proposal_id' => $this->proposal->id, 'department_id' => $this->cb->id]);
    ProposalReview::factory()->create();

    $overview = proposalOverview();

    expect(array_map(fn (ProposalReview $review): array => [$review->department->code, $review->status->value, $review->comment], $overview->reviews))->toBe([
        ['CB', 'accepted', null],
        ['SC', 'returned', 'Falta un grupo.'],
    ])
        ->and($overview->reviewFor($this->sc->id)?->comment)->toBe('Falta un grupo.')
        ->and($overview->reviewFor(Department::factory()->create()->id))->toBeNull();
});

it('suma las materias con grupos y sus grupos, sin sumar alumnos esperados entre materias', function () {
    overviewRow($this->cb, 'ACF-0901', 1, 2, 70);
    overviewRow($this->sc, 'SCD-1015', 5, 1, 20);
    overviewRow($this->sc, 'AED-1026', 2, 0, 15);

    expect(proposalOverview()->totals)->toBe(['subjects' => 2, 'groups' => 3]);
});

it('una propuesta sin filas da semestres y totales vacíos', function () {
    $overview = proposalOverview();

    expect($overview->semesters)->toBe([])
        ->and($overview->reviews)->toBe([])
        ->and($overview->totals)->toBe(['subjects' => 0, 'groups' => 0])
        ->and($overview->pendingCatalogs)->toBe([]);
});

it('avisa de los departamentos involucrados con catálogos sin validar, por clave, con cuáles', function () {
    overviewRow($this->sc, 'SCD-1015', 5, 1);
    overviewRow($this->cb, 'ACF-0901', 1, 2);
    $ii = Department::factory()->create(['code' => 'II']);
    overviewRow($ii, 'INC-1001', 3, 1);
    validateOverviewCatalogs($ii);
    foreach ([Catalog::Teachers, Catalog::Subjects, Catalog::Careers] as $catalog) {
        CatalogReview::factory()->general($catalog)->validated()->create(['term_id' => $this->proposal->term_id, 'department_id' => $this->sc->id]);
    }

    $pending = proposalOverview()->pendingCatalogs;

    expect(array_map(fn (array $row): array => [$row['department']->code, $row['catalogs']], $pending))->toBe([
        ['CB', [Catalog::Teachers, Catalog::Subjects, Catalog::StudyPlans, Catalog::Careers]],
        ['SC', [Catalog::StudyPlans]],
    ]);
});

it('no avisa de un departamento al que no se le piden grupos', function () {
    overviewRow($this->cb, 'ACF-0901', 1, 0);

    expect(proposalOverview()->pendingCatalogs)->toBe([]);
});

it('en un campus que usa salones también avisa de los salones sin validar', function () {
    $this->campus->update(['settings' => CampusProfile::DEFAULTS]);
    overviewRow($this->cb, 'ACF-0901', 1, 1);
    validateOverviewCatalogs($this->cb);

    expect(proposalOverview($this->proposal->fresh())->pendingCatalogs[0]['catalogs'])->toBe([Catalog::Rooms]);
});

it('no consulta por fila: las mismas consultas con 2 que con 12 materias', function () {
    $count = function (): int {
        $proposal = CareerProposal::query()->findOrFail($this->proposal->id);
        DB::flushQueryLog();
        DB::enableQueryLog();
        proposalOverview($proposal);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    overviewRow($this->cb, 'ACF-0901', 1, 1);
    overviewRow($this->sc, 'SCD-1015', 5, 1);
    $few = $count();

    foreach (range(1, 10) as $n) {
        overviewRow($n % 2 === 0 ? $this->cb : $this->sc, sprintf('MAT-%04d', $n), $n % 9 + 1, 1);
    }

    expect($count())->toBe($few);
});

it('una materia borrada después de armar la propuesta sigue en su fila', function () {
    $item = overviewRow($this->cb, 'ACF-0901', 1, 1);
    $item->subject->delete();

    $row = proposalOverview()->semesters[1][0];

    expect($row->subject->code)->toBe('ACF-0901')
        ->and($row->subject->department->code)->toBe('CB');
});

it('dice si una fila se puede corregir: en borrador o regresada, y no si su departamento ya aceptó', function () {
    $calculo = overviewRow($this->cb, 'ACF-0901', 1, 1);
    $redes = overviewRow($this->sc, 'SCD-1015', 5, 1);

    expect(proposalOverview()->isEditable($calculo))->toBeTrue();

    $this->proposal->update(['status' => ProposalStatus::Returned]);
    ProposalReview::factory()->accepted()->create(['career_proposal_id' => $this->proposal->id, 'department_id' => $this->cb->id]);
    $returned = proposalOverview();

    expect($returned->isEditable($calculo))->toBeFalse()
        ->and($returned->isEditable($redes))->toBeTrue();

    $this->proposal->update(['status' => ProposalStatus::Submitted]);

    expect(proposalOverview()->isEditable($redes))->toBeFalse();
});

it('encuentra la propuesta de una carrera en un campus y periodo, o ninguna', function () {
    $query = app(CareerProposalQuery::class);

    expect($query->findFor($this->proposal->term, $this->campus, $this->proposal->career)?->id)->toBe($this->proposal->id)
        ->and($query->findFor(Term::factory()->create(), $this->campus, $this->proposal->career))->toBeNull();
});

it('busca la propuesta y sus filas por id del cliente, sin fallar con un id que no es uuid', function () {
    $query = app(CareerProposalQuery::class);
    $item = overviewRow($this->cb, 'ACF-0901', 1, 1);
    $other = ProposalItem::factory()->create();

    expect($query->findOrFail($this->proposal->id)->id)->toBe($this->proposal->id)
        ->and($query->itemOrFail($this->proposal, $item->id)->id)->toBe($item->id)
        ->and(fn () => $query->findOrFail('no-es-uuid'))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $query->itemOrFail($this->proposal, 'no-es-uuid'))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $query->itemOrFail($this->proposal, $other->id))->toThrow(ModelNotFoundException::class);
});

it('dice la especialidad de cada materia en su plan y acomoda el tronco común antes que cada banda', function () {
    $plan = StudyPlan::factory()->create();
    $redes = Specialty::factory()->for($plan)->create(['code' => 'REDES', 'name' => 'Redes']);
    $web = Specialty::factory()->for($plan)->create(['code' => 'WEB', 'name' => 'Web']);
    $common = overviewRow($this->cb, 'AAA-0001', 5, 1);
    $inWeb = overviewRow($this->sc, 'AAA-0002', 5, 1);
    $inRedes = overviewRow($this->sc, 'AAA-0003', 5, 1);
    foreach ([[$common, null], [$inWeb, $web], [$inRedes, $redes]] as [$item, $specialty]) {
        placeInPlan($plan, $item->subject, 5, $specialty);
        $item->update(['study_plan_id' => $plan->id]);
    }

    $overview = proposalOverview();

    expect($overview->specialties)->toBe([['id' => $redes->id, 'name' => 'Redes', 'subjects' => 1], ['id' => $web->id, 'name' => 'Web', 'subjects' => 1]])
        ->and($overview->specialtyFor($common->fresh()))->toBeNull()
        ->and($overview->specialtyFor($inWeb->fresh()))->toBe($web->id)
        ->and(array_map(fn (ProposalItem $item): string => $item->subject->code, $overview->gridSemesters()[5]))->toBe(['AAA-0001', 'AAA-0003', 'AAA-0002']);
});

it('empareja la especialidad por el plan de la fila, no por la materia en otro plan', function () {
    $planA = StudyPlan::factory()->create();
    $planB = StudyPlan::factory()->create();
    $inA = Specialty::factory()->for($planA)->create(['code' => 'AAA', 'name' => 'De A']);
    $inB = Specialty::factory()->for($planB)->create(['code' => 'BBB', 'name' => 'De B']);
    $item = overviewRow($this->cb, 'AAA-0001', 3, 1);
    $item->update(['study_plan_id' => $planA->id]);
    placeInPlan($planA, $item->subject, 3);
    placeInPlan($planB, $item->subject, 3, $inB);
    $other = overviewRow($this->sc, 'AAA-0002', 3, 1);
    $other->update(['study_plan_id' => $planB->id]);
    placeInPlan($planB, $other->subject, 3, $inB);

    $overview = proposalOverview();

    expect($overview->specialtyFor($item->fresh()))->toBeNull()
        ->and($overview->specialtyFor($other->fresh()))->toBe($inB->id)
        ->and($overview->specialties)->toBe([['id' => $inB->id, 'name' => 'De B', 'subjects' => 1]]);
});

it('conserva la banda de una materia cuya colocación se quitó después', function () {
    $plan = StudyPlan::factory()->create();
    $redes = Specialty::factory()->for($plan)->create(['code' => 'REDES', 'name' => 'Redes']);
    $item = overviewRow($this->cb, 'AAA-0001', 3, 1);
    $item->update(['study_plan_id' => $plan->id]);
    placeInPlan($plan, $item->subject, 3, $redes)->delete();

    expect(proposalOverview()->specialtyFor($item->fresh()))->toBe($redes->id);
});
