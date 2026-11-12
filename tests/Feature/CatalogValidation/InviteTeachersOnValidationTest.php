<?php

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Actions\ValidateReview;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Actions\SendAccessInvitation;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\AccessInvitation;
use App\Modules\Faculty\Actions\AddAdscription;
use App\Modules\Faculty\Actions\SetTeacherActive;
use App\Modules\Faculty\Actions\SyncTeacherAccess;
use App\Modules\Faculty\Actions\UpdateTeacher;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| AC-09: cuando el jefe valida la revisión de docentes de su departamento, cada docente activo adscrito a él que no
| tiene acceso ni una invitación vigente recibe la suya en su correo institucional (decisión 3, confirmada el 11 de
| noviembre de 2026). Supuestos por confirmar: validar no se bloquea por los que no tienen correo institucional (el
| aviso dice cuántos); un docente en dos departamentos se invita con la primera validación; y si el jefe captura el
| correo después de validar, la invitación sale en ese momento.
*/

beforeEach(function () {
    Notification::fake();
    $this->term = Term::factory()->current()->create();
    Campus::factory()->create(['code' => 'C2']);
    $this->sc = Department::factory()->create(['code' => 'SC']);
    $this->cb = Department::factory()->create(['code' => 'CB']);
    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sc->id])->create();
    $this->review = CatalogReview::factory()->create(['term_id' => $this->term->id, 'department_id' => $this->sc->id, 'catalog' => Catalog::Teachers]);
});

/**
 * Un docente activo de `$department` con usuario sin contraseña y, si se da, correo institucional.
 *
 * @param  array<string, mixed>  $user
 */
function teacherWithoutAccess(Department $department, string $rfc, ?string $email, array $user = []): Teacher
{
    $account = User::factory()->create(['username' => $rfc, 'email' => null, 'password' => null, 'must_change_password' => true, ...$user]);

    return Teacher::factory()->adscribedTo($department)->create(['rfc' => $rfc, 'institutional_email' => $email, 'user_id' => $account->id]);
}

/**
 * Los correos a los que se mandó una invitación, en orden.
 *
 * @return list<string>
 */
function invitedEmails(): array
{
    $emails = [];

    Notification::assertSentOnDemand(AccessInvitation::class, function (AccessInvitation $invitation, array $channels, AnonymousNotifiable $notifiable) use (&$emails): bool {
        $emails[] = $notifiable->routes['mail'];

        return true;
    });

    sort($emails);

    return $emails;
}

it('AC-09: validar la revisión de docentes invita a los activos del departamento sin acceso ni invitación vigente, con correo institucional', function () {
    teacherWithoutAccess($this->sc, 'AAAA800101AA1', 'ana@cdjuarez.tecnm.mx');
    teacherWithoutAccess($this->sc, 'BBBB800101BB1', null);
    teacherWithoutAccess($this->sc, 'CCCC800101CC1', 'cora@cdjuarez.tecnm.mx', ['password' => 'otra-clave-123']);
    teacherWithoutAccess($this->sc, 'DDDD800101DD1', 'dani@cdjuarez.tecnm.mx')->update(['is_active' => false]);
    teacherWithoutAccess($this->cb, 'EEEE800101EE1', 'eli@cdjuarez.tecnm.mx');
    teacherWithoutAccess($this->sc, 'FFFF800101FF1', 'fer@cdjuarez.tecnm.mx', ['access_invited_at' => now()->subDays(2)]);
    teacherWithoutAccess($this->sc, 'GGGG800101GG1', 'gil@cdjuarez.tecnm.mx', ['access_invited_at' => now()->subDays(8)]);

    app(ValidateReview::class)->handle($this->review, $this->jefe);

    $log = Activity::where('event', 'teachers-invited')->sole();

    expect(invitedEmails())->toBe(['ana@cdjuarez.tecnm.mx', 'gil@cdjuarez.tecnm.mx'])
        ->and($log->subject->is($this->review))->toBeTrue()
        ->and($log->causer_id)->toBe($this->jefe->id)
        ->and($log->properties['invited'])->toBe(2);
});

