<?php

namespace App\Modules\Core\Health\Checks;

use App\Modules\Core\Health\Check;
use App\Modules\Core\Health\CheckResult;
use App\Modules\Core\Queries\MigrationPlanQuery;

/**
 * Pendientes o con nombre repetido: `fail` (el código nuevo correría sobre un esquema viejo). Desconocidas (en la base,
 * no en el código; tras volver a una imagen anterior): `degraded`, porque con expand/contract el código viejo tolera el
 * esquema nuevo.
 */
final class MigrationsCheck implements Check
{
    public function __construct(private readonly MigrationPlanQuery $plan) {}

    public function name(): string
    {
        return 'migrations';
    }

    public function critical(): bool
    {
        return true;
    }

    public function run(): CheckResult
    {
        $plan = $this->plan->run();
        $detail = ['pending' => $plan->pending, 'unknown' => $plan->unknown, 'duplicates' => $plan->duplicates];

        return match (true) {
            $plan->pending !== [] => CheckResult::fail('Hay migraciones pendientes.', $detail),
            $plan->duplicates !== [] => CheckResult::fail('Dos carpetas tienen una migración con el mismo nombre.', $detail),
            $plan->unknown !== [] => CheckResult::degraded('La base tiene migraciones que el código no conoce.', $detail),
            default => CheckResult::ok($detail),
        };
    }
}
