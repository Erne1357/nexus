<?php

namespace App\Modules\Scheduling\Validation;

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Queries\ScheduleStateQuery;
use App\Modules\Scheduling\Rules\Constraints\CampusWindow;
use App\Modules\Scheduling\Rules\Constraints\Constraint;
use App\Modules\Scheduling\Rules\Constraints\LabRoom;
use App\Modules\Scheduling\Rules\Constraints\OfferableGroup;
use App\Modules\Scheduling\Rules\Constraints\RoomBlocked;
use App\Modules\Scheduling\Rules\Constraints\RoomCapacity;
use App\Modules\Scheduling\Rules\Constraints\RoomOverlap;
use App\Modules\Scheduling\Rules\Constraints\TeacherOverlap;
use App\Modules\Scheduling\Rules\Constraints\WeeklyHours;
use Illuminate\Contracts\Container\Container;

/**
 * El orquestador del validador de restricciones (tarea 3.2): junta las reglas de `Rules/Constraints` y valida un
 * grupo, un docente, un salón o toda una carga, o pregunta sin escribir qué pasaría con una sesión candidata (el
 * editor) o con otro docente (el ranking). Lo usan el editor de horarios (3.5), la asignación (3.8, 3.9), el job que
 * recalcula `schedule_conflicts` y el tablero de choques.
 *
 * Guarda la foto de cada periodo y campus que ya leyó mientras viva la instancia: varias preguntas seguidas (una por
 * celda del editor, una por docente candidato) leen la base una sola vez. No es singleton (Octane): cada petición o
 * job resuelve la suya; después de escribir, forget() la olvida.
 */
final class ScheduleValidator
{
    /**
     * Las reglas que corre, por id.
     *
     * @var list<class-string<Constraint>>
     */
    public const array CONSTRAINTS = [
        TeacherOverlap::class,
        RoomOverlap::class,
        CampusWindow::class,
        RoomCapacity::class,
        LabRoom::class,
        RoomBlocked::class,
        WeeklyHours::class,
        OfferableGroup::class,
    ];

    /**
     * Lo que checkTeacher() responde: choque (HC-01), horas (HC-08) y disponibilidad (HC-09) del docente.
     */
    public const array TEACHER_RULES = ['HC-01', 'HC-08', 'HC-09'];

    /** @var array<string, ScheduleState> */
    private array $states = [];

    public function __construct(
        private readonly ScheduleStateQuery $query,
        private readonly Container $container,
    ) {}

    /**
     * @return list<Constraint>
     */
    public function constraints(): array
    {
        return array_map(fn (string $constraint): Constraint => $this->container->make($constraint), self::CONSTRAINTS);
    }

    /**
     * El corazón puro: todas las reglas sobre una foto y un alcance.
     */
    public function validate(ScheduleState $state, ValidationScope $scope): ValidationResult
    {
        return new ValidationResult((function () use ($state, $scope): iterable {
            foreach ($this->constraints() as $constraint) {
                yield from $constraint->check($state, $scope);
            }
        })());
    }

    /**
     * Las violaciones del grupo (las que lo tienen como grupo afectado; el otro grupo de un empalme tiene las suyas).
     */
    public function validateGroup(Group $group): ValidationResult
    {
        return self::of($group->id, $this->validate($this->stateFor($group->term_id, $group->campus_id), ValidationScope::groups($group->id)));
    }

    /**
     * Todo lo del docente en el periodo, en cualquier campus.
     */
    public function validateTeacher(Teacher $teacher, Term $term): ValidationResult
    {
        $state = $this->stateFor($term->id, null);

        if ($state->teacher($teacher->id) === null) {
            $state = $state->withTeachers($this->query->teachers($term, [$teacher->id]));
        }

        return $this->validate($state, ValidationScope::teacher($teacher->id));
    }

    public function validateRoom(Room $room, Term $term): ValidationResult
    {
        return $this->validate($this->stateFor($term->id, $room->campus_id), ValidationScope::room($room->id));
    }

