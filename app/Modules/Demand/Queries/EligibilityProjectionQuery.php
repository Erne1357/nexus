<?php

namespace App\Modules\Demand\Queries;

use App\Modules\Core\Models\Modality;
use App\Modules\Demand\Rules\StandingCalculator;
use App\Modules\Students\Enums\StudentStatus;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Queries\PlanGrid;
use App\Modules\Students\Queries\PlanGridQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * Los elegibles proyectados al inicio del periodo que se planea, por carrera, campus y materia (tarea 5.3; DM-01): de
 * cada alumno, lo que podrá cursar contando como aprobado lo que cursa hoy (StandingCalculator), cuántos de esos lo son
 * solo por eso y cuántos cursan hoy la materia, que vuelven como repetidores.
 *
 * - Hoy: los alumnos activos de la modalidad (estatus `activo`, `is_active`, sin borrar), los mismos que cuenta R1
 *   (EligibleStudentsQuery), con todo su kárdex anterior al periodo.
 * - «Como si» durante `$asOfTerm` (la evaluación de la 5.9): los alumnos con kárdex en ese periodo, sin mirar nada de
 *   después; lo de ese periodo se toma como cursando (DM-10, supuesto por confirmar).
 *
 * Lee a los alumnos de 500 en 500, cada bloque con su kárdex. Tarda: la corre el job de la proyección.
 */
final class EligibilityProjectionQuery
{
    private const int CHUNK = 500;

    public function __construct(
        private readonly PlanGridQuery $grids,
        private readonly StandingCalculator $standings,
    ) {}

    /**
     * @return array<string, array<string, array<string, array{eligible: int, pending: int, current: int}>>> carrera → campus → materia → conteos
     */
    public function forTerm(string $termCode, Modality $modality, ?string $asOfTerm = null): array
    {
        $counts = [];
        /** @var array<string, PlanGrid> $grids */
        $grids = [];
        $cutoff = $asOfTerm ?? $termCode;

        $this->population($modality, $asOfTerm)
            ->select(['id', 'career_id', 'campus_id', 'study_plan_id'])
            ->chunkById(self::CHUNK, function (Collection $students) use ($termCode, $asOfTerm, $cutoff, &$counts, &$grids): void {
                $kardex = KardexRecord::query()
                    ->whereIn('student_id', $students->modelKeys())
                    ->where(fn ($query) => $query->whereNull('term_code')->orWhere('term_code', $asOfTerm === null ? '<' : '<=', $cutoff))
                    ->toBase()
                    ->get(['student_id', 'subject_id', 'term_code', 'result'])
                    ->groupBy('student_id');

                foreach ($students as $student) {
                    $grid = $grids[$student->study_plan_id] ??= $this->grids->forPlan($student->study_plan_id);

                    if ($grid->credits === [] && $grid->unplaced === []) {
                        continue;
                    }

                    $standing = $this->standings->at($grid, $kardex->get($student->id, collect()), $termCode, $asOfTerm);
                    $bySubject = &$counts[$student->career_id][$student->campus_id];

                    foreach ($standing->eligible as $subject) {
                        $bySubject[$subject] ??= ['eligible' => 0, 'pending' => 0, 'current' => 0];
                        $bySubject[$subject]['eligible']++;
                    }

                    foreach ($standing->pendingPrerequisite as $subject) {
                        $bySubject[$subject]['pending']++;
                    }

                    foreach ($standing->taking as $subject) {
                        $bySubject[$subject] ??= ['eligible' => 0, 'pending' => 0, 'current' => 0];
                        $bySubject[$subject]['current']++;
                    }

                    unset($bySubject);
                }
            });

        return $counts;
    }

    /**
     * @return Builder<Student>
     */
    private function population(Modality $modality, ?string $asOfTerm): Builder
    {
        $students = Student::query()->where('modality_id', $modality->getKey());

        if ($asOfTerm === null) {
            return $students->where('is_active', true)->where('status', StudentStatus::Activo);
        }

        return $students->whereExists(fn (QueryBuilder $query) => $query->selectRaw('1')
            ->from('kardex_records')
            ->whereColumn('kardex_records.student_id', 'students.id')
            ->where('kardex_records.term_code', $asOfTerm)
            ->whereNull('kardex_records.deleted_at'));
    }
}
