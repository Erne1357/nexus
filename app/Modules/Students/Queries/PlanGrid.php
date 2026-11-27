<?php

namespace App\Modules\Students\Queries;

/**
 * La retícula de un plan para calcular el avance de sus alumnos: los créditos de cada materia del plan, en el
 * orden de la retícula, su seriación, la especialidad de las materias de especialidad, su semestre y el
 * porcentaje de créditos que piden algunas (Servicio Social, Residencia y, al menos SPECIALTY_CREDIT_SHARE, las de
 * especialidad), y aparte las materias del plan que esperan
 * semestre en «Por validar» (D19), que no están en la retícula. Solo seriaciones: un correquisito no
 * impide cursar una materia (reglas-de-negocio.md, "Elegibilidad y demanda"). Una materia inactiva del catálogo
 * cuenta igual, porque el plan se ata al alumno al inscribirse. La arma PlanGridQuery; aquí solo se calcula,
 * sin base.
 */
final readonly class PlanGrid
{
    /**
     * El porcentaje de los créditos del plan que pide una materia de especialidad para poder cursarla
     * (reglas-de-negocio.md, «Elegibilidad y demanda»; supuesto por confirmar: el usuario cree que es el 60 %, 17 de
     * noviembre de 2026). Una materia de especialidad que ya pide un porcentaje mayor (por ejemplo, una residencia de
     * especialidad) conserva el suyo.
     */
    public const int SPECIALTY_CREDIT_SHARE = 60;

    /**
     * @param  array<string, int>  $credits  id de materia → créditos, en el orden de la retícula
     * @param  array<string, list<string>>  $seriation  id de materia → ids de las materias que pide antes
     * @param  array<string, string>  $specialtyOf  id de materia de especialidad → id de su especialidad
     * @param  array<string, int>  $semesterOf  id de materia → su semestre en la retícula
     * @param  array<string, int>  $creditShare  id de materia → porcentaje de los créditos del plan que pide
     * @param  int|null  $totalCredits  créditos totales del plan, si están capturados
     * @param  array<string, int>  $unplaced  id de materia → créditos, de las que esperan semestre en «Por validar», por su renglón y su clave
     * @param  list<string>  $inactiveSpecialties  ids de las especialidades del plan que no están activas (1.69)
     */
    public function __construct(
        public array $credits,
        public array $seriation = [],
        public array $specialtyOf = [],
        public array $semesterOf = [],
        public array $creditShare = [],
        public ?int $totalCredits = null,
        public array $unplaced = [],
        public array $inactiveSpecialties = [],
    ) {}

    /**
     * El avance de un alumno que aprobó `$approved` y está cursando `$taking`: créditos y materias aprobadas
     * del plan, las que le faltan y, de esas, las que ya puede cursar porque aprobó toda su seriación. Lo
     * aprobado fuera del plan no cuenta, y una materia de seriación que ya no está en la retícula no bloquea. Lo
     * aprobado en una materia que espera semestre en «Por validar» cuenta en créditos y materias, pero esas nunca
     * faltan ni se ofrecen, porque no están en la retícula (decidido el 10 de noviembre de 2026).
     *
     * La especialidad del alumno sale de su kardex: si aprobó o está cursando materias de una especialidad,
     * las de las otras ya no le faltan (confirmado el 29 de octubre de 2026). Si el kardex no la dice, vale la que
     * le asignó el SII (`$assignedSpecialty`; supuesto por confirmar, 1.69). Mientras no tiene ninguna, le faltan
     * solo las de las especialidades activas, porque puede elegir cualquiera de ellas (confirmado el 26 de
     * noviembre de 2026; antes, las de todas).
     *
     * Puede cursar las materias de su semestre y de hasta dos más, y las de semestres anteriores que le faltan;
     * sin semestre (un egresado) no hay límite. Una materia que pide un porcentaje de créditos queda disponible
     * al llegar a él (los dos confirmados el 29 de octubre de 2026); una de especialidad pide al menos
     * SPECIALTY_CREDIT_SHARE (supuesto por confirmar).
     *
     * @param  list<string>  $approved  ids de las materias que aprobó, de cualquier plan
     * @param  list<string>  $taking  ids de las materias que está cursando
     * @param  int|null  $semester  el semestre del alumno, como lo trae el SII
     * @param  string|null  $assignedSpecialty  id de la especialidad que le asignó el SII, si la hay
     * @return array{credits_approved: int, subjects_approved: int, subjects_pending: list<string>, subjects_available: list<string>}
     */
    public function progressFor(array $approved, array $taking = [], ?int $semester = null, ?string $assignedSpecialty = null): array
    {
        $passed = array_fill_keys($approved, true);
        $chosen = $this->specialtiesOf([...$approved, ...$taking], $assignedSpecialty);
        $credits = 0;
        $count = 0;
        $pending = [];

        foreach ($this->credits as $subject => $subjectCredits) {
            if (isset($passed[$subject])) {
                $credits += $subjectCredits;
                $count++;

                continue;
            }

            if (isset($this->specialtyOf[$subject]) && ! $this->offered($this->specialtyOf[$subject], $chosen)) {
                continue;
            }

            $pending[] = $subject;
        }

        foreach (array_intersect_key($this->unplaced, $passed) as $subjectCredits) {
            $credits += $subjectCredits;
            $count++;
        }

        $total = $this->creditShare === [] && $this->specialtyOf === [] ? 0 : $this->totalCredits ?? $this->gridCredits($chosen);
        $available = array_values(array_filter($pending, fn (string $subject): bool => $this->seriationPassed($subject, $passed)
            && $this->withinReach($subject, $semester)
            && $this->creditsReached($subject, $credits, $total)));

        return [
            'credits_approved' => $credits,
            'subjects_approved' => $count,
            'subjects_pending' => $pending,
            'subjects_available' => $available,
        ];
    }

    /**
     * Los créditos totales del plan para un alumno que aprobó o cursa `$subjects`: los capturados en el plan o,
     * si no están, los de la retícula con su especialidad (o la de más créditos entre las activas si todavía no elige).
     *
     * @param  list<string>  $subjects
     */
    public function totalFor(array $subjects, ?string $assignedSpecialty = null): int
    {
        return $this->totalCredits ?? $this->gridCredits($this->specialtiesOf($subjects, $assignedSpecialty));
    }

    /**
     * Si una especialidad se le ofrece al alumno: la que eligió (si ya eligió) o, mientras no elige, cualquiera
     * de las activas (1.69).
     *
     * @param  array<string, true>  $chosen
     */
    private function offered(string $specialty, array $chosen): bool
    {
        return $chosen === [] ? ! in_array($specialty, $this->inactiveSpecialties, true) : isset($chosen[$specialty]);
    }

    /**
     * @param  list<string>  $subjects
     * @return array<string, true> las especialidades de las materias de especialidad que tomó o, si no tomó ninguna, la asignada por el SII
     */
    private function specialtiesOf(array $subjects, ?string $assignedSpecialty = null): array
    {
        $specialties = [];

        foreach ($subjects as $subject) {
            if (isset($this->specialtyOf[$subject])) {
                $specialties[$this->specialtyOf[$subject]] = true;
            }
        }

        if ($specialties === [] && $assignedSpecialty !== null && in_array($assignedSpecialty, $this->specialtyOf, true)) {
            $specialties[$assignedSpecialty] = true;
        }

        return $specialties;
    }

    /**
     * Los créditos de la retícula con una sola especialidad: la que eligió el alumno o, si todavía no elige, la
     * de más créditos, para no dar por cumplido un porcentaje antes de tiempo. Suma las materias que esperan
     * semestre, que también son del plan y cuentan en lo aprobado.
     *
     * @param  array<string, true>  $chosen
     */
    private function gridCredits(array $chosen): int
    {
        $common = array_sum($this->unplaced);
        $bySpecialty = [];

        foreach ($this->credits as $subject => $subjectCredits) {
            if (isset($this->specialtyOf[$subject])) {
                $bySpecialty[$this->specialtyOf[$subject]] = ($bySpecialty[$this->specialtyOf[$subject]] ?? 0) + $subjectCredits;
            } else {
                $common += $subjectCredits;
            }
        }

        $candidates = $chosen === [] ? array_diff_key($bySpecialty, array_flip($this->inactiveSpecialties)) : array_intersect_key($bySpecialty, $chosen);

        return $common + ($candidates === [] ? 0 : max($candidates));
    }

    /**
     * El porcentaje de los créditos del plan que pide una materia para cursarla, o null si no pide ninguno: el de
     * Servicio Social y Residencia (`creditShare`) y, en una materia de especialidad, al menos
     * SPECIALTY_CREDIT_SHARE.
     */
    public function creditShareOf(string $subject): ?int
    {
        $share = max($this->creditShare[$subject] ?? 0, isset($this->specialtyOf[$subject]) ? self::SPECIALTY_CREDIT_SHARE : 0);

        return $share === 0 ? null : $share;
    }

    /**
     * Sin créditos que medir (un total en cero) no da por cumplido el porcentaje.
     */
    private function creditsReached(string $subject, int $credits, int $total): bool
    {
        $share = $this->creditShareOf($subject) ?? 0;

        return $share === 0 || ($total > 0 && $credits * 100 >= $share * $total);
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
