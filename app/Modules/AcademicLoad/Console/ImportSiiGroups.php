<?php

namespace App\Modules\AcademicLoad\Console;

use App\Modules\AcademicLoad\Actions\History\ImportSiiGroupHistory;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Lleva el historial de grupos del SII (`sii_archivo`) a `historical_groups` (tarea 5.6, ImportSiiGroupHistory). Con
 * `--dry-run` calcula y reporta sin escribir.
 */
final class ImportSiiGroups extends Command
{
    protected $signature = 'academic-load:import-sii-groups {--periodos= : Periodos separados por coma (solo tipo 1 y 3); todos los del archivo si se omite} {--dry-run : Calcula y reporta sin escribir}';

    protected $description = 'Importa el historial de grupos del SII ya migrado (cupo e inscritos por materia y periodo)';

    public function handle(ImportSiiGroupHistory $import): int
    {
        $option = trim((string) $this->option('periodos'));
        $terms = $option === '' ? null : array_values(array_filter(array_map('trim', explode(',', $option))));

        try {
            $report = $import->handle($terms, (bool) $this->option('dry-run'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(($report['dry_run'] ? 'Simulacro, no se escribió nada. ' : '')."Grupos: {$report['groups']} en ".count($report['terms']).' periodos.');
        $this->line("Sin campus: {$report['without_campus']}. Con carrera exclusiva que Nexus no tiene: {$report['without_career']}. Cancelados (estatus): {$report['cancelled']}. Cupo ilegible (queda en 0): {$report['invalid_capacity']}.");

        if ($report['ignored_terms'] !== []) {
            $this->warn('Periodos ignorados (verano o que no están en el archivo): '.implode(', ', $report['ignored_terms']).'.');
        }

        if ($report['unknown_subjects'] !== []) {
            $total = array_sum($report['unknown_subjects']);
            $sample = implode(', ', array_slice(array_keys($report['unknown_subjects']), 0, 10));
            $this->warn(count($report['unknown_subjects'])." materias del SII que Nexus no tiene ({$total} grupos sin importar): {$sample}.");
        }

        return self::SUCCESS;
    }
}
