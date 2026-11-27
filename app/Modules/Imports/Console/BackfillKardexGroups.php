<?php

namespace App\Modules\Imports\Console;

use App\Modules\Imports\Actions\BackfillKardexGroups as BackfillKardexGroupsAction;
use Illuminate\Console\Command;

/**
 * Comando de una sola vez (MP-04): rellena `kardex_records.sii_group_code` en lo ya migrado, desde `sii_archivo`
 * (Action BackfillKardexGroups). Idempotente; con `--dry-run` solo cuenta. No lleva datos en el código.
 */
final class BackfillKardexGroups extends Command
{
    protected $signature = 'imports:backfill-kardex-groups {--dry-run : Solo cuenta lo que rellenaría} {--term= : Limita la corrida a un periodo (p. ej. 20263)}';

    protected $description = 'Rellena el grupo del SII en el kárdex ya migrado desde sii_archivo (seleccion_materias e historia_alumno)';

    public function handle(BackfillKardexGroupsAction $backfill): int
    {
        $dry = (bool) $this->option('dry-run');
        $report = $backfill->handle($dry, $this->option('term') ?: null);

        foreach ($report['missing'] as $table) {
            $this->warn("sii_archivo.{$table} no existe: se omite.");
        }

        foreach ($report['filled'] as $source => $terms) {
            $this->line(($dry ? 'Simulacro: ' : '').array_sum($terms).' renglones del kárdex '.($dry ? 'se rellenarían' : 'rellenados')." desde {$source} (".implode(', ', array_map(fn (string $term, int $n): string => "{$term}: {$n}", array_keys($terms), $terms)).').');
        }

        $this->info(($dry ? 'Simulacro, no se escribió nada. ' : '')."Total: {$report['total']}. Siguen sin grupo (con periodo y clave del SII): {$report['pending']}.");

        return self::SUCCESS;
    }
}
