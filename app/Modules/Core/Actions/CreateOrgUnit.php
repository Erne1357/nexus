<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Events\OrgUnitCreated;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Rules\OrgUnitRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Da de alta una unidad estructural bajo una madre (spec §6.1, AZ-S13). Quién puede lo decide OrgUnitPolicy. La
 * Dirección es la raíz única y los departamentos académicos se dan de alta en Administración › Departamentos, así que esos tipos se
 * rechazan (`kind`). Relee la madre con `lockForUpdate` y revisa que siga activa y que no sea un departamento
 * académico (`parent`). La clave debe ser libre (`code`); si otra sesión la toma entre la revisión y la escritura, el
 * índice único de la base da el mismo error. La unidad queda al final de sus hermanas.
 *
 * Deja la bitácora `created` sobre la unidad y avisa con OrgUnitCreated después del commit.
 */
final class CreateOrgUnit
{
    public function __construct(private readonly OrgUnitRules $rules) {}

    /**
     * @param  array{code: string, name: string, kind: OrgUnitKind}  $data
     *
     * @throws ValidationException
     */
    public function handle(OrgUnit $parent, array $data): OrgUnit
    {
        return DB::transaction(function () use ($parent, $data): OrgUnit {
            $parent = OrgUnit::withTrashed()->whereKey($parent->getKey())->lockForUpdate()->first() ?? $parent;
            $errors = [];

            if (($problem = $this->rules->kindProblem($data['kind'])) !== null) {
                $errors['kind'] = $problem;
            }

            if (($problem = $this->rules->parentProblem($parent)) !== null) {
                $errors['parent'] = $problem;
            }

            try {
                $this->rules->ensureCodeIsFree($data['code']);
            } catch (ValidationException $exception) {
                $errors['code'] = $exception->errors()['code'][0];
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $last = OrgUnit::query()->where('parent_id', $parent->getKey())->max('sort');

            try {
                $unit = OrgUnit::create([
                    'parent_id' => $parent->getKey(),
                    'code' => $data['code'],
                    'name' => $data['name'],
                    'kind' => $data['kind'],
                    'sort' => $last === null ? 0 : $last + 1,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw $this->rules->codeTaken($data['code']);
            }

            activity()
                ->performedOn($unit)
                ->event(ActivityEvent::Created)
                ->withChanges(['attributes' => [
                    'parent_id' => $parent->getKey(),
                    'code' => $unit->code,
                    'name' => $unit->name,
                    'kind' => $unit->kind->value,
                    'sort' => $unit->sort,
                ]])
                ->log("Dio de alta la unidad {$unit->code} · {$unit->name}");

            OrgUnitCreated::dispatch($unit);

            return $unit;
        });
    }
}
