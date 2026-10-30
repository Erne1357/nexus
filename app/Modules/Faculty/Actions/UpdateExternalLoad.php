<?php

namespace App\Modules\Faculty\Actions;

use App\Modules\Faculty\Events\ExternalLoadChanged;
use App\Modules\Faculty\Models\TeacherExternalLoad;
use App\Modules\Faculty\Rules\ExternalLoadRules;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Cambia el origen, las horas o las notas de unas horas fuera del ITCJ (S16, supuesto por confirmar). Quién puede lo
 * decide TeacherPolicy (`manageExternalLoads`) antes de llamarla.
 *
 * En una transacción vuelve a leer la fila con bloqueo, así el valor anterior de la bitácora es el confirmado. Si
 * nada cambió, no escribe, no registra ni avisa. Un origen que otra fila del mismo periodo ya tiene, o una fila ya
 * quitada, es un error.
 */
final class UpdateExternalLoad
{
    public function __construct(private readonly ExternalLoadRules $rules) {}

    /**
     * @param  array{source?: string, hours?: int|string|null, notes?: ?string}  $data
     *
     * @throws ValidationException
     */
    public function handle(TeacherExternalLoad $load, array $data): TeacherExternalLoad
    {
        return DB::transaction(function () use ($load, $data): TeacherExternalLoad {
            $locked = TeacherExternalLoad::query()->lockForUpdate()->find($load->getKey())
                ?? throw ValidationException::withMessages(['load' => 'Esas horas ya se quitaron.']);

            $old = $this->rules->logged($locked);
            $new = $this->rules->normalize([...$old, ...Arr::only($data, ExternalLoadRules::FIELDS)]);
            $changed = array_keys(array_filter($new, fn (mixed $value, string $field): bool => $value !== $old[$field], ARRAY_FILTER_USE_BOTH));

            if ($changed === []) {
                $load->setRawAttributes($locked->getAttributes(), sync: true);

                return $load;
            }

            $taken = TeacherExternalLoad::withTrashed()
                ->where('teacher_id', $locked->teacher_id)
                ->where('term_id', $locked->term_id)
                ->whereKeyNot($locked->getKey())
                ->whereRaw('lower(source) = ?', [mb_strtolower($new['source'])])
                ->exists();

            if ($taken) {
                throw ValidationException::withMessages(['source' => 'Ese origen ya tiene horas en el periodo.']);
            }

            $locked->forceFill(Arr::only($new, $changed))->save();
            $load->setRawAttributes($locked->getAttributes(), sync: true);

            activity()
                ->performedOn($locked)
                ->event(ActivityEvent::Updated)
                ->withChanges(['attributes' => Arr::only($new, $changed), 'old' => Arr::only($old, $changed)])
                ->withProperties(['teacher_id' => $locked->teacher_id, 'term_id' => $locked->term_id])
                ->log('Cambió unas horas fuera del ITCJ del docente');

            ExternalLoadChanged::dispatch($locked);

            return $load;
        });
    }
}
