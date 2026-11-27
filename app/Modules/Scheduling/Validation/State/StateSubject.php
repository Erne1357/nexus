<?php

namespace App\Modules\Scheduling\Validation\State;

use App\Modules\Academic\Rules\SubjectTeacherHours;

/**
 * Una materia en el estado del validador: horas semanales (HC-12), laboratorio (HC-06), si está activa (HC-13) y si sus
 * horas cuentan en la carga del docente (PL-02, tarea 1.73): las horas de sesión (weeklyHours) siguen siendo las de la
 * materia, y las del docente (teacherHours, HC-08) son 0 si no cuentan, como las tutorías.
 */
final readonly class StateSubject
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public int $theoryHours,
        public int $practiceHours,
        public bool $needsLab = false,
        public bool $isActive = true,
        public bool $countsTeacherHours = true,
    ) {}

    /** Las horas de sesión del grupo (HC-12): teoría más práctica. */
    public function weeklyHours(): int
    {
        return $this->theoryHours + $this->practiceHours;
    }

    /** Las horas que le cuenta al docente (HC-08, PL-02): las de sesión, o 0 si no cuentan en su carga. */
    public function teacherHours(): int
    {
        return SubjectTeacherHours::of($this->theoryHours, $this->practiceHours, $this->countsTeacherHours);
    }
}
