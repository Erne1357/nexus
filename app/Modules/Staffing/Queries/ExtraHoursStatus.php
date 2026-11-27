<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Staffing\Enums\ExtraHoursState;

/**
 * PL-04: las horas extra de un docente en un periodo y su estado: «2 h extra · honorarios por autorizar (jefe de depto)»,
 * «2 h de honorarios autorizadas», «2 h extra no autorizadas · requiere oficio del docente (pendiente)» o, de quien no puede
 * tener honorarios, «2 h extra · Jefatura: sin honorarios · requiere oficio del docente (pendiente)». Sin horas extra,
 * `extra` es 0 y no hay nada que decir (`hasExtra()` falso).
 */
final readonly class ExtraHoursStatus
{
    public function __construct(
        public int $extra,
        public ExtraHoursState $state,
        public ?int $decidedHours = null,
    ) {}

    public function hasExtra(): bool
    {
        return $this->extra > 0;
    }

    public function label(): string
    {
        return $this->state->describe($this->extra);
    }

    /** El jefe puede decidir ahora: hay horas extra y la persona puede tener honorarios. */
    public function isDecidable(): bool
    {
        return $this->hasExtra() && $this->state->isDecidable();
    }

    public static function none(): self
    {
        return new self(0, ExtraHoursState::Pending);
    }
}
