<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Queries\Parallels;
use Illuminate\Support\Facades\Gate;

/*
| ParallelsPolicy (tarea 8.8; reglas-de-negocio.md, «Aprobaciones y permisos», fila «Armar paralelos»): ven la pestaña
| quienes ven la bandeja del departamento; arma el jefe del departamento que imparte (`scheduling.parallels.arrange`), de
| cualquier departamento (confirmado el 17 de noviembre de 2026). El jefe de proyecto docente, Subdirección y el
| coordinador no arman (supuesto por confirmar).
*/

beforeEach(function () {
    $this->modality = Modality::query()->where('code', 'PRES')->sole();
    $this->cb = Department::factory()->create(['code' => 'CB']);
    $this->sc = Department::factory()->create(['code' => 'SC']);
});

/**
 * @return array{view: bool, arrange: bool}
 */
function parallelsAbilities(User $user, ?Department $department = null): array
{
    $arguments = [Parallels::class, test()->modality, $department ?? test()->cb];

    return ['view' => Gate::forUser($user)->allows('view', $arguments), 'arrange' => Gate::forUser($user)->allows('arrange', $arguments)];
}

it('el jefe del departamento que imparte ve y arma sus paralelos', function () {
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->cb->id])->create();

    expect(parallelsAbilities($jefe))->toBe(['view' => true, 'arrange' => true]);
});

it('el jefe de otro departamento no ve ni arma los paralelos de CB', function () {
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sc->id])->create();

    expect(parallelsAbilities($jefe))->toBe(['view' => false, 'arrange' => false])
        ->and(parallelsAbilities($jefe, $this->sc))->toBe(['view' => true, 'arrange' => true]);
});

it('el jefe de proyecto docente y Subdirección los ven sin armarlos, y el coordinador no los ve', function () {
    $jpd = User::factory()->withRole(Role::JefeProyectoDocente, ['modality_id' => $this->modality->id, 'department_id' => $this->cb->id])->create();
    $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();
    $coordinador = User::factory()->withRole(Role::Coordinador, ['modality_id' => $this->modality->id, 'career_id' => Career::factory()->create(['department_id' => $this->cb->id])->id])->create();

    expect(parallelsAbilities($jpd))->toBe(['view' => true, 'arrange' => false])
        ->and(parallelsAbilities($subdireccion))->toBe(['view' => true, 'arrange' => false])
        ->and(parallelsAbilities($coordinador))->toBe(['view' => false, 'arrange' => false]);
});

it('el admin ve y arma los de cualquier departamento', function () {
    expect(parallelsAbilities(User::factory()->admin()->create()))->toBe(['view' => true, 'arrange' => true]);
});
