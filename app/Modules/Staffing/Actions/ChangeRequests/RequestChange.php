<?php

namespace App\Modules\Staffing\Actions\ChangeRequests;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Enums\ChangeRequestKind;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use App\Modules\Staffing\Models\ChangeRequest;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pide un cambio de horario o de salón de un grupo (reglas-de-negocio.md, "Solicitudes de cambio"; tarea 3.11).
 * Lo piden el docente asignado al grupo o el coordinador de su carrera; quién puede lo decide
 * ChangeRequestPolicy::create antes de llamarla.
 *
 * En una transacción exige que la carga del departamento del grupo esté en asignación o ajustes y que el grupo no esté
 * cancelado; si pide el docente del grupo, que no tenga ya una solicitud abierta en él (confirmado el 7 de noviembre
 * de 2026; la base lo garantiza con un índice único parcial). Guarda la solicitud pendiente con una foto de las
 * sesiones (`current`), deja bitácora y, después del commit, avisa a los jefes del departamento.
 */
final class RequestChange
{
    public function __construct(private readonly NotifyChangeRequest $notify) {}

    /**
     * @throws ValidationException
     */
    public function handle(Group $group, User $actor, ChangeRequestKind $kind, string $description, string $reason): ChangeRequest
    {
        $description = trim($description);
        $reason = trim($reason);

        if ($description === '') {
            throw ValidationException::withMessages(['description' => 'Describe qué cambio pides.']);
        }

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Escribe el motivo del cambio.']);
        }

        try {
            $request = DB::transaction(fn (): ChangeRequest => $this->create($group, $actor, $kind, $description, $reason));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['group' => 'Ya tienes una solicitud abierta para este grupo.']);
        }

        $this->notify->requested($request);

        return $request;
    }

    private function create(Group $group, User $actor, ChangeRequestKind $kind, string $description, string $reason): ChangeRequest
    {
        $group = Group::query()->with(['teacher', 'sessions.room'])->lockForUpdate()->findOrFail($group->getKey());

        if ($group->status === GroupStatus::Cancelled) {
            throw ValidationException::withMessages(['group' => 'El grupo está cancelado: no admite cambios.']);
        }

        $load = AcademicLoad::query()
            ->ofDepartment($group->term_id, $group->modality_id, $group->department_id)
            ->first();

        if ($load === null || ! in_array($load->status, [LoadStatus::Staffing, LoadStatus::Adjusting], true)) {
            $state = $load === null ? 'sin abrir' : $load->status->label();

            throw ValidationException::withMessages(['group' => "La carga del departamento está en {$state}: solo se piden cambios en Asignación o Ajustes."]);
        }

        $teacherId = $group->teacher?->user_id === $actor->getKey() ? $group->teacher_id : null;

        if ($teacherId !== null && ChangeRequest::query()->open()->where('group_id', $group->getKey())->where('teacher_id', $teacherId)->exists()) {
            throw ValidationException::withMessages(['group' => 'Ya tienes una solicitud abierta para este grupo.']);
        }

        $request = ChangeRequest::query()->create([
            'term_id' => $group->term_id,
            'modality_id' => $group->modality_id,
            'department_id' => $group->department_id,
            'group_id' => $group->getKey(),
            'requested_by' => $actor->getKey(),
            'teacher_id' => $teacherId,
            'kind' => $kind,
            'current' => $group->sessions
                ->map(fn ($session): array => [
                    'day' => $session->day,
                    'starts_at' => substr((string) $session->starts_at, 0, 5),
                    'ends_at' => substr((string) $session->ends_at, 0, 5),
                    'room' => $session->room?->code,
                ])
                ->values()
                ->all(),
            'proposed' => ['description' => $description],
            'reason' => $reason,
            'status' => ChangeRequestStatus::Pending,
        ]);

        activity()
            ->performedOn($request)
            ->causedBy($actor)
            ->event('requested')
            ->withProperties(['kind' => $kind->value, 'group_id' => $group->getKey()])
            ->log("Pidió un cambio de {$kind->label()} del grupo {$group->code}");

        return $request;
    }
}
