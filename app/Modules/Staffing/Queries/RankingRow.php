<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Faculty\Enums\QualificationLevel;

/**
 * Un lugar del ranking de un grupo como lo muestra la asignación: el docente, su plaza y el desglose guardado en
 * `staffing_rankings.breakdown` (reglas-de-negocio.md, «Ranking docente (desempate)»). `remainingHours` son las horas
 * que le quedan al docente sin el grupo y `remainingAfterHours`, las que le quedarían con él; las dos pueden faltar
 * (null) en renglones viejos o cuando el validador no las da.
 */
final readonly class RankingRow
{
    /** Las marcas que bajan al docente al bloque final del ranking, con su nombre en pantalla. */
    public const array MARKS = ['choque' => 'Choque', 'no_disponible' => 'No disponible', 'sin_cupo' => 'No le caben'];

    /**
     * Los avisos que se muestran discretos junto a la plaza, con su nombre en pantalla: con el grupo pasa su banda de
     * horas frente a grupo (HC-08; además lo pone en el bloque «Pasan su banda», tarea 1.50) y traslado justo (SC-09).
     */
    public const array NOTICES = ['sin_horas' => 'con horas extra', 'traslado_justo' => 'traslado justo'];

    /**
     * @param  list<string>  $flags  `sin_horas`, `banda_aceptada`, `choque`, `no_disponible`, `sin_cupo`, `sin_evaluacion`, `traslado_justo`
     */
    public function __construct(
        public int $rank,
        public float $score,
        public string $teacherId,
        public string $teacherName,
        public string $positions,
        public int $baseHours,
        public int $historyCount,
        public ?QualificationLevel $qualification,
        public ?float $evaluation,
        public ?int $remainingHours,
        public ?int $remainingAfterHours,
        public array $flags,
        public bool $fromOtherDepartment,
    ) {}

    /**
     * Si va en el bloque final: choque o no disponible.
     */
    public function isFlagged(): bool
    {
        return $this->marks() !== [];
    }

    /**
     * Si va en el bloque «Pasan su banda» (HC-08, tarea 1.50): con el grupo pasa su banda de horas frente a grupo, sin
     * choque ni no disponible. Se puede asignar; va debajo de los demás y antes del bloque final.
     */
    public function isOverBand(): bool
    {
        return in_array('sin_horas', $this->flags, true) && ! $this->isFlagged();
    }

    /**
     * Si su casilla (SEL-12) ya cubre las horas con las que pasa su banda: «aceptado por el docente (casilla)».
     */
    public function bandAccepted(): bool
    {
        return in_array('banda_aceptada', $this->flags, true);
    }

    /**
     * Las marcas del bloque final que tiene, por su clave, con su nombre.
     *
     * @return array<string, string>
     */
    public function marks(): array
    {
        return array_intersect_key(self::MARKS, array_flip($this->flags));
    }

    /**
     * Los avisos discretos que tiene (pasa su banda, HC-08; traslado justo, SC-09), por su clave, con su nombre.
     *
     * @return array<string, string>
     */
    public function notices(): array
    {
        return array_intersect_key(self::NOTICES, array_flip($this->flags));
    }

    public function lacksEvaluation(): bool
    {
        return $this->evaluation === null;
    }

    /**
     * El puntaje sin ceros de sobra: «15», «9.66».
     */
    public function scoreLabel(): string
    {
        return rtrim(rtrim(number_format($this->score, 2, '.', ''), '0'), '.');
    }

    /**
     * La evaluación con un decimal, o null si no tiene.
     */
    public function evaluationLabel(): ?string
    {
        return $this->evaluation === null ? null : number_format($this->evaluation, 1, '.', '');
    }

    /**
     * Las horas que le quedan sin el grupo y, si se saben, con él: «12 → 7» o «12»; null si no se saben.
     */
    public function remainingLabel(): ?string
    {
        if ($this->remainingHours === null) {
            return null;
        }

        return $this->remainingAfterHours === null ? (string) $this->remainingHours : "{$this->remainingHours} → {$this->remainingAfterHours}";
    }
}
