<?php

namespace App\Modules\Facilities\Actions;

use App\Modules\Core\Models\Campus;
use App\Modules\Facilities\Events\BuildingCreated;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Rules\BuildingRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Da de alta un edificio del campus. Recibe datos ya validados en forma (el componente) y hace cumplir
 * BuildingRules: clave en mayúsculas, sin espacios y única en el campus, también entre los borrados. Una
 * regla rota es una ValidationException sobre su campo.
 *
 * En una transacción revisa la clave, escribe y deja bitácora; si otra sesión guarda la misma clave entre
 * la revisión y el insert, la restricción única de la base responde con el mismo error. Después del
 * commit avisa con BuildingCreated. Quién puede lo decide BuildingPolicy antes de llamarla.
 */
final class CreateBuilding
{
    public function __construct(private readonly BuildingRules $rules) {}

    /**
     * @param  array{code: string, name: string}  $data
     *
     * @throws ValidationException
     */
    public function handle(Campus $campus, array $data): Building
    {
        $code = $this->rules->normalizeCode($data['code']);
        $name = $this->rules->normalizeName($data['name']);

        return DB::transaction(function () use ($campus, $code, $name): Building {
            $this->rules->ensureCodeIsFree($campus, $code);

            try {
                $building = Building::create(['campus_id' => $campus->getKey(), 'code' => $code, 'name' => $name, 'is_active' => true]);
            } catch (UniqueConstraintViolationException) {
                throw $this->rules->codeTaken($code);
            }

            activity()
                ->performedOn($building)
                ->event(ActivityEvent::Created)
                ->withChanges(['attributes' => $building->only(['campus_id', 'code', 'name', 'is_active'])])
                ->log("Dio de alta el edificio {$building->code}");

            BuildingCreated::dispatch($building);

            return $building;
        });
    }
}
