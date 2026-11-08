<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\Core\Models\Term;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;
use Illuminate\Database\Eloquent\Builder;

/**
 * Qué cargas (periodo y campus) hay que recalcular cuando cambia algo que lee el validador (tarea 3.2): un grupo, el
 * docente de un grupo (HC-01 cruza campus), un salón o un docente (horas, plazas, disponibilidad). Sin periodo, mira
 * los periodos que no han terminado.
 */
final class ConflictRecomputeTargetsQuery
{
    /**
     * El periodo y campus del grupo, más los campus donde estos docentes tienen grupos activos en el periodo.
     *
     * @param  list<string|null>  $teacherIds
     * @return list<array{string, string}>
     */
    public function forGroup(Group $group, array $teacherIds = []): array
    {
        $targets = ["{$group->term_id}|{$group->campus_id}" => [$group->term_id, $group->campus_id]];

        foreach (array_filter($teacherIds) as $teacherId) {
            foreach ($this->forTeacher($teacherId, $group->term_id) as $target) {
                $targets[implode('|', $target)] = $target;
            }
        }

        return array_values($targets);
    }

    /**
     * Los periodos y campus donde el docente tiene grupos activos.
     *
     * @return list<array{string, string}>
     */
    public function forTeacher(string $teacherId, ?string $termId = null): array
    {
        return $this->pairs(Group::query()->where('teacher_id', $teacherId)
            ->where('status', '<>', GroupStatus::Cancelled->value)
            ->when($termId !== null, fn (Builder $query) => $query->where('term_id', $termId), fn (Builder $query) => $this->openTerms($query)));
    }

    /**
     * Los periodos con grupos en el campus del salón (el indicado, o los que no han terminado).
     *
     * @return list<array{string, string}>
     */
    public function forRoom(Room $room, ?string $termId = null): array
    {
        return $this->pairs(Group::query()->where('campus_id', $room->campus_id)
            ->when($termId !== null, fn (Builder $query) => $query->where('term_id', $termId), fn (Builder $query) => $this->openTerms($query)));
    }

    /**
     * @param  Builder<Group>  $query
     */
    private function openTerms(Builder $query): void
    {
        $query->whereIn('term_id', Term::query()->where('ends_on', '>=', today()->toDateString())->select('id'));
    }

    /**
     * @param  Builder<Group>  $query
     * @return list<array{string, string}>
     */
    private function pairs(Builder $query): array
    {
        return $query->distinct()->orderBy('term_id')->orderBy('campus_id')->get(['term_id', 'campus_id'])
            ->map(fn (Group $group): array => [$group->term_id, $group->campus_id])
            ->all();
    }
}
