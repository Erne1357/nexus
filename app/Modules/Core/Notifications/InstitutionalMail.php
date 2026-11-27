<?php

namespace App\Modules\Core\Notifications;

/**
 * Qué es un correo institucional (CE-01, confirmado el 26 de noviembre de 2026): una dirección válida cuyo dominio, sin
 * importar mayúsculas, es uno de la lista `nexus.institutional_email_domains` (por omisión solo `cdjuarez.tecnm.mx`;
 * `itcj.edu.mx` no cuenta) o el dominio con que Nexus arma y valida los correos (`nexus.institutional_email_domain`,
 * AC-06 y AC-13), que siempre cuenta para que un correo que Nexus mismo armó nunca se bloquee (supuesto por confirmar).
 * Un subdominio o un dominio que solo contiene al institucional no cuenta. Es la única noción de «institucional» del
 * correo: la preguntan la guardia (GuardMailRecipient), la dirección de la persona (PersonMailAddresses) y
 * PasswordResetRules. Lee la configuración en cada llamada, sin guardar nada (Octane).
 */
final class InstitutionalMail
{
    /**
     * Los dominios institucionales, en minúsculas, sin vacíos ni repetidos, en el orden de la lista y al final el de
     * `institutional_email_domain`.
     *
     * @return list<string>
     */
    public static function domains(): array
    {
        $domains = array_map(
            fn (mixed $domain): string => mb_strtolower(trim((string) $domain)),
            [...(array) config('nexus.institutional_email_domains', []), config('nexus.institutional_email_domain')],
        );

        return array_values(array_unique(array_filter($domains, fn (string $domain): bool => $domain !== '')));
    }

    public static function isInstitutional(?string $email): bool
    {
        $email = mb_strtolower(trim((string) $email));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        return in_array(substr($email, strrpos($email, '@') + 1), self::domains(), true);
    }
}
