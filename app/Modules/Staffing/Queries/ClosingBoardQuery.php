<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Rules\Constraints\AgreementsAnswered;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\RecipientsQuery;
use App\Modules\Scheduling\Queries\ClosingBlockers;
use App\Modules\Scheduling\Queries\ConflictBoardQuery;
use App\Modules\Scheduling\Queries\GroupClosingFlags;
use App\Modules\Scheduling\Queries\GroupClosingFlagsQuery;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use App\Modules\Staffing\Models\ChangeRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Lo que lee «Avance del cierre» (tarea 4.2), el tablero de Subdirección: el cierre de todas las cargas del Tec en un
 * periodo, un renglón por departamento con una entrada por carga con grupos (por campus) y lo que le falta a cada una,
 * contado por grupo como el veto de HC-15.
 *
 * Lee todo en unas cuantas consultas, sin importar cuántas cargas haya: las cargas, las banderas de HC-15 de los grupos
 * del periodo (GroupClosingFlagsQuery, contadas con la regla por grupo del veto), las solicitudes de cambio y los
 * acuerdos (AgreementsAnswered::countsByLoad). Los choques duros son los vigentes de `schedule_conflicts` (como
 * ConflictBoardQuery), no los del validador, que tardaría segundos por carga; por eso el tablero dice cuándo se
 * recalcularon y el veto sigue decidiendo al cerrar.
 *
 * Una carga sin grupos se queda en borrador y no aparece por campus; un departamento sin grupos en ningún campus va al
 * final. A una carga publicada no se le cuentan faltantes: ya pasó el cierre (se reabre para corregirla).
 */
final class ClosingBoardQuery
{
    private const array STATES = ['missing', 'ready', 'closed', 'published'];

    public function __construct(
        private readonly AgreementsAnswered $agreements,
        private readonly ConflictBoardQuery $conflicts,
        private readonly RecipientsQuery $recipients,
        private readonly GroupClosingFlagsQuery $flags,
    ) {}

    /**
     * El tablero del periodo. `$search` filtra por clave o nombre del departamento sin importar acentos; `$state`
     * (missing, ready, closed o published) deja los departamentos con alguna carga en esa cubeta (LoadProgress::bucket).
     * Las cifras de arriba (`kpis`) son de todo el Tec; los totales, de los renglones que quedan.
     *
     * @return array{
     *     rows: list<array{department: Department, loads: list<LoadProgress>, groups: int, hard: int, unscheduled: int, withoutTeacher: int, withoutRoom: int, others: int, justified: int}>,
     *     kpis: array{missing: int, ready: int, closed: int, published: int, total: int},
     *     totals: array{groups: int, loads: int, hard: int, unscheduled: int, withoutTeacher: int, withoutRoom: int, others: int, justified: int},
     *     departments: int,
     *     campuses: Collection<int, Campus>,
     *     syncedAt: ?CarbonImmutable,
     *     unsynced: list<string>
     * }
     */
    public function board(Term $term, string $search = '', string $state = ''): array
    {
        $loads = AcademicLoad::query()->where('term_id', $term->getKey())->with(['campus', 'department' => fn ($query) => $query->withTrashed()])->get();
        $progress = $this->progress($term, $loads);
        $withGroups = $progress->filter(fn (LoadProgress $load): bool => $load->groups > 0);

        $rows = $loads->pluck('department')->unique('id')
            ->map(fn (Department $department): array => [
                'department' => $department,
                'loads' => $withGroups->filter(fn (LoadProgress $load): bool => $load->load->department_id === $department->id)
                    ->sortBy(fn (LoadProgress $load): string => $load->load->campus->code)->values()->all(),
            ])
            ->map(fn (array $row): array => [...$row, ...self::sums(collect($row['loads']))])
            ->sort(fn (array $a, array $b): int => [$a['loads'] === [], Str::lower(Str::ascii($a['department']->name))] <=> [$b['loads'] === [], Str::lower(Str::ascii($b['department']->name))])
            ->values();

        $needle = Str::lower(Str::ascii(trim($search)));
        $state = in_array($state, self::STATES, true) ? $state : '';
        $visible = $rows->filter(fn (array $row): bool => ($needle === '' || str_contains(Str::lower(Str::ascii($row['department']->code.' '.$row['department']->name)), $needle))
            && ($state === '' || array_any($row['loads'], fn (LoadProgress $load): bool => $load->bucket() === $state)))->values();
        $visibleLoads = $visible->flatMap(fn (array $row): array => $row['loads']);

        $campuses = $withGroups->map(fn (LoadProgress $load): Campus => $load->load->campus)->unique('id')->sortBy('code')->values();
        $stamps = $campuses->mapWithKeys(fn (Campus $campus): array => [$campus->code => $this->conflicts->lastSyncedAt($term, $campus)]);

        return [
            'rows' => $visible->all(),
            'kpis' => [
                ...collect(self::STATES)->mapWithKeys(fn (string $bucket): array => [$bucket => $withGroups->filter(fn (LoadProgress $load): bool => $load->bucket() === $bucket)->count()])->all(),
                'total' => $withGroups->count(),
            ],
            'totals' => [...self::sums($visibleLoads), 'loads' => $visibleLoads->count()],
            'departments' => $rows->count(),
            'campuses' => $campuses,
            'syncedAt' => $stamps->filter()->sortBy(fn (CarbonImmutable $at): int => $at->getTimestamp())->first(),
            'unsynced' => $stamps->filter(fn (?CarbonImmutable $at): bool => $at === null)->keys()->values()->all(),
        ];
    }

