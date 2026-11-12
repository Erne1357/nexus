<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Actions\SendAccessInvitation;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\AccessInvitation;
use App\Modules\Faculty\Actions\InviteTeacherAccess;
use App\Modules\Faculty\Actions\UpdateTeacher;
use App\Modules\Faculty\Models\Teacher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| El correo institucional del docente (AC-06) y su invitación de acceso desde Faculty (AC-07): lo captura en la ficha
| el jefe de un departamento del docente o el admin, y el de un docente con otro puesto, solo el admin; solo del
| dominio institucional y único sin importar mayúsculas. Cambiarlo invalida la invitación que tenía. «Reenviar
| invitación» es del jefe de su departamento y del admin. A quien tiene otro puesto no se le invita.
*/

beforeEach(function () {
    Notification::fake();
    $this->sc = Department::factory()->create(['code' => 'SC']);
    $this->cb = Department::factory()->create(['code' => 'CB']);
    $this->user = User::factory()->withRole(Role::Docente)->create(['username' => 'PEAA800101XX1', 'email' => null, 'password' => null, 'must_change_password' => true]);
    $this->teacher = Teacher::factory()->adscribedTo($this->sc)->create(['rfc' => 'PEAA800101XX1', 'name' => 'Ana Pérez', 'user_id' => $this->user->id]);
    $this->admin = User::factory()->admin()->create();
    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sc->id])->create();
    $this->jefeCb = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->cb->id])->create();
    $this->subdireccion = User::factory()->withRole(Role::Subdireccion)->create();
    $this->jpd = User::factory()->withRole(Role::JefeProyectoDocente, ['department_id' => $this->sc->id, 'campus_id' => Campus::factory()->create()->id])->create();
});

/**
 * El usuario del docente es además jefe de otro departamento: tiene un puesto distinto de docente, vigente.
 */
function makeTeacherHeadElsewhere(): void
{
    RoleAssignment::factory()->for(test()->user)->create(['role' => Role::JefeDepartamento, 'org_unit_id' => Department::factory()->create()->id, 'valid_from' => today()->subMonth()->toDateString()]);
}

