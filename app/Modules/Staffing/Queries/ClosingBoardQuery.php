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
use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Queries\ConflictBoardQuery;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use App\Modules\Staffing\Models\ChangeRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lo que lee «Avance del cierre» (tarea 4.2), el tablero de Subdirección: el cierre de todas las cargas del Tec en un
 * periodo, un renglón por departamento con una entrada por carga con grupos (por campus) y lo que le falta a cada una,
 * contado por grupo como el veto de HC-15.
 *
 * Lee todo en unas cuantas consultas, sin importar cuántas cargas haya: las cargas, una agregación de los grupos por
 * campus y departamento, las solicitudes de cambio y los acuerdos (AgreementsAnswered::countsByLoad). Los choques duros
 * son los vigentes de `schedule_conflicts` (como ConflictBoardQuery), no los del validador, que tardaría segundos por
 * carga; por eso el tablero dice cuándo se recalcularon y el veto sigue decidiendo al cerrar.
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
     * grupos, por nombre; ninguno si no hay titular. Va aparte de board() porque pregunta por departamento.
     *
     * @param  list<array{department: Department, loads: list<LoadProgress>}>  $rows
     * @return array<string, list<string>>
     */
    public function heads(array $rows): array
    {
        return collect($rows)->mapWithKeys(function (array $row): array {
            $campuses = array_values(array_unique(array_map(fn (LoadProgress $load): string => $load->load->campus_id, $row['loads'])));
            $holders = $this->recipients->holders(AcademicLoadPermission::LoadStartStaffing, Target::of(campus: $campuses === [] ? null : $campuses, unit: $row['department']->id));

            return [$row['department']->id => array_map(fn (array $holder): string => $holder['user']->name, $holders)];
        })->all();
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
     * Las cifras de cada carga del periodo, con una consulta para los grupos, una para las solicitudes y una para los
     * acuerdos. Sin horario y sin salón cuentan solo si el perfil del campus usa bloques de tiempo y salones.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, AcademicLoad>  $loads
     * @return Collection<int, LoadProgress>
     */
    private function progress(Term $term, Collection $loads): Collection
    {
        $justified = "coalesce(btrim(g.close_justification), '') <> ''";
        $roomless = 'exists (select 1 from group_sessions s where s.group_id = g.id and s.is_active and s.room_id is null and s.kind <> ?)';
        // Los grupos distintos que faltan: lo que falta siempre (choque duro, sin docente, docente de baja) y, según el
        // perfil del campus, sin horario (U) y sin salón (R); se cuentan las cuatro combinaciones y se elige por campus.
        $always = 'exists (select 1 from schedule_conflicts c where c.group_id = g.id and c.resolved_at is null and c.severity = ?)'
            ." or (g.teacher_id is null and not ({$justified}))"
            .' or exists (select 1 from teachers t where t.id = g.teacher_id and (not t.is_active or t.deleted_at is not null))';
        $unscheduled = 'not exists (select 1 from group_sessions s where s.group_id = g.id and s.is_active)';
        $withoutRoom = "({$roomless} and not ({$justified}))";
        $hard = ConflictSeverity::Hard->value;
        $virtual = SessionKind::Virtual->value;

        $groups = DB::table('groups as g')
            ->where('g.term_id', $term->getKey())
            ->where('g.status', '<>', GroupStatus::Cancelled->value)
            ->groupBy('g.campus_id', 'g.department_id')
            ->select(['g.campus_id', 'g.department_id'])
            ->selectRaw('count(*) as groups')
            ->selectRaw('count(*) filter (where exists (select 1 from schedule_conflicts c where c.group_id = g.id and c.resolved_at is null and c.severity = ?)) as hard', [ConflictSeverity::Hard->value])
            ->selectRaw('count(*) filter (where not exists (select 1 from group_sessions s where s.group_id = g.id and s.is_active)) as unscheduled')
            ->selectRaw("count(*) filter (where g.teacher_id is null and not ({$justified})) as without_teacher")
            ->selectRaw("count(*) filter (where {$roomless} and not ({$justified})) as without_room", [SessionKind::Virtual->value])
            ->selectRaw('count(*) filter (where exists (select 1 from teachers t where t.id = g.teacher_id and (not t.is_active or t.deleted_at is not null))) as inactive_teacher')
            ->selectRaw("count(*) filter (where ({$justified}) and g.teacher_id is null) as justified_teacher")
            ->selectRaw("count(*) filter (where ({$justified}) and g.teacher_id is not null and {$roomless}) as justified_room", [SessionKind::Virtual->value])
            ->selectRaw("count(*) filter (where {$always} or {$unscheduled} or {$withoutRoom}) as blocking_ur", [$hard, $virtual])
            ->selectRaw("count(*) filter (where {$always} or {$unscheduled}) as blocking_u", [$hard])
            ->selectRaw("count(*) filter (where {$always} or {$withoutRoom}) as blocking_r", [$hard, $virtual])
            ->selectRaw("count(*) filter (where {$always}) as blocking", [$hard])
            ->get()
            ->keyBy(fn (object $row): string => "{$row->campus_id}|{$row->department_id}");

        $requests = ChangeRequest::query()->toBase()
            ->where('term_id', $term->getKey())
            ->whereIn('status', [ChangeRequestStatus::Pending->value, ChangeRequestStatus::Approved->value])
            ->groupBy('campus_id', 'department_id')
            ->selectRaw("campus_id || '|' || department_id as load_key, count(*) as total")
            ->pluck('total', 'load_key');

        $agreements = $this->agreements->countsByLoad($term->getKey());

        return $loads->map(function (AcademicLoad $load) use ($groups, $requests, $agreements): LoadProgress {
            $key = "{$load->campus_id}|{$load->department_id}";
            $row = $groups[$key] ?? null;
            $profile = $load->campus->profile;

            // Una carga publicada ya pasó el cierre: no se le cuentan faltantes, como en «Cierre de la carga».
            if (in_array($load->status, [LoadStatus::Published, LoadStatus::Reanalysis], true)) {
                return new LoadProgress(
                    load: $load,
                    groups: (int) ($row->groups ?? 0),
                    justified: (int) ($row->justified_teacher ?? 0) + ($profile->usesRooms ? (int) ($row->justified_room ?? 0) : 0),
                );
            }

            return new LoadProgress(
                load: $load,
                groups: (int) ($row->groups ?? 0),
                hard: (int) ($row->hard ?? 0),
                unscheduled: $profile->usesTimeBlocks ? (int) ($row->unscheduled ?? 0) : 0,
                withoutTeacher: (int) ($row->without_teacher ?? 0),
                withoutRoom: $profile->usesRooms ? (int) ($row->without_room ?? 0) : 0,
                inactiveTeacher: (int) ($row->inactive_teacher ?? 0),
                requests: (int) ($requests[$key] ?? 0),
                shared: $agreements[$key]['proposed'] ?? 0,
                awaiting: $agreements[$key]['awaiting'] ?? 0,
                justified: (int) ($row->justified_teacher ?? 0) + ($profile->usesRooms ? (int) ($row->justified_room ?? 0) : 0),
                blockingGroups: (int) ($row?->{'blocking'.($profile->usesTimeBlocks || $profile->usesRooms ? '_' : '').($profile->usesTimeBlocks ? 'u' : '').($profile->usesRooms ? 'r' : '')} ?? 0),
            );
        });
    }
}
