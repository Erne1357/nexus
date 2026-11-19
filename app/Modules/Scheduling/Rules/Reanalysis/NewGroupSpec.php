<?php

namespace App\Modules\Scheduling\Rules\Reanalysis;

/**
 * El grupo nuevo que propone abrir una sugerencia del re-análisis (tarea 8.3b) para una carrera faltante en una materia y
 * un campus: lo que la heurística necesita para colocarlo (NewGroupPlacementRequested) y lo que el jefe crea al aplicar
 * (OpenReanalysisGroup). Suelto (sin paquete), sin docente, con la carrera como dueña en su plan vigente y su semestre, la
 * capacidad de GroupCapacity y `seats` lugares para la carrera: lo que le falta, hasta la capacidad (supuesto por
 * confirmar).
 */
final readonly class NewGroupSpec
{
    public function __construct(
        public string $subjectId,
        public string $subjectCode,
        public string $campusId,
        public string $campusCode,
        public string $careerId,
        public string $careerCode,
        public string $studyPlanId,
        public int $semester,
        public int $capacity,
        public int $seats,
    ) {}

    /**
     * La clave de la cohorte del alumno tipo de la carrera en el semestre (HC-03, como Rules\Cohorts:
     * `plan:<study_plan_id>:<semestre>:<campus_code>`). Cohorts no se la daría (la materia tendría dos grupos), pero el
     * grupo nuevo es para los alumnos de ese semestre y no debe empalmarse con lo que llevan (supuesto por confirmar).
     */
    public function cohortKey(): string
    {
        return "plan:{$this->studyPlanId}:{$this->semester}:{$this->campusCode}";
    }
}
