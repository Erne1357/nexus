<?php

namespace App\Modules\Scheduling\Rules;

use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Campus;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Validation\Clock;
use App\Modules\Scheduling\Validation\Minutes;
use App\Modules\Scheduling\Validation\ValidationResult;
use App\Modules\Scheduling\Validation\Violation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Reglas que comparten las Actions del editor de horarios (tarea 3.5: PlaceGroupSession, UpdateGroupSession y
 * RemoveGroupSession). Cada regla rota es una ValidationException sobre `session`, en español.
 *
 * - Se editan las sesiones de un grupo sin cancelar cuya carga (la de su departamento en su campus y periodo) está en
 *   propuesta, asignación o ajustes (reglas-de-negocio.md, "Aprobaciones y permisos", «Crear grupos y editar
 *   sesiones»).
 * - HC-04: el día es del perfil del campus, las horas caen entre `day_start` y `day_end` y se alinean a
 *   `block_minutes` desde `day_start`; un campus sin bloques de tiempo no tiene ventana, como en el validador.
 * - Una sesión no se encima con otra del mismo grupo.
 * - El salón de una sesión es del campus de su grupo y está activo (tarea 3.7).
 * - HC-01 y HC-02, los empalmes que la base no deja guardar, detienen la escritura con el mensaje del validador; las
 *   demás reglas duras se guardan y quedan marcadas (confirmado el 7 de noviembre de 2026). Si otra sesión gana la
 *   carrera, overlapTaken() traduce las tres exclusiones de `group_sessions`.
 */
final class SessionRules
{
    private const string TEACHER_EXCLUSION = 'group_sessions_teacher_no_overlap';

    private const string ROOM_EXCLUSION = 'group_sessions_room_no_overlap';

    private const string GROUP_EXCLUSION = 'group_sessions_group_no_overlap';

    public function __construct(private readonly LoadEditability $editability) {}

    /**
     * La hora en `HH:MM`; acepta `HH:MM:00`.
     *
     * @throws ValidationException
     */
    public function time(string $time): string
    {
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d(:00)?$/', $time) !== 1) {
            throw self::invalid("La hora «{$time}» no es válida: se escribe como 07:00.");
        }

