<?php

namespace App\Modules\Scheduling\Validation\State;

use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Validation\Minutes;

/**
 * Una sesión en el estado del validador, con las horas en minutos desde la medianoche ([start, end)). La candidata
 * del editor entra con id null.
 */
final readonly class StateSession
{
    public function __construct(
        public ?string $id,
        public string $groupId,
        public int $day,
        public int $start,
        public int $end,
        public ?string $roomId,
        public SessionKind $kind = SessionKind::Teoria,
    ) {}

    /**
     * Si se empalma con otra el mismo día; dos horas contiguas no.
     */
    public function overlaps(self $other): bool
    {
        return $this->day === $other->day && Minutes::overlap($this->start, $this->end, $other->start, $other->end);
    }

    public function minutes(): int
    {
        return $this->end - $this->start;
    }

    /**
     * «lunes de 07:00 a 09:00».
     */
    public function describe(): string
    {
        return Minutes::dayName($this->day).' de '.Minutes::format($this->start).' a '.Minutes::format($this->end);
    }
}
