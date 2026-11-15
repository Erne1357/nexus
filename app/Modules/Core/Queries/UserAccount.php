<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Enums\UserAccess;
use App\Modules\Core\Models\User;

/**
 * El bloque «Cuenta» de la ficha de la persona y por qué no se puede cada acción de su menú (1.22-C1), armado por
 * UserAccountQuery. Cada motivo es nulo si la acción sí se puede; la baja solo se ofrece a una cuenta activa y la
 * reactivación a una dada de baja, así que el motivo de la que no aplica también es nulo.
 */
final readonly class UserAccount
{
    public function __construct(
        public User $person,
        public UserAccess $access,
        public bool $isStudent,
        public bool $emailVerified,
        public ?string $updateBlocker,
        public ?string $resetBlocker,
        public ?string $deactivateBlocker,
        public ?string $reactivateBlocker,
    ) {}
}
