<?php

use App\Modules\Academic\Actions\CloseRoleScopes;
use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\RoleScope;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\ScopedContext;
use Spatie\Activitylog\Models\Activity;

/*
| CloseRoleScopes (confirmado el 29 de octubre de 2026): al borrar un departamento o una carrera, sus alcances vigentes que
| empezaron antes de hoy terminan ayer; los que empiezan hoy o después se eliminan, porque el CHECK
| `valid_to >= valid_from` impide cerrarlos antes de empezar; los vencidos y los de otro registro no
| se tocan. Cada cambio queda en la bitácora sobre el usuario del alcance.
*/

beforeEach(function () {
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->department = Department::factory()->create(['code' => 'SIS']);
    $this->other = Department::factory()->create();
    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->c2->id, 'department_id' => $this->department->id])->create();
});

function departmentScope(User $user, string $departmentId, array $dates): RoleScope
{
    return RoleScope::factory()->for($user)->create([
        'role' => Role::JefeDepartamento,
        'campus_id' => $user->roleScopes()->first()?->campus_id ?? Campus::factory()->create()->id,
        'department_id' => $departmentId,
        ...$dates,
    ]);
}

it('termina ayer un alcance vigente desde hace un mes', function () {
    $scope = $this->jefe->roleScopes()->sole();

    expect(app(CloseRoleScopes::class)->handle('department_id', $this->department->id))->toBe(1);

    expect($scope->fresh()->valid_to->toDateString())->toBe(today()->subDay()->toDateString());
});

it('elimina un alcance que empieza hoy o mañana', function (int $daysFromToday) {
    $this->jefe->roleScopes()->delete();
    $scope = departmentScope($this->jefe, $this->department->id, ['valid_from' => today()->addDays($daysFromToday)->toDateString()]);

    expect(app(CloseRoleScopes::class)->handle('department_id', $this->department->id))->toBe(1);

    $entry = Activity::sole();

    expect(RoleScope::find($scope->id))->toBeNull()
        ->and($entry->event)->toBe('deleted')
        ->and($entry->description)->toBe('Cerró el alcance de '.Role::JefeDepartamento->label().' por borrar el departamento SIS')
        ->and($entry->attribute_changes['old'])->toMatchArray([
            'role' => 'jefe_departamento',
            'department_id' => $this->department->id,
            'valid_from' => today()->addDays($daysFromToday)->toDateString(),
            'valid_to' => null,
        ]);
})->with(['hoy' => 0, 'mañana' => 1]);

it('no toca un alcance que ya venció', function () {
    $this->jefe->roleScopes()->delete();
    $scope = departmentScope($this->jefe, $this->department->id, [
        'valid_from' => today()->subMonths(6)->toDateString(),
        'valid_to' => today()->subMonth()->toDateString(),
    ]);

    expect(app(CloseRoleScopes::class)->handle('department_id', $this->department->id))->toBe(0);

    expect($scope->fresh()->valid_to->toDateString())->toBe(today()->subMonth()->toDateString())
        ->and(Activity::count())->toBe(0);
});

it('no toca los alcances de otro departamento', function () {
    $otherJefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->c2->id, 'department_id' => $this->other->id])->create();

    app(CloseRoleScopes::class)->handle('department_id', $this->department->id);

    expect($otherJefe->roleScopes()->sole()->valid_to)->toBeNull();
});

it('cierra también los alcances de una carrera con career_id', function () {
    $career = Career::factory()->create();
    $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => $career->id])->create();

    expect(app(CloseRoleScopes::class)->handle('career_id', $career->id))->toBe(1);

    expect($coordinador->roleScopes()->sole()->valid_to->toDateString())->toBe(today()->subDay()->toDateString())
        ->and($this->jefe->roleScopes()->sole()->valid_to)->toBeNull();
});

it('después de cerrar, el jefe ya no ejerce el rol', function () {
    expect(app(ScopedContext::class)->forUser($this->jefe)->has(Role::JefeDepartamento))->toBeTrue();

    app(CloseRoleScopes::class)->handle('department_id', $this->department->id);

    expect(app(ScopedContext::class)->forUser($this->jefe->fresh())->has(Role::JefeDepartamento))->toBeFalse();
});

it('deja en la bitácora, sobre el usuario del alcance, qué cerró y por qué', function () {
    app(CloseRoleScopes::class)->handle('department_id', $this->department->id);

    $entry = Activity::sole();

    expect($entry->subject->is($this->jefe))->toBeTrue()
        ->and($entry->description)->toBe('Cerró el alcance de '.Role::JefeDepartamento->label().' por borrar el departamento SIS');
});

it('rechaza una columna que no es de departamento ni de carrera', function () {
    expect(fn () => app(CloseRoleScopes::class)->handle('campus_id', $this->c2->id))
        ->toThrow(InvalidArgumentException::class);
});
