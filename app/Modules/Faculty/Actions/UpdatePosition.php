<?php

namespace App\Modules\Faculty\Actions;

use App\Modules\Faculty\Events\PositionChanged;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Faculty\Rules\PositionRules;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Cambia una plaza: solo las claves que recibe, revisadas junto con las que no cambian (PositionRules). Quién
 * puede lo decide TeacherPolicy (`managePositions`) antes de llamarla.
 *
 * En una transacción vuelve a leer la plaza con bloqueo (`lockForUpdate`), así el valor anterior de la bitácora es
 * el confirmado aunque `$position` llegue vieja. Si nada cambió, no escribe, no registra ni avisa; si cambió, deja
 * el valor anterior y el nuevo y, después del commit, avisa con PositionChanged. Una plaza ya quitada es un error
 * en `position`.
 */
final class UpdatePosition
{
    public function __construct(private readonly PositionRules $rules) {}

    /**
     * @param  array{kind?: string, base_hours?: int|string|null, valid_from?: ?string, valid_to?: ?string}  $data
     *
     * @throws ValidationException
     */
    public function handle(TeacherPosition $position, array $data): TeacherPosition
    {
        $data = Arr::only($data, PositionRules::FIELDS);

        return DB::transaction(function () use ($position, $data): TeacherPosition {
            $locked = TeacherPosition::query()->lockForUpdate()->find($position->getKey())
                ?? throw ValidationException::withMessages(['position' => 'Esa plaza ya se quitó.']);

            $old = $this->rules->logged($locked);
            $new = $this->rules->normalize([...$old, ...$data]);
            $new['kind'] = $new['kind']->value;

            $changed = array_keys(array_filter($new, fn (mixed $value, string $field): bool => $value !== $old[$field], ARRAY_FILTER_USE_BOTH));

            if ($changed === []) {
                $position->setRawAttributes($locked->getAttributes(), sync: true);

                return $position;
            }

            $locked->forceFill(Arr::only($new, $changed))->save();
            $position->setRawAttributes($locked->getAttributes(), sync: true);

            activity()
                ->performedOn($locked)
                ->event(ActivityEvent::Updated)
                ->withChanges(['attributes' => Arr::only($new, $changed), 'old' => Arr::only($old, $changed)])
                ->withProperties(['teacher_id' => $locked->teacher_id])
                ->log('Cambió una plaza del docente');

            PositionChanged::dispatch($locked);

            return $position;
        });
    }
}
