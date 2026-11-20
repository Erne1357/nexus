<?php

namespace App\Modules\Facilities\Actions;

use App\Modules\Facilities\Models\Room;

/**
 * Le pone dueño a un salón que no lo tiene: lo reclama un departamento desde su validación de catálogos (CV16,
 * confirmado el 20 de noviembre de 2026). No pasa por RoomPolicy::update (que cubre al admin y a la Subdirección del
 * campus, no al jefe, que reclama salones de cualquier campus): quien llama ya autorizó con CatalogReviewPolicy
 * sobre la revisión del departamento.
 *
 * Va dentro de la transacción de quien la llama (ClaimRooms): relee el salón con bloqueo (`lockForUpdate`): el primero que reclama se lo queda; si el salón ya
 * tiene dueño, está inactivo o se borró, no escribe y devuelve false. Si lo reclama, lo cambia con UpdateRoom, que deja su
 * bitácora (`department_id` antes y después) y avisa con RoomUpdated.
 */
final class ClaimRoomForDepartment
{
    public function __construct(private readonly UpdateRoom $updateRoom) {}

    /**
     * Devuelve si el salón quedó del departamento.
     */
    public function handle(string $roomId, string $departmentId): bool
    {
        $room = Room::query()->lockForUpdate()->find($roomId);

        if ($room === null || ! $room->is_active || $room->department_id !== null) {
            return false;
        }

        $this->updateRoom->handle($room, ['department_id' => $departmentId]);

        return true;
    }
}
