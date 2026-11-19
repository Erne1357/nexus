<?php

namespace App\Modules\Faculty\Database\Seeders;

/**
 * El correo institucional ficticio de los docentes de la demo (`teachers.institutional_email`, AC-06), para que en la
 * demo se puedan ensayar el correo de los avisos (RA-06) y la invitación de acceso (AC-07): el RFC sintético del docente
 * en minúsculas, `@` y el dominio institucional configurado (`nexus.institutional_email_domain`), así que cumple AC-06
 * y es la dirección que eligen los avisos (HeadMailAddressQuery). Sale del RFC, nunca del uuid: la misma semilla da los
 * mismos correos.
 *
 * La demo nunca escribe un dominio real: solo siembra el correo cuando el dominio configurado es de un dominio de primer
 * nivel reservado (RFC 2606 y RFC 6761: `.example`, `.test`, `.invalid` o `.localhost`), que no existe ni recibe correo.
 * El valor de la demo es DOMAIN, `docentes.itcj.example`: se siembra y se sirve con
 * `NEXUS_INSTITUTIONAL_EMAIL_DOMAIN=docentes.itcj.example` (docs/arquitectura/testing.md, «Datos de prueba»). Con el
 * dominio del Tec (`cdjuarez.tecnm.mx`, el de omisión) o cualquier otro no siembra ninguno y los docentes quedan sin
 * correo institucional.
 */
final class DemoTeacherEmail
{
    /** El dominio institucional de la demo. */
    public const string DOMAIN = 'docentes.itcj.example';

    /**
     * El dominio institucional configurado, en minúsculas, si es reservado; null si no lo es.
     */
    public static function domain(): ?string
    {
        $domain = mb_strtolower(trim((string) config('nexus.institutional_email_domain')));

        return preg_match('/^([a-z0-9-]+\.)+(example|test|invalid|localhost)$/', $domain) === 1 ? $domain : null;
    }

    /**
     * El correo institucional de la demo del docente con ese RFC, o null si el dominio configurado no es reservado.
     */
    public static function for(string $rfc): ?string
    {
        $domain = self::domain();

        return $domain === null ? null : mb_strtolower($rfc).'@'.$domain;
    }
}
