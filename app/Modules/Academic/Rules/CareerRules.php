<?php

namespace App\Modules\Academic\Rules;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Core\Queries\KeyLookup;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de una carrera que comparten CreateCareer y UpdateCareer
 * (docs/dominio/modelo-de-datos.md, `careers`). Cada regla rota es una ValidationException sobre su
 * campo.
 *
 * - `code`: texto UTF-8 válido, en mayúsculas, sin espacios (también los de Unicode, como NBSP), no
 *   vacío y único entre todas las carreras, también las borradas: la clave de una borrada sigue
 *   reservada.
 * - `department_id`: un departamento que existe y no está borrado (uno inactivo vale).
 */
final class CareerRules
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
     * Revisa una clave ya normalizada; `$except` es la carrera que la conserva al editarse.
     *
     * @throws ValidationException
     */
    public function ensureCodeIsFree(string $code, ?Career $except = null): void
    {
        $taken = Career::withTrashed()
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
            'code' => "Ya existe una carrera con la clave {$code}, aunque esté desactivada o borrada.",
        ]);
    }

    /**
     * El departamento de `$id`, que debe existir y no estar borrado.
     *
     * @throws ValidationException sobre `department_id`
     */
    public function department(string $id): Department
    {
        return KeyLookup::find(Department::query(), $id)
            ?? throw ValidationException::withMessages(['department_id' => 'El departamento no existe o fue borrado.']);
    }
}
