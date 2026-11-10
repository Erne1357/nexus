<?php

namespace App\Modules\Scheduling\Queries;

use Illuminate\Support\Collection;

/**
 * Cuántos grupos hay en un conjunto y cuántos tienen cada cosa pendiente; `missing` es cuántos no están listos para
 * cerrar (les falta al menos una).
 */
final readonly class BoardCount
{
    public function __construct(
        public int $groups = 0,
        public int $hard = 0,
        public int $unscheduled = 0,
        public int $withoutTeacher = 0,
        public int $withoutRoom = 0,
        public int $missing = 0,
        public int $pending = 0,
    ) {}

    /**
     * @param  Collection<int, GroupBoardStatus>  $statuses
     */
    public static function of(Collection $statuses): self
    {
        return new self(
            groups: $statuses->count(),
            hard: $statuses->where('hard', true)->count(),
            unscheduled: $statuses->where('unscheduled', true)->count(),
            withoutTeacher: $statuses->where('withoutTeacher', true)->count(),
            withoutRoom: $statuses->where('withoutRoom', true)->count(),
            missing: $statuses->filter(fn (GroupBoardStatus $status): bool => $status->isMissing())->count(),
            pending: $statuses->filter(fn (GroupBoardStatus $status): bool => $status->isPending() && ! $status->hard)->count(),
        );
    }

    public function ready(): int
    {
        return $this->groups - $this->missing;
    }

    public function isReady(): bool
    {
        return $this->groups > 0 && $this->missing === 0;
    }
}
