<?php

namespace App\Modules\Scheduling\Validation\State;

/**
 * Una materia en el estado del validador: horas semanales (HC-12), laboratorio (HC-06) y si está activa (HC-13).
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
    ) {}

    public function weeklyHours(): int
    {
        return $this->theoryHours + $this->practiceHours;
    }
}
