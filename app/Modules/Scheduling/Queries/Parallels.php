<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;

/**
 * La pestaña «Paralelos» de la bandeja de un departamento en una modalidad y un periodo (tarea 8.8, opción B;
 * ParallelsQuery::for): un renglón por materia y campus con algo que pedir (ParallelSubject), por clave de materia y de
 * campus, y por qué la carga no deja armar (`blockedReason`, LoadEditability), o null, con el estado de la carga
 * (`loadStatus`, null sin carga) para el encabezado. Quién la ve y quién arma lo decide ParallelsPolicy.
 */
final readonly class Parallels
{
    /**
     * @param  list<ParallelSubject>  $subjects
     */
    public function __construct(
        public Term $term,
        public Modality $modality,
        public Department $department,
        public ?string $blockedReason,
        public array $subjects,
        public ?LoadStatus $loadStatus = null,
    ) {}

    /**
     * Las materias y campus donde juntando se ahorran grupos; con `$campusId`, las de ese campus.
     *
     * @return list<ParallelSubject>
     */
    public function joinable(?string $campusId = null): array
    {
        return $this->filtered($campusId, fn (ParallelSubject $row): bool => $row->whyNot === null);
    }

    /**
     * Las que no tienen nada que juntar, con su motivo (`whyNot`).
     *
     * @return list<ParallelSubject>
     */
    public function nothingToJoin(?string $campusId = null): array
    {
        return $this->filtered($campusId, fn (ParallelSubject $row): bool => $row->whyNot !== null);
    }

    /**
     * Las cifras de la cabecera, de las materias juntables: cuántas son, los grupos que piden, los que bastan, los de menos
     * y cuántas ya se armaron.
     *
     * @return array{subjects: int, requested: int, suggested: int, saved: int, arranged: int}
     */
    public function figures(?string $campusId = null): array
    {
        $rows = $this->joinable($campusId);
        $sum = fn (callable $value): int => array_sum(array_map($value, $rows));

        return [
            'subjects' => count($rows),
            'requested' => $sum(fn (ParallelSubject $row): int => $row->requested()),
            'suggested' => $sum(fn (ParallelSubject $row): int => $row->suggested()),
            'saved' => $sum(fn (ParallelSubject $row): int => $row->saved()),
            'arranged' => count(array_filter($rows, fn (ParallelSubject $row): bool => $row->arranged())),
        ];
    }

    /**
     * El renglón de una materia en un campus, por ids que pueden llegar del cliente, o null.
     */
    public function subject(string $subjectId, string $campusId): ?ParallelSubject
    {
        return array_find($this->subjects, fn (ParallelSubject $row): bool => $row->subject->getKey() === $subjectId && $row->campus->getKey() === $campusId);
    }

    /**
     * @param  callable(ParallelSubject): bool  $keep
     * @return list<ParallelSubject>
     */
    private function filtered(?string $campusId, callable $keep): array
    {
        return array_values(array_filter($this->subjects, fn (ParallelSubject $row): bool => ($campusId === null || $row->campus->getKey() === $campusId) && $keep($row)));
    }
}
