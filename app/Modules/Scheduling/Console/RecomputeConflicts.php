<?php

namespace App\Modules\Scheduling\Console;

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Scheduling\Actions\SyncScheduleConflicts;
use App\Modules\Scheduling\Models\Group;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Recalcula los choques de un periodo, en todos sus campus con grupos o en los indicados (SyncScheduleConflicts). Sirve
 * después de cambios de catálogo que el validador no escucha (horas de una materia, oferta de una carrera) y para los
 * seeders de demostración.
 */
final class RecomputeConflicts extends Command
{
    protected $signature = 'scheduling:recompute-conflicts {term : Clave del periodo, p. ej. 20271} {--campus=* : Clave de campus, p. ej. C2}';

    protected $description = 'Recalcula los choques de horario de un periodo';

    public function handle(SyncScheduleConflicts $sync): int
    {
        $term = Term::query()->where('code', $this->argument('term'))->first();

        if ($term === null) {
            $this->error("No existe el periodo {$this->argument('term')}.");

            return self::FAILURE;
        }

        $codes = (array) $this->option('campus');
        $campuses = Campus::query()
            ->when($codes !== [], fn (Builder $query) => $query->whereIn('code', $codes),
                fn (Builder $query) => $query->whereIn('id', Group::query()->where('term_id', $term->id)->select('campus_id')))
            ->orderBy('code')->get();

        foreach ($campuses as $campus) {
            $counts = $sync->handle($term, $campus);
            $this->line("{$campus->code}: {$counts['opened']} nuevos, {$counts['kept']} vigentes, {$counts['resolved']} resueltos");
        }

        return self::SUCCESS;
    }
}
