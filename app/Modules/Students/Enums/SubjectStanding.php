<?php

namespace App\Modules\Students\Enums;

/**
 * Estado de una materia en la retícula del alumno, con los nombres que usa el SII (tarea 1.6;
 * reglas-de-negocio.md, "Retícula del alumno"). Sale de sus filas de kardex de esa materia y de si ya puede
 * cursarla según su avance (`student_progress.subjects_available`). «Seleccionada solamente» llega con la
 * inscripción (R3).
 */
enum SubjectStanding: string
{
    case Acreditada = 'acreditada';
    case Cursando = 'cursando';
    case SinCalificar = 'sin_calificar';
    case ReprobadaPrioridad = 'reprobada_prioridad';
    case ACursoEspecial = 'a_curso_especial';
    case CursoEspecialReprobado = 'curso_especial_reprobado';
    case PuedeSeleccionarla = 'puede_seleccionarla';
    case NoPermitida = 'no_permitida';

    /**
     * El estado de una materia con estas filas de kardex. Lo aprobado manda; si no, decide la fila más reciente
     * (por periodo y oportunidad) que no sea una baja, porque una baja no gasta oportunidad: si la cursa desde el
     * periodo en curso, cursando; si es de uno anterior, sin calificar; si la reprobó, según la oportunidad: la 1
     * es ordinaria, la 2 repetición y la 3 curso especial.
     *
     * @param  list<array{result: KardexResult, attempt: int, term: string}>  $attempts
     * @param  bool  $available  si ya puede cursarla según su avance
     * @param  string|null  $termInProgress  el periodo en curso de su campus (TermInProgressQuery), si lo hay
     */
    public static function of(array $attempts, bool $available, ?string $termInProgress): self
    {
        if (in_array(KardexResult::Aprobada, array_column($attempts, 'result'), true)) {
            return self::Acreditada;
        }

        $counted = array_values(array_filter($attempts, fn (array $attempt): bool => $attempt['result'] !== KardexResult::Baja));
        usort($counted, fn (array $a, array $b): int => [$a['term'], $a['attempt']] <=> [$b['term'], $b['attempt']]);
        $last = end($counted);

        return match (true) {
            $last === false => $available ? self::PuedeSeleccionarla : self::NoPermitida,
            $last['result'] === KardexResult::Cursando => $termInProgress === null || $last['term'] >= $termInProgress ? self::Cursando : self::SinCalificar,
            $last['attempt'] >= 3 => self::CursoEspecialReprobado,
            $last['attempt'] === 2 => self::ACursoEspecial,
            default => self::ReprobadaPrioridad,
        };
    }

    /**
     * Si la materia cuenta para «Puede seleccionar» cuando su avance la deja cursar. Reprobar el curso especial
     * da de baja al alumno, así que esa ya no cuenta (reglas-de-negocio.md, "Retícula del alumno").
     */
    public function countsAsSelectable(): bool
    {
        return in_array($this, [self::PuedeSeleccionarla, self::ReprobadaPrioridad, self::ACursoEspecial], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Acreditada => 'Acreditada',
            self::Cursando => 'Cursando',
            self::SinCalificar => 'Sin calificar',
            self::ReprobadaPrioridad => 'Reprobada · prioridad',
            self::ACursoEspecial => 'A curso especial',
            self::CursoEspecialReprobado => 'Curso especial reprobado',
            self::PuedeSeleccionarla => 'Puede seleccionarla',
            self::NoPermitida => 'No permitida aún',
        };
    }

    /**
     * Icono de Heroicons del estado: el color nunca va solo (DESIGN.md, "Named Rules").
     */
    public function icon(): string
    {
        return match ($this) {
            self::Acreditada => 'o-check-circle',
            self::Cursando => 'o-clock',
            self::SinCalificar => 'o-question-mark-circle',
            self::ReprobadaPrioridad => 'o-arrow-path',
            self::ACursoEspecial => 'o-exclamation-triangle',
            self::CursoEspecialReprobado => 'o-x-circle',
            self::PuedeSeleccionarla => 'o-arrow-right-circle',
            self::NoPermitida => 'o-lock-closed',
        };
    }
}
