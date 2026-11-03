<?php

namespace App\Modules\Core\Rules;

use App\Modules\Core\Models\User;

/**
 * AZ-01 (autoaprobación marcada; confirmado el 2 de noviembre de 2026): cuando quien aprueba es la misma persona que
 * creó o solicitó lo aprobado (con otro puesto), la acción procede, la pantalla lo advierte antes de confirmar y la
 * bitácora lleva `self_approval: true`. Lo detecta la Action con el dato del dominio (`submitted_by`, `requested_by`),
 * no la Policy. Es la pieza común de la bandeja del departamento, las solicitudes de cambio (fase 3) y el visto bueno
 * de la carga (fase 4).
 */
final class SelfApproval
{
    /**
     * Verdadero si `$requesterId` existe y es la persona que aprueba.
     */
    public static function applies(User $approver, ?string $requesterId): bool
    {
        return $requesterId !== null && $requesterId === (string) $approver->getKey();
    }

    /**
     * La marca para las propiedades de la bitácora: `['self_approval' => true]` o nada.
     *
     * @return array{self_approval?: true}
     */
    public static function mark(User $approver, ?string $requesterId): array
    {
        return self::applies($approver, $requesterId) ? ['self_approval' => true] : [];
    }
}
