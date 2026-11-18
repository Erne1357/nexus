<?php

namespace App\Modules\Scheduling\Rules\Reanalysis;

/**
 * Un grupo vivo (sin cancelar) de una materia en un campus para ReanalysisAdvisor: su capacidad, sus cupos por carrera
 * (`quotas`, id de la carrera => lugares), lo que apartan sus propuestas de grupo compartido (`held`, HC-07), su docente y
 * sus sesiones con su salón.
 */
final readonly class AdvisorGroup
{
    /**
     * @param  array<string, int>  $quotas
     * @param  list<AdvisorSession>  $sessions
     */
    public function __construct(
        public string $id,
        public string $code,
        public int $capacity,
        public array $quotas,
        public array $sessions = [],
        public int $held = 0,
        public ?string $teacherName = null,
    ) {}

    /**
     * Los lugares repartidos entre carreras.
     */
    public function used(): int
    {
        return array_sum($this->quotas);
    }

    /**
     * Lo que cabe sin subir la capacidad (HC-07): la capacidad menos lo repartido y lo apartado.
     */
    public function free(): int
    {
        return max(0, $this->capacity - $this->used() - $this->held);
    }

    /**
     * Hasta cuánto puede llegar la capacidad sin cambiar de salón (HC-05): el salón más chico de sus sesiones. Null si no
     * tiene sesiones o alguna no tiene salón: sin salón, nada dice qué tan grande puede ser (supuesto por confirmar).
     */
    public function roomLimit(): ?int
    {
        if ($this->sessions === [] || array_any($this->sessions, fn (AdvisorSession $session): bool => $session->room === null)) {
            return null;
        }

        return min(array_map(fn (AdvisorSession $session): int => $session->room->capacity, $this->sessions));
    }

    /**
     * Las horas a la semana de sus sesiones.
     */
    public function weeklyHours(): float
    {
        return array_sum(array_map(fn (AdvisorSession $session): int => $session->minutes(), $this->sessions)) / 60;
    }
}
