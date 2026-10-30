<?php

namespace App\Modules\Facilities\Rules;

use App\Modules\Core\Models\Campus;
use App\Modules\Facilities\Models\Building;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de un edificio que comparten CreateBuilding y UpdateBuilding (docs/dominio/modelo-de-datos.md,
 * `buildings`). Cada regla rota es una ValidationException sobre su campo.
 *
 * - `code`: texto UTF-8 válido, en mayúsculas, sin espacios (tampoco los de Unicode), no vacío y único
 *   dentro del campus, también entre los borrados: la clave de uno borrado sigue reservada.
 * - `name`: no vacío.
 */
final class BuildingRules
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
     * @throws ValidationException
     */
    public function normalizeName(string $name): string
    {
        $name = preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $name);

        if ($name === null || $name === '') {
            throw ValidationException::withMessages(['name' => 'El nombre no puede quedar vacío.']);
        }

        return $name;
    }

    /**
     * Revisa una clave ya normalizada dentro del campus; `$except` es el edificio que la conserva al editarse.
     *
     * @throws ValidationException
     */
    public function ensureCodeIsFree(Campus|string $campus, string $code, ?Building $except = null): void
    {
        $taken = Building::withTrashed()
            ->where('campus_id', $campus instanceof Campus ? $campus->getKey() : $campus)
            ->where('code', $code)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->exists();

        if ($taken) {
            throw $this->codeTaken($code);
        }
    }

    /**
     * El error de clave repetida, también para cuando la restricción única de la base gana la carrera.
     */
    public function codeTaken(string $code): ValidationException
    {
        return ValidationException::withMessages([
            'code' => "Ya existe un edificio con la clave {$code} en este campus, aunque esté desactivado o borrado.",
        ]);
    }
}
