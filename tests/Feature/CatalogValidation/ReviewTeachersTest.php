<?php

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Actions\AddReviewTeacher;
use App\Modules\CatalogValidation\Actions\CorrectRecord;
use App\Modules\CatalogValidation\Actions\DischargeReviewTeacher;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Models\CatalogReviewMark;
use App\Modules\CatalogValidation\Queries\CatalogContentsQuery;
use App\Modules\Core\Enums\PasswordOrigin;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\AccessInvitation;
use App\Modules\Faculty\Models\Teacher;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| Docentes en la revisión de su catálogo: el jefe corrige su correo institucional (CV1, con las reglas de AC-06), los
| da de baja de su departamento (CV8, confirmado el 12 de noviembre de 2026: si no les queda otra adscripción, quedan
| dados de baja) y agrega a uno que ya existe, activo o dado de baja, que se reactiva (CV-S9, supuesto por confirmar).
| Agregar lo marca como revisado (CV7); el que se da de baja sale de la revisión. Todo con la revisión abierta.
*/

beforeEach(function () {
    $this->term = Term::factory()->current()->create();
    $this->sc = Department::factory()->create(['code' => 'SC']);
    $this->ii = Department::factory()->create(['code' => 'II']);
    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sc->id])->create();
    $this->actingAs($this->jefe);
    $this->review = CatalogReview::factory()->general(Catalog::Teachers)->create(['term_id' => $this->term->id, 'department_id' => $this->sc->id]);
});

