<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Events\OrgUnitMoved;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Rules\OrgUnitRules;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Cuelga una unidad, con sus hijas, de otra madre (spec §6.1). AZ-S13 (confirmado el 3 de noviembre de 2026): la
 * Dirección y los departamentos académicos no se mueven (`unit`). La madre debe estar activa y no ser un departamento
 * académico (`parent`) y no puede ser la unidad ni una de sus descendientes (`parent`). Quién puede lo decide
 * OrgUnitPolicy.
 *
 * Los movimientos se serializan con un candado consultivo (`org_units.tree`) para que la revisión de ciclos lea un
 * árbol que no cambia a medias; si aun así el árbol cambió, el trigger `org_units_no_cycle` (SQLSTATE P0001) rechaza
 * y se traduce al mismo mensaje. A la misma madre no escribe. La unidad queda al final de sus nuevas hermanas.
 *
 * Deja la bitácora de `parent_id` con las claves de la madre vieja y la nueva (`from`, `to`) y avisa con
 * OrgUnitMoved después del commit.
 */
final class MoveOrgUnit
{
    public function __construct(private readonly OrgUnitRules $rules) {}

    /**
     * @throws ValidationException
     */
    public function handle(OrgUnit $unit, OrgUnit $parent): OrgUnit
    {
        return DB::transaction(function () use ($unit, $parent): OrgUnit {
            DB::select("select pg_advisory_xact_lock(hashtext('org_units.tree'))");

            $unit->setRawAttributes($unit->newQuery()->lockForUpdate()->findOrFail($unit->getKey())->getAttributes(), sync: true);

            if (($problem = $this->rules->moveBlocker($unit)) !== null) {
                throw ValidationException::withMessages(['unit' => $problem]);
            }

            $parent = OrgUnit::withTrashed()->whereKey($parent->getKey())->lockForUpdate()->first() ?? $parent;

            if (($problem = $this->rules->parentProblem($parent)) !== null) {
                throw ValidationException::withMessages(['parent' => $problem]);
            }

            if ($this->rules->wouldCycle($unit, $parent)) {
                throw $this->rules->cycleError($unit);
            }

            if ($unit->parent_id === $parent->getKey()) {
                return $unit;
            }

            $oldParent = OrgUnit::withTrashed()->find($unit->parent_id);
            $last = OrgUnit::query()->where('parent_id', $parent->getKey())->max('sort');

            $unit->parent_id = $parent->getKey();
            $unit->sort = $last === null ? 0 : $last + 1;

            try {
                $unit->save();
            } catch (QueryException $exception) {
                throw $this->isCycle($exception) ? $this->rules->cycleError($unit) : $exception;
            }

            activity()
                ->performedOn($unit)
                ->event(ActivityEvent::Updated)
                ->withProperties(['from' => $oldParent?->code, 'to' => $parent->code])
                ->withChanges(['attributes' => ['parent_id' => $parent->getKey()], 'old' => ['parent_id' => $oldParent?->getKey()]])
                ->log("Movió la unidad {$unit->code} de {$oldParent?->code} a {$parent->code}");

            OrgUnitMoved::dispatch($unit);

            return $unit;
        });
    }

    private function isCycle(QueryException $exception): bool
    {
        return (string) ($exception->errorInfo[0] ?? $exception->getCode()) === 'P0001' && str_contains($exception->getMessage(), 'ciclo');
    }
}
