<?php

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Actions\AddReviewTeacher;
use App\Modules\CatalogValidation\Actions\CorrectRecord;
use App\Modules\CatalogValidation\Actions\DischargeReviewTeacher;
use App\Modules\CatalogValidation\Actions\SetReviewTeacherEmail;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\DischargeReason;
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

describe('correo institucional desde la fila (CV1, CV-S2, AC-06; D4 y D11)', function () {
    it('CV1: con la revisión abierta, guardar el correo es corregir: lo marca como revisado aunque no cambie (CV-S7)', function () {
        $teacher = Teacher::factory()->adscribedTo($this->sc)->create(['institutional_email' => 'ana@cdjuarez.tecnm.mx']);

        app(SetReviewTeacherEmail::class)->handle($this->review, $teacher, 'ana@cdjuarez.tecnm.mx', $this->jefe);

        expect(CatalogReviewMark::query()->where('record_id', $teacher->id)->exists())->toBeTrue()
            ->and($this->review->fresh()->status)->toBe(ReviewStatus::InReview);
    });

    it('CV-S2: con la revisión validada solo cambia el correo: sigue validada, sin marca nueva, y sale la invitación (AC-09)', function () {
        Notification::fake();
        $user = User::factory()->create(['username' => 'AAAA800101AA1', 'email' => null, 'password' => null, 'must_change_password' => true]);
        $teacher = Teacher::factory()->adscribedTo($this->sc)->create(['rfc' => 'AAAA800101AA1', 'institutional_email' => null, 'user_id' => $user->id]);
        $review = validateAllReviewed($this->review, $this->jefe);
        CatalogReviewMark::query()->delete();

        app(SetReviewTeacherEmail::class)->handle($review, $teacher, 'ana@cdjuarez.tecnm.mx', $this->jefe);

        expect($teacher->fresh()->institutional_email)->toBe('ana@cdjuarez.tecnm.mx')
            ->and($review->fresh())->status->toBe(ReviewStatus::Validated)->validated_by->toBe($this->jefe->id)
            ->and(CatalogReviewMark::query()->count())->toBe(0);

        Notification::assertSentOnDemand(AccessInvitation::class, fn (AccessInvitation $invitation, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'ana@cdjuarez.tecnm.mx');
    });

    it('AC-06: con la revisión validada también rechaza un correo de otro dominio o que ya tiene otro docente', function () {
        Teacher::factory()->create(['institutional_email' => 'ocupado@cdjuarez.tecnm.mx']);
        $teacher = Teacher::factory()->adscribedTo($this->sc)->create(['institutional_email' => null]);
        $review = validateAllReviewed($this->review, $this->jefe);

        expect(teachersErrors(fn () => app(SetReviewTeacherEmail::class)->handle($review, $teacher, 'ana@itcj.edu.mx', $this->jefe)))->toHaveKey('institutional_email')
            ->and(teachersErrors(fn () => app(SetReviewTeacherEmail::class)->handle($review, $teacher, 'OCUPADO@cdjuarez.tecnm.mx', $this->jefe)))->toHaveKey('institutional_email')
            ->and($teacher->fresh()->institutional_email)->toBeNull();
    });

    it('rechaza a un docente que no es de la revisión, también validada, y fuera de la revisión de docentes', function () {
        $foreign = Teacher::factory()->adscribedTo($this->ii)->create(['institutional_email' => null]);
        $subjects = CatalogReview::factory()->general(Catalog::Subjects)->create(['term_id' => $this->term->id]);
        $own = Teacher::factory()->adscribedTo($this->sc)->create(['institutional_email' => null]);

        expect(teachersErrors(fn () => app(SetReviewTeacherEmail::class)->handle($subjects, $own, 'ana@cdjuarez.tecnm.mx', $this->jefe)))->toBe(['record' => ['El registro no es de esta revisión.']]);

        $review = validateAllReviewed($this->review, $this->jefe);

        expect(teachersErrors(fn () => app(SetReviewTeacherEmail::class)->handle($review, $foreign, 'ana@cdjuarez.tecnm.mx', $this->jefe)))->toBe(['record' => ['El registro no es de esta revisión.']])
            ->and($foreign->fresh()->institutional_email)->toBeNull()
            ->and($own->fresh()->institutional_email)->toBeNull();
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
        $subjects = CatalogReview::factory()->general(Catalog::Subjects)->create(['term_id' => $this->term->id]);
        $teacher = Teacher::factory()->adscribedTo($this->sc)->create();

        expect(teachersErrors(fn () => app(DischargeReviewTeacher::class)->handle($subjects, $teacher, $this->jefe)))->toBe(['record' => ['El registro no es de esta revisión.']]);
    });

    it('CV8: deja en la bitácora del docente la baja con su motivo en `reason`, quién y en qué departamento', function (?DischargeReason $kind, ?string $text, ?array $reason) {
        $this->sc->update(['name' => 'Ciencias Básicas']);
        $teacher = Teacher::factory()->adscribedTo($this->sc)->create(['rfc' => 'AAAA800101AA1']);

        app(DischargeReviewTeacher::class)->handle($this->review, $teacher, $this->jefe, $kind, $text);

        $activity = Activity::query()->where('event', 'discharged')->sole();

        expect($activity->subject->is($teacher))->toBeTrue()
            ->and($activity->causer->is($this->jefe))->toBeTrue()
            ->and($activity->description)->toBe('Quitó al docente AAAA800101AA1 de Ciencias Básicas en la validación de docentes')
            ->and($activity->properties->except('acting_as')->all())->toBe(['review_id' => $this->review->id, 'department_id' => $this->sc->id, 'deactivated' => true, 'reason' => $reason]);
    })->with([
        'sin motivo' => [null, null, null],
        'jubilación' => [DischargeReason::Retirement, null, ['kind' => 'retirement']],
        'renuncia: el texto solo va con «Otro»' => [DischargeReason::Resignation, 'no va', ['kind' => 'resignation']],
        'cambio de departamento' => [DischargeReason::DepartmentChange, null, ['kind' => 'department_change']],
        'otro, con su texto' => [DischargeReason::Other, '  Se cambia de institución  ', ['kind' => 'other', 'text' => 'Se cambia de institución']],
    ]);

    it('CV8: con el motivo «Otro» pide el texto y no da de baja', function (?string $text) {
        $teacher = Teacher::factory()->adscribedTo($this->sc)->create();

        expect(teachersErrors(fn () => app(DischargeReviewTeacher::class)->handle($this->review, $teacher, $this->jefe, DischargeReason::Other, $text)))->toBe(['reason' => ['Escribe el motivo.']])
            ->and(inTeachersReview($teacher))->toBeTrue()
            ->and(Activity::query()->where('event', 'discharged')->exists())->toBeFalse();
    })->with(['sin texto' => [null], 'en blanco' => ['   ']]);
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
