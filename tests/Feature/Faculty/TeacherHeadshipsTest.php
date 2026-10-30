<?php

use App\Modules\Academic\Actions\AppointDepartmentHead;
use App\Modules\Academic\Enums\DepartmentHeadKind;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\DepartmentHead;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\ScopedContext;
use App\Modules\Faculty\Models\Teacher;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
| Sección «Jefaturas» de la ficha del docente (tarea 1.14, solo admin): lista las jefaturas del usuario del docente,
| vigentes y pasadas, nombra jefe de departamento (uno por departamento para todo el Tec, sin campus; confirmado el
| 30 de octubre de 2026) o jefe de proyecto docente (de un campus; supuesto por confirmar) y termina una jefatura.
| Si el docente no tiene usuario, se le crea antes (SyncTeacherAccess). Un docente inactivo no se nombra.
*/

beforeEach(function () {
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->sc = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->ii = Department::factory()->create(['code' => 'II', 'name' => 'Ingeniería Industrial']);
    $this->admin = User::factory()->admin()->create();
    $this->ana = Teacher::factory()->adscribedTo($this->sc)->create(['employee_number' => '1000001', 'name' => 'Ana Pérez']);
});

function headshipRecord(User $user, Teacher $teacher): Testable
{
    return Livewire::actingAs($user)->test('faculty::pages.teacher', ['teacher' => $teacher->id]);
}

describe('nombrar', function () {
    it('nombra jefe de departamento a un docente sin usuario: se lo crea y su alcance cubre todo el Tec', function () {
        headshipRecord($this->admin, $this->ana)
            ->call('createHeadship')
            ->set('headKind', 'jefe')
            ->set('headDepartmentId', $this->sc->id)
            ->set('headFrom', today()->subDay()->toDateString())
            ->call('saveHeadship')
            ->assertHasNoErrors();

        $user = $this->ana->fresh()->user;
        $head = DepartmentHead::sole();

        expect($user)->not->toBeNull()
            ->and($head)->user_id->toBe($user->id)->department_id->toBe($this->sc->id)->campus_id->toBeNull()->kind->toBe(DepartmentHeadKind::Jefe);

        $context = app(ScopedContext::class)->forUser($user->fresh());
        expect($context->coversDepartment(Role::JefeDepartamento, $this->sc->id))->toBeTrue()
            ->and($context->coversDepartment(Role::JefeDepartamento, $this->ii->id))->toBeFalse();
    });

    it('nombra jefe de proyecto docente en un campus a un docente con usuario, con fecha de fin', function () {
        $user = User::factory()->withRole(Role::Docente)->create(['username' => '1000001']);
        $this->ana->update(['user_id' => $user->id]);

        headshipRecord($this->admin, $this->ana)
            ->call('createHeadship')
            ->set('headKind', 'jefe_proyecto_docente')
            ->set('headDepartmentId', $this->sc->id)
            ->set('headCampusId', $this->c2->id)
            ->set('headFrom', today()->toDateString())
            ->set('headTo', today()->addMonths(6)->toDateString())
            ->call('saveHeadship')
            ->assertHasNoErrors();

        expect(DepartmentHead::sole())
            ->user_id->toBe($user->id)->campus_id->toBe($this->c2->id)->valid_to->toDateString()->toBe(today()->addMonths(6)->toDateString());
    });

    it('el jefe de proyecto docente exige campus y el jefe no lo lleva: el error sale en el campo', function () {
        headshipRecord($this->admin, $this->ana)
            ->call('createHeadship')
            ->set('headKind', 'jefe_proyecto_docente')
            ->set('headDepartmentId', $this->sc->id)
            ->set('headFrom', today()->toDateString())
            ->call('saveHeadship')
            ->assertHasErrors('headCampusId');

        expect(DepartmentHead::count())->toBe(0);
    });

    it('un segundo jefe vigente del departamento muestra el error en su campo y no escribe nada', function () {
        $other = User::factory()->create();
        app(AppointDepartmentHead::class)->handle($this->sc, null, $other, DepartmentHeadKind::Jefe, today()->subMonth()->toImmutable());

        headshipRecord($this->admin, $this->ana)
            ->call('createHeadship')
            ->set('headKind', 'jefe')
            ->set('headDepartmentId', $this->sc->id)
            ->set('headFrom', today()->toDateString())
            ->call('saveHeadship')
            ->assertHasErrors('headDepartmentId');

        expect(DepartmentHead::count())->toBe(1);
    });

    it('un docente inactivo no se puede nombrar', function () {
        $inactive = Teacher::factory()->inactive()->create(['employee_number' => '1000009']);

        headshipRecord($this->admin, $inactive)
            ->call('createHeadship')
            ->set('headKind', 'jefe')
            ->set('headDepartmentId', $this->sc->id)
            ->set('headFrom', today()->toDateString())
            ->call('saveHeadship')
            ->assertHasErrors('headKind');

        expect(DepartmentHead::count())->toBe(0)->and($inactive->fresh()->user_id)->toBeNull();
    });
});

