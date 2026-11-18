<?php

namespace App\Modules\Scheduling\Rules;

use App\Modules\Academic\Models\Subject;
use App\Modules\Demand\Rules\SuggestedGroups;
use App\Modules\Facilities\Models\SubjectRoomDefault;

/**
 * La capacidad con la que nace un grupo nuevo (tarea 3.3, CreateGroupsFromProposal; tarea 8.3b, abrir un grupo en el
 * re-análisis): la del salón por defecto de la materia en el campus (el de menor prioridad, sin contar los borrados);
 * sin él, el tamaño objetivo de SuggestedGroups (35, o 25 con laboratorio).
 */
final class GroupCapacity
{
    public static function for(Subject $subject, string $campusId, ?SuggestedGroups $sizes = null): int
    {
        $default = SubjectRoomDefault::query()
            ->with('room')
            ->where('subject_id', $subject->getKey())
            ->where('campus_id', $campusId)
            ->orderBy('priority')
            ->first();

        return $default?->room?->capacity ?? ($sizes ?? SuggestedGroups::fromSettings())->sizeFor((bool) $subject->needs_lab);
    }
}
