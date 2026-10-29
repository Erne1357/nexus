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
     * El estado de una materia con estas filas de kardex. Lo aprobado manda; luego lo que cursa (en el periodo
     * más reciente del kardex, o sin calificar si es de uno anterior); luego la oportunidad más alta que
     * reprobó: la 1 es ordinaria, la 2 repetición y la 3 curso especial. Una baja no gasta oportunidad.
     *
     * @param  list<array{result: KardexResult, attempt: int, term: string}>  $attempts
     * @param  bool  $available  si ya puede cursarla según su avance
     * @param  string  $latestTerm  el periodo más reciente del kardex importado
     */
    public static function of(array $attempts, bool $available, string $latestTerm): self
    {
        $results = array_column($attempts, 'result');

        if (in_array(KardexResult::Aprobada, $results, true)) {
            return self::Acreditada;
        }

        $taking = array_filter($attempts, fn (array $attempt): bool => $attempt['result'] === KardexResult::Cursando);

        if ($taking !== []) {
            return in_array($latestTerm, array_column($taking, 'term'), true) ? self::Cursando : self::SinCalificar;
        }

        $failed = array_column(array_filter($attempts, fn (array $attempt): bool => $attempt['result'] === KardexResult::Reprobada), 'attempt');

        return match (true) {
            $failed === [] => $available ? self::PuedeSeleccionarla : self::NoPermitida,
            max($failed) >= 3 => self::CursoEspecialReprobado,
            max($failed) === 2 => self::ACursoEspecial,
            default => self::ReprobadaPrioridad,
        };
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
