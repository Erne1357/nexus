<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Actions\DeactivateUser;
use App\Modules\Core\Enums\DeactivationReason;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\AccessInvitation;
use App\Modules\Core\Notifications\PasswordResetLink;
use App\Modules\Core\Rules\UserAccountRules;
use App\Modules\Faculty\Enums\QualificationLevel;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherAvailability;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Faculty\Models\TeacherEvaluation;
use App\Modules\Faculty\Models\TeacherExternalLoad;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Faculty\Models\TeacherSubjectHistory;
use App\Modules\Faculty\Models\TeacherSubjectQualification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| Ficha del docente (`/faculty/teachers/{teacher}`, faculty::pages.teacher; spec 4.3 y S8). Cinco pestañas: Datos
| (datos, asignaciones: quitar y hacer principal), Plazas (agregar, editar y quitar), Disponibilidad (de solo
| lectura, con el enlace al editor), Perfil e Historial. Cada quien ve y cambia a los docentes de su alcance
| (TeacherPolicy); cada método vuelve a autorizar. «Restablecer contraseña» (por enlace) y la invitación del admin son
| solo del administrador (UserPolicy).
*/

beforeEach(function () {
    $this->sc = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->ii = Department::factory()->create(['code' => 'II', 'name' => 'Ingeniería Industrial']);
    $this->cb = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    $this->term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027', 'starts_on' => '2027-01-25', 'ends_on' => '2027-06-11']);

    $this->docente = User::factory()->withRole(Role::Docente)->create(['username' => '1000001']);
    $this->ana = Teacher::factory()->adscribedTo($this->ii)->create(['rfc' => '1000001', 'name' => 'Ana Pérez', 'email' => 'ana@example.test', 'user_id' => $this->docente->id]);
    TeacherDepartment::factory()->for($this->ana)->for($this->sc)->create(['is_primary' => false, 'created_at' => now()->addSecond()]);
    $this->plaza = TeacherPosition::factory()->for($this->ana)->tiempoCompleto()->create();
    $this->beto = Teacher::factory()->adscribedTo($this->ii)->create(['rfc' => '1000002', 'name' => 'Beto Ruiz']);

    $this->admin = User::factory()->admin()->create();
    $this->sin = Teacher::factory()->create(['rfc' => '1000003', 'name' => 'Elsa Mora']);
    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sc->id])->create();
    $this->jefeCb = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->cb->id])->create();
    $this->subdireccion = User::factory()->withRole(Role::Subdireccion)->create();
    $this->activityBase = (int) Activity::max('id');
});

function teacherRecord(User $user, Teacher $teacher): Testable
{
    return Livewire::actingAs($user)->test('faculty::pages.teacher', ['teacher' => $teacher->id]);
}

describe('ficha', function () {
    it('muestra encabezado, horas base del periodo y las cinco pestañas', function () {
        $this->actingAs($this->admin)->get(route('faculty.teachers.show', $this->ana->id))
            ->assertOk()
            ->assertSeeInOrder(['Ana Pérez', '1000001', 'Horas base', 'Ene-Jun 2027', '40', 'Datos', 'Plazas', 'Disponibilidad', 'Perfil', 'Historial']);
    });

    it('en Datos muestra sus datos y sus asignaciones con la principal primero', function () {
        teacherRecord($this->admin, $this->ana)
            ->assertSeeInOrder(['RFC', '1000001', 'Correo institucional', 'Correo personal', 'ana@example.test', 'Usuario'])
            ->assertSeeInOrder(['Asignaciones', 'Ingeniería Industrial', 'Principal', 'Sistemas y Computación', 'Hacer principal']);
    });

    it('1.24.3: dice «Asignación» y «Plazas con las que cuenta», y ya no «Adscripción» ni «Plazas que cuentan»', function () {
        $page = teacherRecord($this->admin, $this->ana)->assertSee('Asignación principal')->assertSee('Asignaciones')->assertSee('Plazas con las que cuenta');

        foreach (['datos', 'plazas', 'disponibilidad', 'perfil', 'historial'] as $tab) {
            $html = teacherRecord($this->admin, $this->ana)->set('tab', $tab)->html();

            expect($html)->not->toContain('Adscripci')->not->toContain('Plazas que cuentan')->not->toContain('fuera del ITCJ');
        }

        expect($page->html())->not->toContain('Adscripci');
    });

    it('1.24.3: en Datos el correo institucional va primero y el otro se llama «Correo personal», también en el modal', function () {
        teacherRecord($this->admin, $this->ana)
            ->assertSeeInOrder(['Correo institucional', 'Correo personal'])
            ->call('editData')
            ->assertSeeInOrder(['Correo institucional', 'Correo personal']);
    });

    it('recuerda la pestaña en la URL y una que no existe muestra Datos', function () {
        teacherRecord($this->admin, $this->ana)->set('tab', 'plazas')->assertSee('Tiempo completo');
        teacherRecord($this->admin, $this->ana)->set('tab', 'nada')->assertSet('tab', 'datos');
    });

    it('no dice «horas extras» en ninguna pestaña', function (string $tab) {
        $page = teacherRecord($this->admin, $this->ana)->set('tab', $tab);

        expect(mb_strtolower($page->html()))->not->toContain('horas extra');
    })->with(['datos', 'plazas', 'disponibilidad', 'perfil', 'historial']);

    it('el nombre y los datos del docente se escapan', function () {
        $this->ana->update(['name' => '<script>alert(1)</script>']);

        teacherRecord($this->admin, $this->ana)->assertDontSeeHtml('<script>alert(1)</script>');
    });
});

describe('datos', function () {
    it('edita los datos del docente', function () {
        $page = teacherRecord($this->jefe, $this->ana)->call('editData')->set('name', 'Ana María Pérez')->set('email', 'am@example.test')->call('saveData');

        expect($this->ana->fresh())->name->toBe('Ana María Pérez')->email->toBe('am@example.test')
            ->and(lastToastOf($page))->type->toBe('success')->title->toBe('Datos actualizados');
    });

    it('desactiva y activa al docente (la baja es solo del admin y la Subdirección)', function () {
        $page = teacherRecord($this->subdireccion, $this->ana)->call('toggleActive');

        expect($this->ana->fresh()->is_active)->toBeFalse()
            ->and(lastToastOf($page))->title->toBe('Docente desactivado');

        $page->call('toggleActive');

        expect($this->ana->fresh()->is_active)->toBeTrue();
    });
});

