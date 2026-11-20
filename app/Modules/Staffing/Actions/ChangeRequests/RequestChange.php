<?php

namespace App\Modules\Staffing\Actions\ChangeRequests;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Authorization\AcademicLoadVisibility;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\ModalityProfile;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Rules\LoadEditability;
use App\Modules\Scheduling\Validation\Minutes;
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
 *
 * MN-05 (confirmado el 20 de noviembre de 2026): con la carga académica oculta, «Mi carga» queda en solo lectura y la
 * solicitud se rechaza con 403, también si se llama a mano; quien tiene `core.academic_load.view_hidden` no se afecta.
 */
final class RequestChange
{
    public function __construct(private readonly NotifyChangeRequest $notify, private readonly AssessChangeImpact $assess, private readonly LoadEditability $editability) {}

    /**
     * `$structured` es lo que se propone en forma que el validador entiende (opcional, CR-01): `sessions` (día 1 a 7,
     * `starts_at` y `ends_at` en H:i) para un cambio de horario, o `room` (la clave del salón del campus del grupo)
     * para uno de salón. Sin eso la solicitud solo lleva la descripción y su impacto no se puede simular.
     *
     * @param  array{sessions?: list<array{day: int|string, starts_at: string, ends_at: string}>, room?: string}  $structured
     *
     * @throws ValidationException
     */
    public function handle(Group $group, User $actor, ChangeRequestKind $kind, string $description, string $reason, array $structured = []): ChangeRequest
    {
        abort_if(app(AcademicLoadVisibility::class)->hiddenFrom(app(Actor::class)), 403, 'Las solicitudes de cambio no están disponibles por ahora.');

        $description = trim($description);
        $reason = trim($reason);

        if ($description === '') {
            throw ValidationException::withMessages(['description' => 'Describe qué cambio pides.']);
        }

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Escribe el motivo del cambio.']);
        }

        try {
            $request = $this->editability->guard(fn (): ChangeRequest => DB::transaction(fn (): ChangeRequest => $this->create($group, $actor, $kind, $description, $reason, $structured)), 'group', 'Otra persona está cambiando este grupo; intenta de nuevo.');
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['group' => 'Ya tienes una solicitud abierta para este grupo.']);
        }

        $this->assessAfterCommit($request);
        $this->notify->requested($request);

        return $request;
    }

    /**
     * El impacto nace con la solicitud (CR-01), fuera de su transacción: si el cálculo falla, la solicitud ya existe y
     * el jefe lo recalcula al abrirla.
     */
    private function assessAfterCommit(ChangeRequest $request): void
    {
        try {
            $this->assess->handle($request);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  array<string, mixed>  $structured
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function proposal(Group $group, ChangeRequestKind $kind, string $description, array $structured): array
    {
        $proposed = ['description' => $description];

        if ($kind === ChangeRequestKind::Room) {
            $code = trim((string) ($structured['room'] ?? ''));

            if ($code !== '') {
                $room = Room::query()->where('campus_id', $group->campus_id)->where('is_active', true)->whereRaw('lower(code) = ?', [mb_strtolower($code)])->first();

                if ($room === null) {
                    throw ValidationException::withMessages(['proposedRoom' => 'Ese salón no existe en el campus del grupo.']);
                }

                $proposed += ['room' => $room->code, 'room_id' => $room->id];
            }

            return $proposed;
        }

        $profile = Modality::query()->find($group->modality_id)?->valid_profile ?? ModalityProfile::fromArray([]);
        $sessions = [];

        foreach (array_values($structured['sessions'] ?? []) as $session) {
            $day = (int) ($session['day'] ?? 0);
            $start = (string) ($session['starts_at'] ?? '');
            $end = (string) ($session['ends_at'] ?? '');

            if ($day < 1 || $day > 7 || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $end) || $end <= $start) {
                throw ValidationException::withMessages(['proposedSessions' => 'Cada sesión propuesta lleva día y una hora de inicio anterior a la de fin.']);
            }

            if ($profile->usesTimeBlocks) {
                $dayStart = Minutes::of($profile->dayStart);

                if (! in_array($day, $profile->days, true)
                    || Minutes::of($start) < $dayStart
                    || Minutes::of($end) > Minutes::of($profile->dayEnd)
                    || (Minutes::of($start) - $dayStart) % $profile->blockMinutes !== 0
                    || (Minutes::of($end) - $dayStart) % $profile->blockMinutes !== 0) {
                    throw ValidationException::withMessages(['proposedSessions' => "Las sesiones propuestas deben caer en los días y bloques de la modalidad ({$profile->schedule()})."]);
                }
            }

            foreach ($sessions as $before) {
                if ($before['day'] === $day && $before['starts_at'] < $end && $start < $before['ends_at']) {
                    throw ValidationException::withMessages(['proposedSessions' => 'Las sesiones propuestas no pueden empalmarse entre sí.']);
                }
            }

            $sessions[] = ['day' => $day, 'starts_at' => $start, 'ends_at' => $end];
        }

        return $sessions === [] ? $proposed : $proposed + ['sessions' => $sessions];
    }

    /**
     * @param  array<string, mixed>  $structured
     */
    private function create(Group $group, User $actor, ChangeRequestKind $kind, string $description, string $reason, array $structured): ChangeRequest
    {
        $ids = Group::query()->findOrFail($group->getKey(), ['id', 'term_id', 'modality_id', 'department_id']);
        Modality::withTrashed()->whereKey($ids->modality_id)->sharedLock()->firstOrFail();
        $this->editability->lockLoad($ids);
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
                ->sortBy([['day', 'asc'], ['starts_at', 'asc']])
                ->map(fn ($session): array => [
                    'day' => $session->day,
                    'starts_at' => substr((string) $session->starts_at, 0, 5),
                    'ends_at' => substr((string) $session->ends_at, 0, 5),
                    'room' => $session->room?->code,
                ])
                ->values()
                ->all(),
            'proposed' => $this->proposal($group, $kind, $description, $structured),
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
