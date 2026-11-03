<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Events\OrgUnitUpdated;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Rules\OrgUnitRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Cambia el nombre, la clave o el tipo de una unidad estructural: solo las claves que recibe (spec §6.1). AZ-S13: un
 * departamento académico se edita en Académico (`unit`) y la clave de DIRECCION y SUB-ACADEMICA no cambia (`code`; su
 * nombre sí). Una clave nueva debe ser libre (`code`) y un tipo nuevo no puede dejar sin lugar los nombramientos de la
 * unidad (`kind`). Quién puede lo decide OrgUnitPolicy.
 *
 * Relee la unidad con `lockForUpdate`, así el valor anterior de la bitácora es el confirmado. Si nada cambió, no
 * escribe, no registra ni avisa; si algo cambió, deja en la bitácora el valor anterior y el nuevo de lo que cambió y,
 * después del commit, avisa con OrgUnitUpdated.
 */
final class UpdateOrgUnit
{
    public function __construct(private readonly OrgUnitRules $rules) {}

    /**
     * @param  array{code?: string, name?: string, kind?: OrgUnitKind}  $data
     *
     * @throws ValidationException
     */
    public function handle(OrgUnit $unit, array $data): OrgUnit
    {
        $data = Arr::only($data, ['code', 'name', 'kind']);

        return DB::transaction(function () use ($unit, $data): OrgUnit {
            $unit->setRawAttributes($unit->newQuery()->lockForUpdate()->findOrFail($unit->getKey())->getAttributes(), sync: true);

            if (($locked = $this->rules->lockedReason($unit)) !== null) {
                throw ValidationException::withMessages(['unit' => $locked]);
            }

            $errors = [];

            if (isset($data['code']) && $data['code'] !== $unit->code) {
                if (($problem = $this->rules->fixedCodeProblem($unit, $data['code'])) !== null) {
                    $errors['code'] = $problem;
                } else {
                    try {
                        $this->rules->ensureCodeIsFree($data['code'], $unit);
                    } catch (ValidationException $exception) {
                        $errors['code'] = $exception->errors()['code'][0];
                    }
                }
            }

            if (isset($data['kind']) && $data['kind'] !== $unit->kind && ($problem = $this->rules->kindProblem($data['kind'], $unit)) !== null) {
                $errors['kind'] = $problem;
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $unit->fill($data);
            $changed = array_keys($unit->getDirty());

            if ($changed === []) {
                return $unit;
            }

            $old = Arr::only($unit->getRawOriginal(), $changed);

            try {
                $unit->save();
            } catch (UniqueConstraintViolationException) {
                throw $this->rules->codeTaken($unit->code);
            }

            activity()
                ->performedOn($unit)
                ->event(ActivityEvent::Updated)
                ->withChanges(['attributes' => Arr::only($unit->getAttributes(), $changed), 'old' => $old])
                ->log("Editó la unidad {$unit->code}");

            OrgUnitUpdated::dispatch($unit);

            return $unit;
        });
    }
}
