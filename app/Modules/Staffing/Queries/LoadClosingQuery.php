<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\AcademicLoad\Models\LoadTransition;
use App\Modules\AcademicLoad\Rules\Constraints\AgreementsAnswered;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Queries\ClosingBlockers;
use App\Modules\Scheduling\Queries\ClosingBlockersQuery;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use App\Modules\Staffing\Models\ChangeRequest;
use App\Modules\Staffing\Rules\ScheduleSummary;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Str;

/**
 * Lo que leen «Cierre de la carga» y el detalle de «Avance del cierre» (tareas 4.1 y 4.2; reglas-de-negocio.md,
 * "Cierre y reapertura de la carga"):
 *
 * - de qué departamentos puede ver el cierre quien entra (los que puede cerrar, `academic_load.load.close`) y, de un
 *   departamento, sus cargas del periodo con grupos activos, una por campus (las pestañas); una carga sin grupos se
 *   queda en borrador y no tiene pestaña;
 * - de una carga (of()), lo que le falta para cerrar con el validador al momento (ClosingBlockersQuery, la misma
 *   cuenta del veto), los acuerdos que la detienen (AgreementsAnswered), las solicitudes de cambio pendientes y
 *   aprobadas sin aplicar (las del veto de Staffing), los grupos sin docente o sin salón con su justificación, quién y
 *   cuándo, los docentes dados de baja, el historial de sus estados con nombres y los grupos activos que el cierre
 *   confirma.
 *
 * El validador tarda con cargas grandes, así que of() acepta lo que ya calculó la pantalla (`$blockers`) para no
 * repetirlo en cada petición; el veto sigue decidiendo al cerrar.
 */
final class LoadClosingQuery
{
    public function __construct(
        private readonly ClosingBlockersQuery $closingBlockers,
        private readonly AgreementsAnswered $agreements,
    ) {}

    /**
     * Los departamentos con alguna carga en el periodo que el usuario puede cerrar, por clave: el suyo para el jefe;
     * todos para Subdirección y el admin. `has_groups` dice si alguna de esas cargas tiene grupos activos: el selector
     * solo ofrece esos, pero el departamento del jefe se ve aunque no tenga grupos.
     *
     * @return Collection<int, Department>
     */
    public function departmentsFor(Actor $actor, string $termId): Collection
    {
        $withGroups = $actor->constrain(AcademicLoad::query()->where('academic_loads.term_id', $termId), AcademicLoadPermission::LoadClose, [
            'campus' => 'academic_loads.campus_id',
            'unit' => 'academic_loads.department_id',
        ])
            ->groupBy('academic_loads.department_id')
            ->select('academic_loads.department_id')
            ->selectRaw('bool_or(exists (select 1 from groups where groups.term_id = academic_loads.term_id and groups.campus_id = academic_loads.campus_id and groups.department_id = academic_loads.department_id and groups.status <> ?)) as has_groups', [GroupStatus::Cancelled->value])
            ->toBase()->pluck('has_groups', 'department_id');

        return Department::query()->withTrashed()->whereIn('id', $withGroups->keys()->all())->orderBy('code')->get(['id', 'code', 'name'])
            ->each(fn (Department $department) => $department->setAttribute('has_groups', (bool) $withGroups[$department->id]));
    }

    /**
     * Las cargas del departamento en el periodo con grupos activos, por código de campus, con su campus y cuántos
     * grupos activos tienen (`active_groups`).
     *
     * @return Collection<int, AcademicLoad>
     */
    public function loadsOf(string $termId, string $departmentId): Collection
    {
        return AcademicLoad::query()
            ->where('academic_loads.term_id', $termId)
            ->where('academic_loads.department_id', $departmentId)
            ->join('campuses', 'campuses.id', '=', 'academic_loads.campus_id')
            ->whereExists($this->activeGroupsOf(...))
            ->select('academic_loads.*')
            ->selectSub($this->activeGroupsCount(), 'active_groups')
            ->orderBy('campuses.code')
            ->with(['campus', 'department'])
            ->get();
    }

    /**
     * Una carga por un id del cliente; 404 si no existe.
     */
    public function findOrFail(string $id): AcademicLoad
    {
        /** @var AcademicLoad */
        return KeyLookup::findOrFail(AcademicLoad::query()->with(['campus', 'department', 'term']), $id);
    }

    /**
     * Un grupo activo de la carga por un id del cliente, para justificarlo; 404 si no es de ella.
     */
    public function groupOrFail(AcademicLoad $load, string $groupId): Group
    {
        /** @var Group */
        return KeyLookup::findOrFail($this->groupsOf($load)->with('campus'), $groupId);
    }

