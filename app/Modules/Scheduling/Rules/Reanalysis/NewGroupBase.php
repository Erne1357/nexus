<?php

namespace App\Modules\Scheduling\Rules\Reanalysis;

/**
 * Lo que ReanalysisAdvisor necesita para proponer abrir un grupo en una materia y un campus (tarea 8.3b): la materia, el
 * campus, la capacidad con la que nacería (GroupCapacity) y el plan vigente de cada carrera con la materia y su semestre
 * (`plans`, por id de la carrera). Lo arma ReanalysisSuggestionsQuery; el asesor sigue sin leer la base.
 */
final readonly class NewGroupBase
{
    /**
     * @param  array<string, array{studyPlanId: string, semester: int}>  $plans
     */
    public function __construct(
        public string $subjectId,
        public string $subjectCode,
        public string $campusId,
        public string $campusCode,
        public int $capacity,
        public array $plans,
    ) {}

    /**
     * El grupo nuevo para una carrera a la que le faltan `$gap` lugares: los que faltan, hasta la capacidad. Null si la
     * carrera no tiene un plan vigente con la materia.
     */
    public function specFor(string $careerId, string $careerCode, int $gap): ?NewGroupSpec
    {
        $plan = $this->plans[$careerId] ?? null;

        if ($plan === null || $this->capacity < 1) {
            return null;
        }

        return new NewGroupSpec(
            subjectId: $this->subjectId,
            subjectCode: $this->subjectCode,
            campusId: $this->campusId,
            campusCode: $this->campusCode,
            careerId: $careerId,
            careerCode: $careerCode,
            studyPlanId: $plan['studyPlanId'],
            semester: $plan['semester'],
            capacity: $this->capacity,
            seats: max(1, min($gap, $this->capacity)),
        );
    }
}
