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
 * quitada, es un error; uno que tuvo una fila quitada la restaura y quita la editada.
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

            $clash = TeacherExternalLoad::withTrashed()
                ->where('teacher_id', $locked->teacher_id)
                ->where('term_id', $locked->term_id)
                ->whereKeyNot($locked->getKey())
                ->whereRaw('lower(source) = lower(?)', [$new['source']])
                ->lockForUpdate()
                ->first();

            if ($clash !== null && ! $clash->trashed()) {
                throw ValidationException::withMessages(['source' => 'Ese origen ya tiene horas en el periodo.']);
            }

            if ($clash !== null) {
                return $this->restoreInstead($locked, $clash, $new, $old);
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

    /**
     * El origen nuevo es el de una fila quitada del periodo: se restaura con los valores nuevos y la editada se quita,
     * como AddExternalLoad restaura un origen quitado; así el índice único no se rompe.
     *
     * @param  array{source: string, hours: int, notes: ?string}  $new
     * @param  array{source: string, hours: int, notes: ?string}  $old
     */
    private function restoreInstead(TeacherExternalLoad $edited, TeacherExternalLoad $removed, array $new, array $old): TeacherExternalLoad
    {
        $before = $this->rules->logged($removed);
        $edited->delete();
        $removed->forceFill($new);
        $removed->deleted_at = null;
        $removed->save();

        activity()
            ->performedOn($edited)
            ->event(ActivityEvent::Deleted)
            ->withChanges(['old' => $old])
            ->withProperties(['teacher_id' => $edited->teacher_id, 'term_id' => $edited->term_id])
            ->log('Quitó unas horas fuera del ITCJ del docente al cambiarlas a un origen quitado');

        activity()
            ->performedOn($removed)
            ->event(ActivityEvent::Created)
            ->withChanges(['attributes' => $new, 'old' => $before])
            ->withProperties(['teacher_id' => $removed->teacher_id, 'term_id' => $removed->term_id])
            ->log('Restauró unas horas fuera del ITCJ del docente');

        ExternalLoadChanged::dispatch($edited);
        ExternalLoadChanged::dispatch($removed);

        return $removed;
    }
}
