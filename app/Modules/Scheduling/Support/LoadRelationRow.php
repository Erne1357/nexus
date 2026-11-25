<?php

namespace App\Modules\Scheduling\Support;

/**
 * Un renglón de la «Relación de materias» (LoadRelationSheet), ya normalizado: lo usan el importador de la carga
 * (ImportLoadProjection, 1.40) y el de elecciones previas de Staffing (1.41).
 */
final readonly class LoadRelationRow
{
    public function __construct(
        public int $line,
        public ?string $teacherName,
        public string $subjectKey,
        public ?string $letter,
        public ?string $hourRange,
        public ?int $semester,
        public ?string $planCode,
        public ?int $credits,
        public ?string $roomCode,
        public ?int $students,
        public ?string $space,
        public string $subjectName,
    ) {}

    /**
     * Sin horario fijo (decisión D3, 25 de noviembre de 2026): la letra «GL» o un rango vacío o `#N/D`.
     */
    public function hasFixedSchedule(): bool
    {
        return $this->letter !== 'GL' && $this->hourRange !== null;
    }
}
