<?php

use App\Modules\Core\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| `users.email` es único sin distinguir mayúsculas (`users_email_lower_unique`): el código lo busca con
| `lower(email) = ?` y el índice lo sirve; dos correos que solo difieren en mayúsculas no pueden convivir.
*/

const USERS_EMAIL_LOWER_MIGRATION = 'app/Modules/Core/Database/Migrations/2026_11_12_000001_add_lowercase_email_unique_index_to_users.php';

it('la base rechaza dos correos que solo difieren en mayúsculas', function () {
    User::factory()->create(['email' => 'ana@example.test']);

    expect(fn () => DB::transaction(fn () => User::factory()->create(['email' => 'ANA@Example.test'])))
        ->toThrow(QueryException::class, 'users_email_lower_unique');
});

it('permite varios usuarios sin correo', function () {
    User::factory()->count(2)->create(['email' => null]);

    expect(User::query()->whereNull('email')->count())->toBe(2);
});

it('la búsqueda por lower(email) puede usar el índice', function () {
    User::factory()->count(3)->create();

    DB::statement('set enable_seqscan = off');
    $plan = collect(DB::select("explain select id from users where lower(email) = 'a@example.test'"))
        ->pluck('QUERY PLAN')->implode("\n");
    DB::statement('reset enable_seqscan');

    expect($plan)->toContain('users_email_lower_unique');
});

it('la migración se detiene y lista los ids si ya hay correos repetidos sin distinguir mayúsculas', function () {
    DB::statement('drop index users_email_lower_unique');
    $first = User::factory()->create(['email' => 'luis@example.test']);
    $second = User::factory()->create(['email' => 'LUIS@example.test']);
    $other = User::factory()->create(['email' => 'otro@example.test']);

    $migration = require base_path(USERS_EMAIL_LOWER_MIGRATION);

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, "{$first->id}")
        ->and(fn () => $migration->up())->toThrow(RuntimeException::class, "{$second->id}")
        ->and(DB::table('pg_indexes')->where('indexname', 'users_email_lower_unique')->exists())->toBeFalse()
        ->and(User::query()->count())->toBe(3);

    $second->forceFill(['email' => 'luis2@example.test'])->save();
    $migration->up();

    expect(DB::table('pg_indexes')->where('indexname', 'users_email_lower_unique')->exists())->toBeTrue()
        ->and($other->fresh()->email)->toBe('otro@example.test');
});

it('la migración reemplaza el índice temporal de la demostración', function () {
    DB::statement('drop index users_email_lower_unique');
    DB::statement('create index users_email_lower_demo_idx on users (lower(email))');

    (require base_path(USERS_EMAIL_LOWER_MIGRATION))->up();

    expect(DB::table('pg_indexes')->where('indexname', 'users_email_lower_demo_idx')->exists())->toBeFalse()
        ->and(DB::table('pg_indexes')->where('indexname', 'users_email_lower_unique')->exists())->toBeTrue();
});
