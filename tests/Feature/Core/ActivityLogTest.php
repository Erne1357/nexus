<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/*
| La bitácora es inmutable a nivel de BD (docs/dominio/modelo-de-datos.md). Cada intento va
| dentro de DB::transaction() para que el error revierta solo hasta su savepoint y la prueba
| pueda comprobar después que la entrada sigue intacta.
*/

it('registra nuevas entradas en la bitácora', function () {
    activity()->log('Campus C2 creado');

    expect(Activity::sole()->description)->toBe('Campus C2 creado');
});

it('no permite editar una entrada de la bitácora', function (Closure $edit) {
    $entry = activity()->log('Campus C2 creado');

    expect(fn () => DB::transaction(fn () => $edit($entry)))
        ->toThrow(QueryException::class, 'activity_log es inmutable');

    expect($entry->fresh()->description)->toBe('Campus C2 creado');
})->with([
    'con Eloquent' => fn (Activity $entry) => $entry->update(['description' => 'Campus C2 alterado']),
    'con el query builder' => fn () => DB::table('activity_log')->update(['description' => 'Campus C2 alterado']),
]);

it('no permite borrar entradas de la bitácora', function (Closure $delete) {
    $entry = activity()->log('Campus C2 creado');

    expect(fn () => DB::transaction(fn () => $delete($entry)))
        ->toThrow(QueryException::class, 'activity_log es inmutable');

    expect(Activity::whereKey($entry->id)->exists())->toBeTrue();
})->with([
    'con Eloquent' => fn (Activity $entry) => $entry->delete(),
    'con el query builder' => fn () => DB::table('activity_log')->delete(),
    'con truncate' => fn () => DB::table('activity_log')->truncate(),
]);
