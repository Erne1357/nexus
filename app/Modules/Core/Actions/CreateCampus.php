<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Enums\Modality;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\CampusProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Da de alta un campus. Recibe datos ya validados en forma (el componente) y hace cumplir las reglas
 * del campus de docs/dominio/modelo-de-datos.md (`campuses`):
 *
 * - `code` se guarda en mayúsculas y sin espacios (C1, C2, EAD, ENL, TNMV) y es único entre todos
 *   los campus, también los borrados: el código de un campus borrado sigue reservado.
 * - `settings` debe ser un perfil de flujo que CampusProfile acepte; se guarda completo y normalizado.
 *
 * Una regla rota lanza ValidationException con la clave del campo, que Livewire muestra junto a él.
 * Escribe y deja bitácora en la misma transacción. No avisa con un evento: nada depende aún de un
 * campus que acaba de nacer (no hay perfil en caché que invalidar ni alcances en él); al cambiarlo,
 * UpdateCampus sí avisa.
 */
final class CreateCampus
{
    /**
     * @param  array{code: string, name: string, modality: Modality|string, settings: array<string, mixed>}  $data
     *
     * @throws ValidationException
     */
    public function handle(array $data): Campus
    {
        $code = $this->freeCode($data['code']);
        $settings = $this->validProfile($data['settings']);

        return DB::transaction(function () use ($code, $data, $settings): Campus {
            $campus = Campus::create([
                'code' => $code,
                'name' => $data['name'],
                'modality' => $data['modality'],
                'settings' => $settings,
                'is_active' => true,
            ]);

            activity()
                ->performedOn($campus)
                ->event(ActivityEvent::Created)
                ->withChanges(['attributes' => $campus->only(['code', 'name', 'modality', 'settings', 'is_active'])])
                ->log("Dio de alta el campus {$campus->code}");

            return $campus;
        });
    }

    /**
     * @throws ValidationException
     */
    private function freeCode(string $code): string
    {
        $code = Str::upper(preg_replace('/\s+/u', '', $code));

        Validator::make(['code' => $code], ['code' => Rule::unique(Campus::class, 'code')], [
            'code.unique' => "Ya existe un campus con el código {$code}, aunque esté desactivado o borrado.",
        ])->validate();

        return $code;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validProfile(array $settings): array
    {
        try {
            return CampusProfile::fromArray($settings)->toArray();
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['settings' => $exception->getMessage()]);
        }
    }
}