describe('correo institucional', function () {
    it('AC-06: se guarda sin espacios y en minúsculas', function () {
        app(UpdateTeacher::class)->handle($this->teacher, ['institutional_email' => '  Ana.Perez@CDJuarez.TecNM.mx ']);

        expect($this->teacher->fresh()->institutional_email)->toBe('ana.perez@cdjuarez.tecnm.mx');
    });

    it('AC-06: solo acepta el dominio institucional configurado', function (string $email) {
        expect(fn () => app(UpdateTeacher::class)->handle($this->teacher, ['institutional_email' => $email]))
            ->toThrow(ValidationException::class, 'El correo institucional debe terminar en @cdjuarez.tecnm.mx.');
    })->with(['ana@itcj.edu.mx', 'ana@gmail.com', 'ana@cdjuarez.tecnm.mx.evil.test', 'no-es-correo']);

    it('AC-06: el dominio sale de la configuración', function () {
        config(['nexus.institutional_email_domain' => 'otro.tecnm.mx']);

        app(UpdateTeacher::class)->handle($this->teacher, ['institutional_email' => 'ana@otro.tecnm.mx']);

        expect($this->teacher->fresh()->institutional_email)->toBe('ana@otro.tecnm.mx');
    });

    it('AC-06: dos docentes no comparten el correo, sin importar mayúsculas, aunque uno esté borrado', function () {
        $other = Teacher::factory()->create(['institutional_email' => 'ana.perez@cdjuarez.tecnm.mx']);
        $other->delete();

        expect(fn () => app(UpdateTeacher::class)->handle($this->teacher, ['institutional_email' => 'ANA.PEREZ@cdjuarez.tecnm.mx']))
            ->toThrow(ValidationException::class, 'Otro docente ya tiene el correo institucional ana.perez@cdjuarez.tecnm.mx.');

        expect(fn () => DB::table('teachers')->where('id', $this->teacher->id)->update(['institutional_email' => 'Ana.Perez@cdjuarez.tecnm.mx']))
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('AC-07: cambiar el correo de un docente invitado le manda la invitación al correo nuevo', function () {
        $this->teacher->update(['institutional_email' => 'ana.perez@cdjuarez.tecnm.mx']);
        app(SendAccessInvitation::class)->handle($this->user, 'ana.perez@cdjuarez.tecnm.mx');
        $first = $this->user->fresh()->access_invited_at;

        app(UpdateTeacher::class)->handle($this->teacher, ['institutional_email' => 'ana.perez2@cdjuarez.tecnm.mx']);

        expect($this->user->fresh())->email->toBe('ana.perez2@cdjuarez.tecnm.mx')
            ->and($this->user->fresh()->access_invited_at->gt($first))->toBeTrue();
        Notification::assertSentOnDemand(AccessInvitation::class, fn (AccessInvitation $invitation, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'ana.perez2@cdjuarez.tecnm.mx');
    });

    it('AC-07: borrar el correo de un docente invitado deja sin efecto su invitación; cambiar solo mayúsculas no la toca', function () {
        $this->teacher->update(['institutional_email' => 'ana.perez@cdjuarez.tecnm.mx']);
        app(SendAccessInvitation::class)->handle($this->user, 'ana.perez@cdjuarez.tecnm.mx');

        app(UpdateTeacher::class)->handle($this->teacher, ['institutional_email' => 'ANA.PEREZ@cdjuarez.tecnm.mx']);

        expect($this->user->fresh()->access_invited_at)->not->toBeNull();

        app(UpdateTeacher::class)->handle($this->teacher, ['institutional_email' => '']);

        expect($this->user->fresh()->access_invited_at)->toBeNull()
            ->and(Activity::where('event', 'access-invitation-cancelled')->where('subject_id', $this->user->id)->exists())->toBeTrue();
        Notification::assertSentOnDemandTimes(AccessInvitation::class, 1);
    });

    it('el jefe lo captura en «Editar datos» y la ficha lo muestra', function () {
        $page = Livewire::actingAs($this->jefe)->test('faculty::pages.teacher', ['teacher' => $this->teacher->id])
            ->call('editData')
            ->set('institutional_email', 'ana.perez@cdjuarez.tecnm.mx')
            ->call('saveData')
            ->assertHasNoErrors();

        expect($this->teacher->fresh()->institutional_email)->toBe('ana.perez@cdjuarez.tecnm.mx')
            ->and(lastToastOf($page))->type->toBe('success');

        $this->actingAs($this->jefe)->get(route('faculty.teachers.show', $this->teacher->id))->assertSee('ana.perez@cdjuarez.tecnm.mx');
    });

    it('AC-06: la Subdirección y el jefe de proyecto docente editan los datos, pero no el correo institucional', function (string $who) {
        $dom = domOf(Livewire::actingAs($this->{$who})->test('faculty::pages.teacher', ['teacher' => $this->teacher->id])->call('editData')->html());

        expect($dom->querySelector('input[name="institutional_email"]')?->hasAttribute('disabled'))->toBeTrue();

        Livewire::actingAs($this->{$who})->test('faculty::pages.teacher', ['teacher' => $this->teacher->id])
            ->call('editData')
            ->set('institutional_email', 'otro@cdjuarez.tecnm.mx')
            ->call('saveData')
            ->assertHasErrors(['institutional_email']);

        expect($this->teacher->fresh()->institutional_email)->toBeNull();
    })->with(['subdireccion', 'jpd']);

    it('AC-06: el correo institucional de un docente que es jefe en otro lado solo lo cambia el admin, y a él no se le invita (AC-07)', function () {
        makeTeacherHeadElsewhere();

        Livewire::actingAs($this->jefe)->test('faculty::pages.teacher', ['teacher' => $this->teacher->id])
            ->call('editData')
            ->set('institutional_email', 'buzon.ajeno@cdjuarez.tecnm.mx')
            ->call('saveData')
            ->assertHasErrors(['institutional_email']);

        expect($this->teacher->fresh()->institutional_email)->toBeNull();

        Livewire::actingAs($this->admin)->test('faculty::pages.teacher', ['teacher' => $this->teacher->id])
            ->call('editData')
            ->set('institutional_email', 'ana.perez@cdjuarez.tecnm.mx')
            ->call('saveData')
            ->assertHasNoErrors();

        expect($this->teacher->fresh()->institutional_email)->toBe('ana.perez@cdjuarez.tecnm.mx');
        expect(fn () => app(InviteTeacherAccess::class)->handle($this->teacher->fresh()))->toThrow(ValidationException::class);
        Notification::assertNothingSent();
    });

    it('AC-07: si el docente invitado pasa a tener otro puesto, cambiarle el correo deja sin efecto la invitación en vez de reenviarla', function () {
        $this->teacher->update(['institutional_email' => 'ana.perez@cdjuarez.tecnm.mx']);
        app(SendAccessInvitation::class)->handle($this->user, 'ana.perez@cdjuarez.tecnm.mx');
        makeTeacherHeadElsewhere();

        app(UpdateTeacher::class)->handle($this->teacher, ['institutional_email' => 'ana.p@cdjuarez.tecnm.mx']);

        expect($this->user->fresh()->access_invited_at)->toBeNull();
        Notification::assertSentOnDemandTimes(AccessInvitation::class, 1);
    });

    it('un correo de otro dominio sale como error en su campo', function () {
        Livewire::actingAs($this->jefe)->test('faculty::pages.teacher', ['teacher' => $this->teacher->id])
            ->call('editData')
            ->set('institutional_email', 'ana@itcj.edu.mx')
            ->call('saveData')
            ->assertHasErrors(['institutional_email']);

        expect($this->teacher->fresh()->institutional_email)->toBeNull();
    });
});

describe('invitar', function () {
    it('AC-07: invita al docente activo sin acceso a su correo institucional', function () {
        $this->teacher->update(['institutional_email' => 'ana.perez@cdjuarez.tecnm.mx']);

        app(InviteTeacherAccess::class)->handle($this->teacher, $this->admin);

        Notification::assertSentOnDemand(AccessInvitation::class, fn (AccessInvitation $invitation, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'ana.perez@cdjuarez.tecnm.mx');
        expect($this->user->fresh()->email)->toBe('ana.perez@cdjuarez.tecnm.mx');
    });

    it('AC-07: sin correo institucional, con acceso, inactivo o sin usuario no invita', function (Closure $spoil, string $message) {
        $this->teacher->update(['institutional_email' => 'ana.perez@cdjuarez.tecnm.mx']);
        $spoil($this);

        expect(fn () => app(InviteTeacherAccess::class)->handle($this->teacher->fresh()))->toThrow(ValidationException::class, $message);

        Notification::assertNothingSent();
    })->with([
        'sin correo institucional' => [fn ($test) => $test->teacher->update(['institutional_email' => null]), 'Falta su correo institucional.'],
        'con acceso' => [fn ($test) => $test->user->forceFill(['password' => 'otra-clave-123'])->save(), 'Ya tiene acceso.'],
        'inactivo' => [fn ($test) => $test->teacher->update(['is_active' => false]), 'El docente está inactivo.'],
        'sin usuario' => [fn ($test) => $test->teacher->update(['user_id' => null]), 'El docente no tiene usuario.'],
    ]);
});

describe('reenviar invitación en la ficha', function () {
    beforeEach(function () {
        $this->teacher->update(['institutional_email' => 'ana.perez@cdjuarez.tecnm.mx']);
        app(SendAccessInvitation::class)->handle($this->user, 'ana.perez@cdjuarez.tecnm.mx');
        $this->travel(1)->minutes();
    });

    it('AC-07: el jefe de su departamento y el admin la reenvían a su correo institucional', function (string $who) {
        $before = $this->user->fresh()->access_invited_at;

        $page = Livewire::actingAs($this->{$who})->test('faculty::pages.teacher', ['teacher' => $this->teacher->id])->call('resendInvitation');

        expect(lastToastOf($page))->type->toBe('success')->title->toBe('Invitación reenviada')
            ->and($this->user->fresh()->access_invited_at->gt($before))->toBeTrue();
        Notification::assertSentOnDemandTimes(AccessInvitation::class, 2);
    })->with(['jefe', 'admin']);

    it('AC-07: a la Subdirección y al jefe de proyecto docente les sale deshabilitado con su motivo y reciben 403; el jefe de otro departamento, 403', function () {
        foreach (['subdireccion', 'jpd'] as $who) {
            $dom = domOf($this->actingAs($this->{$who})->get(route('faculty.teachers.show', $this->teacher->id))->assertOk());

            expect(textOf($dom->querySelector('[data-testid="resend-invitation"]')))->toBe('Reenviar invitación Solo su jefe de departamento o el administrador la reenvían.');

            Livewire::actingAs($this->{$who})->test('faculty::pages.teacher', ['teacher' => $this->teacher->id])->call('resendInvitation')->assertForbidden();
        }

        $page = Livewire::actingAs($this->admin)->test('faculty::pages.teacher', ['teacher' => $this->teacher->id]);
        $this->actingAs($this->jefeCb);
        $page->call('resendInvitation')->assertForbidden();

        Notification::assertSentOnDemandTimes(AccessInvitation::class, 1);
    });

    it('AC-07: sin invitación previa no se reenvía: sale con la validación del catálogo', function () {
        $this->user->forceFill(['access_invited_at' => null])->save();

        $dom = domOf($this->actingAs($this->jefe)->get(route('faculty.teachers.show', $this->teacher->id))->assertOk());
        $item = $dom->querySelector('[data-testid="resend-invitation"]');

        expect(textOf($item))->toBe('Reenviar invitación Sale cuando su departamento valida el catálogo de docentes.')
            ->and($item->getAttribute('aria-disabled'))->toBe('true');
    });
});