it('AC-09: validar otro catálogo no invita a nadie', function () {
    teacherWithoutAccess($this->sc, 'AAAA800101AA1', 'ana@cdjuarez.tecnm.mx');
    $subjects = CatalogReview::factory()->general(Catalog::Subjects)->create(['term_id' => $this->term->id, 'department_id' => $this->sc->id]);

    app(ValidateReview::class)->handle($subjects, $this->jefe);

    Notification::assertNothingSent();
});

it('AC-09: un docente en dos departamentos se invita con la primera validación', function () {
    $teacher = teacherWithoutAccess($this->sc, 'AAAA800101AA1', 'ana@cdjuarez.tecnm.mx');
    TeacherDepartment::factory()->for($teacher)->for($this->cb)->create(['is_primary' => false]);
    $jefeCb = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->cb->id])->create();
    $other = CatalogReview::factory()->create(['term_id' => $this->term->id, 'department_id' => $this->cb->id, 'catalog' => Catalog::Teachers]);

    app(ValidateReview::class)->handle($this->review, $this->jefe);
    app(ValidateReview::class)->handle($other, $jefeCb);

    Notification::assertSentOnDemandTimes(AccessInvitation::class, 1);
});

it('AC-09: si una invitación falla, sigue con los demás y la falla queda en la bitácora', function () {
    User::factory()->create(['email' => 'ana@cdjuarez.tecnm.mx']);
    teacherWithoutAccess($this->sc, 'AAAA800101AA1', 'ana@cdjuarez.tecnm.mx');
    teacherWithoutAccess($this->sc, 'BBBB800101BB1', 'beto@cdjuarez.tecnm.mx');

    app(ValidateReview::class)->handle($this->review, $this->jefe);

    $log = Activity::where('event', 'teachers-invited')->sole();

    expect(invitedEmails())->toBe(['beto@cdjuarez.tecnm.mx'])
        ->and($log->properties['failed'])->toBe(['AAAA800101AA1' => 'Otro usuario ya tiene el correo ana@cdjuarez.tecnm.mx.']);
});

it('AC-09: capturar el correo institucional después de validar manda la invitación en ese momento; antes, no', function () {
    $teacher = teacherWithoutAccess($this->sc, 'AAAA800101AA1', null);
    $this->actingAs($this->jefe);

    app(UpdateTeacher::class)->handle($teacher, ['institutional_email' => 'ana@cdjuarez.tecnm.mx']);

    Notification::assertNothingSent();

    app(UpdateTeacher::class)->handle($teacher, ['institutional_email' => '']);
    app(ValidateReview::class)->handle($this->review, $this->jefe);
    app(UpdateTeacher::class)->handle($teacher, ['institutional_email' => 'ana@cdjuarez.tecnm.mx']);

    expect(invitedEmails())->toBe(['ana@cdjuarez.tecnm.mx']);
});

it('AC-09: el docente que queda en condiciones después de validar se invita a nombre de quien hizo el cambio', function (Closure $after) {
    $teacher = teacherWithoutAccess($this->cb, 'AAAA800101AA1', 'ana@cdjuarez.tecnm.mx');
    app(ValidateReview::class)->handle($this->review, $this->jefe);
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $after($teacher, $this);

    $log = Activity::where('event', 'access-invited')->sole();

    expect(invitedEmails())->toBe(['ana@cdjuarez.tecnm.mx'])
        ->and($log->causer_id)->toBe($admin->id);
})->with([
    'adscrito al departamento' => [fn (Teacher $teacher, $test) => app(AddAdscription::class)->handle($teacher, $test->sc)],
    'reactivado' => [function (Teacher $teacher) {
        app(SetTeacherActive::class)->handle($teacher, false);
        TeacherDepartment::factory()->for($teacher)->for(test()->sc)->create(['is_primary' => false]);
        app(SetTeacherActive::class)->handle($teacher->fresh(), true);
    }],
]);

it('AC-09: al docente que recibe su usuario después de validar lo invita, a nombre de quien validó', function () {
    $teacher = Teacher::factory()->adscribedTo($this->sc)->create(['rfc' => 'AAAA800101AA1', 'institutional_email' => 'ana@cdjuarez.tecnm.mx']);
    app(ValidateReview::class)->handle($this->review, $this->jefe);

    Notification::assertNothingSent();

    app(SyncTeacherAccess::class)->handle($teacher);

    expect(invitedEmails())->toBe(['ana@cdjuarez.tecnm.mx'])
        ->and(Activity::where('event', 'access-invited')->sole()->causer_id)->toBe($this->jefe->id);
});

