<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\CatalogValidation\Actions\CreateTermReviews;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Actions\AddExternalLoad;
use App\Modules\Faculty\Actions\RemoveAdscription;
use App\Modules\Faculty\Actions\SetQualification;
use App\Modules\Faculty\Actions\SetTeacherAvailability;
use App\Modules\Faculty\Enums\AvailabilityKind;
use App\Modules\Faculty\Enums\QualificationLevel;
use App\Modules\Faculty\Events\AdscriptionChanged;
use App\Modules\Faculty\Events\AvailabilityChanged;
use App\Modules\Faculty\Events\PositionChanged;
use App\Modules\Faculty\Events\TeacherUpdated;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Faculty\Models\TeacherPosition;

/*
| CV-S4 ampliado (supuesto por confirmar): la primera captura del jefe sobre un docente de su departamento, en la
| ficha o en el editor de disponibilidad, pasa la revisión de docentes del periodo actual de pendiente a en
| revisión. Admin, Subdirección y el jefe de proyecto docente no la mueven.
*/

beforeEach(function () {
    $this->term = Term::factory()->current()->create();
    $this->campus = Campus::factory()->create();
    $this->sis = Department::factory()->create();
    $this->ele = Department::factory()->create();
    app(CreateTermReviews::class)->handle($this->term, $this->campus);

    $this->teacher = Teacher::factory()->create();
    TeacherDepartment::factory()->for($this->teacher)->for($this->sis)->create();
    $this->subject = Subject::factory()->create(['department_id' => $this->sis->id]);
    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sis->id])->create();
});

function teachersReviewStatus(Department $department): ReviewStatus
{
    return CatalogReview::query()->where('term_id', test()->term->id)->where('department_id', $department->id)->where('catalog', Catalog::Teachers)->sole()->status;
}

function captureQualification(User $actor): void
{
    test()->actingAs($actor);
    app(SetQualification::class)->handle(test()->teacher, test()->subject, QualificationLevel::Apto, $actor);
}

it('el perfil que declara el jefe de su departamento inicia la revisión de docentes', function () {
    expect(teachersReviewStatus($this->sis))->toBe(ReviewStatus::Pending);

    captureQualification($this->jefe);

    expect(teachersReviewStatus($this->sis))->toBe(ReviewStatus::InReview)
        ->and(teachersReviewStatus($this->ele))->toBe(ReviewStatus::Pending);
});

it('la disponibilidad y las horas fuera del ITCJ del jefe también la inician', function () {
    $this->actingAs($this->jefe);
    app(SetTeacherAvailability::class)->handle($this->teacher, $this->term, [['day' => 1, 'starts_at' => '09:00', 'kind' => AvailabilityKind::Unavailable]], $this->campus->profile);
    expect(teachersReviewStatus($this->sis))->toBe(ReviewStatus::InReview);

    CatalogReview::query()->update(['status' => ReviewStatus::Pending]);
    app(AddExternalLoad::class)->handle($this->teacher, $this->term, ['source' => 'Otra escuela', 'hours' => 5]);
    expect(teachersReviewStatus($this->sis))->toBe(ReviewStatus::InReview);
});

it('los cambios de ficha, adscripción y plaza del jefe la inician', function (string $change) {
    $this->actingAs($this->jefe);

    match ($change) {
        'ficha' => TeacherUpdated::dispatch($this->teacher, ['name'], $this->jefe),
        'adscripción' => AdscriptionChanged::dispatch($this->teacher->adscriptions()->first(), $this->jefe),
        'plaza' => PositionChanged::dispatch(TeacherPosition::factory()->for($this->teacher)->tiempoCompleto()->create(), $this->jefe),
    };

    expect(teachersReviewStatus($this->sis))->toBe(ReviewStatus::InReview);
})->with(['ficha', 'adscripción', 'plaza']);

it('el admin, Subdirección y el jefe de proyecto docente no la mueven', function (string $role) {
    captureQualification(match ($role) {
        'admin' => User::factory()->admin()->create(),
        'subdirección' => User::factory()->withRole(Role::Subdireccion)->create(),
        'jefe de proyecto docente' => User::factory()->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->campus->id, 'department_id' => $this->sis->id])->create(),
    });

    expect(teachersReviewStatus($this->sis))->toBe(ReviewStatus::Pending);
})->with(['admin', 'subdirección', 'jefe de proyecto docente']);

it('el jefe de otro departamento no la mueve', function () {
    captureQualification(User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->ele->id])->create());

    expect(teachersReviewStatus($this->sis))->toBe(ReviewStatus::Pending)
        ->and(teachersReviewStatus($this->ele))->toBe(ReviewStatus::Pending);
});

