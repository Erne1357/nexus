<?php

namespace App\Modules\Academic\Rules;

use App\Modules\Academic\Models\Department;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de un departamento que comparten CreateDepartment y UpdateDepartment
 * (docs/dominio/modelo-de-datos.md, `departments`). Cada regla rota es una ValidationException
 * sobre su campo.
 *
 * - `code`: texto UTF-8 válido, en mayúsculas, sin espacios (también los de Unicode, como NBSP), no
 *   vacío y único entre todos los departamentos, también los borrados: la clave de uno borrado sigue
 *   reservada.
 */
final class DepartmentRules
{
    /**
     * @throws ValidationException si no es UTF-8 válido o queda vacío
     */
    public function normalizeCode(string $code): string
    {
        $code = preg_replace('/\s+/u', '', $code);

        if ($code === null) {
            throw ValidationException::withMessages(['code' => 'La clave no es texto válido.']);
        }

        if ($code === '') {
            throw ValidationException::withMessages(['code' => 'La clave no puede quedar vacía ni ser solo espacios.']);
        }

        return Str::upper($code);
    }

    /**
     * Revisa una clave ya normalizada; `$except` es el departamento que la conserva al editarse.
     *
     * @throws ValidationException
     */
    public function ensureCodeIsFree(string $code, ?Department $except = null): void
    {
        $taken = Department::withTrashed()
            ->where('code', $code)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->exists();

        if ($taken) {
            throw $this->codeTaken($code);
        }
    }

    /**
     * El error de clave repetida, también para cuando la restricción única de la base gana la carrera
     * a ensureCodeIsFree().
     */
    public function codeTaken(string $code): ValidationException
    {
        return ValidationException::withMessages([
            'code' => "Ya existe un departamento con la clave {$code}, aunque esté desactivado o borrado.",
        ]);
    }
}
