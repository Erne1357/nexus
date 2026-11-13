<?php

namespace App\Modules\Scheduling\Actions;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Core\Models\Campus;
use App\Modules\Scheduling\Events\GroupMovedToCampus;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Queries\GroupQuotasQuery;
use App\Modules\Scheduling\Rules\LoadEditability;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Cambia el campus de un grupo (M8: el grupo nace con campus y se cambia mientras la carga esté abierta; MS9, supuesto
 * por confirmar). No sabe de puestos: quién lo cambia lo decide la pantalla con GroupPolicy::moveToCampus (gestionar el
 * grupo en el campus de ahora y en el nuevo).
 *
 * En una transacción toma la carga (FOR SHARE) y relee el grupo con bloqueo, como las Actions del editor, y exige:
 * - la carga editable y el grupo sin cancelar (LoadEditability);
 * - ninguna sesión con salón: el salón es del campus del grupo, así que primero se quitan (MS9);
 * - ningún acuerdo de grupo compartido propuesto o aceptado (MS25): el acuerdo guarda el campus del grupo y lo decidió
 *   el otro departamento para ese campus;
 * - el campus nuevo activo y sin borrar, y que ofrezca la carrera dueña del grupo (`career_campus` activa, HC-13).
 *
 * Al mismo campus no escribe ni avisa. Si cambia, las sesiones toman el campus nuevo por el trigger de `groups`, deja la
 * bitácora con el campus anterior y el nuevo, y avisa dentro de la transacción con GroupMovedToCampus, para que las
 * copias del campus del grupo de los módulos de la derecha cambien en la misma transacción. Después del commit avisa
 * con GroupScheduleChanged, que recalcula los choques de su modalidad (los traslados de SC-09 cambian) y su ranking.
 */
final class MoveGroupToCampus
{
    public function __construct(
        private readonly LoadEditability $editability,
        private readonly GroupQuotasQuery $quotas,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Group $group, Campus $campus): Group
    {
        return $this->editability->guard(fn (): Group => DB::transaction(function () use ($group, $campus): Group {
            $this->editability->lockLoad($group);
            $group->setRawAttributes($group->newQuery()->lockForUpdate()->findOrFail($group->getKey())->getAttributes(), sync: true);

            $this->editability->ensure($group, 'grupos', 'campus');

            if ($group->campus_id === $campus->getKey()) {
                return $group;
            }

            $this->ensureWithoutRooms($group);
            $this->ensureWithoutSharedGroup($group);
            $this->ensureOffers($campus, $group);

            $from = Campus::query()->withTrashed()->findOrFail($group->campus_id);
            $group->update(['campus_id' => $campus->getKey()]);

            activity()
                ->performedOn($group)
                ->event(ActivityEvent::Updated)
                ->withChanges(['attributes' => ['campus_id' => $campus->getKey()], 'old' => ['campus_id' => $from->getKey()]])
                ->log("Cambió el grupo {$group->code} de {$from->name} a {$campus->name}");

            GroupMovedToCampus::dispatch($group->getKey(), $group->term_id, $group->modality_id, $from->getKey(), $campus->getKey());
            GroupScheduleChanged::dispatch($group);

            return $group;
        }), 'campus');
    }

    /**
     * MS25: un grupo con un acuerdo de grupo compartido vivo (propuesto o aceptado) no cambia de campus.
     *
     * @throws ValidationException
     */
    private function ensureWithoutSharedGroup(Group $group): void
    {
        if ($this->quotas->hasLiveSharedGroup($group)) {
            throw ValidationException::withMessages(['campus' => "El grupo {$group->code} tiene un acuerdo de grupo compartido propuesto o aceptado: resuélvelo antes de cambiarlo de campus (MS25)."]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function ensureWithoutRooms(Group $group): void
    {
        $withRoom = $group->sessions()->whereNotNull('room_id')->count();

        if ($withRoom > 0) {
            throw ValidationException::withMessages(['campus' => "El grupo {$group->code} tiene salón en {$withRoom} ".($withRoom === 1 ? 'sesión: quítalo' : 'sesiones: quítalos').' antes de cambiarlo de campus.']);
        }
    }

    /**
     * @throws ValidationException
     */
    private function ensureOffers(Campus $campus, Group $group): void
    {
        $campus = Campus::query()->withTrashed()->findOrFail($campus->getKey());

        if (! $campus->is_active || $campus->trashed()) {
            throw ValidationException::withMessages(['campus' => "{$campus->name} está inactivo: el grupo no se cambia ahí."]);
        }

        $offered = CareerCampus::query()->where('career_id', $group->owner_career_id)->where('campus_id', $campus->getKey())->where('is_active', true)->exists();

        if (! $offered) {
            $career = Career::query()->withTrashed()->whereKey($group->owner_career_id)->value('code') ?? 'la carrera del grupo';

            throw ValidationException::withMessages(['campus' => "{$campus->name} no ofrece {$career}: el grupo no se cambia ahí (HC-13)."]);
        }
    }
}
