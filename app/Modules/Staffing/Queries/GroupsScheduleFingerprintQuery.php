<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;

/**
 * La huella barata de unos grupos y sus sesiones (CR-03): el sha256 de sus datos de horario (docente, estado, campus,
 * cupo y última edición) y de cada sesión (día, horas, salón y tipo), con dos consultas. ChooseChangeAlternative la
 * compara ya con los candados tomados, donde rearmar la entrada del motor (la huella completa, SV-02) costaría leer
 * toda la foto de la modalidad.
 */
final class GroupsScheduleFingerprintQuery
{
    /**
     * @param  list<string>  $groupIds
     */
    public function of(array $groupIds): string
    {
        $groups = Group::query()->whereIn('id', $groupIds)->orderBy('id')
            ->get(['id', 'teacher_id', 'status', 'campus_id', 'capacity', 'updated_at'])
            ->map(fn (Group $group): array => [$group->id, $group->teacher_id, $group->status->value, $group->campus_id, $group->capacity, (string) $group->updated_at])
            ->all();
        $sessions = GroupSession::query()->whereIn('group_id', $groupIds)->orderBy('group_id')->orderBy('id')
            ->get(['id', 'group_id', 'day', 'starts_at', 'ends_at', 'room_id', 'kind'])
            ->map(fn (GroupSession $session): array => [$session->id, $session->group_id, $session->day, (string) $session->starts_at, (string) $session->ends_at, $session->room_id, $session->kind->value])
            ->all();

        return hash('sha256', json_encode([$groups, $sessions], JSON_THROW_ON_ERROR));
    }
}