describe('listar y terminar', function () {
    it('lista las jefaturas vigentes y las pasadas del usuario del docente, y no las de otros', function () {
        $user = User::factory()->withRole(Role::Docente)->create(['username' => '1000001']);
        $this->ana->update(['user_id' => $user->id]);
        $past = DepartmentHead::factory()->create(['user_id' => $user->id, 'department_id' => $this->ii->id, 'campus_id' => null, 'kind' => DepartmentHeadKind::Jefe, 'valid_from' => today()->subYear(), 'valid_to' => today()->subMonths(6)]);
        $now = DepartmentHead::factory()->create(['user_id' => $user->id, 'department_id' => $this->sc->id, 'campus_id' => $this->c2->id, 'kind' => DepartmentHeadKind::JefeProyectoDocente, 'valid_from' => today()->subMonth(), 'valid_to' => null]);
        DepartmentHead::factory()->create(['user_id' => User::factory()->create()->id, 'department_id' => $this->ii->id, 'campus_id' => $this->c1->id, 'kind' => DepartmentHeadKind::JefeProyectoDocente, 'valid_from' => today()->subMonth()]);

        headshipRecord($this->admin, $this->ana)
            ->assertSeeInOrder(['Jefaturas', 'Jefe de proyecto docente', 'Sistemas y Computación', 'Campus II', 'Vigente', 'Jefe de departamento', 'Ingeniería Industrial', 'Terminada'])
            ->assertViewHas('headships', fn ($rows) => $rows->pluck('id')->all() === [$now->id, $past->id]);
    });

    it('termina una jefatura con su último día y deja de cubrir', function () {
        $user = User::factory()->withRole(Role::Docente)->create(['username' => '1000001']);
        $this->ana->update(['user_id' => $user->id]);
        $head = app(AppointDepartmentHead::class)->handle($this->sc, null, $user, DepartmentHeadKind::Jefe, today()->subMonth()->toImmutable());

        headshipRecord($this->admin, $this->ana)
            ->call('confirmEndHeadship', $head->id)
            ->set('endLastDay', today()->subDay()->toDateString())
            ->call('endHeadship')
            ->assertHasNoErrors();

        expect($head->fresh()->valid_to->toDateString())->toBe(today()->subDay()->toDateString())
            ->and(app(ScopedContext::class)->forUser($user->fresh())->coversDepartment(Role::JefeDepartamento, $this->sc->id))->toBeFalse();
    });

    it('no termina la jefatura de otro usuario aunque se mande su id', function () {
        $other = DepartmentHead::factory()->create(['user_id' => User::factory()->create()->id, 'department_id' => $this->ii->id, 'campus_id' => $this->c1->id, 'kind' => DepartmentHeadKind::JefeProyectoDocente, 'valid_from' => today()->subMonth()]);

        headshipRecord($this->admin, $this->ana)->call('confirmEndHeadship', $other->id)->assertNotFound();

        expect($other->fresh()->valid_to)->toBeNull();
    });
});

describe('alcance', function () {
    it('un jefe o la Subdirección no ven la sección y sus métodos dan 403', function (Role $role, array $scope) {
        $viewer = User::factory()->withRole($role, $role === Role::JefeDepartamento ? ['department_id' => $this->sc->id] : [])->create();

        headshipRecord($viewer, $this->ana)->assertDontSee('Nombrar jefatura')->assertViewHas('canAppoint', false);

        headshipRecord($viewer, $this->ana)->call('createHeadship')->assertForbidden();
        headshipRecord($viewer, $this->ana)
            ->set('headKind', 'jefe')->set('headDepartmentId', $this->sc->id)->set('headFrom', today()->toDateString())
            ->call('saveHeadship')->assertForbidden();
        headshipRecord($viewer, $this->ana)->call('confirmEndHeadship', '00000000-0000-7000-8000-000000000000')->assertForbidden();
        headshipRecord($viewer, $this->ana)->call('endHeadship')->assertForbidden();

        expect(DepartmentHead::count())->toBe(0);
    })->with([
        'jefe de SC' => [Role::JefeDepartamento, []],
        'Subdirección' => [Role::Subdireccion, []],
    ]);
});

describe('rechazos y límites', function () {
    it('un usuario inactivo se rechaza en el campo del cargo, no en el departamento', function () {
        $user = User::factory()->withRole(Role::Docente)->create(['username' => '1000001', 'is_active' => false]);
        $this->ana->update(['user_id' => $user->id]);

        headshipRecord($this->admin, $this->ana)
            ->call('createHeadship')
            ->set('headKind', 'jefe')
            ->set('headDepartmentId', $this->sc->id)
            ->set('headFrom', today()->toDateString())
            ->call('saveHeadship')
            ->assertHasErrors('headKind')
            ->assertHasNoErrors('headDepartmentId');
    });

    it('un campus que quedó de antes se ignora al nombrar a un jefe de departamento', function () {
        headshipRecord($this->admin, $this->ana)
            ->call('createHeadship')
            ->set('headKind', 'jefe_proyecto_docente')
            ->set('headCampusId', $this->c1->id)
            ->set('headKind', 'jefe')
            ->set('headDepartmentId', $this->sc->id)
            ->set('headFrom', today()->toDateString())
            ->call('saveHeadship')
            ->assertHasNoErrors();

        expect(DepartmentHead::sole()->campus_id)->toBeNull();
    });

    it('terminar con un último día anterior al inicio no falla: la jefatura que no había empezado se quita', function () {
        $user = User::factory()->withRole(Role::Docente)->create(['username' => '1000001']);
        $this->ana->update(['user_id' => $user->id]);
        $head = app(AppointDepartmentHead::class)->handle($this->sc, null, $user, DepartmentHeadKind::Jefe, today()->addMonth()->toImmutable());

        headshipRecord($this->admin, $this->ana)
            ->call('confirmEndHeadship', $head->id)
            ->set('endLastDay', today()->toDateString())
            ->call('endHeadship')
            ->assertHasNoErrors();

        expect(DepartmentHead::count())->toBe(0);
    });
});
