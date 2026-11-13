<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Enums\ChangeRequestKind;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use App\Modules\Staffing\Enums\StaffingPermission;
use App\Modules\Staffing\Models\ChangeRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Lo que lee la pantalla «Solicitudes de cambio» (tarea 3.11, maqueta A; el alcance, maqueta U2, opción A): las
 * solicitudes de un departamento en el periodo y la modalidad de la página (M1), de todos sus campus o de uno (el filtro,
 * `$campusId`), que la persona puede ver, en el orden de la lista (pendientes, luego aprobadas por aplicar, luego el
 * resto; las más recientes primero), cuántas hay en cada campus, los departamentos que puede elegir y la carga del
 * departamento. El campus de una solicitud es el de hoy de su grupo: la solicitud no lo copia (M8), así que la de un
 * grupo que cambió de campus se lista y se aplica donde ahora está.
 *
 * Quién ve una solicitud es ChangeRequestPolicy::view en SQL (visible()): quien la pidió; quien tiene ver, decidir o
 * aplicar las de salón sobre la modalidad y el departamento del grupo (jefes, jefe de proyecto docente, Subdirección;
 * el campus del nombramiento no limita la lectura), y el coordinador de la carrera dueña del grupo en su modalidad (y
 * en su campus, si el nombramiento lo tiene; AZ-S17, MS6), solo las de horario (las aplica él). También da los grupos
 * en que el coordinador puede pedir una nueva. Un número constante de consultas.
 */
