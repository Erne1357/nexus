<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\Academic\Models\Career;
use App\Modules\AcademicLoad\Enums\DemandProposalState;
use App\Modules\Core\Models\Campus;
use App\Modules\Demand\Enums\ForecastMethod;

/**
 * Una materia de una carrera en un campus en el tablero «Demanda por materia» (tarea 5.7): lo que proyecta Demand
 * (elegibles, esperados, grupos sugeridos, con qué método y si hay que revisarla, en una materia de especialidad) y lo
 * que pidió la carrera en su propuesta (`requested`; null si su propuesta no cuenta, DemandProposalState::counts). Sin
 * fila de proyección, lo proyectado va en cero y el método en null. `semester` es el de la materia en la retícula de la
 * carrera (el de su propuesta si la tiene), o null si no está en ella. `campus` es null en una modalidad sin salones (MS5).
 */
final readonly class DemandBoardRow
{
    public function __construct(
        public Career $career,
        public ?Campus $campus,
        public DemandProposalState $state,
        public ?int $semester,
        public int $eligible,
        public int $expected,
        public int $suggested,
        public ?int $requested,
        public ?ForecastMethod $method,
        public bool $needsReview,
    ) {}

    /**
     * Lo que cuenta como pedido: lo pedido si la propuesta cuenta; si no, lo sugerido.
     */
    public function counted(): int
    {
        return $this->state->counts() ? (int) $this->requested : $this->suggested;
    }

    public function campusId(): ?string
    {
        return $this->campus?->getKey();
    }
}
