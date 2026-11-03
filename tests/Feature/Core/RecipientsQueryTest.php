<?php

use App\Modules\Core\Database\Seeders\RoleSeeder;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\RecipientsQuery;
use Carbon\CarbonImmutable;

/*
| A quién avisar: los usuarios activos que, con el rol, cubren el campus y, si se piden, el departamento
| o la carrera, con la misma regla que las policies (ScopedContext::covers), pero sin el admin, que lo
| cubre todo y no es destinatario de los avisos del proceso. Departamentos 10 y 11, carreras 20 y 21.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 12:00', 'America/Ciudad_Juarez'));
    $this->seed(RoleSeeder::class);

    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
});

/**
 * @return list<string>
 */
function recipientNames(Role $role, Campus $campus, ?string $department = null, ?string $career = null): array
{
    return app(RecipientsQuery::class)->for($role, $campus, $department, $career)->pluck('name')->sort()->values()->all();
}

it('el jefe de departamento, sin campus, recibe en cualquier campus activo los avisos de su departamento', function () {
    User::factory()->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10)])->create(['name' => 'Jefe 10']);
    User::factory()->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(11)])->create(['name' => 'Jefe 11']);

    expect(recipientNames(Role::JefeDepartamento, $this->c1, departmentKey(10)))->toBe(['Jefe 10'])
        ->and(recipientNames(Role::JefeDepartamento, $this->c2, departmentKey(10)))->toBe(['Jefe 10']);
});

it('el coordinador solo recibe los de su carrera en su campus', function () {
    User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])->create(['name' => 'Coord 20 C2']);
    User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c1->id, 'career_id' => careerKey(20)])->create(['name' => 'Coord 20 C1']);
    User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(21)])->create(['name' => 'Coord 21 C2']);

    expect(recipientNames(Role::Coordinador, $this->c2, career: careerKey(20)))->toBe(['Coord 20 C2']);
});

it('deja fuera al admin, a los usuarios inactivos, a los alcances vencidos y a otros roles', function () {
    User::factory()->admin()->create(['name' => 'Admin']);
    // Un jefe por departamento en cada fecha (AZ-S7): el inactivo releva al vencido.
    User::factory()->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10), 'valid_from' => '2026-07-01'])->create(['name' => 'Inactivo', 'is_active' => false]);
    User::factory()->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10), 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-30'])->create(['name' => 'Vencido']);
    User::factory()->withRole(Role::JefeProyectoDocente, ['department_id' => departmentKey(10), 'campus_id' => $this->c2->id])->create(['name' => 'JPD']);

    expect(recipientNames(Role::JefeDepartamento, $this->c2, departmentKey(10)))->toBe([]);
});

it('un admin que además es jefe recibe los de su departamento, y no los de otros', function () {
    User::factory()->admin()->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10)])->create(['name' => 'Admin y jefe 10']);

    expect(recipientNames(Role::JefeDepartamento, $this->c2, departmentKey(10)))->toBe(['Admin y jefe 10'])
        ->and(recipientNames(Role::JefeDepartamento, $this->c2, departmentKey(11)))->toBe([]);
});

it('no avisa en un campus inactivo', function () {
    User::factory()->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10)])->create(['name' => 'Jefe 10']);
    $this->c1->update(['is_active' => false]);

    expect(recipientNames(Role::JefeDepartamento, $this->c1, departmentKey(10)))->toBe([]);
});

it('la subdirección, de todo el Tec, recibe los avisos de cualquier campus activo', function () {
    User::factory()->withRole(Role::Subdireccion)->create(['name' => 'Subdirección']);

    expect(recipientNames(Role::Subdireccion, $this->c2))->toBe(['Subdirección']);
});
