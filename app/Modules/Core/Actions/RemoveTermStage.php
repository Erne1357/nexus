<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Events\TermCalendarChanged;
use App\Modules\Core\Models\TermStage;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Quita la ventana de una etapa, la general o la de un campus (con lo que el campus vuelve a la
 * general). Borra físicamente: `term_stages` no es catálogo ni bitácora; la bitácora del periodo
 * guarda la ventana quitada. Después del commit avisa con TermCalendarChanged.
 */
final class RemoveTermStage
{
    public function handle(TermStage $window): void
    {
        DB::transaction(function () use ($window): void {
            $window = $window->newQuery()->lockForUpdate()->find($window->getKey());

            if ($window === null) {
                return;
            }

            $term = $window->term;
            $campus = $window->campus;
            $where = $campus === null ? 'general' : "de {$campus->code}";

            $window->delete();

            activity()
                ->performedOn($term)
                ->event(ActivityEvent::Deleted)
                ->withChanges(['old' => [
                    'stage' => $window->stage->value,
                    'campus_id' => $window->campus_id,
                    'starts_on' => $window->starts_on->toDateString(),
                    'ends_on' => $window->ends_on->toDateString(),
                ]])
                ->log("Quitó la etapa {$window->stage->label()} ({$where}) del periodo {$term->code}");

            TermCalendarChanged::dispatch($term, $campus);
        });
    }
}
