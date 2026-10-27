<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Enums\Modality;
use App\Modules\Core\Events\CampusUpdated;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\CampusProfile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Cambia los datos, el estado (activar o desactivar) o el perfil de flujo de un campus: solo las
 * claves que recibe. Quién puede cambiar qué lo decide CampusPolicy antes de llamarla. Hace cumplir
 * las mismas reglas que CreateCampus (docs/dominio/modelo-de-datos.md, `campuses`):
 *
 * - `code` se guarda en mayúsculas y sin espacios y es único entre todos los campus, también los
 *   borrados; conservar el propio vale.
 * - `settings` debe ser un perfil de flujo que CampusProfile acepte; se guarda completo y normalizado.
 *
 * El perfil se compara ya normalizado: PostgreSQL reordena las claves del jsonb y un perfil igual no
 * cuenta como cambio. Si nada cambió, no escribe, no registra ni avisa. Si algo cambió, en una
 * transacción guarda, deja en la bitácora el valor anterior y el nuevo de cada campo que cambió y
 * dispara CampusUpdated, que sale después del commit.
 */
final class UpdateCampus
{
    /**
     * @param  array{code?: string, name?: string, modality?: Modality|string, settings?: array<string, mixed>, is_active?: bool}  $data
     *
     * @throws ValidationException
     */
    public function handle(Campus $campus, array $data): Campus
    {
        $old = [...$campus->only(['code', 'name', 'modality', 'is_active']), 'settings' => $campus->profile->toArray()];

        if (array_key_exists('code', $data)) {
            $data['code'] = $this->freeCode($data['code'], $campus);
        }

        if (array_key_exists('settings', $data)) {
            $data['settings'] = $this->validProfile($data['settings']);

            if ($data['settings'] === $old['settings']) {
                unset($data['settings']);
            }
        }

        $campus->fill(Arr::only($data, ['code', 'name', 'modality', 'settings', 'is_active']));
        $changed = array_keys($campus->getDirty());

        if ($changed === []) {
            return $campus;
        }

        return DB::transaction(function () use ($campus, $changed, $old): Campus {
            $campus->save();

            activity()
                ->performedOn($campus)
                ->event(ActivityEvent::Updated)
                ->withChanges([
                    'attributes' => $campus->only($changed),
                    'old' => Arr::only($old, $changed),
                ])
                ->log("Actualizó el campus {$campus->code}");

            CampusUpdated::dispatch($campus, $changed);

            return $campus;
        });
    }

    /**
     * @throws ValidationException
     */
    private function freeCode(string $code, Campus $campus): string
    {
        $code = Str::upper(preg_replace('/\s+/u', '', $code));

        Validator::make(['code' => $code], ['code' => Rule::unique(Campus::class, 'code')->ignore($campus)], [
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
