<?php

namespace App\Modules\Students\Rules;

/**
 * AC-13: el correo institucional del alumno sale de su número de control (confirmado el 11 de noviembre de 2026):
 * `l` + número de control + `@` + el dominio institucional (`nexus.institutional_email_domain`). Los de posgrado, cuyo
 * número de control ya empieza con una letra (M, D…), usan esa letra en lugar de la `l`: el número de control tal
 * cual. Todo en minúsculas. Un número de control con algo que no sea letra o dígito no da correo.
 */
final class InstitutionalEmail
{
    public static function of(string $controlNumber): ?string
    {
        if (preg_match('/^[A-Za-z0-9]+$/', $controlNumber) !== 1) {
            return null;
        }

        $local = ctype_alpha($controlNumber[0]) ? $controlNumber : 'l'.$controlNumber;

        return mb_strtolower($local).'@'.config('nexus.institutional_email_domain');
    }
}
