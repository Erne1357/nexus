<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\InboxStatus;
use App\Modules\AcademicLoad\Enums\ProposalReviewStatus;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalIntake;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\AcademicLoad\Rules\ParallelGroups;
use App\Modules\AcademicLoad\Rules\ProposalReviewRules;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Demand\Enums\ForecastMethod;
use App\Modules\Demand\Rules\SuggestedGroups;
use App\Modules\Scheduling\Events\GroupsCancelling;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Rules\GroupCapacity;
use App\Modules\Scheduling\Rules\LoadEditability;
use App\Modules\Scheduling\Rules\ParallelPlacement;
use App\Modules\Scheduling\Actions\Parallels\ArmParallelGroups;
use Spatie\Activitylog\Models\Activity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Lecturas de la pestaña «Paralelos» de la bandeja (tarea 8.8, opción B; reglas-de-negocio.md, «Paralelos»): el jefe del
 * departamento que imparte una materia ve lo que piden todas las carreras y las junta en menos grupos (confirmado el 18 de
 * noviembre de 2026). Quién la ve lo decide ParallelsPolicy en el componente.
 *
 * Las carreras que cuentan son las mismas que en la sugerencia de la bandeja (DepartmentInboxQuery): parte aceptada, o
 * pendiente que el departamento puede decidir (ProposalReviewRules::isDecidable); la regresada se muestra aparte y no
 * cuenta, y un borrador no se ve. Solo las filas con campus: una fila sin campus todavía no da grupos (MS5). Los grupos de
 * paquete no se juntan: cuentan los esperados y grupos sueltos (ParallelCareer) y los grupos sin paquete.
 */
final class ParallelsQuery
{
    public function __construct(
        private readonly ProposalReviewRules $reviewRules,
        private readonly LoadEditability $editability,
    ) {}

    /**
     * La pestaña de un departamento. Un número fijo de consultas, sin importar cuántas materias, carreras o grupos: la carga,
     * las propuestas, las partes del departamento, las filas pedidas con sus materias y campus, las carreras, el nuevo
     * ingreso (solo con paquetes), los grupos sueltos con sus docentes y cupos, una sola pregunta a Staffing por los que no
     * se podrían cancelar (GroupsCancelling: solicitudes de cambio abiertas), el tamaño objetivo y los salones por defecto
     * (GroupCapacity) para los grupos que nacerían.
     */
    public function for(Term $term, Modality $modality, Department $department): Parallels
    {
        $load = AcademicLoad::query()->ofDepartment($term->getKey(), $modality->getKey(), $department->getKey())->first(['id', 'status']);
        $blocked = $this->editability->loadReason($load?->status, $department->getKey(), $modality->getKey(), 'paralelos');

        $proposals = CareerProposal::query()
            ->where('term_id', $term->getKey())
            ->where('modality_id', $modality->getKey())
            ->where('status', '<>', ProposalStatus::Draft->value)
            ->get(['id', 'career_id', 'status'])
            ->keyBy('id');

        $statuses = ProposalReview::query()
            ->where('department_id', $department->getKey())
            ->whereIn('career_proposal_id', $proposals->keys()->all())
            ->get(['career_proposal_id', 'status'])
            ->mapWithKeys(fn (ProposalReview $review): array => [$review->career_proposal_id => $this->statusOf($proposals[$review->career_proposal_id], $review)])
            ->filter();

        $items = ProposalItem::query()
            ->requested()
            ->whereIn('career_proposal_id', $statuses->keys()->all())
            ->whereNotNull('campus_id')
            ->whereIn('subject_id', Subject::withTrashed()->where('department_id', $department->getKey())->select('id'))
            ->with(['subject' => fn ($query) => $query->withTrashed(), 'campus' => fn ($query) => $query->withTrashed()])
            ->get();

        if ($items->isEmpty()) {
            return new Parallels($term, $modality, $department, $blocked, [], $load?->status);
        }

        $careers = Career::query()->withTrashed()->whereIn('id', $proposals->only($statuses->keys()->all())->pluck('career_id')->all())->get()->keyBy('id');
        $intakes = $this->newStudents($items);
        $loose = $this->looseGroups($term->getKey(), $modality->getKey(), $department->getKey())->get();
        $vetoes = self::vetoes($loose, $items->pluck('subject.name', 'subject_id')->all());
        $groups = $loose
            ->map(fn (Group $group): array => ['subject' => $group->subject_id, 'campus' => $group->campus_id, 'group' => self::existing($group, $vetoes[$group->getKey()] ?? null)])
            ->groupBy(fn (array $row): string => "{$row['subject']}|{$row['campus']}");

        $sizes = SuggestedGroups::fromSettings();
        $rows = $items->groupBy(fn (ProposalItem $item): string => "{$item->subject_id}|{$item->campus_id}");
        $capacities = GroupCapacity::forMany($rows->map(fn (Collection $rowItems): array => [$rowItems->first()->subject, $rowItems->first()->campus_id])->values(), $sizes);
        $armed = $this->armed($term, $modality, $items->pluck('subject_id')->unique()->values()->all());

        $subjects = $rows->map(function (Collection $rowItems, string $key) use ($proposals, $statuses, $careers, $intakes, $groups, $sizes, $capacities, $armed): ParallelSubject {
            $entries = $rowItems
                ->map(fn (ProposalItem $item): ParallelCareer => $this->career($item, $careers[$proposals[$item->career_proposal_id]->career_id], $statuses[$item->career_proposal_id], $intakes))
                ->sortBy(fn (ParallelCareer $career): string => $career->career->code)
                ->values();
            $existing = $groups->get($key, collect())->pluck('group')->all();

            return $this->row($rowItems->first(), $entries, $existing, $sizes, $capacities[$key], isset($armed[$key]));
        })
            ->sortBy(fn (ParallelSubject $row): string => $row->subject->code.'|'.$row->campus->code)
            ->values()
            ->all();

        return new Parallels($term, $modality, $department, $blocked, $subjects, $load?->status);
    }

