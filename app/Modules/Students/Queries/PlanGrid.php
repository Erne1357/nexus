<?php

namespace App\Modules\Students\Queries;

/**
 * La retícula de un plan para calcular el avance de sus alumnos: los créditos de cada materia del plan, en el
 * orden de la retícula, su seriación, la especialidad de las materias de especialidad, su semestre y el
 * porcentaje de créditos que piden algunas (Servicio Social y Residencia). Solo seriaciones: un correquisito no
 * impide cursar una materia (reglas-de-negocio.md, "Elegibilidad y demanda"). Una materia inactiva del catálogo
 * cuenta igual, porque el plan se ata al alumno al inscribirse. La arma PlanGridQuery; aquí solo se calcula,
 * sin base.
 */
final readonly class PlanGrid
{
    /**
     * @param  array<string, int>  $credits  id de materia → créditos, en el orden de la retícula
     * @param  array<string, list<string>>  $seriation  id de materia → ids de las materias que pide antes
     * @param  array<string, string>  $specialtyOf  id de materia de especialidad → id de su especialidad
     * @param  array<string, int>  $semesterOf  id de materia → su semestre en la retícula
     * @param  array<string, int>  $creditShare  id de materia → porcentaje de los créditos del plan que pide
     * @param  int|null  $totalCredits  créditos totales del plan, si están capturados
     */
    public function __construct(
        public array $credits,
        public array $seriation = [],
        public array $specialtyOf = [],
        public array $semesterOf = [],
        public array $creditShare = [],
        public ?int $totalCredits = null,
    ) {}

    /**
     * El avance de un alumno que aprobó `$approved` y está cursando `$taking`: créditos y materias aprobadas
     * del plan, las que le faltan y, de esas, las que ya puede cursar porque aprobó toda su seriación. Lo
     * aprobado fuera del plan no cuenta, y una materia de seriación que ya no está en la retícula no bloquea.
     *
     * La especialidad del alumno sale de su kardex: si aprobó o está cursando materias de una especialidad,
     * las de las otras ya no le faltan. Mientras no toma ninguna, le faltan las de todas, porque puede elegir
     * cualquiera (confirmado el 29 de octubre de 2026).
     *
     * Puede cursar las materias de su semestre y de hasta dos más, y las de semestres anteriores que le faltan;
     * sin semestre (un egresado) no hay límite. Una materia que pide un porcentaje de créditos queda disponible
     * al llegar a él (los dos confirmados el 29 de octubre de 2026).
     *
     * @param  list<string>  $approved  ids de las materias que aprobó, de cualquier plan
     * @param  list<string>  $taking  ids de las materias que está cursando
     * @param  int|null  $semester  el semestre del alumno, como lo trae el SII
     * @return array{credits_approved: int, subjects_approved: int, subjects_pending: list<string>, subjects_available: list<string>}
     */
    public function progressFor(array $approved, array $taking = [], ?int $semester = null): array
    {
        $passed = array_fill_keys($approved, true);
        $chosen = $this->specialtiesOf([...$approved, ...$taking]);
        $credits = 0;
        $count = 0;
        $pending = [];

        foreach ($this->credits as $subject => $subjectCredits) {
            if (isset($passed[$subject])) {
                $credits += $subjectCredits;
                $count++;

                continue;
            }

            if ($chosen !== [] && isset($this->specialtyOf[$subject]) && ! isset($chosen[$this->specialtyOf[$subject]])) {
                continue;
            }

            $pending[] = $subject;
        }

        $total = $this->totalCredits ?? $this->gridCredits($chosen);
        $available = array_values(array_filter($pending, fn (string $subject): bool => $this->seriationPassed($subject, $passed)
            && $this->withinReach($subject, $semester)
            && $credits * 100 >= ($this->creditShare[$subject] ?? 0) * $total));

        return [
            'credits_approved' => $credits,
            'subjects_approved' => $count,
            'subjects_pending' => $pending,
            'subjects_available' => $available,
        ];
    }

    /**
     * @param  list<string>  $subjects
     * @return array<string, true> las especialidades de las materias de especialidad que tomó
     */
    private function specialtiesOf(array $subjects): array
    {
        $specialties = [];

        foreach ($subjects as $subject) {
            if (isset($this->specialtyOf[$subject])) {
                $specialties[$this->specialtyOf[$subject]] = true;
            }
        }

        return $specialties;
    }

    /**
     * Los créditos de la retícula con una sola especialidad: la que eligió el alumno o, si todavía no elige, la
     * de más créditos, para no dar por cumplido un porcentaje antes de tiempo.
     *
     * @param  array<string, true>  $chosen
     */
    private function gridCredits(array $chosen): int
    {
        $common = 0;
        $bySpecialty = [];

        foreach ($this->credits as $subject => $subjectCredits) {
            if (isset($this->specialtyOf[$subject])) {
                $bySpecialty[$this->specialtyOf[$subject]] = ($bySpecialty[$this->specialtyOf[$subject]] ?? 0) + $subjectCredits;
            } else {
                $common += $subjectCredits;
            }
        }

        $candidates = $chosen === [] ? $bySpecialty : array_intersect_key($bySpecialty, $chosen);

        return $common + ($candidates === [] ? 0 : max($candidates));
    }

    private function withinReach(string $subject, ?int $semester): bool
    {
        return $semester === null || ! isset($this->semesterOf[$subject]) || $this->semesterOf[$subject] <= $semester + 2;
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