final class ChangeRequestsQuery
{
    /**
     * Los departamentos que puede elegir en el periodo y la modalidad, por clave: los activos sobre los que tiene ver,
     * decidir o aplicar las de salón (esta, en alguno de `$campusIds`, los campus de la modalidad), más los de alguna
     * solicitud que ve en el filtro (el coordinador y quien pidió).
     *
     * @param  list<string>  $campusIds
     * @return Collection<int, Department>
     */
    public function departmentsFor(Actor $context, string $termId, string $modalityId, ?string $campusId, array $campusIds): Collection
    {
        $withRequests = $this->visible($context, $this->scoped($termId, $modalityId, $campusId))
            ->distinct()
            ->pluck('department_id');

        return Department::query()
            ->withTrashed()
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $current) => $current->whereNull('deleted_at')->where('is_active', true))
                ->orWhereIn('id', $withRequests->all()))
            ->orderBy('code')
            ->get()
            ->filter(fn (Department $department): bool => $withRequests->contains($department->id) || $this->overseesUnit($context, $modalityId, $campusIds, $department->id))
            ->values();
    }

    /**
     * El departamento de una solicitud que ve en el periodo, la modalidad y el campus (null, todos), por un id que llega
     * del cliente (`?solicitud=`).
     */
    public function departmentOf(Actor $context, string $id, string $termId, string $modalityId, ?string $campusId = null): ?string
    {
        $request = KeyLookup::find($this->visible($context, $this->scoped($termId, $modalityId, $campusId)), $id);

        return $request?->department_id;
    }

    /**
     * La modalidad de una solicitud del periodo, por un id que llega del cliente (`?solicitud=` sin `?modalidad=`); null
     * si no existe. Si la ve lo deciden la página y visible().
     */
    public function modalityIdOf(string $id, string $termId): ?string
    {
        return KeyLookup::find(ChangeRequest::query()->where('term_id', $termId), $id)?->modality_id;
    }

    /**
     * Cuántas solicitudes que ve del departamento hay en cada campus (el de hoy de su grupo), por clave del campus: las
     * cifras del filtro de campus.
     *
     * @return array<string, int>
     */
    public function campusCounts(Actor $context, string $termId, string $modalityId, string $departmentId): array
    {
        $visible = $this->visible($context, $this->ofDepartment($termId, $modalityId, null, $departmentId))->select('change_requests.id');

        return DB::table('change_requests')
            ->join('groups', 'groups.id', '=', 'change_requests.group_id')
            ->join('campuses', 'campuses.id', '=', 'groups.campus_id')
            ->whereIn('change_requests.id', $visible)
            ->groupBy('campuses.code')
            ->selectRaw('campuses.code, count(*) as total')
            ->pluck('total', 'code')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();
    }

    /**
     * Las solicitudes que ve del departamento en el periodo, la modalidad y el campus (null, todos), en el orden de la
     * lista, con lo que pintan la lista y el detalle (el campus de hoy de su grupo, M8).
     *
     * @return Collection<int, ChangeRequest>
     */
    public function listFor(Actor $context, string $termId, string $modalityId, ?string $campusId, string $departmentId): Collection
    {
        return $this->visible($context, $this->ofDepartment($termId, $modalityId, $campusId, $departmentId))
            ->with([
                'group' => fn ($group) => $group->select('id', 'code', 'subject_id', 'owner_career_id', 'campus_id')->with([
                    'subject:id,code,name',
                    'ownerCareer' => fn ($career) => $career->withTrashed()->select('id', 'code', 'name'),
                    'campus' => fn ($campus) => $campus->withTrashed()->select('id', 'code', 'name'),
                ]),
                'teacher:id,name',
                'requestedBy:id,name',
                'decidedBy:id,name',
                'appliedBy:id,name',
            ])
            ->orderByRaw("case status when 'pending' then 0 when 'approved' then 1 else 2 end")
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Una solicitud que ve del departamento, por un id que llega del cliente, con su grupo (lo pide la policy).
     */
    public function find(Actor $context, string $id, string $termId, string $modalityId, ?string $campusId, string $departmentId): ?ChangeRequest
    {
        return KeyLookup::find($this->visible($context, $this->ofDepartment($termId, $modalityId, $campusId, $departmentId))->with('group:id,code,owner_career_id,campus_id'), $id);
    }

    /**
     * La carga del departamento en la modalidad y el periodo (M1), y cuántas solicitudes aprobadas siguen sin aplicar en
     * ella, de todos sus campus (todas, las vea o no: son las que la retienen en Ajustes).
     *
     * @return array{load: ?AcademicLoad, approved: int}
     */
    public function loadOf(string $termId, string $modalityId, string $departmentId): array
    {
        return [
            'load' => AcademicLoad::query()->ofDepartment($termId, $modalityId, $departmentId)->first(),
            'approved' => ChangeRequest::query()->where('term_id', $termId)->where('modality_id', $modalityId)->where('department_id', $departmentId)
                ->where('status', ChangeRequestStatus::Approved)->count(),
        ];
    }

    /**
     * Los grupos en que puede pedir un cambio como coordinador (confirmado el 7 de noviembre de 2026): los no cancelados
     * de las carreras dueñas que cubre su permiso de pedir (con el campus del nombramiento, si lo tiene, MS6), en el
     * periodo, la modalidad y el campus del filtro (null, todos), de cualquier departamento cuya carga esté en Asignación o
     * Ajustes; por semestre y clave, con su materia, su departamento, su campus y sus sesiones.
     *
     * @return Collection<int, Group>
     */
    public function requestableGroups(Actor $context, string $termId, string $modalityId, ?string $campusId): Collection
    {
        return $this->requestable($context, $termId, $modalityId, $campusId)
            ->with(['subject:id,code,name', 'department:id,code', 'campus:id,code,name', 'sessions'])
            ->orderBy('groups.semester')
            ->orderBy('groups.code')
            ->get();
    }

    public function hasRequestableGroups(Actor $context, string $termId, string $modalityId, ?string $campusId): bool
    {
        return $this->requestable($context, $termId, $modalityId, $campusId)->exists();
    }

    /**
     * Un grupo en que puede pedir, por un id que llega del cliente, con su docente (lo pide la policy).
     */
    public function requestableGroup(Actor $context, string $termId, string $modalityId, ?string $campusId, string $id): ?Group
    {
        return KeyLookup::find($this->requestable($context, $termId, $modalityId, $campusId)->with('teacher'), $id);
    }

    /**
     * Deja en `$query` solo las solicitudes que ve (ChangeRequestPolicy::view). Cada permiso va en su propio grupo,
     * que arranca con `true` para que el admin, con quien constrain() no agrega nada, lo vea todo. Los permisos de
     * aplicar declaran el campus, que es el del grupo (MS6): se acotan los grupos y la solicitud entra si su grupo es de
     * los que quedan.
     *
     * @param  Builder<ChangeRequest>  $query
     * @return Builder<ChangeRequest>
     */
    public function visible(Actor $context, Builder $query): Builder
    {
        $userId = $context->user()?->getKey();
        $unit = ['modality' => 'modality_id', 'unit' => 'department_id'];
        $groups = fn (StaffingPermission $permission, array $columns): Builder => $context->constrain(Group::query()->select('groups.id'), $permission, $columns);

        if ($userId === null) {
            return $query->whereRaw('false');
        }

        return $query->where(fn (Builder $any) => $any
            ->where('requested_by', $userId)
            ->orWhere(fn (Builder $clause) => $context->constrain($clause->whereRaw('true'), StaffingPermission::ChangeRequestView, $unit))
            ->orWhere(fn (Builder $clause) => $context->constrain($clause->whereRaw('true'), StaffingPermission::ChangeRequestDecide, $unit))
            ->orWhere(fn (Builder $clause) => $clause->where('kind', ChangeRequestKind::Room)->whereIn('group_id', $groups(StaffingPermission::ChangeRequestApplyRoom, [
                'modality' => 'groups.modality_id', 'campus' => 'groups.campus_id', 'unit' => 'groups.department_id',
            ])))
            ->orWhere(fn (Builder $clause) => $clause->where('kind', ChangeRequestKind::Schedule)->whereIn('group_id', $groups(StaffingPermission::ChangeRequestApplySchedule, [
                'modality' => 'groups.modality_id', 'campus' => 'groups.campus_id', 'career' => 'groups.owner_career_id',
            ]))));
    }

    /**
     * @return Builder<Group>
     */
    private function requestable(Actor $context, string $termId, string $modalityId, ?string $campusId): Builder
    {
        $groups = Group::query()
            ->active()
            ->where('groups.term_id', $termId)
            ->where('groups.modality_id', $modalityId)
            ->when($campusId !== null, fn (Builder $query) => $query->where('groups.campus_id', $campusId))
            ->whereExists(fn (QueryBuilder $load) => $load->select(DB::raw(1))->from('academic_loads')
                ->whereColumn('academic_loads.term_id', 'groups.term_id')
                ->whereColumn('academic_loads.modality_id', 'groups.modality_id')
                ->whereColumn('academic_loads.department_id', 'groups.department_id')
                ->whereIn('academic_loads.status', [LoadStatus::Staffing->value, LoadStatus::Adjusting->value]));

        return $context->constrain($groups, StaffingPermission::ChangeRequestRequest, ['modality' => 'groups.modality_id', 'campus' => 'groups.campus_id', 'career' => 'groups.owner_career_id']);
    }

    /**
     * Las solicitudes del periodo y la modalidad (M1) y, con `$campusId`, solo las de grupos que hoy están en ese campus.
     *
     * @return Builder<ChangeRequest>
     */
    private function scoped(string $termId, string $modalityId, ?string $campusId): Builder
    {
        return ChangeRequest::query()
            ->where('change_requests.term_id', $termId)
            ->where('change_requests.modality_id', $modalityId)
            ->when($campusId !== null, fn (Builder $query) => $query->whereIn('change_requests.group_id', Group::query()->select('id')->where('campus_id', $campusId)));
    }

    /**
     * @return Builder<ChangeRequest>
     */
    private function ofDepartment(string $termId, string $modalityId, ?string $campusId, string $departmentId): Builder
    {
        return $this->scoped($termId, $modalityId, $campusId)->where('change_requests.department_id', $departmentId);
    }

    /**
     * Si ve las solicitudes de todo el departamento en la modalidad: ver, decidir o aplicar las de salón (esta última,
     * con el campus del nombramiento si lo tiene: basta uno de los campus de la modalidad).
     *
     * @param  list<string>  $campusIds
     */
    private function overseesUnit(Actor $context, string $modalityId, array $campusIds, string $departmentId): bool
    {
        $target = Target::of(modality: $modalityId, unit: $departmentId);

        return $context->allows(StaffingPermission::ChangeRequestView, $target)
            || $context->allows(StaffingPermission::ChangeRequestDecide, $target)
            || array_any($campusIds === [] ? [null] : $campusIds, fn (?string $campusId): bool => $context->allows(StaffingPermission::ChangeRequestApplyRoom, Target::of(modality: $modalityId, campus: $campusId, unit: $departmentId)));
    }
}
