<?php

namespace App\Modules\Facilities\Actions;

use App\Modules\Facilities\Models\Room;

/**
 * Deja sin dueño a un salón que el departamento dado reclamó desde su validación de catálogos (CV16, confirmado el
 * 20 de noviembre de 2026). Como ClaimRoomForDepartment, no pasa por RoomPolicy::update: autoriza la revisión del
 * departamento. Va dentro de la transacción de quien la llama (ReleaseRoomClaim). Relee el salón con bloqueo y solo lo suelta si todavía es de ese departamento; si no, no escribe y
 * devuelve false. Lo cambia con UpdateRoom (bitácora y RoomUpdated).
 */
final class ReleaseRoomFromDepartment
{
    public function __construct(private readonly UpdateRoom $updateRoom) {}

    /**
     * Devuelve si el salón quedó sin dueño.
     */
    public function handle(string $roomId, string $departmentId): bool
    {
        $room = Room::query()->lockForUpdate()->find($roomId);

        if ($room === null || $room->department_id !== $departmentId) {
            return false;
        }

        $this->updateRoom->handle($room, ['department_id' => null]);

        return true;
    }
}
