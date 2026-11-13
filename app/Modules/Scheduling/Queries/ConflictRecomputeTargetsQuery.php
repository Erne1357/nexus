<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;
use Illuminate\Database\Eloquent\Builder;

/**
 * Qué cargas (periodo y modalidad, M1) hay que recalcular cuando cambia algo que lee el validador (tarea 3.2): un
 * grupo, el docente de un grupo (HC-01 cruza campus y modalidades), un salón, un docente (horas, plazas,
 * disponibilidad) o el perfil de una modalidad. Sin periodo, mira los periodos que no han terminado.
 */
final class ConflictRecomputeTargetsQuery
{
    /**
     * El periodo y la modalidad del grupo, más las modalidades donde estos docentes tienen grupos activos en el periodo.
     *
     * @param  list<string|null>  $teacherIds
     * @return list<array{string, string}>
     */
    public function forGroup(Group $group, array $teacherIds = []): array
    {
        $targets = ["{$group->term_id}|{$group->modality_id}" => [$group->term_id, $group->modality_id]];

        foreach (array_filter($teacherIds) as $teacherId) {
            foreach ($this->forTeacher($teacherId, $group->term_id) as $target) {
                $targets[implode('|', $target)] = $target;
            }
        }

        return array_values($targets);
    }

    /**
     * Los periodos y modalidades donde el docente tiene grupos activos.
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
     * Los periodos y modalidades con grupos en el campus del salón (el indicado, o los que no han terminado): un salón lo
     * comparten las modalidades de su campus.
     *
     * @return list<array{string, string}>
     */
    public function forRoom(Room $room, ?string $termId = null): array
    {
        return $this->pairs(Group::query()->where('campus_id', $room->campus_id)
            ->when($termId !== null, fn (Builder $query) => $query->where('term_id', $termId), fn (Builder $query) => $this->openTerms($query)));
    }

    /**
     * Los periodos abiertos en la modalidad (`term_openings`, M4) que no han terminado, con ella: su perfil (ventana,
     * días y bloques de HC-04, tolerancia de HC-12, MS17) cambió.
     *
     * @return list<array{string, string}>
     */
    public function forModality(Modality $modality): array
    {
        return Term::query()
            ->whereIn('id', TermOpening::query()->where('modality_id', $modality->getKey())->select('term_id'))
            ->where('ends_on', '>=', today()->toDateString())
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (string $termId): array => [$termId, $modality->getKey()])
            ->all();
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
        return $query->distinct()->orderBy('term_id')->orderBy('modality_id')->get(['term_id', 'modality_id'])
            ->map(fn (Group $group): array => [$group->term_id, $group->modality_id])
            ->all();
    }
}
