<?php

namespace App\Modules\Staffing\Console;

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Staffing\Actions\ImportPriorChoices;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Carga las elecciones previas (SEL-16) de la «Relación de materias»: los maestros con grupos escritos ahí ya terminaron y
 * no reciben turno en la orden de selección (ImportPriorChoices). Corre después de `academic-load:import-projection` con el
 * mismo archivo, que crea los grupos. Con `--dry-run` calcula y reporta sin escribir.
 *
 *     docker compose exec app php artisan staffing:import-prior-choices storage/app/imports/relacion.csv --no-interaction
 */
final class ImportPriorChoicesCommand extends Command
{
    protected $signature = 'staffing:import-prior-choices {file : Ruta del csv de la «Relación de materias»} {--term=20271 : Clave del periodo} {--campus=C1 : Clave del campus} {--modality=PRES : Clave de la modalidad} {--user= : Username a nombre de quien se asignan los grupos (por omisión, el usuario activo más antiguo)} {--dry-run : Calcula y reporta sin escribir}';

    protected $description = 'Marca la elección previa de los maestros que ya traen grupos en la relación de materias y les asigna esos grupos';

    public function handle(ImportPriorChoices $import): int
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

        $username = (string) $this->option('user');
        $actor = $username !== '' ? User::query()->where('username', $username)->first() : User::query()->where('is_active', true)->oldest()->first();

        if ($actor === null) {
            $this->error($username !== '' ? "No existe el usuario {$username}." : 'No hay un usuario activo a cuyo nombre asignar.');

            return self::FAILURE;
        }

        try {
            $report = $import->handle((string) $this->argument('file'), $term, $campus, $modality, $actor, (bool) $this->option('dry-run'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(($report['dry_run'] ? 'Simulacro, no se escribió nada. ' : '')."Renglones: {$report['rows']} ({$report['rows_with_teacher']} con maestro). Docentes: {$report['teachers']}. Elecciones previas ".($report['dry_run'] ? 'por registrar' : 'registradas').": {$report['recorded']}. Grupos ".($report['dry_run'] ? 'por asignar' : 'asignados').": {$report['groups_assigned']}. Tipos de espacio ".($report['dry_run'] ? 'por llenar' : 'llenados').": {$report['spaces_set']}.");

        foreach ($report['errors'] as $error) {
            $this->line("  Renglón {$error['row']}: {$error['message']}");
        }

        foreach ($report['warnings'] as $warning) {
            $this->warn($warning);
        }

        $this->line('Tipo de espacio (decisión del 25 de noviembre de 2026): LABORATORIO y SALA DE COMPUTO = sala de cómputo, SALON y AULA = aula, LABORATORIO DE REDES = laboratorio; solo llena la materia que no tiene tipo de espacio.');

        return $report['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
