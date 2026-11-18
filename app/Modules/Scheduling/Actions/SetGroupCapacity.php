<?php

namespace App\Modules\Scheduling\Actions;

use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use App\Modules\Scheduling\Rules\QuotaRules;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cambia la capacidad de un grupo (tarea 8.3a, para ampliar un cupo en el re-análisis; el permiso es GroupPolicy::manage,
 * «corregir su capacidad», y lo revisa quien llama). Va de 1 a 999 lugares, como un cupo.
 *
 * - **HC-07:** no baja de lo repartido entre carreras más lo que apartan sus propuestas de grupo compartido
 *   (QuotaRules::heldSeats); el trigger de la base revisa otra vez los cupos y su rechazo se traduce.
 * - **HC-05:** no sube más que el salón más chico de sus sesiones vivas con salón (supuesto por confirmar: al asignar un
 *   salón chico, HC-05 se guarda marcado; aquí se pide la capacidad, así que no se deja empeorar). Las sesiones sin salón
 *   no la limitan.
 *
 * En una transacción toma la carga (FOR SHARE), relee el grupo con bloqueo y exige la carga editable (LoadEditability). Sin
 * cambio no escribe ni avisa. Deja la bitácora `capacity_updated` (antes y después) y avisa con GroupScheduleChanged sin
 * cambio de horas después del commit, que recalcula los choques (HC-05, HC-07) sin tocar la clave ni el ranking.
 */
final class SetGroupCapacity
{
    public const string EVENT = 'capacity_updated';

    public function __construct(private readonly QuotaRules $rules) {}

    /**
     * @throws ValidationException
     */
    public function handle(Group $group, int $capacity, User $actor): Group
    {
        if ($capacity < 1 || $capacity > QuotaRules::MAX_SEATS) {
            throw ValidationException::withMessages(['capacity' => 'La capacidad va de 1 a '.QuotaRules::MAX_SEATS.' lugares.']);
        }

        try {
            return $this->rules->guard(fn (): Group => DB::transaction(function () use ($group, $capacity, $actor): Group {
                $this->rules->lockLoad($group);
                $group->setRawAttributes($group->newQuery()->lockForUpdate()->findOrFail($group->getKey())->getAttributes(), sync: true);

                try {
                    $this->rules->ensureEditable($group);
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(['capacity' => $exception->validator->errors()->first()]);
                }

                $old = $group->capacity;

                if ($old === $capacity) {
                    return $group;
                }

                $this->ensureFitsQuotas($group, $capacity);

                if ($capacity > $old) {
                    $this->ensureFitsRooms($group, $capacity);
                }

                $group->update(['capacity' => $capacity]);

                activity()
                    ->performedOn($group)
                    ->causedBy($actor)
                    ->event(self::EVENT)
                    ->withChanges(['old' => ['capacity' => $old], 'attributes' => ['capacity' => $capacity]])
                    ->log("Cambió la capacidad del grupo {$group->code} de {$old} a {$capacity}");

                GroupScheduleChanged::dispatch($group, timesChanged: false);

                return $group;
            }));
        } catch (QueryException $exception) {
            throw $this->rules->fromDatabase($exception, 'capacity') ?? $exception;
        }
    }

    /**
     * HC-07: lo repartido y lo apartado caben en la capacidad nueva.
     *
     * @throws ValidationException
     */
    private function ensureFitsQuotas(Group $group, int $capacity): void
    {
        $used = (int) GroupQuota::query()->where('group_id', $group->getKey())->sum('max_seats');
        $held = $this->rules->heldSeats($group);

        if ($used + $held > $capacity) {
            throw ValidationException::withMessages(['capacity' => "HC-07: el grupo {$group->code} tiene {$used} lugares repartidos"
                .($held > 0 ? " y {$held} apartados" : '').'; la capacidad no baja de '.($used + $held).'.']);
        }
    }

    /**
     * HC-05: la capacidad nueva cabe en el salón más chico de sus sesiones vivas.
     *
     * @throws ValidationException
     */
    private function ensureFitsRooms(Group $group, int $capacity): void
    {
        $room = Room::withTrashed()
            ->whereIn('id', $group->sessions()->where('is_active', true)->whereNotNull('room_id')->select('room_id'))
            ->orderBy('capacity')
            ->orderBy('code')
            ->first(['code', 'capacity']);

        if ($room !== null && $room->capacity < $capacity) {
            throw ValidationException::withMessages(['capacity' => "HC-05: el salón {$room->code} tiene {$room->capacity} lugares; la capacidad del grupo {$group->code} no sube de ahí."]);
        }
    }
}
