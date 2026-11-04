<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
| Sección «Jefaturas» de la ficha del docente (tarea 1.14, solo admin; 3.P8 la pasa a role_assignments): lista las
| jefaturas del usuario del docente, vigentes y pasadas, nombra jefe de departamento (uno por departamento para todo
| el Tec, sin campus; confirmado el 30 de octubre de 2026) o jefe de proyecto docente (de un campus; supuesto por
| confirmar) y termina una jefatura, todo con las Actions de Core. Si el docente no tiene usuario, se le crea antes
| (SyncTeacherAccess). Un docente inactivo no se nombra.
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

function headshipFor(User $user, Role $role, string $unitId, ?string $campusId, array $dates = []): RoleAssignment
{
    return RoleAssignment::factory()->create([
        'user_id' => $user->id,
        'role' => $role,
        'org_unit_id' => $unitId,
        'campus_id' => $campusId,
        ...$dates,
    ]);
}

describe('nombrar', function () {
    it('nombra jefe de departamento a un docente sin usuario: se lo crea y su alcance cubre todo el Tec', function () {
        $page = headshipRecord($this->admin, $this->ana)
            ->call('createHeadship')
            ->set('headKind', 'jefe_departamento')
            ->set('headDepartmentId', $this->sc->id)
            ->set('headFrom', today()->subDay()->toDateString())
            ->call('saveHeadship')
            ->assertHasNoErrors();

        expect(lastToastOf($page))->toMatchArray(['type' => 'success', 'title' => 'Jefatura nombrada', 'description' => 'Jefe de departamento académico · Sistemas y Computación · 1000001']);

        $user = $this->ana->fresh()->user;
        $head = RoleAssignment::where('role', Role::JefeDepartamento)->sole();

        expect($user)->not->toBeNull()
            ->and($head)->user_id->toBe($user->id)->org_unit_id->toBe($this->sc->id)->campus_id->toBeNull();

        $context = app(Actor::class)->forUser($user->fresh());
        expect($context->heldRoles())->toContain(Role::JefeDepartamento)
            ->and($context->coversDepartment(Role::JefeDepartamento, $this->sc->id))->toBeTrue()
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

        expect(RoleAssignment::where('role', Role::JefeProyectoDocente)->sole())
            ->user_id->toBe($user->id)->campus_id->toBe($this->c2->id)->valid_to->toDateString()->toBe(today()->addMonths(6)->toDateString());
    });

    it('el jefe de proyecto docente exige campus y el jefe no lo lleva: el error sale en el campo', function () {
        headshipRecord($this->admin, $this->ana)
            ->call('createHeadship')
            ->set('headKind', 'jefe_proyecto_docente')
            ->set('headDepartmentId', $this->sc->id)
            ->set('headFrom', today()->toDateString())
            ->call('saveHeadship')
            ->assertHasErrors(['headCampusId' => 'Elige el campus.']);

        expect(RoleAssignment::where('role', Role::JefeProyectoDocente)->count())->toBe(0);
    });

    it('un segundo jefe vigente del departamento muestra el error en su campo y no escribe nada', function () {
        $other = User::factory()->create(['name' => 'Luis Mora']);
        headshipFor($other, Role::JefeDepartamento, $this->sc->id, null, ['valid_from' => today()->subMonth()->toDateString()]);
        $since = today()->subMonth()->format('d/m/Y');

        $page = headshipRecord($this->admin, $this->ana)
            ->call('createHeadship')
            ->set('headKind', 'jefe_departamento')
            ->set('headDepartmentId', $this->sc->id)
            ->set('headFrom', today()->toDateString())
            ->call('saveHeadship');

        expect($page->errors()->first('headDepartmentId'))->toBe("Sistemas y Computación ya tiene jefe en esas fechas: Luis Mora (desde {$since}). Termina ese nombramiento antes de nombrar a otra persona.");

        expect(RoleAssignment::where('role', Role::JefeDepartamento)->count())->toBe(1);
    });

    it('la misma persona no es dos veces jefe de proyecto docente del mismo departamento y campus en fechas que se traslapan', function () {
        $user = User::factory()->withRole(Role::Docente)->create(['username' => '1000001', 'name' => 'Ana Pérez']);
        $this->ana->update(['user_id' => $user->id]);
        headshipFor($user, Role::JefeProyectoDocente, $this->sc->id, $this->c2->id);

        headshipRecord($this->admin, $this->ana)
            ->call('createHeadship')
            ->set('headKind', 'jefe_proyecto_docente')
            ->set('headDepartmentId', $this->sc->id)
            ->set('headCampusId', $this->c2->id)
            ->set('headFrom', today()->toDateString())
            ->call('saveHeadship')
            ->assertHasErrors(['headDepartmentId' => 'Ana Pérez ya es Jefe de proyecto docente · Sistemas y Computación · C2 en esas fechas.']);

        expect(RoleAssignment::where('role', Role::JefeProyectoDocente)->count())->toBe(1);
    });

    it('un docente inactivo no se puede nombrar', function () {
        $inactive = Teacher::factory()->inactive()->create(['employee_number' => '1000009']);

        headshipRecord($this->admin, $inactive)
            ->call('createHeadship')
            ->set('headKind', 'jefe_departamento')
            ->set('headDepartmentId', $this->sc->id)
            ->set('headFrom', today()->toDateString())
            ->call('saveHeadship')
            ->assertHasErrors('headKind');

        expect(RoleAssignment::where('role', Role::JefeDepartamento)->count())->toBe(0)->and($inactive->fresh()->user_id)->toBeNull();
    });

    it('un usuario inactivo se rechaza en el campo del cargo, no en el departamento', function () {
        $user = User::factory()->withRole(Role::Docente)->create(['username' => '1000001', 'is_active' => false]);
        $this->ana->update(['user_id' => $user->id]);

        headshipRecord($this->admin, $this->ana)
            ->call('createHeadship')
            ->set('headKind', 'jefe_departamento')
            ->set('headDepartmentId', $this->sc->id)
            ->set('headFrom', today()->toDateString())
            ->call('saveHeadship')
            ->assertHasErrors('headKind')
            ->assertHasNoErrors('headDepartmentId');
    });

    it('P6: un departamento inactivo no sale en la lista, y forzarlo da su error en el campo', function () {
        $this->sc->update(['is_active' => false]);

        headshipRecord($this->admin, $this->ana)
            ->call('createHeadship')
            ->assertViewHas('departmentOptions', fn (array $options) => collect($options)->pluck('id')->doesntContain($this->sc->id))
            ->set('headKind', 'jefe_departamento')
            ->set('headDepartmentId', $this->sc->id)
            ->set('headFrom', today()->toDateString())
            ->call('saveHeadship')
            ->assertHasErrors(['headDepartmentId' => 'Sistemas y Computación está inactiva.']);

        expect(RoleAssignment::where('role', Role::JefeDepartamento)->count())->toBe(0);
    });

    it('forzar un cargo que no es jefatura da error en el cargo y no escribe', function () {
        headshipRecord($this->admin, $this->ana)
            ->call('createHeadship')
            ->set('headKind', 'admin')
            ->set('headDepartmentId', $this->sc->id)
            ->set('headFrom', today()->toDateString())
            ->call('saveHeadship')
            ->assertHasErrors(['headKind' => 'Elige un cargo de la lista.']);

        expect(RoleAssignment::where('role', Role::Admin)->count())->toBe(1)->and($this->ana->fresh()->user_id)->toBeNull();
    });
});

