<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Events\CurrentTermChanged;
use App\Modules\Core\Models\Term;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Marca un periodo como el actual, el de omisión de la credencial (D3, confirmado el 1 de noviembre
 * de 2026; cualquier periodo puede marcarse, S-A4 supuesto por confirmar). Solo uno lo es: en la misma
 * transacción quita la marca al anterior ANTES de ponérsela a este, porque el índice único parcial
 * `terms_single_current_unique` rechazaría a los dos a la vez.
 *
 * Es idempotente: si ya era el actual no escribe, no registra ni avisa. Después del commit avisa con
 * CurrentTermChanged, con el periodo anterior si lo había.
 */
final class MakeTermCurrent
{
    public function handle(Term $term): Term
    {
        return DB::transaction(function () use ($term): Term {
            $term->setRawAttributes($term->newQuery()->lockForUpdate()->findOrFail($term->getKey())->getAttributes(), sync: true);

            if ($term->is_current) {
                return $term;
            }

            $previous = Term::query()->current()->whereKeyNot($term->getKey())->lockForUpdate()->first();

            $previous?->forceFill(['is_current' => false])->save();
            $term->forceFill(['is_current' => true])->save();

            activity()
                ->performedOn($term)
                ->event(ActivityEvent::Updated)
                ->withChanges([
                    'attributes' => ['is_current' => true],
                    'old' => ['is_current' => false, 'previous_current' => $previous?->code],
                ])
                ->log($previous === null
                    ? "Marcó el periodo {$term->code} como actual"
                    : "Marcó el periodo {$term->code} como actual, en lugar del {$previous->code}");

            CurrentTermChanged::dispatch($term, $previous);

            return $term;
        });
    }
}
