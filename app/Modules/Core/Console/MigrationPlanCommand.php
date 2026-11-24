<?php

namespace App\Modules\Core\Console;

use App\Modules\Core\Queries\MigrationPlanQuery;
use Illuminate\Console\Command;

/**
 * Imprime qué migraciones faltan, cuáles sobran y cuáles de las pendientes son contracciones. El deployer lo lee con
 * `--json` antes de migrar. Siempre sale con 0: decidir es cosa de quien lo llama.
 */
final class MigrationPlanCommand extends Command
{
    protected $signature = 'nexus:migration-plan {--json : Imprime el plan como JSON}';

    protected $description = 'Muestra las migraciones pendientes, desconocidas, duplicadas y las contracciones pendientes';

    public function handle(MigrationPlanQuery $query): int
    {
        $plan = $query->run();

        if ($this->option('json')) {
            $this->line((string) json_encode($plan->toArray(), JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $labels = ['pending' => 'Pendiente', 'unknown' => 'Desconocida', 'duplicates' => 'Duplicada', 'pending_contracts' => 'Contracción pendiente'];
        $rows = [];

        foreach ($plan->toArray() as $kind => $names) {
            foreach ($names as $name) {
                $rows[] = [$labels[$kind], $name];
            }
        }

        if ($rows === []) {
            $this->info('Sin migraciones pendientes ni diferencias con la base.');

            return self::SUCCESS;
        }

        $this->table(['Tipo', 'Migración'], $rows);

        return self::SUCCESS;
    }
}
