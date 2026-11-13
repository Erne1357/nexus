<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\Core\Models\Campus;

/**
 * Una materia de la propuesta de carrera con sus filas, una por columna (M6; ProposalOverview): la tabla y la retícula
 * la pintan como un renglón o un mosaico con las cifras de cada campus, y el panel la abre entera. El semestre y el
 * plan son los de sus filas, que StartCareerProposal toma del mismo lugar de la retícula.
 */
final readonly class ProposalSubject
{
    /**
     * @param  array<string, ProposalItem>  $cells  por llave de columna (ProposalOverview::columnKey), en el orden de las columnas; falta la de una columna sin fila
     */
    public function __construct(
        public Subject $subject,
        public int $semester,
        public string $studyPlanId,
        public array $cells,
    ) {}

    public function id(): string
    {
        return $this->subject->getKey();
    }

    /**
     * La fila de la columna, o null si la materia no la tiene.
     */
    public function cell(?Campus $campus): ?ProposalItem
    {
        return $this->cells[ProposalOverview::columnKey($campus)] ?? null;
    }

    /**
     * @return list<ProposalItem>
     */
    public function items(): array
    {
        return array_values($this->cells);
    }

    /**
     * Los grupos pedidos en todos los campus.
     */
    public function groups(): int
    {
        return array_sum(array_map(fn (ProposalItem $item): int => $item->requested_groups, $this->cells));
    }

    /**
     * Los alumnos posibles de todos los campus: un alumno es de un solo campus, así que se suman.
     */
    public function eligible(): int
    {
        return array_sum(array_map(fn (ProposalItem $item): int => $item->eligible_students, $this->cells));
    }

    /**
     * Los alumnos esperados de todos los campus.
     */
    public function expected(): int
    {
        return array_sum(array_map(fn (ProposalItem $item): int => $item->expected_students, $this->cells));
    }

    /**
     * Las notas de sus filas, por llave de columna; sin las vacías.
     *
     * @return array<string, string>
     */
    public function notes(): array
    {
        return array_filter(array_map(fn (ProposalItem $item): string => (string) $item->notes, $this->cells), fn (string $note): bool => $note !== '');
    }
}
