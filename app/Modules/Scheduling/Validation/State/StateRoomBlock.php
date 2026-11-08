<?php

namespace App\Modules\Scheduling\Validation\State;

/**
 * Un bloqueo vigente de un salón en el periodo (HC-11), con horas en minutos.
 */
final readonly class StateRoomBlock
{
    public function __construct(
        public string $roomId,
        public int $day,
        public int $start,
        public int $end,
        public string $reason = '',
    ) {}
}
