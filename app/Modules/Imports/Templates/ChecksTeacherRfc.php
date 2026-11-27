<?php

namespace App\Modules\Imports\Templates;

use App\Modules\Faculty\Rules\TeacherRfc;

/**
 * El campo `rfc` de las plantillas de docentes: la regla de forma de Faculty y la advertencia de un RFC sin
 * homoclave (acepta 9 o 10 caracteres, pero avisa).
 */
trait ChecksTeacherRfc
{
    /**
     * @return list<mixed>
     */
    protected function rfcRules(): array
    {
        return ['required', 'string', new TeacherRfc];
    }

    /**
     * El RFC en mayúsculas y sin espacios; lo que no es texto (o no es UTF-8 válido) queda como vino.
     */
    protected static function normalizedRfc(mixed $rfc): mixed
    {
        return is_string($rfc) ? (TeacherRfc::normalize($rfc) ?? $rfc) : $rfc;
    }

    protected function warnWithoutHomoclave(RowIssues $issues, mixed $rfc): RowIssues
    {
        return is_string($rfc) && TeacherRfc::lacksHomoclave($rfc)
            ? $issues->withWarning('rfc', TeacherRfc::NO_HOMOCLAVE_WARNING)
            : $issues;
    }
}
