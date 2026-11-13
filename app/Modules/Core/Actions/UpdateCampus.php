<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Events\CampusUpdated;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Rules\CampusRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Cambia los datos o el estado (activar o desactivar) de un campus: solo las claves que recibe. Quién puede cambiar
 * qué lo decide CampusPolicy antes de llamarla. Hace cumplir las mismas reglas que CreateCampus (CampusRules);
 * conservar el código propio vale. El campus es solo el lugar (MS3, supuesto por confirmar): no tiene modalidad ni
 * perfil de flujo.
 *
 * En una transacción vuelve a leer el campus con bloqueo (`lockForUpdate`), así el valor anterior de
 * la bitácora es el confirmado aunque `$campus` llegue viejo, y lo deja en `$campus`. Si nada cambió, no escribe, no
 * registra ni avisa; si algo cambió, guarda, deja en la bitácora el valor anterior y el nuevo de cada campo y, después
 * del commit, avisa con CampusUpdated.
 */
final class UpdateCampus
{
    public function __construct(private readonly CampusRules $rules) {}

    /**
     * @param  array{code?: string, name?: string, is_active?: bool}  $data
     *
     * @throws ValidationException
     */
    public function handle(Campus $campus, array $data): Campus
    {
        $data = Arr::only($data, ['code', 'name', 'is_active']);

        if (array_key_exists('code', $data)) {
            $data['code'] = $this->rules->normalizeCode($data['code']);
        }

        return DB::transaction(function () use ($campus, $data): Campus {
            $campus->setRawAttributes($campus->newQuery()->lockForUpdate()->findOrFail($campus->getKey())->getAttributes(), sync: true);
            $old = $campus->only(['code', 'name', 'is_active']);

            if (array_key_exists('code', $data)) {
                $this->rules->ensureCodeIsFree($data['code'], $campus);
            }

            $campus->fill($data);
            $changed = array_keys($campus->getDirty());

            if ($changed === []) {
                return $campus;
            }

            try {
                $campus->save();
            } catch (UniqueConstraintViolationException) {
                throw $this->rules->codeTaken($campus->code);
            }

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
}
