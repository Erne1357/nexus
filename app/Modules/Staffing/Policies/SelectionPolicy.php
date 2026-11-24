<?php

namespace App\Modules\Staffing\Policies;

use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Enums\StaffingPermission;
use App\Modules\Staffing\Models\Selection;
use Illuminate\Database\Eloquent\Builder;

/**
 * Quién maneja, ve y elige en una selección de carga (diseño 1.33, 6.8): maneja el admin; ven los jefes y la
 * Subdirección del departamento y el docente con renglón en la cola de una selección ya publicada; elige el docente con renglón propio.
 *
 * El docente no tiene unidad, así que un permiso de gestión suyo cubriría cualquier departamento (y un puesto sin
 * acotar no puede tener permisos de gestión): no se le concede `view`; lo deja ver su renglón.
 */
final class SelectionPolicy
{
    use AuthorizesActor;

    public function manage(User $user, Selection $selection): bool
    {
        return $this->actor($user)->allows(StaffingPermission::SelectionManage, Target::of(
            modality: $selection->modality_id,
            unit: $selection->department_id,
        ));
    }

    public function view(User $user, Selection $selection): bool
    {
        return $this->manage($user, $selection)
            || $this->actor($user)->allows(StaffingPermission::SelectionView, Target::of(
                modality: $selection->modality_id,
                unit: $selection->department_id,
            ))
            || $this->hasEntry($user, $selection);
    }

    public function pickOwn(User $user, Selection $selection): bool
    {
        return $this->actor($user)->allows(StaffingPermission::SelectionPickOwn, Target::ownedBy($user->getKey()))
            && $this->hasEntry($user, $selection);
    }

    /**
     * El docente entra por su renglón: es el docente activo del usuario, sigue adscrito al departamento de la selección
     * y la selección ya se publicó (un borrador o una cancelada no se ven).
     */
    private function hasEntry(User $user, Selection $selection): bool
    {
        if (! in_array($selection->status, [SelectionStatus::Published, SelectionStatus::Running, SelectionStatus::ExtraRound, SelectionStatus::Finished], true)) {
            return false;
        }

        return $selection->entries()
            ->whereHas('teacher', fn (Builder $teachers) => $teachers
                ->where('user_id', $user->getKey())
                ->where('is_active', true)
                ->whereHas('adscriptions', fn (Builder $adscriptions) => $adscriptions->where('department_id', $selection->department_id)))
            ->exists();
    }
}
