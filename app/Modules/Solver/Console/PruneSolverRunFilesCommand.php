<?php

namespace App\Modules\Solver\Console;

use App\Modules\Solver\Actions\PruneSolverRunFiles;
use Illuminate\Console\Command;

/**
 * Borra los archivos de entrada y salida de las corridas que ya no se van a aceptar y las carpetas sin corrida
 * (PruneSolverRunFiles). Se corre a mano:
 *
 *     docker compose exec app php artisan solver:prune-files --days=30
 */
final class PruneSolverRunFilesCommand extends Command
{
    protected $signature = 'solver:prune-files {--days=30 : Borra los de las corridas que terminaron hace más de estos días}';

    protected $description = 'Borra los archivos de las corridas del motor fallidas, canceladas o descartadas y las carpetas sin corrida';

    public function handle(PruneSolverRunFiles $prune): int
    {
        $result = $prune->handle(max(1, (int) $this->option('days')));
        $folders = $result['orphans'] === 1 ? 'carpeta' : 'carpetas';

        $this->info("Se borraron los archivos de {$result['runs']} corridas y {$result['orphans']} {$folders} sin corrida.");

        return self::SUCCESS;
    }
}