it('una revisión validada no cambia', function () {
    CatalogReview::query()->where('department_id', $this->sis->id)->where('catalog', Catalog::Teachers)->update(['status' => ReviewStatus::Validated]);

    captureQualification($this->jefe);

    expect(teachersReviewStatus($this->sis))->toBe(ReviewStatus::Validated);
});

it('la revisión de un periodo que no es el actual no cambia', function () {
    Term::query()->update(['is_current' => false]);

    captureQualification($this->jefe);

    expect(teachersReviewStatus($this->sis))->toBe(ReviewStatus::Pending);
});

it('sin sesión usa el actor del evento, como en un job', function () {
    app(SetQualification::class)->handle($this->teacher, $this->subject, QualificationLevel::Apto, $this->jefe);

    expect(teachersReviewStatus($this->sis))->toBe(ReviewStatus::InReview);
});

it('sin sesión y sin actor no hace nada', function () {
    app(AddExternalLoad::class)->handle($this->teacher, $this->term, ['source' => 'Otra escuela', 'hours' => 5]);

    expect(teachersReviewStatus($this->sis))->toBe(ReviewStatus::Pending);
});

it('con otro periodo activo en la sesión, inicia la revisión de ese periodo y no la del actual', function () {
    $active = Term::factory()->create();
    app(CreateTermReviews::class)->handle($active, $this->campus);
    $this->withSession([Actor::ACTIVE_TERM => $active->id]);

    captureQualification($this->jefe);

    $status = fn (Term $term): ReviewStatus => CatalogReview::query()->where('term_id', $term->id)->where('department_id', $this->sis->id)->where('catalog', Catalog::Teachers)->sole()->status;
    expect($status($active))->toBe(ReviewStatus::InReview)
        ->and($status($this->term))->toBe(ReviewStatus::Pending);
});

it('la disponibilidad y las horas fuera traen su periodo y ese usa', function () {
    $other = Term::factory()->create();
    app(CreateTermReviews::class)->handle($other, $this->campus);
    $this->actingAs($this->jefe);

    AvailabilityChanged::dispatch($this->teacher, $other, $this->jefe);

    expect(CatalogReview::query()->where('term_id', $other->id)->where('catalog', Catalog::Teachers)->where('department_id', $this->sis->id)->sole()->status)->toBe(ReviewStatus::InReview)
        ->and(teachersReviewStatus($this->sis))->toBe(ReviewStatus::Pending);
});

it('Quitar de mi departamento también inicia la revisión, aunque el docente ya no sea del departamento', function () {
    TeacherDepartment::factory()->for($this->teacher)->for($this->ele)->create(['is_primary' => false]);
    $this->actingAs($this->jefe);

    app(RemoveAdscription::class)->handle(TeacherDepartment::query()->where('teacher_id', $this->teacher->id)->where('department_id', $this->sis->id)->sole());

    expect(teachersReviewStatus($this->sis))->toBe(ReviewStatus::InReview);
});

it('el jefe con Coordinación activa no la mueve', function () {
    vacate(Role::JefeDepartamento, $this->sis->id);
    $jefe = User::factory()
        ->withRole(Role::JefeDepartamento, ['department_id' => $this->sis->id, 'valid_from' => today()->toDateString()])
        ->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => Career::factory()->create()->id])
        ->create();

    actingAsRole($jefe, Role::Coordinador);
    app(SetQualification::class)->handle($this->teacher, $this->subject, QualificationLevel::Apto, $jefe);

    expect(teachersReviewStatus($this->sis))->toBe(ReviewStatus::Pending);
});

function adminAndJefeOfSistemas(): User
{
    vacate(Role::JefeDepartamento, test()->sis->id);

    return User::factory()
        ->admin()
        ->withRole(Role::JefeDepartamento, ['department_id' => test()->sis->id, 'valid_from' => today()->toDateString()])
        ->create();
}

it('la persona que es admin y jefe la inicia con Jefatura activa y no con Administración activa', function () {
    $adminJefe = adminAndJefeOfSistemas();

    actingAsRole($adminJefe, Role::Admin);
    app(SetQualification::class)->handle($this->teacher, $this->subject, QualificationLevel::Apto, $adminJefe);
    expect(teachersReviewStatus($this->sis))->toBe(ReviewStatus::Pending);

    app()->forgetScopedInstances();
    actingAsRole($adminJefe, Role::JefeDepartamento);
    app(SetQualification::class)->handle($this->teacher, $this->subject, QualificationLevel::Preferente, $adminJefe);
    expect(teachersReviewStatus($this->sis))->toBe(ReviewStatus::InReview);
});

it('D8: sin sesión, la persona que es admin y jefe la inicia como jefe', function () {
    $adminJefe = adminAndJefeOfSistemas();

    app(SetQualification::class)->handle($this->teacher, $this->subject, QualificationLevel::Apto, $adminJefe);

    expect(teachersReviewStatus($this->sis))->toBe(ReviewStatus::InReview);
});
