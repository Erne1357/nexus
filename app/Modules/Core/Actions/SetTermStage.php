<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Enums\Stage;
use App\Modules\Core\Events\TermCalendarChanged;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermStage;
use App\Modules\Core\Rules\TermRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Crea o cambia la ventana de fechas de una etapa del periodo: la general (`$modality` nula) o la de una modalidad, que
 * reemplaza a la general en ella (M4, confirmado el 12 de noviembre de 2026; ver docs/dominio/reglas-de-negocio.md,
 * Periodo y calendario). Es un upsert por periodo, modalidad y etapa. Las fechas solo informan (D4): no se comparan con
 * las del periodo.
 *
 * La modalidad debe estar activa y no borrada. En una transacción, con el periodo bloqueado para serializar el upsert,
 * escribe y deja bitácora con las fechas anteriores y nuevas; si la ventana ya tenía esas fechas no hace nada. Después
 * del commit avisa con TermCalendarChanged.
 */
final class SetTermStage
{
    public function __construct(private readonly TermRules $rules) {}

    /**
     * @throws ValidationException
     */
    public function handle(Term $term, Stage $stage, ?Modality $modality, mixed $startsOn, mixed $endsOn): TermStage
    {
        [$startsOn, $endsOn] = $this->rules->dates($startsOn, $endsOn);

        return DB::transaction(function () use ($term, $stage, $modality, $startsOn, $endsOn): TermStage {
            $term->newQuery()->whereKey($term->getKey())->lockForUpdate()->firstOrFail();

            if ($modality !== null && ! Modality::query()->whereKey($modality->getKey())->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['modality_id' => 'La modalidad debe estar activa.']);
            }

            $window = TermStage::query()
                ->where('term_id', $term->getKey())
                ->where('modality_id', $modality?->getKey())
                ->where('stage', $stage->value)
                ->first();

            $old = $window === null ? null : [
                'starts_on' => $window->starts_on->toDateString(),
                'ends_on' => $window->ends_on->toDateString(),
            ];

            if ($old === ['starts_on' => $startsOn, 'ends_on' => $endsOn]) {
                return $window;
            }

            $window ??= new TermStage(['term_id' => $term->getKey(), 'modality_id' => $modality?->getKey(), 'stage' => $stage]);
            $window->fill(['starts_on' => $startsOn, 'ends_on' => $endsOn])->save();

            $where = $modality === null ? 'general' : "de {$modality->name}";
            $changes = ['attributes' => ['stage' => $stage->value, 'modality_id' => $modality?->getKey(), 'starts_on' => $startsOn, 'ends_on' => $endsOn]];

            if ($old !== null) {
                $changes['old'] = $old;
            }

            activity()
                ->performedOn($term)
                ->event($old === null ? ActivityEvent::Created : ActivityEvent::Updated)
                ->withChanges($changes)
                ->log(($old === null ? 'Fijó' : 'Cambió')." la etapa {$stage->label()} ({$where}) del periodo {$term->code}");

            TermCalendarChanged::dispatch($term, $modality);

            return $window;
        });
    }
}
