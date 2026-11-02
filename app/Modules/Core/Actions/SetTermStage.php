<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Enums\Stage;
use App\Modules\Core\Events\TermCalendarChanged;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermStage;
use App\Modules\Core\Rules\TermRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Crea o cambia la ventana de fechas de una etapa del periodo: la general (`$campus` nulo) o la de un
 * campus, que reemplaza a la general en él (S-A5, supuesto por confirmar). Es un upsert por periodo,
 * campus y etapa. Las fechas solo informan (D4): no se comparan con las del periodo.
 *
 * El campus debe estar activo y no borrado. En una transacción, con el periodo bloqueado para
 * serializar el upsert, escribe y deja bitácora con las fechas anteriores y nuevas; si la ventana ya
 * tenía esas fechas no hace nada. Después del commit avisa con TermCalendarChanged.
 */
final class SetTermStage
{
    public function __construct(private readonly TermRules $rules) {}

    /**
     * @throws ValidationException
     */
    public function handle(Term $term, Stage $stage, ?Campus $campus, mixed $startsOn, mixed $endsOn): TermStage
    {
        [$startsOn, $endsOn] = $this->rules->dates($startsOn, $endsOn);

        return DB::transaction(function () use ($term, $stage, $campus, $startsOn, $endsOn): TermStage {
            $term->newQuery()->whereKey($term->getKey())->lockForUpdate()->firstOrFail();

            if ($campus !== null && ! Campus::query()->whereKey($campus->getKey())->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['campus_id' => 'El campus debe estar activo.']);
            }

            $window = TermStage::query()
                ->where('term_id', $term->getKey())
                ->where('campus_id', $campus?->getKey())
                ->where('stage', $stage->value)
                ->first();

            $old = $window === null ? null : [
                'starts_on' => $window->starts_on->toDateString(),
                'ends_on' => $window->ends_on->toDateString(),
            ];

            if ($old === ['starts_on' => $startsOn, 'ends_on' => $endsOn]) {
                return $window;
            }

            $window ??= new TermStage(['term_id' => $term->getKey(), 'campus_id' => $campus?->getKey(), 'stage' => $stage]);
            $window->fill(['starts_on' => $startsOn, 'ends_on' => $endsOn])->save();

            $where = $campus === null ? 'general' : "de {$campus->code}";
            $changes = ['attributes' => ['stage' => $stage->value, 'campus_id' => $campus?->getKey(), 'starts_on' => $startsOn, 'ends_on' => $endsOn]];

            if ($old !== null) {
                $changes['old'] = $old;
            }

            activity()
                ->performedOn($term)
                ->event($old === null ? ActivityEvent::Created : ActivityEvent::Updated)
                ->withChanges($changes)
                ->log(($old === null ? 'Fijó' : 'Cambió')." la etapa {$stage->label()} ({$where}) del periodo {$term->code}");

            TermCalendarChanged::dispatch($term, $campus);

            return $window;
        });
    }
}