function teachersErrors(Closure $action): array
{
    try {
        $action();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

function inTeachersReview(Teacher $teacher): bool
{
    return app(CatalogContentsQuery::class)->contains(test()->review->fresh(), $teacher);
}

describe('correo institucional (CV1, AC-06)', function () {
    it('CV1: corrige solo el correo institucional del docente con UpdateTeacher y lo marca como revisado', function () {
        $teacher = Teacher::factory()->adscribedTo($this->sc)->create(['name' => 'Ana Ruiz']);

        app(CorrectRecord::class)->handle($this->review, $teacher, ['institutional_email' => 'ANA.RUIZ@cdjuarez.tecnm.mx', 'name' => 'Otro nombre'], $this->jefe);

        expect($teacher->fresh())
            ->institutional_email->toBe('ana.ruiz@cdjuarez.tecnm.mx')
            ->name->toBe('Ana Ruiz')
            ->and(CatalogReviewMark::query()->where('record_id', $teacher->id)->exists())->toBeTrue()
            ->and($this->review->fresh()->status)->toBe(ReviewStatus::InReview);
    });

    it('AC-06: rechaza un correo de otro dominio', function () {
        $teacher = Teacher::factory()->adscribedTo($this->sc)->create();

        expect(teachersErrors(fn () => app(CorrectRecord::class)->handle($this->review, $teacher, ['institutional_email' => 'ana@itcj.edu.mx'], $this->jefe)))
            ->toHaveKey('institutional_email');
    });
});

describe('dar de baja (CV8)', function () {
    it('CV8: sin otra adscripción, sale de la revisión y queda dado de baja; la revisión pasa a en revisión', function () {
        $teacher = Teacher::factory()->adscribedTo($this->sc)->create(['user_id' => User::factory()->create()->id]);

        $result = app(DischargeReviewTeacher::class)->handle($this->review, $teacher, $this->jefe);

        expect($result['deactivated'])->toBeTrue()
            ->and(inTeachersReview($teacher))->toBeFalse()
            ->and($teacher->fresh()->is_active)->toBeFalse()
            ->and($teacher->user->fresh()->is_active)->toBeFalse()
            ->and($this->review->fresh()->status)->toBe(ReviewStatus::InReview);
    });

    it('CV8: con otra adscripción solo sale de este departamento y sigue activo; su marca se quita (CV-S7)', function () {
        $teacher = Teacher::factory()->adscribedTo($this->sc)->adscribedTo($this->ii, primary: false)->create();
        CatalogReviewMark::factory()->for($this->review, 'review')->on($teacher)->create();

        $result = app(DischargeReviewTeacher::class)->handle($this->review, $teacher, $this->jefe);

        expect(CatalogReviewMark::query()->where('record_id', $teacher->id)->exists())->toBeFalse();

        expect($result['deactivated'])->toBeFalse()
            ->and(inTeachersReview($teacher))->toBeFalse()
            ->and($teacher->fresh()->is_active)->toBeTrue()
            ->and($teacher->adscriptions()->sole()->is_primary)->toBeTrue();
    });

    it('rechaza a un docente que no es de la revisión, y con la revisión validada', function () {
        $foreign = Teacher::factory()->adscribedTo($this->ii)->create();
        $own = Teacher::factory()->adscribedTo($this->sc)->create();

        expect(teachersErrors(fn () => app(DischargeReviewTeacher::class)->handle($this->review, $foreign, $this->jefe)))->toBe(['record' => ['El registro no es de esta revisión.']]);

        $this->review->update(['status' => ReviewStatus::Validated]);

        expect(teachersErrors(fn () => app(DischargeReviewTeacher::class)->handle($this->review, $own, $this->jefe)))->toHaveKey('status')
            ->and(inTeachersReview($own))->toBeTrue();
    });

    it('solo en la revisión de docentes', function () {
        $subjects = CatalogReview::factory()->general(Catalog::Subjects)->create(['term_id' => $this->term->id, 'department_id' => $this->sc->id]);
        $teacher = Teacher::factory()->adscribedTo($this->sc)->create();

        expect(teachersErrors(fn () => app(DischargeReviewTeacher::class)->handle($subjects, $teacher, $this->jefe)))->toBe(['record' => ['El registro no es de esta revisión.']]);
    });
});

describe('agregar docente (CV-S9)', function () {
    it('CV-S9: adscribe a un docente activo de otro departamento, sin cambiar su principal, y lo marca como revisado', function () {
        $teacher = Teacher::factory()->adscribedTo($this->ii)->create();

        app(AddReviewTeacher::class)->handle($this->review, $teacher, $this->jefe);

        expect(inTeachersReview($teacher))->toBeTrue()
            ->and($teacher->adscriptions()->where('department_id', $this->sc->id)->sole()->is_primary)->toBeFalse()
            ->and(CatalogReviewMark::query()->where('record_id', $teacher->id)->exists())->toBeTrue()
            ->and($this->review->fresh()->status)->toBe(ReviewStatus::InReview);
    });

    it('CV-S9: a uno dado de baja lo reactiva, con su usuario, y su adscripción queda como principal', function () {
        $user = User::factory()->create(['is_active' => false]);
        $teacher = Teacher::factory()->inactive()->create(['user_id' => $user->id]);

        app(AddReviewTeacher::class)->handle($this->review, $teacher, $this->jefe);

        expect($teacher->fresh()->is_active)->toBeTrue()
            ->and($user->fresh()->is_active)->toBeTrue()
            ->and($teacher->adscriptions()->sole()->is_primary)->toBeTrue();
    });

    it('CV-S9: al reactivarlo le quita la contraseña que tenía: vuelve a entrar solo con una invitación nueva (confirmado)', function () {
        $user = User::factory()->create(['is_active' => false, 'password' => 'una-clave-vieja-123', 'password_origin' => PasswordOrigin::User, 'access_invited_at' => now()->subDay()]);
        $teacher = Teacher::factory()->inactive()->create(['user_id' => $user->id]);

        app(AddReviewTeacher::class)->handle($this->review, $teacher, $this->jefe);

        expect($user->fresh())
            ->is_active->toBeTrue()
            ->password->toBeNull()
            ->password_origin->toBeNull()
            ->must_change_password->toBeFalse()
            ->access_invited_at->toBeNull()
            ->and(Activity::query()->where('event', 'access-revoked')->where('subject_id', $user->id)->sole()->causer->is($this->jefe))->toBeTrue();
    });

    it('a uno activo de otro departamento no le toca la contraseña', function () {
        $user = User::factory()->create(['password' => 'una-clave-vieja-123', 'password_origin' => PasswordOrigin::User]);
        $teacher = Teacher::factory()->adscribedTo($this->ii)->create(['user_id' => $user->id]);

        app(AddReviewTeacher::class)->handle($this->review, $teacher, $this->jefe);

        expect($user->fresh()->password)->not->toBeNull()
            ->and($user->fresh()->password_origin)->toBe(PasswordOrigin::User);
    });

    it('rechaza a uno que ya está en el departamento, y con la revisión validada', function () {
        $already = Teacher::factory()->adscribedTo($this->sc)->create();
        $other = Teacher::factory()->adscribedTo($this->ii)->create();

        expect(teachersErrors(fn () => app(AddReviewTeacher::class)->handle($this->review, $already, $this->jefe)))->toHaveKey('adscription');

        $this->review->update(['status' => ReviewStatus::Validated]);

        expect(teachersErrors(fn () => app(AddReviewTeacher::class)->handle($this->review, $other, $this->jefe)))->toHaveKey('status')
            ->and(inTeachersReview($other))->toBeFalse();
    });

    it('AC-09: el docente agregado y reactivado recibe su invitación al validar la revisión', function () {
        Notification::fake();
        $user = User::factory()->create(['username' => 'AAAA800101AA1', 'email' => null, 'password' => null, 'is_active' => false]);
        $teacher = Teacher::factory()->inactive()->create(['rfc' => 'AAAA800101AA1', 'institutional_email' => 'ana@cdjuarez.tecnm.mx', 'user_id' => $user->id]);

        app(AddReviewTeacher::class)->handle($this->review, $teacher, $this->jefe);

        Notification::assertNothingSent();

        validateAllReviewed($this->review->fresh(), $this->jefe);

        Notification::assertSentOnDemand(AccessInvitation::class, fn (AccessInvitation $invitation, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'ana@cdjuarez.tecnm.mx');
    });
});
