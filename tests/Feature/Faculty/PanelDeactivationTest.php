<?php

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Actions\DeactivateUser;
use App\Modules\Core\Actions\ReactivateUser;
use App\Modules\Core\Enums\DeactivationReason;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\AccessInvitation;
use App\Modules\Core\Queries\DeactivationPreviewQuery;
use App\Modules\Core\Queries\UnitAdscriptions;
use App\Modules\Faculty\Actions\AddAdscription;
use App\Modules\Faculty\Actions\ProvisionTeacherUsers;
use App\Modules\Faculty\Actions\SyncTeacherAccess;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Students\Actions\SyncStudentAccounts;
use App\Modules\Students\Models\Student;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

/*
| CF-09 (confirmado el 14 de noviembre de 2026): la baja que el admin da en el panel se respeta. El docente sigue en el
| catálogo, pero ningún camino que sincroniza su acceso (SyncTeacherAccess: asignaciones, «Dar de alta usuarios»,
| validar el catálogo) le reactiva la cuenta (`users.deactivated_at`), así que tampoco le llega una invitación (AC-09).
| Tampoco la migración del SII a quien además es alumno. Solo el admin la reactiva desde el panel (CF-06).
*/

beforeEach(function () {
    $this->travelTo('2026-11-14 10:00:00');
    Notification::fake();

    $this->term = Term::factory()->current()->create();
    Campus::factory()->create(['code' => 'C2']);
    $this->sc = Department::factory()->create(['code' => 'SC']);
    $this->cb = Department::factory()->create(['code' => 'CB']);
    $this->admin = User::factory()->admin()->create();
    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sc->id])->create();

    $this->teacher = panelTeacher($this->sc, 'AAAA800101AA1', 'ana@cdjuarez.tecnm.mx');
    $this->account = $this->teacher->user;

    $this->actingAs($this->admin);
    app(DeactivateUser::class)->handle($this->account, DeactivationReason::Renuncia, null, $this->admin);
});

/**
 * Un docente activo de `$department`, con su usuario sin contraseña y su nombramiento de docente.
 */
function panelTeacher(Department $department, string $rfc, string $email): Teacher
{
    $account = User::factory()->create(['username' => $rfc, 'email' => null, 'password' => null, 'must_change_password' => true]);
    $teacher = Teacher::factory()->adscribedTo($department)->create(['rfc' => $rfc, 'institutional_email' => $email, 'user_id' => $account->id]);

    app(SyncTeacherAccess::class)->handle($teacher);

    return $teacher->fresh();
}

function invitedTo(): array
{
    $emails = [];

    Notification::assertSentOnDemand(AccessInvitation::class, function (AccessInvitation $invitation, array $channels, AnonymousNotifiable $notifiable) use (&$emails): bool {
        $emails[] = $notifiable->routes['mail'];

        return true;
    });

    return $emails;
}

it('CF-09: sincronizar al docente no reactiva la cuenta que el admin dio de baja, y el docente sigue en el catálogo', function () {
    app(SyncTeacherAccess::class)->handle($this->teacher);
    app(AddAdscription::class)->handle($this->teacher->fresh(), $this->cb);

    $account = $this->account->fresh();

    expect($account->is_active)->toBeFalse()
        ->and($account->deactivated_at)->not->toBeNull()
        ->and($this->teacher->fresh()->is_active)->toBeTrue()
        ->and($account->roleAssignments()->where('role', Role::Docente)->notEnded()->exists())->toBeTrue();
});

it('CF-09: «Dar de alta usuarios» (ProvisionTeacherUsers) no la reactiva ni la invita', function () {
    app(ProvisionTeacherUsers::class)->handle($this->admin);

    expect($this->account->fresh()->is_active)->toBeFalse();
    Notification::assertNothingSent();
});

it('CF-09 y AC-09: validar el catálogo de docentes invita a los demás, pero no a la cuenta dada de baja', function () {
    panelTeacher($this->sc, 'BBBB800101BB1', 'beto@cdjuarez.tecnm.mx');
    $review = CatalogReview::factory()->create(['term_id' => $this->term->id, 'department_id' => $this->sc->id, 'catalog' => Catalog::Teachers]);

    validateAllReviewed($review, $this->jefe);

    expect(invitedTo())->toBe(['beto@cdjuarez.tecnm.mx'])
        ->and($this->account->fresh()->is_active)->toBeFalse();
});

it('CF-09: la migración del SII no reactiva ni le da su NIP a quien también es alumno', function () {
    Student::factory()->create(['control_number' => '21110001', 'user_id' => $this->account->id]);

    app(SyncStudentAccounts::class)->handle(['21110001' => '4821']);

    expect($this->account->fresh()->is_active)->toBeFalse()
        ->and($this->account->fresh()->password)->toBeNull();
});

it('CF-09: la vista previa de la baja lee de Faculty solo los departamentos de esa persona', function () {
    $other = panelTeacher($this->sc, 'CCCC800101CC1', 'cora@cdjuarez.tecnm.mx');
    app(AddAdscription::class)->handle($other, $this->cb);
    panelTeacher($this->cb, 'DDDD800101DD1', 'dani@cdjuarez.tecnm.mx');

    expect(app(DeactivationPreviewQuery::class)->for($other->user, $this->admin)->teacherDepartments)
        ->toBe([['code' => 'CB', 'name' => $this->cb->name], ['code' => 'SC', 'name' => $this->sc->name]])
        ->and(app(UnitAdscriptions::class)->unitIdsOf($this->jefe->id))->toBe([]);
});

it('CF-06: solo el admin la reactiva desde el panel; después, Faculty la vuelve a sincronizar', function () {
    app(ReactivateUser::class)->handle($this->account, $this->admin);

    expect($this->account->fresh()->is_active)->toBeTrue()
        ->and($this->account->fresh()->deactivated_at)->toBeNull();

    $this->teacher->forceFill(['is_active' => false])->save();
    app(SyncTeacherAccess::class)->handle($this->teacher);
    expect($this->account->fresh()->is_active)->toBeFalse();

    $this->teacher->forceFill(['is_active' => true])->save();
    app(SyncTeacherAccess::class)->handle($this->teacher);
    expect($this->account->fresh()->is_active)->toBeTrue();
});
