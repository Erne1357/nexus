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
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Rules\GroupCapacity;
use App\Modules\Scheduling\Rules\LoadEditability;
use App\Modules\Scheduling\Rules\ParallelPlacement;
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
     * ingreso (solo con paquetes), los grupos sueltos con sus docentes y cupos, el tamaño objetivo y los salones por defecto
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
            return new Parallels($term, $modality, $department, $blocked, []);
        }

        $careers = Career::query()->withTrashed()->whereIn('id', $proposals->only($statuses->keys()->all())->pluck('career_id')->all())->get()->keyBy('id');
        $intakes = $this->newStudents($items);
        $groups = $this->looseGroups($term->getKey(), $modality->getKey(), $department->getKey())->get()
            ->map(fn (Group $group): array => ['subject' => $group->subject_id, 'campus' => $group->campus_id, 'group' => self::existing($group)])
            ->groupBy(fn (array $row): string => "{$row['subject']}|{$row['campus']}");

        $sizes = SuggestedGroups::fromSettings();
        $rows = $items->groupBy(fn (ProposalItem $item): string => "{$item->subject_id}|{$item->campus_id}");
        $capacities = GroupCapacity::forMany($rows->map(fn (Collection $rowItems): array => [$rowItems->first()->subject, $rowItems->first()->campus_id])->values(), $sizes);

        $subjects = $rows->map(function (Collection $rowItems, string $key) use ($proposals, $statuses, $careers, $intakes, $groups, $sizes, $capacities): ParallelSubject {
            $entries = $rowItems
                ->map(fn (ProposalItem $item): ParallelCareer => $this->career($item, $careers[$proposals[$item->career_proposal_id]->career_id], $statuses[$item->career_proposal_id], $intakes))
                ->sortBy(fn (ParallelCareer $career): string => $career->career->code)
                ->values();
            $existing = $groups->get($key, collect())->pluck('group')->all();

            return $this->row($rowItems->first(), $entries, $existing, $sizes, $capacities[$key]);
        })
            ->sortBy(fn (ParallelSubject $row): string => $row->subject->code.'|'.$row->campus->code)
            ->values()
            ->all();

        return new Parallels($term, $modality, $department, $blocked, $subjects);
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
     * Un grupo leído con looseGroups(), como lo muestra la pestaña.
     */
    public static function existing(Group $group): ParallelExisting
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
    private function row(ProposalItem $item, Collection $entries, array $existing, SuggestedGroups $sizes, int $newCapacity): ParallelSubject
    {
        [$excluded, $counting] = $entries->partition(fn (ParallelCareer $career): bool => $career->status === InboxStatus::Returned);
        $careers = $counting->filter(fn (ParallelCareer $career): bool => $career->expected > 0 || $career->requested > 0)->values();

        $parallels = ParallelGroups::suggest($sizes->sizeFor((bool) $item->subject->needs_lab), $careers
            ->mapWithKeys(fn (ParallelCareer $career): array => [$career->career->getKey() => ['expected' => $career->expected, 'requested' => $career->requested]])
            ->all());
        $layout = $parallels->layout();
        $placement = ParallelPlacement::place($layout, array_map(fn (ParallelExisting $group): array => $group->forPlacement(), $existing));
        $byId = collect($existing)->keyBy('id');
        $full = array_sum(array_column($parallels->careers, 'full'));

        $suggestion = array_map(function (array $expected, int $index) use ($placement, $byId, $newCapacity, $full): ParallelSlot {
            $keeps = isset($placement->kept[$index]) ? $byId[$placement->kept[$index]] : null;
            $owner = $keeps?->ownerCareerId ?? (string) array_key_first($expected);
            $capacity = $keeps?->capacity ?? $newCapacity;

            return new ParallelSlot($expected, count($expected) > 1 ? $expected : [$owner => $capacity], $owner, $capacity, $index < $full, $keeps);
        }, $layout, array_keys($layout));

        return new ParallelSubject(
            subject: $item->subject,
            campus: $item->campus,
            careers: $careers->all(),
            excluded: $excluded->values()->all(),
            parallels: $parallels,
            groups: $existing,
            suggestion: $suggestion,
            cancelled: array_map(fn (string $id): ParallelExisting => $byId[$id], $placement->cancelled),
            whyNot: $this->whyNot($careers, $parallels),
        );
    }

    /**
     * Por qué no hay nada que juntar (maqueta B): una sola carrera, cada carrera llena sus grupos o juntando no se ahorra.
     *
     * @param  Collection<int, ParallelCareer>  $careers
     */
    private function whyNot(Collection $careers, ParallelGroups $parallels): ?string
    {
        return match (true) {
            $careers->isEmpty() => 'Ninguna carrera que cuente la pide',
            $careers->count() === 1 => 'Solo la pide '.$careers->first()->career->code,
            count($parallels->mixedShares) < 2 => 'Cada carrera llena sus grupos',
            $parallels->saved() <= 0 => 'Juntando no se ahorran grupos',
            default => null,
        };
    }
}
