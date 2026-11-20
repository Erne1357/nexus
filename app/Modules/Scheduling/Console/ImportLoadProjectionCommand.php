<?php

namespace App\Modules\Scheduling\Console;

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Scheduling\Actions\ImportLoadProjection;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Carga la proyección de carga de un departamento desde su xlsx (ImportLoadProjection, R-PRY-1). Con `--dry-run` calcula y
 * reporta sin escribir. Días por créditos: 5 = lunes a viernes, 4 = lunes a jueves y 1 = viernes; 6 = lunes a viernes con la sexta hora por acomodar a mano (confirmado); 2 y 3
 * son supuestos por confirmar (2 y 3: los primeros días; 6: lunes a viernes y la sexta hora el lunes).
 */
final class ImportLoadProjectionCommand extends Command
{
    protected $signature = 'academic-load:import-projection {file : Ruta del xlsx (formato proyecto docente)} {--term=20271 : Clave del periodo} {--campus=C1 : Clave del campus} {--modality=PRES : Clave de la modalidad} {--dry-run : Calcula y reporta sin escribir}';

    protected $description = 'Carga la proyección de carga de un departamento (grupos, cupos y horas) desde un xlsx';

    public function handle(ImportLoadProjection $import): int
    {
        $term = Term::query()->where('code', $this->option('term'))->first();
        $campus = Campus::query()->where('code', $this->option('campus'))->first();
        $modality = Modality::query()->where('code', $this->option('modality'))->first();

        foreach ([[$term, 'periodo', 'term'], [$campus, 'campus', 'campus'], [$modality, 'modalidad', 'modality']] as [$model, $label, $option]) {
            if ($model === null) {
                $this->error("No existe el {$label} {$this->option($option)}.");

                return self::FAILURE;
            }
        }

        try {
            $report = $import->handle((string) $this->argument('file'), $term, $campus, $modality, (bool) $this->option('dry-run'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(($report['dry_run'] ? 'Simulacro, no se escribió nada. ' : '')."Filas leídas: {$report['rows']}. Grupos ".($report['dry_run'] ? 'por crear' : 'creados').": {$report['created']} ({$report['sessions']} sesiones).");

        foreach ($report['per_department'] as $department => $count) {
            $this->line("  Departamento {$department}: {$count} grupos");
        }

        if ($report['skipped_by_kind'] !== []) {
            $this->warn('Filas que no se cargaron: '.collect($report['skipped_by_kind'])->map(fn (int $count, string $kind): string => "{$kind} {$count}")->implode(', ').'.');

            foreach ($report['skipped'] as $skipped) {
                $this->line("  Fila {$skipped['row']} [{$skipped['kind']}]: {$skipped['message']}");
            }
        }

        foreach ($report['warnings'] as $warning) {
            $this->warn($warning);
        }

        $this->line('Días por créditos: 5 = lunes a viernes, 4 = lunes a jueves, 1 = viernes, 6 = lunes a viernes con la sexta hora por acomodar a mano (confirmado); 2 y 3 = primeros días hábiles (supuesto por confirmar).');

        if ($report['conflicts'] !== null) {
            $this->line("Choques recalculados: {$report['conflicts']['opened']} nuevos, {$report['conflicts']['kept']} vigentes, {$report['conflicts']['resolved']} resueltos.");
        }

        return self::SUCCESS;
    }
}
