<?php

namespace App\Modules\Scheduling\Validation;

/**
 * Qué se valida dentro de un ScheduleState: unos grupos, unos docentes y unos salones. Cada regla revisa lo que toca a
 * algo del alcance (HC-01 revisa los docentes del alcance y los de sus grupos; HC-02, los salones del alcance y los de
 * las sesiones de sus grupos) y reporta cada choque en los grupos involucrados.
 */
final readonly class ValidationScope
{
    /** @var array<string, true> */
    private array $groups;

    /** @var array<string, true> */
    private array $teachers;

    /** @var array<string, true> */
    private array $rooms;

    /**
     * @param  list<string>  $groupIds
     * @param  list<string>  $teacherIds
     * @param  list<string>  $roomIds
     */
    public function __construct(array $groupIds = [], array $teacherIds = [], array $roomIds = [])
    {
        $this->groups = array_fill_keys($groupIds, true);
        $this->teachers = array_fill_keys($teacherIds, true);
        $this->rooms = array_fill_keys($roomIds, true);
    }

    public static function groups(string ...$groupIds): self
    {
        return new self(array_values($groupIds));
    }

    public static function teacher(string $teacherId): self
    {
        return new self(teacherIds: [$teacherId]);
    }

    public static function room(string $roomId): self
    {
        return new self(roomIds: [$roomId]);
    }

    /**
     * Toda la carga de una modalidad, con los grupos de todos sus campus (M1), o la de un departamento en ella: sus
     * grupos activos.
     */
    public static function load(ScheduleState $state, string $modalityId, ?string $departmentId = null): self
    {
        $ids = [];

        foreach ($state->groups() as $group) {
            if ($group->isActive && $group->modalityId === $modalityId && ($departmentId === null || $group->departmentId === $departmentId)) {
                $ids[] = $group->id;
            }
        }

        return new self($ids);
    }

    /**
     * @return list<string>
     */
    public function groupIds(): array
    {
        return array_keys($this->groups);
    }

    public function hasGroup(string $groupId): bool
    {
        return isset($this->groups[$groupId]);
    }

    public function hasTeacher(string $teacherId): bool
    {
        return isset($this->teachers[$teacherId]);
    }

    public function hasRoom(string $roomId): bool
    {
        return isset($this->rooms[$roomId]);
    }

    /**
     * Los docentes del alcance más los de sus grupos activos.
     *
     * @return list<string>
     */
    public function teacherIds(ScheduleState $state): array
    {
        $ids = $this->teachers;

        foreach ($this->groupIds() as $groupId) {
            $teacherId = $state->group($groupId)?->teacherId;

            if ($teacherId !== null) {
                $ids[$teacherId] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * Los salones del alcance más los de las sesiones de sus grupos.
     *
     * @return list<string>
     */
    public function roomIds(ScheduleState $state): array
    {
        $ids = $this->rooms;

        foreach ($this->groupIds() as $groupId) {
            foreach ($state->sessionsOf($groupId) as $session) {
                if ($session->roomId !== null) {
                    $ids[$session->roomId] = true;
                }
            }
        }

        return array_keys($ids);
    }

    /**
     * Si un choque entre dos grupos toca el alcance: uno de los grupos, o el docente o salón que los une.
     */
    public function touches(string $groupA, string $groupB, ?string $teacherId = null, ?string $roomId = null): bool
    {
        return $this->hasGroup($groupA) || $this->hasGroup($groupB)
            || ($teacherId !== null && $this->hasTeacher($teacherId))
            || ($roomId !== null && $this->hasRoom($roomId));
    }
}