        return Clock::of($time);
    }

    /**
     * El grupo no está cancelado y la carga de su departamento en su campus y periodo se puede editar.
     *
     * @throws ValidationException
     */
    public function ensureEditable(Group $group): void
    {
        $this->editability->ensure($group, 'horarios', 'session');
    }

    /**
     * HC-04 y que la sesión termine después de empezar. Las horas ya vienen en `HH:MM`.
     *
     * @throws ValidationException
     */
    public function ensureWithinWindow(Group $group, int $day, string $startsAt, string $endsAt): void
    {
        $start = Minutes::of($startsAt);
        $end = Minutes::of($endsAt);

        if ($end <= $start) {
            throw self::invalid('La sesión debe terminar después de empezar.');
        }

        $campus = $this->campusOf($group);
        $profile = $campus->profile;

        if (! in_array($day, $profile->days, true)) {
            throw self::invalid("HC-04: {$campus->name} no da clases el ".Minutes::dayName($day)." ({$profile->schedule()}).");
        }

        if (! $profile->usesTimeBlocks) {
            return;
        }

        $dayStart = Minutes::of($profile->dayStart);

        if ($start < $dayStart || $end > Minutes::of($profile->dayEnd)) {
            throw self::invalid("HC-04: la sesión debe caer dentro del horario de {$campus->name} ({$profile->schedule()}).");
        }

        if (($start - $dayStart) % $profile->blockMinutes !== 0 || ($end - $dayStart) % $profile->blockMinutes !== 0) {
            throw self::invalid("HC-04: la sesión debe empezar y terminar en bloques de {$profile->blockMinutes} minutos desde las ".Minutes::format($dayStart).'.');
        }
    }

    /**
     * La sesión no se encima con otra del mismo grupo en el día.
     *
     * @param  Collection<int, GroupSession>  $sameDay  las del grupo en ese día, sin la que se mueve
     *
     * @throws ValidationException
     */
    public function ensureNoOwnOverlap(Group $group, Collection $sameDay, string $startsAt, string $endsAt): void
    {
        $taken = $sameDay->first(fn (GroupSession $session): bool => Minutes::overlap(
            Minutes::of($startsAt), Minutes::of($endsAt), Minutes::of($session->starts_at), Minutes::of($session->ends_at),
        ));

        if ($taken !== null) {
            throw self::invalid("El grupo {$group->code} ya tiene sesión el ".self::describe($taken).': no se encima otra.');
        }
    }

    /**
     * El salón que se asigna a una sesión es del campus del grupo y está activo (ni inactivo ni borrado).
     *
     * @throws ValidationException
     */
    public function ensureRoomUsable(Group $group, Room $room): void
    {
        if ($room->campus_id !== $group->campus_id) {
            throw self::invalid("El salón {$room->code} es de otro campus.");
        }

        if (! $room->is_active || $room->trashed()) {
            throw self::invalid("El salón {$room->code} está inactivo.");
        }
    }

    /**
     * Las violaciones duras de `$rules` que causa la candidata (`$sessionId`: la que se mueve, o null si es nueva).
     *
     * @param  list<string>  $rules
     * @return list<Violation>
     */
    public function overlapsOf(ValidationResult $result, ?string $sessionId, array $rules): array
    {
        return array_values(array_filter($result->violations(), fn (Violation $violation): bool => $violation->severity === ConflictSeverity::Hard
            && in_array($violation->rule, $rules, true)
            && ($violation->related['session_id'] ?? null) === $sessionId));
    }

    /**
     * HC-01 o HC-02 de la candidata: no se guarda, con el mensaje del validador.
     *
     * @param  list<string>  $rules
     *
     * @throws ValidationException
     */
    public function ensureNoOverlaps(ValidationResult $result, ?string $sessionId, array $rules = ['HC-01', 'HC-02']): void
    {
        $overlaps = $this->overlapsOf($result, $sessionId, $rules);

        if ($overlaps !== []) {
            throw ValidationException::withMessages(['session' => array_map(fn (Violation $violation): string => $violation->message, $overlaps)]);
        }
    }

    /**
     * Si el error es la exclusión de salón de `group_sessions` (otro editor tomó el salón).
     */
    public function isRoomTaken(QueryException $exception): bool
    {
        return str_contains($exception->getMessage(), self::ROOM_EXCLUSION);
    }

    /**
     * El error de una exclusión de `group_sessions` cuando otra sesión gana la carrera al validador; cualquier otro
     * error de la base sigue su camino.
     *
     * @throws QueryException
     */
    public function overlapTaken(QueryException $exception, Group $group, int $day, string $startsAt, string $endsAt, ?string $roomId): ValidationException
    {
        $when = Minutes::dayName($day)." entre {$startsAt} y {$endsAt}";
        $message = $exception->getMessage();

        if (str_contains($message, self::TEACHER_EXCLUSION)) {
            $teacher = Teacher::withTrashed()->whereKey($group->teacher_id)->value('name') ?? 'El docente';

            return self::invalid("HC-01: {$teacher} ya da clase el {$when}.");
        }

        if (str_contains($message, self::ROOM_EXCLUSION)) {
            $room = Room::withTrashed()->whereKey($roomId)->value('code') ?? '';

            return self::invalid("HC-02: el salón {$room} ya está ocupado el {$when}.");
        }

        if (str_contains($message, self::GROUP_EXCLUSION)) {
            return self::invalid("El grupo {$group->code} ya tiene otra sesión el {$when}: no se encima otra.");
        }

        throw $exception;
    }

    /**
     * Lo que la bitácora guarda de una sesión: día, horas en `HH:MM`, tipo y salón.
     *
     * @return array{day: int, starts_at: string, ends_at: string, kind: string, room_id: ?string}
     */
    public static function snapshot(GroupSession $session): array
    {
        return [
            'day' => $session->day,
            'starts_at' => Clock::of($session->starts_at),
            'ends_at' => Clock::of($session->ends_at),
            'kind' => $session->kind->value,
            'room_id' => $session->room_id,
        ];
    }

    /**
     * Cómo se nombra el grupo en la bitácora, como el validador: «Cálculo Diferencial, grupo D» (sin leer la foto).
     */
    public static function label(Group $group): string
    {
        return (Subject::withTrashed()->whereKey($group->subject_id)->value('name') ?? 'La materia').', grupo '.$group->code;
    }

    /**
     * «lunes de 07:00 a 09:00».
     */
    public static function describe(GroupSession $session): string
    {
        return Minutes::dayName($session->day).' de '.Clock::of($session->starts_at).' a '.Clock::of($session->ends_at);
    }

    /**
     * Las sesiones que una unión de contiguas borró, con su id, para la bitácora (`absorbed` en las propiedades).
     *
     * @return list<array{id: string, day: int, starts_at: string, ends_at: string, kind: string, room_id: ?string}>
     */
    public static function absorbed(?GroupSession ...$sessions): array
    {
        return array_values(array_map(
            fn (GroupSession $session): array => ['id' => $session->id, ...self::snapshot($session)],
            array_filter($sessions),
        ));
    }

    private function campusOf(Group $group): Campus
    {
        return $group->campus()->withTrashed()->firstOrFail();
    }

    private static function invalid(string $message): ValidationException
    {
        return ValidationException::withMessages(['session' => $message]);
    }
}