describe('baja', function () {
    it('el jefe ve «Desactivar» deshabilitado con su motivo y recibe 403 al llamarlo', function () {
        $dom = domOf($this->actingAs($this->jefe)->get(route('faculty.teachers.show', $this->ana->id))->assertOk());
        $item = $dom->querySelector('[data-testid="toggle-active"]');

        expect($item->getAttribute('aria-disabled'))->toBe('true')
            ->and(textOf($item))->toContain('Solo el administrador y la Subdirección');

        teacherRecord($this->jefe, $this->ana)->call('toggleActive')->assertForbidden();

        expect($this->ana->fresh()->is_active)->toBeTrue();
    });

    it('el admin y la Subdirección la ven habilitada', function (string $user) {
        $dom = domOf($this->actingAs($this->{$user})->get(route('faculty.teachers.show', $this->ana->id))->assertOk());

        expect($dom->querySelector('[data-testid="toggle-active"]')->getAttribute('aria-disabled'))->toBeNull();
    })->with(['admin', 'subdireccion']);
});

describe('asignaciones', function () {
    it('quita una asignación después de confirmar y avisa', function () {
        $sc = $this->ana->adscriptions()->where('department_id', $this->sc->id)->sole();
        $page = teacherRecord($this->admin, $this->ana)->call('confirmRemoveAdscription', $sc->id);
        $page->assertSet('removingAdscriptionId', $sc->id)->assertSee('¿Quitar al docente');
        $page->call('removeAdscription');

        expect($sc->fresh()->trashed())->toBeTrue()
            ->and(lastToastOf($page))->type->toBe('success')->title->toBe('Quitado de Sistemas y Computación');
    });

    it('el jefe de SC que quita la de SC ya no cubre al docente: vuelve a la lista', function () {
        $sc = $this->ana->adscriptions()->where('department_id', $this->sc->id)->sole();

        teacherRecord($this->jefe, $this->ana)->call('confirmRemoveAdscription', $sc->id)->call('removeAdscription')->assertRedirect(route('faculty.teachers'));

        expect($sc->fresh()->trashed())->toBeTrue();
    });

    it('quitar la principal pasa la principal a la más antigua que queda, y el aviso lo dice', function () {
        $primary = $this->ana->adscriptions()->where('is_primary', true)->sole();
        $sc = $this->ana->adscriptions()->where('department_id', $this->sc->id)->sole();

        $page = teacherRecord($this->admin, $this->ana)->call('confirmRemoveAdscription', $primary->id)
            ->assertSee('su asignación principal pasa a Sistemas y Computación')
            ->call('removeAdscription');

        expect($primary->fresh()->trashed())->toBeTrue()
            ->and($sc->fresh()->is_primary)->toBeTrue()
            ->and(lastToastOf($page))->type->toBe('success')->description->toContain('principal pasa a SC');
    });

    it('CV8: quitar la única asignación da de baja al docente, y el aviso lo dice', function () {
        $page = teacherRecord($this->admin, $this->beto)->call('confirmRemoveAdscription', $this->beto->adscriptions()->sole()->id)
            ->assertSee('Es su último departamento: queda dado de baja y su cuenta se inhabilita.')
            ->call('removeAdscription');

        expect($this->beto->fresh()->is_active)->toBeFalse()
            ->and($this->beto->adscriptions()->count())->toBe(0)
            ->and(lastToastOf($page))->description->toContain('queda dado de baja y su cuenta se inhabilita');
    });

    it('al jefe de proyecto docente la última asignación se le ve deshabilitada con su motivo, y llamarla es 403 (confirmado)', function () {
        $beto = Teacher::factory()->adscribedTo($this->sc)->create(['rfc' => '1000009', 'name' => 'Beto Solo']);
        $jpd = User::factory()->withRole(Role::JefeProyectoDocente, ['campus_id' => Campus::factory()->create()->id, 'department_id' => $this->sc->id])->create();
        $adscription = $beto->adscriptions()->sole();

        teacherRecord($jpd, $beto)
            ->assertSee('Es su último departamento: lo quita el jefe de departamento.')
            ->call('confirmRemoveAdscription', $adscription->id)
            ->assertForbidden();

        expect($adscription->fresh()->trashed())->toBeFalse()
            ->and($beto->fresh()->is_active)->toBeTrue();

        teacherRecord($this->jefe, $beto)->call('confirmRemoveAdscription', $adscription->id)->assertSet('showRemoveAdscriptionModal', true);
    });

    it('CV-S8: a quien ocupa un puesto solo lo quitan el admin o la Subdirección', function () {
        $this->ana->update(['user_id' => User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->ii->id])->create()->id]);
        $sc = $this->ana->adscriptions()->where('department_id', $this->sc->id)->sole();

        teacherRecord($this->jefe, $this->ana)
            ->assertSee('Ocupa un puesto: lo quitan el administrador o la Subdirección.')
            ->call('confirmRemoveAdscription', $sc->id)
            ->assertForbidden();
        teacherRecord($this->admin, $this->ana)->call('confirmRemoveAdscription', $sc->id)->assertSet('showRemoveAdscriptionModal', true);
    });

    it('nadie se quita a sí mismo de un departamento (supuesto por confirmar)', function () {
        $this->ana->update(['user_id' => $this->jefe->id]);
        $sc = $this->ana->adscriptions()->where('department_id', $this->sc->id)->sole();

        teacherRecord($this->jefe, $this->ana)->call('confirmRemoveAdscription', $sc->id)->assertForbidden();

        expect($sc->fresh()->trashed())->toBeFalse();
    });

    it('lista las asignaciones sin campus, con «Quitar de <Departamento>» por renglón', function () {
        $page = teacherRecord($this->admin, $this->ana);

        $page->assertSee('Quitar de Ingeniería Industrial')->assertSee('Quitar de Sistemas y Computación')->assertDontSee(' en C2');
    });

    it('hace principal a otra y deja una sola', function () {
        $sc = $this->ana->adscriptions()->where('department_id', $this->sc->id)->sole();

        $page = teacherRecord($this->jefe, $this->ana)->call('setPrimary', $sc->id);

        expect($this->ana->adscriptions()->where('is_primary', true)->pluck('id')->all())->toBe([$sc->id])
            ->and(lastToastOf($page))->title->toBe('Asignación principal cambiada');
    });

    it('el jefe ve la asignación de otro departamento con «Quitar» deshabilitado y su motivo', function () {
        $dom = domOf($this->actingAs($this->jefe)->get(route('faculty.teachers.show', $this->ana->id))->assertOk());
        $buttons = collect($dom->querySelectorAll('[data-testid^="remove-adscription-"]'));

        expect($buttons)->toHaveCount(2)
            ->and($buttons->filter(fn ($button): bool => $button->hasAttribute('disabled'))->count())->toBe(1);
    });
});