    /**
     * Las materias y campus que ya se armaron con ArmParallelGroups en el periodo y la modalidad, por `materia|campus`: la
     * Action deja en la bitácora de la materia un `parallels_arranged` con el periodo, la modalidad y el campus cada vez que
     * arma, aunque el arreglo sea solo de completos y restos de una carrera o no cambie nada (supuesto por confirmar). Una
     * consulta.
     *
     * @param  list<string>  $subjectIds
     * @return array<string, true>
     */
    private function armed(Term $term, Modality $modality, array $subjectIds): array
    {
        return Activity::query()
            ->where('event', ArmParallelGroups::LOG_EVENT)
            ->where('subject_type', (new Subject)->getMorphClass())
            ->whereIn('subject_id', $subjectIds)
            ->where('properties->term_id', $term->getKey())
            ->where('properties->modality_id', $modality->getKey())
            ->get(['subject_id', 'properties'])
            ->mapWithKeys(fn (Activity $entry): array => ["{$entry->subject_id}|{$entry->properties['campus_id']}" => true])
            ->all();
    }

    /**
     * Los grupos sueltos sin cancelar del departamento en el periodo y la modalidad (con campus), por orden de creación, con
     * su docente, sus cupos, cuántas sesiones tienen y sus minutos colocados (`scheduled_minutes`). Con materia y campus,
     * solo los de esa materia en ese campus. Lo usan la pestaña y ArmParallelGroups (que los bloquea).
     *
     * @return Builder<Group>
     */
    public function looseGroups(string $termId, string $modalityId, string $departmentId, ?string $subjectId = null, ?string $campusId = null): Builder
    {
        return Group::query()
            ->active()
            ->where('groups.term_id', $termId)
            ->where('groups.modality_id', $modalityId)
            ->where('groups.department_id', $departmentId)
            ->whereNull('groups.package_id')
            ->whereNotNull('groups.campus_id')
            ->when($subjectId !== null, fn (Builder $query) => $query->where('groups.subject_id', $subjectId))
            ->when($campusId !== null, fn (Builder $query) => $query->where('groups.campus_id', $campusId))
            ->select('groups.*')
            ->withCount('sessions')
            ->addSelect(['scheduled_minutes' => GroupSession::query()
                ->selectRaw('coalesce(sum(extract(epoch from (ends_at - starts_at)) / 60), 0)::int')
                ->whereColumn('group_sessions.group_id', 'groups.id')])
            ->with(['teacher' => fn ($query) => $query->withTrashed(), 'quotas'])
            ->orderBy('groups.id');
    }

