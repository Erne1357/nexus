<?php

namespace App\Modules\Faculty\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * El RFC, clave natural y usuario del docente (reglas-de-negocio.md, "Reglas de datos", confirmado el 5 de
 * noviembre de 2026). Se normaliza a mayúsculas y sin espacios y debe tener la forma de un RFC (supuesto por
 * confirmar): 3 o 4 letras (`Ñ` y `&` valen), 6 dígitos de fecha y, de preferencia, 3 de homoclave. Sin homoclave (9
 * o 10 caracteres) se acepta, pero lo avisan las importaciones (supuesto por confirmar). Es el único lugar donde vive
 * la forma.
 */
final class TeacherRfc implements ValidationRule
{
    public const string PATTERN = '/^[A-ZÑ&]{3,4}\d{6}([A-Z0-9]{3})?$/u';

    public const string SHAPE_MESSAGE = 'El RFC no tiene la forma de un RFC.';

    public const string NO_HOMOCLAVE_WARNING = 'el RFC no trae homoclave';

    /**
     * Mayúsculas y sin espacios (también los de Unicode). Un texto que no es UTF-8 válido da null.
     */
    public static function normalize(string $rfc): ?string
    {
        $stripped = preg_replace('/\s+/u', '', $rfc);

        return $stripped === null ? null : mb_strtoupper($stripped, 'UTF-8');
    }

    /**
     * Si un RFC ya normalizado tiene la forma de uno, con o sin homoclave.
     */
    public static function hasShape(string $normalized): bool
    {
        return preg_match(self::PATTERN, $normalized) === 1;
    }

    /**
     * Si un RFC ya normalizado y con forma válida no trae los 3 caracteres de la homoclave.
     */
    public static function lacksHomoclave(string $normalized): bool
    {
        return self::hasShape($normalized) && mb_strlen($normalized) < 12;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $normalized = is_string($value) ? self::normalize($value) : null;

        if ($normalized === null || ! self::hasShape($normalized)) {
            $fail(self::SHAPE_MESSAGE);
        }
    }
}