describe('plazas', function () {
    it('agrega una plaza y las horas base suben', function () {
        $page = teacherRecord($this->jefe, $this->ana)->set('tab', 'plazas')->call('createPosition')
            ->set('kind', 'asignatura')->set('base_hours', '6')->set('valid_from', '2027-01-01')->set('valid_to', '')
            ->call('savePosition');

        expect($this->ana->positions()->count())->toBe(2)
            ->and(lastToastOf($page))->type->toBe('success')->title->toBe('Plaza agregada');
        $page->assertSeeInOrder(['Horas base en Ene-Jun 2027', '46']);
    });

    it('un tipo con horas fijas muestra sus horas y no las pide', function () {
        teacherRecord($this->jefe, $this->ana)->call('createPosition')->set('kind', 'medio_tiempo')->assertSet('base_hours', '20');
    });

    it('muestra los errores de la plaza junto a su campo, en español', function () {
        teacherRecord($this->jefe, $this->ana)->call('createPosition')
            ->set('kind', 'asignatura')->set('base_hours', '44')->set('valid_from', '2027-02-01')->set('valid_to', '2027-01-01')
            ->call('savePosition')
            ->assertHasErrors(['base_hours', 'valid_to'])
            ->assertSee('Las horas base de una plaza de asignatura van de 1 a 40.')
            ->assertSee('La vigencia no puede terminar antes de empezar.');

        expect($this->ana->positions()->count())->toBe(1);
    });

    it('edita una plaza', function () {
        $asignatura = TeacherPosition::factory()->for($this->ana)->asignatura(5)->create();
        $page = teacherRecord($this->jefe, $this->ana)->call('editPosition', $asignatura->id)->assertSet('base_hours', '5')->set('base_hours', '9')->call('savePosition');

        expect($asignatura->fresh()->base_hours)->toBe(9)
            ->and(lastToastOf($page))->title->toBe('Plaza actualizada');
    });

    it('quita una plaza después de confirmar', function () {
        $page = teacherRecord($this->jefe, $this->ana)->call('confirmRemovePosition', $this->plaza->id)->call('removePosition');

        expect($this->plaza->fresh()->trashed())->toBeTrue()
            ->and(lastToastOf($page))->title->toBe('Plaza quitada');
    });
});

describe('restablecer contraseña (CF-08)', function () {
    beforeEach(function () {
        Notification::fake();
        $this->docente->forceFill(['email' => 'ana.perez@cdjuarez.tecnm.mx', 'password' => 'otra-clave-123', 'must_change_password' => false])->save();
    });

    it('CF-08: el admin manda el enlace al correo de su usuario, sin la contraseña por defecto', function () {
        $before = $this->docente->fresh()->password;
        $item = domOf($this->actingAs($this->admin)->get(route('faculty.teachers.show', $this->ana->id))->assertOk())->querySelector('[data-testid="reset-password"]');

        expect(textOf($item))->toBe('Restablecer contraseña')
            ->and($item->getAttribute('x-confirm'))->toBe('Le llega a ana.perez@cdjuarez.tecnm.mx un enlace para elegir una contraseña nueva. Vale 60 minutos.');

        $page = teacherRecord($this->admin, $this->ana)->call('resetPassword');

        expect(lastToastOf($page))->type->toBe('success')->title->toBe('Enlace enviado')->description->toBe('Enlace enviado a ana.perez@cdjuarez.tecnm.mx. Vale 60 minutos.')
            ->and($this->docente->fresh()->password)->toBe($before);
        Notification::assertSentTo($this->docente, PasswordResetLink::class);
    });

    it('CF-08: sin correo institucional sale deshabilitado con su motivo y la llamada no manda nada', function () {
        $this->docente->forceFill(['email' => 'ana@example.test'])->save();

        $item = domOf($this->actingAs($this->admin)->get(route('faculty.teachers.show', $this->ana->id)))->querySelector('[data-testid="reset-password"]');

        expect(textOf($item))->toBe('Restablecer contraseña '.UserAccountRules::RESET_NO_EMAIL)
            ->and($item->getAttribute('aria-disabled'))->toBe('true');

        $page = teacherRecord($this->admin, $this->ana)->call('resetPassword');

        expect(lastToastOf($page))->type->toBe('error')->description->toBe(UserAccountRules::RESET_NO_EMAIL);
        Notification::assertNothingSent();
    });

    it('sin usuario no hay nada que restablecer', function () {
        $page = teacherRecord($this->admin, $this->beto)->call('resetPassword');

        expect(lastToastOf($page))->type->toBe('error')->description->toBe('El docente no tiene usuario.');
    });

    it('el jefe y la Subdirección lo ven deshabilitado con su motivo y reciben 403 al llamarlo', function (string $who) {
        $item = domOf($this->actingAs($this->{$who})->get(route('faculty.teachers.show', $this->ana->id))->assertOk())->querySelector('[data-testid="reset-password"]');

        expect(textOf($item))->toBe('Restablecer contraseña Solo el administrador restablece contraseñas.')
            ->and($item->getAttribute('aria-disabled'))->toBe('true');

        teacherRecord($this->{$who}, $this->ana)->call('resetPassword')->assertForbidden();
        Notification::assertNothingSent();
    })->with(['jefe', 'subdireccion']);
});

