<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Events\TermUpdated;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Rules\TermRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Cambia código, nombre y fechas de un periodo: solo las claves que recibe. Nunca toca `is_current`
 * (es de MakeTermCurrent). Quién puede lo decide TermPolicy antes de llamarla; las reglas son las de
 * CreateTerm (TermRules), y conservar el código propio vale.
 *
 * En una transacción vuelve a leer el periodo con bloqueo, así el valor anterior de la bitácora es el
 * confirmado aunque `$term` llegue viejo. Si nada cambió, no escribe, no registra ni avisa; si algo
 * cambió, guarda, deja el valor anterior y el nuevo y, después del commit, avisa con TermUpdated.
 */
final class UpdateTerm
{
    public function __construct(private readonly TermRules $rules) {}

    /**
     * @param  array{code?: string, name?: string, starts_on?: mixed, ends_on?: mixed}  $data
     *
     * @throws ValidationException
     */
    public function handle(Term $term, array $data): Term
    {
        $data = Arr::only($data, ['code', 'name', 'starts_on', 'ends_on']);

        if (array_key_exists('code', $data)) {
            $data['code'] = $this->rules->normalizeCode($data['code']);
        }

        return DB::transaction(function () use ($term, $data): Term {
            $term->setRawAttributes($term->newQuery()->lockForUpdate()->findOrFail($term->getKey())->getAttributes(), sync: true);

            // Las fechas se validan juntas con las guardadas cuando solo llega una.
            if (array_key_exists('starts_on', $data) || array_key_exists('ends_on', $data)) {
                [$starts, $ends] = $this->rules->dates(
                    $data['starts_on'] ?? $term->starts_on,
                    $data['ends_on'] ?? $term->ends_on,
                );
                $data = array_replace($data, array_intersect_key(['starts_on' => $starts, 'ends_on' => $ends], $data));
            }

            $old = [...$term->only(['code', 'name']), 'starts_on' => $term->starts_on->toDateString(), 'ends_on' => $term->ends_on->toDateString()];

            if (array_key_exists('code', $data)) {
                $this->rules->ensureCodeIsFree($data['code'], $term);
            }

            $term->fill($data);
            $changed = array_keys($term->getDirty());

            if ($changed === []) {
                return $term;
            }

            try {
                $term->save();
            } catch (UniqueConstraintViolationException) {
                throw $this->rules->codeTaken($term->code);
            }

            activity()
                ->performedOn($term)
                ->event(ActivityEvent::Updated)
                ->withChanges([
                    'attributes' => Arr::only([...$term->only(['code', 'name']), 'starts_on' => $term->starts_on->toDateString(), 'ends_on' => $term->ends_on->toDateString()], $changed),
                    'old' => Arr::only($old, $changed),
                ])
                ->log("Actualizó el periodo {$term->code}");

            TermUpdated::dispatch($term, $changed);

            return $term;
        });
    }
}
