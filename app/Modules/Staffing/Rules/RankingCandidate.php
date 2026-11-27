<?php

namespace App\Modules\Staffing\Rules;

use App\Modules\Faculty\Enums\QualificationLevel;

/**
 * Un docente candidato a un grupo, con los datos que necesita el ranking (reglas-de-negocio.md, «Ranking docente
 * (desempate)»). `evaluation` es el promedio de 0 a 5 o null si no tiene; `remainingHours` es informativo. `noHours`
 * (con el grupo pasa su banda de horas frente a grupo, HC-08; `bandAccepted` si su casilla ya lo cubre) lo baja al
 * bloque «Pasan su banda», asignable, debajo de los demás (tarea 1.50); choque y no disponible, al bloque final.
 * `tightTravel` es el aviso de traslado justo entre campus (SC-09): se muestra, pero no lo mueve.
 */
final readonly class RankingCandidate
{
    public function __construct(
        public string $teacherId,
        public string $name,
        public int $baseHours,
        public int $historyCount,
        public ?QualificationLevel $qualification,
        public ?float $evaluation,
        public ?int $remainingHours,
        public bool $noHours,
        public bool $clash,
        public bool $unavailable,
        public ?int $previousRank,
        public bool $tightTravel = false,
        public bool $bandAccepted = false,
        public bool $noRoom = false,
    ) {}

    /** El bloque final, «no pueden tomarlo»: choque (HC-01), no disponible (HC-09) o, con plaza administrativa, que no le caben las horas del grupo en su tope de 42 h (HC-22, `noRoom`). */
    public function isFlagged(): bool
    {
        return $this->clash || $this->unavailable || $this->noRoom;
    }

    /** El bloque «Pasan su banda» (HC-08): asignable, debajo de los demás y antes del bloque final. */
    public function isOverBand(): bool
    {
        return $this->noHours && ! $this->isFlagged();
    }

    /** El bloque para ordenar: 0 los demás, 1 pasan su banda, 2 el bloque final. */
    public function tier(): int
    {
        return $this->isFlagged() ? 2 : ($this->noHours ? 1 : 0);
    }
}