describe('acceso (AC-04, CF-07 y CF-09)', function () {
    it('AC-04: la sección Datos muestra el estado del acceso del usuario del docente', function (?string $password, bool $mustChange, string $label) {
        $this->docente->forceFill(['password' => $password, 'must_change_password' => $mustChange])->save();

        $dom = domOf($this->actingAs($this->admin)->get(route('faculty.teachers.show', $this->ana->id))->assertOk());

        expect(textOf($dom->querySelector('[data-testid="user-access"]')))->toBe($label);
    })->with([
        'sin contraseña' => [null, true, 'Sin acceso'],
        'con la de defecto' => ['clave-por-defecto-1', true, 'Debe cambiar su contraseña'],
        'con la suya' => ['otra-clave-123', false, 'Con acceso'],
    ]);

    it('CF-07: a un docente sin acceso el admin le manda su invitación, aunque su departamento no haya validado; ya no hay «Dar acceso»', function () {
        Notification::fake();
        $this->docente->forceFill(['password' => null, 'must_change_password' => true])->save();
        $this->ana->update(['institutional_email' => 'ana.perez@cdjuarez.tecnm.mx']);

        $response = $this->actingAs($this->admin)->get(route('faculty.teachers.show', $this->ana->id))->assertOk()->assertDontSee('Dar acceso');
        $dom = domOf($response);
        $item = $dom->querySelector('[data-testid="resend-invitation"]');

        expect(textOf($item))->toBe('Enviar invitación')
            ->and($item->getAttribute('aria-disabled'))->toBeNull()
            ->and($dom->querySelector('[data-testid="reset-password"]'))->toBeNull();

        $page = teacherRecord($this->admin, $this->ana)->call('resendInvitation');

        expect(lastToastOf($page))->type->toBe('success')->title->toBe('Invitación enviada')
            ->and($this->docente->fresh())->password->toBeNull()->access_invited_by->toBe($this->admin->id)->email->toBe('ana.perez@cdjuarez.tecnm.mx');
        Notification::assertSentOnDemandTimes(AccessInvitation::class, 1);
    });

    it('CF-07: el admin invita al docente que también es jefe, y ese enlace abre', function () {
        Notification::fake();
        $this->docente->forceFill(['password' => null])->save();
        $this->ana->update(['institutional_email' => 'ana.perez@cdjuarez.tecnm.mx']);
        RoleAssignment::factory()->for($this->docente)->create(['role' => Role::JefeDepartamento, 'org_unit_id' => Department::factory()->create()->id, 'valid_from' => today()->subMonth()->toDateString()]);

        expect(textOf(domOf($this->actingAs($this->admin)->get(route('faculty.teachers.show', $this->ana->id)))->querySelector('[data-testid="resend-invitation"]')))->toBe('Enviar invitación');

        teacherRecord($this->admin, $this->ana)->call('resendInvitation');

        $link = null;
        Notification::assertSentOnDemand(AccessInvitation::class, function (AccessInvitation $invitation) use (&$link): bool {
            $link = $invitation->url;

            return true;
        });
        $this->get($link)->assertOk()->assertSee('Activar mi acceso');
    });

    it('CF-07: sin correo institucional, la del admin sale deshabilitada con su motivo', function () {
        Notification::fake();
        $this->docente->forceFill(['password' => null])->save();

        $item = domOf($this->actingAs($this->admin)->get(route('faculty.teachers.show', $this->ana->id)))->querySelector('[data-testid="resend-invitation"]');

        expect(textOf($item))->toBe('Enviar invitación Falta su correo institucional: captúralo en «Editar datos».')
            ->and($item->getAttribute('aria-disabled'))->toBe('true');

        $page = teacherRecord($this->admin, $this->ana)->call('resendInvitation');

        expect(lastToastOf($page))->type->toBe('error')->description->toBe('Falta su correo institucional.');
        Notification::assertNothingSent();
    });

    it('CF-07: a un usuario inactivo no se le invita: deshabilitado con su motivo y un toast si se llama', function () {
        Notification::fake();
        $this->docente->forceFill(['password' => null, 'is_active' => false])->save();
        $this->ana->update(['institutional_email' => 'ana.perez@cdjuarez.tecnm.mx']);

        $dom = domOf($this->actingAs($this->admin)->get(route('faculty.teachers.show', $this->ana->id))->assertOk());

        expect(textOf($dom->querySelector('[data-testid="resend-invitation"]')))->toBe('Enviar invitación Su usuario está inactivo.');

        $page = teacherRecord($this->admin, $this->ana)->call('resendInvitation');

        expect(lastToastOf($page))->type->toBe('error')->title->toBe('No se pudo enviar la invitación')
            ->and($this->docente->fresh()->access_invited_at)->toBeNull();
        Notification::assertNothingSent();
    });

    it('CF-09: si el admin dio de baja su cuenta, la ficha lo dice y no ofrece invitar ni restablecer: se reactiva desde el panel', function (string $who, bool $link) {
        Notification::fake();
        $this->ana->update(['institutional_email' => 'ana.perez@cdjuarez.tecnm.mx']);
        app(DeactivateUser::class)->handle($this->docente, DeactivationReason::Otro, null, $this->admin);

        $viewer = $who === 'directivo' ? User::factory()->withRole(Role::Directivo)->create() : $this->{$who};
        $dom = domOf($this->actingAs($viewer)->get(route('faculty.teachers.show', $this->ana->id))->assertOk());
        $item = $dom->querySelector('[data-testid="reactivate-from-panel"]');

        expect(textOf($dom->querySelector('[data-testid="user-deactivated"]')))->toBe('Cuenta dada de baja por el admin')
            ->and($dom->querySelector('[data-testid="resend-invitation"]'))->toBeNull()
            ->and($dom->querySelector('[data-testid="reset-password"]'))->toBeNull()
            ->and(textOf($item))->toBe('Se reactiva desde el panel Cuenta dada de baja por el admin.')
            ->and($item->getAttribute('href'))->toBe($link ? route('core.people.show', $this->docente->id) : null);

        $this->actingAs($this->admin)->get(route('faculty.teachers'))->assertSee('Cuenta dada de baja por el admin');

        teacherRecord($this->admin, $this->ana)->call('resendInvitation');
        Notification::assertNothingSent();
    })->with([
        'el admin, con el enlace' => ['admin', true],
        'el jefe, sin enlace' => ['jefe', false],
        'el directivo, que ve la ficha pero no reactiva: sin enlace (AZ-03)' => ['directivo', false],
    ]);
});

