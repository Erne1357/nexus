<?php

namespace App\Modules\Scheduling\Validation;

use App\Modules\Core\Models\CampusProfile;
use App\Modules\Scheduling\Validation\State\StateGroup;
use App\Modules\Scheduling\Validation\State\StateQuota;
use App\Modules\Scheduling\Validation\State\StateRoom;
use App\Modules\Scheduling\Validation\State\StateRoomBlock;
use App\Modules\Scheduling\Validation\State\StateRoomLoan;
use App\Modules\Scheduling\Validation\State\StateSession;
use App\Modules\Scheduling\Validation\State\StateSubject;
use App\Modules\Scheduling\Validation\State\StateTeacher;
use LogicException;

/**
 * Foto en memoria de lo que el validador revisa en un periodo (tarea 3.2): los grupos y sesiones del campus que se
 * valida, más los grupos con docente de otros campus del periodo (HC-01 cruza campus), y las materias, salones,
 * docentes, bloqueos, cupos, préstamos, perfiles de campus, carreras ofrecidas y planes activos que las reglas
 * consultan. Las reglas son puras: leen esta foto y nunca la base, así que se prueban sin base y el editor puede
 * preguntar «¿y si…?» sin escribir (withSession(), withTeacher()). La arma ScheduleStateQuery o, en las pruebas,
 * ScheduleStateBuilder. Inmutable: los with…() devuelven otra foto.
 */
final class ScheduleState
{
    /** @var array<string, list<StateSession>> */
    private array $byTeacher = [];

    /** @var array<string, list<StateSession>> */
    private array $byRoom = [];

    /** @var array<string, list<StateGroup>> */
    private array $bySemester = [];

    /**
     * @param  array<string, StateGroup>  $groups  por id
     * @param  array<string, list<StateSession>>  $sessions  por grupo
     * @param  array<string, StateSubject>  $subjects  por id
     * @param  array<string, StateRoom>  $rooms  por id
     * @param  array<string, StateTeacher>  $teachers  por id
     * @param  array<string, list<StateRoomBlock>>  $blocks  por salón
     * @param  array<string, list<StateQuota>>  $quotas  por grupo
     * @param  array<string, list<StateRoomLoan>>  $loans  por grupo
     * @param  array<string, CampusProfile>  $profiles  por campus
     * @param  array<string, true>  $offerings  llaves «carrera|campus» de las carreras ofrecidas
     * @param  array<string, true>  $activePlans  ids de los planes activos
     * @param  array<string, mixed>  $settings  parámetros (`staffing.extra_hours_tolerance`…)
     */
    public function __construct(
        public readonly string $termId,
        private readonly array $groups = [],
        private readonly array $sessions = [],
        private readonly array $subjects = [],
        private readonly array $rooms = [],
        private readonly array $teachers = [],
        private readonly array $blocks = [],
        private readonly array $quotas = [],
        private readonly array $loans = [],
        private readonly array $profiles = [],
        private readonly array $offerings = [],
        private readonly array $activePlans = [],
        private readonly array $settings = [],
    ) {
        foreach ($this->groups as $group) {
            if (! $group->isActive) {
                continue;
            }

            $this->bySemester[self::semesterKey($group->campusId, $group->studyPlanId, $group->semester)][] = $group;

            foreach ($this->sessions[$group->id] ?? [] as $session) {
                if ($group->teacherId !== null) {
                    $this->byTeacher[$group->teacherId][] = $session;
                }

                if ($session->roomId !== null) {
                    $this->byRoom[$session->roomId][] = $session;
                }
            }
        }
    }

    /**
     * @return array<string, StateGroup>
     */
    public function groups(): array
    {
        return $this->groups;
    }

    public function group(string $id): ?StateGroup
    {
        return $this->groups[$id] ?? null;
    }

    /**
     * El grupo, o un error si la foto no lo trae: una regla que lo pide sin que esté es un error de quien la armó.
     */
    public function groupOrFail(string $id): StateGroup
    {
        return $this->groups[$id] ?? throw new LogicException("El estado del validador no trae el grupo {$id}.");
    }

    /**
     * Las sesiones de un grupo, aunque esté cancelado.
     *
     * @return list<StateSession>
     */
    public function sessionsOf(string $groupId): array
    {
        return $this->sessions[$groupId] ?? [];
    }

    /**
     * Las sesiones de los grupos activos de un docente, en cualquier campus del periodo.
     *
     * @return list<StateSession>
     */
    public function teacherSessions(string $teacherId): array
    {
        return $this->byTeacher[$teacherId] ?? [];
    }

    /**
     * Las sesiones de los grupos activos en un salón.
     *
     * @return list<StateSession>
     */
    public function roomSessions(string $roomId): array
    {
        return $this->byRoom[$roomId] ?? [];
    }