describe('listar y terminar', function () {
    it('lista las jefaturas vigentes y las pasadas del usuario del docente, y no las de otros', function () {
        $user = User::factory()->withRole(Role::Docente)->create(['username' => '1000001']);
        $this->ana->update(['user_id' => $user->id]);
        $past = headshipFor($user, Role::JefeDepartamento, $this->ii->id, null, ['valid_from' => today()->subYear()->toDateString(), 'valid_to' => today()->subMonths(6)->toDateString()]);
        $now = headshipFor($user, Role::JefeProyectoDocente, $this->sc->id, $this->c2->id, ['valid_from' => today()->subMonth()->toDateString()]);
        $elsewhere = headshipFor($user, Role::JefeProyectoDocente, $this->ii->id, $this->c1->id, ['valid_from' => today()->subMonths(2)->toDateString()]);
        headshipFor(User::factory()->create(), Role::JefeProyectoDocente, $this->ii->id, $this->c1->id);
        RoleAssignment::factory()->create(['user_id' => $user->id, 'role' => Role::Coordinador, 'campus_id' => $this->c2->id, 'career_id' => careerKey(20)]);

        headshipRecord($this->admin, $this->ana)
            ->assertSeeInOrder(['Jefaturas', 'Jefe de proyecto docente', 'Sistemas y Computación', 'Campus II', 'Vigente', 'Jefe de departamento', 'Ingeniería Industrial', 'Todo el Tec', 'Terminado'])
            ->assertViewHas('headships', fn ($rows) => $rows->pluck('assignment.id')->all() === [$now->id, $elsewhere->id, $past->id]);
    });

    it('termina una jefatura con su último día y deja de cubrir', function () {
        $user = User::factory()->withRole(Role::Docente)->create(['username' => '1000001']);
        $this->ana->update(['user_id' => $user->id]);
        $head = headshipFor($user, Role::JefeDepartamento, $this->sc->id, null);

        $page = headshipRecord($this->admin, $this->ana)
            ->call('confirmEndHeadship', $head->id)
            ->set('endLastDay', today()->subDay()->toDateString())
            ->call('endHeadship')
            ->assertHasNoErrors();

        expect(lastToastOf($page))->toMatchArray(['type' => 'success', 'title' => 'Jefatura terminada', 'description' => 'Último día: '.today()->subDay()->format('d/m/Y')]);

        expect($head->fresh()->valid_to->toDateString())->toBe(today()->subDay()->toDateString())
            ->and(app(Actor::class)->forUser($user->fresh())->coversDepartment(Role::JefeDepartamento, $this->sc->id))->toBeFalse();
    });

    it('terminar con un último día anterior al inicio no falla: la jefatura que no había empezado se quita', function () {
        $user = User::factory()->withRole(Role::Docente)->create(['username' => '1000001']);
        $this->ana->update(['user_id' => $user->id]);
        $head = headshipFor($user, Role::JefeDepartamento, $this->sc->id, null, ['valid_from' => today()->addMonth()->toDateString()]);

        $page = headshipRecord($this->admin, $this->ana)
            ->call('confirmEndHeadship', $head->id)
            ->set('endLastDay', today()->toDateString())
            ->call('endHeadship')
            ->assertHasNoErrors();

        expect(lastToastOf($page))->toMatchArray(['type' => 'success', 'title' => 'Jefatura quitada', 'description' => 'Todavía no empezaba.']);

        expect(RoleAssignment::whereKey($head->id)->exists())->toBeFalse();
    });

    it('quitar una jefatura que empieza hoy dice que empezaba hoy', function () {
        $user = User::factory()->withRole(Role::Docente)->create(['username' => '1000001']);
        $this->ana->update(['user_id' => $user->id]);
        $head = headshipFor($user, Role::JefeDepartamento, $this->sc->id, null, ['valid_from' => today()->toDateString()]);

        $page = headshipRecord($this->admin, $this->ana)
            ->call('confirmEndHeadship', $head->id)
            ->assertSee('Si empieza hoy o después y el último día es anterior a su inicio, se quita.')
            ->set('endLastDay', today()->subDay()->toDateString())
            ->call('endHeadship')
            ->assertHasNoErrors();

        expect(lastToastOf($page))->toMatchArray(['type' => 'success', 'title' => 'Jefatura quitada', 'description' => 'Empezaba hoy.'])
            ->and(RoleAssignment::whereKey($head->id)->exists())->toBeFalse();
    });

    it('terminar en una fecha posterior a su fin no cambia nada', function () {
        $user = User::factory()->withRole(Role::Docente)->create(['username' => '1000001']);
        $this->ana->update(['user_id' => $user->id]);
        $end = today()->addMonth();
        $head = headshipFor($user, Role::JefeDepartamento, $this->sc->id, null, ['valid_to' => $end->toDateString()]);

        $page = headshipRecord($this->admin, $this->ana)
            ->call('confirmEndHeadship', $head->id)
            ->set('endLastDay', today()->addMonths(2)->toDateString())
            ->call('endHeadship')
            ->assertHasNoErrors();

        expect(lastToastOf($page))->toMatchArray(['type' => 'info', 'title' => 'Sin cambios', 'description' => 'Esa jefatura ya termina el '.$end->format('d/m/Y').'.']);

        expect($head->fresh()->valid_to->toDateString())->toBe($end->toDateString());
    });

    it('no termina la jefatura de otro usuario, ni un nombramiento que no es jefatura, aunque se mande su id', function () {
        $user = User::factory()->withRole(Role::Docente)->create(['username' => '1000001']);
        $this->ana->update(['user_id' => $user->id]);
        $other = headshipFor(User::factory()->create(), Role::JefeProyectoDocente, $this->ii->id, $this->c1->id);
        $coordinator = RoleAssignment::factory()->create(['user_id' => $user->id, 'role' => Role::Coordinador, 'campus_id' => $this->c2->id, 'career_id' => careerKey(20)]);

        headshipRecord($this->admin, $this->ana)->call('confirmEndHeadship', $other->id)->assertNotFound();
        headshipRecord($this->admin, $this->ana)->call('confirmEndHeadship', $coordinator->id)->assertNotFound();

        expect($other->fresh()->valid_to)->toBeNull()->and($coordinator->fresh()->valid_to)->toBeNull();
    });
});

