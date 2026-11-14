<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use Livewire\Livewire;

/*
| La ficha del docente y sus salidas de departamento (validación del 14 de noviembre de 2026, tarea 1.21): «Quitar de
| <Departamento>» lleva el nombre del departamento y dice la consecuencia (D10), y la ficha muestra el motivo con que
| un jefe lo quitó de su departamento en la validación de docentes (D8), que la revisión deja en la bitácora del
| docente (evento `discharged`, propiedad `reason`). Lo ven quienes dan de baja: el admin y la Subdirección (supuesto
| por confirmar).
*/

beforeEach(function () {
    $this->sc = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->ii = Department::factory()->create(['code' => 'II', 'name' => 'Ingeniería Industrial']);
    $this->ana = Teacher::factory()->adscribedTo($this->ii)->create(['rfc' => 'PRUE000001AB1', 'name' => 'Ana Pérez']);
    TeacherDepartment::factory()->for($this->ana)->for($this->sc)->create(['is_primary' => false, 'created_at' => now()->addSecond()]);

    $this->admin = User::factory()->admin()->create();
    $this->subdireccion = User::factory()->withRole(Role::Subdireccion)->create();
    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sc->id])->create(['name' => 'Jefa de Sistemas']);
    $this->jefeIi = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->ii->id])->create();
});

/**
 * La entrada de bitácora que deja la revisión de docentes al quitar a un docente de su departamento (paquete A de la
 * tarea 1.21): sobre el docente, a nombre del jefe, con el departamento, si quedó dado de baja y el motivo.
 *
 * @param  array{kind: string, text?: string}|null  $reason
 */
function logDischarge(Teacher $teacher, User $by, Department $department, bool $deactivated, ?array $reason): void
{
    activity()
        ->performedOn($teacher)
        ->causedBy($by)
        ->event('discharged')
        ->withProperties(['review_id' => null, 'department_id' => $department->id, 'deactivated' => $deactivated, 'reason' => $reason])
        ->log("Quitó al docente {$teacher->rfc} de {$department->name} en la validación de docentes");
}

describe('«Quitar de <Departamento>» (D10)', function () {
    it('D10: el botón y el modal nombran el departamento y dicen la consecuencia', function () {
        $sc = $this->ana->adscriptions()->where('department_id', $this->sc->id)->sole();
        $ii = $this->ana->adscriptions()->where('department_id', $this->ii->id)->sole();

        $page = Livewire::actingAs($this->admin)->test('faculty::pages.teacher', ['teacher' => $this->ana->id])
            ->assertSee('Quitar de Sistemas y Computación')->assertSee('Quitar de Ingeniería Industrial')
            ->assertDontSee('Quitar de SC')
            ->call('confirmRemoveAdscription', $ii->id)
            ->assertSee('¿Quitar al docente PRUE000001AB1 de Ingeniería Industrial? Solo sale de Ingeniería Industrial: sigue activo y su adscripción principal pasa a Sistemas y Computación. Queda en la bitácora.')
            ->call('removeAdscription');

        expect(lastToastOf($page))->title->toBe('Quitado de Ingeniería Industrial');

        $page->call('confirmRemoveAdscription', $sc->id)
            ->assertSee('Es su último departamento: queda dado de baja y su cuenta se inhabilita.');
    });
});

describe('motivo de la salida (D8)', function () {
    it('D8: el admin y la Subdirección ven en la ficha de quién lo quitó, de qué departamento, cuándo y por qué', function (string $who) {
        $this->travelTo(now()->setDate(2026, 11, 20));
        logDischarge($this->ana, $this->jefe, $this->sc, false, ['kind' => 'department_change']);
        logDischarge($this->ana, $this->jefeIi, $this->ii, true, ['kind' => 'other', 'text' => 'Se fue a otro Tec']);

        $dom = domOf($this->actingAs($this->{$who})->get(route('faculty.teachers.show', $this->ana->id))->assertOk());
        $departures = $dom->querySelector('[data-testid="departures"]');

        expect(textOf($departures))->toContain('Salidas de departamento')
            ->toContain('Sistemas y Computación', '20/11/2026', 'Jefa de Sistemas', 'Cambio de departamento')
            ->toContain('Ingeniería Industrial', 'Quedó dado de baja', 'Otro: Se fue a otro Tec');
    })->with(['admin', 'subdireccion']);

    it('D8: sin motivo lo dice, y las etiquetas son las de la revisión', function (string $kind, string $label) {
        logDischarge($this->ana, $this->jefe, $this->sc, false, ['kind' => $kind]);
        logDischarge($this->ana, $this->jefe, $this->sc, false, null);

        $text = textOf(domOf($this->actingAs($this->admin)->get(route('faculty.teachers.show', $this->ana->id)))->querySelector('[data-testid="departures"]'));

        expect($text)->toContain($label, 'Sin motivo');
    })->with([
        'jubilación' => ['retirement', 'Jubilación'],
        'renuncia' => ['resignation', 'Renuncia'],
        'cambio de departamento' => ['department_change', 'Cambio de departamento'],
    ]);

    it('D8: el jefe de un departamento del docente no ve los motivos (supuesto por confirmar)', function () {
        logDischarge($this->ana, $this->jefe, $this->sc, false, ['kind' => 'resignation']);

        $this->actingAs($this->jefeIi)->get(route('faculty.teachers.show', $this->ana->id))
            ->assertOk()
            ->assertDontSee('Salidas de departamento')
            ->assertDontSee('Renuncia');
    });

    it('D8: sin salidas en la bitácora no muestra la sección', function () {
        $this->actingAs($this->admin)->get(route('faculty.teachers.show', $this->ana->id))
            ->assertOk()
            ->assertDontSee('Salidas de departamento');
    });
});
