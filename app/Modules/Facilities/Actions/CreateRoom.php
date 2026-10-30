<?php

namespace App\Modules\Facilities\Actions;

use App\Modules\Core\Models\Campus;
use App\Modules\Facilities\Events\RoomCreated;
use App\Modules\Facilities\Models\Room;
use App\Modules\Facilities\Rules\RoomRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Da de alta un salón del campus. Recibe datos ya validados en forma (el componente) y hace cumplir
 * RoomRules: clave en mayúsculas y única en el campus (también entre los borrados), capacidad mayor que
 * cero, tipo válido, edificio del mismo campus, dueño existente si lo hay y características como lista
 * limpia. Una regla rota es una ValidationException sobre su campo.
 *
 * En una transacción revisa, escribe y deja bitácora; si otra sesión guarda la misma clave entre la
 * revisión y el insert, la restricción única de la base responde con el mismo error. Después del commit
 * avisa con RoomCreated. Quién puede lo decide RoomPolicy antes de llamarla.
 */
final class CreateRoom
{
    public function __construct(private readonly RoomRules $rules) {}

    /**
     * @param  array{building_id: string, code: string, name?: ?string, type: string, capacity: int, department_id?: ?string, features?: array<array-key, mixed>}  $data
     *
     * @throws ValidationException
     */
    public function handle(Campus $campus, array $data): Room
    {
        $code = $this->rules->normalizeCode($data['code']);
        $type = $this->rules->type($data['type']);
        $this->rules->ensureCapacity($data['capacity']);
        $attributes = [
            'campus_id' => $campus->getKey(),
            'building_id' => $data['building_id'],
            'code' => $code,
            'name' => $this->rules->normalizeName($data['name'] ?? null),
            'type' => $type,
            'capacity' => $data['capacity'],
            'department_id' => ($data['department_id'] ?? '') === '' ? null : $data['department_id'],
            'features' => $this->rules->normalizeFeatures($data['features'] ?? []),
            'is_active' => true,
        ];

        return DB::transaction(function () use ($campus, $code, $attributes): Room {
            $this->rules->ensureCodeIsFree($campus, $code);
            $this->rules->ensureBuildingBelongsToCampus($campus, $attributes['building_id']);
            $this->rules->ensureOwnerExists($attributes['department_id']);

            try {
                $room = Room::create($attributes);
            } catch (UniqueConstraintViolationException) {
                throw $this->rules->codeTaken($code);
            }

            activity()
                ->performedOn($room)
                ->event(ActivityEvent::Created)
                ->withChanges(['attributes' => [...$room->only(['campus_id', 'building_id', 'code', 'name', 'capacity', 'department_id', 'features', 'is_active']), 'type' => $room->type->value]])
                ->log("Dio de alta el salón {$room->code}");

            RoomCreated::dispatch($room);

            return $room;
        });
    }
}