describe('alcance', function () {
    it('un jefe o la Subdirección no ven la sección y sus métodos dan 403', function (Role $role) {
        $viewer = User::factory()->withRole($role, $role === Role::JefeDepartamento ? ['department_id' => $this->sc->id] : [])->create();

        headshipRecord($viewer, $this->ana)->assertDontSee('Nombrar jefatura')->assertViewHas('canAppoint', false);

        headshipRecord($viewer, $this->ana)->call('createHeadship')->assertForbidden();
        headshipRecord($viewer, $this->ana)
            ->set('headKind', 'jefe_departamento')->set('headDepartmentId', $this->sc->id)->set('headFrom', today()->toDateString())
            ->call('saveHeadship')->assertForbidden();
        headshipRecord($viewer, $this->ana)->call('confirmEndHeadship', '00000000-0000-7000-8000-000000000000')->assertForbidden();
        headshipRecord($viewer, $this->ana)->call('endHeadship')->assertForbidden();

        expect(RoleAssignment::where('role', Role::JefeProyectoDocente)->count())->toBe(0);
    })->with([
        'jefe de SC' => [Role::JefeDepartamento],
        'Subdirección' => [Role::Subdireccion],
    ]);

    it('la misma persona, admin y coordinadora, con Coordinación activa ve la ficha como admin pero recibe 403 con Coordinación activa', function () {
        $both = User::factory()->admin()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])->create();

        actingAsRole($both, Role::Admin)->get(route('faculty.teachers.show', $this->ana->id))->assertOk()->assertSee('Nombrar jefatura');

        actingAsRole($both, Role::Coordinador)->get(route('faculty.teachers.show', $this->ana->id))->assertForbidden();
        expect(Gate::allows('viewAny', RoleAssignment::class))->toBeFalse()
            ->and(Gate::allows('create', RoleAssignment::class))->toBeFalse();
    });
});
