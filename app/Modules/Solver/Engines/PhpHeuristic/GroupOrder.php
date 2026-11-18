<?php

namespace App\Modules\Solver\Engines\PhpHeuristic;

/**
 * El orden en que la heurística coloca los grupos (tarea 6.6): lo más restringido primero, porque es lo que más afecta
 * al resultado. Cada grupo recibe una dificultad, la suma de estos términos por su peso, y se colocan de mayor a menor;
 * empatan por semestre (el menor primero), por horas (las más primero) y por id. Los pesos son parámetros: quien
 * construye el motor puede darle otros.
 *
 * - `teacherPattern`: qué tan pocas horas de inicio le quedan al docente para el patrón del grupo (de 0, todas, a 1,
 *   ninguna), con lo que ya trae ocupado e indisponible.
 * - `teacherLoad`: las horas de los grupos movibles del docente entre las horas que tiene libres en el campus (hasta 2).
 * - `rooms`: pocos salones que le sirven (capacidad, dueño y, si lleva práctica de laboratorio, laboratorios): 1 con 4
 *   salones o menos, y baja con más.
 * - `hours`: sus horas semanales entre 6.
 * - `cohort`: la cohorte más llena del grupo (alumno tipo o paquete de primer semestre): sus horas entre las horas de la
 *   rejilla (hasta 1).
 * - `semester`: 1 entre el semestre, para que los primeros semestres (paquetes y troncos comunes) vayan antes.
 */
final readonly class GroupOrder
{
    public function __construct(
        public float $teacherPattern = 3.0,
        public float $teacherLoad = 3.0,
        public float $rooms = 2.0,
        public float $hours = 1.0,
        public float $cohort = 2.0,
        public float $semester = 0.5,
    ) {}

    /**
     * @param  array{has_teacher: bool, pattern_starts: int, total_starts: int, teacher_load: float, rooms: int, weekly_hours: int, cohort_load: float, semester: int}  $metrics
     */
    public function difficulty(array $metrics): float
    {
        $difficulty = 0.0;

        if ($metrics['has_teacher']) {
            $difficulty += $this->teacherPattern * (1 - min(1, $metrics['pattern_starts'] / max(1, $metrics['total_starts'])));
            $difficulty += $this->teacherLoad * min(2.0, $metrics['teacher_load']);
        }

        return $difficulty
            + $this->rooms * min(1.0, 4 / max(1, $metrics['rooms']))
            + $this->hours * min(1.0, $metrics['weekly_hours'] / 6)
            + $this->cohort * min(1.0, $metrics['cohort_load'])
            + $this->semester / max(1, $metrics['semester']);
    }
}
