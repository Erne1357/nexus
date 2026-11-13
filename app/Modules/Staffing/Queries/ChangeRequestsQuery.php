<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
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
 * Lo que lee la pantalla «Solicitudes de cambio» (tarea 3.11, maqueta A): las solicitudes de un departamento en el
 * periodo y el campus de la credencial que la persona puede ver, en el orden de la lista (pendientes, luego aprobadas
 * por aplicar, luego el resto; las más recientes primero), los departamentos que puede elegir y la carga del
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
     * Los departamentos que puede elegir en el campus y el periodo, por clave: los activos sobre los que tiene ver,
     * decidir o aplicar las de salón, más los de alguna solicitud que ve (el coordinador y quien pidió).
     *
     * @return Collection<int, Department>
     */
    public function departmentsFor(Actor $context, Campus $campus, Term $term): Collection
    {
        $withRequests = $this->visible($context, $this->inCampus($term->id, $campus->id))
            ->distinct()
            ->pluck('department_id');

        return Department::query()
            ->withTrashed()
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $current) => $current->whereNull('deleted_at')->where('is_active', true))
                ->orWhereIn('id', $withRequests->all()))
            ->orderBy('code')
            ->get()
            ->filter(fn (Department $department): bool => $withRequests->contains($department->id) || $this->overseesUnit($context, $campus->id, $department->id))
            ->values();
    }

    /**
     * El departamento de una solicitud que ve en el periodo y el campus, por un id que llega del cliente (`?solicitud=`).
     */
    public function departmentOf(Actor $context, string $id, string $termId, string $campusId): ?string
    {
        $request = KeyLookup::find($this->visible($context, $this->inCampus($termId, $campusId)), $id);

        return $request?->department_id;
    }

    /**
     * Las solicitudes que ve del departamento, en el orden de la lista, con lo que pintan la lista y el detalle.
     *
     * @return Collection<int, ChangeRequest>
     */
    public function listFor(Actor $context, string $termId, string $campusId, string $departmentId): Collection
    {
        return $this->visible($context, $this->ofDepartment($termId, $campusId, $departmentId))
            ->with([
                'group' => fn ($group) => $group->select('id', 'code', 'subject_id', 'owner_career_id')->with([
                    'subject:id,code,name',
                    'ownerCareer' => fn ($career) => $career->withTrashed()->select('id', 'code', 'name'),
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
    public function find(Actor $context, string $id, string $termId, string $campusId, string $departmentId): ?ChangeRequest
    {
        return KeyLookup::find($this->visible($context, $this->ofDepartment($termId, $campusId, $departmentId))->with('group:id,code,owner_career_id'), $id);
    }

    /**
     * La carga del departamento en la modalidad del campus de la pantalla y el periodo (M1; el campus lleva a su
     * modalidad por el puente `campuses.modality_id` mientras la pantalla sea por campus), y cuántas solicitudes
     * aprobadas siguen sin aplicar en ella, de todos sus campus (todas, las vea o no: son las que la retienen en Ajustes).
     * Un campus sin modalidad en el puente no tiene carga.
     *
     * @return array{load: ?AcademicLoad, approved: int}
     */
    public function loadOf(string $termId, string $campusId, string $departmentId): array
    {
        $modalityId = Campus::withTrashed()->whereKey($campusId)->value('modality_id');

        if ($modalityId === null) {
            return ['load' => null, 'approved' => 0];
        }

        return [
            'load' => AcademicLoad::query()->ofDepartment($termId, $modalityId, $departmentId)->first(),
            'approved' => ChangeRequest::query()->where('term_id', $termId)->where('modality_id', $modalityId)->where('department_id', $departmentId)
                ->where('status', ChangeRequestStatus::Approved)->count(),
        ];
    }

    /**
     * Los grupos en que puede pedir un cambio como coordinador (confirmado el 7 de noviembre de 2026): los no cancelados
     * de las carreras dueñas que cubre su permiso de pedir, en el periodo y el campus, de cualquier departamento cuya
     * carga esté en Asignación o Ajustes; por semestre y clave, con su materia, su departamento y sus sesiones.
     *
     * @return Collection<int, Group>
     */
    public function requestableGroups(Actor $context, string $termId, string $campusId): Collection
    {
        return $this->requestable($context, $termId, $campusId)
            ->with(['subject:id,code,name', 'department:id,code', 'sessions'])
            ->orderBy('groups.semester')
            ->orderBy('groups.code')
            ->get();
    }

    public function hasRequestableGroups(Actor $context, string $termId, string $campusId): bool
    {
        return $this->requestable($context, $termId, $campusId)->exists();
    }

    /**
     * Un grupo en que puede pedir, por un id que llega del cliente, con su docente (lo pide la policy).
     */
    public function requestableGroup(Actor $context, string $termId, string $campusId, string $id): ?Group
    {
        return KeyLookup::find($this->requestable($context, $termId, $campusId)->with('teacher'), $id);
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
    private function requestable(Actor $context, string $termId, string $campusId): Builder
    {
        $groups = Group::query()
            ->active()
            ->where('groups.term_id', $termId)
            ->where('groups.campus_id', $campusId)
            ->whereExists(fn (QueryBuilder $load) => $load->select(DB::raw(1))->from('academic_loads')
                ->whereColumn('academic_loads.term_id', 'groups.term_id')
                ->whereColumn('academic_loads.modality_id', 'groups.modality_id')
                ->whereColumn('academic_loads.department_id', 'groups.department_id')
                ->whereIn('academic_loads.status', [LoadStatus::Staffing->value, LoadStatus::Adjusting->value]));

        return $context->constrain($groups, StaffingPermission::ChangeRequestRequest, ['modality' => 'groups.modality_id', 'campus' => 'groups.campus_id', 'career' => 'groups.owner_career_id']);
    }

    /**
     * Las solicitudes del periodo cuyo grupo está hoy en el campus.
     *
     * @return Builder<ChangeRequest>
     */
    private function inCampus(string $termId, string $campusId): Builder
    {
        return ChangeRequest::query()->where('term_id', $termId)->whereIn('group_id', Group::query()->select('id')->where('campus_id', $campusId));
    }

    /**
     * @return Builder<ChangeRequest>
     */
    private function ofDepartment(string $termId, string $campusId, string $departmentId): Builder
    {
        return $this->inCampus($termId, $campusId)->where('department_id', $departmentId);
    }

    /**
     * Si ve las solicitudes de todo el departamento en la modalidad del campus: ver, decidir o aplicar las de salón (esta
     * última, con el campus del nombramiento si lo tiene).
     */
    private function overseesUnit(Actor $context, string $campusId, string $departmentId): bool
    {
        $modalityId = Campus::withTrashed()->whereKey($campusId)->value('modality_id');
        $target = Target::of(modality: $modalityId, unit: $departmentId);

        return $context->allows(StaffingPermission::ChangeRequestView, $target)
            || $context->allows(StaffingPermission::ChangeRequestDecide, $target)
            || $context->allows(StaffingPermission::ChangeRequestApplyRoom, Target::of(modality: $modalityId, campus: $campusId, unit: $departmentId));
    }
}
