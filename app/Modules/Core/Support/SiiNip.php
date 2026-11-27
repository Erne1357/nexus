<?php

namespace App\Modules\Core\Support;

use App\Modules\Core\Enums\PasswordOrigin;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * El NIP del SII como contraseña del alumno (AC-10 y AC-11). Funciones puras, sin estado (Octane).
 *
 * La exportación del SII quitó los ceros a la izquierda: un NIP de 1 a 3 dígitos se completa con ceros a 4 al
 * guardarlo y al entrar, así que sirven las dos formas, 123 y 0123 (confirmado el 11 de noviembre de 2026); solo
 * mientras el alumno siga con el NIP del SII (`password_origin = sii_nip`).
 *
 * Se guarda con bcrypt a costo `ROUNDS` (o el configurado, si es menor): el NIP solo tiene 10,000 combinaciones, así
 * que lo que lo protege es el acceso a la base, no el costo, y un costo menor deja migrar ~68,000 alumnos en minutos
 * y no en horas (AC-14). En el primer inicio de sesión correcto se rehace con el costo normal (UpgradePasswordHash).
 * Los parámetros que llevan un NIP son `SensitiveParameter`: nunca salen en una traza.
 */
final class SiiNip
{
    /** Costo de bcrypt del NIP del SII; el normal de Nexus es `hashing.bcrypt.rounds` (12). */
    public const int ROUNDS = 10;

    /**
     * El NIP como se guarda y se compara: uno de 1 a 3 dígitos se completa con ceros a 4; cualquier otro, tal cual.
     */
    public static function normalize(#[\SensitiveParameter] string $nip): string
    {
        return preg_match('/^\d{1,3}$/', $nip) === 1 ? str_pad($nip, 4, '0', STR_PAD_LEFT) : $nip;
    }

    /**
     * El hash bcrypt del NIP normalizado, con `rounds()`.
     */
    public static function hash(#[\SensitiveParameter] string $nip): string
    {
        return Hash::make(self::normalize($nip), ['rounds' => self::rounds()]);
    }

    /**
     * `ROUNDS`, o el costo configurado si es menor (las pruebas usan 4): un hash nunca cuesta más que el normal.
     */
    public static function rounds(): int
    {
        return min(self::ROUNDS, (int) config('hashing.bcrypt.rounds', 12));
    }

    /**
     * Lo que se compara con la contraseña de `$user`: la entrada normalizada si su contraseña es el NIP del SII, y si
     * no, tal cual.
     */
    public static function forUser(User $user, #[\SensitiveParameter] string $input): string
    {
        return $user->password_origin === PasswordOrigin::SiiNip ? self::normalize($input) : $input;
    }
}
