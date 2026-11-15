<?php

namespace App\Modules\Facilities\Actions;

use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Facilities\Models\Room;
use App\Modules\Facilities\Rules\RoomRules;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Asigna el departamento dueño a varios salones a la vez, o los deja «Sin dueño» (`null`, es decir, compartidos).
 * Es todo o nada: en una sola transacción, y si el usuario no puede editar alguno de los salones (RoomPolicy::update,
 * por su campus) no se cambia ninguno. Cada salón pasa por UpdateRoom, así que deja su propia entrada de bitácora
 * con el dueño anterior y el nuevo, y avisa con RoomUpdated; un salón que ya tenía ese dueño no deja nada.
 * Devuelve cuántos salones cambiaron de dueño.
 */
final class AssignRoomOwner
{
    public function __construct(private readonly UpdateRoom $updateRoom, private readonly RoomRules $rules) {}

    /**
     * @param  list<string>  $roomIds
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, array $roomIds, ?string $departmentId): int
    {
        $this->rules->ensureOwnerExists($departmentId);

        $rooms = collect($roomIds)->unique()->map(fn (string $id): Room => KeyLookup::findOrFail(Room::query(), $id));

        if ($rooms->isEmpty()) {
            throw ValidationException::withMessages(['selected' => 'Elige al menos un salón.']);
        }

        $rooms->each(fn (Room $room) => Gate::forUser($actor)->authorize('update', $room));

        return DB::transaction(fn (): int => $rooms
            ->filter(fn (Room $room): bool => $room->department_id !== $departmentId)
            ->each(fn (Room $room) => $this->updateRoom->handle($room, ['department_id' => $departmentId]))
            ->count());
    }
}