    /**
     * De `$groupIds` (del cliente), los que son grupos activos de la carga, en el mismo orden.
     *
     * @param  list<string>  $groupIds
     * @return list<string>
     */
    public function groupIdsOf(AcademicLoad $load, array $groupIds): array
    {
        $ids = array_values(array_filter($groupIds, fn (mixed $id): bool => is_string($id) && Str::isUuid($id)));
        $own = $ids === [] ? [] : $this->groupsOf($load)->whereIn('groups.id', $ids)->pluck('groups.id')->all();

        return array_values(array_intersect($ids, $own));
    }

    /**
     * Lo que le falta a la carga para cerrar (fresco, con el validador al momento), o null si no aplica: una carga en
     * borrador no tiene grupos y una publicada ya pasó el cierre.
     */
    public function blockers(AcademicLoad $load): ?ClosingBlockers
    {
        return in_array($load->status, [LoadStatus::Draft, LoadStatus::Published, LoadStatus::Reanalysis], true)
            ? null
            : $this->closingBlockers->of($load);
    }

    /**
     * Los grupos activos de la carga sin docente o con alguna sesión presencial sin salón (si el campus usa salones), con
     * su justificación, quién y cuándo, por clave de materia y de grupo: la lista de «Justificaciones». Con `$groupIds`,
     * solo esos (el drawer de un grupo).
     *
     * @param  list<string>|null  $groupIds
     * @return list<array{id: string, code: string, subject_code: string, subject_name: string, career_code: string, semester: int, schedule: string, lacks_teacher: bool, roomless_sessions: int, justification: ?string, justified_by: ?string, justified_at: ?CarbonImmutable}>
     */
    public function items(AcademicLoad $load, ?array $groupIds = null): array
    {
        $load->loadMissing(['campus', 'department', 'term']);
        $profile = $load->campus->profile;
        $roomless = GroupSession::query()->toBase()
            ->selectRaw('count(*)')
            ->whereColumn('group_sessions.group_id', 'groups.id')
            ->where('group_sessions.is_active', true)
            ->whereNull('group_sessions.room_id')
            ->where('group_sessions.kind', '<>', SessionKind::Virtual->value);

        $rows = $this->groupsOf($load)
            ->join('subjects', 'subjects.id', '=', 'groups.subject_id')
            ->join('careers', 'careers.id', '=', 'groups.owner_career_id')
            ->leftJoin('users as justifiers', 'justifiers.id', '=', 'groups.close_justified_by')
            ->select(['groups.id', 'groups.code', 'groups.semester', 'groups.teacher_id', 'groups.close_justification', 'groups.close_justified_at'])
            ->addSelect(['subjects.code as subject_code', 'subjects.name as subject_name', 'careers.code as career_code', 'justifiers.name as justified_by'])
            ->selectSub($roomless, 'roomless_sessions')
            ->where(fn (Builder $missing) => $missing->whereNull('groups.teacher_id')
                ->when($profile->usesRooms, fn (Builder $query) => $query->orWhereExists(fn (QueryBuilder $sessions) => $sessions->selectRaw('1')->from('group_sessions')
                    ->whereColumn('group_sessions.group_id', 'groups.id')
                    ->where('group_sessions.is_active', true)
                    ->whereNull('group_sessions.room_id')
                    ->where('group_sessions.kind', '<>', SessionKind::Virtual->value))))
            ->when($groupIds !== null, fn (Builder $query) => $query->whereIn('groups.id', $groupIds))
            ->orderBy('subjects.code')->orderBy('groups.code')
            ->toBase()->get();

        $schedules = GroupSession::query()->whereIn('group_id', $rows->pluck('id')->all())->where('is_active', true)
            ->orderBy('day')->orderBy('starts_at')
            ->get(['group_id', 'day', 'starts_at', 'ends_at'])
            ->groupBy('group_id');

        return $rows->map(fn (object $row): array => [
            'id' => $row->id,
            'code' => $row->code,
            'subject_code' => $row->subject_code,
            'subject_name' => $row->subject_name,
            'career_code' => $row->career_code,
            'semester' => (int) $row->semester,
            'schedule' => ScheduleSummary::of(($schedules[$row->id] ?? collect())->map(fn (GroupSession $session): array => [
                'day' => $session->day, 'starts_at' => $session->starts_at, 'ends_at' => $session->ends_at,
            ]))->label(),
            'lacks_teacher' => $row->teacher_id === null,
            'roomless_sessions' => $profile->usesRooms ? (int) $row->roomless_sessions : 0,
            'justification' => $row->close_justification === null || trim($row->close_justification) === '' ? null : $row->close_justification,
            'justified_by' => $row->justified_by,
            'justified_at' => $row->close_justified_at === null ? null : CarbonImmutable::parse($row->close_justified_at)->setTimezone(config('app.timezone')),
        ])->values()->all();
    }