    /**
     * La carga de un campus completo, o la de un departamento en él.
     */
    public function validateLoad(Term $term, Campus $campus, ?Department $department = null): ValidationResult
    {
        $state = $this->stateFor($term->id, $campus->id);

        return $this->validate($state, ValidationScope::load($state, $campus->id, $department?->id));
    }

    /**
     * Las violaciones del grupo si la sesión candidata quedara así, sin escribir nada: lo que el editor pinta en vivo.
     */
    public function checkSession(Group $group, SessionCandidate $candidate): ValidationResult
    {
        $state = $this->stateFor($group->term_id, $group->campus_id);

        return self::of($group->id, $this->validate($state->withSession($group->id, $candidate), ValidationScope::groups($group->id)));
    }

    /**
     * checkSession() para varias candidatas del mismo grupo, con la misma foto (una celda del editor cada una).
     *
     * @param  array<array-key, SessionCandidate>  $candidates
     * @return array<array-key, ValidationResult>
     */
    public function checkSessions(Group $group, array $candidates): array
    {
        return array_map(fn (SessionCandidate $candidate): ValidationResult => $this->checkSession($group, $candidate), $candidates);
    }

    /**
     * Qué pasaría si el grupo fuera de este docente, sin escribir: choque (HC-01), horas (HC-08) y disponibilidad
     * (HC-09). No se cuenta a sí mismo: el grupo deja de ser de su docente actual, así que reasignarlo al mismo no da
     * choque ni falta de horas por sus propias sesiones.
     */
    public function checkTeacher(Group $group, Teacher $teacher): ValidationResult
    {
        return $this->checkTeachers($group, [$teacher])[$teacher->id];
    }

    /**
     * checkTeacher() para varios docentes candidatos con una sola lectura de la base.
     *
     * @param  iterable<Teacher>  $teachers
     * @return array<string, ValidationResult> por id de docente
     */
    public function checkTeachers(Group $group, iterable $teachers): array
    {
        $state = $this->stateFor($group->term_id, $group->campus_id);
        $ids = [];

        foreach ($teachers as $teacher) {
            $ids[] = $teacher->id;
        }

        $missing = array_values(array_filter($ids, fn (string $id): bool => $state->teacher($id) === null));

        if ($missing !== []) {
            $state = $state->withTeachers($this->query->teachers(Term::query()->findOrFail($group->term_id), $missing));
            $this->states[self::key($group->term_id, $group->campus_id)] = $state;
        }

        $results = [];

        foreach ($ids as $id) {
            $result = $this->validate($state->withTeacher($group->id, $id), new ValidationScope([$group->id], [$id]));

            $results[$id] = new ValidationResult(array_filter($result->violations(), fn (Violation $violation): bool => in_array($violation->rule, self::TEACHER_RULES, true)
                && ($violation->groupId === $group->id || ($violation->groupId === null && $violation->subjectType === 'teacher' && $violation->subjectId === $id))));
        }

        return $results;
    }

    /**
     * La foto del periodo vista desde un campus (sin campus: los grupos con docente de todo el periodo), leída una sola
     * vez por instancia.
     */
    public function stateFor(string $termId, ?string $campusId): ScheduleState
    {
        return $this->states[self::key($termId, $campusId)] ??= $this->query->forCampus(
            Term::query()->findOrFail($termId),
            $campusId === null ? null : Campus::query()->findOrFail($campusId),
        );
    }

    /**
     * Olvida las fotos leídas: después de escribir, la siguiente pregunta vuelve a leer la base.
     */
    public function forget(): void
    {
        $this->states = [];
    }

    private static function of(string $groupId, ValidationResult $result): ValidationResult
    {
        return new ValidationResult($result->forGroup($groupId));
    }

    private static function key(string $termId, ?string $campusId): string
    {
        return $termId.'|'.($campusId ?? '*');
    }
}
