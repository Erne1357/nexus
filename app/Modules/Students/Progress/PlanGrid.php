<?php

namespace App\Modules\Students\Progress;

/**
 * La retícula de un plan para calcular el avance de sus alumnos: los créditos de cada materia del plan, en el
 * orden de la retícula, y su seriación. Solo seriaciones: un correquisito no impide cursar una materia
 * (reglas-de-negocio.md, "Elegibilidad y demanda"). La arma PlanGridQuery; aquí solo se calcula, sin base.
 */
final readonly class PlanGrid
{
    /**
     * @param  array<string, int>  $credits  id de materia → créditos, en el orden de la retícula
     * @param  array<string, list<string>>  $seriation  id de materia → ids de las materias que pide antes
     */
    public function __construct(
        public array $credits,
        public array $seriation = [],
    ) {}

    /**
     * El avance de un alumno que aprobó `$approved`: créditos y materias aprobadas del plan, las que le
     * faltan y, de esas, las que ya puede cursar porque aprobó toda su seriación. Lo aprobado fuera del plan
     * no cuenta, y una materia de seriación que ya no está en la retícula no bloquea.
     *
     * @param  list<string>  $approved  ids de las materias que aprobó, de cualquier plan
     * @return array{credits_approved: int, subjects_approved: int, subjects_pending: list<string>, subjects_available: list<string>}
     */
    public function progressFor(array $approved): array
    {
        $passed = array_fill_keys($approved, true);
        $credits = 0;
        $count = 0;
        $pending = [];
        $available = [];

        foreach ($this->credits as $subject => $subjectCredits) {
            if (isset($passed[$subject])) {
                $credits += $subjectCredits;
                $count++;

                continue;
            }

            $pending[] = $subject;

            if ($this->seriationPassed($subject, $passed)) {
                $available[] = $subject;
            }
        }

        return [
            'credits_approved' => $credits,
            'subjects_approved' => $count,
            'subjects_pending' => $pending,
            'subjects_available' => $available,
        ];
    }

    /**
     * @param  array<string, true>  $passed
     */
    private function seriationPassed(string $subject, array $passed): bool
    {
        foreach ($this->seriation[$subject] ?? [] as $prerequisite) {
            if (isset($this->credits[$prerequisite]) && ! isset($passed[$prerequisite])) {
                return false;
            }
        }

        return true;
    }
}