    /**
     * Pregunta a los módulos de la derecha, con el evento con veto GroupsCancelling y una sola vez para todos, qué grupos no
     * se podrían cancelar (una solicitud de cambio abierta), sin escribir nada: el primer motivo de cada uno, por id.
     *
     * @param  iterable<Group>  $groups
     * @param  array<string, string>  $subjectNames  por id de materia
     * @return array<string, string>
     */
    public static function vetoes(iterable $groups, array $subjectNames): array
    {
        $groups = collect($groups);

        if ($groups->isEmpty()) {
            return [];
        }

        $question = new GroupsCancelling($groups->mapWithKeys(fn (Group $group): array => [
            $group->getKey() => ($subjectNames[$group->subject_id] ?? 'La materia').", grupo {$group->code}",
        ])->all());
        event($question);

        return $groups->mapWithKeys(fn (Group $group): array => [$group->getKey() => $question->reasonsFor($group->getKey())[0] ?? null])->filter()->all();
    }

    /**
     * Un grupo leído con looseGroups(), como lo muestra la pestaña, con el motivo por el que no se cancelaría (vetoes()).
     */
    public static function existing(Group $group, ?string $vetoed = null): ParallelExisting
    {
        return new ParallelExisting(
            id: $group->getKey(),
            code: $group->code,
            ownerCareerId: $group->owner_career_id,
            capacity: $group->capacity,
            teacher: $group->teacher_id === null ? null : ($group->teacher?->name ?? 'Docente'),
            sessions: (int) $group->sessions_count,
            minutes: (int) $group->scheduled_minutes,
            quotas: $group->quotas->sortByDesc('is_owner')->mapWithKeys(fn (GroupQuota $quota): array => [$quota->career_id => $quota->max_seats])->all(),
            vetoed: $vetoed,
        );
    }

    /**
     * Los esperados del reparto de una fila (los rezagados si tiene paquetes) y sus grupos sueltos.
     *
     * @param  array<string, int>  $intakes  nuevo ingreso por `propuesta|campus`
     */
    public static function looseDemand(ProposalItem $item, array $intakes): array
    {
        $newStudents = $item->package_groups > 0 && $item->forecast_method === ForecastMethod::FirstSemester
            ? ($intakes["{$item->career_proposal_id}|{$item->campus_id}"] ?? 0)
            : 0;

        return [
            'newStudents' => $newStudents,
            'expected' => max(0, $item->expected_students - $newStudents),
            'requested' => max(0, $item->requested_groups - $item->package_groups),
        ];
    }

    /**
     * El nuevo ingreso vigente por `propuesta|campus`, solo de las propuestas con filas de paquete.
     *
     * @param  Collection<int, ProposalItem>  $items
     * @return array<string, int>
     */
    public function newStudents(Collection $items): array
    {
        $withPackages = $items->where('package_groups', '>', 0)->pluck('career_proposal_id')->unique()->values()->all();

        if ($withPackages === []) {
            return [];
        }

        return ProposalIntake::query()->whereIn('career_proposal_id', $withPackages)->get(['career_proposal_id', 'campus_id', 'new_students'])
            ->mapWithKeys(fn (ProposalIntake $intake): array => ["{$intake->career_proposal_id}|{$intake->campus_id}" => (int) $intake->new_students])
            ->all();
    }

    /**
     * El estado de la parte que se muestra: aceptada, pendiente que se puede decidir o regresada; null si no se muestra.
     */
    private function statusOf(CareerProposal $proposal, ProposalReview $review): ?InboxStatus
    {
        return match (true) {
            $review->status === ProposalReviewStatus::Accepted => InboxStatus::Accepted,
            $review->status === ProposalReviewStatus::Returned => InboxStatus::Returned,
            $this->reviewRules->isDecidable($proposal->status, $review->status) => InboxStatus::Pending,
            default => null,
        };
    }

    /**
     * @param  array<string, int>  $intakes
     */
    private function career(ProposalItem $item, Career $career, InboxStatus $status, array $intakes): ParallelCareer
    {
        $loose = self::looseDemand($item, $intakes);

        return new ParallelCareer($career, $status, $item, $loose['newStudents'], $loose['expected'], $loose['requested']);
    }