    /**
     * Los grupos activos de un plan y semestre reticular en un campus (HC-03).
     *
     * @return list<StateGroup>
     */
    public function groupsOfSemester(string $campusId, string $studyPlanId, int $semester): array
    {
        return $this->bySemester[self::semesterKey($campusId, $studyPlanId, $semester)] ?? [];
    }

    /**
     * Los planes y semestres con grupos activos de un campus, como [plan, semestre].
     *
     * @return list<array{string, int}>
     */
    public function semestersOf(string $campusId): array
    {
        $semesters = [];

        foreach (array_keys($this->bySemester) as $key) {
            [$campus, $plan, $semester] = explode('|', $key);

            if ($campus === $campusId) {
                $semesters[] = [$plan, (int) $semester];
            }
        }

        return $semesters;
    }

    /**
     * Cómo se nombra un grupo en un mensaje: «Cálculo Diferencial, grupo D».
     */
    public function labelOf(string $groupId): string
    {
        $group = $this->groupOrFail($groupId);
        $subject = $this->subject($group->subjectId);

        return ($subject?->name ?? 'La materia').', grupo '.$group->code;
    }

    public function subject(string $id): ?StateSubject
    {
        return $this->subjects[$id] ?? null;
    }

    public function room(string $id): ?StateRoom
    {
        return $this->rooms[$id] ?? null;
    }

    public function teacher(string $id): ?StateTeacher
    {
        return $this->teachers[$id] ?? null;
    }

    /**
     * @return list<StateRoomBlock>
     */
    public function blocksOf(string $roomId): array
    {
        return $this->blocks[$roomId] ?? [];
    }

    /**
     * @return list<StateQuota>
     */
    public function quotasOf(string $groupId): array
    {
        return $this->quotas[$groupId] ?? [];
    }

    /**
     * Los préstamos vivos (aceptados o propuestos) de un grupo.
     *
     * @return list<StateRoomLoan>
     */
    public function loansOf(string $groupId): array
    {
        return $this->loans[$groupId] ?? [];
    }

    /**
     * El perfil de flujo del campus; sin perfil cargado, el de omisión (presencial).
     */
    public function profile(string $campusId): CampusProfile
    {
        return $this->profiles[$campusId] ?? CampusProfile::fromArray([]);
    }

    public function isOffered(string $careerId, string $campusId): bool
    {
        return isset($this->offerings["{$careerId}|{$campusId}"]);
    }

    public function isPlanActive(string $studyPlanId): bool
    {
        return isset($this->activePlans[$studyPlanId]);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    /**
     * La foto si la candidata quedara en el grupo: reemplaza la sesión que mueve, o se agrega.
     */
    public function withSession(string $groupId, SessionCandidate $candidate): self
    {
        $this->groupOrFail($groupId);

        $sessions = array_values(array_filter(
            $this->sessionsOf($groupId),
            fn (StateSession $session): bool => $candidate->replacesSessionId === null || $session->id !== $candidate->replacesSessionId,
        ));

        $sessions[] = new StateSession(
            $candidate->replacesSessionId,
            $groupId,
            $candidate->day,
            Minutes::of($candidate->startsAt),
            Minutes::of($candidate->endsAt),
            $candidate->roomId,
            $candidate->kind,
        );

        return $this->copy(sessions: [...$this->sessions, $groupId => $sessions]);
    }

    /**
     * La foto si el grupo tuviera otro docente (o ninguno).
     */
    public function withTeacher(string $groupId, ?string $teacherId): self
    {
        $group = $this->groupOrFail($groupId);

        return $this->copy(groups: [...$this->groups, $groupId => $group->withTeacher($teacherId)]);
    }

    /**
     * La foto con más docentes: los que no traía, o con datos más nuevos.
     *
     * @param  iterable<StateTeacher>  $teachers
     */
    public function withTeachers(iterable $teachers): self
    {
        $merged = $this->teachers;

        foreach ($teachers as $teacher) {
            $merged[$teacher->id] = $teacher;
        }

        return $this->copy(teachers: $merged);
    }

    /**
     * @param  array<string, StateGroup>|null  $groups
     * @param  array<string, list<StateSession>>|null  $sessions
     * @param  array<string, StateTeacher>|null  $teachers
     */
    private function copy(?array $groups = null, ?array $sessions = null, ?array $teachers = null): self
    {
        return new self(
            $this->termId,
            $groups ?? $this->groups,
            $sessions ?? $this->sessions,
            $this->subjects,
            $this->rooms,
            $teachers ?? $this->teachers,
            $this->blocks,
            $this->quotas,
            $this->loans,
            $this->profiles,
            $this->offerings,
            $this->activePlans,
            $this->settings,
        );
    }

    private static function semesterKey(string $campusId, string $studyPlanId, int $semester): string
    {
        return "{$campusId}|{$studyPlanId}|{$semester}";
    }
}
