<?php

namespace App\Modules\Scheduling\Rules\Reanalysis;

use App\Modules\Scheduling\Validation\Minutes;

/**
 * Una sesión de un grupo para ReanalysisAdvisor: el día y las horas, su salón (null sin salón) y los salones más grandes
 * que el suyo que están libres a esa hora (`largerRooms`, de ReanalysisSuggestionsQuery: activos del campus, del tipo que
 * pide la sesión, sin otra sesión, bloqueo ni préstamo de otro grupo encima; los que no piden préstamo primero, luego el
 * más chico).
 */
final readonly class AdvisorSession
{
    /**
     * @param  list<AdvisorRoom>  $largerRooms
     */
    public function __construct(
        public string $id,
        public int $day,
        public string $startsAt,
        public string $endsAt,
        public ?AdvisorRoom $room,
        public array $largerRooms = [],
    ) {}

    public function minutes(): int
    {
        return Minutes::of($this->endsAt) - Minutes::of($this->startsAt);
    }

    /**
     * «lun 07:00–09:00».
     */
    public function label(): string
    {
        return mb_strtolower(Minutes::dayLabel($this->day, short: true)).' '.Minutes::labelOf($this->startsAt).'–'.Minutes::labelOf($this->endsAt);
    }

    public function overlaps(self $other): bool
    {
        return $this->day === $other->day
            && Minutes::of($this->startsAt) < Minutes::of($other->endsAt)
            && Minutes::of($other->startsAt) < Minutes::of($this->endsAt);
    }
}
