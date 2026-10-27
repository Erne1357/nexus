<?php

namespace App\Modules\Core\Rules;

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\CampusProfile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Reglas de un campus que comparten CreateCampus y UpdateCampus (docs/dominio/modelo-de-datos.md,
 * `campuses`). Cada regla rota es una ValidationException sobre su campo, que Livewire muestra junto
 * a él.
 *
 * - `code`: texto UTF-8 válido, en mayúsculas, sin espacios (también los de Unicode, como NBSP), no
 *   vacío y único entre todos los campus, también los borrados: el código de uno borrado sigue
 *   reservado.
 * - `settings`: un perfil de flujo que CampusProfile acepte; se guarda completo y normalizado.
 */
final class CampusRules
{
    /**
     * @throws ValidationException si no es UTF-8 válido o queda vacío
     */
    public function normalizeCode(string $code): string
    {
        $code = preg_replace('/\s+/u', '', $code);

        if ($code === null) {
            throw ValidationException::withMessages(['code' => 'El código no es texto válido.']);
        }

        if ($code === '') {
            throw ValidationException::withMessages(['code' => 'El código no puede quedar vacío ni ser solo espacios.']);
        }

        return Str::upper($code);
    }

    /**
     * Revisa un código ya normalizado; `$except` es el campus que lo conserva al editarse.
     *
     * @throws ValidationException
     */
    public function ensureCodeIsFree(string $code, ?Campus $except = null): void
    {
        $taken = Campus::withTrashed()
            ->where('code', $code)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->exists();

        if ($taken) {
            throw $this->codeTaken($code);
        }
    }

    /**
     * El error de código repetido, también para cuando la restricción única de la base gana la
     * carrera a ensureCodeIsFree().
     */
    public function codeTaken(string $code): ValidationException
    {
        return ValidationException::withMessages([
            'code' => "Ya existe un campus con el código {$code}, aunque esté desactivado o borrado.",
        ]);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed> el perfil completo y normalizado que se guarda en `settings`
     *
     * @throws ValidationException
     */
    public function profile(array $settings): array
    {
        try {
            return CampusProfile::fromArray($settings)->toArray();
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['settings' => $exception->getMessage()]);
        }
    }
}
