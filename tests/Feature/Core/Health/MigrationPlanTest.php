<?php

use App\Modules\Core\Queries\MigrationPlanQuery;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/HealthTestHelpers.php';

/*
| El plan de migraciones (tarea 1.31): lo leen /health y el deployer.
*/

it('lista pendientes, desconocidas y duplicadas', function () {
    $existing = (string) DB::table('migrations')->orderBy('id')->value('migration');
    DB::table('migrations')->insert(['migration' => '2998_01_01_000000_sobrante', 'batch' => 99]);
    $directory = healthTempDirectory();
    healthFakeMigration($directory);
    healthFakeMigration($directory, $existing);
    app('migrator')->path($directory);

    $plan = app(MigrationPlanQuery::class)->run();

    expect($plan->pending)->toBe(['2999_01_01_000000_health_pending'])
        ->and($plan->unknown)->toBe(['2998_01_01_000000_sobrante'])
        ->and($plan->duplicates)->toBe([$existing])
        ->and($plan->pendingContracts)->toBe([]);
});

it('marca como contracción la pendiente con el atributo Contract', function () {
    $directory = healthTempDirectory();
    healthFakeMigration($directory, '2999_01_01_000001_health_expand');
    healthFakeMigration($directory, '2999_01_01_000002_health_contract', contract: true);
    app('migrator')->path($directory);

    $plan = app(MigrationPlanQuery::class)->run();

    expect($plan->pending)->toBe(['2999_01_01_000001_health_expand', '2999_01_01_000002_health_contract'])
        ->and($plan->pendingContracts)->toBe(['2999_01_01_000002_health_contract']);
});

it('el comando imprime el plan en JSON', function () {
    $directory = healthTempDirectory();
    healthFakeMigration($directory, '2999_01_01_000002_health_contract', contract: true);
    app('migrator')->path($directory);

    $this->artisan('nexus:migration-plan --json')
        ->expectsOutputToContain('"pending_contracts":["2999_01_01_000002_health_contract"]')
        ->assertSuccessful();
});

it('el comando sin --json imprime una tabla en español', function () {
    $directory = healthTempDirectory();
    healthFakeMigration($directory);
    app('migrator')->path($directory);

    $this->artisan('nexus:migration-plan')
        ->expectsOutputToContain('Pendiente')
        ->assertSuccessful();
});
