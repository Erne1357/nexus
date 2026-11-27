<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\RepublicationAudience;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Notifications\LoadRepublished;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Notifications\CatalogReviewsPending;
use App\Modules\CatalogValidation\Queries\ReviewOwner;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\PositionNotice;
use App\Modules\Staffing\Notifications\ChangeAlternativeMovesCareerGroup;
use App\Modules\Staffing\Notifications\SelectionFinishedWithPending;

/*
| CE-02: los cuatro avisos que compiten al puesto dicen su puesto (el rol con que se mandaron y el alcance que traen),
| y su correo va al buzón de ese puesto: la validación de catálogos a la jefatura dueña, la republicación a la
| coordinación de la carrera o a la jefatura del otro departamento, el grupo movido por una solicitud a la coordinación
| de su carrera y la selección terminada con pendientes a la jefatura y al jefe de proyecto docente. Sin puesto (no se
| mandaron a uno, o un aviso en cola de antes sin el alcance), siguen a la persona.
*/

beforeEach(function () {
    $this->department = Department::factory()->create(['code' => 'SIS', 'name' => 'Sistemas y Computación']);
    $this->term = Term::factory()->create();
});

/**
 * Un aviso de republicación para la audiencia, con o sin el id de su carrera o departamento.
 */
function republishedFor(AcademicLoad $load, RepublicationAudience $audience, ?string $audienceId): LoadRepublished
{
    return new LoadRepublished($load, 2, null, $audience, 'ISC', [], 'published.index', [], $audienceId);
}

/**
 * Un aviso de grupo movido, con o sin los ids de su carrera y su campus.
 */
function movedGroupNotice(?string $careerId, ?string $campusId, string $modalityId): ChangeAlternativeMovesCareerGroup
{
    return new ChangeAlternativeMovesCareerGroup([
        'group_id' => fakeKey(1), 'label' => 'SCD-1015 · 5A', 'subject' => 'Redes', 'career' => 'ISC', 'campus' => 'C2',
        ...($careerId === null ? [] : ['career_id' => $careerId, 'campus_id' => $campusId]),
        'from' => 'L 7:00', 'to' => 'M 9:00',
    ], 'SCD-1015 · 4A', 'A', fakeKey(2), $modalityId);
}

it('CE-02: la validación de catálogos va a la jefatura del departamento dueño o de Servicios Escolares', function () {
    $schoolServices = schoolServicesUnit();
    $byDepartment = (new CatalogReviewsPending($this->term, $this->department, [Catalog::Teachers]))->addressedTo(Role::JefeDepartamento);
    $bySchoolServices = (new CatalogReviewsPending($this->term, ReviewOwner::schoolServices(), [Catalog::Subjects]))->addressedTo(Role::JefeDepartamento);

    expect($byDepartment)->toBeInstanceOf(PositionNotice::class)
        ->and($byDepartment->position()->columns())->toBe(['org_unit_id' => $this->department->id, 'career_id' => null, 'campus_id' => null, 'modality_id' => null])
        ->and($byDepartment->position()->role)->toBe(Role::JefeDepartamento)
        ->and($bySchoolServices->position()->orgUnitId)->toBe($schoolServices->id)
        ->and((new CatalogReviewsPending($this->term, $this->department, [Catalog::Teachers]))->position())->toBeNull();
});

it('CE-02: la republicación va a la coordinación de la carrera o a la jefatura del otro departamento; al docente, no', function () {
    $load = AcademicLoad::factory()->create(['term_id' => $this->term->id]);
    $career = careerKey(1);

    $toCareer = republishedFor($load, RepublicationAudience::Career, $career)->addressedTo(Role::Coordinador);
    $toDepartment = republishedFor($load, RepublicationAudience::Department, $this->department->id)->addressedTo(Role::JefeProyectoDocente);
    $toTeacher = republishedFor($load, RepublicationAudience::Teacher, fakeKey(9))->addressedTo(Role::Docente);

    expect($toCareer->position()->columns())->toBe(['org_unit_id' => null, 'career_id' => $career, 'campus_id' => null, 'modality_id' => $load->modality_id])
        ->and($toDepartment->position()->columns())->toBe(['org_unit_id' => $this->department->id, 'career_id' => null, 'campus_id' => null, 'modality_id' => $load->modality_id])
        ->and($toTeacher->position())->toBeNull()
        ->and(republishedFor($load, RepublicationAudience::Career, null)->addressedTo(Role::Coordinador)->position())->toBeNull();
});

it('CE-02: el grupo movido va a la coordinación de su carrera en su campus; un aviso en cola de antes, sin los ids, sigue a la persona', function () {
    $load = AcademicLoad::factory()->create();
    $career = careerKey(1);
    $user = User::factory()->create(['email' => 'coordinadora@cdjuarez.tecnm.mx']);

    $notice = movedGroupNotice($career, fakeKey(5), $load->modality_id)->addressedTo(Role::Coordinador);
    $legacy = movedGroupNotice(null, null, $load->modality_id)->addressedTo(Role::Coordinador);

    expect($notice->position()->columns())->toBe(['org_unit_id' => null, 'career_id' => $career, 'campus_id' => fakeKey(5), 'modality_id' => $load->modality_id])
        ->and($notice->via($user))->toBe(['database', 'mail'])
        ->and($legacy->position())->toBeNull()
        ->and($legacy->via($user))->toBe(['database', 'mail'])
        ->and($user->routeNotificationFor('mail', $legacy))->toBe('coordinadora@cdjuarez.tecnm.mx');
});

it('CE-02: la selección terminada con pendientes va a la jefatura y al jefe de proyecto docente del departamento', function () {
    $load = AcademicLoad::factory()->create();
    $make = fn (?string $department): SelectionFinishedWithPending => new SelectionFinishedWithPending($this->term->id, $load->modality_id, 'Sistemas y Computación', fakeKey(7), ['Docente Uno'], $department);

    expect($make($this->department->id)->addressedTo(Role::JefeDepartamento)->position()->columns())->toBe(['org_unit_id' => $this->department->id, 'career_id' => null, 'campus_id' => null, 'modality_id' => null])
        ->and($make($this->department->id)->addressedTo(Role::JefeProyectoDocente)->position()->modalityId)->toBe($load->modality_id)
        ->and($make(null)->addressedTo(Role::JefeDepartamento)->position())->toBeNull();
});

it('CE-02: un aviso de puesto va a la campana y al correo aunque el puesto no tenga buzón; la copia que comparte el correo, solo a la campana', function () {
    $user = User::factory()->create(['email' => null]);
    $notice = (new CatalogReviewsPending($this->term, $this->department, [Catalog::Teachers]))->addressedTo(Role::JefeDepartamento);

    expect($notice->via($user))->toBe(['database', 'mail'])
        ->and($user->routeNotificationFor('mail', $notice))->toBeNull()
        ->and((clone $notice)->sharesPositionMail()->via($user))->toBe(['database']);
});
