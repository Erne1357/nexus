<?php

namespace App\Modules\Core\Notifications;

use App\Modules\Core\Models\User;
use Closure;

/**
 * El correo institucional de una persona (CE-01, confirmado el 26 de noviembre de 2026), al que le llega todo aviso de
 * persona que no elige su dirección (RoutesMailAddress): el de su usuario si es institucional (InstitutionalMail), tal
 * como está guardado; si no,
 * el que dé cada fuente que otro módulo registró con extend() al arrancar, en el orden de bootstrap/providers.php
 * (Faculty, el institucional de su ficha de docente; Students, el que sale de su número de control, AC-13), porque Core
 * no los conoce; si ninguna da uno institucional, null y no le sale correo (la campana sí). Nunca el personal. Las
 * fuentes se registran solo en boot() y son closures sin estado, así que el registro es seguro bajo Octane.
 */
final class PersonMailAddresses
{
    /** @var list<Closure(User): ?string> */
    private array $sources = [];

    /**
     * @param  Closure(User): ?string  $source  el correo institucional de la persona en esa fuente, o null si no tiene
     */
    public function extend(Closure $source): void
    {
        $this->sources[] = $source;
    }

    public function for(User $user): ?string
    {
        if (InstitutionalMail::isInstitutional($user->email)) {
            return $user->email;
        }

        foreach ($this->sources as $source) {
            $email = $source($user);

            if (InstitutionalMail::isInstitutional($email)) {
                return mb_strtolower(trim((string) $email));
            }
        }

        return null;
    }
}