describe('alcance', function () {
    it('el jefe de SC recibe 403 en la ficha de un docente de otro departamento, y el de CB en la de uno de SC e II', function () {
        $this->actingAs($this->jefe)->get(route('faculty.teachers.show', $this->beto->id))->assertForbidden();
        $this->actingAs($this->jefeCb)->get(route('faculty.teachers.show', $this->ana->id))->assertForbidden();
    });

    it('la Subdirección ve la ficha de cualquiera, también la de un docente sin departamento; el jefe no', function () {
        $this->actingAs($this->subdireccion)->get(route('faculty.teachers.show', $this->sin->id))->assertOk();
        $this->actingAs($this->subdireccion)->get(route('faculty.teachers.show', $this->beto->id))->assertOk();
        $this->actingAs($this->jefe)->get(route('faculty.teachers.show', $this->sin->id))->assertForbidden();
    });

    it('un id que no es uuid o no existe da 404', function () {
        $this->actingAs($this->admin)->get('/faculty/teachers/no-es-uuid')->assertNotFound();
        $this->actingAs($this->admin)->get('/faculty/teachers/'.fakeKey(99))->assertNotFound();
    });

    it('el docente, el coordinador y el invitado no entran', function () {
        $this->actingAs($this->docente)->get(route('faculty.teachers.show', $this->ana->id))->assertForbidden();
        auth()->logout();
        $this->get(route('faculty.teachers.show', $this->ana->id))->assertRedirect(route('login'));
    });

    it('el jefe de SC recibe 403 al quitar la asignación de II, aunque la llame directo', function () {
        $ii = $this->ana->adscriptions()->where('department_id', $this->ii->id)->sole();
        $sc = $this->ana->adscriptions()->where('department_id', $this->sc->id)->sole();
        $this->ana->adscriptions()->where('department_id', $this->ii->id)->update(['is_primary' => false]);
        $sc->update(['is_primary' => true]);

        teacherRecord($this->jefe, $this->ana)->call('confirmRemoveAdscription', $ii->id)->assertForbidden();

        expect($ii->fresh()->trashed())->toBeFalse();
    });

    it('cada método da 403 si lo llama alguien que no cubre al docente, también sobre una página que abrió el admin', function (string $method, array $arguments) {
        $position = $this->plaza;
        $sc = $this->ana->adscriptions()->where('department_id', $this->sc->id)->sole();
        $arguments = array_map(fn ($argument) => match ($argument) {
            ':position' => $position->id,
            ':adscription' => $sc->id,
            default => $argument,
        }, $arguments);

        $page = Livewire::actingAs($this->admin)->test('faculty::pages.teacher', ['teacher' => $this->ana->id]);
        $this->actingAs($this->jefeCb);

        $page->call($method, ...$arguments)->assertForbidden();

        expect(activitySince()->count())->toBe(0)
            ->and($this->ana->fresh()->name)->toBe('Ana Pérez')
            ->and($this->plaza->fresh()->trashed())->toBeFalse()
            ->and($sc->fresh()->trashed())->toBeFalse();
    })->with([
        'editar datos' => ['editData', []],
        'guardar datos' => ['saveData', []],
        'desactivar' => ['toggleActive', []],
        'restablecer contraseña' => ['resetPassword', []],
        'confirmar quitar asignación' => ['confirmRemoveAdscription', [':adscription']],
        'hacer principal' => ['setPrimary', [':adscription']],
        'agregar plaza' => ['createPosition', []],
        'guardar plaza' => ['savePosition', []],
        'editar plaza' => ['editPosition', [':position']],
        'confirmar quitar plaza' => ['confirmRemovePosition', [':position']],
    ]);

    it('quitar una asignación o una plaza confirmada recibe 403 si cambió quien la pide', function () {
        $sc = $this->ana->adscriptions()->where('department_id', $this->sc->id)->sole();
        $page = Livewire::actingAs($this->admin)->test('faculty::pages.teacher', ['teacher' => $this->ana->id])
            ->call('confirmRemoveAdscription', $sc->id)->call('confirmRemovePosition', $this->plaza->id);

        $this->actingAs($this->jefeCb);
        $page->call('removeAdscription')->assertForbidden();

        $page = Livewire::actingAs($this->admin)->test('faculty::pages.teacher', ['teacher' => $this->ana->id])->call('confirmRemovePosition', $this->plaza->id);
        $this->actingAs($this->jefeCb);
        $page->call('removePosition')->assertForbidden();

        expect($sc->fresh()->trashed())->toBeFalse()->and($this->plaza->fresh()->trashed())->toBeFalse();
    });
});

