<?php

namespace App\Modules\Facilities\Actions;

use App\Modules\Facilities\Events\RoomUpdated;
use App\Modules\Facilities\Models\Room;
use App\Modules\Facilities\Rules\RoomRules;
use BackedEnum;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Cambia los datos de un salón (clave, nombre, edificio, tipo, capacidad, dueño, características) o su
 * estado (activar o desactivar): solo las claves que recibe; el campus no cambia. Quién puede lo decide
 * RoomPolicy antes de llamarla. Hace cumplir las mismas reglas que CreateRoom; conservar la clave, el
 * edificio o el dueño propios vale aunque hayan quedado desactivados o borrados.
 *
 * En una transacción vuelve a leer el salón con bloqueo (`lockForUpdate`), así el valor anterior de la
 * bitácora es el confirmado aunque `$room` llegue viejo, y lo deja en `$room`. Si nada cambió, no
 * escribe, no registra ni avisa; si algo cambió, guarda, deja en la bitácora el valor anterior y el
 * nuevo de cada campo y, después del commit, avisa con RoomUpdated.
 */
final class UpdateRoom
{
    private const array COLUMNS = ['code', 'name', 'building_id', 'type', 'capacity', 'department_id', 'features', 'is_active'];

    public function __construct(private readonly RoomRules $rules) {}

    /**
     * @param  array{code?: string, name?: ?string, building_id?: string, type?: string, capacity?: int, department_id?: ?string, features?: array<array-key, mixed>, is_active?: bool}  $data
     *
     * @throws ValidationException
     */
    public function handle(Room $room, array $data): Room
    {
        $data = Arr::only($data, self::COLUMNS);

        if (array_key_exists('code', $data)) {
            $data['code'] = $this->rules->normalizeCode($data['code']);
        }

        if (array_key_exists('name', $data)) {
            $data['name'] = $this->rules->normalizeName($data['name']);
        }

        if (array_key_exists('type', $data)) {
            $data['type'] = $this->rules->type($data['type'])->value;
        }

        if (array_key_exists('capacity', $data)) {
            $this->rules->ensureCapacity($data['capacity']);
        }

        if (array_key_exists('features', $data)) {
            $data['features'] = $this->rules->normalizeFeatures($data['features']);
        }

        if (array_key_exists('department_id', $data) && $data['department_id'] === '') {
            $data['department_id'] = null;
        }

        return DB::transaction(function () use ($room, $data): Room {
            $room->setRawAttributes($room->newQuery()->lockForUpdate()->findOrFail($room->getKey())->getAttributes(), sync: true);
            $old = $room->only(self::COLUMNS);

            if (array_key_exists('code', $data)) {
                $this->rules->ensureCodeIsFree($room->campus_id, $data['code'], $room);
            }

            if (array_key_exists('building_id', $data) && $data['building_id'] !== $room->building_id) {
                $this->rules->ensureBuildingBelongsToCampus($room->campus_id, $data['building_id']);
            }

            if (array_key_exists('department_id', $data) && $data['department_id'] !== $room->department_id) {
                $this->rules->ensureOwnerExists($data['department_id']);
            }

            $room->fill($data);
            $changed = array_keys($room->getDirty());

            if ($changed === []) {
                return $room;
            }

            try {
                $room->save();
            } catch (UniqueConstraintViolationException) {
                throw $this->rules->codeTaken($room->code);
            }

            activity()
                ->performedOn($room)
                ->event(ActivityEvent::Updated)
                ->withChanges([
                    'attributes' => $this->loggable($room->only($changed)),
                    'old' => $this->loggable(Arr::only($old, $changed)),
                ])
                ->log("Actualizó el salón {$room->code}");

            RoomUpdated::dispatch($room, $changed);

            return $room;
        });
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function loggable(array $values): array
    {
        return array_map(fn (mixed $value): mixed => $value instanceof BackedEnum ? $value->value : $value, $values);
    }
}
