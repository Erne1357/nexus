<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\Demand\Enums\DemandBalance;
use Carbon\CarbonImmutable;

/**
 * Una fila del diff del re-análisis (tarea 8.2): una materia de una carrera en un campus, con lo que espera la
 * proyección vigente y lo que ofrece la última versión publicada. Sin proyección (`forecasted` falso) los esperados son 0:
 * es oferta sin demanda. Sin oferta (`offeredGroups` 0) es demanda sin oferta. `balance` es nulo en una materia de primer
 * semestre (RA-09): se muestra con su nuevo ingreso capturado y no se clasifica.
 */
final readonly class ReanalysisRow
{
    public function __construct(
        public ?string $campusId,
        public ?string $campusCode,
        public ?string $campusName,
        public string $careerId,
        public string $careerCode,
        public string $careerName,
        public string $subjectId,
        public string $subjectCode,
        public string $subjectName,
        public ?int $semester,
        public bool $forecasted,
        public int $expectedStudents,
        public int $suggestedGroups,
        public ?int $newStudents,
        public int $offeredSeats,
        public int $offeredGroups,
        public bool $isFirstSemester,
        public ?DemandBalance $balance,
        public bool $needsReview,
        public bool $insufficientHistory,
        public ?CarbonImmutable $computedAt,
    ) {}

    /**
     * Lo esperado menos lo ofrecido: positivo falta oferta, negativo sobra.
     */
    public function gap(): int
    {
        return $this->expectedStudents - $this->offeredSeats;
    }
}
