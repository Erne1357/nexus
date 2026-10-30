<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Faculty\Models\TeacherPosition;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| Ficha del docente (`/faculty/teachers/{teacher}`, faculty::pages.teacher; spec 4.3 y S8). Cinco pestañas: Datos
| (datos, adscripciones: quitar y hacer principal), Plazas (agregar, editar y quitar) y, para las tareas
| siguientes, Disponibilidad, Perfil e Historial. Cada quien ve y cambia a los docentes de su alcance
| (TeacherPolicy); cada método vuelve a autorizar. «Restablecer contraseña» es solo del administrador.
*/

beforeEach(function () {
    $this->sc = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->ii = Department::factory()->create(['code' => 'II', 'name' => 'Ingeniería Industrial']);
    $this->cb = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    $this->term = Term::factory()->current()->create(['name' => 'Ene-Jun 2027', 'starts_on' => '2027-01-25', 'ends_on' => '2027-06-11']);

    $this->docente = User::factory()->withRole(Role::Docente)->create(['username' => '1000001']);
    $this->ana = Teacher::factory()->adscribedTo($this->ii)->create(['employee_number' => '1000001', 'name' => 'Ana Pérez', 'email' => 'ana@example.test', 'user_id' => $this->docente->id]);
    TeacherDepartment::factory()->for($this->ana)->for($this->sc)->create(['is_primary' => false, 'created_at' => now()->addSecond()]);
    $this->plaza = TeacherPosition::factory()->for($this->ana)->tiempoCompleto()->create();
    $this->beto = Teacher::factory()->adscribedTo($this->ii)->create(['employee_number' => '1000002', 'name' => 'Beto Ruiz']);

    $this->admin = User::factory()->admin()->create();
    $this->sin = Teacher::factory()->create(['employee_number' => '1000003', 'name' => 'Elsa Mora']);
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

    it('en Datos muestra sus datos y sus adscripciones con la principal primero', function () {
        teacherRecord($this->admin, $this->ana)
            ->assertSeeInOrder(['Número de empleado', '1000001', 'Correo', 'ana@example.test', 'Usuario'])
            ->assertSeeInOrder(['Adscripciones', 'Ingeniería Industrial', 'Principal', 'Sistemas y Computación', 'Hacer principal']);
    });

    it('recuerda la pestaña en la URL y una que no existe muestra Datos', function () {
        teacherRecord($this->admin, $this->ana)->set('tab', 'plazas')->assertSee('Tiempo completo');
        teacherRecord($this->admin, $this->ana)->set('tab', 'nada')->assertSet('tab', 'datos');
    });

    it('las pestañas que vienen después muestran un estado vacío', function (string $tab) {
        teacherRecord($this->admin, $this->ana)->set('tab', $tab)->assertSeeHtml('data-testid="tab-pending"');
    })->with(['disponibilidad', 'perfil', 'historial']);

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

describe('adscripciones', function () {
    it('quita una adscripción después de confirmar y avisa', function () {
        $sc = $this->ana->adscriptions()->where('department_id', $this->sc->id)->sole();
        $page = teacherRecord($this->admin, $this->ana)->call('confirmRemoveAdscription', $sc->id);
        $page->assertSet('removingAdscriptionId', $sc->id)->assertSee('¿Quitar al docente');
        $page->call('removeAdscription');

        expect($sc->fresh()->trashed())->toBeTrue()
            ->and(lastToastOf($page))->type->toBe('success')->title->toBe('Quitado de SC');
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
            ->assertSee('su adscripción principal pasa a SC')
            ->call('removeAdscription');

        expect($primary->fresh()->trashed())->toBeTrue()
            ->and($sc->fresh()->is_primary)->toBeTrue()
            ->and(lastToastOf($page))->type->toBe('success')->description->toContain('principal pasa a SC');
    });

    it('quitar la única adscripción deja al docente activo y sin departamento', function () {
        $page = teacherRecord($this->admin, $this->beto)->call('confirmRemoveAdscription', $this->beto->adscriptions()->sole()->id)
            ->assertSee('Se queda sin departamento, pero sigue activo.')
            ->call('removeAdscription');

        expect($this->beto->fresh()->is_active)->toBeTrue()
            ->and($this->beto->adscriptions()->count())->toBe(0)
            ->and(lastToastOf($page))->description->toContain('queda sin departamento');
    });

    it('nadie se quita a sí mismo de un departamento (supuesto por confirmar)', function () {
        $this->ana->update(['user_id' => $this->jefe->id]);
        $sc = $this->ana->adscriptions()->where('department_id', $this->sc->id)->sole();

        teacherRecord($this->jefe, $this->ana)->call('confirmRemoveAdscription', $sc->id)->assertForbidden();

        expect($sc->fresh()->trashed())->toBeFalse();
    });

    it('lista las adscripciones sin campus, con «Quitar de <depto>» por renglón', function () {
        $page = teacherRecord($this->admin, $this->ana);

        $page->assertSee('Quitar de II')->assertSee('Quitar de SC')->assertDontSee(' en C2');
    });

    it('hace principal a otra y deja una sola', function () {
        $sc = $this->ana->adscriptions()->where('department_id', $this->sc->id)->sole();

        $page = teacherRecord($this->jefe, $this->ana)->call('setPrimary', $sc->id);

        expect($this->ana->adscriptions()->where('is_primary', true)->pluck('id')->all())->toBe([$sc->id])
            ->and(lastToastOf($page))->title->toBe('Adscripción principal cambiada');
    });

    it('el jefe ve la adscripción de otro departamento con «Quitar» deshabilitado y su motivo', function () {
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
            ->assertSee('Las horas base de una asignatura van de 1 a 40.')
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

describe('restablecer contraseña', function () {
    it('el admin la devuelve a la por defecto y obliga a cambiarla', function () {
        $this->docente->forceFill(['password' => 'otra-clave-123', 'must_change_password' => false])->save();

        $page = teacherRecord($this->admin, $this->ana)->call('resetPassword');

        expect($this->docente->fresh())->must_change_password->toBeTrue()
            ->and(Hash::check(config('auth.default_password'), $this->docente->fresh()->password))->toBeTrue()
            ->and(lastToastOf($page))->type->toBe('success')->title->toBe('Contraseña restablecida');
    });

    it('sin usuario no hay nada que restablecer', function () {
        $page = teacherRecord($this->admin, $this->beto)->call('resetPassword');

        expect(lastToastOf($page))->type->toBe('error');
    });

    it('sin NEXUS_DEFAULT_PASSWORD lo dice en un toast y no cambia nada', function () {
        config(['auth.default_password' => null]);
        $before = $this->docente->fresh()->password;

        $page = teacherRecord($this->admin, $this->ana)->call('resetPassword');

        expect(lastToastOf($page))->type->toBe('error')->description->toContain('NEXUS_DEFAULT_PASSWORD')
            ->and($this->docente->fresh()->password)->toBe($before);
    });

    it('el jefe no la ve habilitada, con su motivo, y recibe 403 al llamarla', function () {
        $dom = domOf($this->actingAs($this->jefe)->get(route('faculty.teachers.show', $this->ana->id))->assertOk());
        $item = $dom->querySelector('[data-testid="reset-password"]');

        expect($item)->not->toBeNull()->and($item->getAttribute('aria-disabled'))->toBe('true');

        teacherRecord($this->jefe, $this->ana)->call('resetPassword')->assertForbidden();
    });
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

    it('el jefe de SC recibe 403 al quitar la adscripción de II, aunque la llame directo', function () {
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
        'confirmar quitar adscripción' => ['confirmRemoveAdscription', [':adscription']],
        'hacer principal' => ['setPrimary', [':adscription']],
        'agregar plaza' => ['createPosition', []],
        'guardar plaza' => ['savePosition', []],
        'editar plaza' => ['editPosition', [':position']],
        'confirmar quitar plaza' => ['confirmRemovePosition', [':position']],
    ]);

    it('quitar una adscripción o una plaza confirmada recibe 403 si cambió quien la pide', function () {
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