    /**
     * El jefe de cada departamento de los renglones, por id: quienes tienen vigente pasar la carga a asignación
     * (`academic_load.load.start_staffing`, que solo tiene la jefatura; RecipientsQuery::holders) en sus campus con
     * grupos, por nombre; ninguno si no hay titular. Va aparte de board() y lee a los titulares una vez para todos los
     * renglones (RecipientsQuery::holdersByKey).
     *
     * @param  list<array{department: Department, loads: list<LoadProgress>}>  $rows
     * @return array<string, list<string>>
     */
    public function heads(array $rows): array
    {
        $targets = collect($rows)->mapWithKeys(function (array $row): array {
            $campuses = array_values(array_unique(array_map(fn (LoadProgress $load): string => $load->load->campus_id, $row['loads'])));

            return [$row['department']->id => Target::of(campus: $campuses === [] ? null : $campuses, unit: $row['department']->id)];
        })->all();

        return array_map(
            fn (array $holders): array => array_map(fn (array $holder): string => $holder['user']->name, $holders),
            $this->recipients->holdersByKey(AcademicLoadPermission::LoadStartStaffing, $targets),
        );
    }

    /**
     * Las sumas de un conjunto de cargas, por columna del tablero.
     *
     * @param  Collection<int, LoadProgress>  $loads
     * @return array{groups: int, hard: int, unscheduled: int, withoutTeacher: int, withoutRoom: int, others: int, justified: int}
     */
    private static function sums(Collection $loads): array
    {
        return [
            'groups' => (int) $loads->sum('groups'),
            'hard' => (int) $loads->sum('hard'),
            'unscheduled' => (int) $loads->sum('unscheduled'),
            'withoutTeacher' => (int) $loads->sum('withoutTeacher'),
            'withoutRoom' => (int) $loads->sum('withoutRoom'),
            'others' => (int) $loads->sum(fn (LoadProgress $load): int => $load->others()),
            'justified' => (int) $loads->sum('justified'),
        ];
    }

    /**
     * Las cifras de cada carga del periodo, con una consulta para las banderas de los grupos (GroupClosingFlagsQuery),
     * una para las solicitudes y una para los acuerdos. Cada carga se cuenta con la regla por grupo del veto
     * (ClosingBlockers::tally) y el perfil de su campus, pero con los choques duros vigentes de `schedule_conflicts`.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, AcademicLoad>  $loads
     * @return Collection<int, LoadProgress>
     */
    private function progress(Term $term, Collection $loads): Collection
    {
        $groups = $this->flags->ofTerm($term->getKey())->groupBy(fn (GroupClosingFlags $group): string => "{$group->campusId}|{$group->departmentId}");

        $requests = ChangeRequest::query()->toBase()
            ->where('term_id', $term->getKey())
            ->whereIn('status', [ChangeRequestStatus::Pending->value, ChangeRequestStatus::Approved->value])
            ->groupBy('campus_id', 'department_id')
            ->selectRaw("campus_id || '|' || department_id as load_key, count(*) as total")
            ->pluck('total', 'load_key');

        $agreements = $this->agreements->countsByLoad($term->getKey());

        return $loads->map(function (AcademicLoad $load) use ($groups, $requests, $agreements): LoadProgress {
            $key = "{$load->campus_id}|{$load->department_id}";
            $ofLoad = $groups[$key] ?? collect();
            $blockers = ClosingBlockers::tally($ofLoad, $load->campus->profile);

            // Una carga publicada ya pasó el cierre: no se le cuentan faltantes, como en «Cierre de la carga».
            if (in_array($load->status, [LoadStatus::Published, LoadStatus::Reanalysis], true)) {
                return new LoadProgress(load: $load, groups: $ofLoad->count(), justified: $blockers->justified);
            }

            return new LoadProgress(
                load: $load,
                groups: $ofLoad->count(),
                hard: $blockers->conflicted,
                unscheduled: $blockers->unscheduled,
                withoutTeacher: $blockers->withoutTeacher,
                withoutRoom: $blockers->withoutRoom,
                inactiveTeacher: $blockers->inactiveTeacher,
                requests: (int) ($requests[$key] ?? 0),
                shared: $agreements[$key]['proposed'] ?? 0,
                awaiting: $agreements[$key]['awaiting'] ?? 0,
                justified: $blockers->justified,
                blockingGroups: $blockers->groups,
            );
        });
    }
}