    /**
     * El detalle de la carga. `$blockers` es lo que la pantalla ya calculó con blockers(); sin él, lo calcula.
     */
    public function of(AcademicLoad $load, ?ClosingBlockers $blockers = null): LoadClosing
    {
        $items = $this->items($load);
        $requests = ChangeRequest::query()
            ->where('term_id', $load->term_id)->where('campus_id', $load->campus_id)->where('department_id', $load->department_id)
            ->whereIn('status', [ChangeRequestStatus::Pending, ChangeRequestStatus::Approved])
            ->selectRaw('status, count(*) as total')->groupBy('status')
            ->pluck('total', 'status');

        $pending = $this->agreements->pending($load);

        return new LoadClosing(
            load: $load,
            activeGroups: $this->groupsOf($load)->count(),
            blockers: $blockers ?? $this->blockers($load),
            roomlessSessions: (int) collect($items)->whereNull('justification')->sum('roomless_sessions'),
            inactiveTeachers: $this->groupsOf($load)
                ->join('teachers', 'teachers.id', '=', 'groups.teacher_id')
                ->where(fn (Builder $inactive) => $inactive->where('teachers.is_active', false)->orWhereNotNull('teachers.deleted_at'))
                ->distinct()->orderBy('teachers.name')->pluck('teachers.name')->all(),
            pendingRequests: (int) ($requests[ChangeRequestStatus::Pending->value] ?? 0),
            approvedRequests: (int) ($requests[ChangeRequestStatus::Approved->value] ?? 0),
            sharedWaitingOn: $pending['proposed']->map(fn (InterdepartmentalAgreement $agreement): string => $agreement->toDepartment->name ?? $agreement->to_department_id)->values()->all(),
            awaiting: [
                'room_loan' => $pending['awaiting']->where('kind', AgreementKind::RoomLoan)->count(),
                'shared_group' => $pending['awaiting']->where('kind', AgreementKind::SharedGroup)->count(),
            ],
            items: $items,
            history: $this->history($load),
        );
    }

    /**
     * @return list<array{from: LoadStatus, to: LoadStatus, by: string, at: CarbonImmutable, comment: ?string}>
     */
    private function history(AcademicLoad $load): array
    {
        return LoadTransition::query()
            ->where('academic_load_id', $load->getKey())
            ->with('user:id,name')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get()
            ->map(fn (LoadTransition $transition): array => [
                'from' => $transition->from_status,
                'to' => $transition->to_status,
                'by' => $transition->user->name ?? 'Un usuario ya dado de baja',
                'at' => CarbonImmutable::parse($transition->created_at)->setTimezone(config('app.timezone')),
                'comment' => $transition->comment,
            ])->values()->all();
    }

    /**
     * Los grupos activos (no cancelados) de la carga.
     *
     * @return Builder<Group>
     */
    private function groupsOf(AcademicLoad $load): Builder
    {
        return Group::query()
            ->where('groups.term_id', $load->term_id)
            ->where('groups.campus_id', $load->campus_id)
            ->where('groups.department_id', $load->department_id)
            ->where('groups.status', '<>', GroupStatus::Cancelled->value);
    }

    private function activeGroupsOf(QueryBuilder $groups): void
    {
        $groups->selectRaw('1')->from('groups')
            ->whereColumn('groups.term_id', 'academic_loads.term_id')
            ->whereColumn('groups.campus_id', 'academic_loads.campus_id')
            ->whereColumn('groups.department_id', 'academic_loads.department_id')
            ->where('groups.status', '<>', GroupStatus::Cancelled->value);
    }

    private function activeGroupsCount(): QueryBuilder
    {
        return Group::query()->toBase()->selectRaw('count(*)')
            ->whereColumn('groups.term_id', 'academic_loads.term_id')
            ->whereColumn('groups.campus_id', 'academic_loads.campus_id')
            ->whereColumn('groups.department_id', 'academic_loads.department_id')
            ->where('groups.status', '<>', GroupStatus::Cancelled->value);
    }
}
