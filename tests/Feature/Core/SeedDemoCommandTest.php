<?php

use App\Modules\Core\Models\User;

/*
| `php artisan demo:seed`: borra la base y siembra la demo hasta un punto del proceso. Aquí, lo que pasa antes de borrar
| (valores desconocidos, confirmación, producción); la siembra la prueban DemoRunnerTest y DemoCheckpointsTest, porque
| migrate:fresh rompería la transacción de la prueba.
*/

it('rechaza un punto desconocido, lista los válidos y no toca la base', function () {
    User::factory()->create();

    $this->artisan('demo:seed', ['--hasta' => 'asignación', '--force' => true])
        ->expectsOutputToContain('Punto desconocido: asignación. Válidos: catalogos, validacion, propuesta, horarios, asignacion, salones, cierre, todo.')
        ->assertExitCode(2);

    expect(User::query()->count())->toBe(1);
});

it('rechaza un volumen desconocido', function () {
    $this->artisan('demo:seed', ['--hasta' => 'propuesta', '--volumen' => 'Masivo', '--force' => true])
        ->expectsOutputToContain('Volumen desconocido: Masivo. Válidos: demo, masivo.')
        ->assertExitCode(2);
});

it('rechaza una semilla que no es número', function () {
    $this->artisan('demo:seed', ['--semilla' => 'abc', '--force' => true])
        ->expectsOutputToContain('La semilla debe ser un número entero.')
        ->assertExitCode(2);
});

it('sin --force pregunta antes de borrar y con «no» deja la base como estaba', function () {
    User::factory()->create();

    $this->artisan('demo:seed', ['--hasta' => 'propuesta'])
        ->expectsConfirmation('Esto borra la base «'.config('database.connections.pgsql.database').'» y la vuelve a sembrar. ¿Seguir?', 'no')
        ->expectsOutput('Sin cambios.')
        ->assertExitCode(1);

    expect(User::query()->count())->toBe(1);
});

it('se niega en producción', function () {
    app()->detectEnvironment(fn (): string => 'production');

    $this->artisan('demo:seed', ['--force' => true])
        ->expectsOutputToContain('Los datos demo no se siembran en producción.')
        ->assertExitCode(1);
});