describe('revisión de la ficha', function () {
    it('«Hacer principal» es de quien cubre el departamento de esa asignación (supuesto por confirmar)', function () {
        $ii = $this->ana->adscriptions()->where('department_id', $this->ii->id)->sole();
        $sc = $this->ana->adscriptions()->where('department_id', $this->sc->id)->sole();
        $ii->update(['is_primary' => false]);
        $sc->update(['is_primary' => true]);

        teacherRecord($this->jefe, $this->ana)->call('setPrimary', $ii->id)->assertForbidden();

        expect($ii->fresh()->is_primary)->toBeFalse();

        teacherRecord($this->subdireccion, $this->ana)->call('setPrimary', $ii->id);

        expect($ii->fresh()->is_primary)->toBeTrue();
    });

    it('el jefe ve «Hacer principal» deshabilitado, con su motivo, en el departamento que no cubre', function () {
        $ii = $this->ana->adscriptions()->where('department_id', $this->ii->id)->sole();
        $ii->update(['is_primary' => false]);
        $this->ana->adscriptions()->where('department_id', $this->sc->id)->update(['is_primary' => true]);

        $dom = domOf($this->actingAs($this->jefe)->get(route('faculty.teachers.show', $this->ana->id))->assertOk());
        $button = $dom->querySelector('[data-testid="set-primary-'.$ii->id.'"]');

        expect($button->hasAttribute('disabled'))->toBeTrue()
            ->and($button->parentElement->getAttribute('data-tip'))->toContain('Solo quien administra');
    });

    it('la ficha no consulta por asignación: las consultas no crecen con tres o más', function () {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->jefe)->get(route('faculty.teachers.show', $this->ana->id))->assertOk();

            return count(DB::getQueryLog());
        };
        $count();
        $before = $count();

        foreach ([$this->cb, Department::factory()->create(), Department::factory()->create()] as $department) {
            TeacherDepartment::factory()->for($this->ana)->for($department)->create(['is_primary' => false]);
        }

        expect($this->ana->adscriptions()->count())->toBe(5)
            ->and($count())->toBe($before);
    });
});

describe('perfil', function () {
    beforeEach(function () {
        $this->redes = Subject::factory()->create(['code' => 'SCC-1', 'name' => 'Redes de computadoras', 'department_id' => $this->sc->id]);
        $this->calculo = Subject::factory()->create(['code' => 'ACF-1', 'name' => 'Cálculo diferencial', 'department_id' => $this->cb->id]);
    });

    it('S17: lista las materias del perfil con su nivel y cuántas veces las impartió', function () {
        TeacherSubjectQualification::factory()->for($this->ana)->for($this->redes)->preferente()->create();
        TeacherSubjectHistory::factory()->for($this->ana)->for($this->redes)->count(2)->create();

        teacherRecord($this->admin, $this->ana)->set('tab', 'perfil')
            ->assertSeeInOrder(['Perfil', 'SCC-1', 'Redes de computadoras', 'Preferente', 'veces'])
            ->assertSeeHtml('>2</span> veces')
            ->assertSeeHtml('data-testid="qualifications"');
    });

    it('S17: el jefe de SC declara una materia de SC con su nivel, con bitácora', function () {
        $page = teacherRecord($this->jefe, $this->ana)->set('tab', 'perfil')->call('createQualification')
            ->set('qualSubjectId', $this->redes->id)->set('qualLevel', 'preferente')->set('qualNotes', 'La dio en 2024')->call('saveQualification');

        $qualification = TeacherSubjectQualification::sole();

        expect($qualification)->teacher_id->toBe($this->ana->id)->subject_id->toBe($this->redes->id)->level->toBe(QualificationLevel::Preferente)
            ->qualified_by->toBe($this->jefe->id)->notes->toBe('La dio en 2024')
            ->and(lastToastOf($page))->type->toBe('success')
            ->and(Activity::where('id', '>', $this->activityBase)->where('event', 'created')->count())->toBe(1);
    });

    it('S17: una materia inactiva o borrada es un error del campo materia, no se declara', function () {
        $inactive = Subject::factory()->create(['department_id' => $this->sc->id, 'is_active' => false]);
        $deleted = Subject::factory()->create(['department_id' => $this->sc->id]);
        $deleted->delete();

        foreach ([$inactive, $deleted] as $subject) {
            teacherRecord($this->jefe, $this->ana)->set('tab', 'perfil')->call('createQualification')
                ->set('qualSubjectId', $subject->id)->set('qualLevel', 'apto')->call('saveQualification')->assertHasErrors(['qualSubjectId']);
        }

        expect(TeacherSubjectQualification::count())->toBe(0);
    });

    it('lee el perfil de 10 materias sin una consulta por materia', function () {
        foreach (range(1, 10) as $i) {
            TeacherSubjectQualification::factory()->for($this->ana)->for(Subject::factory()->create(['department_id' => $this->sc->id]))->create();
        }
        $page = teacherRecord($this->jefe, $this->ana);
        DB::enableQueryLog();
        $page->set('tab', 'perfil');
        $with10 = count(DB::getQueryLog());

        TeacherSubjectQualification::query()->limit(5)->get()->each->delete();
        $page = teacherRecord($this->jefe, $this->ana);
        DB::flushQueryLog();
        $page->set('tab', 'perfil');

        expect($with10)->toBe(count(DB::getQueryLog()));
    });

    it('S17: el jefe de SC no declara una materia de CB: 403 y nada se guarda', function () {
        teacherRecord($this->jefe, $this->ana)->set('tab', 'perfil')->call('createQualification')
            ->set('qualSubjectId', $this->calculo->id)->set('qualLevel', 'apto')->call('saveQualification')->assertForbidden();

        expect(TeacherSubjectQualification::count())->toBe(0);
    });

    it('la Subdirección y el admin declaran cualquier materia', function (string $user) {
        teacherRecord($this->{$user}, $this->ana)->set('tab', 'perfil')->call('createQualification')
            ->set('qualSubjectId', $this->calculo->id)->set('qualLevel', 'apto')->call('saveQualification')->assertHasNoErrors();

        expect(TeacherSubjectQualification::count())->toBe(1);
    })->with(['admin', 'subdireccion']);

    it('la búsqueda de materias del jefe de SC solo ofrece las de su departamento', function () {
        teacherRecord($this->jefe, $this->ana)->set('tab', 'perfil')->call('createQualification')
            ->assertSee('SCC-1')->assertDontSee('ACF-1')
            ->set('qualSearch', 'cálculo')->assertDontSee('ACF-1');

        teacherRecord($this->subdireccion, $this->ana)->set('tab', 'perfil')->call('createQualification')
            ->set('qualSearch', 'cálculo')->assertSee('ACF-1');
    });

    it('cambia el nivel de una materia y la quita', function () {
        $qualification = TeacherSubjectQualification::factory()->for($this->ana)->for($this->redes)->apto()->create();

        $page = teacherRecord($this->jefe, $this->ana)->set('tab', 'perfil')->call('editQualification', $qualification->id)
            ->assertSet('qualSubjectId', $this->redes->id)->set('qualLevel', 'preferente')->call('saveQualification');

        expect($qualification->fresh()->level)->toBe(QualificationLevel::Preferente);

        $page->call('removeQualification', $qualification->id);

        expect($qualification->fresh()->trashed())->toBeTrue()
            ->and(lastToastOf($page))->title->toBe('Materia quitada del perfil');
    });

    it('el jefe de SC no edita ni quita una materia de CB del perfil aunque llame directo', function () {
        $qualification = TeacherSubjectQualification::factory()->for($this->ana)->for($this->calculo)->apto()->create();

        teacherRecord($this->jefe, $this->ana)->call('editQualification', $qualification->id)->assertForbidden();
        teacherRecord($this->jefe, $this->ana)->call('removeQualification', $qualification->id)->assertForbidden();

        expect($qualification->fresh()->trashed())->toBeFalse();
    });

    it('no toca el perfil de otro docente ni uno que no es uuid', function () {
        $foreign = TeacherSubjectQualification::factory()->for($this->beto)->for($this->redes)->create();

        teacherRecord($this->admin, $this->ana)->call('removeQualification', $foreign->id)->assertNotFound();
        teacherRecord($this->admin, $this->ana)->call('editQualification', 'no-es-uuid')->assertNotFound();

        expect($foreign->fresh()->trashed())->toBeFalse();
    });

    it('el nivel y la materia son obligatorios', function () {
        teacherRecord($this->admin, $this->ana)->call('createQualification')->set('qualSubjectId', '')->set('qualLevel', 'experto')->call('saveQualification')
            ->assertHasErrors(['qualSubjectId', 'qualLevel']);
    });

    it('quien no cubre al docente no ve su ficha', function () {
        $this->actingAs($this->jefeCb)->get(route('faculty.teachers.show', $this->beto->id))->assertForbidden();
    });
});