    /**
     * @param  Collection<int, ParallelCareer>  $entries
     * @param  list<ParallelExisting>  $existing
     */
    private function row(ProposalItem $item, Collection $entries, array $existing, SuggestedGroups $sizes, int $newCapacity, bool $armed): ParallelSubject
    {
        [$returned, $counting] = $entries->partition(fn (ParallelCareer $career): bool => $career->status === InboxStatus::Returned);
        [$careers, $withoutStudents] = $counting->partition(fn (ParallelCareer $career): bool => $career->expected > 0);
        $careers = $careers->values();

        $parallels = ParallelGroups::suggest($sizes->sizeFor((bool) $item->subject->needs_lab), $careers
            ->mapWithKeys(fn (ParallelCareer $career): array => [$career->career->getKey() => ['expected' => $career->expected, 'requested' => $career->requested]])
            ->all());
        $byId = collect($existing)->keyBy('id');
        [$suggestion, $short, $placement] = self::armable($parallels->layout(), $existing, $byId, $newCapacity, array_sum(array_column($parallels->careers, 'full')));

        return new ParallelSubject(
            subject: $item->subject,
            campus: $item->campus,
            careers: $careers->all(),
            excluded: $returned->concat($withoutStudents)->sortBy(fn (ParallelCareer $career): string => $career->career->code)->values()->all(),
            parallels: $parallels,
            groups: $existing,
            suggestion: $suggestion,
            cancelled: array_map(fn (string $id): ParallelExisting => $byId[$id], $placement->cancelled),
            whyNot: $this->whyNot($careers, $withoutStudents->isNotEmpty(), $parallels),
            short: $short,
            newCapacity: $newCapacity,
            armed: $armed,
        );
    }

    /**
     * La sugerencia, armable tal cual: ArmParallelGroups coloca los grupos que ya existen sobre los cupos del arreglo que
     * recibe, no sobre los esperados, y el cupo de un grupo de una sola carrera es la capacidad del que se queda en él. Así
     * que se coloca sobre los esperados, se arman los cupos con las capacidades de los que se quedaron (slots()) y se vuelve
     * a colocar sobre esos cupos hasta que se quedan los mismos: entonces la Action hace lo mismo y ningún cupo pasa la
     * capacidad del grupo que lo recibe (HC-07). Si no se asienta (dos grupos de la misma carrera con capacidades distintas
     * pueden ir y venir), los cupos se arman con la capacidad más chica de todas (la de un grupo nuevo o la de los que ya
     * existen), que cabe en cualquier grupo, y se coloca una vez sobre ellos (supuesto por confirmar: se pierden lugares de los
     * grupos grandes antes que sugerir un arreglo que la Action rechaza).
     *
     * @param  list<array<string, int>>  $layout
     * @param  list<ParallelExisting>  $existing
     * @param  Collection<string, ParallelExisting>  $byId
     * @return array{0: list<ParallelSlot>, 1: array<string, int>, 2: ParallelPlacement}
     */
    private static function armable(array $layout, array $existing, Collection $byId, int $newCapacity, int $full): array
    {
        $groups = array_map(fn (ParallelExisting $group): array => $group->forPlacement(), $existing);
        $quotas = fn (array $slots): array => array_map(fn (ParallelSlot $slot): array => $slot->quotas, $slots);
        $placement = ParallelPlacement::place($layout, $groups);

        for ($round = 0; $round < 4; $round++) {
            [$suggestion, $short] = self::slots($layout, $placement, $byId, $newCapacity, $full);
            $actual = ParallelPlacement::place($quotas($suggestion), $groups);

            if ($actual->kept === $placement->kept) {
                return [$suggestion, $short, $actual];
            }

            $placement = $actual;
        }

        $floor = min([$newCapacity, ...array_map(fn (ParallelExisting $group): int => $group->capacity, $existing)]);
        [$suggestion, $short] = self::slots($layout, ParallelPlacement::place([], []), $byId, $floor, $full);
        $placement = ParallelPlacement::place($quotas($suggestion), $groups);

        return [array_map(function (ParallelSlot $slot, int $index) use ($placement, $byId, $newCapacity): ParallelSlot {
            $keeps = isset($placement->kept[$index]) ? $byId[$placement->kept[$index]] : null;

            return new ParallelSlot($slot->expected, $slot->quotas, $keeps?->ownerCareerId ?? $slot->ownerCareerId, $keeps?->capacity ?? $newCapacity, $slot->full, $keeps);
        }, $suggestion, array_keys($suggestion)), $short, $placement];
    }

