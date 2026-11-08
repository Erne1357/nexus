<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Enums\ChangeRequestKind;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use App\Modules\Staffing\Models\ChangeRequest;
use Carbon\CarbonImmutable;

/**
 * «Mis solicitudes» de Mi carga (tarea 3.11): las solicitudes de cambio del docente en el periodo (las que pidió o las
 * que son de él como docente del grupo), de la más reciente a la más vieja, y, por cada grupo suyo no cancelado, si
 * tiene una abierta y en qué estado está la carga de su departamento en su campus (solo se pide en Asignación o
 * Ajustes; reglas-de-negocio.md, "Solicitudes de cambio"). Un número constante de consultas.
 */
final class MyChangeRequestsQuery
{
    /**
     * @return array{
     *     requests: list<array{id: string, group_id: string, group_code: string, subject_name: string, campus_code: string, kind: ChangeRequestKind, proposal: string, status: ChangeRequestStatus, comment: ?string, requested_at: CarbonImmutable}>,
     *     groups: array<string, array{open: ?ChangeRequestStatus, load: ?LoadStatus}>
     * }
     */
    public function forTeacher(Teacher $teacher, string $userId, Term $term): array
    {
        $requests = ChangeRequest::query()
            ->where('term_id', $term->id)
            ->where(fn ($query) => $query->where('teacher_id', $teacher->id)->orWhere('requested_by', $userId))
            ->with(['group' => fn ($group) => $group->select('id', 'code', 'subject_id', 'campus_id')->with(['subject:id,name', 'campus:id,code'])])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $groups = Group::query()
            ->active()
            ->where('term_id', $term->id)
            ->where('teacher_id', $teacher->id)
            ->get(['id', 'campus_id', 'department_id']);

        $loads = $groups->isEmpty() ? collect() : AcademicLoad::query()
            ->where('term_id', $term->id)
            ->whereIn('campus_id', $groups->pluck('campus_id')->unique()->values())
            ->whereIn('department_id', $groups->pluck('department_id')->unique()->values())
            ->get(['campus_id', 'department_id', 'status'])
            ->keyBy(fn (AcademicLoad $load): string => $load->campus_id.'|'.$load->department_id);

        $open = $requests
            ->filter(fn (ChangeRequest $request): bool => $request->status->isOpen() && $request->teacher_id === $teacher->id)
            ->mapWithKeys(fn (ChangeRequest $request): array => [$request->group_id => $request->status]);

        return [
            'requests' => $requests->map(fn (ChangeRequest $request): array => [
                'id' => $request->id,
                'group_id' => $request->group_id,
                'group_code' => $request->group->code,
                'subject_name' => $request->group->subject->name,
                'campus_code' => $request->group->campus->code,
                'kind' => $request->kind,
                'proposal' => (string) ($request->proposed['description'] ?? ''),
                'status' => $request->status,
                'comment' => $request->decision_comment,
                'requested_at' => $request->created_at,
            ])->values()->all(),
            'groups' => $groups->mapWithKeys(fn (Group $group): array => [$group->id => [
                'open' => $open->get($group->id),
                'load' => $loads->get($group->campus_id.'|'.$group->department_id)?->status,
            ]])->all(),
        ];
    }

    /**
     * Un grupo no cancelado del docente en el periodo por un id que llega del cliente, con su docente (lo pide la
     * policy); el de otro docente o de otro periodo no existe.
     */
    public function groupOf(Teacher $teacher, Term $term, string $groupId): ?Group
    {
        $group = KeyLookup::find(Group::query()->active()->where('term_id', $term->id)->where('teacher_id', $teacher->id), $groupId);

        return $group?->setRelation('teacher', $teacher);
    }
}
