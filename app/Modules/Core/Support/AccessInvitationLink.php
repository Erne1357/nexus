<?php

namespace App\Modules\Core\Support;

use App\Modules\Core\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;

/**
 * El enlace de la invitación de acceso (AC-07): una URL firmada que vence a los `DAYS` días (supuesto por confirmar)
 * con el id del usuario y una huella de su correo y de la hora de la invitación. Cambiar el correo o mandar otra
 * invitación cambia la huella, así que solo sirve el último enlace; la firma impide alterarlo. No lleva el correo a la
 * vista: solo su huella.
 */
final class AccessInvitationLink
{
    /** Días que vale un enlace. */
    public const int DAYS = 7;

    /**
     * El enlace de la invitación vigente de `$user`, que ya tiene `email` y `access_invited_at`.
     */
    public function url(User $user): string
    {
        return URL::temporarySignedRoute('access.accept', $this->expiresAt($user), [
            'user' => $user->getKey(),
            'hash' => $this->hash($user),
        ]);
    }

    public function expiresAt(User $user): CarbonImmutable
    {
        return $user->access_invited_at->addDays(self::DAYS);
    }

    /**
     * Si el enlace todavía abre: el usuario sigue activo y sin contraseña, y `$hash` es la huella de su invitación
     * vigente, que no ha vencido. No revisa la firma: eso lo hace la ruta.
     */
    public function opens(User $user, string $hash): bool
    {
        return $user->is_active
            && $user->password === null
            && $user->email !== null
            && $user->access_invited_at !== null
            && $this->expiresAt($user)->isFuture()
            && hash_equals($this->hash($user), $hash);
    }

    private function hash(User $user): string
    {
        return hash('sha256', mb_strtolower((string) $user->email).'|'.$user->access_invited_at?->getTimestamp());
    }
}