    /**
     * Los grupos de la sugerencia con sus cupos, sin pasar la capacidad real de cada uno (la del grupo que se queda o la de
     * uno nuevo, GroupCapacity), y los esperados que no caben en ninguno (supuesto por confirmar):
     *
     * - un grupo de una sola carrera le da toda su capacidad; lo que pasa de ella sobra;
     * - en un mixto cada carrera recibe sus esperados en exclusiva y lo demás queda libre (confirmado el 18 de noviembre de
     *   2026); si suman más que la capacidad, sobra lo de la carrera con más lugares;
     * - lo que sobra de cada carrera se acomoda en los lugares libres de otros mixtos, primero donde ya va la carrera, y lo que
     *   no cabe queda de menos (`short`).
     *
     * @param  list<array<string, int>>  $layout
     * @param  Collection<string, ParallelExisting>  $byId
     * @return array{0: list<ParallelSlot>, 1: array<string, int>}
     */
    private static function slots(array $layout, ParallelPlacement $placement, Collection $byId, int $newCapacity, int $full): array
    {
        $slots = [];
        $overflow = [];

        foreach ($layout as $index => $expected) {
            $keeps = isset($placement->kept[$index]) ? $byId[$placement->kept[$index]] : null;
            $owner = $keeps?->ownerCareerId ?? (string) array_key_first($expected);
            $capacity = $keeps?->capacity ?? $newCapacity;
            $mixed = count($expected) > 1;

            if ($mixed) {
                $excess = array_sum($expected) - $capacity;
                arsort($expected);

                foreach (array_keys($expected) as $career) {
                    $take = min(max(0, $excess), $expected[$career]);
                    $expected[$career] -= $take;
                    $excess -= $take;
                    $overflow[$career] = ($overflow[$career] ?? 0) + $take;
                }

                $expected = array_filter($expected);
            } else {
                $overflow[$owner] = ($overflow[$owner] ?? 0) + max(0, $expected[$owner] - $capacity);
            }

            $slots[$index] = ['expected' => $expected, 'owner' => $owner, 'capacity' => $capacity, 'mixed' => $mixed, 'keeps' => $keeps];
        }

        $short = [];

        foreach (array_filter($overflow) as $career => $left) {
            foreach ([true, false] as $carrying) {
                foreach ($slots as $index => $slot) {
                    $free = $slot['capacity'] - array_sum($slot['expected']);

                    if ($left > 0 && $slot['mixed'] && $free > 0 && isset($slot['expected'][$career]) === $carrying) {
                        $take = min($left, $free);
                        $slots[$index]['expected'][$career] = ($slot['expected'][$career] ?? 0) + $take;
                        $left -= $take;
                    }
                }
            }

            if ($left > 0) {
                $short[$career] = $left;
            }
        }

        return [array_map(fn (array $slot, int $index): ParallelSlot => new ParallelSlot(
            $slot['expected'],
            $slot['mixed'] ? $slot['expected'] : [$slot['owner'] => $slot['capacity']],
            $slot['owner'],
            $slot['capacity'],
            $index < $full,
            $slot['keeps'],
        ), $slots, array_keys($slots)), $short];
    }

    /**
     * Por qué no hay nada que juntar (maqueta B): ninguna carrera con esperados, una sola, cada carrera llena sus grupos o
     * juntando no se ahorra. Las carreras sin esperados no cuentan.
     *
     * @param  Collection<int, ParallelCareer>  $careers  las que cuentan y esperan alumnos
     */
    private function whyNot(Collection $careers, bool $othersWithoutStudents, ParallelGroups $parallels): ?string
    {
        return match (true) {
            $careers->isEmpty() => $othersWithoutStudents ? 'Ninguna carrera espera alumnos' : 'Ninguna carrera que cuente la pide',
            $careers->count() === 1 => 'Solo la pide '.$careers->first()->career->code,
            count($parallels->mixedShares) < 2 => 'Cada carrera llena sus grupos',
            $parallels->saved() <= 0 => 'Juntando no se ahorran grupos',
            default => null,
        };
    }
}
