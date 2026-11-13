<?php

namespace App\Modules\Scheduling\Console;

use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Scheduling\Actions\SyncScheduleConflicts;
use App\Modules\Scheduling\Models\Group;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Recalcula los choques de un periodo, en todas sus modalidades con grupos o en las indicadas (SyncScheduleConflicts; la
 * carga es por modalidad, M1). Sirve después de cambios de catálogo que el validador no escucha (horas de una materia,
 * oferta de una carrera) y para los seeders de demostración.
 */
final class RecomputeConflicts extends Command
{
    protected $signature = 'scheduling:recompute-conflicts {term : Clave del periodo, p. ej. 20271} {--modality=* : Clave de modalidad, p. ej. PRES}';

    protected $description = 'Recalcula los choques de horario de un periodo';

    public function handle(SyncScheduleConflicts $sync): int
    {
        $term = Term::query()->where('code', $this->argument('term'))->first();

        if ($term === null) {
            $this->error("No existe el periodo {$this->argument('term')}.");

            return self::FAILURE;
        }

        $codes = (array) $this->option('modality');
        $modalities = Modality::query()
            ->when($codes !== [], fn (Builder $query) => $query->whereIn('code', $codes),
                fn (Builder $query) => $query->whereIn('id', Group::query()->where('term_id', $term->id)->select('modality_id')))
            ->orderBy('code')->get();

        foreach ($modalities as $modality) {
            $counts = $sync->handle($term, $modality);
            $this->line("{$modality->code}: {$counts['opened']} nuevos, {$counts['kept']} vigentes, {$counts['resolved']} resueltos");
        }

        return self::SUCCESS;
    }
}
