<?php

namespace App\Modules\Core\Rules;

use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\ModalityProfile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Reglas de una modalidad que comparten CreateModality y UpdateModality (MS1, supuesto por confirmar; ver
 * docs/dominio/modelo-de-datos.md, `modalities`). Cada regla rota es una ValidationException sobre su campo, que
 * Livewire muestra junto a él.
 *
 * - `code`: texto UTF-8 válido, en mayúsculas, sin espacios (también los de Unicode, como NBSP), no vacío y único entre
 *   todas las modalidades, también las borradas: la clave de una borrada sigue reservada.
 * - `settings`: un perfil de flujo que ModalityProfile acepte; se guarda completo y normalizado.
 */
final class ModalityRules
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
     * Revisa una clave ya normalizada; `$except` es la modalidad que la conserva al editarse.
     *
     * @throws ValidationException
     */
    public function ensureCodeIsFree(string $code, ?Modality $except = null): void
    {
        $taken = Modality::withTrashed()
            ->where('code', $code)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->exists();

        if ($taken) {
            throw $this->codeTaken($code);
        }
    }

    /**
     * El error de clave repetida, también para cuando la restricción única de la base gana la carrera a
     * ensureCodeIsFree().
     */
    public function codeTaken(string $code): ValidationException
    {
        return ValidationException::withMessages([
            'code' => "Ya existe una modalidad con la clave {$code}, aunque esté desactivada o borrada.",
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
            return ModalityProfile::fromArray($settings)->toArray();
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['settings' => $exception->getMessage()]);
        }
    }
}