describe('carga académica en otra modalidad', function () {
    it('S16: las muestra por origen con el total, contra las horas base', function () {
        TeacherExternalLoad::factory()->for($this->ana)->for($this->term)->create(['source' => 'TecNM Virtual', 'hours' => 6]);
        TeacherExternalLoad::factory()->for($this->ana)->for($this->term)->create(['source' => 'Otra escuela', 'hours' => 4]);

        teacherRecord($this->admin, $this->ana)->set('tab', 'perfil')
            ->assertSee('Carga académica en otra modalidad')
            ->assertSeeInOrder(['Ene-Jun 2027', 'Otra escuela', 'TecNM Virtual', 'En otra modalidad', '10 h'])
            ->assertSeeHtml('data-testid="external-loads"');
    });

    it('S16: agrega, edita y quita horas del periodo activo', function () {
        $page = teacherRecord($this->jefe, $this->ana)->set('tab', 'perfil')->call('createLoad')
            ->set('loadSource', 'TecNM Virtual')->set('loadHours', '12')->set('loadNotes', 'Dos grupos')->call('saveLoad');

        $load = TeacherExternalLoad::sole();

        expect($load)->teacher_id->toBe($this->ana->id)->term_id->toBe($this->term->id)->hours->toBe(12)
            ->and(lastToastOf($page))->type->toBe('success');

        $page->call('editLoad', $load->id)->assertSet('loadSource', 'TecNM Virtual')->set('loadHours', '8')->call('saveLoad');

        expect($load->fresh()->hours)->toBe(8);

        $page->call('removeLoad', $load->id);

        expect($load->fresh()->trashed())->toBeTrue();
    });

    it('S16: rechaza horas fuera de 1 a 40 y un origen repetido', function () {
        teacherRecord($this->admin, $this->ana)->set('tab', 'perfil')->call('createLoad')->set('loadSource', 'A')->set('loadHours', '41')->call('saveLoad')
            ->assertHasErrors(['loadHours']);

        TeacherExternalLoad::factory()->for($this->ana)->for($this->term)->create(['source' => 'A']);

        teacherRecord($this->admin, $this->ana)->set('tab', 'perfil')->call('createLoad')->set('loadSource', 'A')->set('loadHours', '3')->call('saveLoad')
            ->assertHasErrors(['loadSource']);

        expect(TeacherExternalLoad::count())->toBe(1);
    });

    it('sin periodo activo pide elegir uno en la credencial y no deja capturar', function () {
        Term::query()->update(['is_current' => false]);

        teacherRecord($this->admin, $this->ana)->set('tab', 'perfil')
            ->assertSee('Elige un periodo en la credencial')
            ->call('createLoad')->assertForbidden();

        expect(TeacherExternalLoad::count())->toBe(0);
    });

    it('no toca las horas de otro docente ni las de otro periodo', function () {
        $foreign = TeacherExternalLoad::factory()->for($this->beto)->for($this->term)->create();
        $past = TeacherExternalLoad::factory()->for($this->ana)->for(Term::factory()->create(['code' => '20281']))->create();

        teacherRecord($this->admin, $this->ana)->call('removeLoad', $foreign->id)->assertNotFound();
        teacherRecord($this->admin, $this->ana)->call('editLoad', $past->id)->assertNotFound();
        teacherRecord($this->admin, $this->ana)->call('removeLoad', $past->id)->assertNotFound();

        expect($foreign->fresh()->trashed())->toBeFalse()->and($past->fresh()->trashed())->toBeFalse();
    });

    it('quien no cubre al docente no llega a sus horas', function () {
        $this->actingAs($this->jefeCb)->get(route('faculty.teachers.show', $this->beto->id))->assertForbidden();
    });
});

