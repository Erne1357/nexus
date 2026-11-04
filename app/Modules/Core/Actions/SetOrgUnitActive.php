<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Events\OrgUnitDeactivating;
use App\Modules\Core\Events\OrgUnitUpdated;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Rules\OrgUnitRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Desactiva o activa una unidad estructural (spec §6.1, AZ-S13). Quién puede lo decide OrgUnitPolicy.
 *
 * Relee la unidad con `lockForUpdate`. Al desactivar, todos los impedimentos de OrgUnitRules::deactivationBlockers()
 * (la raíz, un departamento académico, hijas activas y nombramientos vigentes o por empezar) salen juntos sobre `unit`,
 * y luego OrgUnitDeactivating, con veto, deja que un módulo de la derecha la bloquee dentro de la transacción. Al
 * activar, la madre debe estar activa. Si no cambia el estado, no escribe ni registra.
 *
 * Deja la bitácora de `is_active` y avisa con OrgUnitUpdated después del commit.
 */
final class SetOrgUnitActive
{
    public function __construct(private readonly OrgUnitRules $rules) {}

    /**
     * @throws ValidationException
     */
    public function handle(OrgUnit $unit, bool $active): OrgUnit
    {
        return DB::transaction(function () use ($unit, $active): OrgUnit {
            $unit->setRawAttributes($unit->newQuery()->lockForUpdate()->findOrFail($unit->getKey())->getAttributes(), sync: true);

            if ($unit->is_active === $active) {
                return $unit;
            }

            if ($active) {
                if (($problem = $this->rules->activationBlocker($unit)) !== null) {
                    throw ValidationException::withMessages(['unit' => $problem]);
                }
            } else {
                $blockers = $this->rules->deactivationBlockers($unit);

                if ($blockers !== []) {
                    throw ValidationException::withMessages(['unit' => $blockers]);
                }

                $event = new OrgUnitDeactivating($unit);
                event($event);

                if ($event->blocked()) {
                    throw ValidationException::withMessages(['unit' => $event->reasons()]);
                }
            }

            $unit->is_active = $active;
            $unit->save();

            activity()
                ->performedOn($unit)
                ->event(ActivityEvent::Updated)
                ->withChanges(['attributes' => ['is_active' => $active], 'old' => ['is_active' => ! $active]])
                ->log(($active ? 'Activó' : 'Desactivó')." la unidad {$unit->code}");

            OrgUnitUpdated::dispatch($unit);

            return $unit;
        });
    }
}
