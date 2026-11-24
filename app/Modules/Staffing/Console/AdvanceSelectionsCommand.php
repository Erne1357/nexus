<?php

namespace App\Modules\Staffing\Console;

use App\Modules\Staffing\Actions\Selection\AdvanceSelections;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * El reloj de las selecciones de carga (SEL-08, SEL-09): corre cada minuto en el scheduler y es idempotente.
 */
final class AdvanceSelectionsCommand extends Command
{
    protected $signature = 'staffing:advance-selections';

    protected $description = 'Avanza las selecciones de carga: estados, avisos de turno, ronda extra y cierre';

    public function handle(AdvanceSelections $advance): int
    {
        $result = $advance->handle(CarbonImmutable::now());

        $this->info("{$result['advanced']} cambios de estado y {$result['notices']} avisos.");

        return self::SUCCESS;
    }
}