it('AC-09: un periodo ya terminado no cuenta para invitar después', function () {
    $this->term->update(['starts_on' => today()->subYear()->toDateString(), 'ends_on' => today()->subDay()->toDateString()]);
    $teacher = teacherWithoutAccess($this->sc, 'AAAA800101AA1', null);
    $this->review->forceFill(['status' => ReviewStatus::Validated, 'validated_by' => $this->jefe->id, 'validated_at' => now()->subMonth()])->save();
    $this->actingAs($this->jefe);

    app(UpdateTeacher::class)->handle($teacher, ['institutional_email' => 'ana@cdjuarez.tecnm.mx']);

    Notification::assertNothingSent();
});

it('AC-09: cambiar el correo de un docente ya invitado no le manda dos invitaciones', function () {
    $teacher = teacherWithoutAccess($this->sc, 'AAAA800101AA1', 'ana@cdjuarez.tecnm.mx');
    app(ValidateReview::class)->handle($this->review, $this->jefe);
    $this->actingAs($this->jefe);

    app(UpdateTeacher::class)->handle($teacher, ['institutional_email' => 'ana.p@cdjuarez.tecnm.mx']);

    expect(invitedEmails())->toBe(['ana.p@cdjuarez.tecnm.mx', 'ana@cdjuarez.tecnm.mx']);
});

it('AC-09: antes de validar, el aviso dice cuántos docentes activos sin acceso no tienen correo institucional y cuántos no tienen usuario activo', function () {
    teacherWithoutAccess($this->sc, 'AAAA800101AA1', 'ana@cdjuarez.tecnm.mx');
    teacherWithoutAccess($this->sc, 'BBBB800101BB1', null);
    teacherWithoutAccess($this->sc, 'CCCC800101CC1', null);
    teacherWithoutAccess($this->sc, 'DDDD800101DD1', null, ['password' => 'otra-clave-123']);
    teacherWithoutAccess($this->sc, 'EEEE800101EE1', null)->update(['is_active' => false]);
    teacherWithoutAccess($this->sc, 'FFFF800101FF1', 'fer@cdjuarez.tecnm.mx', ['is_active' => false]);
    Teacher::factory()->adscribedTo($this->sc)->create(['rfc' => 'GGGG800101GG1', 'institutional_email' => 'gil@cdjuarez.tecnm.mx']);

    Livewire::withQueryParams(['department' => $this->sc->id])
        ->actingAs($this->jefe)
        ->test('catalog-validation::pages.review')
        ->call('confirm', 'validate', 'teachers')
        ->assertSeeText('2 docentes activos sin correo institucional no recibirán su acceso.')
        ->assertSeeText('2 docentes activos no tienen un usuario activo y no recibirán su acceso; lo resuelve el administrador.');
});

it('AC-09: con todos los correos capturados, el aviso no sale', function () {
    teacherWithoutAccess($this->sc, 'AAAA800101AA1', 'ana@cdjuarez.tecnm.mx');

    Livewire::withQueryParams(['department' => $this->sc->id])
        ->actingAs($this->jefe)
        ->test('catalog-validation::pages.review')
        ->call('confirm', 'validate', 'teachers')
        ->assertDontSeeText('sin correo institucional')
        ->assertDontSeeText('usuario activo');
});

it('AC-09: una invitación ya enviada al validar no se repite al reenviar el mismo correo', function () {
    $teacher = teacherWithoutAccess($this->sc, 'AAAA800101AA1', 'ana@cdjuarez.tecnm.mx');
    app(SendAccessInvitation::class)->handle($teacher->user, 'ana@cdjuarez.tecnm.mx');

    app(ValidateReview::class)->handle($this->review, $this->jefe);

    Notification::assertSentOnDemandTimes(AccessInvitation::class, 1);
    expect(Activity::where('event', 'teachers-invited')->exists())->toBeFalse();
});
