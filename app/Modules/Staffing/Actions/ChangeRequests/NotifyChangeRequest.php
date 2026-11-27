<?php

namespace App\Modules\Staffing\Actions\ChangeRequests;

use App\Modules\Core\Actions\NotifyHolders;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Queries\RecipientsQuery;
use App\Modules\Core\Rules\PasswordResetRules;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Enums\ChangeRequestKind;
use App\Modules\Staffing\Enums\StaffingPermission;
use App\Modules\Staffing\Models\ChangeRequest;
use App\Modules\Staffing\Notifications\ChangeAlternativeMovesCareerGroup;
use App\Modules\Staffing\Notifications\ChangeRequested;
use App\Modules\Staffing\Notifications\ChangeRequestMovedTeacherGroups;
use App\Modules\Staffing\Notifications\ChangeRequestNotification;
use App\Modules\Staffing\Notifications\ChangeRequestPendingApplication;
use App\Modules\Staffing\Support\ChangeAlternatives;
use Illuminate\Support\Facades\Notification;

/**
 * Los avisos del flujo de una solicitud de cambio (tarea 3.11): a los jefes al pedirla, al solicitante al decidirla
 * o aplicarla, y a quien debe aplicarla al aprobarla (el coordinador de la carrera dueña si es de horario; el jefe
 * del departamento si es de salón). Las Actions lo llaman después del commit.
 *
 * Con una alternativa del motor (tarea 8.7, decidido por el usuario el 18 de noviembre de 2026): al elegirla, a la
 * coordinación de cada carrera (distinta de la dueña del grupo de la solicitud) con un grupo que la cadena mueve; al
 * aplicarla, a cada docente cuyos grupos cambiaron de horario.
 */
final class NotifyChangeRequest
{
    public function __construct(private readonly RecipientsQuery $recipients, private readonly NotifyHolders $notifyHolders, private readonly PasswordResetRules $emails) {}

    public function requested(ChangeRequest $request): void
    {
        $heads = $this->recipients->holders(StaffingPermission::ChangeRequestDecide, Target::of(modality: $request->modality_id, unit: $request->department_id));

        $this->notifyHolders->handle($heads, new ChangeRequested($request));
    }

    /**
     * Al solicitante. Sin puesto (`data.role`): pidió como docente o como coordinador y el aviso no cambia de perfil.
     *
     * @param  class-string<ChangeRequestNotification>  $notification
     */
    public function requester(ChangeRequest $request, string $notification): void
    {
        $request->loadMissing('requestedBy');

        $request->requestedBy->notify(new $notification($request));
    }

    public function appliers(ChangeRequest $request): void
    {
        $request->loadMissing('group');

        $holders = $request->kind === ChangeRequestKind::Schedule
            ? $this->recipients->holders(StaffingPermission::ChangeRequestApplySchedule, Target::of(modality: $request->modality_id, campus: $request->group->campus_id, career: $request->group->owner_career_id))
            : $this->recipients->holders(StaffingPermission::ChangeRequestApplyRoom, Target::of(modality: $request->modality_id, campus: $request->group->campus_id, unit: $request->department_id));

        $this->notifyHolders->handle($holders, new ChangeRequestPendingApplication($request));
    }

    /**
     * A la coordinación de la carrera dueña de cada grupo que mueve la alternativa elegida, si no es la del grupo de la
     * solicitud (quienes aplican un cambio de horario en esa carrera, en el campus del grupo): qué grupo, de dónde (su
     * horario de hoy) a dónde y por qué solicitud.
     *
     * @param  array<string, mixed>  $item
     */
    public function careerGroupsMoved(ChangeRequest $request, array $item): void
    {
        $requested = Group::query()->with('subject:id,code')->findOrFail($request->group_id);
        $letter = (string) ChangeAlternatives::letter($request->alternatives, $item['id']);
        $entries = collect($item['groups'])->reject(fn (array $entry): bool => $entry['requested'])->keyBy('group_id');
        $groups = Group::query()->with(['subject:id,code,name', 'ownerCareer:id,code', 'campus:id,code', 'sessions.room:id,code'])->whereIn('id', $entries->keys())->orderBy('code')->get();

        foreach ($groups as $group) {
            if ($group->owner_career_id === null || $group->owner_career_id === $requested->owner_career_id) {
                continue;
            }

            $holders = $this->recipients->holders(StaffingPermission::ChangeRequestApplySchedule, Target::of(modality: $group->modality_id, campus: $group->campus_id, career: $group->owner_career_id));

            $this->notifyHolders->handle($holders, new ChangeAlternativeMovesCareerGroup([
                'group_id' => $group->id,
                'label' => ChangeAlternatives::label($group),
                'subject' => $group->subject?->name,
                'career' => $group->ownerCareer?->code,
                'campus' => $group->campus?->code,
                'from' => ChangeAlternatives::line(ChangeAlternatives::live($group)),
                'to' => ChangeAlternatives::line($entries[$group->id]['sessions']),
            ], ChangeAlternatives::label($requested), $letter, $request->term_id, $request->modality_id));
        }
    }

    /**
     * A cada docente cuyos grupos cambiaron de horario al aplicar una alternativa, un aviso con todos los suyos: a su
     * usuario o, si no tiene, a su correo institucional. Un docente inactivo no recibe nada.
     *
     * @param  list<array{teacher_id: ?string, label: string, subject: ?string, from: string, to: string}>  $moved
     */
    public function teachersOfMovedGroups(ChangeRequest $request, array $moved): void
    {
        $byTeacher = collect($moved)->filter(fn (array $group): bool => $group['teacher_id'] !== null)->groupBy('teacher_id');

        if ($byTeacher->isEmpty()) {
            return;
        }

        $requested = ChangeAlternatives::label(Group::query()->with('subject:id,code')->findOrFail($request->group_id));

        foreach (Teacher::query()->with('user')->whereKey($byTeacher->keys())->orderBy('name')->get() as $teacher) {
            if (! $teacher->is_active) {
                continue;
            }

            $notice = new ChangeRequestMovedTeacherGroups($byTeacher[$teacher->id]->map(fn (array $group): array => array_diff_key($group, ['teacher_id' => true]))->values()->all(),
                $requested, $request->term_id, $request->modality_id);

            if ($teacher->user !== null) {
                $teacher->user->notify($notice);
            } elseif ($this->emails->isInstitutionalEmail($teacher->institutional_email)) {
                Notification::route('mail', mb_strtolower((string) $teacher->institutional_email))->notify($notice);
            }
        }
    }
}
