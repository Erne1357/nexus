<?php

namespace App\Modules\Academic\Console;

use App\Modules\Academic\Actions\MarkCurrentSpecialties;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Comando de una sola vez (1.69): activa las especialidades más recientes de cada plan según el catálogo del SII
 * (`sii_archivo.especialidades`) y desactiva las demás (MarkCurrentSpecialties). La (carrera, retícula) del SII se
 * lleva al plan de Nexus con el curado de planes (`planes.csv`, el mismo de la migración del SII). Con `--dry-run`
 * solo cuenta e imprime el resumen por plan. Idempotente.
 *
 *     docker compose exec app php artisan academic:mark-current-specialties --dry-run --no-interaction
 */
final class MarkCurrentSpecialtiesCommand extends Command
{
    protected $signature = 'academic:mark-current-specialties {--dry-run : Solo cuenta lo que cambiaría}';

    protected $description = 'Activa las especialidades más recientes de cada plan según el SII y desactiva las demás';

    public function handle(MarkCurrentSpecialties $mark): int
    {
        if (! Schema::hasTable('sii_archivo.especialidades')) {
            $this->error('No hay archivo del SII en esta base (sii_archivo.especialidades): corre antes imports:migrate-sii.');

            return self::FAILURE;
        }

        $planFor = $this->planFor();

        if ($planFor === []) {
            $this->error('No se encontró el curado de planes (planes.csv).');

            return self::FAILURE;
        }

        $rows = [];
        $outside = 0;

        foreach (DB::table('sii_archivo.especialidades')->orderBy('_linea')->get(['especialidad', 'carrera', 'reticula', 'periodo_inicio', 'periodo_termino']) as $row) {
            $plan = $planFor[trim($row->carrera).'/'.trim($row->reticula)] ?? null;

            if ($plan === null) {
                $outside++;

                continue;
            }

            $rows[] = ['plan' => $plan, 'code' => $row->especialidad, 'start' => (string) $row->periodo_inicio, 'end' => (string) $row->periodo_termino];
        }

        $dryRun = (bool) $this->option('dry-run');
        $result = $mark->handle($rows, $dryRun);

        $this->table(['Plan', 'Más reciente', 'Activar', 'Desactivar', 'Sin cambio'], array_map(
            fn (string $plan, array $s): array => [$plan, $s['current'], $s['activated'], $s['deactivated'], $s['unchanged']],
            array_keys($result['plans']),
            $result['plans'],
        ));

        $this->line(($dryRun ? 'Simulación: no se escribió nada. ' : '')."{$outside} renglón(es) del SII de retículas fuera del curado.");

        if ($result['unknownPlans'] !== []) {
            $this->warn('Planes del curado que no existen en Nexus: '.implode(', ', $result['unknownPlans']));
        }

        if ($result['unmatched'] !== []) {
            $this->warn(count($result['unmatched']).' especialidad(es) de Nexus que el SII no lista (no se tocaron): '.implode(', ', $result['unmatched']));
        }

        return self::SUCCESS;
    }

    /**
     * «carrera/retícula» del SII → clave del plan de Nexus, del curado.
     *
     * @return array<string, string>
     */
    private function planFor(): array
    {
        $path = app_path('Modules/Imports/Sii/Curated/planes.csv');
        $stream = is_file($path) ? fopen($path, 'r') : false;

        if ($stream === false) {
            return [];
        }

        $header = fgetcsv($stream, escape: '') ?: [];
        $map = [];

        while (($values = fgetcsv($stream, escape: '')) !== false) {
            if ($values === [null] || count($values) < count($header)) {
                continue;
            }

            $row = array_combine($header, array_slice($values, 0, count($header)));
            $map[trim($row['sii_carrera']).'/'.trim($row['sii_reticula'])] = trim($row['plan']);
        }

        fclose($stream);

        return $map;
    }
}
