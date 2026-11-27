<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermStage;
use App\Modules\Core\Queries\TermCalendarQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Copia el calendario de etapas de un periodo a otro (ver docs/dominio/reglas-de-negocio.md, Periodo y
 * calendario): reemplaza todas las ventanas del destino por las del origen corridas un número entero de semanas
 * (TermCalendarQuery::copyPreview), así que cada ventana conserva su día de la semana. Las ventanas de modalidades
 * inactivas o borradas no se copian. Devuelve cuántas ventanas escribió.
 *
 * Todo en UNA transacción, con el destino bloqueado: se quitan las ventanas actuales (RemoveTermStage) y se
 * escriben las nuevas (SetTermStage); sus avisos TermCalendarChanged salen hasta el commit y, si algo falla, no
 * queda nada. Deja además una entrada de bitácora en el destino con los conteos. La autorización
 * (TermPolicy::manageCalendar) es de la página.
 */
final class CopyTermCalendar
{
    public function __construct(
        private readonly TermCalendarQuery $calendar,
        private readonly SetTermStage $setStage,
        private readonly RemoveTermStage $removeStage,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Term $target, Term $source): int
    {
        if ($source->is($target)) {
            throw ValidationException::withMessages(['source' => 'Elige un periodo distinto al que vas a llenar.']);
        }

        return DB::transaction(function () use ($target, $source): int {
            $target->newQuery()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();

            $preview = $this->calendar->copyPreview($target, $source);

            if ($preview['windows'] === []) {
                throw ValidationException::withMessages(['source' => "El periodo {$source->code} no tiene calendario que copiar."]);
            }

            $removed = 0;

            foreach (TermStage::query()->where('term_id', $target->getKey())->get() as $window) {
                $this->removeStage->handle($window);
                $removed++;
            }

            foreach ($preview['windows'] as $window) {
                $this->setStage->handle($target, $window['stage'], $window['modality'], $window['starts_on'], $window['ends_on']);
            }

            $written = count($preview['windows']);

            activity()
                ->performedOn($target)
                ->withProperties([
                    'source' => $source->code,
                    'shift_days' => $preview['shiftDays'],
                    'written' => $written,
                    'removed' => $removed,
                    'skipped' => count($preview['skipped']),
                ])
                ->log("Copió el calendario del periodo {$source->code}");

            return $written;
        });
    }
}
