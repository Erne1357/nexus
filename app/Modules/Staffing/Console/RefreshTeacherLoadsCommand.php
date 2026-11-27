<?php

namespace App\Modules\Staffing\Console;

use App\Modules\Core\Models\Term;
use App\Modules\Staffing\Actions\RefreshTeacherTermLoads;
use Illuminate\Console\Command;

/**
 * Recalcula `teacher_term_loads` de un periodo ahora mismo, sin la cola (tarea 3.12): para los seeders y para
 * reparar el tablero de carga por docente.
 */
final class RefreshTeacherLoadsCommand extends Command
{
    protected $signature = 'staffing:teacher-loads {term : Clave del periodo, p. ej. 20271}';

    protected $description = 'Recalcula la carga por docente (teacher_term_loads) de un periodo';

    public function handle(RefreshTeacherTermLoads $refresh): int
    {
        $key = (string) $this->argument('term');
        $term = Term::query()->where('code', $key)->first();

        if ($term === null) {
            $this->error("No existe el periodo {$key}.");

            return self::FAILURE;
        }

        $this->info("{$refresh->handle($term)} docentes calculados en {$term->code}.");

        return self::SUCCESS;
    }
}
