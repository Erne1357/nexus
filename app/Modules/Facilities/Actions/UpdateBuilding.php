<?php

namespace App\Modules\Facilities\Actions;

use App\Modules\Facilities\Events\BuildingUpdated;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Rules\BuildingRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Cambia la clave, el nombre o el estado (activar o desactivar) de un edificio: solo las claves que
 * recibe; el campus no cambia. Quién puede lo decide BuildingPolicy antes de llamarla. Hace cumplir las
 * mismas reglas que CreateBuilding; conservar la clave propia vale.
 *
 * En una transacción vuelve a leer el edificio con bloqueo (`lockForUpdate`), así el valor anterior de la
 * bitácora es el confirmado aunque `$building` llegue viejo, y lo deja en `$building`. Si nada cambió, no
 * escribe, no registra ni avisa; si algo cambió, guarda, deja en la bitácora el valor anterior y el nuevo
 * de cada campo y, después del commit, avisa con BuildingUpdated.
 */
final class UpdateBuilding
{
    public function __construct(private readonly BuildingRules $rules) {}

    /**
     * @param  array{code?: string, name?: string, is_active?: bool}  $data
     *
     * @throws ValidationException
     */
    public function handle(Building $building, array $data): Building
    {
        $data = Arr::only($data, ['code', 'name', 'is_active']);

        if (array_key_exists('code', $data)) {
            $data['code'] = $this->rules->normalizeCode($data['code']);
        }

        if (array_key_exists('name', $data)) {
            $data['name'] = $this->rules->normalizeName($data['name']);
        }

        return DB::transaction(function () use ($building, $data): Building {
            $building->setRawAttributes($building->newQuery()->lockForUpdate()->findOrFail($building->getKey())->getAttributes(), sync: true);
            $old = $building->only(['code', 'name', 'is_active']);

            if (array_key_exists('code', $data)) {
                $this->rules->ensureCodeIsFree($building->campus_id, $data['code'], $building);
            }

            $building->fill($data);
            $changed = array_keys($building->getDirty());

            if ($changed === []) {
                return $building;
            }

            try {
                $building->save();
            } catch (UniqueConstraintViolationException) {
                throw $this->rules->codeTaken($building->code);
            }

            activity()
                ->performedOn($building)
                ->event(ActivityEvent::Updated)
                ->withChanges([
                    'attributes' => $building->only($changed),
                    'old' => Arr::only($old, $changed),
                ])
                ->log("Actualizó el edificio {$building->code}");

            BuildingUpdated::dispatch($building, $changed);

            return $building;
        });
    }
}
