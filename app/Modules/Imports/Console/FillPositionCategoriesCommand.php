<?php

namespace App\Modules\Imports\Console;

use App\Modules\Imports\Actions\FillPositionCategories;
use Illuminate\Console\Command;

/**
 * Comando de una sola vez (docs/operacion/despliegue.md): llena la categoría de las plazas ya importadas desde
 * `sii_archivo.docentes2.claves_plazas` (PL-01). Idempotente; con `--dry-run` solo cuenta y no escribe.
 */
final class FillPositionCategoriesCommand extends Command
{
    protected $signature = 'imports:fill-position-categories {--dry-run : Solo cuenta lo que llenaría, sin escribir}';

    protected $description = 'Llena la categoría de las plazas ya importadas desde la exportación del SII archivada (PL-01)';

    public function handle(FillPositionCategories $fill): int
    {
        try {
            $result = $fill->handle((bool) $this->option('dry-run'));
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $verb = $this->option('dry-run') ? 'Llenaría' : 'Llenó';
        $this->info("{$verb} {$result['filled']} plazas; {$result['already']} ya tenían categoría; {$result['no_category']} plazas sin clave en el SII; {$result['unmatched_rows']} renglones de docentes2 sin docente en Nexus.");

        return self::SUCCESS;
    }
}
