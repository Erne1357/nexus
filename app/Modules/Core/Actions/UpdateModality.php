<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Events\ModalityUpdated;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Rules\ModalityRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Cambia los datos, el estado (activar o desactivar) o el perfil de flujo de una modalidad: solo las claves que recibe.
 * Quién puede cambiar qué lo decide ModalityPolicy antes de llamarla. Hace cumplir las mismas reglas que CreateModality
 * (ModalityRules); conservar la clave propia vale.
 *
 * En una transacción vuelve a leer la modalidad con bloqueo (`lockForUpdate`), así el valor anterior de la bitácora es
 * el confirmado aunque `$modality` llegue vieja, y la deja en `$modality`. El perfil se compara normalizado (PostgreSQL
 * reordena el jsonb): uno igual no cuenta como cambio. Un perfil guardado inválido no estorba: los demás cambios
 * funcionan y cualquier perfil válido lo repara. Si nada cambió, no escribe, no registra ni avisa; si algo cambió,
 * guarda, deja en la bitácora el valor anterior y el nuevo de cada campo y, después del commit, avisa con
 * ModalityUpdated.
 *
 * Temporal, hasta el paso de contracción del plan de carga por modalidad: Scheduling todavía lee el perfil de
 * `campuses.settings`, así que cuando cambia el perfil se escribe igual en cada campus liga a esta modalidad por el
 * puente `campuses.modality_id` (con UpdateCampus: bitácora y CampusUpdated de cada campus), en la misma transacción:
 * si un campus lo rechaza no queda nada guardado y nada diverge en silencio.
 */
final class UpdateModality
{
    public function __construct(
        private readonly ModalityRules $rules,
        private readonly UpdateCampus $updateCampus,
    ) {}

    /**
     * @param  array{code?: string, name?: string, settings?: array<string, mixed>, is_active?: bool}  $data
     *
     * @throws ValidationException
     */
    public function handle(Modality $modality, array $data): Modality
    {
        $data = Arr::only($data, ['code', 'name', 'settings', 'is_active']);

        if (array_key_exists('code', $data)) {
            $data['code'] = $this->rules->normalizeCode($data['code']);
        }

        if (array_key_exists('settings', $data)) {
            $data['settings'] = $this->rules->profile($data['settings']);
        }

        return DB::transaction(function () use ($modality, $data): Modality {
            $modality->setRawAttributes($modality->newQuery()->lockForUpdate()->findOrFail($modality->getKey())->getAttributes(), sync: true);
            $old = [...$modality->only(['code', 'name', 'is_active']), 'settings' => $this->storedProfile($modality)];

            if (array_key_exists('code', $data)) {
                $this->rules->ensureCodeIsFree($data['code'], $modality);
            }

            if (array_key_exists('settings', $data) && $data['settings'] === $old['settings']) {
                unset($data['settings']);
            }

            $modality->fill($data);
            $changed = array_keys($modality->getDirty());

            if ($changed === []) {
                return $modality;
            }

            try {
                $modality->save();
            } catch (UniqueConstraintViolationException) {
                throw $this->rules->codeTaken($modality->code);
            }

            activity()
                ->performedOn($modality)
                ->event(ActivityEvent::Updated)
                ->withChanges([
                    'attributes' => $modality->only($changed),
                    'old' => Arr::only($old, $changed),
                ])
                ->log("Actualizó la modalidad {$modality->code}");

            if (in_array('settings', $changed, true)) {
                $this->copyProfileToCampuses($modality);
            }

            ModalityUpdated::dispatch($modality, $changed);

            return $modality;
        });
    }

    /**
     * El perfil nuevo en cada campus de la modalidad (puente temporal; ver la documentación de la clase).
     *
     * @throws ValidationException
     */
    private function copyProfileToCampuses(Modality $modality): void
    {
        Campus::query()
            ->where('modality_id', $modality->getKey())
            ->orderBy('code')
            ->get()
            ->each(fn (Campus $campus) => $this->updateCampus->handle($campus, ['settings' => $modality->settings]));
    }

    /**
     * El perfil guardado, normalizado; si ModalityProfile no lo acepta, tal como está (así queda en la bitácora como
     * valor anterior).
     *
     * @return array<string, mixed>
     */
    private function storedProfile(Modality $modality): array
    {
        try {
            return $modality->profile->toArray();
        } catch (InvalidArgumentException) {
            return $modality->settings ?? [];
        }
    }
}
