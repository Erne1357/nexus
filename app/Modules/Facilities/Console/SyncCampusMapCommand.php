<?php

namespace App\Modules\Facilities\Console;

use App\Modules\Facilities\Actions\SyncCampusMap;
use Illuminate\Console\Command;

/**
 * Corre SyncCampusMap y imprime el resumen. `--dry-run` hace todo y revierte, sin escribir nada.
 *
 *     docker compose exec app php artisan facilities:sync-campus-map --dry-run --no-interaction
 */
final class SyncCampusMapCommand extends Command
{
    protected $signature = 'facilities:sync-campus-map {--dry-run : Calcula el resumen sin escribir}';

    protected $description = 'Normaliza edificios, salones y departamentos con el mapa del Campus I';

    public function handle(SyncCampusMap $sync): int
    {
        $dry = (bool) $this->option('dry-run');
        $report = $sync->handle($dry);

        $this->info($dry ? 'Simulación (no se escribió nada):' : 'Mapa sincronizado:');
        $this->table(['Concepto', 'Cantidad'], [
            ['Edificios creados', $report['buildings_created']],
            ['Edificios renombrados o reactivados', $report['buildings_updated']],
            ['Edificios desactivados', $report['buildings_deactivated']],
            ['Salones movidos al edificio del mapa', $report['rooms_moved']],
            ['Salones ligados al mapa sin moverlos', $report['rooms_linked']],
            ['Salones creados desde el mapa', $report['rooms_created']],
            ['Salones del SII sin cruzar (se dejan como están)', count($report['rooms_unmatched'])],
            ['Campus II: edificios creados', $report['c2_buildings_created']],
            ['Campus II: salones movidos', $report['c2_rooms_moved']],
            ['Departamentos actualizados', $report['departments_updated']],
        ]);

        if ($report['rooms_unmatched'] !== []) {
            $this->line('Sin cruzar: '.implode(', ', $report['rooms_unmatched']));
        }

        foreach ($report['conflicts'] as $conflict) {
            $this->warn($conflict);
        }

        return self::SUCCESS;
    }
}
