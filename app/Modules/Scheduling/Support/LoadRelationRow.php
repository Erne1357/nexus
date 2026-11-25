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

    /**
     * El minuto de inicio del rango («9 - 10» = 540, «9:30 - 10:30» = 570), o null si no hay horario fijo o no se lee.
     * Es el mismo criterio con que ImportLoadProjection arma la hora de inicio de las sesiones del grupo.
     */
    public function startMinute(): ?int
    {
        if (! $this->hasFixedSchedule() || preg_match('/^(\d{1,2})(?::(\d{2}))?\s*-\s*(\d{1,2})(?::(\d{2}))?$/', (string) $this->hourRange, $m) !== 1) {
            return null;
        }

        $start = (int) $m[1] * 60 + (int) ($m[2] ?? 0);
        $end = (int) $m[3] * 60 + (int) ($m[4] ?? 0);

        return $end > $start && $end <= 24 * 60 ? $start : null;
    }
}