describe('historial', function () {
    it('muestra los grupos por periodo, del más reciente al más antiguo, y las evaluaciones por tipo', function () {
        $subject = Subject::factory()->create(['code' => 'SCC-1', 'name' => 'Redes de computadoras']);
        $campus = Campus::factory()->create(['code' => 'C2']);
        TeacherSubjectHistory::factory()->for($this->ana)->for($subject)->create(['term_code' => '20243', 'campus_id' => $campus->id, 'group_code' => '5B']);
        TeacherSubjectHistory::factory()->for($this->ana)->for($subject)->create(['term_code' => '20241', 'campus_id' => $campus->id, 'group_code' => '3A']);
        TeacherEvaluation::factory()->for($this->ana)->estudiantil()->create(['term_code' => '20241', 'score' => 4.5]);
        TeacherEvaluation::factory()->for($this->ana)->departamental()->create(['term_code' => '20241', 'score' => 3.8]);

        teacherRecord($this->admin, $this->ana)->set('tab', 'historial')
            ->assertSeeInOrder(['Historial', 'Ago-Dic 2024', '5B', 'Ene-Jun 2024', 'SCC-1', '3A', 'C2'])
            ->assertSeeInOrder(['Evaluaciones', 'Ene-Jun 2024', 'Estudiantil', '4.50', 'Departamental', '3.80'])
            ->assertSeeHtml('data-testid="history"')
            ->assertSeeHtml('data-testid="evaluations"');
    });

    it('1.24.3: lo más reciente va primero y dentro del periodo conserva su orden', function () {
        $subject = Subject::factory()->create(['code' => 'SCC-1']);
        $other = Subject::factory()->create(['code' => 'SCC-2']);
        $campus = Campus::factory()->create(['code' => 'C2']);
        TeacherSubjectHistory::factory()->for($this->ana)->for($other)->create(['term_code' => '20241', 'campus_id' => $campus->id, 'group_code' => '4B']);
        TeacherSubjectHistory::factory()->for($this->ana)->for($subject)->create(['term_code' => '20241', 'campus_id' => $campus->id, 'group_code' => '3A']);
        TeacherSubjectHistory::factory()->for($this->ana)->for($subject)->create(['term_code' => '20253', 'campus_id' => $campus->id, 'group_code' => '5C']);
        TeacherSubjectHistory::factory()->for($this->ana)->for($subject)->create(['term_code' => '20243', 'campus_id' => $campus->id, 'group_code' => '5B']);

        teacherRecord($this->admin, $this->ana)->set('tab', 'historial')
            ->assertSee('del más reciente al más antiguo')
            ->assertDontSee('del más antiguo al más reciente')
            ->assertSeeInOrder(['20253', '5C', '20243', '5B', '20241', 'SCC-1', '3A', 'SCC-2', '4B']);
    });

    it('es de solo lectura: no hay botones de captura ni métodos que escriban', function () {
        TeacherSubjectHistory::factory()->for($this->ana)->create();

        $page = teacherRecord($this->admin, $this->ana)->set('tab', 'historial');
        $dom = domOf($page);

        expect($dom->querySelectorAll('#panel button'))->toHaveCount(0)
            ->and(method_exists($page->instance(), 'saveHistory'))->toBeFalse();
    });

    it('sin historial dice que no hay grupos', function () {
        teacherRecord($this->admin, $this->ana)->set('tab', 'historial')->assertSee('Todavía no tiene grupos importados')->assertSee('Todavía no tiene evaluaciones');
    });

    it('lee el historial de 8 periodos sin N+1', function () {
        $subject = Subject::factory()->create();
        $campus = Campus::factory()->create();

        foreach (['20231', '20232', '20233', '20241', '20242', '20243', '20251', '20252'] as $code) {
            TeacherSubjectHistory::factory()->for($this->ana)->for($subject)->count(3)->create(['term_code' => $code, 'campus_id' => $campus->id]);
            TeacherEvaluation::factory()->for($this->ana)->estudiantil()->create(['term_code' => $code]);
        }

        $page = teacherRecord($this->admin, $this->ana);
        DB::enableQueryLog();
        $page->set('tab', 'historial');

        expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(30);
    });
});

describe('disponibilidad', function () {
    it('muestra el resumen del periodo, la cuadrícula de solo lectura, los rangos por día y el enlace al editor', function () {
        TeacherAvailability::factory()->for($this->ana)->for($this->term)->create(['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00']);
        TeacherAvailability::factory()->for($this->ana)->for($this->term)->preferred()->create(['day' => 3, 'starts_at' => '10:00', 'ends_at' => '11:00']);

        $dom = domOf(teacherRecord($this->jefe, $this->ana)->set('tab', 'disponibilidad'));
        $section = $dom->querySelector('[data-testid="availability"]');

        expect(textOf($section->querySelector('header')))->toBe('Disponibilidad Ene-Jun 2027 Editar disponibilidad')
            ->and($section->querySelector('[data-testid="edit-availability"]')->getAttribute('href'))->toBe(route('faculty.teachers.availability', $this->ana->id))
            ->and(textOf($section->querySelector('p')))->toBe('Disponible 68 h de 70 Preferido 1 h No disponible 2 h Horas base 40 h')
            ->and(textOf($section->querySelector('[data-testid="availability-ranges"]')))->toBe(
                'Lunes 12 h disp. No disponible 7:00–9:00 Martes 14 h disp. Disponible todo el día Miércoles 14 h disp. Preferido 10:00–11:00 '
                .'Jueves 14 h disp. Disponible todo el día Viernes 14 h disp. Disponible todo el día'
            )
            ->and($section->querySelector('[data-testid="availability-grid"]')->getAttribute('aria-hidden'))->toBe('true')
            ->and($section->querySelector('[data-testid="availability-grid"] button'))->toBeNull();
    });

    it('sin periodo activo pide elegirlo', function () {
        $this->term->update(['is_current' => false]);

        teacherRecord($this->admin, $this->ana)->set('tab', 'disponibilidad')
            ->assertSee('Elige un periodo en tu credencial')
            ->assertDontSee('Editar disponibilidad');
    });
});
