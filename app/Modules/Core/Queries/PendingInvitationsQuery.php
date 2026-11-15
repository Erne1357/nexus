<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Models\User;
use App\Modules\Core\Rules\RoleAssignmentRules;
use App\Modules\Core\Support\AccessInvitationLink;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Las invitaciones de acceso pendientes (AC-07), para «Correos y colas» (CF-S8): los usuarios activos, todavía sin
 * contraseña, con una invitación mandada; la más próxima a vencer primero. El vencimiento lo calcula
 * AccessInvitationLink, el mismo que el del enlace. Las vencidas se incluyen, marcadas, porque son las que hay que
 * reenviar. `staff` dice si el usuario tiene un puesto distinto de docente vigente o por empezar: «Reenviar» manda la
 * invitación del admin (CF-07), cuyo enlace le da acceso con ese puesto, y la pantalla lo avisa antes de confirmar.
 */
final class PendingInvitationsQuery
{
    public function __construct(
        private readonly AccessInvitationLink $link,
        private readonly RoleAssignmentRules $rules,
    ) {}

    /**
     * @return Collection<int, array{user: User, expires_at: CarbonImmutable, expired: bool, staff: bool}>
     */
    public function all(): Collection
    {
        $users = User::query()
            ->where('is_active', true)
            ->whereNull('password')
            ->whereNotNull('access_invited_at')
            ->orderBy('access_invited_at')
            ->get();
        $staff = $this->rules->staffUserIds()->whereIn('user_id', $users->modelKeys())->pluck('user_id')->flip();

        return $users
            ->map(fn (User $user): array => [
                'user' => $user,
                'expires_at' => $expiresAt = $this->link->expiresAt($user),
                'expired' => $expiresAt->isPast(),
                'staff' => $staff->has($user->getKey()),
            ])
            ->values();
    }
}
