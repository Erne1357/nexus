<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| El modelo RoleScope y ScopedContext llegan en la tarea 0.7; aquí solo se prueba la tabla.
*/

it('guarda el alcance de un rol en un campus, departamento y carrera', function () {
    $user = User::factory()->create();
    $campus = Campus::factory()->create();

    DB::table('role_scopes')->insert([
        'user_id' => $user->id,
        'role' => Role::Coordinador->value,
        'campus_id' => $campus->id,
        'department_id' => fakeKey(7),
        'career_id' => fakeKey(12),
        'valid_from' => '2026-08-01',
        'valid_to' => null,
    ]);

    expect(DB::table('role_scopes')->where('user_id', $user->id)->first())
        ->campus_id->toBe($campus->id)
        ->department_id->toBe(fakeKey(7))
        ->career_id->toBe(fakeKey(12))
        ->valid_to->toBeNull();
});

it('rechaza un alcance de un usuario o campus que no existe', function (string $column) {
    $row = [
        'user_id' => User::factory()->create()->id,
        'role' => Role::JefeDepartamento->value,
        'campus_id' => Campus::factory()->create()->id,
        'valid_from' => '2026-08-01',
    ];

    expect(fn () => DB::table('role_scopes')->insert([...$row, $column => fakeKey(999_999)]))
        ->toThrow(QueryException::class, 'foreign key');
})->with(['user_id', 'campus_id']);

it('rechaza una vigencia que termina antes de empezar y acepta una de un solo día', function () {
    $row = [
        'user_id' => User::factory()->create()->id,
        'role' => Role::Docente->value,
        'valid_from' => '2026-08-01',
    ];

    DB::table('role_scopes')->insert([...$row, 'valid_to' => '2026-08-01']);

    expect(fn () => DB::table('role_scopes')->insert([...$row, 'valid_to' => '2026-07-31']))
        ->toThrow(QueryException::class, 'role_scopes_validity_check');
});
