<?php

use App\Modules\Core\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

it('autentica con el modelo User de Core', function () {
    expect(config('auth.providers.users.model'))->toBe(User::class);
});

it('no permite dos usuarios con el mismo username', function () {
    User::factory()->create(['username' => '19110001']);

    expect(fn () => User::factory()->create(['username' => '19110001']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('permite usuarios sin correo', function () {
    $first = User::factory()->create(['email' => null]);
    $second = User::factory()->create(['email' => null]);

    expect($first->fresh()->email)->toBeNull()
        ->and($second->fresh()->email)->toBeNull();
});

it('crea los usuarios activos y obligados a cambiar su contraseña en el primer acceso', function () {
    $user = User::create(['username' => 'docente1', 'name' => 'Docente Uno', 'password' => 'secreto'])->fresh();

    expect($user->is_active)->toBeTrue()
        ->and($user->must_change_password)->toBeTrue()
        ->and($user->last_login_at)->toBeNull();
});

it('agrega username a una tabla users que ya tiene filas', function () {
    $migration = require base_path('app/Modules/Core/Database/Migrations/2026_10_27_000000_add_login_fields_to_users_table.php');
    $migration->down();
    DB::table('users')->insert([
        ['name' => 'Usuario previo', 'email' => 'previo@itcj.edu.mx', 'password' => 'x'],
        ['name' => 'Otro previo', 'email' => 'otro@itcj.edu.mx', 'password' => 'x'],
    ]);

    $migration->up();

    expect(DB::table('users')->orderBy('id')->pluck('username')->all())->toBe(['previo@itcj.edu.mx', 'otro@itcj.edu.mx'])
        ->and(fn () => DB::table('users')->insert(['name' => 'Sin username', 'password' => 'x']))
        ->toThrow(QueryException::class, 'not-null');
});
